<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict\Redirector;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Contracts\Provider_Interface;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Notices\Renderer;
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Notices\Writer;
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
			Writer_Interface::class,
			static function () use ( $container ): Writer {
				return new Writer( $container->get( Store::class ) );
			}
		);

		$this->bind_once(
			Presenter::class,
			static function () use ( $container ): Presenter {
				return new Presenter( $container->get( Store::class ), $container->get( Renderer::class ) );
			}
		);

		$this->bind_once(
			Registry_Reader::class,
			static function () use ( $container ): Registry_Reader {
				return new Registry_Reader( $container->get( Registrar_Interface::class ) );
			}
		);

		$this->bind_once(
			Detector::class,
			static function () use ( $container ): Detector {
				return new Detector(
					$container->get( Registry_Reader::class ),
					$container->get( Plugin_Checker_Interface::class )
				);
			}
		);

		$this->bind_once(
			Resolver_Interface::class,
			static function () use ( $container ): Resolver {
				return new Resolver(
					$container->get( Registry_Reader::class ),
					$container->get( Detector::class ),
					$container->get( Plugin_Deactivator_Interface::class ),
					$container->get( Writer_Interface::class ),
					$container->get( Redirector::class )
				);
			}
		);

		$this->bind_once(
			Loader::class,
			static function () use ( $container ): Loader {
				return new Loader(
					$container->get( Registry_Reader::class ),
					$container->get( Writer_Interface::class ),
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
	 * calls `set_container()` and `boot()` decide which implementation it gets. It is also what
	 * keeps a second `register()` harmless, instead of swapping in a fresh registrar and losing
	 * every sub-plugin registered so far.
	 *
	 * The `class_exists()` half is what makes that question answerable at all. `has()` means "can
	 * return an entry", not "the host bound this" -- di52 answers it with `isBound() ||
	 * class_exists()`, so for a class id it is true before anything has been bound. Asked alone it
	 * stands down every binding above whose id is a class, the explicit factories included, leaving
	 * those collaborators autowired where the container autowires, broken where it does not, and
	 * singletons nowhere. An interface no container can build unprompted, so there the same call
	 * answers exactly what is being asked.
	 *
	 * What that costs is a host rebinding one of the concrete workers, which has to happen after
	 * boot: nothing here can tell that binding apart from the container's own willingness to build
	 * the class. The interface seams -- the ones a host is invited to replace -- are unaffected.
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
		if ( ! class_exists( $id ) && $this->container->has( $id ) ) {
			return;
		}

		$this->container->singleton( $id, $implementation );
	}
}
