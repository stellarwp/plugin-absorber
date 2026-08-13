<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Notices;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Notices\Writer;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use wpdb;

/**
 * What a notice says, and which `slug:type` key it lands under.
 *
 * Who may consume the queue and how a pending notice is drawn is `PresenterTest`'s: this class asks
 * only what a message reads and where it is filed, so a test here never needs a signed-in user.
 *
 * @since 1.0.0
 */
class WriterTest extends WPTestCase {
	use WithSubPlugins;

	private const OPTION = 'give_plugin_absorber_notices';

	// The option the writer moves to once the hook prefix changes, which is what proves the name is
	// derived from the prefix rather than fixed.
	private const OPTION_FOR_OTHER_PREFIX = 'woo_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->clear_queue();
	}

	public function tearDown(): void {
		$this->clear_queue();
		delete_site_option( self::OPTION_FOR_OTHER_PREFIX );
		Config_State::reset();
		parent::tearDown();
	}

	public function test_the_default_writer_satisfies_the_contract(): void {
		$this->assertInstanceOf( Writer_Interface::class, $this->make_writer() );
	}

	/**
	 * @dataProvider queued_notices
	 *
	 * @param string              $method    Method on Writer that queues the notice.
	 * @param array<string,mixed> $overrides Sub-plugin config overrides.
	 * @param string              $key       Queue key the notice must land under.
	 * @param string              $expected  Expected message, whole or partial.
	 * @param bool                $exact     Whether $expected is the whole message.
	 */
	public function test_it_queues_a_notice(
		string $method,
		array $overrides,
		string $key,
		string $expected,
		bool $exact
	): void {
		$writer = $this->make_writer();
		$writer->{$method}( $this->make_sub_plugin( $overrides ) );

		$queue = $this->queue();

		$this->assertArrayHasKey( $key, $queue );

		if ( $exact ) {
			$this->assertSame( $expected, $queue[ $key ] );

			return;
		}

		// The fallbacks are not pinned word for word — they are allowed to be reworded, as long
		// as they still name the sub-plugin and are not empty.
		$this->assertStringContainsString( $expected, $queue[ $key ] );
		$this->assertNotSame( $expected, $queue[ $key ] );
	}

	/**
	 * Both the configured message and the fallback for each of the three notice types. The
	 * fallbacks are covered here rather than in their own methods because the assertion is the
	 * same one: the right message lands under the right `slug:type` key.
	 *
	 * @return Generator<string,array{0:string,1:array<string,mixed>,2:string,3:string,4:bool}>
	 */
	public static function queued_notices(): Generator {
		yield 'merge, configured' => [
			'queue_merge_notice',
			[ 'conflict_notice_message' => static fn() => 'Bundled now.' ],
			'give-recurring:merge',
			'Bundled now.',
			true,
		];

		yield 'merge, fallback' => [
			'queue_merge_notice',
			[],
			'give-recurring:merge',
			'give-recurring',
			false,
		];

		yield 'conflict, configured' => [
			'queue_conflict_notice',
			[ 'conflict_notice_message' => static fn() => 'Bundled now.' ],
			'give-recurring:conflict',
			'Bundled now.',
			true,
		];

		yield 'conflict, fallback' => [
			'queue_conflict_notice',
			[],
			'give-recurring:conflict',
			'give-recurring',
			false,
		];

		yield 'dependency, configured' => [
			'queue_dependency_notice',
			[ 'dependency_notice_message' => static fn() => 'Needs Give.' ],
			'give-recurring:dependency',
			'Needs Give.',
			true,
		];

		yield 'dependency, fallback' => [
			'queue_dependency_notice',
			[],
			'give-recurring:dependency',
			'give-recurring could not be loaded because its requirements are not met.',
			true,
		];
	}

	/**
	 * The two conflict-flavoured notices say opposite things — one reports a deactivation that
	 * already happened, the other asks the user to do it. Sharing a default would be wrong.
	 */
	public function test_the_merge_and_conflict_defaults_differ(): void {
		$writer = $this->make_writer();
		$writer->queue_merge_notice( $this->make_sub_plugin() );
		$writer->queue_conflict_notice( $this->make_sub_plugin() );

		$queue = $this->queue();

		$this->assertNotSame( $queue['give-recurring:merge'], $queue['give-recurring:conflict'] );
	}

	public function test_a_configured_message_is_used_for_both_conflict_types(): void {
		$writer = $this->make_writer();
		$writer->queue_merge_notice(
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Ours.' ] )
		);
		$writer->queue_conflict_notice(
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Ours.' ] )
		);

		$queue = $this->queue();

		$this->assertSame( 'Ours.', $queue['give-recurring:merge'] );
		$this->assertSame( 'Ours.', $queue['give-recurring:conflict'] );
	}

	public function test_queueing_the_same_slug_and_type_twice_does_not_duplicate(): void {
		$writer = $this->make_writer();
		$writer->queue_merge_notice( $this->make_sub_plugin() );
		$writer->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertCount( 1, $this->queue() );
	}

	public function test_one_slug_can_hold_notices_of_different_types(): void {
		$writer = $this->make_writer();
		$writer->queue_merge_notice( $this->make_sub_plugin() );
		$writer->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertCount( 2, $this->queue() );
	}

	public function test_different_slugs_do_not_collide(): void {
		$writer = $this->make_writer();
		$writer->queue_merge_notice( $this->make_sub_plugin() );
		$writer->queue_merge_notice( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );

		$queue = $this->queue();

		$this->assertCount( 2, $queue );
		$this->assertArrayHasKey( 'give-recurring:merge', $queue );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queue );
	}

	/**
	 * Where notices are kept is a constructor argument, so a host can move the queue somewhere else
	 * without also taking on how notices are worded. It is a required argument and it is bound by
	 * `Provider`, so a host rebinds the store rather than subclassing the writer.
	 */
	public function test_a_replacement_store_is_used_instead_of_the_option(): void {
		$store = new class() extends Store {
			/**
			 * @var array<string,string>
			 */
			public $written = [];

			/**
			 * @return array<string,string>
			 */
			public function all(): array {
				return $this->written;
			}

			/**
			 * @param string $key     Queue key.
			 * @param string $message Resolved message.
			 *
			 * @return void
			 */
			public function put( string $key, string $message ): void {
				$this->written[ $key ] = $message;
			}

			/**
			 * @return void
			 */
			public function clear(): void {
				$this->written = [];
			}
		};

		$this->make_writer( $store )->queue_merge_notice(
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Bundled now.' ] )
		);

		$this->assertSame( [ 'give-recurring:merge' => 'Bundled now.' ], $store->written );
		$this->assertFalse( $this->queue_exists(), 'The default option must not have been written to.' );
	}

	/**
	 * The resolver redirects, so the queue has to come back off a durable database row rather
	 * than out of the object cache the redirecting request happened to warm. Asserting the row
	 * itself, not just that a flush is survivable: on a site with no persistent object cache a
	 * transient lands in the options table too, so a flush test alone would pass for the
	 * transient-backed design this class exists to avoid.
	 */
	public function test_the_queue_is_a_durable_database_row(): void {
		$this->make_writer()->queue_merge_notice(
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Bundled now.' ] )
		);

		$this->assertStringContainsString(
			'Bundled now.',
			$this->stored_row(),
			'The queue must be a row in the database, not a cache entry.'
		);

		wp_cache_flush();

		$this->assertSame( 'Bundled now.', $this->queue()['give-recurring:merge'] ?? '' );
	}

	public function test_a_corrupted_queue_heals_on_the_next_write(): void {
		$this->seed_queue( [ 'a:merge' => [ 'nested' ] ] );

		$this->make_writer()->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertSame( [ 'give-recurring:merge' ], array_keys( $this->queue() ) );
	}

	public function test_the_option_is_keyed_by_the_hook_prefix(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'woo' );

		$this->assertSame( self::OPTION_FOR_OTHER_PREFIX, $this->make_writer()->option_name() );

		$this->make_writer()->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertIsArray( get_site_option( self::OPTION_FOR_OTHER_PREFIX, false ) );
		$this->assertFalse( $this->queue_exists() );
	}

	public function test_queueing_needs_a_hook_prefix(): void {
		Config_State::reset();

		$this->expectException( Config_Exception::class );

		$this->make_writer()->queue_merge_notice( $this->make_sub_plugin() );
	}

	/**
	 * The queue is empty on nearly every request and only ever read in the admin, so it must not
	 * ride along in the autoloaded bundle on every front-end request.
	 */
	public function test_the_queue_is_not_autoloaded(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Network options are not part of the per-site autoload bundle.' );
		}

		$this->make_writer()->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertNotContains( self::OPTION, array_keys( wp_load_alloptions() ) );
	}

	/**
	 * The queue as the class stores it. Always an array, so callers can index and count it: use
	 * queue_exists() to ask whether there is a row at all.
	 *
	 * @return array<string,string>
	 */
	private function queue(): array {
		$queue = get_site_option( self::OPTION, [] );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Whether the option exists at all, which is what "the writer wrote somewhere else" means.
	 *
	 * @return bool
	 */
	private function queue_exists(): bool {
		return get_site_option( self::OPTION, false ) !== false;
	}

	/**
	 * The serialized option value straight out of the database, bypassing the object cache.
	 *
	 * The table name goes through the `%i` identifier placeholder rather than into the string, so
	 * the query stays a literal and nothing interpolated ever reaches the parser.
	 *
	 * @return string
	 */
	private function stored_row(): string {
		/** @var wpdb $wpdb */
		global $wpdb;

		if ( is_multisite() ) {
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT meta_value FROM %i WHERE meta_key = %s AND site_id = %d',
					$wpdb->sitemeta,
					self::OPTION,
					get_current_network_id()
				)
			);
		} else {
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s',
					$wpdb->options,
					self::OPTION
				)
			);
		}

		$this->assertIsString( $stored, 'The queue option has no row in the database.' );

		return $stored;
	}

	/**
	 * @param mixed $queue Raw queue contents, well-formed or not.
	 */
	private function seed_queue( $queue ): void {
		update_site_option( self::OPTION, $queue );
	}

	private function clear_queue(): void {
		delete_site_option( self::OPTION );
	}

	/**
	 * The writer as the container builds it, or with its store replaced.
	 *
	 * The store is a required argument — nothing in `src/` defaults a collaborator to a `new` of
	 * its own any more — so the standard shape is spelled out once here rather than in every test.
	 *
	 * @param Store|null $store Where the queue is kept.
	 *
	 * @return Writer
	 */
	private function make_writer( ?Store $store = null ): Writer {
		return new Writer( $store ?? new Store() );
	}
}
