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
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;

/**
 * The storage half of the queue, exercised without going through Writer or Presenter.
 *
 * WriterTest and PresenterTest cover this ground from the outside; these are the assertions that
 * belong to the store itself, so that swapping how notices are worded or drawn cannot quietly take
 * the storage guarantees with it.
 *
 * @since 1.0.0
 */
class StoreTest extends WPTestCase {
	private const OPTION = 'give_plugin_absorber_notices';

	private const OPTION_WOO = 'woo_plugin_absorber_notices';

	private const OPTION_NORMALISED = 'give_core_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		delete_site_option( self::OPTION );
	}

	public function tearDown(): void {
		delete_site_option( self::OPTION );
		delete_site_option( self::OPTION_WOO );
		delete_site_option( self::OPTION_NORMALISED );
		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_stores_a_message_under_the_given_key(): void {
		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		$this->assertSame( [ 'give-recurring:merge' => 'Bundled now.' ], ( new Store() )->all() );
	}

	/**
	 * A second instance reads what the first wrote: the queue lives in the database, not in the
	 * object that happened to write it. The resolver redirects, so the reading request is almost
	 * never the writing one.
	 */
	public function test_the_queue_outlives_the_instance_that_wrote_it(): void {
		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		wp_cache_flush();

		$this->assertSame( 'Bundled now.', ( new Store() )->all()['give-recurring:merge'] ?? '' );
	}

	public function test_writing_the_same_key_twice_replaces_rather_than_duplicates(): void {
		$store = new Store();
		$store->put( 'give-recurring:merge', 'First.' );
		$store->put( 'give-recurring:merge', 'Second.' );

		$this->assertSame( [ 'give-recurring:merge' => 'Second.' ], $store->all() );
	}

	public function test_different_keys_coexist(): void {
		$store = new Store();
		$store->put( 'give-recurring:merge', 'One.' );
		$store->put( 'give-recurring:dependency', 'Two.' );

		$this->assertCount( 2, $store->all() );
	}

	public function test_clear_removes_the_row_entirely(): void {
		$store = new Store();
		$store->put( 'give-recurring:merge', 'Bundled now.' );

		$store->clear();

		$this->assertFalse( get_site_option( self::OPTION, false ), 'The option must be gone, not emptied.' );
		$this->assertSame( [], $store->all() );
	}

	/**
	 * @dataProvider malformed_queues
	 *
	 * @param mixed                $stored   Raw option value to seed.
	 * @param array<string,string> $expected What all() must return for it.
	 */
	public function test_it_drops_anything_that_is_not_a_message( $stored, array $expected ): void {
		update_site_option( self::OPTION, $stored );

		$this->assertSame( $expected, ( new Store() )->all() );
	}

	/**
	 * The first two are the likeliest real corruption: another plugin, or a host reading and
	 * rewriting the option, leaves behind something that is not an array at all. The rest are
	 * per-entry rubbish, which is dropped without taking the well-formed entries with it.
	 *
	 * @return Generator<string,array{0:mixed,1:array<string,string>}>
	 */
	public static function malformed_queues(): Generator {
		yield 'a scalar instead of an array' => [ 'not-a-queue', [] ];

		yield 'an object instead of an array' => [ (object) [ 'a:merge' => 'Nope.' ], [] ];

		yield 'entries that are not strings' => [
			[
				'a:merge' => 'Fine.',
				'b:merge' => [ 'nested' ],
				'c:merge' => null,
				'd:merge' => 42,
			],
			[ 'a:merge' => 'Fine.' ],
		];
	}

	public function test_a_corrupted_queue_heals_on_the_next_write(): void {
		update_site_option( self::OPTION, [ 'a:merge' => [ 'nested' ] ] );

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		$this->assertSame( [ 'give-recurring:merge' ], array_keys( ( new Store() )->all() ) );
	}

	public function test_the_option_is_keyed_by_the_hook_prefix(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'woo' );

		$this->assertSame( self::OPTION_WOO, ( new Store() )->option_name() );
	}

	/**
	 * The hook prefix is allowed mixed case and hyphens because it names filters. An option name
	 * is a storage key, so the prefix reaches the database folded rather than raw.
	 */
	public function test_the_option_name_normalises_the_hook_prefix(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'Give-Core' );

		$this->assertSame( self::OPTION_NORMALISED, ( new Store() )->option_name() );

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		$this->assertSame(
			[ 'give-recurring:merge' => 'Bundled now.' ],
			get_site_option( self::OPTION_NORMALISED ),
			'The queue must be written under the normalised name.'
		);
	}

	public function test_it_needs_a_hook_prefix(): void {
		Config_State::reset();

		$this->expectException( Config_Exception::class );

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );
	}

	/**
	 * The queue is empty on nearly every request and only ever read in the admin, so it must not
	 * ride along in the autoloaded bundle on every front-end request.
	 */
	public function test_the_queue_is_not_autoloaded(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Network options are not part of the per-site autoload bundle.' );
		}

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		$this->assertNotContains( self::OPTION, array_keys( wp_load_alloptions() ) );
	}
}
