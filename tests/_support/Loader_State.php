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
		'pending' => [],
		'booted'  => false,
	];

	/**
	 * Return every static property of `Loader` to its default, and unwire the hooks it added.
	 *
	 * Clearing the boot flag without unwiring would leave a `Loader` that reports itself unbooted
	 * while its callbacks are still attached: the next `boot()` would wire nothing, and still look
	 * like it had worked.
	 *
	 * @throws LogicException When `Loader` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$reflection = new ReflectionClass( Loader::class );

		// The memo lives on Container\Resolution now, but every test in the suite resets through
		// this one call, so it is dropped from here rather than making each of them reset twice.
		Resolution_State::reset();

		// Read rather than restated, so the helper unwires exactly what boot() wired -- including
		// any step the Loader grows later. Restating it would go on removing a callback from a
		// priority the Loader no longer uses, leaving the real one attached and every later test
		// loading sub-plugins it never registered.
		$sequence = $reflection->getConstant( 'SEQUENCE' );

		// Refused rather than coerced, for the same reason.
		if ( ! is_array( $sequence ) ) {
			throw new LogicException(
				sprintf( 'Loader::SEQUENCE must be an array for %s to unwire the hooks boot() added.', self::class )
			);
		}

		foreach ( $sequence as $method => $priority ) {
			if ( ! is_string( $method ) || ! is_int( $priority ) ) {
				throw new LogicException(
					sprintf( 'Loader::SEQUENCE must map method names to int priorities for %s to unwire them.', self::class )
				);
			}

			remove_action( 'plugins_loaded', [ Loader::class, $method ], $priority );
		}

		remove_action( 'all_admin_notices', [ Loader::class, 'render_notices' ] );

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
