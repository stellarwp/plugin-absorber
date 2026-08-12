<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Load;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Load\Runner;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Queue;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;

/**
 * The load loop and its gate chain.
 *
 * The gates run cheapest and most decisive first — enabled, then already loaded, then dependencies,
 * then the file itself, then the `should_load` filter — and each one is asserted both by what it
 * skips and by what it stops the later gates from doing.
 *
 * @since 1.0.0
 */
class RunnerTest extends WPTestCase {
	use UopzFunctions;
	use WithBundledPlugins;
	use WithContainer;
	use WithIncorrectUsage;

	/**
	 * Guard constants a test defined through uopz.
	 *
	 * @var string[]
	 */
	private $constants = [];

	/**
	 * Every value the should_load filter was called with.
	 *
	 * A property rather than a local, because the recorder is a closure the filter keeps: a local
	 * handed back from the helper that installs it would be a copy taken before the first call.
	 *
	 * @var array<int,mixed>
	 */
	private $should_load_calls = [];

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->clear_notices();
		$this->reset_bundled_plugin_loads();
		$this->should_load_calls = [];
	}

	public function tearDown(): void {
		$this->remove_bundled_plugin_files();

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// strand the constant for the rest of the process, and every later test would read it as a
		// sub-plugin whose code is already present and skip the load it was written to exercise.
		foreach ( $this->constants as $constant ) {
			$this->unsetConstant( $constant );
		}
		$this->constants = [];

		$this->stop_expecting_incorrect_usage();
		$this->clear_notices();
		Loader_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_it_requires_the_bundled_file(): void {
		$constant = $this->register();

		$this->runner()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertTrue( defined( $constant ) );
	}

	public function test_it_requires_the_bundled_file_exactly_once(): void {
		$this->register();

		$this->runner()->load_all();
		$this->runner()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
	}

	public function test_it_skips_when_dependencies_are_unmet_and_queues_a_notice(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertArrayHasKey( 'give-recurring:dependency', $this->notice_queue() );
	}

	public function test_it_skips_when_the_guard_constant_is_already_defined(): void {
		$constant = $this->define_guard( 'ABSORBER_ALREADY_LOADED_GUARD' );

		$this->register( [], $constant );

		$this->runner()->load_all();

		$this->assertSame(
			0,
			$this->bundled_plugin_loads(),
			'A defined constant means the code is already present.'
		);
	}

	public function test_it_skips_when_the_bundled_file_is_missing(): void {
		$this->expect_incorrect_usage();

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $this->missing_bundled_plugin_file(),
				'plugin_loaded_constant' => $this->make_guard_constant(),
			]
		);

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * A broken build is a developer problem, not a site-owner one. It must not reach the notice
	 * queue, where it would render the host's own dependency_notice_message and send the owner after
	 * a dependency that is perfectly fine.
	 *
	 * The message is configured as a callable because that is the only shape the key takes: a string
	 * is refused outright, so that a host's __() cannot run while it builds its config array.
	 */
	public function test_a_missing_bundled_file_reports_to_the_developer_not_the_site_owner(): void {
		$this->expect_incorrect_usage();

		Loader::register(
			[
				'slug'                      => 'give-recurring',
				'bundled_plugin_file'       => $this->missing_bundled_plugin_file(),
				'plugin_loaded_constant'    => $this->make_guard_constant(),
				'dependency_notice_message' => static fn() => 'GiveWP 3.0 or later is required.',
			]
		);

		$this->runner()->load_all();

		$this->assertSame( [], $this->notice_queue() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * file_exists() is true for a directory, and require_once fatals on one.
	 */
	public function test_it_skips_when_the_bundled_path_is_a_directory(): void {
		$this->expect_incorrect_usage();

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => sys_get_temp_dir(),
				'plugin_loaded_constant' => $this->make_guard_constant(),
			]
		);

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * file_exists() is also true for a file the process cannot read, and require_once fatals.
	 */
	public function test_it_skips_when_the_bundled_file_is_unreadable(): void {
		$constant = $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );
		chmod( $path, 0000 );

		if ( is_readable( $path ) ) {
			$this->markTestSkipped( 'Running as a user that can read a 0000 file.' );
		}

		$this->expect_incorrect_usage();

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $constant,
			]
		);

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The dependency check calls an arbitrary host callable, so it must not run for a sub-plugin
	 * whose code is already present — and must not warn that requirements are unmet for a plugin the
	 * admin can see running.
	 */
	public function test_an_already_loaded_sub_plugin_is_not_dependency_checked(): void {
		$constant = $this->define_guard( 'ABSORBER_LOADED_BEFORE_DEPS_GUARD' );

		$checked = 0;
		$this->register(
			[
				'dependency_check' => static function () use ( &$checked ) {
					++$checked;

					return false;
				},
			],
			$constant
		);

		$this->runner()->load_all();

		$this->assertSame( 0, $checked );
		$this->assertSame( [], $this->notice_queue(), 'No notice for a plugin that is already running.' );
	}

	public function test_the_should_load_filter_can_veto_the_load(): void {
		$this->register();

		add_filter( 'give/plugin_absorber/should_load', '__return_false' );

		$this->runner()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
	}

	public function test_the_should_load_filter_receives_the_sub_plugin(): void {
		$this->register();

		$received = null;
		add_filter(
			'give/plugin_absorber/should_load',
			static function ( $should_load, $sub_plugin ) use ( &$received ) {
				$received = $sub_plugin;

				return $should_load;
			},
			10,
			2
		);

		$this->runner()->load_all();

		$this->assertInstanceOf( Sub_Plugin::class, $received );
		$this->assertSame( 'give-recurring', $received->get_slug() );
	}

	/**
	 * The filter is the last gate before require_once, so it must not be consulted for a sub-plugin
	 * that was already going to be skipped — a host counting its invocations would otherwise see calls
	 * for loads that never happen.
	 */
	public function test_the_should_load_filter_is_not_consulted_for_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		$this->record_should_load_calls();

		$this->runner()->load_all();

		$this->assertSame( [], $this->should_load_calls );

		$this->assert_the_should_load_recorder_works();
	}

	/**
	 * The same guarantee at the far end of the chain: a sub-plugin that failed the dependency check
	 * has already earned its notice, and asking the filter as well would offer a host a veto over a
	 * load that was never going to happen.
	 */
	public function test_the_should_load_filter_is_not_consulted_when_dependencies_are_unmet(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		$this->record_should_load_calls();

		$this->runner()->load_all();

		$this->assertSame( [], $this->should_load_calls );

		$this->assert_the_should_load_recorder_works();
	}

	public function test_it_loads_every_registered_sub_plugin(): void {
		$this->register( [ 'slug' => 'give-recurring' ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->runner()->load_all();

		$this->assertSame( 2, $this->bundled_plugin_loads() );
	}

	/**
	 * One sub-plugin failing its checks must not stop the rest, or the failure order would decide
	 * which plugins a site gets.
	 */
	public function test_a_skipped_sub_plugin_does_not_stop_the_others(): void {
		$this->register( [ 'slug' => 'give-recurring', 'enabled' => false ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->runner()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * Registrar_Interface::all() can only declare `array`, so a host implementation is free to return
	 * anything. The default Registrar cannot produce this state — only a bound one can.
	 */
	public function test_it_ignores_entries_that_are_not_sub_plugins(): void {
		$constant   = $this->make_guard_constant();
		$path       = $this->make_bundled_plugin_file( $constant );
		$sub_plugin = new Sub_Plugin(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $constant,
			]
		);

		$registrar = new class( $sub_plugin ) implements Registrar_Interface {
			/**
			 * @var array<string,mixed>
			 */
			private $entries;

			public function __construct( Sub_Plugin $sub_plugin ) {
				$this->entries = [
					'junk' => 'not-a-sub-plugin',
					'nope' => 42,
					'real' => $sub_plugin,
				];
			}

			public function register( Sub_Plugin $sub_plugin ): void {
			}

			public function all(): array {
				// The interface can only declare `array`, and its docblock is a promise a host is free
				// to break — which is exactly what this double is here to do. Restating the promised
				// shape is what lets the analyser check every other implementation strictly while this
				// one hands back the junk the load path has to survive.
				/** @var array<string,Sub_Plugin> $entries */
				$entries = $this->entries;

				return $entries;
			}
		};

		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $registrar ): Registrar_Interface {
				return $registrar;
			}
		);
		$this->set_up_container( $container );

		$this->runner()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * require_once dedupes by resolved path, so one file behind two registrations executes once even
	 * when the second one's guard constant never gets defined.
	 */
	public function test_one_bundled_file_behind_two_registrations_loads_once(): void {
		$path = $this->make_bundled_plugin_file( $this->make_guard_constant() );

		foreach ( [ 'give-recurring', 'give-fee-recovery' ] as $slug ) {
			Loader::register(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => $path,
					'plugin_loaded_constant' => $this->make_guard_constant(),
				]
			);
		}

		$this->runner()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * The load path needs the prefix for the should_load filter and for the notice store. Throwing
	 * out of a core action would take the whole site down over a bootstrap mistake, so it is reported
	 * where a developer will see it and the load is abandoned instead.
	 */
	public function test_load_all_does_nothing_without_a_hook_prefix(): void {
		$this->register();

		$runner    = $this->runner();
		$container = $this->container();

		// The prefix goes, the container stays: this is about the missing prefix, and a library that
		// reached the container first would fail this test for the other reason.
		Config_State::reset();
		Config::set_container( $container );
		$this->expect_incorrect_usage();

		$runner->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads(), 'A bootstrap mistake must not fatal the site.' );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * Registration is buffered, which is what lets the container arrive after the sub-plugins do —
	 * and the registry is only half of that: the load path resolves the notice queue as well. A
	 * collaborator pinned by an eager resolve inside register() would leave the host's binding bound
	 * and never used.
	 */
	public function test_a_container_set_after_register_reaches_the_load_path(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'give' );

		$this->register( [ 'dependency_check' => static fn() => false ] );

		$notices = new Spy_Queue();

		// The registrar is bound as well as the queue. Without it the sub-plugin would sit in the
		// default registrar either way and the notice would arrive however register() behaved, so the
		// test would pass without the buffer existing at all.
		$registrar = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $registrar ): Registrar_Interface {
				return $registrar;
			}
		);
		$container->singleton(
			Queue_Interface::class,
			static function () use ( $notices ): Queue_Interface {
				return $notices;
			}
		);
		$this->set_up_container( $container );

		$this->runner()->load_all();

		$this->assertSame(
			[ 'give-recurring' ],
			$notices->dependency_notices,
			'A binding made after register() has to reach the load path.'
		);
		$this->assertSame(
			[],
			$this->notice_queue(),
			'The default queue must not have been resolved alongside it.'
		);
	}

	/**
	 * The runner as the container builds it, which is how the scheduler reaches it too.
	 *
	 * @return Runner
	 */
	private function runner(): Runner {
		return $this->resolve( Runner::class );
	}

	/**
	 * Record every should_load call, so a test can assert there were none.
	 *
	 * @return void
	 */
	private function record_should_load_calls(): void {
		$calls = &$this->should_load_calls;

		add_filter(
			'give/plugin_absorber/should_load',
			static function ( $should_load ) use ( &$calls ) {
				$calls[] = $should_load;

				return $should_load;
			}
		);
	}

	/**
	 * Show the recorder works, having just asserted it caught nothing.
	 *
	 * Without this, a filter that never attached — a mistyped hook name, an add_filter() that ran too
	 * late — leaves the array empty for a reason that has nothing to do with the gate under test, and
	 * the assertion passes having proved nothing at all.
	 *
	 * @return void
	 */
	private function assert_the_should_load_recorder_works(): void {
		apply_filters( 'give/plugin_absorber/should_load', true );

		$this->assertSame(
			[ true ],
			$this->should_load_calls,
			'The recorder must catch a call that really happened.'
		);
	}

	private function clear_notices(): void {
		delete_site_option( 'give_plugin_absorber_notices' );
	}

	/**
	 * The queue is stored as a site option on every install — on single site that call falls through
	 * to the plain option table — so there is one place to read it from.
	 *
	 * @return array<string,string>
	 */
	private function notice_queue(): array {
		$queue = get_site_option( 'give_plugin_absorber_notices', [] );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Define a guard constant for the duration of one test, undone in tearDown.
	 *
	 * uopz is what makes this reversible: a plain define() lasts for the whole PHP process, and a
	 * guard left standing makes every later test read its sub-plugin as already loaded.
	 *
	 * @param string $constant Constant to define.
	 *
	 * @return string
	 */
	private function define_guard( string $constant ): string {
		$this->constants[] = $constant;

		$this->setConstant( $constant, '1.0.0' );

		return $constant;
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 * @param string|null         $constant  Guard constant to use, or a fresh one.
	 *
	 * @return string
	 */
	private function register( array $overrides = [], ?string $constant = null ): string {
		$constant = $constant ?? $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );

		Loader::register(
			array_merge(
				[
					'slug'                   => 'give-recurring',
					'bundled_plugin_file'    => $path,
					'plugin_loaded_constant' => $constant,
				],
				$overrides
			)
		);

		return $constant;
	}
}
