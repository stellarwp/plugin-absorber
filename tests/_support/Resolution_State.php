<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use LogicException;
use Nexcess\PluginAbsorber\Container\Resolution;
use ReflectionClass;
use ReflectionProperty;

/**
 * Restores `Container\Resolution`'s memo between tests.
 *
 * Separate from `Loader_State` because the memo is no longer the Loader's — but reached through it,
 * so the suite still has one call to make. See `Loader_State::reset()`.
 *
 * Dropping the memo is enough for the default collaborators, which are built per resolve. A
 * collaborator bound into a container as a singleton comes back populated on the next resolve, so
 * a test that binds one must build a fresh instance rather than expect this to empty it.
 */
class Resolution_State {
	/**
	 * The value each of `Resolution`'s static properties starts life with.
	 *
	 * Spelled out rather than read from `ReflectionClass::getDefaultProperties()`, which reports a
	 * static property's *current* value on PHP below 8.3 — a reset built on it is a silent no-op on
	 * the 7.4 leg.
	 *
	 * @var array<string,mixed>
	 */
	protected const DEFAULTS = [
		'resolved' => [],
	];

	/**
	 * Return every static property of `Resolution` to its default.
	 *
	 * @throws LogicException When `Resolution` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$reflection = new ReflectionClass( Resolution::class );

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$name = $property->getName();

			if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new LogicException(
					sprintf( 'Resolution::$%s has no default in %s. Add one.', $name, self::class )
				);
			}

			$property->setAccessible( true );
			$property->setValue( null, self::DEFAULTS[ $name ] );
		}
	}
}
