<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Registrar;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * @since 1.0.0
 */
class LoaderResolveTest extends WPTestCase {
	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
	}

	public function tearDown(): void {
		Loader_State::reset();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_falls_back_to_the_default_registrar_without_a_container(): void {
		$this->assertInstanceOf( Registrar::class, Loader::registrar() );
	}

	public function test_it_memoizes_the_resolved_collaborator(): void {
		$this->assertSame( Loader::registrar(), Loader::registrar() );
	}

	/**
	 * Both binding styles arrive at the same instance: the memo is what makes even a bind(), which
	 * the container would otherwise rebuild on every call, resolve exactly once.
	 *
	 * @dataProvider container_binding_methods
	 *
	 * @param string $binding_method Container method the host bound the registrar with.
	 */
	public function test_it_resolves_a_bound_registrar_from_the_container( string $binding_method ): void {
		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->{$binding_method}( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		$this->assertSame( $bound, Loader::registrar() );
		$this->assertSame( $bound, Loader::registrar(), 'The binding must be resolved only once.' );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function container_binding_methods(): Generator {
		yield 'singleton' => [ 'singleton' ];
		yield 'bind'      => [ 'bind' ];
	}

	public function test_it_ignores_a_container_with_no_binding(): void {
		$container = new Test_Container();

		$this->assertFalse(
			$container->has( Registrar_Interface::class ),
			'DI52 reports has() true for any existing class name; this must stay an interface.'
		);

		Config::set_container( $container );

		$this->assertInstanceOf( Registrar::class, Loader::registrar() );
	}

	/**
	 * Checked before the instance is memoized. Caching it would make every accessor throw a
	 * TypeError blaming the library, with no way back.
	 *
	 * @dataProvider unusable_bindings
	 *
	 * @param mixed  $bound         Whatever the host's factory hands back.
	 * @param string $reported_type How the rejection is expected to name it.
	 */
	public function test_it_rejects_a_binding_that_does_not_implement_the_interface(
		$bound,
		string $reported_type
	): void {
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		try {
			Loader::registrar();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( Registrar_Interface::class, $exception->getMessage() );
			$this->assertStringContainsString(
				$reported_type,
				$exception->getMessage(),
				'The message has to name what came back, or the host cannot tell which binding is wrong.'
			);
		}

		Config_State::reset();

		$this->assertInstanceOf(
			Registrar::class,
			Loader::registrar(),
			'The bad instance must not have been cached, or there would be no way back.'
		);
	}

	/**
	 * A factory is free to return anything at all, and the two non-object cases are the ones that
	 * reach the branch reporting a type name rather than a class name.
	 *
	 * @return Generator<string,array{0:mixed,1:string}>
	 */
	public static function unusable_bindings(): Generator {
		yield 'an instance of the wrong class'        => [ new stdClass(), 'stdClass' ];
		yield 'the class name instead of an instance' => [ Registrar::class, 'string' ];
		yield 'nothing at all'                        => [ null, 'NULL' ];
	}

	/**
	 * A bound factory may throw, and a container asked for something it cannot build throws its own
	 * exception type; the contract is explicit that has() true does not promise get() succeeds.
	 * Left uncaught, the host gets a fatal from a vendor namespace at plugins_loaded that names
	 * neither this library nor the binding at fault.
	 */
	public function test_it_reports_a_container_that_throws_as_a_configuration_error(): void {
		$failure   = new RuntimeException( 'the host factory needed a database connection' );
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $failure ): Registrar_Interface {
				throw $failure;
			}
		);
		Config::set_container( $container );

		try {
			Loader::registrar();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString(
				Registrar_Interface::class,
				$exception->getMessage(),
				'The message has to name the binding that could not be built.'
			);
			$this->assertContains(
				$failure,
				$this->previous_chain( $exception ),
				'The original failure has to stay reachable, or the real cause is lost.'
			);
		}

		Config_State::reset();

		$this->assertInstanceOf(
			Registrar::class,
			Loader::registrar(),
			'A binding that could not be built must not have been memoized.'
		);
	}

	/**
	 * A binding that could not be built leaves the registrations buffered, so the read that comes
	 * after the host fixes its container still has them. Emptying the buffer before the registrar
	 * resolved would drop them silently and load nothing.
	 */
	public function test_registrations_survive_a_container_that_throws(): void {
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function (): Registrar_Interface {
				throw new RuntimeException( 'not today' );
			}
		);
		Config::set_container( $container );

		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		try {
			Loader::all();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( Registrar_Interface::class, $exception->getMessage() );
		}

		Config_State::reset();

		$this->assertArrayHasKey(
			'give-recurring',
			Loader::all(),
			'The buffered registration must still be there once the container is usable.'
		);
	}

	/**
	 * The memo is what makes the container optional without paying for a lookup per call. Reading a
	 * collaborator is a boot-time act, so a host that has done it has finished configuring.
	 */
	public function test_a_container_set_after_the_first_resolve_does_not_change_the_memo(): void {
		$default = Loader::registrar();

		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		$this->assertSame(
			$default,
			Loader::registrar(),
			'Swapping a collaborator mid-request would strand whatever already holds the old one.'
		);
	}

	public function test_register_builds_a_sub_plugin_and_stores_it(): void {
		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		$all = Loader::all();

		$this->assertCount( 1, $all );
		$this->assertInstanceOf( Sub_Plugin::class, $all['give-recurring'] );
		$this->assertSame( 'give-recurring', $all['give-recurring']->get_slug() );
	}

	public function test_register_rejects_an_invalid_config(): void {
		$this->expectException( Config_Exception::class );

		Loader::register( [ 'slug' => 'give-recurring' ] );
	}

	/**
	 * Registering must not resolve anything, or the first register() call would pin the default
	 * registrar for the whole request.
	 */
	public function test_register_resolves_nothing_until_the_first_read(): void {
		$builds    = 0;
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( &$builds ): Registrar_Interface {
				++$builds;

				return new Spy_Registrar();
			}
		);
		Config::set_container( $container );

		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		$this->assertSame( 0, $builds, 'register() must not reach the container.' );

		Loader::all();

		$this->assertSame( 1, $builds, 'The first read is what resolves the registrar.' );
	}

	/**
	 * The headline of deferring registration: the container is a configuration call like any other,
	 * so it may arrive after the sub-plugins do. Resolving inside register() dropped it silently.
	 */
	public function test_a_container_set_after_register_takes_effect(): void {
		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		Loader::all();

		$this->assertArrayHasKey(
			'give-recurring',
			$bound->sub_plugins,
			'The bound registrar must receive registrations made before it was bound.'
		);
	}

	public function test_register_delegates_to_a_bound_registrar(): void {
		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );
		Loader::all();

		$this->assertArrayHasKey( 'give-recurring', $bound->sub_plugins );
	}

	/**
	 * The buffer is emptied as it drains. Left full, the second read hands the registrar a slug it
	 * already holds and the duplicate guard fires on a registration the host only made once.
	 */
	public function test_reading_twice_does_not_register_twice(): void {
		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		Loader::all();
		Loader::all();

		$this->assertSame( 1, $bound->register_calls );
	}

	/**
	 * Deferring registration moves the duplicate-slug report from the second register() call to the
	 * first read. It still names both bundled files, which is what the host needs to find them.
	 */
	public function test_a_duplicate_slug_is_refused_at_the_first_read(): void {
		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );
		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => '/tmp/other/other.php',
				'plugin_loaded_constant' => 'OTHER_VERSION_FIXTURE',
			]
		);

		try {
			Loader::all();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
			$this->assertStringContainsString( '/tmp/other/other.php', $exception->getMessage() );
		}
	}

	public function test_all_is_empty_before_anything_is_registered(): void {
		$this->assertSame( [], Loader::all() );
	}

	/**
	 * The buffer is static state like the memo, so the suite's reset helper has to reach it too.
	 * A registration left buffered by one test drains into the next test's registrar.
	 */
	public function test_the_state_helper_clears_buffered_registrations(): void {
		Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

		Loader_State::reset();

		$this->assertSame( [], Loader::all() );
	}

	/**
	 * Build the raw configuration array a host writes.
	 *
	 * The shared WithSubPlugins trait builds `Sub_Plugin` objects; `Loader::register()` takes the
	 * array instead, because building that object is the part of registration under test here. The
	 * derived values follow the trait's: a per-slug bundled file, and a guard constant carrying the
	 * `_FIXTURE` suffix nothing ever defines, since a define() lasts for the whole PHP process.
	 *
	 * @param string $slug Sub-plugin slug.
	 *
	 * @return array<string,mixed>
	 */
	private function sub_plugin_config( string $slug ): array {
		return [
			'slug'                   => $slug,
			'bundled_plugin_file'    => "/tmp/{$slug}/{$slug}.php",
			'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
		];
	}

	/**
	 * Every exception behind the given one, nearest first.
	 *
	 * Walked rather than read a single level deep: a container is entitled to wrap what a factory
	 * threw in its own exception type before it reaches us, and it does.
	 *
	 * @param Throwable $exception Exception to walk.
	 *
	 * @return Throwable[]
	 */
	private function previous_chain( Throwable $exception ): array {
		$chain = [];

		for ( $previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious() ) {
			$chain[] = $previous;
		}

		return $chain;
	}
}
