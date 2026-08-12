<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Activator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Notices\Queue;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Activator;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Gatekeeper;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Queue;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Resolver;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithHaltedRedirects;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;

/**
 * The library driven against a real WordPress, end to end.
 *
 * Every other class in the suite tests one collaborator with its neighbours doubled. This one doubles
 * nothing by default and reaches for no entry point a host does not have: the bootstrap is
 * `Config::set_hook_prefix()`, `Config::set_container()`, `Loader::register()` and `Loader::boot()`,
 * and everything after that arrives through the hooks boot() wired. `active_plugins` is the real
 * option, `deactivate_plugins()` is core's own, the notice queue and the activation record are real
 * rows, and the guard constants are real `define()` calls made by real files on disk.
 *
 * The container is deliberately *not* pre-registered by `Traits\WithContainer`. A host hands
 * `Config` a bare container and `Loader::boot()` is what teaches it about this library, so running
 * the provider up front would leave the one binding step a host performs untested here.
 *
 * A "request" is `do_action( 'plugins_loaded' )` — the conflict step at priority 1 and the load pass
 * at 2, in the order and at the priorities boot() wired them. Several tests run two in a row, which
 * is the only way to cover what this library is for: the merge takes one request to resolve and the
 * next one to load.
 *
 * Two functions are stubbed, and only two. `wp_get_referer()`, because a referrer is a request
 * header no test can send and the destination after a deactivation is decided from it; and
 * `wp_safe_redirect()`, because the line after it is `exit`. The redirect stub throws instead, which
 * stops the request where production stops it — never `preventExit()`, which would let a test run
 * past that point and report a failure as a pass.
 *
 * @since 1.0.0
 */
class EndToEndTest extends WPTestCase {
	use UopzFunctions;
	use WithBundledPlugins;
	use WithHaltedRedirects;
	use WithIncorrectUsage;
	use WithUsers;

	/**
	 * @var string
	 */
	private const HOOK_PREFIX = 'absorber_host';

	/**
	 * @var string
	 */
	private const SLUG = 'absorber-recurring';

	/**
	 * The standalone copy the sub-plugin below absorbed.
	 *
	 * @var string
	 */
	private const STANDALONE = 'absorber-standalone/absorber-standalone.php';

	/**
	 * Core's own activation-error sentence, spelled out rather than built with `__()` — the rewrite
	 * is a `str_replace()` against this exact string.
	 *
	 * @var string
	 */
	private const CORE_TEXT = 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.';

	/**
	 * The notice core is about to print, as `wp_admin_notice_markup` hands it over.
	 *
	 * @var string
	 */
	private const MARKUP = '<div class="notice notice-error is-dismissible"><p>' . self::CORE_TEXT . '</p></div>';

	/**
	 * Guard constants a test defined through uopz, undone in tearDown.
	 *
	 * @var string[]
	 */
	private $constants = [];

	/**
	 * Hook callbacks these tests added, as [ hook, callback, priority ] triples.
	 *
	 * Tracked so tearDown can take back exactly what a test put there. `remove_all_filters()` would
	 * strip the hook bare instead, discarding every callback WordPress and the rest of the suite have
	 * on it for the remainder of the process.
	 *
	 * @var array<int,array{0:string,1:callable,2:int}>
	 */
	private $added_hooks = [];

	/**
	 * @var string|null
	 */
	private $request_method = null;

	/**
	 * The plugins_loaded count as the harness left it.
	 *
	 * @var int
	 */
	private $plugins_loaded_count = 0;

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();

		// The first half of the bootstrap. The container is the second, and each test builds its own
		// so that a host binding its implementations first has somewhere to bind them.
		Config::set_hook_prefix( self::HOOK_PREFIX );

		// Conflict resolution runs only on an interactive admin GET, since plugins_loaded fires on
		// every request. Without both of these every policy test below would pass while resolving
		// nothing at all.
		set_current_screen( 'plugins' );
		$this->request_method      = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// Deactivating a standalone and consuming the notice queue are both gated on
		// activate_plugins, which on multisite maps through manage_network_plugins.
		$this->become_plugin_administrator();

