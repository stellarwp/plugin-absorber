<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Contracts\Provider_Interface;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;

/**
 * Static facade: registration, and the one call that starts everything.
 *
 * What a host touches, and deliberately little else. How collaborators are built belongs to
 * `Provider`, when they run to `Boot\Scheduler`, and the load pass itself to `Load\Runner` — so
 * the only reason to open this file is to change what a host may say to the library.
 *
 * `final` because it cannot usefully be extended: every member is private static and every internal
 * call is `self::`, so a subclass would inherit the API, be unable to override any of it, and change
 * nothing — which is the silent no-op this class reports on everywhere else.
 *
 * @since 1.0.0
 */
final class Loader {
	use Guards_Hook_Prefix;

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
	 * @throws Config_Exception When no container has been set, or its binding is unusable.
	 *
	 * @return Registrar_Interface
	 */
	public static function registrar(): Registrar_Interface {
		return self::collaborator( Registrar_Interface::class );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set, or its binding is unusable.
	 *
	 * @return Queue_Interface
	 */
	public static function notices(): Queue_Interface {
		return self::collaborator( Queue_Interface::class );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set, or its binding is unusable.
	 *
	 * @return Resolver_Interface
	 */
	public static function resolver(): Resolver_Interface {
		return self::collaborator( Resolver_Interface::class );
	}

	/**
	 * Register one bundled sub-plugin. Call once per sub-plugin, before boot().
	 *
	 * The sub-plugin is buffered rather than handed straight to the registrar, so that registering
	 * resolves nothing. Reaching the registrar needs the container, and a host that registers before
	 * it calls Config::set_container() would otherwise fail on a call that has nothing to do with
	 * the container. Buffering is what lets the container arrive at any point before boot, like
	 * every other configuration call.
	 *
	 * The configuration is still validated here: building the Sub_Plugin is what rejects it, and
	 * that happens at the call the host can see in its own stack trace. It is built rather than
	 * resolved because it is a value object — a container asked for one would need the config
	 * passed through it, and there is nothing about it to rebind.
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
	 * @throws Config_Exception When no container has been set, or two sub-plugins were registered
	 *                          under one slug.
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public static function all(): array {
		self::flush();

		// Registrar_Interface::all() can only declare `array` — PHP 7.4 has no way to say
		// array<string,Sub_Plugin> in a signature — so a host binding its own registrar may return
		// anything at all. Narrowed once here, where the untrusted value crosses into the library,
		// rather than at each call site: a consumer that forgot the check would fatal inside
		// plugins_loaded on its first predicate call, which is the exact failure this library
		// exists to prevent, and every future consumer would have to remember it too.
		return array_filter(
			self::registrar()->all(),
			static function ( $sub_plugin ): bool {
				return $sub_plugin instanceof Sub_Plugin;
			}
		);
	}

	/**
	 * Bind the collaborators, then let the scheduler decide when they run. Idempotent — safe to
	 * call from more than one code path.
	 *
	 * The provider is constructed rather than resolved: it is what teaches the container about this
	 * library, so the container cannot be asked to build it first. It is bound afterwards, and only
	 * when nothing answers to `Provider_Interface` already, so a host may replace the whole set of
	 * bindings with one of its own.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		$container = Config::get_container();

		if ( ! $container->has( Provider_Interface::class ) ) {
			$container->singleton( Provider_Interface::class, new Provider( $container ) );
		}

		$container->get( Provider_Interface::class )->register();
		$container->get( Scheduler::class )->wire();

		// Last, not first. A boot that threw on its way through -- no container, a binding that
		// cannot be built -- has wired nothing, and a host that fixes the mistake and calls again
		// should get a working library rather than a silent no-op.
		self::$booted = true;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set.
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
	 * Rewrite the activation-error notice for a standalone this library has absorbed.
	 *
	 * The parameter is untyped because a filter receives whatever the filter before it returned,
	 * and a `string` declaration would turn another plugin's sloppy return into a TypeError raised
	 * from here.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $markup Notice markup WordPress is about to print.
	 *
	 * @throws Config_Exception When no container has been set, or a container binding is unusable.
	 *
	 * @return string
	 */
	public static function filter_activation_error_markup( $markup ): string {
		$markup = is_string( $markup ) ? $markup : '';

		// Returned unchanged rather than thrown out of: this runs while WordPress is drawing an
		// error screen, and a second fatal there would replace the one the user came to read.
		if ( ! self::has_hook_prefix() ) {
			return $markup;
		}

		return self::notices()->filter_activation_error_markup( $markup );
	}

	/**
	 * The object bound to a collaborator interface, checked before it is handed on.
	 *
	 * The container's own return type promises nothing, so a host that bound the wrong class -- a
	 * typo'd class name, an interface it forgot to implement -- would otherwise surface as a
	 * TypeError raised inside this library, naming this library's method. That reads as a bug here
	 * rather than a mistake in the host's own bindings, and it happens inside `plugins_loaded`,
	 * where nobody is looking. Naming the interface and the class that failed it turns the same
	 * failure into an instruction.
	 *
	 * @since 1.0.0
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $interface Collaborator interface to resolve.
	 *
	 * @throws Config_Exception When no container has been set, or its binding does not implement
	 *                          the interface it was bound to.
	 *
	 * @return T
	 */
	private static function collaborator( string $interface ): object {
		$collaborator = Config::get_container()->get( $interface );

		if ( ! $collaborator instanceof $interface ) {
			throw new Config_Exception(
				sprintf(
					'The container binding for %s returned %s, which does not implement it.',
					$interface,
					is_object( $collaborator ) ? get_class( $collaborator ) : gettype( $collaborator )
				)
			);
		}

		return $collaborator;
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
	 * @throws Config_Exception When no container has been set, or two sub-plugins were registered
	 *                          under one slug.
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
}
