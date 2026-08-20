<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Boot;

use Codeception\TestCase\WPTestCase;
use Generator;
use LogicException;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Presenter;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithNoticeQueue;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithRequestMethod;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;
use ReflectionClass;
use RuntimeException;
use WP_Hook;

/**
 * When the library's steps run, and what happens when a host asks too late.
 *
 * Driven through `Absorber::boot()` rather than by calling the scheduler directly. That is the only
 * entry point a host has, and the timing this class exists to get right is a property of the whole
 * boot — a scheduler asked to wire in isolation cannot show that a host booting from `plugins_loaded`
 * at the default priority still gets its sub-plugins.
 *
 * The steps are closures over the container now, so a callback cannot be recognised by name: the
 * assertions are that a callback appeared at the right priority, and that firing the hook does what
 * the step was wired to do.
 *
 * @since 1.0.0
 */
class SchedulerTest extends WPTestCase {
	use WithBundledPlugins;
	use WithContainer;
	use WithIncorrectUsage;
	use WithNoticeQueue;
	use WithRequestMethod;
	use WithUsers;

	/**
	 * @var int
	 */
	private $plugins_loaded_count = 0;

	/**
	 * Every gate and probe the conflict step reached, in the order it reached them.
	 *
	 * The step short-circuits, so what it did not ask is as much of the behaviour as what it did —
	 * and an ordered log says both in one assertion, where a counter per double would let a
	 * capability check that ran first still satisfy a count of one.
	 *
	 * @var array<int,string>
	 */
	private $conflict_calls = [];

	/**
	 * How many times the container was asked to build a resolver.
	 *
	 * Counted in the binding's factory rather than on the double, because the property under test is
	 * that a request turned away before the resolve step builds nothing at all — which a counter on
	 * the double could not tell apart from one that was built and never asked anything.
	 *
	 * @var int
	 */
	private $resolvers_built = 0;

	/**
	 * Hook callbacks these tests added, as [ hook, callback, priority ] triples.
	 *
	 * Tracked so tearDown can take back exactly what a test put there. remove_all_actions() strips the
	 * hook bare instead, discarding every callback WordPress and the rest of the suite have on it for
	 * the remainder of the process.
	 *
	 * @var array<int,array{0:string,1:callable,2:int}>
	 */
	private $added_actions = [];

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->reset_bundled_plugin_loads();
		$this->clear_notices();

		$this->conflict_calls  = [];
		$this->resolvers_built = 0;

		// The conflict step reads the request method, so the one test that lets the real gatekeeper
		// answer depends on it rather than on whatever the harness happened to leave behind.
		$this->set_request_method( 'GET' );

