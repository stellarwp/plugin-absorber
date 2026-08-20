<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Closure;
use LogicException;
use Nexcess\PluginAbsorber\Absorber;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use WP_Hook;

/**
 * Restores `Absorber`'s static state between tests, and unwires the hooks boot() added.
 *
 * `Absorber` has no public way to clear itself, and deliberately so: a reset method would be API the
 * library then has to support forever for the sake of its own test suite, and a host that reached for
 * it mid-request would discard the registrations the load loop is about to read. Reflection keeps that
 * seam on this side of the fence.
 *
 * Collaborators are the container's now, so there is no memo here to drop: a test gets a fresh set by
 * standing up a fresh container, which `Traits\WithContainer` does in one line.
 */
class Absorber_State {
	/**
	 * The value each of `Absorber`'s static properties starts life with.
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
	 * The hooks boot() reaches, directly or through `Boot\Scheduler`.
	 *
	 * @var string[]
	 */
	protected const HOOKS = [
		'plugins_loaded',
		'all_admin_notices',
		'wp_admin_notice_markup',
	];

	/**
	 * Namespace every callback this library wires belongs to.
	 *
	 * @var string
	 */
	protected const NAMESPACE_PREFIX = 'Nexcess\\PluginAbsorber\\';

	/**
	 * Return every static property of `Absorber` to its default, and unwire the hooks it added.
	 *
	 * Clearing the boot flag without unwiring would leave an `Absorber` that reports itself unbooted
	 * while its callbacks are still attached: the next `boot()` would wire nothing, and still look
	 * like it had worked.
	 *
	 * @throws LogicException When `Absorber` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::unwire();

		$reflection = new ReflectionClass( Absorber::class );

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$name = $property->getName();

			if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new LogicException(
					sprintf( 'Absorber::$%s has no default in %s. Add one.', $name, self::class )
				);
			}

			$property->setAccessible( true );
			$property->setValue( null, self::DEFAULTS[ $name ] );
		}
	}

	/**
	 * Take back every callback this library put on the boot hooks.
	 *
	 * Identified by where the callback comes from rather than by restating what boot() wires. The
	 * steps are closures over the container now, so there is no name to match on and no
	 * `has_action( $hook, [ Absorber::class, 'load_all' ] )` to remove them by; and
	 * `remove_all_actions()` would strip the hook bare, discarding every callback WordPress and the
	 * rest of the suite have on it for the remainder of the process.
	 *
	 * Reading `Boot\Scheduler::SEQUENCE` for its priorities would be the narrower sweep, but it would
	 * also miss a step wired at a priority the constant no longer names — which is exactly the drift
	 * an unwire helper exists to survive.
	 *
	 * @return void
	 */
	protected static function unwire(): void {
		foreach ( self::HOOKS as $hook ) {
			$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;

			if ( ! $wp_hook instanceof WP_Hook ) {
				continue;
			}

			// Iterating a by-value copy, so removing as we go cannot disturb the walk.
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				if ( ! is_int( $priority ) || ! is_array( $callbacks ) ) {
					continue;
				}

				foreach ( $callbacks as $registered ) {
					$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;

					if ( $callback !== null && is_callable( $callback ) && self::belongs_to_the_library( $callback ) ) {
						remove_action( $hook, $callback, $priority );
					}
				}
			}
		}
	}

	/**
	 * Whether a registered callback came out of this library.
	 *
	 * Covers both shapes a hook callback can take here: a closure written in `src/`, and a static or
	 * instance method on one of the library's own classes.
	 *
	 * @param callable $callback Callback registered on one of the boot hooks.
	 *
	 * @return bool
	 */
	protected static function belongs_to_the_library( callable $callback ): bool {
		if ( $callback instanceof Closure ) {
			$file = ( new ReflectionFunction( $callback ) )->getFileName();

			return is_string( $file ) && strpos( $file, self::source_directory() ) === 0;
		}

		if ( is_array( $callback ) ) {
			$target = $callback[0] ?? null;
			$class  = is_object( $target ) ? get_class( $target ) : $target;

			return is_string( $class ) && strpos( $class, self::NAMESPACE_PREFIX ) === 0;
		}

		return is_string( $callback ) && strpos( $callback, self::NAMESPACE_PREFIX ) === 0;
	}

	/**
	 * Where the library's own source lives, read off a class rather than assumed from this file.
	 *
	 * @throws LogicException When the path cannot be read, rather than matching every closure on
	 *                        an empty prefix and unwiring the whole site.
	 *
	 * @return string
	 */
	protected static function source_directory(): string {
		$file = ( new ReflectionClass( Absorber::class ) )->getFileName();

		if ( ! is_string( $file ) || $file === '' ) {
			throw new LogicException( sprintf( 'Could not locate the library source from %s.', self::class ) );
		}

		return dirname( $file ) . DIRECTORY_SEPARATOR;
	}
}
