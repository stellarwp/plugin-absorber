<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Skip_Reason;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Activator;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Writer;
use Nexcess\PluginAbsorber\Tests\Support\Stub_Registry_Reader;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithNoticeQueue;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use RuntimeException;

/**
 * The load loop and its gate chain.
 *
 * The gates run cheapest and most decisive first — enabled, then already loaded, then dependencies,
 * then the file itself, then the `should_load` filter — and each one is asserted both by what it
 * skips and by what it stops the later gates from doing.
 *
 * @since 1.0.0
 */
class LoaderTest extends WPTestCase {
	use UopzFunctions;
	use WithBundledPlugins;
	use WithContainer;
	use WithIncorrectUsage;
	use WithNoticeQueue;
	use WithSubPlugins;

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

	/**
	 * Every sub-plugin the `loaded` action was fired with, in order.
	 *
	 * @var array<int,mixed>
	 */
	private $loaded_calls = [];

	/**
	 * Every `skipped` firing, as a [ slug, reason ] pair.
	 *
	 * The reason travels with the slug rather than in a list of its own, because a pass over two
	 * sub-plugins is the case where "one skip, for this reason" and "two skips, one of them for this
	 * reason" have to be told apart.
	 *
	 * @var array<int,array{0:mixed,1:mixed}>
	 */
	private $skipped_calls = [];

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->clear_notices();
		$this->clear_activations();
		$this->reset_bundled_plugin_loads();
		$this->should_load_calls = [];
		$this->loaded_calls      = [];
		$this->skipped_calls     = [];
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
		$this->clear_activations();
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_it_requires_the_bundled_file(): void {
		$constant = $this->register();

		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertTrue( defined( $constant ) );
	}

	/**
	 * The registry arrives as a constructor argument, so a load pass can be pointed at a sub-plugin
	 * nothing ever registered — and the file it requires proves the loop read the object it was
	 * handed rather than the facade.
	 */
	public function test_it_loads_the_registry_it_was_handed(): void {
		$constant = $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );

		$loader = new Loader(
			new Stub_Registry_Reader(
				[
					new Sub_Plugin(
						[
							'slug'                   => 'give-recurring',
							'bundled_plugin_file'    => $path,
							'plugin_loaded_constant' => $constant,
						]
					),
				]
			),
			new Spy_Writer(),
			new Spy_Activator()
		);

