<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Throwable;

/**
 * Static facade: collaborator resolution, registration, hook wiring, and the load loop.
 *
 * @since 1.0.0
 */
class Loader {
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
}
