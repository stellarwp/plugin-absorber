<?php
/**
 * The load pass, driven end to end.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Scenario;

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * What a request does with a bundled sub-plugin nothing is fighting over.
 *
 * Every scenario here reaches the load pass with no standalone in the way, so what is under test is
 * the gate chain `Loader::load_all()` walks — enabled, not already loaded, dependencies met, file
 * exists, `should_load`, require, activation callback — and the order it walks it in. The conflict
 * scenarios are the other half, and live beside this file.
 *
 * Each scenario registers its own bundled fixture, which is written fresh and given a guard constant
 * no other test uses. That is not tidiness: `require_once` dedupes by resolved path for the lifetime
 * of the PHP process, so a shared file would let a later scenario pass without loading anything at
 * all — including if the load logic were deleted outright.
 *
 * @since 1.0.0
 */
class LoadTest extends Bootstrap_Test_Case {
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
	 * The guard is not only about the standalone. A must-use copy, a second host plugin bundling the
	 * same code, or the site owner's own snippet all define the same constant, and any of them means
	 * the code is already in memory.
	 */
	public function test_the_bundled_copy_stands_down_when_the_guard_is_already_defined(): void {
		$constant = $this->define_guard( 'ABSORBER_SCENARIO_ALREADY_LOADED_GUARD' );

		$this->register( [], $constant );

		$this->boot();
		$this->run_request();

		$this->assertSame( 0, $this->bundled_plugin_loads() );
		$this->assertSame( [], $this->queued_notices(), 'A plugin the admin can see running has nothing to explain.' );
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
		$this->assertSame( [], $this->queued_notices(), 'A sub-plugin nobody asked for has nothing to report.' );

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
		$this->assertSame( [], $this->queued_notices(), 'A host that vetoed the load does not need telling about it.' );
	}

	/**
	 * Registration order, not slug order and not filesystem order: a host bundles plugins that depend
	 * on one another, and the order it registers them in is the only say it gets.
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
	 * A host module that registers from its own `plugins_loaded` callback, which is the shape
	 * `Registry\Reader` drains at read time rather than at boot to support.
	 *
	 * The callback goes on at the priority the conflict pass already occupies, and after `boot()` wired
	 * it — so WordPress runs it *behind* a pass that has already read the registry and emptied the
	 * buffer on its way past. A registrar asked directly, or a buffer drained once at boot, would leave
	 * this registration in a list nothing reads again and the sub-plugin would silently never load.
	 */
	public function test_a_sub_plugin_registered_after_boot_still_loads_in_the_same_request(): void {
		$first = $this->register();

		$this->boot();

		// Named up front rather than returned from the callback: the guard has to be assertable whether
		// or not the callback ran, which is the failure this scenario is about.
		$second = $this->make_guard_constant();

		$this->add_tracked_action(
			'plugins_loaded',
			function () use ( $second ): void {
				$this->register( [ 'slug' => 'absorber-fee-recovery' ], $second );
			},
			5
		);

		$this->run_request();

		$this->assertSame( 2, $this->bundled_plugin_loads(), 'A registration made mid-request still reaches the load pass.' );
		$this->assertTrue( defined( $first ) );
		$this->assertTrue( defined( $second ) );
	}

	/**
	 * Two registrations under one slug. The collision is the registrar's exception and it is raised
	 * long after both `Absorber::register()` calls returned — the buffer only reaches the registrar
	 * when something reads it, which is inside `plugins_loaded`, the hook this library exists to keep a
	 * site off the floor on. So the read is guarded at both passes: the conflict pass at priority 5
	 * reads first and reports the mistake, and the load pass behind it finds the buffer already drained
	 * and gets on with the load.
	 *
	 * What the sub-plugin registered *after* the collision does is the part worth pinning. The whole
	 * batch is registered and the collision raised afterwards, so a host with a duplicate two entries
	 * up keeps everything it registered behind it — where a throw out of the middle of the flush would
	 * have left those in no registrar and in no buffer, silently, for the rest of the process.
	 */
	public function test_a_duplicate_slug_is_reported_and_the_registration_behind_it_still_loads(): void {
		$this->expect_incorrect_usage();

		$first = $this->register();

		// The same slug, a different bundled file: the mistake a host makes when two of its own modules
		// register the sub-plugin they share.
		$duplicate = $this->register();
		$behind    = $this->register( [ 'slug' => 'absorber-fee-recovery' ] );

		$this->boot();
		$this->run_request();

		$this->assertTrue( defined( $first ), 'The first registration under the slug is the one that stands.' );
		$this->assertFalse( defined( $duplicate ), 'The second is refused rather than allowed to replace it.' );
		$this->assertTrue( defined( $behind ), 'And what was registered after the collision still loads.' );
		$this->assertSame( 2, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();

		// The screen the registration has to be corrected from still draws, and this is a developer's
		// mistake rather than something the site owner can act on — so nothing was left for them.
		$rendered = $this->render_admin_notices();

		$this->assertSame( [], $this->queued_notices() );
		$this->assertStringNotContainsString( self::SLUG, $rendered );
	}

	/**
	 * A bundled file that is not there is a broken build in the host plugin, not a misconfigured site:
	 * nobody who reads wp-admin can put the file back, so the report goes to the developer through
	 * `_doing_it_wrong()` and the notice queue stays empty.
	 *
	 * The broken sub-plugin carries a `dependency_notice_message` it must not be told through. That is
	 * the sentence a file gate folded into the dependency gate would print, and it would send the owner
	 * after a version requirement that is met, for a plugin whose file was never shipped.
	 */
	public function test_a_missing_bundled_file_is_reported_to_the_developer_and_not_to_the_owner(): void {
		$this->expect_incorrect_usage();

		$missing = $this->register(
			[
				'bundled_plugin_file'       => $this->missing_bundled_plugin_file(),
				'dependency_notice_message' => static fn() => 'GiveWP 3.0 or later is required.',
			]
		);

		// Registered behind it, because the gate has to skip one sub-plugin rather than end the pass.
		$behind = $this->register( [ 'slug' => 'absorber-fee-recovery' ] );

		$this->boot();
		$this->run_request();

		$this->assertFalse( defined( $missing ), 'There was no file to define it.' );
		$this->assertTrue( defined( $behind ), 'The sub-plugin behind the broken one still loads.' );
		$this->assertSame( 1, $this->bundled_plugin_loads() );
		$this->assert_the_library_reported_incorrect_usage();

		$this->assertSame( [], $this->queued_notices(), 'A broken build is nothing the site owner can act on.' );

		$rendered = $this->render_admin_notices();

		$this->assertStringNotContainsString( 'GiveWP 3.0 or later is required.', $rendered );
		$this->assertStringNotContainsString( self::SLUG, $rendered );
	}

	/**
	 * All the way to the screen: the load is skipped, the host's own explanation is queued, the render
	 * draws it as an error, and the render consumes the queue so the owner is told once rather than on
	 * every admin page load for ever.
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
			$this->queued_notices()
		);

		$rendered = $this->render_admin_notices();

		$this->assertStringContainsString( 'GiveWP 3.0 or later is required.', $rendered );
		$this->assertStringContainsString( 'notice-error', $rendered, 'A plugin that did not load at all is an error.' );
		$this->assertSame( [], $this->queued_notices(), 'Rendering consumes the queue.' );
	}
}