		$loader->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertTrue( defined( $constant ) );
	}

	/**
	 * A host callable is arbitrary code, and this one runs inside `plugins_loaded` on every request a
	 * site serves. Letting a throw out would white-screen the whole site — front end included — over
	 * one sub-plugin's mistake, and take every sub-plugin behind it in the registration order down
	 * with it. Reported to the developer, and the loop carries on.
	 */
	public function test_a_sub_plugin_that_throws_does_not_stop_the_others(): void {
		$this->expect_incorrect_usage();

		$this->register(
			[
				'slug'    => 'give-recurring',
				'enabled' => static function (): bool {
					throw new RuntimeException( 'the licence server was unreachable' );
				},
			]
		);
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		$this->assertSame(
			1,
			$this->bundled_plugin_loads(),
			'The sub-plugin behind the one that threw still has to load.'
		);
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The bundled file is host code too, and a `require` of a file that throws — or one with a syntax
	 * error, which is a catchable ParseError on an include — must not be the end of the request.
	 */
	public function test_a_bundled_file_that_throws_does_not_stop_the_others(): void {
		$this->expect_incorrect_usage();

		$constant = $this->make_guard_constant();
		$path     = $this->make_throwing_bundled_plugin_file();

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $constant,
			]
		);
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		// Two: the throwing fixture counts its own load before it throws, and the sub-plugin behind
		// it still loaded. One would mean the require never happened; without the guard neither
		// number is ever read, because the throw ends the request.
		$this->assertSame( 2, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	public function test_it_requires_the_bundled_file_exactly_once(): void {
		$this->register();

		$this->loader()->load_all();
		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
	}

	public function test_it_skips_when_dependencies_are_unmet_and_queues_a_notice(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertArrayHasKey( 'give-recurring:dependency', $this->queued_notices() );
	}

	public function test_it_skips_when_the_guard_constant_is_already_defined(): void {
		$constant = $this->define_guard( 'ABSORBER_ALREADY_LOADED_GUARD' );

		$this->register( [], $constant );

		$this->loader()->load_all();

		$this->assertSame(
			0,
			$this->bundled_plugin_loads(),
			'A defined constant means the code is already present.'
		);
	}

	public function test_it_skips_when_the_bundled_file_is_missing(): void {
		$this->expect_incorrect_usage();

		$path = $this->missing_bundled_plugin_file();

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $this->make_guard_constant(),
			]
		);

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );

		// The file gate's own sentence, and the path it refused, rather than "something was reported":
		// a load that skipped for any other reason at all — an unreadable registry, a bootstrap with
		// no hook prefix — reports too, and would satisfy a looser assertion just as well.
		$this->assert_the_library_reported_incorrect_usage_saying(
			sprintf( '"give-recurring" is missing or unreadable: %s', $path ),
			'The report has to name the file gate and the path it refused.'
		);
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

		$path = $this->missing_bundled_plugin_file();

		Absorber::register(
			[
				'slug'                      => 'give-recurring',
				'bundled_plugin_file'       => $path,
				'plugin_loaded_constant'    => $this->make_guard_constant(),
				'dependency_notice_message' => static fn() => 'GiveWP 3.0 or later is required.',
			]
		);

		$this->loader()->load_all();

		$this->assertSame( [], $this->queued_notices() );

		// An empty queue is the same shape a load that never ran leaves behind, so what the developer
		// was told instead has to be the file gate's own report and not any other.
		$this->assert_the_library_reported_incorrect_usage_saying(
			sprintf( '"give-recurring" is missing or unreadable: %s', $path ),
			'The missing file has to be reported to the developer, not merely reported.'
		);
	}

	/**
	 * file_exists() is true for a directory, and require_once fatals on one.
	 */
	public function test_it_skips_when_the_bundled_path_is_a_directory(): void {
		$this->expect_incorrect_usage();

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => sys_get_temp_dir(),
				'plugin_loaded_constant' => $this->make_guard_constant(),
			]
		);

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage_saying(
			sprintf( '"give-recurring" is missing or unreadable: %s', sys_get_temp_dir() ),
			'The report has to name the directory the file gate refused.'
		);
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

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $constant,
			]
		);

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage_saying(
			sprintf( '"give-recurring" is missing or unreadable: %s', $path ),
			'The report has to name the unreadable file, not merely have happened.'
		);
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

		$this->loader()->load_all();

		$this->assertSame( 0, $checked );
		$this->assertSame( [], $this->queued_notices(), 'No notice for a plugin that is already running.' );
	}

	public function test_the_should_load_filter_can_veto_the_load(): void {
		$this->register();

		add_filter( 'give/plugin_absorber/should_load', '__return_false' );

		$this->loader()->load_all();

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

		$this->loader()->load_all();

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

		$this->loader()->load_all();

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

		$this->loader()->load_all();

		$this->assertSame( [], $this->should_load_calls );

		$this->assert_the_should_load_recorder_works();
	}

	/**
	 * The activation callback stands in for the register_activation_hook() a bundled plugin never
	 * gets, so it has to run with the plugin's own code already in memory: a migration that calls a
	 * function the bundled file declares would otherwise fatal.
	 */
	public function test_it_runs_the_activation_callback_after_requiring_the_file(): void {
		$loads_at_activation = null;

		$this->register(
			[
				'activation_callback' => function () use ( &$loads_at_activation ): void {
					$loads_at_activation = $this->bundled_plugin_loads();
				},
			]
		);

		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertSame( 1, $loads_at_activation, 'The bundled file has to be in memory first.' );
		$this->assertSame( [ 'give-recurring' => true ], $this->activation_record() );
	}

	/**
	 * Activation is tied to a require that actually happened. Running it for a skipped sub-plugin
	 * would create the tables and seed the options of a plugin whose code is not loaded, and the
	 * record would then stand the callback down for good once the sub-plugin really did load.
	 */
	public function test_a_skipped_load_runs_no_activation_callback(): void {
		$calls = [];

		$record = static function ( Sub_Plugin $sub_plugin ) use ( &$calls ): void {
			$calls[] = $sub_plugin->get_slug();
		};

		$this->register(
			[
				'enabled'             => false,
				'activation_callback' => $record,
			]
		);

		$this->loader()->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertSame( [], $calls );
		$this->assertSame( [], $this->activation_record() );

		// The recorder has to be shown to work. A closure that never reached the config array — a
		// mistyped key, a value the constructor refused — leaves this empty for a reason that has
		// nothing to do with the load being skipped, and the assertion above proves nothing.
		$record( $this->make_sub_plugin() );

		$this->assertSame( [ 'give-recurring' ], $calls, 'The recorder must catch a call that really happened.' );
	}

	/**
	 * The activator is injected, not resolved inside the load path, so a host that records "once,
	 * ever" in its own migration table binds one implementation and the load pass uses it — and the
	 * default's option is never written behind its back.
	 */
	public function test_a_bound_activator_reaches_the_load_path(): void {
		$activator = new Spy_Activator();
		$container = new Test_Container();
		$container->singleton(
			Activator_Interface::class,
			static function () use ( $activator ): Activator_Interface {
				return $activator;
			}
		);
		$this->set_up_container( $container );

		$this->register( [ 'activation_callback' => static fn() => null ] );

		$this->loader()->load_all();

		$this->assertSame( [ 'give-recurring' ], $activator->slugs );
		$this->assertSame( [], $this->activation_record(), 'The default activator must not have run too.' );
	}

	/**
	 * The activation callback is the one piece of host code that runs *after* a successful require, so
	 * a throw from it leaves the sub-plugin loaded with its migration half-done. Unguarded it would
	 * take the site with it on every request — the sub-plugin loads, the callback throws, the record
	 * is never written, and the next request does it all again. Reported instead, and the record still
	 * goes unwritten, so a fixed callback is retried rather than skipped forever.
	 */
	public function test_a_throwing_activation_callback_does_not_stop_the_others(): void {
		$this->expect_incorrect_usage();

		$this->register(
			[
				'slug'                => 'give-recurring',
				'activation_callback' => static function (): void {
					throw new RuntimeException( 'the migration could not run' );
				},
			]
		);
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		$this->assertSame( 2, $this->bundled_plugin_loads(), 'Both files loaded; the callback threw after one of them.' );
		$this->assertSame( [], $this->activation_record(), 'A callback that threw must not be recorded as done.' );
		$this->assert_the_library_reported_incorrect_usage();
	}

	public function test_it_loads_every_registered_sub_plugin(): void {
		$this->register( [ 'slug' => 'give-recurring' ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		$this->assertSame( 2, $this->bundled_plugin_loads() );
	}

	/**
	 * One sub-plugin failing its checks must not stop the rest, or the failure order would decide
	 * which plugins a site gets.
	 */
	public function test_a_skipped_sub_plugin_does_not_stop_the_others(): void {
		$this->register( [ 'slug' => 'give-recurring', 'enabled' => false ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * Registrar_Interface::all() can only declare `array`, so a host implementation is free to return
	 * anything. The default `Registry\Registrar` cannot produce this state — only a bound one can.
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

		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * require_once dedupes by resolved path, so one file behind two registrations executes once even
	 * when the second one's guard constant never gets defined.
	 */
	public function test_one_bundled_file_behind_two_registrations_loads_once(): void {
		$path = $this->make_bundled_plugin_file( $this->make_guard_constant() );

		foreach ( [ 'give-recurring', 'give-fee-recovery' ] as $slug ) {
			Absorber::register(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => $path,
					'plugin_loaded_constant' => $this->make_guard_constant(),
				]
			);
		}

		$this->loader()->load_all();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * The load path needs the prefix for the should_load filter and for the notice store. Throwing
	 * out of a core action would take the whole site down over a bootstrap mistake, so it is reported
	 * where a developer will see it and the load is abandoned instead.
	 */
	public function test_load_all_does_nothing_without_a_hook_prefix(): void {
		$this->register();

		$loader    = $this->loader();
		$container = $this->container();

		// The prefix goes, the container stays: this is about the missing prefix, and a library that
		// reached the container first would fail this test for the other reason.
		Config_State::reset();
		Config::set_container( $container );
		$this->expect_incorrect_usage();

		$loader->load_all();

		$this->assertSame( 0, $this->bundled_plugin_loads(), 'A bootstrap mistake must not fatal the site.' );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The same guarantee for the read itself. Reading flushes the registration buffer, and the
	 * registrar refuses a slug it already holds — a throw that arrives inside plugins_loaded, where it
	 * would take down the front end and wp-admin together and lock the developer out of the screen
	 * where the duplicate registration could be undone.
	 */
	public function test_a_duplicate_slug_is_reported_rather_than_fataling_the_request(): void {
		// Two registrations of the default slug, each with a bundled fixture of its own — one file
		// behind both would load once for the second registration and hide the skip under a dedupe.
		$this->register();
		$this->register();

		$this->expect_incorrect_usage();

		$this->loader()->load_all();

		// Reaching this line at all is half of what is under test: load_all() has to return.
		$this->assertSame(
			0,
			$this->bundled_plugin_loads(),
			'A read that failed has no list to load from, so nothing may load.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'The registered sub-plugins could not be read, so none were loaded',
			'The read is what failed, and the report has to say so rather than name some other gate.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'give-recurring',
			'The report has to name the slug, or it could have been raised for any other reason.'
		);
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

		$notices = new Spy_Writer();

		// The registrar is bound as well as the writer. Without it the sub-plugin would sit in the
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
			Writer_Interface::class,
			static function () use ( $notices ): Writer_Interface {
				return $notices;
			}
		);
		$this->set_up_container( $container );

		$this->loader()->load_all();

		$this->assertSame(
			[ 'give-recurring' ],
			$notices->dependency_notices,
			'A binding made after register() has to reach the load path.'
		);
		$this->assertSame(
			[],
			$this->queued_notices(),
			'The default queue must not have been resolved alongside it.'
		);
	}

	/**
	 * `loaded` is the answer to "is this sub-plugin here?" without a `defined()` check of the host's
	 * own, so it has to fire once, for the sub-plugin that really was required.
	 */
	public function test_it_announces_a_load_once_with_the_sub_plugin(): void {
		$this->record_lifecycle_actions();

		$this->register();

		$this->loader()->load_all();

		$this->assertCount( 1, $this->loaded_calls );

		$loaded = $this->loaded_calls[0];

		$this->assertInstanceOf( Sub_Plugin::class, $loaded );
		$this->assertSame( 'give-recurring', $loaded->get_slug() );

		// The other half, and the reason both listeners go on together: a load that also announced a
		// skip is a gate chain that ran on past its own `return`, which asserting on one list alone
		// would never see.
		$this->assertSame( [], $this->skipped_calls );
	}

	/**
	 * The whole value of the action is that it means "this code is in memory now", so a gate that
	 * turned the sub-plugin away must not fire it. The second pass is the positive control: an
	 * empty list also describes a listener that never attached at all.
	 */
	public function test_it_announces_no_load_for_a_sub_plugin_that_was_skipped(): void {
		$this->record_lifecycle_actions();

		$this->register( [ 'enabled' => false ] );

		$this->loader()->load_all();

		$this->assertSame( [], $this->loaded_calls );

		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		$this->assertCount( 1, $this->loaded_calls, 'The recorder must catch a load that really happened.' );
	}

	/**
	 * Behind the activation callback, not in front of it. A listener is host code that will reach
	 * into the sub-plugin it was just told about, and on a first-ever load the tables and options
	 * that code expects are the activation callback's work.
	 */
	public function test_it_announces_a_load_after_the_activation_callback(): void {
		$order = [];

		$this->register(
			[
				'activation_callback' => static function () use ( &$order ): void {
					$order[] = 'activation_callback';
				},
			]
		);

		add_action(
			'give/plugin_absorber/loaded',
			static function () use ( &$order ): void {
				$order[] = 'loaded';
			}
		);

		$this->loader()->load_all();

		$this->assertSame( [ 'activation_callback', 'loaded' ], $order );
	}

	/**
	 * One reason per gate, and the reason is what a host dispatches on — so each gate is pinned to
	 * the constant it reports, not merely to having reported something.
	 *
	 * @return Generator<string,array{0:array<string,mixed>,1:string}>
	 */
	public function skipping_gates(): Generator {
		yield 'disabled' => [ [ 'enabled' => false ], Skip_Reason::DISABLED ];

		yield 'dependencies unmet' => [
			[ 'dependency_check' => static fn() => false ],
			Skip_Reason::DEPENDENCIES_UNMET,
		];
	}

	/**
	 * @dataProvider skipping_gates
	 *
	 * @param array<string,mixed> $overrides Config that trips the gate.
	 * @param string              $reason    Reason the gate has to report.
	 *
	 * @return void
	 */
	public function test_it_announces_a_skip_with_the_reason_for_the_gate( array $overrides, string $reason ): void {
		$this->record_lifecycle_actions();

		$this->register( $overrides );

		$this->loader()->load_all();

		$this->assertSame( [ [ 'give-recurring', $reason ] ], $this->skipped_calls );
		$this->assertSame( [], $this->loaded_calls, 'A sub-plugin a gate turned away was not loaded.' );
	}

	/**
	 * The guard constant has its own case because tripping it means defining a constant, which the
	 * data provider cannot do reversibly.
	 */
	public function test_it_announces_a_skip_for_a_sub_plugin_that_is_already_loaded(): void {
		$this->record_lifecycle_actions();

		$constant = $this->define_guard( 'ABSORBER_ANNOUNCED_SKIP_GUARD' );

		$this->register( [], $constant );

		$this->loader()->load_all();

		$this->assertSame( [ [ 'give-recurring', Skip_Reason::ALREADY_LOADED ] ], $this->skipped_calls );
		$this->assertSame( [], $this->loaded_calls, 'A sub-plugin a gate turned away was not loaded.' );
	}

	/**
	 * And the filter has its own because tripping it means a host filter rather than a config key.
	 */
	public function test_it_announces_a_skip_for_a_load_the_filter_vetoed(): void {
		$this->record_lifecycle_actions();

		$this->register();

		add_filter( 'give/plugin_absorber/should_load', '__return_false' );

		$this->loader()->load_all();

		$this->assertSame( [ [ 'give-recurring', Skip_Reason::FILTERED ] ], $this->skipped_calls );
		$this->assertSame( [], $this->loaded_calls, 'A sub-plugin a gate turned away was not loaded.' );
	}

	/**
	 * `do_action()` runs host code, so the lifecycle actions are a new way for a request to die
	 * inside `plugins_loaded`. A listener that throws costs its own sub-plugin and nothing behind it.
	 *
	 * And it costs its own sub-plugin nothing either, which is the half worth pinning: by the time
	 * `loaded` fires the require has happened, the guard constant is defined and the activation
	 * callback has run. Left to the per-sub-plugin catch in `load_all()`, the throw would be reported
	 * as "threw while loading, so it was abandoned" — a sentence that is false in both halves, on the
	 * one channel a host is expected to build a log line on. It is reported as what it is instead:
	 * somebody's listener, named by the hook it is on.
	 */
	public function test_a_throwing_load_listener_does_not_take_the_request_down(): void {
		$this->record_lifecycle_actions();
		$this->expect_incorrect_usage();

		add_action(
			'give/plugin_absorber/loaded',
			static function (): void {
				throw new RuntimeException( 'the telemetry endpoint was unreachable' );
			}
		);

		$this->register( [ 'slug' => 'give-recurring' ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		$this->loader()->load_all();

		// Both files: the listener throws after each require, and the sub-plugin behind the first one
		// still has to load. Without the guard neither number is ever read, because the throw ends the
		// request.
		$this->assertSame( 2, $this->bundled_plugin_loads() );

		$this->assert_the_library_reported_incorrect_usage_saying(
			'A listener on give/plugin_absorber/loaded threw',
			'The report names the hook, so the host knows whose listener to go and look at.'
		);

		$reported = implode( PHP_EOL, $this->incorrect_usage_messages );

		// One per sub-plugin: the listener throws after each require, and each throw is its own
		// listener's, not its sub-plugin's.
		$this->assertSame(
			2,
			substr_count( $reported, 'A listener on give/plugin_absorber/loaded threw' )
		);
		$this->assertStringContainsString( 'the telemetry endpoint was unreachable', $reported );
		$this->assertStringNotContainsString(
			'threw while loading',
			$reported,
			'Both sub-plugins loaded. Reporting either as abandoned would have a host reading its own'
				. ' listener bug as a load that broke.'
		);
	}

	/**
	 * The file gate is two things at once — a build the host has to fix, and a sub-plugin that is not
	 * going to be there — so it is the one gate that reports to the developer *and* announces a skip.
	 */
	public function test_it_reports_a_missing_bundled_file_and_announces_it_as_a_skip(): void {
		$this->record_lifecycle_actions();
		$this->expect_incorrect_usage();

		$path = $this->missing_bundled_plugin_file();

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => $this->make_guard_constant(),
			]
		);

		$this->loader()->load_all();

		$this->assertSame( [ [ 'give-recurring', Skip_Reason::FILE_UNREADABLE ] ], $this->skipped_calls );
		$this->assertSame( [], $this->loaded_calls, 'A sub-plugin a gate turned away was not loaded.' );
		$this->assert_the_library_reported_incorrect_usage_saying(
			$path,
			'The developer channel names the file, because the path is what the host has to correct.'
		);
	}

	/**
	 * @return void
	 */
	private function clear_activations(): void {
		delete_site_option( 'give_plugin_absorber_activations' );
	}

	/**
	 * Slugs whose activation callback has run, as the option holds them.
	 *
	 * @return array<string,mixed>
	 */
	private function activation_record(): array {
		$done = get_site_option( 'give_plugin_absorber_activations', [] );

		return is_array( $done ) ? $done : [];
	}

	/**
	 * The loader as the container builds it, which is how the scheduler reaches it too.
	 *
	 * @return Loader
	 */
	private function loader(): Loader {
		return $this->resolve( Loader::class );
	}

	/**
	 * Listen to both lifecycle actions at once.
	 *
	 * One helper rather than two, because most of these tests assert on one list *and* on the other
	 * staying empty — a skip that also fired `loaded`, a load that also fired `skipped` — and a test
	 * that only attached the listener it names could not see the other half.
	 *
	 * The closures take references to the properties and are `static`: uopz is not involved here, but
	 * the same shape keeps a listener from holding the test object alive on a hook.
	 *
	 * @return void
	 */
	private function record_lifecycle_actions(): void {
		$loaded  = &$this->loaded_calls;
		$skipped = &$this->skipped_calls;

		add_action(
			'give/plugin_absorber/loaded',
			static function ( $sub_plugin ) use ( &$loaded ): void {
				$loaded[] = $sub_plugin;
			}
		);

		add_action(
			'give/plugin_absorber/skipped',
			static function ( $sub_plugin, $reason ) use ( &$skipped ): void {
				$skipped[] = [
					$sub_plugin instanceof Sub_Plugin ? $sub_plugin->get_slug() : $sub_plugin,
					$reason,
				];
			},
			10,
			2
		);
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

		Absorber::register(
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
