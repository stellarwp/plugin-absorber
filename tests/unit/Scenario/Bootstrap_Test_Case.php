<?php
/**
 * Shared bootstrap for the scenario suite.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Scenario;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithHaltedRedirects;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithNoticeQueue;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithRequestMethod;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;
use WP_Hook;

/**
 * The library driven the way a host plugin drives it, against a real WordPress.
 *
 * Every other class in the suite tests one collaborator with its neighbours doubled. The scenario
 * files double nothing by default and reach for no entry point a host does not have: the bootstrap is
 * `Config::set_hook_prefix()`, `Config::set_container()`, `Absorber::register()` and
 * `Absorber::boot()`, and everything after that arrives through the hooks boot() wired.
 * `active_plugins` is the real option, `deactivate_plugins()` is core's own, the notice queue and the
 * activation record are real rows, and the guard constants are real `define()` calls made by real
 * files on disk.
 *
 * The container is deliberately *not* pre-registered by `Traits\WithContainer`. A host hands `Config`
 * a bare container and `Absorber::boot()` is what teaches it about this library, so running the
 * provider up front would leave the one binding step a host performs untested here.
 *
 * A "request" is `do_action( 'plugins_loaded' )` — the conflict step at priority 5 and the load pass
 * at 6, in the order and at the priorities boot() wired them. Several scenarios run two in a row,
 * which is the only way to cover what this library is for: a merge takes one request to resolve and
 * the next one to load.
 *
 * Two functions are stubbed, and only two, both of them for the duration of one dispatched request.
 * `wp_safe_redirect()`, because the line after it is `exit`: the stub throws instead, which stops the
 * request where production stops it — never `preventExit()`, which would let a test run past that
 * point and report a failure as a pass. And `headers_sent()`, because a request served over HTTP has
 * sent nothing by `plugins_loaded` and one run under CLI has sent whatever the test runner printed.
 * That second one is answered `false` unless a scenario says otherwise through
 * `pin_headers_as_sent()`, which is the shape a late boot really takes on a debugging site: the
 * `_doing_it_wrong()` the fallback opens with has already printed, so the redirect at the end of the
 * conflict pass has no headers left to send.
 *
 * Abstract, and named `_Test_Case` rather than `…Test`, so the runner collects the scenario files
 * that extend it and never this one.
 *
 * @since 1.0.0
 */
abstract class Bootstrap_Test_Case extends WPTestCase {
	use UopzFunctions;
	use WithBundledPlugins;
	use WithHaltedRedirects;
	use WithIncorrectUsage;
	use WithNoticeQueue;
	use WithRequestMethod;
	use WithUsers;

	/**
	 * @var string
	 */
	protected const HOOK_PREFIX = 'absorber_host';

	/**
	 * @var string
	 */
	protected const SLUG = 'absorber-recurring';

	/**
	 * The standalone copy the sub-plugin below absorbed.
	 *
	 * @var string
	 */
	protected const STANDALONE = 'absorber-standalone/absorber-standalone.php';

	/**
	 * Guard constants a scenario defined through uopz, undone in tearDown.
	 *
	 * @var string[]
	 */
	private $constants = [];

	/**
	 * Hook callbacks these scenarios added, as [ hook, callback, priority ] triples.
	 *
	 * Tracked so tearDown can take back exactly what a scenario put there. `remove_all_filters()`
	 * would strip the hook bare instead, discarding every callback WordPress and the rest of the suite
	 * have on it for the remainder of the process.
	 *
	 * @var array<int,array{0:string,1:callable,2:int}>
	 */
	private $added_hooks = [];

	/**
	 * The plugins_loaded count as the harness left it.
	 *
	 * @var int
	 */
	private $plugins_loaded_count = 0;

	/**
	 * What `headers_sent()` answers for the duration of a dispatched request.
	 *
	 * @var bool
	 */
	private $headers_sent = false;

