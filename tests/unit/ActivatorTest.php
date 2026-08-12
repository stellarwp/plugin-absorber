<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Activator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use RuntimeException;

/**
 * "Once, ever", and where that is recorded.
 *
 * The activator is built directly rather than resolved: it takes no collaborators, so a container
 * would have nothing to hand it, and two of these tests turn on holding two separate instances —
 * which the provider's singleton binding cannot produce. That it is bound at all, and that a host
 * may rebind it, is `ProviderTest`'s subject.
 *
 * @since 1.0.0
 */
class ActivatorTest extends WPTestCase {
	use WithSubPlugins;

	private const OPTION = 'give_plugin_absorber_activations';

	private const OPTION_WOO = 'woo_plugin_absorber_activations';

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->clear_activations();
	}

	public function tearDown(): void {
		$this->clear_activations();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_runs_the_activation_callback(): void {
		$calls = [];

		( new Activator() )->maybe_run( $this->recording_sub_plugin( $calls ) );

		$this->assertCount( 1, $calls );
	}

	/**
	 * The sub-plugin is the callback's one argument, so a host's migration can read the slug and the
	 * bundled file it is running for rather than being closed over one of them at config time.
	 */
	public function test_the_callback_receives_the_sub_plugin(): void {
		$calls      = [];
		$sub_plugin = $this->recording_sub_plugin( $calls );

		( new Activator() )->maybe_run( $sub_plugin );

		$this->assertSame( [ $sub_plugin ], $calls );
	}

	public function test_it_runs_the_callback_only_once(): void {
		$calls      = [];
		$sub_plugin = $this->recording_sub_plugin( $calls );
		$activator  = new Activator();

		$activator->maybe_run( $sub_plugin );
		$activator->maybe_run( $sub_plugin );

		$this->assertCount( 1, $calls );
	}

	/**
	 * The record is the option, not the object holding it. Every request builds a fresh collaborator,
	 * so an in-memory guard would run the callback again on the next page load — forever.
	 */
	public function test_a_fresh_instance_does_not_run_the_callback_again(): void {
		$calls      = [];
		$sub_plugin = $this->recording_sub_plugin( $calls );

		( new Activator() )->maybe_run( $sub_plugin );
		( new Activator() )->maybe_run( $sub_plugin );

		$this->assertCount( 1, $calls );
	}

	public function test_it_records_the_slug_in_the_option(): void {
		$calls = [];

		( new Activator() )->maybe_run( $this->recording_sub_plugin( $calls ) );

		$this->assertSame( [ 'give-recurring' => true ], $this->recorded() );
	}

	/**
	 * Most sub-plugins configure no callback at all. Writing a row for them would put an option on
	 * every site that has nothing to record, and one that can never be read for anything.
	 */
	public function test_it_does_nothing_without_an_activation_callback(): void {
		( new Activator() )->maybe_run( $this->make_sub_plugin() );

		$this->assertFalse( get_site_option( self::OPTION, false ), 'No callback means no option.' );
	}

	public function test_two_slugs_are_tracked_independently(): void {
		$recurring_calls = [];
		$recovery_calls  = [];
		$recurring       = $this->recording_sub_plugin( $recurring_calls, [ 'slug' => 'give-recurring' ] );
		$recovery        = $this->recording_sub_plugin( $recovery_calls, [ 'slug' => 'give-fee-recovery' ] );
		$activator       = new Activator();

		$activator->maybe_run( $recurring );
		$activator->maybe_run( $recovery );
		$activator->maybe_run( $recurring );

		$this->assertCount( 1, $recurring_calls );
		$this->assertCount( 1, $recovery_calls, 'One slug being recorded must not stand the other down.' );
		$this->assertSame(
			[ 'give-recurring' => true, 'give-fee-recovery' => true ],
			$this->recorded()
		);
	}

	/**
	 * @dataProvider corrupted_options
	 *
	 * @param mixed $stored Raw option value to seed.
	 */
	public function test_it_recovers_from_a_corrupted_option( $stored ): void {
		update_site_option( self::OPTION, $stored );

		$calls = [];

		( new Activator() )->maybe_run( $this->recording_sub_plugin( $calls ) );

		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'give-recurring' => true ], $this->recorded() );
	}

	/**
	 * Anything that is not an array reaches an array read inside plugins_loaded, on every request —
	 * so it is replaced rather than trusted, and the site heals on the next write.
	 *
	 * @return Generator<string,array{0:mixed}>
	 */
	public static function corrupted_options(): Generator {
		yield 'a string instead of an array' => [ 'not-a-record' ];

		yield 'an object instead of an array' => [ (object) [ 'give-recurring' => true ] ];

		yield 'a number instead of an array' => [ 42 ];
	}

	/**
	 * Two hosts on one site each get their own record, and a prefix that names filters is folded
	 * before it becomes a storage key.
	 */
	public function test_the_option_name_follows_the_hook_prefix(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'woo' );

		$calls = [];

		( new Activator() )->maybe_run( $this->recording_sub_plugin( $calls ) );

		$this->assertSame( [ 'give-recurring' => true ], get_site_option( self::OPTION_WOO ) );
		$this->assertFalse( get_site_option( self::OPTION, false ), 'Another host must not be written to.' );
	}

	/**
	 * The slug is recorded after the callback returns, never before. A callback that fatals halfway
	 * leaves the site half-migrated either way, but recording first would make the half-finished
	 * state permanent: the next request would see the record and skip the retry.
	 */
	public function test_a_throwing_callback_leaves_the_slug_unrecorded(): void {
		$failing = $this->make_sub_plugin(
			[
				'activation_callback' => static function (): void {
					throw new RuntimeException( 'the migration could not reach the database' );
				},
			]
		);

		$threw = false;

		try {
			( new Activator() )->maybe_run( $failing );
		} catch ( RuntimeException $exception ) {
			$threw = true;

			$this->assertSame( 'the migration could not reach the database', $exception->getMessage() );
		}

		$this->assertTrue( $threw, 'The callback has to have run for this to be about the ordering.' );
		$this->assertFalse( get_site_option( self::OPTION, false ), 'A failed run must not be recorded.' );

		$calls = [];

		( new Activator() )->maybe_run( $this->recording_sub_plugin( $calls ) );

		$this->assertCount( 1, $calls, 'The slug has to be retried, not skipped forever.' );
	}

	/**
	 * A sub-plugin whose activation callback appends itself to the given array.
	 *
	 * The whole `Sub_Plugin` is recorded rather than its slug, so the argument the callback is handed
	 * can be asserted on as well as counted.
	 *
	 * @param array<int,Sub_Plugin> $calls     Recorder to append to.
	 * @param array<string,mixed>   $overrides Config overrides.
	 *
	 * @return Sub_Plugin
	 */
	private function recording_sub_plugin( array &$calls, array $overrides = [] ): Sub_Plugin {
		return $this->make_sub_plugin(
			array_merge(
				[
					'activation_callback' => static function ( Sub_Plugin $sub_plugin ) use ( &$calls ): void {
						$calls[] = $sub_plugin;
					},
				],
				$overrides
			)
		);
	}

	/**
	 * The record as it stands, read under the name this suite's own prefix produces.
	 *
	 * Against a literal, deliberately. Reading the name off the class under test would move both
	 * sides of every assertion together, so a rename of the key — or of the segment `Config` builds
	 * between the host's prefix and it — would keep passing while writing somewhere nobody reads.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded(): array {
		$done = get_site_option( self::OPTION, [] );

		return is_array( $done ) ? $done : [];
	}

	/**
	 * @return void
	 */
	private function clear_activations(): void {
		delete_site_option( self::OPTION );
		delete_site_option( self::OPTION_WOO );
	}
}
