<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict\Redirector;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Contracts\Provider_Interface;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Load\Runner;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Notices\Queue;
use Nexcess\PluginAbsorber\Notices\Renderer;
use Nexcess\PluginAbsorber\Notices\Store;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * Teaches the host's container how to build every collaborator this library uses.
 *
 * Binding only. Nothing here hooks, and nothing here resolves: `register()` runs at boot, when a
 * host may still be binding, and building an object at that point would pin whichever
 * implementation happened to be bound first.
 *
 * `final` because it is a list of bindings, and the way to change one is to bind it yourself
 * before boot rather than to inherit the list.
 *
 * @since 1.0.0
 */
final class Provider implements Provider_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @var ContainerInterface
	 */
	private $container;

	/**
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Container to bind into.
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		$container = $this->container;

		// The container under its own contract, so that a container which builds unbound classes
		// reflectively can still satisfy the two collaborators that take one. Bound first because
		// everything below it may be resolved that way.
		$this->bind_once( ContainerInterface::class, $container );

		$this->bind_once( Registrar_Interface::class, Registrar::class );
		$this->bind_once( Plugin_Checker_Interface::class, Plugin_Checker::class );
		$this->bind_once( Plugin_Deactivator_Interface::class, Plugin_Deactivator::class );
		$this->bind_once( Activator_Interface::class, Activator::class );
		$this->bind_once( Store::class );
		$this->bind_once( Renderer::class );
		$this->bind_once( Redirector::class );
		$this->bind_once( Gatekeeper::class );

		// Explicit factories rather than a class name for everything with a constructor argument:
		// container-contract promises `bind`, `get`, `has` and `singleton` and nothing about
		// autowiring, so a container that resolves nothing by reflection has to be told.
		$this->bind_once(
			Queue_Interface::class,
			static function () use ( $container ): Queue {
				return new Queue( $container->get( Store::class ), $container->get( Renderer::class ) );
			}
		);

		$this->bind_once(
			Resolver_Interface::class,
			static function () use ( $container ): Resolver {
				return new Resolver(
					$container->get( Plugin_Checker_Interface::class ),
					$container->get( Plugin_Deactivator_Interface::class ),
					$container->get( Queue_Interface::class ),
					$container->get( Redirector::class )
				);
			}
		);

		$this->bind_once(
			Runner::class,
			static function () use ( $container ): Runner {
				return new Runner(
					$container->get( Queue_Interface::class ),
					$container->get( Activator_Interface::class )
				);
			}
		);

		$this->bind_once(
			Scheduler::class,
			static function () use ( $container ): Scheduler {
				return new Scheduler( $container );
			}
		);
	}

	/**
	 * Bind as a singleton, unless the host already bound something.
	 *
	 * The host binds first and wins: this library's defaults are what a container has when nobody
	 * said otherwise, and a provider that overwrote a binding would make the order in which a host
	 * calls `set_container()` and `boot()` decide which implementation it gets.
	 *
	 * Singletons throughout. Every one of these is either a registry whose contents are the point
	 * or a stateless worker, and a second registrar would hold a second, emptier list of
	 * sub-plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id             Interface or class to bind.
	 * @param mixed  $implementation Class name, instance or factory closure; `null` to have the
	 *                               container build `$id` itself.
	 *
	 * @return void
	 */
	private function bind_once( string $id, $implementation = null ): void {
		if ( $this->container->has( $id ) ) {
			return;
		}

		$this->container->singleton( $id, $implementation );
	}
}
