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
			// a Throwable -- which is what the guard constant, checked before any of this and checked
			// again once the require has happened, is for.
			//
			// The loaded and skipped actions run host code too, but they catch their own throws
			// rather than falling to this one: by the time `loaded` fires the require has happened,
			// the guard constant has been checked and the activation callback has run, so a listener's
			// throw arriving here would report a sub-plugin that is loaded and healthy as one that
			// was abandoned -- on the channel a host built its log line on.
			try {
				$this->load( $sub_plugin );
			} catch ( Throwable $thrown ) {
				_doing_it_wrong(
					self::class . '::load_all',
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
			$this->announce_skip( $sub_plugin, Skip_Reason::DISABLED );

			return;
		}

		// Ahead of the dependency check, which calls an arbitrary host callable. This is one
		// defined(), it carries the whole re-declaration guarantee, and it is the only gate that
		// means "the plugin is already running" -- warning that requirements are unmet for a
		// plugin the admin can see working would be worse than useless.
		if ( $sub_plugin->is_already_loaded() ) {
			$this->announce_skip( $sub_plugin, Skip_Reason::ALREADY_LOADED );

			return;
		}

		if ( ! $sub_plugin->are_dependencies_met() ) {
			$this->notices->queue_dependency_notice( $sub_plugin );
			$this->announce_skip( $sub_plugin, Skip_Reason::DEPENDENCIES_UNMET );

			return;
		}

		// Not file_exists(): that is true for a directory and for a file with no read permission,
		// and require_once fatals on both. A missing file is a broken build in the host plugin
		// rather than anything a site owner can act on, so it goes to the developer instead of
		// into the notice queue, where it would have displayed the host's own
		// dependency_notice_message and sent the owner after the wrong problem entirely.
		$file = $sub_plugin->get_bundled_plugin_file();

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			// Reported and announced both, because this gate is two things at once: a build the
			// host has to fix, which is what `_doing_it_wrong()` carries, and a sub-plugin that is
			// not going to be there, which is what anything watching `skipped` is counting. A host
			// watching both sees this one twice, and that is said plainly in the docs rather than
			// solved by picking one.
			_doing_it_wrong(
				self::class . '::load',
				sprintf(
					'The bundled plugin file for "%s" is missing or unreadable: %s',
					$sub_plugin->get_slug(),
					$file
				),
				'1.0.0'
			);
			$this->announce_skip( $sub_plugin, Skip_Reason::FILE_UNREADABLE );

			return;
		}

		// No type guard on the return, unlike the conflict_policy filter: there is no cast here,
		// and every unexpected value is falsy-or-truthy without fataling. Anything odd skips the
		// load, which is the safe direction.
		$should_load = apply_filters( Config::get_hook_name( 'should_load' ), true, $sub_plugin );

		if ( ! $should_load ) {
			$this->announce_skip( $sub_plugin, Skip_Reason::FILTERED );

			return;
		}

		// An include takes the scope of the line it sits on, and this one is inside a method, where
		// wp-settings.php includes plugins at global scope. Top-level assignments in the bundled
		// file are function-local as a result -- documented for hosts, because no amount of
		// wrapping here can hand a required file the global scope it would have had.
		require_once $file;

		// The same defined() the second gate asked, on the other side of the require -- asked of the
		// constant table here rather than through `Sub_Plugin::is_already_loaded()`, because it is no
		// longer the same question. In front of the require that predicate means "the code is already
		// here, from whichever copy"; behind it, the only thing worth asking is whether this require
		// did what the guard promises. `Sub_Plugin` still owns the name, so nothing here spells a guard
		// constant for Strauss's constant_prefix to rewrite at build time.
		//
		// Nothing else checks: a typo in `plugin_loaded_constant`, or a bundled plugin that defines its
		// constant from its own plugins_loaded callback rather than at file scope, leaves the code in
		// memory with nothing standing a standalone copy down -- and a re-declaration fatal, which PHP
		// does not raise as a Throwable and nothing here can catch, is then one activation away while
		// every counter and every action says the load went perfectly.
		//
		// Reported rather than skipped, and the load carries on. The require happened and cannot be
		// undone: the file's code is in memory whatever the guard says, so the activation callback
		// behind this still has to run and `loaded` still has to fire -- announcing a skip would tell
		// a host that code which is running is not there, and withholding the callback would leave
		// the sub-plugin loaded with the tables it expects never created. What is broken is the
		// host's build, which is a developer's to fix and nothing a site owner's screen can help with --
		// so it goes to _doing_it_wrong() and to no notice.
		$guard_constant = $sub_plugin->get_plugin_loaded_constant();

		if ( ! defined( $guard_constant ) ) {
			_doing_it_wrong(
				self::class . '::load',
				sprintf(
					'The bundled plugin "%s" was required and left %s undefined, so nothing stands a'
						. ' standalone copy down. Define the guard constant at file scope, or correct'
						. ' plugin_loaded_constant.',
					$sub_plugin->get_slug(),
					$guard_constant
				),
				'1.0.0'
			);
		}

		// Only after a require that actually happened. A bundled plugin is included rather than
		// activated, so register_activation_hook() never fires for it and whatever that hook would
		// have done -- creating a table, seeding options -- would never happen at all. Running it
		// for a sub-plugin that was skipped would be worse: the schema would appear for a plugin
		// that is not loaded.
		$this->activator->maybe_run( $sub_plugin );

		// Behind the activation callback, not in front of it. A listener here is host code that will
		// reach into the sub-plugin it was just told about, and on the first-ever load the tables and
		// options that code expects are the activation callback's work -- so announcing first would
		// hand a host a plugin that is loaded but not yet set up. It also keeps a throwing listener
		// off the callback: from here announce() catches the throw with the require and the
		// activation already done, where from in front of it the throw would skip the callback
		// entirely and leave it to be retried, silently, on every request for ever.
		$this->announce( Config::get_hook_name( 'loaded' ), [ $sub_plugin ], $sub_plugin );
	}

	/**
	 * Say that a gate turned this sub-plugin away, and which gate it was.
	 *
	 * The hook name is built in one place for the reason `Config` gives for owning the prefix at all.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin that was skipped.
	 * @param string     $reason     One of the `Skip_Reason` constants.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	private function announce_skip( Sub_Plugin $sub_plugin, string $reason ): void {
		$this->announce( Config::get_hook_name( 'skipped' ), [ $sub_plugin, $reason ], $sub_plugin );
	}

	/**
	 * Fire one lifecycle action, and keep what a listener throws out of the load pass's own report.
	 *
	 * The throw is caught here rather than a frame up, because `load_all()`'s per-sub-plugin catch
	 * has exactly one sentence and it is "threw while loading, so it was abandoned". For a listener
	 * on `loaded` that sentence is false in both halves: the require happened, the guard constant has
	 * been checked and the activation callback has already run, so the sub-plugin is loaded and
	 * nothing about it was abandoned. A host would get the `loaded` announcement and a report of a
	 * failed load for the same sub-plugin in the same pass, and the log line it keeps would read a
	 * successful load as a broken one. The same is true of a listener on `skipped`, which reports a
	 * skip that really did happen.
	 *
	 * So the failure is reported as what it is -- somebody's listener, named by the hook it is on and
	 * the sub-plugin it was announcing. It still costs nothing behind it: the sub-plugin is finished
	 * with either way, and the loop moves on to the next.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $hook       Fully qualified hook name.
	 * @param array<int,mixed> $arguments  Arguments to pass to the listeners.
	 * @param Sub_Plugin       $sub_plugin Sub-plugin the announcement is about.
	 *
	 * @return void
	 */
	private function announce( string $hook, array $arguments, Sub_Plugin $sub_plugin ): void {
		try {
			do_action_ref_array( $hook, $arguments );
		} catch ( Throwable $thrown ) {
			_doing_it_wrong(
				self::class . '::announce',
				sprintf(
					'A listener on %s threw for "%s", and was abandoned: %s',
					$hook,
					$sub_plugin->get_slug(),
					$thrown->getMessage()
				),
				'1.0.0'
			);
		}
	}
}
