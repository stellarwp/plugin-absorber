<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * Wraps a `lucatume\DI52\Container` so it satisfies `ContainerInterface` in tests.
 *
 * DI52's own container implements PSR-11's `ContainerInterface`, not StellarWP's — this adapter
 * closes that gap, modelled on `stellarwp/container-contract`'s own `examples/di52/Container.php`.
 */
class Test_Container implements ContainerInterface {
	/**
	 * @var DI52Container
	 */
	protected $container;

	/**
	 * @param DI52Container|null $container Container to wrap; a new one is created when omitted.
	 */
	public function __construct( ?DI52Container $container = null ) {
		$this->container = $container ?: new DI52Container();
	}

	/**
	 * @inheritDoc
	 */
	public function bind( string $id, $implementation = null ) {
		$this->container->bind( $id, $implementation );
	}

	/**
	 * @inheritDoc
	 */
	public function get( string $id ) {
		return $this->container->get( $id );
	}

	/**
	 * Reports whether the container can return an entry for the id.
	 *
	 * Inherits DI52's permissive semantics: any existing *class* name reports true even with
	 * nothing bound, because DI52 falls back to `class_exists()`. Interface names are unaffected.
	 * `isBound()` below is the narrower question — whether a binding was actually made — and this
	 * adapter exposes it so that a caller distinguishing the two reaches the same method on a real
	 * host container.
	 *
	 * @inheritDoc
	 */
	public function has( string $id ) {
		return $this->container->has( $id );
	}

	/**
	 * Whether something was bound to the id, autowirable class names excluded.
	 *
	 * Not part of `ContainerInterface`. It is on DI52, and on the adapters hosts wrap DI52 in — the
	 * example adapter in `stellarwp/container-contract` forwards unknown calls through `__call()` —
	 * so a collaborator probing for it finds it here as it would in production.
	 *
	 * @param string $id Identifier of the entry to look for.
	 *
	 * @return bool
	 */
	public function isBound( string $id ): bool {
		return $this->container->isBound( $id );
	}

	/**
	 * @inheritDoc
	 */
	public function singleton( string $id, $implementation = null ) {
		$this->container->singleton( $id, $implementation );
	}
}
