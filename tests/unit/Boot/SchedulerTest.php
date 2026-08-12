<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Boot;

use Codeception\TestCase\WPTestCase;
use Generator;
use LogicException;
use Nexcess\PluginAbsorber\Boot\Scheduler;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Gatekeeper;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Queue;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Resolver;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;
use ReflectionClass;
use WP_Hook;

/**
 * When the library's steps run, and what happens when a host asks too late.
 *
 * Driven through `Loader::boot()` rather than by calling the scheduler directly. That is the only
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
	use WithUsers;

	/**
	 * @var int
	 */
	private $plugins_loaded_count = 0;

	/**
	 * @var string|null
	 */
	private $request_method;

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

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->reset_bundled_plugin_loads();
		$this->clear_notices();

		// The conflict step reads the request method, so the one test that lets the real gatekeeper
		// answer depends on it rather than on whatever the harness happened to leave behind.
		$this->request_method      = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// The harness has to boot WordPress before it can run anything, so plugins_loaded has already
		// fired by the time any test starts — and boot() would rightly report that it is too late to
		// wire a hook at the load priority. Rewind the counter so the wiring tests see the timing a
		// real host bootstrap sees. The tests that exercise a late boot set it back.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );
	}

	public function tearDown(): void {
		$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;

		if ( $this->request_method === null ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// leak an admin screen into every test that runs after it, since is_admin() checks the
		// current screen before WP_ADMIN.
		set_current_screen( 'front' );

		// Only what these tests added by hand. What boot() wired comes off in Loader_State::reset().
		foreach ( $this->added_actions as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->added_actions = [];

		$this->stop_expecting_incorrect_usage();
		$this->remove_bundled_plugin_files();
		$this->clear_notices();
		Loader_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	/**
	 * Ahead of the default priority, so a bundled plugin is in memory before the plugins that expect
	 * it start their own work, and low enough to leave room for earlier wiring — conflict resolution
	 * runs at 1. The number is documented, so it is part of the contract rather than an internal.
	 */
	public function test_the_load_step_runs_early_in_plugins_loaded(): void {
		$this->assertSame( 2, $this->load_priority() );
	}

	/**
	 * A standalone that survives the conflict defines its guard constant as it loads, and the load
	 * pass has to see that — so resolution runs first and cannot share a priority with it.
	 */
	public function test_the_conflict_step_runs_before_the_load_step(): void {
		$this->assertSame( 1, $this->resolve_priority() );
		$this->assertLessThan( $this->load_priority(), $this->resolve_priority() );
	}

	public function test_it_wires_the_conflict_step_at_the_resolve_priority(): void {
		[ 'resolver' => $resolver ] = $this->bind_conflict_doubles( true );

		$before = $this->callbacks_at( 'plugins_loaded', $this->resolve_priority() );

		Loader::boot();

		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', $this->resolve_priority() ),
			'boot() must wire the conflict step rather than run it.'
		);
		$this->assertSame( 0, $resolver->resolve_calls, 'Wiring must not resolve anything yet.' );

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $resolver->resolve_calls );
	}

	/**
	 * The gate is asked first and the resolver is built only once it has said yes. That order is the
	 * whole guarantee: a host binding its own `Resolver_Interface` decides what a conflict means, not
	 * who is allowed to have one resolved, and a replacement that forgot a gate cannot reopen it
	 * because it is never reached.
	 */
	public function test_the_conflict_step_asks_the_gatekeeper_before_resolving(): void {
		[ 'gatekeeper' => $gatekeeper, 'resolver' => $resolver ] = $this->bind_conflict_doubles( false );

		Loader::boot();

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $gatekeeper->may_resolve_calls, 'The step has to ask the gate.' );
		$this->assertSame( 0, $resolver->resolve_calls, 'A refused request must not reach a resolver at all.' );
	}

	public function test_the_conflict_step_resolves_once_the_gatekeeper_admits_the_request(): void {
		[ 'gatekeeper' => $gatekeeper, 'resolver' => $resolver ] = $this->bind_conflict_doubles( true );

		Loader::boot();

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $gatekeeper->may_resolve_calls );
		$this->assertSame( 1, $resolver->resolve_calls );
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

		Loader::boot();

		do_action( 'plugins_loaded' );

		$this->assertSame( [], $this->notice_queue(), 'A user who could never read the notice must not consume it.' );

		$this->become_plugin_administrator();

		do_action( 'plugins_loaded' );

		$this->assertArrayHasKey(
			'give-recurring:conflict',
			$this->notice_queue(),
			'The conflict has to still be detectable once someone who can act on it arrives.'
		);
	}

	public function test_it_wires_the_load_step_at_the_load_priority(): void {
		$this->register_sub_plugin();

		$before = $this->callbacks_at( 'plugins_loaded', $this->load_priority() );

		Loader::boot();

		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', $this->load_priority() ),
			'boot() must wire the load step rather than run it.'
		);
		$this->assertSame( 0, $this->bundled_plugin_loads(), 'Wiring must not load anything yet.' );

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	public function test_booting_twice_wires_the_load_step_only_once(): void {
		$this->register_sub_plugin();

		$before = $this->callbacks_at( 'plugins_loaded', $this->load_priority() );

		Loader::boot();
		Loader::boot();

		$this->assertSame(
			$before + 1,
			$this->callbacks_at( 'plugins_loaded', $this->load_priority() ),
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

		Loader::boot();

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

		$notices = $this->bind_spy_queue();

		Loader::boot();

		do_action( 'all_admin_notices' );

		$this->assertSame( 1, $notices->render_calls );
	}

	/**
	 * Nothing renders a notice on the front end, and the queue must survive until an admin load
	 * consumes it — rendering is what clears it.
	 */
	public function test_it_does_not_wire_the_notice_step_on_the_front_end(): void {
		set_current_screen( 'front' );

		$notices = $this->bind_spy_queue();

		Loader::boot();

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
		$this->assertSame( 0, $notices->render_calls );
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
				Loader::register(
					[
						'slug'                   => 'give-recurring',
						'bundled_plugin_file'    => $path,
						'plugin_loaded_constant' => $constant,
					]
				);

				Loader::boot();
			},
			$this->load_priority() + $offset
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
		yield 'the default a host omits' => [ 8 ];
	}

	/**
	 * The other side of the same boundary. The window closes at the first step rather than the last,
	 * so a host booting at priority 0 — which is what the README tells it to do — must still wire both
	 * steps and be reported on for nothing.
	 */
	public function test_booting_at_the_start_of_plugins_loaded_still_wires(): void {
		$constant = $this->make_guard_constant();
		$path     = $this->make_bundled_plugin_file( $constant );

		$this->add_tracked_action(
			'plugins_loaded',
			static function () use ( $path, $constant ): void {
				Loader::register(
					[
						'slug'                   => 'give-recurring',
						'bundled_plugin_file'    => $path,
						'plugin_loaded_constant' => $constant,
					]
				);

				Loader::boot();
			},
			0
		);

		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->bundled_plugin_loads() );
	}

	public function test_booting_after_plugins_loaded_has_finished_loads_inline(): void {
		$this->expect_incorrect_usage();

		do_action( 'plugins_loaded' );

		$this->register_sub_plugin();
		Loader::boot();

		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The suite's state helper stands in for the reset() the Loader deliberately does not ship.
	 * Clearing the boot flag without unwiring would leave a Loader that reports itself unbooted while
	 * its callbacks are still attached — and every later test would load sub-plugins it never
	 * registered.
	 */
	public function test_the_state_helper_unwires_the_hooks_boot_added(): void {
		set_current_screen( 'dashboard' );

		$load_step   = $this->callbacks_at( 'plugins_loaded', $this->load_priority() );
		$notice_step = $this->callbacks_at( 'all_admin_notices' );

		Loader::boot();
		Loader_State::reset();

		$this->assertSame( $load_step, $this->callbacks_at( 'plugins_loaded', $this->load_priority() ) );
		$this->assertSame( $notice_step, $this->callbacks_at( 'all_admin_notices' ) );
	}

	/**
	 * The hooks are gone by the time this boots again, so the assertion is about the second boot
	 * rather than a leftover from the first.
	 */
	public function test_the_state_helper_allows_booting_again(): void {
		$before = $this->callbacks_at( 'plugins_loaded', $this->load_priority() );

		Loader::boot();
		Loader_State::reset();

		Loader::boot();

		$this->assertSame( $before + 1, $this->callbacks_at( 'plugins_loaded', $this->load_priority() ) );
	}

	/**
	 * boot() runs before anything reads the prefix, so it must not be the thing that throws when a
	 * host forgot to set one — the load path reports that at a point the host can see.
	 */
	public function test_boot_does_not_need_a_hook_prefix(): void {
		$container = $this->container();

		Config_State::reset();
		Config::set_container( $container );

		$before = $this->callbacks_at( 'plugins_loaded', $this->load_priority() );

		Loader::boot();

		$this->assertSame( $before + 1, $this->callbacks_at( 'plugins_loaded', $this->load_priority() ) );
	}

	/**
	 * The priority the load step is wired at, read from the scheduler rather than restated.
	 *
	 * @throws LogicException When the constant is missing or not an int, rather than counting
	 *                        callbacks at priority zero and passing for the wrong reason.
	 *
	 * @return int
	 */
	private function load_priority(): int {
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
	private function resolve_priority(): int {
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
	 * Bind a recording queue in place of the default one.
	 *
	 * Bound before the provider runs, which is the only order that leaves it bound.
	 *
	 * @return Spy_Queue
	 */
	private function bind_spy_queue(): Spy_Queue {
		$notices   = new Spy_Queue();
		$container = new Test_Container();
		$container->singleton(
			Queue_Interface::class,
			static function () use ( $notices ): Spy_Queue {
				return $notices;
			}
		);

		$this->set_up_container( $container );

		return $notices;
	}

	/**
	 * Bind a gatekeeper with a fixed answer and a resolver that records being reached.
	 *
	 * Both bound before the provider runs, which is the only order that leaves them bound. The pair is
	 * returned rather than resolved back out of the container, so the assertions read a spy's own type
	 * — `Resolver_Interface` declares no counter, and nothing here narrows a container's return.
	 *
	 * @param bool $may_resolve The answer the gate always gives.
	 *
	 * @return array{gatekeeper:Spy_Gatekeeper,resolver:Spy_Resolver}
	 */
	private function bind_conflict_doubles( bool $may_resolve ): array {
		$gatekeeper = new Spy_Gatekeeper( $may_resolve );
		$resolver   = new Spy_Resolver();

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

		$this->set_up_container( $container );

		return [
			'gatekeeper' => $gatekeeper,
			'resolver'   => $resolver,
		];
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

		Loader::register(
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
	 * The queue is stored as a site option on every install — on single site that call falls through
	 * to the plain option table — so there is one place to read it from.
	 *
	 * @return array<string,string>
	 */
	private function notice_queue(): array {
		$queue = get_site_option( 'give_plugin_absorber_notices', [] );

		return is_array( $queue ) ? $queue : [];
	}

	private function clear_notices(): void {
		delete_site_option( 'give_plugin_absorber_notices' );
	}

	/**
	 * Register a sub-plugin whose bundled file records that it was loaded.
	 *
	 * @return void
	 */
	private function register_sub_plugin(): void {
		$constant = $this->make_guard_constant();

		Loader::register(
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
