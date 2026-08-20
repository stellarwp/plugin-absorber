<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use LogicException;
use Nexcess\PluginAbsorber\Config;
use ReflectionClass;
use ReflectionProperty;

/**
 * Restores `Config`'s static state between tests.
 *
 * `Config` is a static facade with no public way to clear itself, and deliberately so: a reset
 * method would be API the library then has to support forever for the sake of its own test suite.
 * Reflection keeps that seam on this side of the fence.
 */
class Config_State {
	/**
	 * The value each of `Config`'s static properties starts life with.
	 *
	 * Spelled out rather than read from `ReflectionClass::getDefaultProperties()`, which reports a
	 * static property's *current* value on PHP below 8.3 — a reset built on it is a silent no-op on
	 * the 7.4 leg.
	 *
	 * @var array<string,mixed>
	 */
	protected const DEFAULTS = [
		'hook_prefix' => '',
		'container'   => null,
	];

	/**
	 * Return every static property of `Config` to its default.
	 *
	 * @throws LogicException When `Config` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$reflection = new ReflectionClass( Config::class );

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$name = $property->getName();

			if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new LogicException(
					sprintf( 'Config::$%s has no default in %s. Add one.', $name, self::class )
				);
			}

			$property->setAccessible( true );
			$property->setValue( null, self::DEFAULTS[ $name ] );
		}
	}
}
