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
	 * @inheritDoc
	 */
	public function has( string $id ) {
		return $this->container->has( $id );
	}

	/**
	 * @inheritDoc
	 */
	public function singleton( string $id, $implementation = null ) {
		$this->container->singleton( $id, $implementation );
	}
}
