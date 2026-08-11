<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use LogicException;
use Nexcess\PluginAbsorber\Loader;
use ReflectionClass;
use ReflectionProperty;

/**
 * Restores `Loader`'s static state between tests.
 *
 * `Loader` has no public way to clear itself, and deliberately so: a reset method would be API the
 * library then has to support forever for the sake of its own test suite, and a host that reached
 * for it mid-request would discard the registrations the load loop is about to read. Reflection
 * keeps that seam on this side of the fence.
 *
 * Dropping the memo is enough for the default collaborators, which are built per resolve. A
 * collaborator bound into a container as a singleton comes back populated on the next resolve, so
 * a test that binds one must build a fresh instance rather than expect this to empty it.
 */
class Loader_State {
	/**
	 * The value each of `Loader`'s static properties starts life with.
	 *
	 * Spelled out rather than read from `ReflectionClass::getDefaultProperties()`, which reports a
	 * static property's *current* value on PHP below 8.3 — a reset built on it is a silent no-op on
	 * the 7.4 leg.
	 *
	 * @var array<string,mixed>
	 */
	protected const DEFAULTS = [
		'resolved' => [],
		'pending'  => [],
	];

	/**
	 * Return every static property of `Loader` to its default.
	 *
	 * @throws LogicException When `Loader` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$reflection = new ReflectionClass( Loader::class );

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$name = $property->getName();

			if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new LogicException(
					sprintf( 'Loader::$%s has no default in %s. Add one.', $name, self::class )
				);
			}

			$property->setAccessible( true );
			$property->setValue( null, self::DEFAULTS[ $name ] );
		}
	}
}
