<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;

/**
 * @since 1.0.0
 */
class LoaderBootTest extends WPTestCase {
	/**
	 * Global a bundled fixture sets when it is required.
	 *
	 * @var string
	 */
	private const LOADED = 'absorber_boot_load';

	/**
	 * @var int
	 */
	private $plugins_loaded_count = 0;

	/**
	 * Hook callbacks these tests added, as [ hook, callback, priority ] triples.
	 *
	 * Tracked so tearDown can take back exactly what a test put there. remove_all_actions() strips
	 * the hook bare instead, discarding every callback WordPress and the rest of the suite have on
	 * it for the remainder of the process.
	 *
	 * @var array<int,array{0:string,1:callable,2:int}>
	 */
	private $added_actions = [];

	/**
	 * @var string[]
	 */
	private $fixtures = [];

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );

		// The harness has to boot WordPress before it can run anything, so plugins_loaded has
		// already fired by the time any test starts — and boot() would rightly report that it is
		// too late to wire a hook at priority 2. Rewind the counter so the wiring tests see the
		// timing a real host bootstrap sees. The tests that exercise a late boot set it back.
		$this->plugins_loaded_count = did_action( 'plugins_loaded' );
		unset( $GLOBALS['wp_actions']['plugins_loaded'] );

		$GLOBALS[ self::LOADED ] = false;
	}

	public function tearDown(): void {
		$GLOBALS['wp_actions']['plugins_loaded'] = $this->plugins_loaded_count;

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// leak an admin screen into every test that runs after it, since is_admin() checks the
		// current screen before WP_ADMIN.
		set_current_screen( 'front' );

		// Only what these tests added by hand. The two hooks boot() wires come off in
		// Loader_State::reset(), which reads the load priority from the Loader rather than
		// restating it.
		foreach ( $this->added_actions as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->added_actions = [];

		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) ) {
				unlink( $fixture );
			}
		}
		$this->fixtures = [];

		unset( $GLOBALS[ self::LOADED ] );
		Loader_State::reset();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_wires_the_load_hook_at_priority_two(): void {
		Loader::boot();

		$this->assertSame( 2, has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] ) );
	}

	public function test_booting_twice_wires_the_hook_only_once(): void {
		Loader::boot();
		Loader::boot();

		$callbacks = $GLOBALS['wp_filter']['plugins_loaded']->callbacks[2] ?? [];

		$this->assertCount( 1, $callbacks, 'boot() must be idempotent.' );
	}

	public function test_it_wires_the_admin_notices_hook_in_the_admin(): void {
		set_current_screen( 'dashboard' );

		Loader::boot();

		$this->assertNotFalse( has_action( 'all_admin_notices', [ Loader::class, 'render_notices' ] ) );
	}

	/**
	 * Nothing renders a notice on the front end, and the queue must survive until an admin load
	 * consumes it.
	 */
	public function test_it_does_not_wire_the_notice_hook_on_the_front_end(): void {
		set_current_screen( 'front' );

		Loader::boot();

		$this->assertFalse( has_action( 'all_admin_notices', [ Loader::class, 'render_notices' ] ) );
	}

	/**
	 * Adding an action at a priority the running dispatch has already passed is accepted and then
	 * never fires. Booting from plugins_loaded at the default priority instead of 0 would
	 * otherwise load nothing at all, on a site that looks completely healthy.
	 *
	 * Priority 2 is the boundary case: a callback added to the priority currently being dispatched
	 * is never reached either, because the dispatch loop walks a by-value copy of that priority's
	 * callback array.
	 *
	 * @dataProvider late_boot_priorities
	 *
	 * @param int $priority plugins_loaded priority the host boots from.
	 */
	public function test_booting_too_late_in_plugins_loaded_loads_inline_instead( int $priority ): void {
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader::boot' );

		$path = $this->make_fixture();

		$this->add_tracked_action(
			'plugins_loaded',
			static function () use ( $path, $priority ) {
				Loader::register(
					[
						'slug'                   => 'give-recurring',
						'bundled_plugin_file'    => $path,
						'plugin_loaded_constant' => 'ABSORBER_LATE_BOOT_GUARD_' . $priority,
					]
				);

				Loader::boot();
			},
			$priority
		);

		do_action( 'plugins_loaded' );

		$this->assertTrue( $GLOBALS[ self::LOADED ], 'A late boot must still load.' );
	}

	/**
	 * @return Generator<string,array{0:int}>
	 */
	public static function late_boot_priorities(): Generator {
		yield 'at the load priority'     => [ 2 ];
		yield 'one past it'              => [ 3 ];
		yield 'the default a host omits' => [ 10 ];
	}

	public function test_booting_after_plugins_loaded_has_finished_loads_inline(): void {
		$this->setExpectedIncorrectUsage( 'Nexcess\PluginAbsorber\Loader::boot' );

		$path = $this->make_fixture();

		do_action( 'plugins_loaded' );

		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => $path,
				'plugin_loaded_constant' => 'ABSORBER_AFTER_BOOT_GUARD',
			]
		);
		Loader::boot();

		$this->assertTrue( $GLOBALS[ self::LOADED ] );
	}

	/**
	 * The suite's state helper stands in for the reset() the Loader deliberately does not ship.
	 * Clearing the boot flag without unwiring would leave a Loader that reports itself unbooted
	 * while its callbacks are still attached — and every later test would load sub-plugins it
	 * never registered.
	 */
	public function test_the_state_helper_unwires_the_hooks_boot_added(): void {
		set_current_screen( 'dashboard' );

		Loader::boot();
		Loader_State::reset();

		$this->assertFalse( has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] ) );
		$this->assertFalse( has_action( 'all_admin_notices', [ Loader::class, 'render_notices' ] ) );
	}

	/**
	 * The hooks are gone by the time this boots again, so the assertion is about the second boot
	 * rather than a leftover from the first.
	 */
	public function test_the_state_helper_allows_booting_again(): void {
		Loader::boot();
		Loader_State::reset();

		Loader::boot();

		$this->assertSame( 2, has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] ) );
	}

	/**
	 * boot() runs before anything reads the prefix, so it must not be the thing that throws when
	 * a host forgot to set one — the load path reports that at a point the host can see.
	 */
	public function test_boot_does_not_need_a_hook_prefix(): void {
		Config_State::reset();

		Loader::boot();

		$this->assertSame( 2, has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] ) );
	}

	/**
	 * Write a throwaway bundled plugin that records its own load.
	 *
	 * A unique path per test is required: require_once caches by resolved path for the lifetime of
	 * the PHP process, so a shared fixture would let a later test pass without loading anything.
	 *
	 * @return string
	 */
	private function make_fixture(): string {
		$path = sys_get_temp_dir() . '/absorber-boot-' . uniqid( '', true ) . '.php';

		file_put_contents(
			$path,
			'<?php' . PHP_EOL . '$GLOBALS["' . self::LOADED . '"] = true;' . PHP_EOL
		);

		$this->fixtures[] = $path;

		return $path;
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
