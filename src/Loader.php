<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Notices\Queue;
use Throwable;
use WP_Hook;

/**
 * Static facade: collaborator resolution, registration, hook wiring, and the load loop.
 *
 * @since 1.0.0
 */
class Loader {
	/**
	 * plugins_loaded priority the load loop runs at.
	 *
	 * Ahead of the default priority, so a bundled plugin is in memory before the plugins that
	 * expect it start their own work, and low enough to leave room for earlier wiring.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const LOAD_PRIORITY = 2;

	/**
	 * Resolved collaborators, memoized by interface name.
	 *
	 * @var array<string,object>
	 */
	private static $resolved = [];

	/**
	 * Sub-plugins registered but not yet handed to the registrar.
	 *
	 * @var Sub_Plugin[]
	 */
	private static $pending = [];

	/**
	 * Whether the hooks have been wired.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable instance.
	 *
	 * @return Registrar_Interface
	 */
	public static function registrar(): Registrar_Interface {
		return self::resolve( Registrar_Interface::class, Registrar::class );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable instance.
	 *
	 * @return Queue_Interface
	 */
	public static function notices(): Queue_Interface {
		return self::resolve( Queue_Interface::class, Queue::class );
	}

	/**
	 * Register one bundled sub-plugin. Call once per sub-plugin, before boot().
	 *
	 * The sub-plugin is buffered rather than handed straight to the registrar, so that registering
	 * resolves nothing. Resolution needs the container, and a host that registers before it calls
	 * Config::set_container() would otherwise pin the default registrar and silently ignore the
	 * binding. Buffering is what lets the container arrive at any point before boot, like every
	 * other configuration call.
	 *
	 * The configuration is still validated here: building the Sub_Plugin is what rejects it, and
	 * that happens at the call the host can see in its own stack trace.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @throws Config_Exception When the configuration is unusable.
	 *
	 * @return void
	 */
	public static function register( array $config ): void {
		self::$pending[] = new Sub_Plugin( $config );
	}

	/**
	 * Every registered sub-plugin, keyed by slug, in registration order.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable registrar, or two
	 *                          sub-plugins were registered under one slug.
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public static function all(): array {
		self::flush();

		return self::registrar()->all();
	}

	/**
	 * Wire the WordPress hooks. Idempotent — safe to call from more than one code path.
	 *
	 * Hooks are plain static trampolines rather than container callbacks, which is what keeps the
	 * container optional. Each trampoline delegates to the resolved collaborator, so rebinding
	 * still takes effect.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( is_admin() ) {
			// all_admin_notices, not admin_notices. WordPress dispatches admin_notices,
			// network_admin_notices and user_admin_notices as mutually exclusive branches, so a
			// superadmin working in the network admin -- exactly where a network-wide
			// deactivation gets noticed -- would never see the queue rendered.
			add_action( 'all_admin_notices', [ self::class, 'render_notices' ] );
		}

		// Adding an action at a priority the current dispatch has already passed is accepted and
		// then never fires. Booting from plugins_loaded at the default priority instead of 0 --
		// the commonest hook mistake there is -- would otherwise mean nothing loads at all, with
		// no warning and a site that looks entirely healthy.
		if ( self::wiring_window_has_closed() ) {
			_doing_it_wrong(
				__METHOD__,
				'Loader::boot() must run before plugins_loaded priority 2. Loading inline instead.',
				'1.0.0'
			);

			self::load_all();

			return;
		}

		add_action( 'plugins_loaded', [ self::class, 'load_all' ], self::LOAD_PRIORITY );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_all(): void {
		// The load path needs the prefix for the should_load filter and for the notice store.
		// Throwing out of a core action would take the whole site down over a bootstrap mistake,
		// so it is reported where a developer will see it and the load is abandoned instead.
		if ( ! self::has_hook_prefix() ) {
			return;
		}

		foreach ( self::all() as $sub_plugin ) {
			// Registrar_Interface::all() only declares `array`. A host binding its own registrar
			// that returns anything else would otherwise fatal inside plugins_loaded on the first
			// predicate call -- the exact failure this library exists to prevent.
			if ( ! $sub_plugin instanceof Sub_Plugin ) {
				continue;
			}

			self::load( $sub_plugin );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		if ( ! self::has_hook_prefix() ) {
			return;
		}

		self::notices()->render();
	}

	/**
	 * Hand every buffered registration to the registrar.
	 *
	 * The registrar stays the single source of truth: the buffer is a pre-store that needs no
	 * container, and duplicate-slug detection and ordering remain the registrar's alone rather
	 * than being restated here in a second dialect.
	 *
	 * The buffer is emptied before the loop, so a second read cannot re-register what the
	 * registrar already holds and trip its duplicate-slug guard. It is emptied *after* the
	 * registrar resolves, so a container binding that throws leaves the registrations buffered
	 * for the next read rather than dropping them on the floor.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable registrar, or two
	 *                          sub-plugins were registered under one slug.
	 *
	 * @return void
	 */
	private static function flush(): void {
		if ( self::$pending === [] ) {
			return;
		}

		$registrar = self::registrar();
		$pending   = self::$pending;

		self::$pending = [];

		foreach ( $pending as $sub_plugin ) {
			$registrar->register( $sub_plugin );
		}
	}

	/**
	 * Resolve an interface from the container when bound, else construct the default.
	 *
	 * The container is never required — with none set, every collaborator is a plain `new`, so
	 * every default class must be constructible with no arguments. Resolution is memoized, and
	 * nothing resolves until the first read, which is boot: that is what lets a host set its
	 * container at any point beforehand. Swapping a collaborator after that would be the worse
	 * behaviour, since anything already holding the old instance would keep it.
	 *
	 * @since 1.0.0
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $interface     Interface to resolve.
	 * @param class-string<T> $default_class Concrete class to build when nothing is bound.
	 *
	 * @throws Config_Exception When the container throws while building the binding, or returns
	 *                          something that does not implement the interface it was asked for.
	 *
	 * @return T
	 */
	private static function resolve( string $interface, string $default_class ): object {
		if ( isset( self::$resolved[ $interface ] ) ) {
			// The map holds a different type per key, so it cannot be typed as T as a whole. The
			// entry found here still implements $interface: a container binding is only memoized
			// once it passes the instanceof below, and the fallback is built from the class-string.
			/** @var T $memoized */
			$memoized = self::$resolved[ $interface ];

			return $memoized;
		}

		$container = Config::get_container();

		if ( $container !== null && $container->has( $interface ) ) {
			// has() true only promises the binding exists, not that it can be built: a host factory
			// closure is free to throw, and a container asked for a class with an unsatisfiable
			// dependency throws its own exception type. Uncaught, either one leaves the host's
			// plugins_loaded with a fatal from a vendor namespace that names neither this library
			// nor the binding at fault, so both are reported the same way as a binding of the wrong
			// type. The original is kept as the previous exception; nothing is memoized.
			try {
				$instance = $container->get( $interface );
			} catch ( Throwable $thrown ) {
				throw new Config_Exception(
					sprintf(
						'The container failed to build the binding for %s: %s',
						$interface,
						$thrown->getMessage()
					),
					0,
					$thrown
				);
			}

			// Checked before it is memoized. Without this the bad instance is cached, and every
			// accessor throws a TypeError blaming this library rather than the binding.
			if ( ! $instance instanceof $interface ) {
				throw new Config_Exception(
					sprintf(
						'The container binding for %s must implement it. Got %s.',
						$interface,
						is_object( $instance ) ? get_class( $instance ) : gettype( $instance )
					)
				);
			}

			self::$resolved[ $interface ] = $instance;

			return $instance;
		}

		self::$resolved[ $interface ] = new $default_class();

		return self::$resolved[ $interface ];
	}

	/**
	 * Load one sub-plugin, cheapest and most decisive check first.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to load.
	 *
	 * @throws Config_Exception When a collaborator binding is unusable.
	 *
	 * @return void
	 */
	private static function load( Sub_Plugin $sub_plugin ): void {
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
			self::notices()->queue_dependency_notice( $sub_plugin );

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
				'Nexcess\PluginAbsorber\Loader',
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

	/**
	 * Whether it is already too late to wire the load hook.
	 *
	 * The comparison is inclusive. A callback added to the priority currently being dispatched is
	 * accepted and never reached either: WP_Hook::apply_filters() walks `$this->callbacks[$priority]`
	 * with a by-value foreach, so the append lands on an array the running loop has already copied.
	 * Booting from plugins_loaded at priority 2 is the case a host is likeliest to hit by accident,
	 * and an exclusive comparison would let exactly that one through unreported.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function wiring_window_has_closed(): bool {
		if ( ! did_action( 'plugins_loaded' ) ) {
			return false;
		}

		if ( ! doing_action( 'plugins_loaded' ) ) {
			return true;
		}

		$hook = $GLOBALS['wp_filter']['plugins_loaded'] ?? null;

		return $hook instanceof WP_Hook && $hook->current_priority() >= self::LOAD_PRIORITY;
	}

	/**
	 * Whether a hook prefix has been set, reporting to the developer when it has not.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function has_hook_prefix(): bool {
		try {
			Config::get_hook_prefix();
		} catch ( Config_Exception $exception ) {
			_doing_it_wrong( 'Nexcess\PluginAbsorber\Loader', $exception->getMessage(), '1.0.0' );

			return false;
		}

		return true;
	}
}
