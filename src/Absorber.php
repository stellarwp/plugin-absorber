<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Rewriter;
use Nexcess\PluginAbsorber\Contracts\Provider_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;
use Throwable;

/**
 * Static facade: registration, and the one call that starts everything.
 *
 * What a host touches, and deliberately little else. How collaborators are built belongs to
 * `Provider`, when they run to `Boot\Scheduler`, and the load pass itself to `Loader` — so
 * the only reason to open this file is to change what a host may say to the library.
 *
 * `final` because it cannot usefully be extended: every member is private static and every internal
 * call is `self::`, so a subclass would inherit the API, be unable to override any of it, and change
 * nothing — which is the silent no-op this class reports on everywhere else.
 *
 * @since 1.0.0
 */
final class Absorber {
	use Guards_Hook_Prefix;

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
	 * @return Writer_Interface
	 */
	public static function notices(): Writer_Interface {
		return self::collaborator( Writer_Interface::class );
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
	 * resolves nothing — not even the container. A host that registers before it calls
	 * Config::set_container() would otherwise fail on a call that has nothing to do with the
	 * container. The buffer belongs to `Registry\Reader`, which is where it is read back out: this
	 * class hands its collaborators no work and holds none of their state.
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
		Reader::buffer( new Sub_Plugin( $config ) );
	}

	/**
	 * Every registered sub-plugin, keyed by slug, in registration order.
	 *
	 * A delegation like the accessors above it, and for the same reason: what a host calls is here,
	 * what it does is the collaborator's. The passes that read the registry are handed that
	 * collaborator directly rather than calling back through this method — a facade sits in front of
	 * its collaborators, never underneath them.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set, or its binding is unusable.
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public static function all(): array {
		return self::collaborator( Reader::class )->all();
	}

	/**
	 * Bind the collaborators, then let the scheduler decide when they run. Idempotent — safe to
	 * call from more than one code path.
	 *
	 * Idempotent means the first call wins outright, and the container is part of what it wins.
	 * A Config::set_container() after this has returned binds nothing: the scheduler keeps the
	 * container it closed over, while the accessors and the notice trampolines resolve from
	 * whatever Config holds when they are called, so the two halves would answer to different
	 * containers and the accessors would ask an unbound one. Set the container first — the
	 * recommended slot is plugins_loaded priority 0 — and do not replace it afterwards.
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

		// The messages a presenter draws were worded by host callables, so rendering runs somebody
		// else's code -- on all_admin_notices, which every admin screen fires. A throw out of here
		// would white-screen wp-admin, which is exactly where a site owner would go to undo whatever
		// caused it. The notice is worth less than the screen it would be read on.
		try {
			self::collaborator( Presenter::class )->render();
		} catch ( Throwable $thrown ) {
			_doing_it_wrong(
				self::class . '::render_notices',
				sprintf( 'The notices could not be rendered: %s', $thrown->getMessage() ),
				'1.0.0'
			);
		}
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
	 * @return string
	 */
	public static function filter_activation_error_markup( $markup ): string {
		$markup = is_string( $markup ) ? $markup : '';

		if ( ! self::has_hook_prefix() ) {
			return $markup;
		}

		// Guarded like render_notices, and for a sharper version of the same reason: this runs while
		// WordPress is drawing the screen that reports a fatal, so a throw out of here would replace
		// the error the admin came to read with one of ours. The markup goes back as it arrived and
		// core's wording stands.
		try {
			return self::collaborator( Rewriter::class )->rewrite( $markup );
		} catch ( Throwable $thrown ) {
			_doing_it_wrong(
				self::class . '::filter_activation_error_markup',
				sprintf( 'The activation error notice could not be rewritten: %s', $thrown->getMessage() ),
				'1.0.0'
			);

			return $markup;
		}
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
	 * @throws Config_Exception When no container has been set, when it throws while building the
	 *                          binding, or when the binding does not implement the interface it was
	 *                          bound to.
	 *
	 * @return T
	 */
	private static function collaborator( string $interface ): object {
		// Resolved outside the try: a missing container is this library's own configuration error
		// already, reported in its own words, and re-wrapping it would bury that sentence one
		// exception deeper for no gain.
		$container = Config::get_container();

		// A host factory closure is free to throw, and a container asked for a binding with an
		// unsatisfiable dependency -- or for an interface nothing has bound yet, which is every
		// interface before boot() runs the provider -- throws its own exception type. Uncaught,
		// either one leaves the host's plugins_loaded with a fatal from a vendor namespace that
		// names neither this library nor the binding at fault, so both are reported the same way as
		// a binding of the wrong type. The original failure is kept as the previous exception.
		try {
			$collaborator = $container->get( $interface );
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
}
