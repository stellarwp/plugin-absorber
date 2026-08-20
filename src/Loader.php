<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;
use Throwable;

/**
 * The load pass: every registered sub-plugin, in registration order, gated one at a time.
 *
 * Separate from `Absorber` because it is the one thing here that runs rather than configures. It is
 * reached from a hook, it needs the notice queue, and it is the piece a host is likeliest to want
 * to watch or replace — none of which is true of registration.
 *
 * @since 1.0.0
 */
class Loader {
	use Guards_Hook_Prefix;

	/**
	 * @since 1.0.0
	 *
	 * @var Registry_Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @var Queue_Interface
	 */
	private $notices;

	/**
	 * @since 1.0.0
	 *
	 * @param Registry_Reader $registry Which sub-plugins are registered.
	 * @param Queue_Interface $notices  Where a sub-plugin that could not load says so.
	 */
	public function __construct( Registry_Reader $registry, Queue_Interface $notices ) {
		$this->registry = $registry;
		$this->notices  = $notices;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception From loading a sub-plugin, which reads the hook prefix the guard
	 *                          above has already established is set.
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
		// registered since the last read.
		try {
			$sub_plugins = $this->registry->all();
		} catch ( Config_Exception $exception ) {
			// The flush is where a duplicate slug is caught, and reading the registrar is where a
			// missing container or an unusable binding is. All three are bootstrap mistakes, and
			// all three arrive inside plugins_loaded: letting one out would fatal every request,
			// front end and admin alike, and lock the developer out of the screen where the
			// registration could be corrected. The hook this runs on exists to prevent a fatal, so
			// it is the last place that may cause one -- the mistake is reported to the developer
			// and the load is abandoned instead.
			_doing_it_wrong(
				self::class,
				sprintf(
					'The registered sub-plugins could not be read, so none were loaded: %s',
					$exception->getMessage()
				),
				'1.0.0'
			);

			return;
		}

		foreach ( $sub_plugins as $sub_plugin ) {
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
			try {
				$this->load( $sub_plugin );
			} catch ( Throwable $thrown ) {
				_doing_it_wrong(
					self::class,
					sprintf(
						'The sub-plugin "%s" threw while loading, so it was abandoned: %s',
						$sub_plugin->get_slug(),
						$thrown->getMessage()
					),
					'1.0.0'
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
			_doing_it_wrong(
				self::class,
				sprintf(
					'The bundled plugin file for "%s" is missing or unreadable: %s',
					$sub_plugin->get_slug(),
					$file
				),
				'1.0.0'
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
	}
}