		// A referrer is a header no test can send. False is the ordinary case — a link followed from
		// somewhere outside the admin — and it sends the user to the plugins list.
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->clear_state();
		$this->reset_bundled_plugin_loads();

		// The harness has to boot WordPress before it can run anything, so plugins_loaded has already
		// fired by the time any test starts — and boot() would rightly report that it is too late to
		// wire. Rewind the counter so a test sees the timing a host bootstrap sees; the late-boot test
		// dispatches the hook itself to close the window again.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );
	}

	public function tearDown(): void {
		// In tearDown rather than at the end of each test body: a failed assertion would otherwise
		// leave an admin screen, a pinned request method, a half-built activation-error request and a
		// rewound hook counter standing for every test that runs afterwards in this process.
		$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;

		if ( $this->request_method === null ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

		unset( $_GET['plugin'], $_GET['_error_nonce'] );
		set_current_screen( 'front' );

		foreach ( $this->constants as $constant ) {
			$this->unsetConstant( $constant );
		}
		$this->constants = [];

		// Only what these tests added by hand. What boot() wired comes off in Loader_State::reset().
		foreach ( $this->added_hooks as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->added_hooks = [];

		$this->stop_expecting_incorrect_usage();
		$this->remove_bundled_plugin_files();
		$this->clear_state();
		Loader_State::reset();
		Config_State::reset();
		parent::tearDown();
	}

	/**
	 * The happy path, and the one every other scenario is a deviation from: nothing else claims the
	 * plugin, so the bundled copy loads, defines the guard the standalone would have defined, and
	 * gets the one-time setup that `register_activation_hook()` never gives it.
	 */
	public function test_a_fresh_load_defines_the_guard_and_activates_exactly_once(): void {
		$activated = [];

		$constant = $this->register(
			[
				'activation_callback' => static function ( Sub_Plugin $sub_plugin ) use ( &$activated ): void {
					$activated[] = $sub_plugin->get_slug();
				},
			]
		);

		$this->boot();
		$this->run_request();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertTrue( defined( $constant ), 'The bundled copy defines the guard the standalone would have.' );
		$this->assertSame( [ self::SLUG ], $activated );
		$this->assertSame( [ self::SLUG => true ], $this->activation_record() );

		// The next page view, with nothing re-registered and nothing re-booted. The constant the file
		// really defined stands the load down, and the record really written stands the callback down.
		$this->run_request();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assertSame( [ self::SLUG ], $activated, 'Activation runs once for the life of the site.' );
	}

	/**
	 * The default policy, against core's own `deactivate_plugins()` and the real `active_plugins`
	 * option rather than a stub of either.
	 */
	public function test_deactivate_deactivates_notifies_and_redirects(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
			]
		);

		$this->boot();

		$location = $this->run_halted_request();

		$this->assertNotContains( self::STANDALONE, $this->active_plugins() );
		$this->assertArrayHasKey( self::SLUG . ':merge', $this->notice_queue() );

		// The destination, not merely that one was asked for: a redirect somewhere else entirely
		// would satisfy "the request ended in a redirect" without sending anyone anywhere useful.
		$this->assertSame( admin_url( 'plugins.php' ), $location );

		// The request really ended in the resolver. The bundled copy loads on the next one, which is
		// what the standalone's own guard constant forces in production.
		$this->assertSame( 0, $this->bundled_plugin_loads() );
	}

	/**
	 * All the way to the screen. The merge notice is the one this library raises exactly once and
	 * never re-queues, so the admin page load after the deactivation has to draw it — and consume it,
	 * or the owner reads the same deactivation report for ever.
	 */
	public function test_the_merge_notice_renders_on_the_next_admin_screen_and_clears(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		$this->boot();
		$this->run_halted_request();

		$rendered = $this->render_admin_notices();

		$this->assertStringContainsString( self::SLUG, $rendered );
		$this->assertStringContainsString( 'has been deactivated', $rendered );
		$this->assertStringContainsString(
			'notice-warning',
			$rendered,
			'A conflict the library has already handled is a warning, not an error.'
		);
		$this->assertSame( [], $this->notice_queue(), 'Rendering consumes the queue.' );
	}

	/**
	 * The failure mode a merge notice queued on every request would produce: a redirect loop, or an
	 * admin screen that reports the same deactivation for ever. Nothing is re-registered between the
	 * two requests — a duplicate slug throws — because this is the next page view, not a second
	 * bootstrap.
	 */
	public function test_the_request_after_a_deactivation_does_not_loop(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$constant = $this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		$this->boot();
		$this->run_halted_request();

		$this->assertArrayHasKey( self::SLUG . ':merge', $this->notice_queue() );

		// The owner has been told. Emptying the queue is what makes a second notice visible at all:
		// re-queuing writes the same `slug:merge` key, so a queue left as it is would look identical
		// whether or not the resolver ran again.
		delete_site_option( Queue::option_name() );

		// This one must not halt, and run_request() fails the test if it does — which is the
		// redirect loop, asserted rather than described.
		$this->run_request();

		$this->assertSame( [], $this->notice_queue(), 'Nothing is left to resolve, so nothing is left to say.' );
		$this->assertSame( 1, $this->bundled_plugin_loads(), 'With the standalone gone the bundled copy takes over.' );
		$this->assertTrue( defined( $constant ) );
	}

	/**
	 * DEFER hands the request to the standalone, and WordPress includes an active plugin from
	 * wp-settings.php long before plugins_loaded — so by the time the resolver runs, the standalone
	 * has already defined the guard constant. Defining it up front is what makes this the scenario
	 * the policy actually describes rather than a resolver that merely declined to act.
	 */
	public function test_defer_leaves_the_standalone_active_and_loads_nothing(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$constant = $this->define_guard( 'ABSORBER_E2E_DEFERRED_GUARD' );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEFER,
			],
			$constant
		);

		$this->boot();
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins() );
		$this->assertSame( 0, $this->bundled_plugin_loads(), 'The standalone won; the guard stands the bundled copy down.' );
		$this->assertSame( [], $this->notice_queue() );
	}

	public function test_notice_only_notifies_without_deactivating(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::NOTICE_ONLY,
				'conflict_notice_message'    => static fn() => 'Deactivate the standalone when you get a chance.',
			]
		);

		$this->boot();

		// A policy that only talks must not end the request, which is what run_request() asserts.
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins() );
		$this->assertSame(
			[ self::SLUG . ':conflict' => 'Deactivate the standalone when you get a chance.' ],
			$this->notice_queue()
		);
	}

	/**
	 * The gate that survives every policy and every rebinding: whoever cannot activate a plugin must
	 * not be able to deactivate one by loading an admin page. Nothing is consumed by refusing — the
	 * standalone is still there to detect on the next request, from someone who can act on it, which
	 * is what the second half asserts.
	 */
	public function test_a_user_who_cannot_activate_plugins_resolves_nothing(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$this->boot();
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins(), 'A subscriber must not deactivate anything.' );
		$this->assertSame( [], $this->notice_queue(), 'A user who could never read the notice must not consume it.' );

		$this->become_plugin_administrator();

		$this->run_halted_request();

		$this->assertNotContains( self::STANDALONE, $this->active_plugins() );
		$this->assertArrayHasKey( self::SLUG . ':merge', $this->notice_queue() );
	}

	/**
	 * The conflict the load guard cannot prevent: the owner reinstalls the standalone and presses
	 * Activate, WordPress includes it on top of the bundled copy, and the re-declaration is a real
	 * fatal that core's sandbox reports as "the plugin triggered a fatal error" — true, and useless.
	 *
	 * Driven through `Loader::boot()` and core's own filter dispatch rather than by calling the queue
	 * directly, because the wiring is half of what has to work: an admin-only `add_filter()` that
	 * never ran leaves the useless sentence on the screen.
	 */
	public function test_a_reactivation_attempt_yields_the_friendly_message(): void {
		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_notice_message'    => static fn() => 'Recurring is already bundled with the host plugin.',
			]
		);

		$this->boot();

		// The request core redirects to once the sandboxed activation has fataled.
		$_GET['plugin']       = self::STANDALONE;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . self::STANDALONE );

		$rewritten = apply_filters( 'wp_admin_notice_markup', self::MARKUP, self::CORE_TEXT, [] );

		$this->assertIsString( $rewritten, 'The filter must hand back markup, whatever it did with it.' );

		$filtered = is_string( $rewritten ) ? $rewritten : '';

		$this->assertStringContainsString( 'Recurring is already bundled with the host plugin.', $filtered );
		$this->assertStringNotContainsString( self::CORE_TEXT, $filtered );

		// The notice box stays core's to draw — its classes, its dismiss button, its wrapper. Only
		// the sentence inside belongs to this library.
		$this->assertStringStartsWith( '<div class="notice notice-error is-dismissible"><p>', $filtered );
	}

	/**
	 * The guard is not only about the standalone. A must-use copy, a second host plugin bundling the
	 * same code, or the site owner's own snippet all define the same constant, and any of them means
	 * the code is already in memory.
	 */
	public function test_the_bundled_copy_stands_down_when_the_guard_is_already_defined(): void {
		$constant = $this->define_guard( 'ABSORBER_E2E_ALREADY_LOADED_GUARD' );

		$this->register( [], $constant );

		$this->boot();
		$this->run_request();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertSame( [], $this->notice_queue(), 'A plugin the admin can see running has nothing to explain.' );
	}

	/**
	 * The toggle is read on every request rather than resolved at registration, so flipping it and
	 * running the next request is what proves the first load was skipped for the toggle and not for
	 * something else entirely — a missing file, say, which would leave the same empty counter.
	 */
	public function test_a_sub_plugin_toggled_off_loads_nothing(): void {
		$enabled = false;

		$constant = $this->register(
			[
				'enabled' => static function () use ( &$enabled ) {
					return $enabled;
				},
			]
		);

		$this->boot();
		$this->run_request();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertFalse( defined( $constant ) );
		$this->assertSame( [], $this->notice_queue(), 'A sub-plugin nobody asked for has nothing to report.' );

		$enabled = true;

		$this->run_request();

		$this->assertSame( 1, $this->bundled_plugin_loads(), 'The toggle is the only thing that was stopping it.' );
	}

	/**
	 * The host's last word before the require, on the hook name its own prefix builds.
	 */
	public function test_the_should_load_filter_can_veto_a_load(): void {
		$constant = $this->register();

		$this->add_tracked_filter(
			Config::get_hook_name( 'should_load' ),
			static function ( $should_load, $sub_plugin ) {
				return $sub_plugin instanceof Sub_Plugin && $sub_plugin->get_slug() === self::SLUG
					? false
					: $should_load;
			},
			10,
			2
		);

		$this->boot();
		$this->run_request();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertFalse( defined( $constant ) );
		$this->assertSame( [], $this->notice_queue(), 'A host that vetoed the load does not need telling about it.' );
	}

	/**
	 * Registration order, not slug order and not filesystem order: a host bundles plugins that
	 * depend on one another, and the order it registers them in is the only say it gets.
	 */
	public function test_two_sub_plugins_load_in_one_request_in_registration_order(): void {
		$loaded = [];

		// Recorded from the activation callback, which runs immediately after each require — so this
		// is the order the files were really required in, not the order they were registered in.
		$record = static function ( Sub_Plugin $sub_plugin ) use ( &$loaded ): void {
			$loaded[] = $sub_plugin->get_slug();
		};

		$first  = $this->register( [ 'activation_callback' => $record ] );
		$second = $this->register(
			[
				'slug'                => 'absorber-fee-recovery',
				'activation_callback' => $record,
			]
		);

		$this->boot();
		$this->run_request();

		$this->assertSame( 2, $this->bundled_plugin_loads() );
		$this->assertTrue( defined( $first ) );
		$this->assertTrue( defined( $second ) );
		$this->assertSame( [ self::SLUG, 'absorber-fee-recovery' ], $loaded );
	}

	/**
	 * All the way to the screen again, from the other end: the load is skipped, the host's own
	 * explanation is queued, the render draws it as an error, and the render consumes the queue so
	 * the owner is told once rather than on every admin page load for ever.
	 */
	public function test_an_unmet_dependency_blocks_the_load_and_queues_the_explanation(): void {
		$this->register(
			[
				'dependency_check'          => static fn() => false,
				'dependency_notice_message' => static fn() => 'GiveWP 3.0 or later is required.',
			]
		);

		$this->boot();
		$this->run_request();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertSame(
			[ self::SLUG . ':dependency' => 'GiveWP 3.0 or later is required.' ],
			$this->notice_queue()
		);

		$rendered = $this->render_admin_notices();

		$this->assertStringContainsString( 'GiveWP 3.0 or later is required.', $rendered );
		$this->assertStringContainsString( 'notice-error', $rendered, 'A plugin that did not load at all is an error.' );
		$this->assertSame( [], $this->notice_queue(), 'Rendering consumes the queue.' );
	}

	/**
	 * Booting from plugins_loaded at the default priority is the commonest hook mistake there is, and
	 * an add_action() at a priority the running dispatch has already passed is accepted and then never
	 * fires. The library reports the mistake and runs the sequence inline, so the site the host
	 * shipped still gets its bundled plugins.
	 */
	public function test_a_host_that_boots_too_late_still_gets_its_sub_plugins(): void {
		$this->expect_incorrect_usage();

		$constant = $this->register();

		$this->add_tracked_action(
			'plugins_loaded',
			function (): void {
				$this->boot();
			}
		);

		$this->run_request();

		$this->assertSame( 1, $this->bundled_plugin_loads(), 'A late boot must still load.' );
		$this->assertTrue( defined( $constant ) );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The whole point of a required container: a host binds its own implementation of an interface
	 * before boot, and that is the object the library uses for the rest of the request.
	 *
	 * One request covers the conflict step and the load pass because the referrer is the plugins
	 * list, where the redirector says to stay put. The defaults are asserted *not* to have run
	 * alongside the doubles — a library that resolved a second copy of the queue or the deactivator
	 * behind the host's back would satisfy every positive assertion here.
	 */
	public function test_a_host_binding_reaches_every_step_of_the_request(): void {
		$registrar = new Spy_Registrar();
		$notices   = new Spy_Queue();
		$activator = new Spy_Activator();

		$checker = new class() implements Plugin_Checker_Interface {
			/**
			 * @var string[]
			 */
			public $basenames = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return bool
			 */
			public function is_active( string $basename ): bool {
				$this->basenames[] = $basename;

				return true;
			}
		};

		$deactivator = new class() implements Plugin_Deactivator_Interface {
			/**
			 * @var string[]
			 */
			public $basenames = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return void
			 */
			public function deactivate( string $basename ): void {
				$this->basenames[] = $basename;
			}
		};

		// Really active, so that the default deactivator would have emptied this option had it been
		// the one reached. Nothing else in this test would notice the difference.
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $registrar ): Registrar_Interface {
				return $registrar;
			}
		);
		$container->singleton(
			Plugin_Checker_Interface::class,
			static function () use ( $checker ): Plugin_Checker_Interface {
				return $checker;
			}
		);
		$container->singleton(
			Plugin_Deactivator_Interface::class,
			static function () use ( $deactivator ): Plugin_Deactivator_Interface {
				return $deactivator;
			}
		);
		$container->singleton(
			Queue_Interface::class,
			static function () use ( $notices ): Queue_Interface {
				return $notices;
			}
		);
		$container->singleton(
			Activator_Interface::class,
			static function () use ( $activator ): Activator_Interface {
				return $activator;
			}
		);

		// From the plugins list the redirector says to stay put, so the request runs on into the load
		// pass instead of ending in the resolver.
		$this->setFunctionReturn( 'wp_get_referer', admin_url( 'plugins.php' ) );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
				'activation_callback'        => static fn() => null,
			]
		);

		$this->boot( $container );
		$this->run_request();

		$this->assertSame( [ self::SLUG ], array_keys( $registrar->sub_plugins ), 'The host registrar holds the registration.' );
		$this->assertSame( [ self::STANDALONE ], $checker->basenames, 'The host checker answers whether the standalone is active.' );
		$this->assertSame( [ self::STANDALONE ], $deactivator->basenames, 'The host deactivator is the one asked to turn it off.' );
		$this->assertSame( [ self::SLUG ], $notices->merge_notices, 'The host queue is told what happened.' );
		$this->assertSame( [ self::SLUG ], $activator->slugs, 'The host activator runs the one-time setup.' );
		$this->assertSame( 1, $this->bundled_plugin_loads() );

		$this->assertContains( self::STANDALONE, $this->active_plugins(), 'The default deactivator must not have run too.' );
		$this->assertSame( [], $this->notice_queue(), 'The default queue must not have been resolved alongside it.' );
		$this->assertSame( [], $this->activation_record(), 'The default activator must not have recorded anything.' );
	}

	/**
	 * The same guarantee for the two the conflict step resolves itself. A host owns what a conflict
	 * means — but not who may have one resolved, which is why the gate is asked first and separately,
	 * and is asserted here to have been asked at all.
	 */
	public function test_a_host_binding_replaces_the_gatekeeper_and_the_resolver(): void {
		$gatekeeper = new Spy_Gatekeeper( true );
		$resolver   = new Spy_Resolver();

		update_option( 'active_plugins', [ self::STANDALONE ] );

		$container = new Test_Container();
		$container->singleton(
			Gatekeeper::class,
			static function () use ( $gatekeeper ): Gatekeeper {
				return $gatekeeper;
			}
		);
		$container->singleton(
			Resolver_Interface::class,
			static function () use ( $resolver ): Resolver_Interface {
				return $resolver;
			}
		);

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
			]
		);

		$this->boot( $container );
		$this->run_request();

		$this->assertSame( 1, $gatekeeper->may_resolve_calls, 'The conflict step has to ask the gate.' );
		$this->assertSame( 1, $resolver->resolve_calls );
		$this->assertContains(
			self::STANDALONE,
			$this->active_plugins(),
			'A host resolver that does nothing means nothing is deactivated.'
		);
		$this->assertSame( [], $this->notice_queue() );
		$this->assertSame( 1, $this->bundled_plugin_loads(), 'The load pass still runs after it.' );
	}

	/**
	 * The second half of the bootstrap, and the point every test above starts from.
	 *
	 * The container is handed over bare: `Loader::boot()` is what runs the provider over it, so a
	 * test that pre-registered the bindings would be asserting against a container the library never
	 * had to teach.
	 *
	 * @param Test_Container|null $container Container to bootstrap with, when a test has bound its
	 *                                       own implementations into one.
	 *
	 * @return void
	 */
	private function boot( ?Test_Container $container = null ): void {
		Config::set_container( $container ?? new Test_Container() );

		Loader::boot();
	}

	/**
	 * One page view, which must not end in a redirect.
	 *
	 * `wp_safe_redirect()` is stubbed even here, where nothing should reach it. The real one is
	 * followed by `exit`, which would take the whole test process down rather than fail one test —
	 * so the stub throws, and a request that redirected when it should not have fails right here
	 * instead of silently passing somewhere else.
	 *
	 * @return void
	 */
	private function run_request(): void {
		$message = self::halted_at_exit_message();

		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () use ( $message ) {
				throw new TestException( $message );
			},
			true
		);

		try {
			do_action( 'plugins_loaded' );
		} catch ( TestException $exception ) {
			$this->fail( 'The request must not redirect and end here. ' . $exception->getMessage() );
		} finally {
			// In a finally block so a failed assertion cannot strand the stub for the rest of the
			// process, where a later test's redirect would throw for no reason it can see.
			$this->unsetFunctionReturn( 'wp_safe_redirect' );
		}
	}

	/**
	 * One page view that must end where production calls exit(), and where it sent the user.
	 *
	 * @return string
	 */
	private function run_halted_request(): string {
		return $this->capture_redirect(
			static function (): void {
				do_action( 'plugins_loaded' );
			}
		);
	}

	/**
	 * An admin page load, as far as this library is concerned: the hook it renders the queue on.
	 *
	 * Dispatched rather than calling `Loader::render_notices()`, because the admin-only `add_action()`
	 * is half of what has to work — a queue nothing renders is a queue nothing clears either.
	 *
	 * @return string
	 */
	private function render_admin_notices(): string {
		ob_start();

		do_action( 'all_admin_notices' );

		return (string) ob_get_clean();
	}

	/**
	 * Register one sub-plugin, backed by a bundled file that exists.
	 *
	 * Called before the container is set, which is legal and deliberate: registration is buffered, so
	 * a host that builds its config array before it builds its container still works. The guard
	 * constant is unique per call unless the test names one, because loading the file defines it with
	 * a real `define()` that lasts for the whole PHP process.
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 * @param string|null         $constant  Guard constant to use, when the test needs to define it.
	 *
	 * @return string
	 */
	private function register( array $overrides = [], ?string $constant = null ): string {
		$constant = $constant ?? $this->make_guard_constant();
		$slug     = isset( $overrides['slug'] ) && is_string( $overrides['slug'] ) && $overrides['slug'] !== ''
			? $overrides['slug']
			: self::SLUG;

		Loader::register(
			array_merge(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => $this->make_bundled_plugin_file( $constant ),
					'plugin_loaded_constant' => $constant,
				],
				$overrides
			)
		);

		return $constant;
	}

	/**
	 * Define a guard constant for the duration of one test, undone in tearDown.
	 *
	 * uopz is what makes this reversible: a plain `define()` lasts for the whole PHP process, and a
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
	 * Add an action tearDown can take back by identity rather than by clearing the whole hook.
	 *
	 * @param string   $hook     Hook to add to.
	 * @param callable $callback Callback to add.
	 * @param int      $priority Priority to add it at.
	 *
	 * @return void
	 */
	private function add_tracked_action( string $hook, callable $callback, int $priority = 10 ): void {
		$this->added_hooks[] = [ $hook, $callback, $priority ];

		add_action( $hook, $callback, $priority );
	}

	/**
	 * The same, for a filter. Spelled separately even though WordPress keeps actions and filters in
	 * one registry, so a reader is never left wondering whether a filter was wired by an add_action()
	 * on purpose.
	 *
	 * @param string   $hook          Hook to add to.
	 * @param callable $callback      Callback to add.
	 * @param int      $priority      Priority to add it at.
	 * @param int      $accepted_args How many arguments the callback takes.
	 *
	 * @return void
	 */
	private function add_tracked_filter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->added_hooks[] = [ $hook, $callback, $priority ];

		add_filter( $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Everything this suite writes outside its own fixtures, cleared before and after each test.
	 *
	 * @return void
	 */
	private function clear_state(): void {
		delete_site_option( Queue::option_name() );
		delete_site_option( Activator::option_name() );
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * @return array<mixed>
	 */
	private function active_plugins(): array {
		return (array) get_option( 'active_plugins', [] );
	}

	/**
	 * The queue is an option and not a transient: with a persistent object cache a transient never
	 * reaches the database, and a `wp_cache_flush()` would destroy a merge notice raised exactly once.
	 * `get_site_option()` is `get_option()` outside multisite, so one read covers both install types.
	 *
	 * @return array<string,string>
	 */
	private function notice_queue(): array {
		$queue = get_site_option( Queue::option_name(), [] );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function activation_record(): array {
		$done = get_site_option( Activator::option_name(), [] );

		return is_array( $done ) ? $done : [];
	}
}
