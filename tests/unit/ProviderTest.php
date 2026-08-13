<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Activator;
use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict\Redirector;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Contracts\Provider_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Notices\Renderer;
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Notices\Writer;
use Nexcess\PluginAbsorber\Plugin\Checker;
use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;
use Nexcess\PluginAbsorber\Plugin\Contracts\Deactivator_Interface;
use Nexcess\PluginAbsorber\Plugin\Deactivator;
use Nexcess\PluginAbsorber\Provider;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Registry\Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Activator;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Resolver;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Writer;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * The one place the library's defaults are named.
 *
 * With the container required, nothing falls back to `new` any more: an id the provider forgets is an
 * id nothing can resolve, and the failure lands inside `plugins_loaded` on a host site. So each
 * binding is asserted by resolving it, not by inspecting the provider.
 *
 * @since 1.0.0
 */
class ProviderTest extends WPTestCase {
	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
	}

	public function tearDown(): void {
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_satisfies_the_contract(): void {
		$this->assertInstanceOf( Provider_Interface::class, new Provider( new Test_Container() ) );
	}

	/**
	 * @dataProvider default_bindings
	 *
	 * @param string       $id       Id the library resolves.
	 * @param class-string $expected Class the container must build for it.
	 */
	public function test_it_binds_every_default( string $id, string $expected ): void {
		$container = $this->registered_container();

		$this->assertInstanceOf( $expected, $container->get( $id ) );
	}

	/**
	 * Every id the library asks the container for. A collaborator added without a line here is one
	 * the container cannot build, which is a fatal at plugins_loaded rather than a missing default.
	 *
	 * @return Generator<string,array{0:string,1:class-string}>
	 */
	public static function default_bindings(): Generator {
		yield 'the registrar'         => [ Registrar_Interface::class, Registrar::class ];
		yield 'the registry reader'   => [ Reader::class, Reader::class ];
		yield 'the notice writer'     => [ Writer_Interface::class, Writer::class ];
		yield 'the notice store'      => [ Store::class, Store::class ];
		yield 'the notice renderer'   => [ Renderer::class, Renderer::class ];
		yield 'the notice presenter'  => [ Presenter::class, Presenter::class ];
		yield 'the plugin checker'    => [ Checker_Interface::class, Checker::class ];
		yield 'the deactivator'       => [ Deactivator_Interface::class, Deactivator::class ];
		yield 'the activator'         => [ Activator_Interface::class, Activator::class ];
		yield 'the conflict resolver' => [ Resolver_Interface::class, Resolver::class ];
		yield 'the conflict detector' => [ Detector::class, Detector::class ];
		yield 'the redirector'        => [ Redirector::class, Redirector::class ];
		yield 'the conflict gate'     => [ Gatekeeper::class, Gatekeeper::class ];
		yield 'the load runner'       => [ Loader::class, Loader::class ];
		yield 'the boot scheduler'    => [ Scheduler::class, Scheduler::class ];
	}

	/**
	 * The registrar holds the registrations and the queue holds its store, so a binding rebuilt per
	 * call would hand the load loop a registry the flush never reached. The rest are stateless, and
	 * are here because a class id that resolves twice over is a class id the provider never bound:
	 * the container is autowiring it, which means the explicit factory never ran.
	 *
	 * @dataProvider single_instance_bindings
	 *
	 * @param string $id Id that must resolve to one instance per container.
	 */
	public function test_a_binding_resolves_to_one_instance( string $id ): void {
		$container = $this->registered_container();

		$this->assertSame( $container->get( $id ), $container->get( $id ) );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function single_instance_bindings(): Generator {
		yield 'the registrar'         => [ Registrar_Interface::class ];
		yield 'the registry reader'   => [ Reader::class ];
		yield 'the notice writer'     => [ Writer_Interface::class ];
		yield 'the notice store'      => [ Store::class ];
		yield 'the notice renderer'   => [ Renderer::class ];
		yield 'the notice presenter'  => [ Presenter::class ];
		yield 'the conflict resolver' => [ Resolver_Interface::class ];
		yield 'the conflict detector' => [ Detector::class ];
		yield 'the redirector'        => [ Redirector::class ];
		yield 'the conflict gate'     => [ Gatekeeper::class ];
		yield 'the load runner'       => [ Loader::class ];
		yield 'the boot scheduler'    => [ Scheduler::class ];
	}

	/**
	 * A class id is the case the guard gets wrong for free: DI52 answers `has()` with
	 * `isBound() || class_exists()`, so every id below reports true before anything is bound. A
	 * provider trusting that reply binds none of them — the explicit factories written for a
	 * container that does not autowire included — and nothing says so, because a container that
	 * *does* autowire still hands back an object.
	 *
	 * @dataProvider class_id_bindings
	 *
	 * @param string $id Class id the provider must bind regardless.
	 */
	public function test_it_binds_a_class_id_the_container_reports_it_already_has( string $id ): void {
		$container = new Test_Container();

		$this->assertTrue( $container->has( $id ), 'has() is expected to be true for an existing class.' );
		$this->assertFalse( $container->isBound( $id ), 'Nothing has bound the id yet.' );

		( new Provider( $container ) )->register();

		$this->assertTrue( $container->isBound( $id ) );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function class_id_bindings(): Generator {
		yield 'the registry reader'   => [ Reader::class ];
		yield 'the notice store'      => [ Store::class ];
		yield 'the notice renderer'   => [ Renderer::class ];
		yield 'the notice presenter'  => [ Presenter::class ];
		yield 'the conflict detector' => [ Detector::class ];
		yield 'the redirector'        => [ Redirector::class ];
		yield 'the conflict gate'     => [ Gatekeeper::class ];
		yield 'the load runner'       => [ Loader::class ];
		yield 'the boot scheduler'    => [ Scheduler::class ];
	}

	/**
	 * The guard, which is the whole reason a host can rebind anything: the provider runs over the
	 * host's own container, so binding first has to be binding last.
	 *
	 * @dataProvider host_bindings
	 *
	 * @param string $id    Id the host bound first.
	 * @param object $bound Implementation the host bound.
	 */
	public function test_it_leaves_a_binding_the_host_made_first_alone( string $id, object $bound ): void {
		$container = new Test_Container();
		$container->singleton(
			$id,
			static function () use ( $bound ): object {
				return $bound;
			}
		);

		( new Provider( $container ) )->register();

		$this->assertSame( $bound, $container->get( $id ) );
	}

	/**
	 * Interface ids only, which is as far as the guard reaches. Nothing can build an interface
	 * unprompted, so `has()` is true there only where a binding exists and the host's object
	 * survives. A class id cannot be covered: di52 answers `has()` for one with the same true
	 * whether or not anything was bound, so the provider cannot see the host's binding and
	 * replaces it — a host rebinding `Store`, `Renderer`, `Presenter`, `Loader` or
	 * `Boot\Scheduler` has to do it after boot.
	 *
	 * @return Generator<string,array{0:string,1:object}>
	 */
	public static function host_bindings(): Generator {
		yield 'the registrar'         => [ Registrar_Interface::class, new Spy_Registrar() ];
		yield 'the notice writer'     => [ Writer_Interface::class, new Spy_Writer() ];
		yield 'the conflict resolver' => [ Resolver_Interface::class, new Spy_Resolver() ];

		// "Once, ever" is recorded in an option here, which is one opinion among several — a host
		// that tracks it in its own migration table has to be able to say so.
		yield 'the activator'         => [ Activator_Interface::class, new Spy_Activator() ];
	}

	/**
	 * Registering twice is the shape a host lands in by wiring the provider from two entry points.
	 * The second pass must not replace instances the first pass already handed out.
	 */
	public function test_registering_twice_keeps_the_first_instances(): void {
		$container = $this->registered_container();
		$registrar = $container->get( Registrar_Interface::class );

		( new Provider( $container ) )->register();

		$this->assertSame( $registrar, $container->get( Registrar_Interface::class ) );
	}

	/**
	 * A container with the defaults registered into it, plus the self-binding a host container
	 * ordinarily offers.
	 *
	 * @return Test_Container
	 */
	private function registered_container(): Test_Container {
		$container = new Test_Container();
		$container->singleton(
			ContainerInterface::class,
			static function () use ( $container ): ContainerInterface {
				return $container;
			}
		);

		( new Provider( $container ) )->register();

		return $container;
	}
}
