<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Conflict\Rewriter;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Notices\Writer;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registry\Registrar;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Presenter;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Rewriter;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The public surface: the accessors, registration, and the two notice trampolines.
 *
 * Boot timing lives in `Boot\SchedulerTest` and the load loop in `LoaderTest`, which is where
 * those behaviours moved. What is left here is what a host actually calls.
 *
 * @since 1.0.0
 */
class AbsorberTest extends WPTestCase {
	use WithContainer;
	use WithIncorrectUsage;

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
	}

	public function tearDown(): void {
		$this->stop_expecting_incorrect_usage();
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	/**
	 * @dataProvider collaborator_accessors
	 *
	 * @param string       $accessor Static method on Absorber.
	 * @param class-string $expected Class the provider's default binding builds.
	 */
	public function test_the_accessors_read_from_the_container( string $accessor, string $expected ): void {
		$this->set_up_container();

		$this->assertInstanceOf( $expected, Absorber::{$accessor}() );
	}

	/**
	 * A host binds the wrong class far more easily than it binds none at all, and the container
	 * hands back whatever it was told to without checking. Left to PHP's own return-type check the
	 * failure is a TypeError naming this library's method, raised inside plugins_loaded, which
	 * reads as a bug here rather than as the typo it is.
	 */
	public function test_a_binding_that_does_not_implement_its_interface_is_reported(): void {
		$container = new Test_Container();

		$container->singleton( Registrar_Interface::class, static function (): object {
			return new class() {
				// Anything at all, so long as it is not a registrar.
			};
		} );

		Config::set_container( $container );

		try {
			Absorber::registrar();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( Registrar_Interface::class, $exception->getMessage() );
			$this->assertStringContainsString(
				'does not implement',
				$exception->getMessage(),
				'A binding that built fine and is simply the wrong type must not be reported as one '
					. 'the container could not build: the two send the host to different files.'
			);
		}
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
		$this->set_up_container( $container );

		try {
			Absorber::registrar();
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
	}

	/**
	 * The missing-container report is this library's own sentence, in its own words. Wrapping it in
	 * the build-failure message would bury it a level deeper and name an interface the host never
	 * bound anything to, when what it has to hear is that it set no container at all.
	 */
	public function test_a_missing_container_is_not_reported_as_a_failed_binding(): void {
		try {
			Absorber::registrar();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringNotContainsString( 'failed to build', $exception->getMessage() );
			$this->assertNull( $exception->getPrevious(), 'There is no earlier failure to point at.' );
		}
	}

	/**
	 * The accessors are the host's own way in, so each one has to survive the container becoming
	 * required rather than only the paths the library happens to take.
	 *
	 * @return Generator<string,array{0:string,1:class-string}>
	 */
	public static function collaborator_accessors(): Generator {
		yield 'the registrar'         => [ 'registrar', Registrar::class ];
		yield 'the notice writer'     => [ 'notices', Writer::class ];
		yield 'the conflict resolver' => [ 'resolver', Resolver::class ];
	}

	/**
	 * Nothing falls back to `new` any more. A host that never set a container gets a
	 * Config_Exception naming the mistake, not a default collaborator that quietly ignores the
	 * bindings it was going to make.
	 *
	 * @dataProvider accessor_names
	 *
	 * @param string $accessor Static method on Absorber.
	 */
	public function test_an_accessor_without_a_container_is_a_configuration_error( string $accessor ): void {
		$this->expectException( Config_Exception::class );

		Absorber::{$accessor}();
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function accessor_names(): Generator {
		yield 'the registrar'         => [ 'registrar' ];
		yield 'the notice writer'     => [ 'notices' ];
		yield 'the conflict resolver' => [ 'resolver' ];
	}

	/**
	 * Whichever way the host bound it. `bind()` rebuilds per call where `singleton()` does not, and
	 * the accessor hands back the host's object either way — a library that cached the first resolve
	 * itself would make the difference invisible and the rebinding untestable.
	 *
	 * @dataProvider container_binding_methods
	 *
	 * @param string $binding_method Container method the host bound the registrar with.
	 */
	public function test_it_resolves_a_bound_registrar_from_the_container( string $binding_method ): void {
		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->{$binding_method}(
			Registrar_Interface::class,
			static function () use ( $bound ): Registrar_Interface {
				return $bound;
			}
		);
		$this->set_up_container( $container );

		$this->assertSame( $bound, Absorber::registrar() );
		$this->assertSame( $bound, Absorber::registrar() );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function container_binding_methods(): Generator {
		yield 'singleton' => [ 'singleton' ];
		yield 'bind'      => [ 'bind' ];
	}

	public function test_register_builds_a_sub_plugin_and_stores_it(): void {
		$this->set_up_container();

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		$all = Absorber::all();

		$this->assertCount( 1, $all );
		$this->assertInstanceOf( Sub_Plugin::class, $all['give-recurring'] );
		$this->assertSame( 'give-recurring', $all['give-recurring']->get_slug() );
	}

	public function test_register_rejects_an_invalid_config(): void {
		$this->set_up_container();

		$this->expectException( Config_Exception::class );

		Absorber::register( [ 'slug' => 'give-recurring' ] );
	}

	/**
	 * Registering must resolve nothing at all — not even the container. The host container LearnDash
	 * hands us is *replaced* at plugins_loaded 0, so anything that touched a container before that
	 * point holds an orphan whose bindings were thrown away.
	 */
	public function test_register_needs_no_container(): void {
		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		$this->set_up_container();

		$this->assertArrayHasKey( 'give-recurring', Absorber::all() );
	}

	/**
	 * The other half of the same guarantee: with a container set, the first register() must still not
	 * reach into it, or it would pin the registrar before the host finished binding.
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
		$this->set_up_container( $container );

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		$this->assertSame( 0, $builds, 'register() must not reach the container.' );

		Absorber::all();

		$this->assertSame( 1, $builds, 'The first read is what resolves the registrar.' );
	}

	public function test_register_delegates_to_a_bound_registrar(): void {
		$bound = $this->bind_registrar();

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );
		Absorber::all();

		$this->assertArrayHasKey( 'give-recurring', $bound->sub_plugins );
	}

	/**
	 * The buffer is emptied as it drains. Left full, the second read hands the registrar a slug it
	 * already holds and the duplicate guard fires on a registration the host only made once.
	 */
	public function test_reading_twice_does_not_register_twice(): void {
		$bound = $this->bind_registrar();

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		Absorber::all();
		Absorber::all();

		$this->assertSame( 1, $bound->register_calls );
	}

	/**
	 * Deferring registration moves the duplicate-slug report from the second register() call to the
	 * first read. It still names both bundled files, which is what the host needs to find them.
	 */
	public function test_a_duplicate_slug_is_refused_at_the_first_read(): void {
		$this->set_up_container();

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );
		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => '/tmp/other/other.php',
				'plugin_loaded_constant' => 'OTHER_VERSION_FIXTURE',
			]
		);

		try {
			Absorber::all();
			$this->fail( 'Expected a Config_Exception.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
			$this->assertStringContainsString( '/tmp/other/other.php', $exception->getMessage() );
		}
	}

	public function test_all_is_empty_before_anything_is_registered(): void {
		$this->set_up_container();

		$this->assertSame( [], Absorber::all() );
	}

	/**
	 * A binding that cannot be built leaves the registrations buffered, so the read that comes after
	 * the host fixes its container still has them. Emptying the buffer before the registrar resolved
	 * would drop them silently and load nothing.
	 */
	public function test_registrations_survive_a_registrar_the_container_cannot_build(): void {
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function (): Registrar_Interface {
				throw new RuntimeException( 'the host factory needed a database connection' );
			}
		);
		$this->set_up_container( $container );

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		$failed = false;

		try {
			Absorber::all();
		} catch ( Config_Exception $exception ) {
			$failed = true;
		}

		$this->assertTrue( $failed, 'A registrar that cannot be built has to surface, not be swallowed.' );

		$this->set_up_container();

		$this->assertArrayHasKey(
			'give-recurring',
			Absorber::all(),
			'The buffered registration must still be there once the container is usable.'
		);
	}

	/**
	 * The buffer is static state, so the suite's reset helper has to reach it: a registration left
	 * buffered by one test drains into the next test's registrar.
	 */
	public function test_the_state_helper_clears_buffered_registrations(): void {
		$this->set_up_container();

		Absorber::register( $this->sub_plugin_config( 'give-recurring' ) );

		Absorber_State::reset();

		$this->assertSame( [], Absorber::all() );
	}

	public function test_render_notices_delegates_to_the_bound_presenter(): void {
		$presenter = $this->bind_presenter();

		Absorber::render_notices();

		$this->assertSame( 1, $presenter->render_calls );
	}

	/**
	 * The notice messages are host callables and the presenter itself resolves whatever store and
	 * renderer are bound, so rendering runs host code — on `all_admin_notices`, which every admin
	 * screen fires. A throw out of it white-screens wp-admin, which is exactly where a site owner
	 * would go to undo whatever caused it, so it is reported and the render is abandoned instead.
	 */
	public function test_render_notices_cannot_end_the_admin_request(): void {
		$this->expect_incorrect_usage();

		// After the provider, because Presenter is a class id: a container reports it can build one
		// whether or not anything was bound, so the provider rebinds it regardless and a factory
		// handed in before set_up_container() would never run.
		$this->set_up_container()->singleton(
			Presenter::class,
			static function (): Presenter {
				throw new RuntimeException( 'the notice option held something unreadable' );
			}
		);

		Absorber::render_notices();

		$this->assert_the_library_reported_incorrect_usage();
	}

	public function test_render_notices_does_nothing_without_a_hook_prefix(): void {
		$container = $this->set_up_container();

		Config_State::reset();
		Config::set_container( $container );
		$this->expect_incorrect_usage();

		ob_start();

		try {
			Absorber::render_notices();
		} finally {
			// In a finally block so a throw cannot leave the suite's own output trapped in an
			// abandoned buffer.
			$output = (string) ob_get_clean();
		}

		$this->assertSame( '', $output );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The trampoline is a named static method rather than a closure over the container, so that a
	 * host can take the filter back off — but it still has to reach whatever the host bound, or a
	 * replacement rewriter would own the error screen and never be asked about one.
	 */
	public function test_the_activation_error_trampoline_delegates_to_the_bound_rewriter(): void {
		$rewriter = $this->bind_rewriter();

		$this->assertSame(
			$rewriter->rewritten_markup,
			Absorber::filter_activation_error_markup( '<p>Core.</p>' )
		);
		$this->assertSame( [ '<p>Core.</p>' ], $rewriter->rewritten );
	}

	/**
	 * Reported and handed back untouched rather than thrown out of: this runs while WordPress is
	 * drawing an error screen, and a second fatal there would replace the one the user came to read.
	 */
	public function test_the_activation_error_trampoline_does_nothing_without_a_hook_prefix(): void {
		$rewriter = $this->bind_rewriter();

		// The prefix goes, the container stays: this is about the missing prefix, and a trampoline
		// that reached the container first would pass this test for the other reason.
		$container = $this->container();
		Config_State::reset();
		Config::set_container( $container );
		$this->expect_incorrect_usage();

		$this->assertSame( '<p>Core.</p>', Absorber::filter_activation_error_markup( '<p>Core.</p>' ) );
		$this->assertSame( [], $rewriter->rewritten, 'The rewriter must not be reached at all.' );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The rewriter is a rebindable seam and the message it substitutes is a host callable, so the
	 * rewrite runs host code — on the screen that is already reporting a fatal. A throw out of here
	 * would replace the error the admin came to read with one of ours, so it is reported and core's
	 * own markup is handed back.
	 *
	 * The throw comes from inside the rewrite rather than from the binding, because that is the
	 * shape the real one takes: a duplicate registration surfaces when the registry drains, which is
	 * the first thing the rewrite does.
	 */
	public function test_the_activation_error_trampoline_cannot_end_the_admin_request(): void {
		$this->expect_incorrect_usage();

		$rewriter          = $this->bind_rewriter();
		$rewriter->failure = new RuntimeException( 'two sub-plugins were registered under one slug' );

		$this->assertSame( '<p>Core.</p>', Absorber::filter_activation_error_markup( '<p>Core.</p>' ) );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The other half of the same guarantee: a rewriter the container cannot build at all is a host's
	 * broken binding, and it arrives on the same screen with the same consequence.
	 */
	public function test_the_activation_error_trampoline_survives_a_rewriter_it_cannot_build(): void {
		$this->expect_incorrect_usage();

		// Bound after the provider, for the reason bind_rewriter() gives: a class id handed in
		// beforehand is rebound to the real thing and this factory would never run.
		$this->set_up_container()->singleton(
			Rewriter::class,
			static function (): Rewriter {
				throw new RuntimeException( 'the rewriter could not be built' );
			}
		);

		$this->assertSame( '<p>Core.</p>', Absorber::filter_activation_error_markup( '<p>Core.</p>' ) );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * A filter receives whatever the filter before it returned, so the trampoline takes an untyped
	 * argument and coerces it. Declaring `string` there would turn another plugin's sloppy return
	 * into a TypeError raised from this library, on the screen least able to afford one.
	 *
	 * The rewriter still sees the coerced value: the guard is about what crosses the boundary, not
	 * about standing the rewrite down, and a trampoline that returned early would leave the
	 * rewriter's `string` promise resting on an untested path.
	 *
	 * @dataProvider non_string_markup
	 *
	 * @param mixed $markup Whatever the previous filter returned.
	 */
	public function test_the_activation_error_trampoline_coerces_a_non_string( $markup ): void {
		$rewriter = $this->bind_rewriter();

		$this->assertSame( $rewriter->rewritten_markup, Absorber::filter_activation_error_markup( $markup ) );
		$this->assertSame( [ '' ], $rewriter->rewritten );
	}

	/**
	 * An integer earns its place alongside the two that would fatal: `42` casts cleanly to `"42"`,
	 * so it is the case a missing guard would pass rather than crash on.
	 *
	 * @return Generator<string,array{0:mixed}>
	 */
	public static function non_string_markup(): Generator {
		yield 'null'       => [ null ];
		yield 'an array'   => [ [ '<p>Core.</p>' ] ];
		yield 'an object'  => [ new stdClass() ];
		yield 'an integer' => [ 42 ];
		yield 'false'      => [ false ];
	}

	/**
	 * Bind a recording presenter into the container already standing.
	 *
	 * After the provider has run, not before it: the id is a class, and a container answers for
	 * every class that exists whether or not anything was bound to it. The provider cannot tell a
	 * host's deliberate binding from that, so it rebinds regardless, and a double handed in
	 * beforehand would be quietly replaced by the real presenter.
	 *
	 * @return Spy_Presenter
	 */
	private function bind_presenter(): Spy_Presenter {
		$presenter = new Spy_Presenter();

		$this->set_up_container()->singleton(
			Presenter::class,
			static function () use ( $presenter ): Presenter {
				return $presenter;
			}
		);

		return $presenter;
	}

	/**
	 * Bind a recording rewriter into the container already standing.
	 *
	 * After the provider has run, not before it like the queue above: the id is a class, and a
	 * container answers for every class that exists whether or not anything was bound to it. The
	 * provider cannot tell a host's deliberate binding from that, so it rebinds regardless, and a
	 * double handed in beforehand would be quietly replaced by the real rewriter.
	 *
	 * @return Spy_Rewriter
	 */
	private function bind_rewriter(): Spy_Rewriter {
		$rewriter = new Spy_Rewriter();

		$this->set_up_container()->singleton(
			Rewriter::class,
			static function () use ( $rewriter ): Rewriter {
				return $rewriter;
			}
		);

		return $rewriter;
	}

	/**
	 * Bind a recording registrar, in the order a host binds one: before the provider fills in what is
	 * missing.
	 *
	 * @return Spy_Registrar
	 */
	private function bind_registrar(): Spy_Registrar {
		$bound     = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $bound ): Registrar_Interface {
				return $bound;
			}
		);

		$this->set_up_container( $container );

		return $bound;
	}

	/**
	 * Build the raw configuration array a host writes.
	 *
	 * The shared WithSubPlugins trait builds `Sub_Plugin` objects; `Absorber::register()` takes the
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
