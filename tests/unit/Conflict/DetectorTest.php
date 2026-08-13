<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Stub_Registry_Reader;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithNoticeQueue;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * Whether there is a conflict at all — asked between the two gates, of a request nobody has been
 * identified on yet.
 *
 * That position is the whole reason this is a class of its own, and it is what the assertions here
 * are about as much as the answers are: the conflict step asks it *before* `current_user_can()`, so
 * anything it changed would be a change made on behalf of an anonymous visitor, and anything
 * expensive it read would be read on every admin GET a site ever serves. Policy is the expensive
 * thing it must not read — a host's policy callable and the filter behind it can do anything at all,
 * and neither says whether a standalone is running.
 *
 * `has_conflict()` reads the registry through the reader it was built with, so most of the tests for
 * it register through the facade the default reader is behind; `is_in_conflict()` is handed the
 * sub-plugin and needs nothing but its checker, which is why the tests for it build a detector
 * directly. Both readings matter: the conflict step reaches the first, and the resolver reaches the
 * second once for every sub-plugin it walks.
 *
 * @since 1.0.0
 */
class DetectorTest extends WPTestCase {
	use UopzFunctions;
	use WithContainer;
	use WithNoticeQueue;
	use WithSubPlugins;

	/**
	 * Every basename the bound checker was asked about, in the order it was asked.
	 *
	 * An ordered log rather than a count, because the short-circuit is the behaviour: a detector
	 * that walked the whole registry would give the same answer and ask twice as much of the
	 * option store on every request.
	 *
	 * @var string[]
	 */
	private $asked = [];

	/**
	 * Every deactivate_plugins() call, as the arguments it was made with.
	 *
	 * Recorded from the function rather than from a double, because what is asserted is that the
	 * probe reaches WordPress by no route at all — and a double it was never handed cannot show
	 * that.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $deactivations = [];

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->clear_notices();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->asked         = [];
		$this->deactivations = [];

		// uopz runs a replacement with no class scope, so $this and self:: are both fatal inside this
		// closure. Bind a reference to the property instead. See tests/README.md.
		$deactivations = &$this->deactivations;

		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( $plugins, $silent = false, $network_wide = null ) use ( &$deactivations ) {
				$deactivations[] = [
					'plugins'      => $plugins,
					'silent'       => $silent,
					'network_wide' => $network_wide,
				];
			},
			true
		);
	}

	public function tearDown(): void {
		$this->clear_notices();
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	/**
	 * The one constructor argument arrives from the container, so a host that rebinds the checker —
	 * to answer from its own plugin registry, say — has the detector reached by it and does not have
	 * to know a detector exists.
	 */
	public function test_the_checker_comes_from_the_container(): void {
		$this->bind_checker( true );
		$this->register();

		$this->assertTrue( $this->detector()->has_conflict() );
		$this->assertSame( [ 'give-recurring/give-recurring.php' ], $this->asked );
	}