	/**
	 * The request URI as the harness left it, put back in tearDown.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();

		// The first half of the bootstrap. The container is the second, and each scenario builds its
		// own so that a host binding its implementations first has somewhere to bind them.
		Config::set_hook_prefix( self::HOOK_PREFIX );

		// Conflict resolution runs only on an interactive admin GET, since plugins_loaded fires on
		// every request. Without both of these every policy scenario would pass while resolving
		// nothing at all.
		set_current_screen( 'plugins' );
		$this->set_request_method( 'GET' );

		// One capability, asked twice: Conflict\Gatekeeper before a standalone is deactivated and
		// Notices\Presenter before the queue reporting it is printed and cleared, both of them
		// manage_network_plugins on multisite and activate_plugins everywhere else.
		$this->become_plugin_administrator();

		// Where a resolved conflict sends the user is read off the current request rather than off the
		// referrer, so the URI is stated rather than inherited: $_SERVER outlives whichever test wrote
		// to it last, and a destination assertion against a URI this file never set would be right by
		// accident. The plugins list, to match the screen set above.
		$this->request_uri      = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';

		$this->clear_state();
		$this->reset_bundled_plugin_loads();

		// The harness has to boot WordPress before it can run anything, so plugins_loaded has already
		// fired by the time any scenario starts — and boot() would rightly report that it is too late
		// to wire. Rewind the counter so a scenario sees the timing a host bootstrap sees; the
		// late-boot scenario dispatches the hook itself to close the window again.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );
	}

	/**
	 * @return void
	 */
	public function tearDown(): void {
		// In tearDown rather than at the end of each scenario body: a failed assertion would otherwise
		// leave an admin screen, a pinned request method and URI, and a rewound hook counter standing
		// for every test that runs afterwards in this process.
		$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;

		$this->restore_request_method();

		if ( $this->request_uri === null ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		set_current_screen( 'front' );

		foreach ( $this->constants as $constant ) {
			$this->unsetConstant( $constant );
		}
		$this->constants = [];

		// Only what these scenarios added by hand. What boot() wired comes off in
		// Absorber_State::reset().
		foreach ( $this->added_hooks as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->added_hooks = [];

		$this->stop_expecting_incorrect_usage();
		$this->remove_bundled_plugin_files();

		// Before the two resets below, not after: the queue's option name is read off the bound
		// `Notices\Store`, and clearing the container first would leave the row standing.
		$this->clear_state();

		Absorber_State::reset();
		Config_State::reset();
		parent::tearDown();
	}

	/**
	 * The second half of the bootstrap, and the point every scenario starts from.
	 *
	 * The container is handed over bare: `Absorber::boot()` is what runs the provider over it, so a
	 * scenario that pre-registered the bindings would be asserting against a container the library
	 * never had to teach.
	 *
	 * @since 1.0.0
	 *
	 * @param Test_Container|null $container Container to bootstrap with, when a scenario has bound its
	 *                                       own implementations into one.
	 *
	 * @return void
	 */
	protected function boot( ?Test_Container $container = null ): void {
		Config::set_container( $container ?? new Test_Container() );

		Absorber::boot();
	}

	/**
	 * One page view, which must not end in a redirect.
	 *
	 * `wp_safe_redirect()` is stubbed even here, where nothing should reach it. The real one is
	 * followed by `exit`, which would take the whole test process down rather than fail one test — so
	 * the stub throws, and a request that redirected when it should not have fails right here instead
	 * of silently passing somewhere else.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function run_request(): void {
		$halted  = false;
		$message = self::halted_at_exit_message();

		$this->pin_headers();

		// The stub raises the flag itself. Catching the TestException here would never fire: every
		// plugins_loaded step wraps itself in `catch ( Throwable )` so that a hook this library owns
		// can never white-screen a site, so the throw is swallowed inside the step and a `try`/`catch`
		// around the dispatch would pass whether the request redirected or not — which is the failure
		// this helper exists to prevent. Reading the halt back out of the library's own
		// `_doing_it_wrong()` report would work, but only for as long as the report keeps its present
		// wording, and the report is not the fact under test. Only this stub can be reached by a
		// redirect, so only this stub is asked.
		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () use ( &$halted, $message ) {
				$halted = true;

				throw new TestException( $message );
			},
			true
		);

		try {
			do_action( 'plugins_loaded' );
		} finally {
			// In a finally block so a failed assertion cannot strand the stubs for the rest of the
			// process, where a later test's redirect would throw for no reason it can see.
			$this->unsetFunctionReturn( 'wp_safe_redirect' );
			$this->unsetFunctionReturn( 'headers_sent' );
		}

		$this->assertFalse( $halted, 'The request must not redirect and end here.' );
	}

	/**
	 * One page view that must end where production calls exit(), and where it sent the user.
	 *
	 * `WithHaltedRedirects::capture_redirect()` is the right tool one level down, where a redirect
	 * throws out of the call under test and a `catch` can see it. It is the wrong tool here, and
	 * quietly so: every `plugins_loaded` step wraps itself in `catch ( Throwable )` — the promise that
	 * a hook this library owns can never white-screen a site — and a stubbed redirect is a throw like
	 * any other, swallowed and reported before it could leave the step. A `catch` around the dispatch
	 * would pass whether or not the request redirected.
	 *
	 * So the stub records the halt itself, and both halves are still asserted: the flag, which only a
	 * redirect can raise, and the library's own `_doing_it_wrong()` report, which says the step ended
	 * the way a swallowed throw ends it. Reading the flag *out of* that report instead would tie the
	 * assertion to the report's present wording, and the wording is not the fact under test.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function run_halted_request(): string {
		$halted   = false;
		$location = '';
		$message  = self::halted_at_exit_message();

		$this->pin_headers();

		// Emptying the hook is a statement about this request and not about the process, so what was
		// on it is put back afterwards. Cloned rather than aliased: the stub empties the object in
		// place, and a reference to it would restore nothing.
		$wired = isset( $GLOBALS['wp_filter']['plugins_loaded'] ) && $GLOBALS['wp_filter']['plugins_loaded'] instanceof WP_Hook
			? clone $GLOBALS['wp_filter']['plugins_loaded']
			: null;

		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function ( $to ) use ( &$halted, &$location, $message ) {
				$halted   = true;
				$location = is_string( $to ) ? $to : '';

				// What `exit` means, modelled: nothing else in this request runs, the load pass wired
				// one priority behind included. uopz cannot stub `exit`, and `preventExit()` would let
				// the request carry on past the line production never returns from — so a scenario
				// that asserts nothing loaded after a redirect would be asserting it about a request
				// production never serves.
				remove_all_actions( 'plugins_loaded' );

				throw new TestException( $message );
			},
			true
		);

		// The step reports the swallowed throw through _doing_it_wrong(), and WPTestCase fails a test
		// that receives one it did not expect.
		$this->expect_incorrect_usage();

		try {
			do_action( 'plugins_loaded' );
		} finally {
			$this->unsetFunctionReturn( 'wp_safe_redirect' );
			$this->unsetFunctionReturn( 'headers_sent' );

			if ( $wired !== null ) {
				$GLOBALS['wp_filter']['plugins_loaded'] = $wired;
			}
		}

		$this->assertTrue( $halted, 'The request had to stop where production calls exit().' );
		$this->assert_the_library_reported_incorrect_usage();

		return $location;
	}

	/**
	 * Describe a request whose output has already started, for the rest of this scenario.
	 *
	 * The opposite of what every other scenario assumes, and it has exactly one shape in production:
	 * `Boot\Scheduler` opens its inline fallback with a `_doing_it_wrong()`, which on a site with
	 * display_errors on prints — so by the time the conflict pass reaches its redirect the headers are
	 * gone. `Conflict\Resolver` reads `headers_sent()` there and stands the redirect down rather than
	 * ending the request on a blank page, which is what lets the load pass behind it run at all.
	 *
	 * Set before the request rather than passed to it, because it is a fact about the scenario and not
	 * about one dispatch: a scenario that says the output has started says it for every request it goes
	 * on to make.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function pin_headers_as_sent(): void {
		$this->headers_sent = true;
	}

	/**
	 * An admin page load, as far as this library is concerned: the hook `Notices\Presenter` draws the
	 * queue on.
	 *
	 * Dispatched rather than calling `Absorber::render_notices()`, because the admin-only
	 * `add_action()` is half of what has to work — a queue nothing renders is a queue nothing clears
	 * either.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function render_admin_notices(): string {
		ob_start();

		do_action( 'all_admin_notices' );

		return (string) ob_get_clean();
	}

	/**
	 * Register one sub-plugin, backed by a bundled file that exists.
	 *
	 * Called before the container is set, which is legal and deliberate: registration is buffered, so
	 * a host that builds its config array before it builds its container still works. The guard
	 * constant is unique per call unless the scenario names one, because loading the file defines it
	 * with a real `define()` that lasts for the whole PHP process.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 * @param string|null         $constant  Guard constant to use, when the scenario needs to define
	 *                                       it.
	 *
	 * @return string
	 */
	protected function register( array $overrides = [], ?string $constant = null ): string {
		$constant = $constant ?? $this->make_guard_constant();
		$slug     = isset( $overrides['slug'] ) && is_string( $overrides['slug'] ) && $overrides['slug'] !== ''
			? $overrides['slug']
			: self::SLUG;

		Absorber::register(
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
	 * Define a guard constant for the duration of one scenario, undone in tearDown.
	 *
	 * uopz is what makes this reversible: a plain `define()` lasts for the whole PHP process, and a
	 * guard left standing makes every later test read its sub-plugin as already loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $constant Constant to define.
	 *
	 * @return string
	 */
	protected function define_guard( string $constant ): string {
		$this->constants[] = $constant;

		$this->setConstant( $constant, '1.0.0' );

		return $constant;
	}

	/**
	 * Add an action tearDown can take back by identity rather than by clearing the whole hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook     Hook to add to.
	 * @param callable $callback Callback to add.
	 * @param int      $priority Priority to add it at.
	 *
	 * @return void
	 */
	protected function add_tracked_action( string $hook, callable $callback, int $priority = 10 ): void {
		$this->added_hooks[] = [ $hook, $callback, $priority ];

		add_action( $hook, $callback, $priority );
	}

	/**
	 * The same, for a filter. Spelled separately even though WordPress keeps actions and filters in
	 * one registry, so a reader is never left wondering whether a filter was wired by an add_action()
	 * on purpose.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook          Hook to add to.
	 * @param callable $callback      Callback to add.
	 * @param int      $priority      Priority to add it at.
	 * @param int      $accepted_args How many arguments the callback takes.
	 *
	 * @return void
	 */
	protected function add_tracked_filter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->added_hooks[] = [ $hook, $callback, $priority ];

		add_filter( $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<mixed>
	 */
	protected function active_plugins(): array {
		return (array) get_option( 'active_plugins', [] );
	}

	/**
	 * Everything a scenario recorded as having activated a sub-plugin once ever.
	 *
	 * Composed here rather than read off the `Activator`, which keeps its option name private on
	 * purpose: nothing outside that class assembles the name, and nothing outside it has a reader to
	 * offer. `Notices\Store` is the opposite case and is read through `WithNoticeQueue` instead of
	 * being composed a second time here.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int|string,mixed>
	 */
	protected function activation_record(): array {
		// get_site_option() is get_option() outside multisite, so one read covers both install types.
		$done = get_site_option( Config::get_option_name( 'activations' ), [] );

		return is_array( $done ) ? $done : [];
	}

	/**
	 * Everything this suite writes outside its own fixtures, cleared before and after each scenario.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function clear_state(): void {
		$this->clear_notices();

		delete_site_option( Config::get_option_name( 'activations' ) );
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * State what a scenario assumes about its own output, rather than leaving it to the runtime.
	 *
	 * `Conflict\Resolver` reads `headers_sent()` to decide whether it may redirect at all, and under
	 * CLI the real answer is not about this request but about the test runner: PHP records headers as
	 * sent the moment anything reaches the output layer, so a single line printed before the suite
	 * starts settles it for the whole process. From PHP 8.4 on, the deprecation notices Codeception's
	 * own vendor tree emits while booting are that line — which is why leaving this to the runtime
	 * passed on 7.4 and failed on 8.5.
	 *
	 * Both halves matter, so both request helpers pin it. Unpinned, `run_halted_request()` fails
	 * because the resolver takes the sent-headers branch and never redirects — and, far worse,
	 * `run_request()` *passes* for the same reason, since a request that cannot redirect satisfies
	 * "this one must not redirect" without anything having been tested.
	 *
	 * Undone in the same `finally` that takes the redirect stub back off, so nothing outside a
	 * dispatched request is answered for.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function pin_headers(): void {
		$this->setFunctionReturn( 'headers_sent', $this->headers_sent );
	}
}
