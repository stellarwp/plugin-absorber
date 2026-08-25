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
use RuntimeException;
use WP_Error;
use WP_Network;

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

	/**
	 * An option deliberately written with autoload on, so that "the queue is not in the bundle" is
	 * read off a bundle that demonstrably holds something.
	 */
	private const AUTOLOADED_CONTROL = 'plugin_absorber_autoload_control';

	/**
	 * The second site the network-scope test reads the queue from, once it has one.
	 *
	 * @var int|null
	 */
	private $second_site_id = null;

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		delete_site_option( self::OPTION );
	}

	public function tearDown(): void {
		$this->delete_second_site();
		delete_site_option( self::OPTION );
		delete_site_option( self::OPTION_WOO );
		delete_site_option( self::OPTION_NORMALISED );
		delete_option( self::AUTOLOADED_CONTROL );
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

		// The row has to be there before it can be taken away: a store that wrote nothing at all
		// would satisfy every assertion below without ever having cleared anything.
		$this->assertNotFalse( get_site_option( self::OPTION, false ), 'The queue must exist before it is cleared.' );

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
	 *
	 * The control option is what makes the absence mean anything. An `assertNotContains()` over an
	 * empty bundle passes without the store having done a thing, and goes on passing for ever — so
	 * an option written with autoload on is looked for first, out of a bundle re-read from the
	 * database rather than off the cache entry the write above just touched.
	 */
	public function test_the_queue_is_not_autoloaded(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Network options are not part of the per-site autoload bundle.' );
		}

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		add_option( self::AUTOLOADED_CONTROL, 'Carried on every request.', '', 'yes' );

		wp_cache_delete( 'alloptions', 'options' );

		$autoloaded = array_keys( wp_load_alloptions() );

		$this->assertContains(
			self::AUTOLOADED_CONTROL,
			$autoloaded,
			'The bundle has to carry an autoloaded option, or the queue not being in it says nothing.'
		);
		$this->assertNotContains( self::OPTION, $autoloaded );
	}

	/**
	 * The queue is one option for the whole network, not one per site.
	 *
	 * `get_site_option()`/`update_site_option()` rather than the plain pair is the only thing making
	 * that true, and outside multisite the two are the same function — so nothing on the singlesite
	 * leg can tell them apart, and swapping them would leave every other test in this class green.
	 * The scope has to match the act it reports: `deactivate_plugins()` takes the standalone out of
	 * the *network's* active plugins, and the merge notice explaining that is raised exactly once, so
	 * a per-site queue would file it against whichever site the request happened to land on and no
	 * administrator elsewhere would ever be told.
	 */
	public function test_the_queue_is_one_option_for_the_whole_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Outside multisite there is one site, so there is no scope to cross.' );
		}

		( new Store() )->put( 'give-recurring:merge', 'Bundled now.' );

		$this->assertSame(
			[ 'give-recurring:merge' => 'Bundled now.' ],
			( new Store() )->all(),
			'The queue must be readable where it was written, or reading it elsewhere proves nothing.'
		);

		$this->second_site_id = $this->create_second_site();

		switch_to_blog( $this->second_site_id );

		try {
			$elsewhere = ( new Store() )->all();
		} finally {
			// In a finally block so a read that throws cannot leave the rest of the suite running
			// against the second site.
			restore_current_blog();
		}

		$this->assertSame(
			[ 'give-recurring:merge' => 'Bundled now.' ],
			$elsewhere,
			'The queue must reach every site on the network, not only the one that wrote it.'
		);
	}

	/**
	 * A second site on the current network, created with the same domain and a path of its own so
	 * that it is unique on a subdomain install and a subdirectory one alike.
	 *
	 * @throws RuntimeException When there is no network, or the site cannot be created.
	 *
	 * @return int
	 */
	private function create_second_site(): int {
		$network = get_network();

		if ( ! $network instanceof WP_Network ) {
			throw new RuntimeException( 'The multisite env has no current network.' );
		}

		// Creating a site runs core's populate_options(), which calls delete_expired_transients(), whose
		// DELETE self-joins the options table under two aliases. The suite runs inside a transaction on
		// TEMPORARY tables, and MySQL cannot open one of those twice in a statement -- so the query
		// fails, harmlessly, on a site that has no transients to expire. Suppressed for the one call
		// rather than left to print a WordPress database error into every CI log.
		global $wpdb;

		$suppressing = $wpdb->suppress_errors( true );

		$site_id = wpmu_create_blog(
			$network->domain,
			$network->path . 'absorber-queue-scope/',
			'Queue scope',
			0,
			[],
			get_current_network_id()
		);

		$wpdb->suppress_errors( $suppressing );

		// `wpmu_create_blog()` puts WordPress into installing mode and never takes it back out, so
		// without this every later test in the process runs as though the site were mid-install.
		// Core's own blog factory ends with the same line, for the same reason.
		wp_installing( false );

		if ( $site_id instanceof WP_Error ) {
			throw new RuntimeException( 'Could not create a second site: ' . $site_id->get_error_message() );
		}

		return $site_id;
	}

	/**
	 * Creating a site is DDL, which MySQL commits outside the transaction the suite rolls back, so
	 * the tables it made have to be dropped by hand rather than left to the rollback.
	 *
	 * @return void
	 */
	private function delete_second_site(): void {
		if ( $this->second_site_id === null ) {
			return;
		}

		wp_delete_site( $this->second_site_id );

		$this->second_site_id = null;
	}
}