	/**
	 * The registry arrives as an argument like everything else, so nothing is registered here at all:
	 * the sub-plugin this probe finds is one the facade has never been told about.
	 */
	public function test_it_reads_the_registry_it_was_handed(): void {
		$detector = new Detector(
			new Stub_Registry_Reader( [ $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] ) ] ),
			$this->recording_checker( true )
		);

		$this->assertTrue( $detector->has_conflict() );
		$this->assertSame(
			[ 'give-recurring/give-recurring.php' ],
			$this->asked,
			'The sub-plugin asked about has to be the one the reader handed over.'
		);
	}

	public function test_it_finds_an_active_standalone(): void {
		$this->standalone_is( true );
		$this->register();

		$this->assertTrue( $this->detector()->has_conflict() );
	}

	public function test_it_is_false_when_the_standalone_is_gone(): void {
		$this->standalone_is( false );
		$this->register();

		$this->assertFalse( $this->detector()->has_conflict() );
	}

	public function test_it_ignores_a_disabled_sub_plugin(): void {
		$this->standalone_is( true );
		$this->register( [ 'enabled' => false ] );

		$this->assertFalse( $this->detector()->has_conflict(), 'A sub-plugin nobody is loading cannot be in conflict.' );
	}

	public function test_it_ignores_a_sub_plugin_with_no_standalone(): void {
		$this->standalone_is( true );
		Absorber::register(
			[
				'slug'                   => 'give-fee-recovery',
				'bundled_plugin_file'    => '/tmp/give-fee-recovery.php',
				'plugin_loaded_constant' => 'GIVE_FEE_RECOVERY_VERSION_FIXTURE',
			]
		);

		$this->assertFalse( $this->detector()->has_conflict() );
	}

	public function test_it_is_false_with_nothing_registered(): void {
		$this->standalone_is( true );

		$this->assertFalse( $this->detector()->has_conflict() );
	}

	/**
	 * Policy says what to do about a conflict, not whether there is one. A deferring sub-plugin whose
	 * standalone is running is exactly as much of a conflict as a deactivating one — the resolver is
	 * where the decision to leave it alone belongs, and it never gets there if the detector has
	 * already answered no.
	 *
	 * @dataProvider quiet_policies
	 *
	 * @param string $policy Policy under test.
	 */
	public function test_it_ignores_the_policy( string $policy ): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => $policy ] );

		$this->assertTrue( $this->detector()->has_conflict() );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function quiet_policies(): Generator {
		yield 'defer'       => [ Conflict_Policy::DEFER ];
		yield 'notice only' => [ Conflict_Policy::NOTICE_ONLY ];
	}

	/**
	 * Not merely that the answer is unaffected by the policy, but that the policy is never asked for.
	 * A configured policy may be a callable, and the value it returns goes through a filter any
	 * plugin on the site may have hooked — so reading it here would run host code on every admin GET,
	 * before anyone has been shown to be allowed to resolve anything.
	 */
	public function test_it_never_reads_the_policy(): void {
		$reads = [];

		$this->standalone_is( true );
		$this->register(
			[
				'conflict_policy' => static function ( Sub_Plugin $sub_plugin ) use ( &$reads ): string {
					$reads[] = $sub_plugin->get_slug();

					return Conflict_Policy::DEACTIVATE;
				},
			]
		);

		$this->assertTrue( $this->detector()->has_conflict() );
		$this->assertSame( [], $reads, 'Detection must not run the host\'s policy callable.' );

		// The recorder has to be shown to work: a callable that was never wired to the sub-plugin at
		// all would leave the same empty log.
		foreach ( Absorber::all() as $sub_plugin ) {
			$sub_plugin->get_conflict_policy();
		}

		$this->assertSame( [ 'give-recurring' ], $reads, 'The configured callable really is the one being watched.' );
	}

	/**
	 * It is asked before the capability gate, on a request whose user has deliberately not been
	 * resolved yet. Anything it changed would be a change made for an anonymous visitor.
	 */
	public function test_it_changes_nothing(): void {
		$this->standalone_is( true );
		$this->register();
		$this->register_fee_recovery();

		$this->assertTrue( $this->detector()->has_conflict() );

		$this->assertSame( [], $this->deactivations, 'Probing must not deactivate anything.' );
		$this->assertSame( [], $this->queued_notices(), 'Probing must not queue a notice.' );

		// Both recorders have to be shown to work: a stub that failed to install and a queue read
		// under a name nothing writes to both look exactly like the assertions above passing.
		deactivate_plugins( 'give-recurring/give-recurring.php' );

		foreach ( Absorber::all() as $sub_plugin ) {
			Absorber::notices()->queue_conflict_notice( $sub_plugin );
		}

		$this->assertCount( 1, $this->deactivations, 'The deactivation recorder really is installed.' );
		$this->assertArrayHasKey(
			'give-recurring:conflict',
			$this->queued_notices(),
			'The queue reader really does see what the library writes.'
		);
	}

	/**
	 * The caller only needs to know whether the rest of the conflict step is worth entering, and the
	 * resolver walks the whole registry again anyway — so a second sub-plugin is a second
	 * `get_option()` per request, bought for an answer that cannot change.
	 */
	public function test_it_stops_at_the_first_conflict_it_finds(): void {
		$this->bind_checker( true );
		$this->register();
		$this->register_fee_recovery();

		$this->assertTrue( $this->detector()->has_conflict() );
		$this->assertSame(
			[ 'give-recurring/give-recurring.php' ],
			$this->asked,
			'The second sub-plugin must not be asked about once the first has answered.'
		);
	}

	/**
	 * The short-circuit is a `return true`, not a `return`. A host that bundles two plugins and has a
	 * standalone still installed for the *second* of them has to have that conflict found: a probe
	 * answering from the first registration alone would leave the standalone active, nothing
	 * deactivated and nothing said — and every other multi-registration case in this file puts the
	 * conflicting sub-plugin first, so it would go on passing.
	 */
	public function test_it_walks_past_a_sub_plugin_that_is_not_in_conflict(): void {
		$this->bind_checker_active_for( [ 'give-fee-recovery/give-fee-recovery.php' ] );
		$this->register();
		$this->register_fee_recovery();

		$this->assertTrue( $this->detector()->has_conflict() );
		$this->assertSame(
			[ 'give-recurring/give-recurring.php', 'give-fee-recovery/give-fee-recovery.php' ],
			$this->asked,
			'Both standalones have to be asked about, in registration order.'
		);
	}

	/**
	 * The probe reads the registry and nothing else, which is what keeps it cheap enough to ask of
	 * every admin GET — and a duplicate slug is the one bootstrap mistake that read can still raise,
	 * because it is only found when the buffer reaches the registrar. The conflict step catches this
	 * exception type around the probe for exactly this case, so it has to arrive as this type.
	 */
	public function test_a_duplicate_slug_surfaces_from_the_probe(): void {
		$this->standalone_is( true );
		$this->register();
		$this->register();

		$this->expectException( Config_Exception::class );

		$this->detector()->has_conflict();
	}

	/**
	 * Public, and asked once per sub-plugin by the resolver, so it is asserted directly rather than
	 * through the registry — no container, no registration, just the object and its checker.
	 */
	public function test_is_in_conflict_is_true_for_an_active_standalone(): void {
		$detector = new Detector( new Stub_Registry_Reader(), $this->recording_checker( true ) );

		$this->assertTrue(
			$detector->is_in_conflict(
				$this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] )
			)
		);
	}

	public function test_is_in_conflict_is_false_when_the_standalone_is_not_active(): void {
		$detector = new Detector( new Stub_Registry_Reader(), $this->recording_checker( false ) );

		$this->assertFalse(
			$detector->is_in_conflict(
				$this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] )
			)
		);
		$this->assertSame(
			[ 'give-recurring/give-recurring.php' ],
			$this->asked,
			'The answer has to have come from the checker rather than from a guard in front of it.'
		);
	}

	/**
	 * The two cheap keys are read first, so a site with a disabled sub-plugin pays nothing for it.
	 */
	public function test_is_in_conflict_asks_nothing_about_a_disabled_sub_plugin(): void {
		$detector = new Detector( new Stub_Registry_Reader(), $this->recording_checker( true ) );

		$this->assertFalse(
			$detector->is_in_conflict(
				$this->make_sub_plugin(
					[
						'enabled'                    => false,
						'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
					]
				)
			)
		);
		$this->assertSame( [], $this->asked );

		$this->assert_the_checker_is_really_recording( $detector );
	}

	public function test_is_in_conflict_asks_nothing_about_a_sub_plugin_with_no_standalone(): void {
		$detector = new Detector( new Stub_Registry_Reader(), $this->recording_checker( true ) );

		$this->assertFalse( $detector->is_in_conflict( $this->make_sub_plugin() ) );
		$this->assertSame( [], $this->asked, 'There is no basename to ask about.' );

		$this->assert_the_checker_is_really_recording( $detector );
	}

	/**
	 * The configured basename, exactly as configured. The sub-plugin's own guard constant names the
	 * same plugin and would be the wrong thing to ask about — it says whether the code is loaded,
	 * from either copy, not whether the standalone is switched on.
	 */
	public function test_is_in_conflict_asks_about_the_configured_basename(): void {
		$detector = new Detector( new Stub_Registry_Reader(), $this->recording_checker( true ) );

		$detector->is_in_conflict(
			$this->make_sub_plugin(
				[
					'slug'                       => 'give-fee-recovery',
					'standalone_plugin_basename' => 'give-fee-recovery/give-fee-recovery.php',
				]
			)
		);

		$this->assertSame( [ 'give-fee-recovery/give-fee-recovery.php' ], $this->asked );
	}

	/**
	 * A checker that was never reached and a checker whose recorder was never installed leave the
	 * same empty log, so every test asserting the checker was *not* asked shows it working once
	 * afterwards.
	 *
	 * @param Detector $detector Detector under test, with its recording checker.
	 *
	 * @return void
	 */
	private function assert_the_checker_is_really_recording( Detector $detector ): void {
		$detector->is_in_conflict(
			$this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] )
		);

		$this->assertSame(
			[ 'give-recurring/give-recurring.php' ],
			$this->asked,
			'The recorder really does catch a question when one is asked.'
		);
	}

	/**
	 * The detector the container builds, which is the one the conflict step reaches.
	 *
	 * @return Detector
	 */
	private function detector(): Detector {
		return $this->resolve( Detector::class );
	}

	/**
	 * A checker with a fixed answer that logs every basename it is asked about.
	 *
	 * The log lives on the test rather than on the double, so the double can be typed as the
	 * interface: a property read off a value typed `Plugin\Contracts\Checker_Interface` is a property the
	 * interface does not declare, and static analysis rightly rejects it.
	 *
	 * @param bool $active Whether every standalone is reported active.
	 *
	 * @return Checker_Interface
	 */
	private function recording_checker( bool $active ): Checker_Interface {
		$asked = &$this->asked;

		$record = static function ( string $basename ) use ( &$asked ): void {
			$asked[] = $basename;
		};

		return new class( $record, $active ) implements Checker_Interface {
			/**
			 * @var callable
			 */
			private $record;

			/**
			 * @var bool
			 */
			private $answer;

			/**
			 * @param callable $record Logs the basename asked about.
			 * @param bool     $answer Whether the standalone is reported active.
			 */
			public function __construct( callable $record, bool $answer ) {
				$this->record = $record;
				$this->answer = $answer;
			}

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return bool
			 */
			public function is_active( string $basename ): bool {
				( $this->record )( $basename );

				return $this->answer;
			}
		};
	}

	/**
	 * A checker that logs like the one above but answers per basename.
	 *
	 * One fixed answer cannot express the site this library is written for: several sub-plugins
	 * bundled, and a standalone still installed for only one of them.
	 *
	 * @param string[] $active Basenames reported active; every other one is reported inactive.
	 *
	 * @return Checker_Interface
	 */
	private function checker_active_for( array $active ): Checker_Interface {
		$asked = &$this->asked;

		$record = static function ( string $basename ) use ( &$asked ): void {
			$asked[] = $basename;
		};

		return new class( $record, $active ) implements Checker_Interface {
			/**
			 * @var callable
			 */
			private $record;

			/**
			 * @var string[]
			 */
			private $active;

			/**
			 * @param callable $record Logs the basename asked about.
			 * @param string[] $active Basenames reported active.
			 */
			public function __construct( callable $record, array $active ) {
				$this->record = $record;
				$this->active = $active;
			}

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return bool
			 */
			public function is_active( string $basename ): bool {
				( $this->record )( $basename );

				return in_array( $basename, $this->active, true );
			}
		};
	}

	/**
	 * Bind the recording checker in place of the default one.
	 *
	 * @param bool $active Whether every standalone is reported active.
	 *
	 * @return void
	 */
	private function bind_checker( bool $active ): void {
		$this->install_checker( $this->recording_checker( $active ) );
	}

	/**
	 * Bind a checker that reports only the named basenames active.
	 *
	 * @param string[] $active Basenames reported active.
	 *
	 * @return void
	 */
	private function bind_checker_active_for( array $active ): void {
		$this->install_checker( $this->checker_active_for( $active ) );
	}

	/**
	 * Put a checker into a container of its own, and configure the library with it.
	 *
	 * Bound before the provider runs, which is the only order that leaves it bound.
	 *
	 * @param Checker_Interface $checker Checker the detector is to be built with.
	 *
	 * @return void
	 */
	private function install_checker( Checker_Interface $checker ): void {
		$container = new Test_Container();
		$container->singleton(
			Checker_Interface::class,
			static function () use ( $checker ): Checker_Interface {
				return $checker;
			}
		);

		$this->set_up_container( $container );
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 *
	 * @return void
	 */
	private function register( array $overrides = [] ): void {
		Absorber::register(
			array_merge(
				[
					'slug'                       => 'give-recurring',
					'bundled_plugin_file'        => '/tmp/give-recurring.php',
					'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION_FIXTURE',
					'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
				],
				$overrides
			)
		);
	}

	/**
	 * A second bundled sub-plugin, for the tests about a site that absorbed more than one.
	 *
	 * @param array<string,mixed> $overrides Config overrides.
	 *
	 * @return void
	 */
	private function register_fee_recovery( array $overrides = [] ): void {
		$this->register(
			array_merge(
				[
					'slug'                       => 'give-fee-recovery',
					'bundled_plugin_file'        => '/tmp/give-fee-recovery.php',
					'plugin_loaded_constant'     => 'GIVE_FEE_RECOVERY_VERSION_FIXTURE',
					'standalone_plugin_basename' => 'give-fee-recovery/give-fee-recovery.php',
				],
				$overrides
			)
		);
	}

	/**
	 * Only is_plugin_active(), which is the one function Checker::is_active() calls — and it
	 * ORs the network check in itself, so stubbing is_plugin_active_for_network() alongside it
	 * would be inert and would read as though a network path were being exercised.
	 *
	 * @param bool $active Whether the standalone is active.
	 *
	 * @return void
	 */
	private function standalone_is( bool $active ): void {
		$this->setFunctionReturn( 'is_plugin_active', $active );
	}
}
