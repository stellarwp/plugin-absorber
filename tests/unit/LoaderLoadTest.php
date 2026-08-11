<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;

/**
 * @since 1.0.0
 */
class LoaderLoadTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @var array<int,string>
	 */
	private $fixtures = [];

	/**
	 * Guard constants a test defined through uopz.
	 *
	 * @var string[]
	 */
	private $constants = [];

	/**
	 * The plugins_loaded count as the harness left it, for the one test that rewinds it.
	 *
	 * @var int|null
	 */
	private $plugins_loaded_count = null;

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->clear_notices();
		$GLOBALS['absorber_loads'] = 0;
	}

	public function tearDown(): void {
		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) ) {
				unlink( $fixture );
			}
		}
		$this->fixtures = [];

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// strand the constant for the rest of the process, and every later test would read it as a
		// sub-plugin whose code is already present and skip the load it was written to exercise.
		foreach ( $this->constants as $constant ) {
			$this->unsetConstant( $constant );
		}
		$this->constants = [];

		if ( $this->plugins_loaded_count !== null ) {
			$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;
			$this->plugins_loaded_count              = null;
		}

		unset( $GLOBALS['absorber_loads'] );
		$this->clear_notices();
		Loader_State::reset();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_requires_the_bundled_file(): void {
		$constant = $this->register();

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
		$this->assertTrue( defined( $constant ) );
	}

	public function test_it_requires_the_bundled_file_exactly_once(): void {
		$this->register();

		Loader::load_all();
		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	public function test_it_skips_when_dependencies_are_unmet_and_queues_a_notice(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
		$this->assertArrayHasKey( 'give-recurring:dependency', $this->notice_queue() );
	}

	public function test_it_skips_when_the_guard_constant_is_already_defined(): void {
		$constant = $this->define_guard( 'ABSORBER_ALREADY_LOADED_GUARD' );

		$this->register( [], $constant );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'], 'A defined constant means the code is already present.' );
	}

	public function test_it_skips_when_the_bundled_file_is_missing(): void {
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => '/tmp/absorber-does-not-exist-' . uniqid( '', true ) . '.php',
				'plugin_loaded_constant' => 'ABSORBER_MISSING_FILE_GUARD',
			]
		);

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	/**
	 * A broken build is a developer problem, not a site-owner one. It must not reach the notice
	 * queue, where it would render the host's own dependency_notice_message and send the owner
	 * after a dependency that is perfectly fine.
	 *
	 * The message is configured as a callable because that is the only shape the key takes: a
	 * string is refused outright, so that a host's __() cannot run while it builds its config array.
	 */
	public function test_a_missing_bundled_file_reports_to_the_developer_not_the_site_owner(): void {
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		Loader::register(
			[
				'slug'                      => 'give-recurring',
				'bundled_plugin_file'       => '/tmp/absorber-does-not-exist-' . uniqid( '', true ) . '.php',
				'plugin_loaded_constant'    => 'ABSORBER_MISSING_FILE_NOTICE_GUARD',
				'dependency_notice_message' => static fn() => 'GiveWP 3.0 or later is required.',
			]
		);

		Loader::load_all();

		$this->assertSame( [], $this->notice_queue() );
	}

	/**
	 * file_exists() is true for a directory, and require_once fatals on one.
	 */
	public function test_it_skips_when_the_bundled_path_is_a_directory(): void {
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => sys_get_temp_dir(),
				'plugin_loaded_constant' => 'ABSORBER_DIRECTORY_GUARD',
			]
		);

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	/**
	 * file_exists() is also true for a file the process cannot read, and require_once fatals.
	 */
	public function test_it_skips_when_the_bundled_file_is_unreadable(): void {
		$path = $this->make_fixture( 'ABSORBER_UNREADABLE_GUARD' );
		chmod( $path, 0000 );

		if ( is_readable( $path ) ) {
			$this->markTestSkipped( 'Running as a user that can read a 0000 file.' );
		}

		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => 'ABSORBER_UNREADABLE_GUARD',
			]
		);

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );

		chmod( $path, 0644 );
	}

	/**
	 * The dependency check calls an arbitrary host callable, so it must not run for a sub-plugin
	 * whose code is already present — and must not warn that requirements are unmet for a plugin
	 * the admin can see running.
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

		Loader::load_all();

		$this->assertSame( 0, $checked );
		$this->assertSame( [], $this->notice_queue(), 'No notice for a plugin that is already running.' );
	}

	public function test_the_should_load_filter_can_veto_the_load(): void {
		$this->register();

		add_filter( 'give/plugin_absorber/should_load', '__return_false' );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
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

		Loader::load_all();

		$this->assertInstanceOf( Sub_Plugin::class, $received );
		$this->assertSame( 'give-recurring', $received->get_slug() );
	}

	/**
	 * The filter is the last gate before require_once, so it must not be consulted for a
	 * sub-plugin that was already going to be skipped — a host counting its invocations would
	 * otherwise see calls for loads that never happen.
	 */
	public function test_the_should_load_filter_is_not_consulted_for_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		$calls = [];
		add_filter(
			'give/plugin_absorber/should_load',
			static function ( $should_load ) use ( &$calls ) {
				$calls[] = $should_load;

				return $should_load;
			}
		);

		Loader::load_all();

		$this->assertSame( [], $calls );

		// The recorder has to be shown to work. Without this, a filter that never attached — a
		// mistyped hook name, an add_filter() that ran too late — leaves the array empty for a
		// reason that has nothing to do with the sub-plugin being disabled, and the assertion above
		// passes having proved nothing at all.
		apply_filters( 'give/plugin_absorber/should_load', true );

		$this->assertSame( [ true ], $calls, 'The recorder must catch a call that really happened.' );
	}

	public function test_it_loads_every_registered_sub_plugin(): void {
		$this->register( [ 'slug' => 'give-recurring' ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		Loader::load_all();

		$this->assertSame( 2, $GLOBALS['absorber_loads'] );
	}

	/**
	 * One sub-plugin failing its checks must not stop the rest, or the failure order would decide
	 * which plugins a site gets.
	 */
	public function test_a_skipped_sub_plugin_does_not_stop_the_others(): void {
		$this->register( [ 'slug' => 'give-recurring', 'enabled' => false ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
	}

	/**
	 * Registrar_Interface::all() can only declare `array`, so a host implementation is free to
	 * return anything. The default Registrar cannot produce this state — only a bound one can.
	 */
	public function test_load_all_ignores_entries_that_are_not_sub_plugins(): void {
		$constant   = 'ABSORBER_MIXED_REGISTRY_GUARD';
		$path       = $this->make_fixture( $constant );
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
				// The interface can only declare `array`, and its docblock is a promise a host is
				// free to break — which is exactly what this double is here to do. Restating the
				// promised shape is what lets the analyser check every other implementation
				// strictly while this one hands back the junk the Loader has to survive.
				/** @var array<string,Sub_Plugin> $entries */
				$entries = $this->entries;

				return $entries;
			}
		};

		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $registrar );
		Config::set_container( $container );

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
	}

	/**
	 * Buffering registration is what lets the container arrive after the sub-plugins do, and the
	 * registry is only half of that: the load path resolves the notice queue as well. A
	 * collaborator pinned by an eager resolve inside register() would leave the host's binding
	 * bound and never used.
	 */
	public function test_a_container_set_after_register_reaches_the_load_path(): void {
		// The harness boots WordPress before any test runs, so plugins_loaded has already fired and
		// boot() would rightly report that wiring at priority 2 is too late and load inline
		// instead. Rewind it so boot() takes the path a real host bootstrap takes; tearDown puts
		// the count back.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );

		$this->register( [ 'dependency_check' => static fn() => false ] );

		$notices = new class() implements Queue_Interface {
			/**
			 * Slugs handed to queue_dependency_notice(), in order.
			 *
			 * @var string[]
			 */
			public $dependency_notices = [];

			public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
			}

			public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
			}

			public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
				$this->dependency_notices[] = $sub_plugin->get_slug();
			}

			public function render(): void {
			}
		};

		// The registrar is bound as well as the queue. Without it the sub-plugin would sit in the
		// default registrar either way and the notice would arrive however register() behaved, so
		// the test would pass without the buffer existing at all.
		$registrar = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton( Registrar_Interface::class, static fn() => $registrar );
		$container->singleton( Queue_Interface::class, static fn() => $notices );
		Config::set_container( $container );

		Loader::boot();

		$this->assertSame(
			2,
			has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] ),
			'boot() must have wired the load hook rather than loading inline.'
		);

		Loader::load_all();

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
	 * require_once dedupes by resolved path, so one file behind two registrations executes once
	 * even when the second one's guard constant never gets defined.
	 */
	public function test_one_bundled_file_behind_two_registrations_loads_once(): void {
		$path = sys_get_temp_dir() . '/absorber-shared-' . uniqid( '', true ) . '.php';
		file_put_contents(
			$path,
			'<?php' . PHP_EOL . '$GLOBALS["absorber_loads"] = ( $GLOBALS["absorber_loads"] ?? 0 ) + 1;' . PHP_EOL
		);
		$this->fixtures[] = $path;

		foreach ( [ 'give-recurring', 'give-fee-recovery' ] as $index => $slug ) {
			Loader::register(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => $path,
					'plugin_loaded_constant' => 'ABSORBER_SHARED_FILE_GUARD_' . $index,
				]
			);
		}

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
	}

	public function test_load_all_does_nothing_without_a_hook_prefix(): void {
		$this->register();

		Config_State::reset();
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'], 'A bootstrap mistake must not fatal the site.' );
	}

	public function test_render_notices_delegates_to_the_bound_collaborator(): void {
		$notices = new class() implements Queue_Interface {
			/**
			 * @var int
			 */
			public $render_calls = 0;

			public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
			}

			public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
			}

			public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
			}

			public function render(): void {
				++$this->render_calls;
			}
		};

		$container = new Test_Container();
		$container->singleton( Queue_Interface::class, static fn() => $notices );
		Config::set_container( $container );

		Loader::render_notices();

		$this->assertSame( 1, $notices->render_calls );
	}

	public function test_render_notices_does_nothing_without_a_hook_prefix(): void {
		Config_State::reset();
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader' );

		ob_start();
		Loader::render_notices();

		$this->assertSame( '', (string) ob_get_clean() );
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
	 * Write a throwaway bundled plugin that counts its own loads and defines its guard constant.
	 *
	 * A unique path per test is required: require_once caches by resolved path for the lifetime of
	 * the PHP process, so a shared fixture would make later tests pass without loading anything.
	 *
	 * @param string $constant Guard constant to define.
	 */
	private function make_fixture( string $constant ): string {
		$path = sys_get_temp_dir() . '/absorber-' . uniqid( '', true ) . '.php';

		file_put_contents(
			$path,
			'<?php' . PHP_EOL
			. '$GLOBALS["absorber_loads"] = ( $GLOBALS["absorber_loads"] ?? 0 ) + 1;' . PHP_EOL
			. 'if ( ! defined( "' . $constant . '" ) ) { define( "' . $constant . '", "1.0.0" ); }' . PHP_EOL
		);

		$this->fixtures[] = $path;

		return $path;
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function register( array $overrides = [], ?string $constant = null ): string {
		$constant = $constant ?? 'ABSORBER_FIXTURE_' . strtoupper( bin2hex( random_bytes( 4 ) ) );
		$path     = $this->make_fixture( $constant );

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
