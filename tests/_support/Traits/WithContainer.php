<?php
/**
 * Stands up the container every test now needs.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use LogicException;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Provider;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * The container is required, so a test that reaches any collaborator has to set one.
 *
 * `set_up_container()` is the one-line form: it builds a container, runs the library's own provider
 * over it, and hands it to `Config`. A test about a *rebinding* host binds its implementation into a
 * container of its own and passes that in — the provider only binds what nothing else has, so the
 * host's binding survives, which is the guarantee those tests exist to pin.
 *
 * @since 1.0.0
 */
trait WithContainer {
	/**
	 * @var Test_Container|null
	 */
	private $absorber_container = null;

	/**
	 * Build (or adopt) a container, register the defaults into it, and configure the library with it.
	 *
	 * @since 1.0.0
	 *
	 * @param Test_Container|null $container Container to register into; a new one when omitted.
	 *
	 * @return Test_Container
	 */
	protected function set_up_container( ?Test_Container $container = null ): Test_Container {
		$container = $container ?? new Test_Container();

		// A host container that can hand out its own instance is the ordinary shape -- LearnDash's
		// App::container() is reachable from anywhere -- and it is what lets the container build a
		// collaborator that takes the container itself. Bound before the provider runs, like any
		// other host binding.
		if ( ! $container->has( ContainerInterface::class ) ) {
			$container->singleton(
				ContainerInterface::class,
				static function () use ( $container ): ContainerInterface {
					return $container;
				}
			);
		}

		( new Provider( $container ) )->register();

		Config::set_container( $container );

		$this->absorber_container = $container;

		return $container;
	}

	/**
	 * The container this test is running against.
	 *
	 * @since 1.0.0
	 *
	 * @throws LogicException When the test never stood one up, rather than failing later on a null.
	 *
	 * @return Test_Container
	 */
	protected function container(): Test_Container {
		if ( $this->absorber_container === null ) {
			throw new LogicException( 'Call set_up_container() before reading the container.' );
		}

		return $this->absorber_container;
	}

	/**
	 * Resolve one id, refusing anything that is not what was asked for.
	 *
	 * The contract's `get()` can only declare an untyped return, so every call site would otherwise
	 * repeat the same narrowing to say what it got back.
	 *
	 * @since 1.0.0
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $id Id to resolve.
	 *
	 * @throws LogicException When the container builds something else.
	 *
	 * @return T
	 */
	protected function resolve( string $id ): object {
		$instance = $this->container()->get( $id );

		if ( ! $instance instanceof $id ) {
			throw new LogicException( sprintf( 'The container did not build a %s.', $id ) );
		}

		/** @var T $instance */
		return $instance;
	}

	/**
	 * Forget the container between tests.
	 *
	 * Call from tearDown alongside `Config_State::reset()`, which clears the library's own reference.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down_container(): void {
		$this->absorber_container = null;
	}
}
