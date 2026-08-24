<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;
use Nexcess\PluginAbsorber\Traits\Reports_Errors;
use Throwable;

/**
 * The load pass: every registered sub-plugin, in registration order, gated one at a time.
 *
 * Separate from `Absorber` because it is the one thing here that runs rather than configures. It is
 * reached from a hook, it needs the notice queue and the activator, and it is the piece a host is
 * likeliest to want to watch or replace — none of which is true of registration.
 *
 * @since 1.0.0
 */
class Loader {
	use Guards_Hook_Prefix;
	use Reports_Errors;

	/**
	 * @since 1.0.0
	 *
	 * @var Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @var Writer_Interface
	 */
	private $notices;

	/**
	 * @since 1.0.0
	 *
	 * @var Activator_Interface
	 */
	private $activator;

	/**
	 * @since 1.0.0
	 *
	 * @param Reader              $registry  Which sub-plugins are registered.
	 * @param Writer_Interface    $notices   Where a sub-plugin that could not load says so.
	 * @param Activator_Interface $activator Runs the activation callback of one that did.
	 */
	public function __construct(
		Reader $registry,
		Writer_Interface $notices,
		Activator_Interface $activator
	) {
		$this->registry  = $registry;
		$this->notices   = $notices;
		$this->activator = $activator;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_all(): void {
		// The load path needs the prefix for the should_load filter and for the notice store.
		// Throwing out of a core action would take the whole site down over a bootstrap mistake,
		// so it is reported where a developer will see it and the load is abandoned instead.
		if ( ! self::has_hook_prefix() ) {
			return;
		}

		// The reader rather than the registrar directly: it drains the registrations still buffered
		// on the facade before it reads, and a registrar asked on its own would miss anything
		// registered since the last read. Unguarded, because the read answers with whatever the
		// registrar legitimately holds: a duplicate slug is refused and reported where it is found,
		// so the sub-plugins around it still reach this loop rather than a host's one mistaken
		// registration costing the site every bundled plugin it has.
		foreach ( $this->registry->all() as $sub_plugin ) {
			// Everything past this line is somebody else's code: the enabled and dependency_check
			// callables, the host's should_load filter, and the bundled file itself, which a require
			// runs from top to bottom. Any of it may throw, and this loop runs inside plugins_loaded
			// on every request a site serves -- so an escaping throw is a white screen on the front
			// end and in wp-admin alike, over one sub-plugin, and it takes every sub-plugin behind it
			// in the registration order with it. The developer is told which sub-plugin, and the
			// loop carries on with the next.
			//
			// A re-declaration is the one failure this cannot catch, because PHP does not raise it as
			// a Throwable -- which is what the guard constant, checked before any of this, is for.
			//
			// Reporting the failure cannot add one of its own: report_error() swallows whatever a
			// listener on the error action throws, so the announcement of a sub-plugin that died
			// cannot be what kills the request.
			try {
				$this->load( $sub_plugin );
			} catch ( Throwable $thrown ) {
				self::report_error(
					self::class,
					sprintf(
						'The sub-plugin "%s" threw while loading, so it was abandoned: %s',
						$sub_plugin->get_slug(),
						$thrown->getMessage()
					),
					$sub_plugin
				);
			}
		}
	}

	/**
	 * Load one sub-plugin, cheapest and most decisive check first.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to load.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	private function load( Sub_Plugin $sub_plugin ): void {
		if ( ! $sub_plugin->is_enabled() ) {
			return;
		}

		// Ahead of the dependency check, which calls an arbitrary host callable. This is one
		// defined(), it carries the whole re-declaration guarantee, and it is the only gate that
		// means "the plugin is already running" -- warning that requirements are unmet for a
		// plugin the admin can see working would be worse than useless.
		if ( $sub_plugin->is_already_loaded() ) {
			return;
		}

		if ( ! $sub_plugin->are_dependencies_met() ) {
			$this->notices->queue_dependency_notice( $sub_plugin );

			return;
		}

		// Not file_exists(): that is true for a directory and for a file with no read permission,
		// and require_once fatals on both. A missing file is a broken build in the host plugin
		// rather than anything a site owner can act on, so it goes to the developer instead of
		// into the notice queue, where it would have displayed the host's own
		// dependency_notice_message and sent the owner after the wrong problem entirely.
		$file = $sub_plugin->get_bundled_plugin_file();

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			// The sub-plugin travels with the sentence, because this failure belongs to exactly one
			// registration: a listener told only that a bundled file is missing would have to parse
			// the path back out to know which of them to act on.
			self::report_error(
				self::class,
				sprintf(
					'The bundled plugin file for "%s" is missing or unreadable: %s',
					$sub_plugin->get_slug(),
					$file
				),
				$sub_plugin
			);

			return;
		}

		// No type guard on the return, unlike the conflict_policy filter: there is no cast here,
		// and every unexpected value is falsy-or-truthy without fataling. Anything odd skips the
		// load, which is the safe direction.
		$should_load = apply_filters( Config::get_hook_name( 'should_load' ), true, $sub_plugin );

		if ( ! $should_load ) {
			return;
		}

		// An include takes the scope of the line it sits on, and this one is inside a method, where
		// wp-settings.php includes plugins at global scope. Top-level assignments in the bundled
		// file are function-local as a result -- documented for hosts, because no amount of
		// wrapping here can hand a required file the global scope it would have had.
		require_once $file;

		// Only after a require that actually happened. A bundled plugin is included rather than
		// activated, so register_activation_hook() never fires for it and whatever that hook would
		// have done -- creating a table, seeding options -- would never happen at all. Running it
		// for a sub-plugin that was skipped would be worse: the schema would appear for a plugin
		// that is not loaded.
		$this->activator->maybe_run( $sub_plugin );
	}
}
