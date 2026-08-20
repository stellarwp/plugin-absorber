<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Every registered sub-plugin, as something a pass can be handed rather than reach for.
 *
 * Registration is buffered before it is stored, and the buffer is static because it has to be:
 * `Absorber::register()` is a static call a host makes at plugin-file scope, before there is a
 * container to resolve a registrar from. What has to be decided is which class that costs — and it
 * is this one, not the facade. Everything that reads the registry (`Conflict\Detector`,
 * `Conflict\Resolver`, `Loader`, and `Conflict\Rewriter`) declares this
 * object in its constructor, so nothing but `Absorber` itself names `Absorber`, and the dependency
 * between the facade and the collaborators runs one way.
 *
 * The buffer is deliberately shared across instances. It is one process's registrations, and a second
 * reader holding a second, emptier list is the bug `Provider` binds every collaborator as a singleton
 * to avoid.
 *
 * Not `final`: it is bound by class name, which is the seam a host rebinds and a test subclasses.
 *
 * @since 1.0.0
 */
class Registry_Reader {
	/**
	 * Sub-plugins registered but not yet handed to the registrar.
	 *
	 * @since 1.0.0
	 *
	 * @var Sub_Plugin[]
	 */
	private static $pending = [];

	/**
	 * @since 1.0.0
	 *
	 * @var Registrar_Interface
	 */
	private $registrar;

	/**
	 * @since 1.0.0
	 *
	 * @param Registrar_Interface $registrar Where the registrations are kept.
	 */
	public function __construct( Registrar_Interface $registrar ) {
		$this->registrar = $registrar;
	}

	/**
	 * Hold a registration until something reads.
	 *
	 * Static, and it stores rather than registers, because `Absorber::register()` must resolve
	 * nothing: the host container LearnDash hands us is *replaced* at `plugins_loaded` priority 0, so
	 * a registration that reached a registrar before that point would go into the container being
	 * thrown away. Buffering is what lets the container arrive at any point before boot.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to hold.
	 *
	 * @return void
	 */
	public static function buffer( Sub_Plugin $sub_plugin ): void {
		self::$pending[] = $sub_plugin;
	}

	/**
	 * Every registered sub-plugin, keyed by slug, in registration order.
	 *
	 * The buffer is drained on the way past, which is why a pass is handed this rather than the
	 * registrar it could resolve for itself: a registrar asked directly would miss everything
	 * registered since the last read, a host registering from its own `plugins_loaded` callback
	 * included.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When two sub-plugins were registered under one slug.
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		$this->flush();

		// Registrar_Interface::all() can only declare `array` -- PHP 7.4 has no way to say
		// array<string,Sub_Plugin> in a signature -- so a host binding its own registrar may return
		// anything at all. Narrowed once here, where the untrusted value crosses into the library,
		// rather than at each call site: a consumer that forgot the check would fatal inside
		// plugins_loaded on its first predicate call, which is the exact failure this library
		// exists to prevent, and every future consumer would have to remember it too.
		return array_filter(
			$this->registrar->all(),
			static function ( $sub_plugin ): bool {
				return $sub_plugin instanceof Sub_Plugin;
			}
		);
	}

	/**
	 * Hand every buffered registration to the registrar.
	 *
	 * The registrar stays the single source of truth: the buffer is a pre-store that needs no
	 * container, and duplicate-slug detection and ordering remain the registrar's alone rather than
	 * being restated here in a second dialect.
	 *
	 * The buffer is emptied before the loop, so a second read cannot re-register what the registrar
	 * already holds and trip its duplicate-slug guard. Nothing has to empty it *after* a failure
	 * either: the registrar is a constructor argument, so a container that cannot build one fails
	 * while this object is being built, with the registrations still buffered for the read that comes
	 * after the host has fixed its bindings.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When two sub-plugins were registered under one slug.
	 *
	 * @return void
	 */
	protected function flush(): void {
		if ( self::$pending === [] ) {
			return;
		}

		$pending = self::$pending;

		self::$pending = [];

		foreach ( $pending as $sub_plugin ) {
			$this->registrar->register( $sub_plugin );
		}
	}
}