		// The harness has to boot WordPress before it can run anything, so plugins_loaded has already
		// fired by the time any test starts — and boot() would rightly report that it is too late to
		// wire a hook at the load priority. Rewind the counter so the wiring tests see the timing a
		// real host bootstrap sees. The tests that exercise a late boot set it back.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );
	}

	public function tearDown(): void {
		$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;

		$this->restore_request_method();

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// leak an admin screen into every test that runs after it, since is_admin() checks the
		// current screen before WP_ADMIN.
		set_current_screen( 'front' );

		// Only what these tests added by hand. What boot() wired comes off in Absorber_State::reset().
		foreach ( $this->added_actions as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->added_actions = [];

		$this->stop_expecting_incorrect_usage();
		$this->remove_bundled_plugin_files();
		$this->clear_notices();
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	/**
	 * Ahead of the default priority, so a bundled plugin is in memory before the plugins that expect
	 * it start their own work. The number is documented, so it is part of the contract rather than an
	 * internal — and it is asserted literally rather than read back through the constant, which would
	 * only prove the constant equals itself.
	 *
	 * Below the default and no lower than it has to be: every priority under this one is a band of
	 * the bundled plugin's own plugins_loaded callbacks that never fire, since the standalone copy
	 * wp-settings.php includes keeps all of them.
	 */
	public function test_the_load_step_runs_early_in_plugins_loaded(): void {
		$this->assertSame( 6, self::load_priority() );
	}

	/**
	 * A standalone that survives the conflict defines its guard constant as it loads, and the load
	 * pass has to see that — so resolution runs first and cannot share a priority with it.
	 *
	 * Being first makes this the number a host is measured against, so it is the one that decides how
	 * much room a host has to configure the library in. Priority 5 leaves 0 through 4, which covers
	 * booting at 0 as documented and the priority-1 habit LearnDash and MemberDash already have.
	 */
	/**
	 * The outermost guarantee, and the reason it lives here rather than in each pass: whatever a step
	 * reaches — a collaborator a host's factory could not build, a gate, a probe, a pass that got past
	 * its own guard — `plugins_loaded` fires on every request a site serves, and a throw out of it is
	 * a white screen on all of them. Each step is reported and abandoned on its own, so the step
	 * behind it still runs.
	 *
	 * @dataProvider throwing_steps
	 *
	 * @param string $id Binding the step resolves, bound to a factory that throws.
	 */
	public function test_a_step_that_throws_cannot_end_the_request( string $id ): void {
		$this->expect_incorrect_usage();

		Absorber::boot();

		// After boot, because `Provider::bind_once()` rebinds a class id whatever a host put there
		// first -- it cannot tell a deliberate binding from a container's willingness to autowire the
		// class. Bound before it, this factory would be replaced by the real collaborator and the step
		// would run perfectly well.
		$this->container()->singleton(
			$id,
			static function (): object {
				throw new RuntimeException( 'the host factory needed a database connection' );
			}
		);

		do_action( 'plugins_loaded' );

		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * Both steps, because a guard on one of them leaves the other able to end the request.
	 *
	 * @return Generator<string,array{0:string}>
	 */
	public static function throwing_steps(): Generator {
		yield 'the conflict step' => [ Gatekeeper::class ];
		yield 'the load step'     => [ Loader::class ];
	}

	public function test_the_conflict_step_runs_before_the_load_step(): void {
		$this->assertSame( 5, self::resolve_priority() );
		$this->assertLessThan( self::load_priority(), self::resolve_priority() );
	}

	public function test_it_wires_the_conflict_step_at_the_resolve_priority(): void {
		$this->bind_resolver_double();

		$before = $this->callbacks_at( 'plugins_loaded', self::resolve_priority() );

		Absorber::boot();

		$this->bind_gate_and_probe_doubles();

		// What carries "wired rather than ran" is the callback count and the build counter. The gate
		// log cannot: those doubles are only in place from the line above, so a boot that ran the step
		// would have asked the real gatekeeper and left the log empty either way.
		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', self::resolve_priority() ),
			'boot() must wire the conflict step rather than run it.'
		);
		$this->assertSame( [], $this->conflict_calls, 'Wiring must not ask anything yet.' );
		$this->assertSame( 0, $this->resolvers_built, 'Wiring must not build a resolver.' );

		do_action( 'plugins_loaded' );

		$this->assertContains( 'resolve_all', $this->conflict_calls );
	}

	/**
	 * The whole sequence, on the one request that runs all of it, asserted as an order rather than as
	 * four counts: each step is the reason the next one is worth taking, so a pass that reached them
	 * in another order is not the behaviour.
	 */
	public function test_the_conflict_step_probes_for_a_conflict_before_it_asks_who_is_signed_in(): void {
		$this->bind_resolver_double();

		Absorber::boot();

		$this->bind_gate_and_probe_doubles();

		do_action( 'plugins_loaded' );

		$this->assertSame(
			[ 'request_may_resolve', 'has_conflict', 'user_may_resolve', 'resolve_all' ],
			$this->conflict_calls
		);
	}

	/**
	 * The shape gate reads the request and nothing else, and it comes first so that cron, WP-CLI, a
	 * POST and every front-end view are turned away having resolved no user and built no resolver.
	 */
	public function test_a_request_the_shape_gate_refuses_builds_no_resolver(): void {
		$this->bind_resolver_double();

		Absorber::boot();

		$this->bind_gate_and_probe_doubles( false );

		do_action( 'plugins_loaded' );

		$this->assertSame( [ 'request_may_resolve' ], $this->conflict_calls );
		$this->assertSame( 0, $this->resolvers_built, 'A refused request must not build a resolver.' );

		// The counter has to be shown to work: a binding that was never reached and a binding that
		// cannot be resolved at all produce the same zero.
		$this->resolve( Resolver_Interface::class );

		$this->assertSame( 1, $this->resolvers_built, 'The container really does build through the factory.' );
	}

	/**
	 * The probe is what keeps the capability check off the requests that have nothing to act on —
	 * `current_user_can()` caches the current user, and this step runs before the plugins that decide
	 * who that is have hooked `determine_current_user`.
	 */
	public function test_a_request_with_no_conflict_never_asks_about_the_user(): void {
		$this->bind_resolver_double();

		Absorber::boot();

		$this->bind_gate_and_probe_doubles( true, false );

		do_action( 'plugins_loaded' );

		$this->assertSame( [ 'request_may_resolve', 'has_conflict' ], $this->conflict_calls );
	}

	/**
	 * And on that request the resolver is never built at all. Detection moving off
	 * `Resolver_Interface` and onto `Conflict\Detector` is what makes this assertable: while the probe
	 * was a method on the resolver, asking it meant constructing one — and everything it depends on —
	 * on every admin GET a site served, whatever the answer turned out to be.
	 */
	public function test_a_request_with_no_conflict_builds_no_resolver(): void {
		$this->bind_resolver_double();

		Absorber::boot();

		$this->bind_gate_and_probe_doubles( true, false );

		do_action( 'plugins_loaded' );

		$this->assertSame( 0, $this->resolvers_built, 'Nothing to resolve must mean nothing built to resolve it.' );

		// The counter has to be shown to work: a binding that was never reached and a binding that
		// cannot be resolved at all produce the same zero.
		$this->resolve( Resolver_Interface::class );

		$this->assertSame( 1, $this->resolvers_built, 'The container really does build through the factory.' );
	}

	/**
	 * The capability gate still stands between a real conflict and acting on it. It lives here rather
	 * than inside the resolver, so a host that binds its own `Resolver_Interface` decides what a
	 * conflict means and not who may have one resolved.
	 */
	public function test_a_user_the_capability_gate_refuses_does_not_reach_resolve_all(): void {
		$this->bind_resolver_double();

		Absorber::boot();

		$this->bind_gate_and_probe_doubles( true, true, false );

		do_action( 'plugins_loaded' );

		$this->assertSame( [ 'request_may_resolve', 'has_conflict', 'user_may_resolve' ], $this->conflict_calls );
	}

	/**
	 * The real gate, on the request it exists to turn away. The capability check covers the policies
	 * that only queue a notice as well as the destructive one, and nothing is lost by that — the
	 * standalone is still there to detect once someone who can act on it arrives, which is what the
	 * second half asserts.
	 */
	public function test_a_user_who_cannot_activate_plugins_has_nothing_resolved_or_queued(): void {
		set_current_screen( 'dashboard' );

		$this->bind_active_standalone();
		$this->register_conflicted_sub_plugin();

		wp_set_current_user( $this->create_user( 'subscriber' ) );

		Absorber::boot();

		do_action( 'plugins_loaded' );

		$this->assertSame( [], $this->queued_notices(), 'A user who could never read the notice must not consume it.' );

		$this->become_plugin_administrator();

		do_action( 'plugins_loaded' );

		$this->assertArrayHasKey(
			'give-recurring:conflict',
			$this->queued_notices(),
			'The conflict has to still be detectable once someone who can act on it arrives.'
		);
	}

	/**
	 * Reading the registry flushes the registration buffer, and the registrar refuses a slug it
	 * already holds. The conflict step reads a priority ahead of the load pass, so it — not the load
	 * pass that has always guarded this — is the first pass a duplicate slug reaches, and a throw
	 * here arrives inside plugins_loaded, where it takes wp-admin down and locks the developer out
	 * of the screen where the second registration could be undone.
	 *
	 * The front end never reaches it, because the request gate turns away first. That is what makes
	 * this the worse failure rather than a lesser one: the only requests that fatal are the ones the
	 * mistake could have been corrected from.
	 */
	public function test_a_duplicate_slug_is_reported_rather_than_fataling_the_conflict_step(): void {
		set_current_screen( 'dashboard' );

		$this->bind_active_standalone();
		$this->register_conflicted_sub_plugin();
		$this->register_conflicted_sub_plugin();

		$this->become_plugin_administrator();

		Absorber::boot();

		$this->expect_incorrect_usage();

		do_action( 'plugins_loaded' );

		// Reaching this line at all is half of what is under test: the step has to return.
		$this->assertSame(
			[],
			$this->queued_notices(),
			'A read that failed has no list to resolve from, so nothing may be resolved.'
		);
		$this->assert_the_library_reported_incorrect_usage();
	}

	public function test_it_wires_the_load_step_at_the_load_priority(): void {
		$this->register_sub_plugin();

		$before = $this->callbacks_at( 'plugins_loaded', self::load_priority() );

		Absorber::boot();

		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', self::load_priority() ),
			'boot() must wire the load step rather than run it.'
		);
		$this->assertSame( 0, $this->bundled_plugin_loads(), 'Wiring must not load anything yet.' );

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	public function test_booting_twice_wires_the_load_step_only_once(): void {
		$this->register_sub_plugin();

		$before = $this->callbacks_at( 'plugins_loaded', self::load_priority() );

		Absorber::boot();
		Absorber::boot();

		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', self::load_priority() ),
			'boot() must be idempotent.'
		);

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	/**
	 * The reason the load step runs at all early: a plugin hooking plugins_loaded at the default
	 * priority — which is nearly all of them — has to find the bundled code already in memory.
	 */
	public function test_a_plugin_hooking_at_the_default_priority_finds_the_bundled_code(): void {
		$this->register_sub_plugin();

		Absorber::boot();

		$loads_seen = null;
		$this->add_tracked_action(
			'plugins_loaded',
			static function () use ( &$loads_seen ): void {
				$loads_seen = $GLOBALS['absorber_loads'] ?? 0;
			}
		);

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $loads_seen );
	}

	public function test_it_wires_the_notice_step_in_the_admin(): void {
		set_current_screen( 'dashboard' );

		Absorber::boot();

		$presenter = $this->bind_spy_presenter();

		do_action( 'all_admin_notices' );

		$this->assertSame( 1, $presenter->render_calls );
	}

	/**
	 * Nothing renders a notice on the front end, and the queue must survive until an admin load
	 * consumes it — rendering is what clears it.
	 */
	public function test_it_does_not_wire_the_notice_step_on_the_front_end(): void {
		set_current_screen( 'front' );

		Absorber::boot();

		$presenter = $this->bind_spy_presenter();

		// The recorder has to be shown to work: without it, a do_action() that fired nothing at all
		// would satisfy the assertion below for a reason that has nothing to do with the front end.
		$fired = false;
		$this->add_tracked_action(
			'all_admin_notices',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		do_action( 'all_admin_notices' );

		$this->assertTrue( $fired, 'The hook must really have been dispatched.' );
		$this->assertSame( 0, $presenter->render_calls );
	}

	/**
	 * Adding an action at a priority the running dispatch has already passed is accepted and then
	 * never fires. Booting from plugins_loaded at the default priority instead of 0 would otherwise
	 * load nothing at all, on a site that looks completely healthy.
	 *
	 * The window is measured from the earliest step in the sequence, so the resolve priority is the
	 * boundary case rather than the load priority: a callback added to the priority currently being
	 * dispatched is never reached either, because the dispatch loop walks a by-value copy of that
	 * priority's callback array. Booting between the two steps still reports, and still loads.
	 *
	 * @dataProvider late_boot_priorities
	 *
	 * @param int $offset How far past the load priority the host boots from; negative for the window
	 *                    between conflict resolution and the load pass.
	 */
	public function test_booting_too_late_in_plugins_loaded_loads_inline_instead( int $offset ): void {
		$this->expect_incorrect_usage();

		$constant = $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );

		$this->add_tracked_action(
			'plugins_loaded',
			static function () use ( $path, $constant ): void {
				Absorber::register(
					[
						'slug'                   => 'give-recurring',
						'bundled_plugin_file'    => $path,
						'plugin_loaded_constant' => $constant,
					]
				);

				Absorber::boot();
			},
			self::load_priority() + $offset
		);

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads(), 'A late boot must still load.' );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * @return Generator<string,array{0:int}>
	 */
	public static function late_boot_priorities(): Generator {
		yield 'at the resolve priority'  => [ -1 ];
		yield 'at the load priority'     => [ 0 ];
		yield 'one past it'              => [ 1 ];
		// Priority 10, where an add_action() naming no priority at all lands — the commonest way to
		// boot too late. Derived from the load priority rather than written as an offset, which
		// would go on describing this case while quietly testing a different one every time the
		// load priority moved.
		yield 'the default a host omits' => [ 10 - self::load_priority() ];
	}

	/**
	 * The other side of the same boundary: every priority the window is still open at must *wire* the
	 * sequence rather than run it.
	 *
	 * That the bundled plugin loaded proves nothing on its own — the inline fallback loads it too,
	 * which is the whole point of having one. The callback count at the load priority is what
	 * separates the two: wiring leaves a callback behind, and running inline never adds one.
	 *
	 * The band below the resolve priority is the whole reason it is not 1. A host has somewhere to
	 * stand other than priority 0, so the documented convention stays a convention: move resolution
	 * back down and the cases below fail instead of quietly taking the inline path.
	 *
	 * @dataProvider boot_priorities_that_still_wire
	 *
	 * @param int $priority plugins_loaded priority the host boots from.
	 */
	public function test_booting_before_the_resolve_priority_still_wires( int $priority ): void {
		$constant = $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );
		$before   = $this->callbacks_at( 'plugins_loaded', self::load_priority() );

		$this->add_tracked_action(
			'plugins_loaded',
			static function () use ( $path, $constant ): void {
				Absorber::register(
					[
						'slug'                   => 'give-recurring',
						'bundled_plugin_file'    => $path,
						'plugin_loaded_constant' => $constant,
					]
				);

				Absorber::boot();
			},
			$priority
		);

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads(), 'The bundled plugin has to load either way.' );
		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', self::load_priority() ),
			'Booting inside the window has to wire the load step, not run it inline.'
		);
	}

	/**
	 * @return Generator<string,array{0:int}>
	 */
	public static function boot_priorities_that_still_wire(): Generator {
		yield 'at the start, as documented'      => [ 0 ];
		yield 'the habit a host arrives with'    => [ 1 ];
		yield 'the last slot before the barrier' => [ 4 ];
	}

	public function test_booting_after_plugins_loaded_has_finished_loads_inline(): void {
		$this->expect_incorrect_usage();

		do_action( 'plugins_loaded' );

		$this->register_sub_plugin();
		Absorber::boot();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The suite's state helper stands in for the reset() the Absorber deliberately does not ship.
	 * Clearing the boot flag without unwiring would leave an Absorber that reports itself unbooted while
	 * its callbacks are still attached — and every later test would load sub-plugins it never
	 * registered.
	 */
	public function test_the_state_helper_unwires_the_hooks_boot_added(): void {
		set_current_screen( 'dashboard' );

		$load_step   = $this->callbacks_at( 'plugins_loaded', self::load_priority() );
		$notice_step = $this->callbacks_at( 'all_admin_notices' );

		Absorber::boot();
		Absorber_State::reset();

		$this->assertSame( $load_step, $this->callbacks_at( 'plugins_loaded', self::load_priority() ) );
		$this->assertSame( $notice_step, $this->callbacks_at( 'all_admin_notices' ) );
	}

	/**
	 * The hooks are gone by the time this boots again, so the assertion is about the second boot
	 * rather than a leftover from the first.
	 */
	public function test_the_state_helper_allows_booting_again(): void {
		$before = $this->callbacks_at( 'plugins_loaded', self::load_priority() );

		Absorber::boot();
		Absorber_State::reset();

		Absorber::boot();

		$this->assertSame( $before + 1, $this->callbacks_at( 'plugins_loaded', self::load_priority() ) );
	}

	/**
	 * boot() runs before anything reads the prefix, so it must not be the thing that throws when a
	 * host forgot to set one — the load path reports that at a point the host can see.
	 */
	public function test_boot_does_not_need_a_hook_prefix(): void {
		$container = $this->container();

		Config_State::reset();
		Config::set_container( $container );

		$before = $this->callbacks_at( 'plugins_loaded', self::load_priority() );

		Absorber::boot();

		$this->assertSame( $before + 1, $this->callbacks_at( 'plugins_loaded', self::load_priority() ) );
	}

	/**
	 * The priority the load step is wired at, read from the scheduler rather than restated.
	 *
	 * @throws LogicException When the constant is missing or not an int, rather than counting
	 *                        callbacks at priority zero and passing for the wrong reason.
	 *
	 * @return int
	 */
	private static function load_priority(): int {
		$priority = ( new ReflectionClass( Scheduler::class ) )->getConstant( 'LOAD_PRIORITY' );

		if ( ! is_int( $priority ) ) {
			throw new LogicException( 'Boot\Scheduler::LOAD_PRIORITY must be an int.' );
		}

		return $priority;
	}

	/**
	 * The priority the conflict step is wired at, read from the scheduler rather than restated.
	 *
	 * @throws LogicException When the constant is missing or not an int, rather than counting
	 *                        callbacks at priority zero and passing for the wrong reason.
	 *
	 * @return int
	 */
	private static function resolve_priority(): int {
		$priority = ( new ReflectionClass( Scheduler::class ) )->getConstant( 'RESOLVE_PRIORITY' );

		if ( ! is_int( $priority ) ) {
			throw new LogicException( 'Boot\Scheduler::RESOLVE_PRIORITY must be an int.' );
		}

		return $priority;
	}

	/**
	 * How many callbacks are on a hook, at one priority or in total.
	 *
	 * @param string   $hook     Hook to count.
	 * @param int|null $priority Priority to count at, or null for every priority.
	 *
	 * @return int
	 */
	private function callbacks_at( string $hook, ?int $priority = null ): int {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;

		if ( ! $wp_hook instanceof WP_Hook ) {
			return 0;
		}

		$total = 0;

		foreach ( $wp_hook->callbacks as $registered_priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}

			if ( $priority === null || $registered_priority === $priority ) {
				$total += count( $callbacks );
			}
		}

		return $total;
	}

	/**
	 * Bind a recording presenter in place of the default one, into the container already standing.
	 *
	 * Called *after* boot, unlike a host rebinding the writer's interface seam would be: the id is a
	 * class, and a container answers for every class that exists whether or not anything was bound to
	 * it, so the provider cannot tell a double apart from that and rebinds regardless. Late is safe
	 * here — the notice step is a named static trampoline that resolves its collaborator when it
	 * fires, not when it is wired.
	 *
	 * @return Spy_Presenter
	 */
	private function bind_spy_presenter(): Spy_Presenter {
		$presenter = new Spy_Presenter();

		$this->container()->singleton(
			Presenter::class,
			static function () use ( $presenter ): Presenter {
				return $presenter;
			}
		);

		return $presenter;
	}

	/**
	 * Bind a resolver that logs `resolve_all` into `$conflict_calls` and counts every build.
	 *
	 * Call before `Absorber::boot()`. `Resolver_Interface` is an interface id, and the provider stands
	 * down for an id the container can already answer for — which, for an interface, it can only do
	 * because something bound one. That is the seam a host is invited to replace, and replacing it
	 * deliberately does not depend on knowing when the library boots.
	 *
	 * Nothing is returned: what the assertions read is the log and the build counter, so a step that
	 * skipped a double is as visible as one that reached it, and neither reading depends on a
	 * container's untyped return being narrowed back to a double's own class.
	 *
	 * @return void
	 */
	private function bind_resolver_double(): void {
		$calls  = &$this->conflict_calls;
		$builds = &$this->resolvers_built;

		$record = static function ( string $call ) use ( &$calls ): void {
			$calls[] = $call;
		};

		$container = new Test_Container();
		$container->singleton(
			Resolver_Interface::class,
			static function () use ( $record, &$builds ): Resolver_Interface {
				++$builds;

				return new class( $record ) implements Resolver_Interface {
					/**
					 * @var callable
					 */
					private $record;

					/**
					 * @param callable $record Logs the call.
					 */
					public function __construct( callable $record ) {
						$this->record = $record;
					}

					/**
					 * @return void
					 */
					public function resolve_all(): void {
						( $this->record )( 'resolve_all' );
					}
				};
			}
		);

		$this->set_up_container( $container );
	}

	/**
	 * Bind a gatekeeper and a detector with fixed answers, both logging into `$conflict_calls`.
	 *
	 * Call *after* `Absorber::boot()`, and before the hook fires. These are class ids, and a container
	 * reports that it can answer for any class that exists whether or not anything was bound to it —
	 * so the provider cannot tell a double apart from the container's own willingness to build the
	 * real class, and rebinds regardless. Binding them alongside the resolver would leave them
	 * overwritten twice over: once by the provider run inside `set_up_container()`, and again by the
	 * one `boot()` performs itself. The step would then reach the real `Gatekeeper` and `Detector`,
	 * the log would come back empty, and the failure would read as a step that never ran.
	 *
	 * After boot is early enough because nothing resolves until `plugins_loaded` fires: the scheduler
	 * wires closures that ask the container when the hook runs, which is the same window a host has
	 * for rebinding one of the concrete workers.
	 *
	 * @param bool $request_may_resolve Whether the shape of the request admits resolution.
	 * @param bool $has_conflict        Whether the detector reports anything to resolve.
	 * @param bool $user_may_resolve    Whether the current user may have it resolved.
	 *
	 * @return void
	 */
	private function bind_gate_and_probe_doubles(
		bool $request_may_resolve = true,
		bool $has_conflict = true,
		bool $user_may_resolve = true
	): void {
		$calls = &$this->conflict_calls;

		$record = static function ( string $call ) use ( &$calls ): void {
			$calls[] = $call;
		};

		$container = $this->container();
		$container->singleton(
			Gatekeeper::class,
			static function () use ( $record, $request_may_resolve, $user_may_resolve ): Gatekeeper {
				return new class( $record, $request_may_resolve, $user_may_resolve ) extends Gatekeeper {
					/**
					 * @var callable
					 */
					private $record;

					/**
					 * @var bool
					 */
					private $request_answer;

					/**
					 * @var bool
					 */
					private $user_answer;

					/**
					 * @param callable $record         Logs the call.
					 * @param bool     $request_answer Answer for the request gate.
					 * @param bool     $user_answer    Answer for the capability gate.
					 */
					public function __construct( callable $record, bool $request_answer, bool $user_answer ) {
						$this->record         = $record;
						$this->request_answer = $request_answer;
						$this->user_answer    = $user_answer;
					}

					/**
					 * @return bool
					 */
					public function request_may_resolve(): bool {
						( $this->record )( 'request_may_resolve' );

						return $this->request_answer;
					}

					/**
					 * @return bool
					 */
					public function user_may_resolve(): bool {
						( $this->record )( 'user_may_resolve' );

						return $this->user_answer;
					}
				};
			}
		);
		$container->singleton(
			Detector::class,
			static function () use ( $record, $has_conflict ): Detector {
				return new class( $record, $has_conflict ) extends Detector {
					/**
					 * @var callable
					 */
					private $record;

					/**
					 * @var bool
					 */
					private $answer;

					/**
					 * No plugin checker, and no parent constructor call: the answer is stated here, and
					 * how a real detector arrives at one is DetectorTest's subject.
					 *
					 * @param callable $record Logs the call.
					 * @param bool     $answer Whether there is a conflict to resolve.
					 */
					public function __construct( callable $record, bool $answer ) {
						$this->record = $record;
						$this->answer = $answer;
					}

					/**
					 * @return bool
					 */
					public function has_conflict(): bool {
						( $this->record )( 'has_conflict' );

						return $this->answer;
					}
				};
			}
		);
	}

	/**
	 * Report every standalone as active, without reaching WordPress for the answer.
	 *
	 * @return void
	 */
	private function bind_active_standalone(): void {
		$container = new Test_Container();
		$container->singleton(
			Plugin_Checker_Interface::class,
			static function (): Plugin_Checker_Interface {
				return new class() implements Plugin_Checker_Interface {
					/**
					 * @param string $basename Plugin basename.
					 *
					 * @return bool
					 */
					public function is_active( string $basename ): bool {
						return true;
					}
				};
			}
		);

		$this->set_up_container( $container );
	}

	/**
	 * Register a sub-plugin whose standalone is in conflict, under the policy that only talks.
	 *
	 * @return void
	 */
	private function register_conflicted_sub_plugin(): void {
		$constant = $this->make_guard_constant();

		Absorber::register(
			[
				'slug'                       => 'give-recurring',
				'bundled_plugin_file'        => $this->make_bundled_plugin_file( $constant ),
				'plugin_loaded_constant'     => $constant,
				'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
				'conflict_policy'            => Conflict_Policy::NOTICE_ONLY,
			]
		);
	}

	/**
	 * Register a sub-plugin whose bundled file records that it was loaded.
	 *
	 * @return void
	 */
	private function register_sub_plugin(): void {
		$constant = $this->make_guard_constant();

		Absorber::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $this->make_bundled_plugin_file( $constant ),
				'plugin_loaded_constant' => $constant,
			]
		);
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
		$this->added_actions[] = [ $hook, $callback, $priority ];

		add_action( $hook, $callback, $priority );
	}
}
