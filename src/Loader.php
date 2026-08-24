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
	 * The `enabled` key, or the callable behind it, said no.
	 *
	 * This and the four that follow are the values the `skipped` action's second argument takes, and
	 * they are public API from the moment they ship: a host comparing against
	 * `Loader::SKIPPED_DISABLED` rather than against `'disabled'` is the point of naming them at all.
	 * They sit on this class because each one names a gate in `load()` and nothing outside the load
	 * pass decides one — where `Conflict_Policy` earns a class of its own by carrying behaviour
	 * (`default()`, `is_valid()`) and by being read by the value object, the resolver and the host
	 * alike.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const SKIPPED_DISABLED = 'disabled';

	/**
	 * The guard constant was already defined, so the code is in memory — a standalone copy loaded
	 * ahead of this pass, or a second registration of the same plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const SKIPPED_ALREADY_LOADED = 'already_loaded';

	/**
	 * The `dependency_check` callable said the requirements are not met. The one skip that also
	 * queues a notice for the site owner.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const SKIPPED_DEPENDENCIES_UNMET = 'dependencies_unmet';

	/**
	 * `bundled_plugin_file` does not name a readable file. A broken build in the host plugin, so it
	 * is announced through `error` as well as here.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const SKIPPED_FILE_UNREADABLE = 'file_unreadable';

	/**
	 * The `should_load` filter returned something falsy.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const SKIPPED_FILTERED = 'filtered';

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
			//
			// The loaded and skipped actions run host code too, but they catch their own throws
			// rather than falling to this one: by the time `loaded` fires the require has happened,
			// the guard constant is defined and the activation callback has run, so a listener's
			// throw arriving here would report a sub-plugin that is loaded and healthy as one that
			// was abandoned -- on the channel a host built its log line on.
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
			$this->announce_skip( $sub_plugin, self::SKIPPED_DISABLED );

			return;
		}

		// Ahead of the dependency check, which calls an arbitrary host callable. This is one
		// defined(), it carries the whole re-declaration guarantee, and it is the only gate that
		// means "the plugin is already running" -- warning that requirements are unmet for a
		// plugin the admin can see working would be worse than useless.
		if ( $sub_plugin->is_already_loaded() ) {
			$this->announce_skip( $sub_plugin, self::SKIPPED_ALREADY_LOADED );

			return;
		}

		if ( ! $sub_plugin->are_dependencies_met() ) {
			$this->notices->queue_dependency_notice( $sub_plugin );
			$this->announce_skip( $sub_plugin, self::SKIPPED_DEPENDENCIES_UNMET );

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
				self::class,
				sprintf(
					'The bundled plugin file for "%s" is missing or unreadable: %s',
					$sub_plugin->get_slug(),
					$file
				),
				'1.0.0'
			);
			$this->announce_skip( $sub_plugin, self::SKIPPED_FILE_UNREADABLE );

			return;
		}

		// No type guard on the return, unlike the conflict_policy filter: there is no cast here,
		// and every unexpected value is falsy-or-truthy without fataling. Anything odd skips the
		// load, which is the safe direction.
		$should_load = apply_filters( Config::get_hook_name( 'should_load' ), true, $sub_plugin );

		if ( ! $should_load ) {
			$this->announce_skip( $sub_plugin, self::SKIPPED_FILTERED );

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

		// Behind the activation callback, not in front of it. A listener here is host code that will
		// reach into the sub-plugin it was just told about, and on the first-ever load the tables and
		// options that code expects are the activation callback's work -- so announcing first would
		// hand a host a plugin that is loaded but not yet set up. It also keeps a throwing listener
		// off the callback: from here the throw is caught a frame up with the require and the
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
	 * @param string     $reason     One of this class's `SKIPPED_*` constants.
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
	 * on `loaded` that sentence is false in both halves: the require happened, the guard constant is
	 * defined and the activation callback has already run, so the sub-plugin is loaded and nothing
	 * about it was abandoned. A host listening to both channels would get `loaded` and `error` for
	 * the same sub-plugin in the same pass, and the log line, health check and support tool that
	 * `error` exists for would each read a successful load as a failed one. The same is true of a
	 * listener on `skipped`, which reports a skip that really did happen.
	 *
	 * So the failure is announced as what it is -- somebody's listener, named by the hook it is on --
	 * in the sentence `Traits\Reports_Errors` already uses for a listener on the error action. It
	 * still costs nothing behind it: the sub-plugin is finished with either way, and the loop moves
	 * on to the next.
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
