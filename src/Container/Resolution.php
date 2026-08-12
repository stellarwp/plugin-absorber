<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Container;

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Notices\Queue;
use Nexcess\PluginAbsorber\Registrar;
use Throwable;

/**
 * Turns an interface into the object that implements it, from the container or from the default.
 *
 * Separate from `Loader` because it shares nothing with it: no hooks, no registration buffer, no
 * load loop. Keeping it here is what lets a collaborator depend on the narrow thing it actually
 * needs — "give me the notice queue" — rather than on the whole facade, which is how a collaborator
 * ends up reaching back into static wiring it has no business knowing about.
 *
 * `Loader` keeps its own accessors as one-line delegations, so a host still calls
 * `Loader::notices()` and nothing about the public surface changes.
 *
 * @since 1.0.0
 */
class Resolution {
	/**
	 * Resolved collaborators, memoized by interface name.
	 *
	 * @var array<string,object>
	 */
	private static $resolved = [];

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable instance.
	 *
	 * @return Registrar_Interface
	 */
	public static function registrar(): Registrar_Interface {
		return self::get( Registrar_Interface::class, Registrar::class );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable instance.
	 *
	 * @return Queue_Interface
	 */
	public static function notices(): Queue_Interface {
		return self::get( Queue_Interface::class, Queue::class );
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
	 * Generic rather than one method per collaborator, so each accessor can land with the PR that
	 * needs it without this one being rewritten each time.
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
	public static function get( string $interface, string $default_class ): object {
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
