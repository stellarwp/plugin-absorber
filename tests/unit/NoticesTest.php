<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Notices_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use RuntimeException;
use WP_Error;
use wpdb;

/**
 * @since 1.0.0
 */
class NoticesTest extends WPTestCase {
	use WithSubPlugins;

	private const OPTION = 'give_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Loader::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->clear_queue();

		// render() consumes the queue, so it is gated on a capability. Most tests care about the
		// queue rather than the gate, so they run as someone who has it.
		$user_id = $this->create_user( 'administrator' );

		// On multisite activate_plugins is a network capability, so an administrator of a site is
		// not enough — see test_a_site_administrator_on_multisite_cannot_consume_the_queue().
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );
	}

	public function tearDown(): void {
		$this->clear_queue();
		delete_site_option( 'woo_plugin_absorber_notices' );
		Loader::reset();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_the_loader_resolves_the_default_notices(): void {
		$this->assertInstanceOf( Notices::class, Loader::notices() );
	}

	public function test_the_default_notices_satisfy_the_contract(): void {
		$this->assertInstanceOf( Notices_Interface::class, new Notices() );
	}

	/**
	 * @dataProvider queued_notices
	 *
	 * @param string              $method    Method on Notices that queues the notice.
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
		$notices = new Notices();
		$notices->{$method}( $this->make_sub_plugin( $overrides ) );

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
			[ 'conflict_notice_message' => 'Bundled now.' ],
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
			[ 'conflict_notice_message' => 'Bundled now.' ],
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
			[ 'dependency_notice_message' => 'Needs Give.' ],
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
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_conflict_notice( $this->make_sub_plugin() );

		$queue = $this->queue();

		$this->assertNotSame( $queue['give-recurring:merge'], $queue['give-recurring:conflict'] );
	}

	public function test_a_configured_message_is_used_for_both_conflict_types(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Ours.' ] ) );
		$notices->queue_conflict_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Ours.' ] ) );

		$queue = $this->queue();

		$this->assertSame( 'Ours.', $queue['give-recurring:merge'] );
		$this->assertSame( 'Ours.', $queue['give-recurring:conflict'] );
	}

	public function test_queueing_the_same_slug_and_type_twice_does_not_duplicate(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertCount( 1, $this->queue() );
	}

	public function test_one_slug_can_hold_notices_of_different_types(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertCount( 2, $this->queue() );
	}

	public function test_different_slugs_do_not_collide(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );

		$queue = $this->queue();

		$this->assertCount( 2, $queue );
		$this->assertArrayHasKey( 'give-recurring:merge', $queue );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queue );
	}

	public function test_render_outputs_dismissible_markup(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	/**
	 * @dataProvider notice_severities
	 *
	 * @param string $method Method on Notices that queues the notice.
	 * @param string $class  Expected `notice-*` class.
	 */
	public function test_render_uses_the_severity_of_the_notice_type( string $method, string $class ): void {
		$notices = new Notices();
		$notices->{$method}( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Something happened.' ] ) );

		$this->assertStringContainsString( 'notice ' . $class . ' is-dismissible', $this->render_to_string( $notices ) );
	}

	/**
	 * A dependency notice reports a plugin that did not load, which is an error; the conflict
	 * pair report something the library handled, which is a warning.
	 *
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function notice_severities(): Generator {
		yield 'merge'      => [ 'queue_merge_notice', 'notice-warning' ];
		yield 'conflict'   => [ 'queue_conflict_notice', 'notice-warning' ];
		yield 'dependency' => [ 'queue_dependency_notice', 'notice-error' ];
	}

	public function test_render_escapes_the_message(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => '<script>alert(1)</script>' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * Escaping is `esc_html()` on purpose, so a message is plain text and a host that ships a
	 * link gets literal angle brackets. Pinned here because loosening it later is safe and
	 * tightening it later is not.
	 */
	public function test_render_does_not_allow_markup_in_a_message(): void {
		$notices = new Notices();
		$notices->queue_merge_notice(
			$this->make_sub_plugin( [ 'conflict_notice_message' => 'See <a href="https://example.com">the docs</a>.' ] )
		);

		$output = $this->render_to_string( $notices );

		$this->assertStringNotContainsString( '<a href', $output );
		$this->assertStringContainsString( '&lt;a href=', $output );
	}

	public function test_render_clears_the_queue(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->render_to_string( $notices );

		$this->assertFalse( $this->queue_exists() );
		$this->assertSame( '', $this->render_to_string( $notices ), 'A second render must output nothing.' );
	}

	public function test_render_outputs_every_queued_notice(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'First.' ] ) );
		$notices->queue_dependency_notice( $this->make_sub_plugin( [ 'dependency_notice_message' => 'Second.' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringContainsString( 'First.', $output );
		$this->assertStringContainsString( 'Second.', $output );
	}

	public function test_render_outputs_nothing_when_the_queue_is_empty(): void {
		$this->assertSame( '', $this->render_to_string( new Notices() ) );
	}

	/**
	 * Rendering consumes the queue, so a user who cannot act on the notice must neither see it
	 * nor destroy it. The merge notice is raised once and never re-queued.
	 *
	 * @dataProvider users_who_cannot_activate_plugins
	 *
	 * @param string|null $role Role to render as, or null for a logged-out visitor.
	 */
	public function test_render_does_nothing_for_a_user_who_cannot_activate_plugins( ?string $role ): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		wp_set_current_user( $role === null ? 0 : $this->create_user( $role ) );

		$this->assertSame( '', $this->render_to_string( $notices ) );
		$this->assertTrue( $this->queue_exists(), 'The queue must survive for someone who can act on it.' );
	}

	/**
	 * @return Generator<string,array{0:string|null}>
	 */
	public static function users_who_cannot_activate_plugins(): Generator {
		yield 'a subscriber'         => [ 'subscriber' ];
		yield 'a logged-out visitor' => [ null ];
	}

	/**
	 * Surprising but intended: on multisite `activate_plugins` maps through
	 * `manage_network_plugins`, which only a super admin has unless the network has opened the
	 * plugins menu to site admins. So the person who installed the plugin on their own site is
	 * not the person who sees the notice — a network administrator is.
	 */
	public function test_a_site_administrator_on_multisite_cannot_consume_the_queue(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Outside multisite an administrator simply has activate_plugins.' );
		}

		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		wp_set_current_user( $this->create_user( 'administrator' ) );

		$this->assertSame( '', $this->render_to_string( $notices ) );
		$this->assertTrue( $this->queue_exists(), 'The queue must survive for the network administrator.' );
	}

	/**
	 * The resolver redirects, so the queue has to come back off a durable database row rather
	 * than out of the object cache the redirecting request happened to warm. Asserting the row
	 * itself, not just that a flush is survivable: on a site with no persistent object cache a
	 * transient lands in the options table too, so a flush test alone would pass for the
	 * transient-backed design this class exists to avoid.
	 */
	public function test_the_queue_is_a_durable_database_row(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$this->assertStringContainsString(
			'Bundled now.',
			$this->stored_row(),
			'The queue must be a row in the database, not a cache entry.'
		);

		wp_cache_flush();

		$this->assertStringContainsString( 'Bundled now.', $this->render_to_string( new Notices() ) );
	}

	/**
	 * @dataProvider malformed_queues
	 *
	 * @param mixed         $stored  Raw option value to seed.
	 * @param string|null   $present Substring the output must contain, or null when nothing at
	 *                               all should be rendered.
	 * @param array<string> $absent  Substrings the output must not contain.
	 */
	public function test_render_ignores_anything_that_is_not_a_message( $stored, ?string $present, array $absent ): void {
		$this->seed_queue( $stored );

		$output = $this->render_to_string( new Notices() );

		if ( $present === null ) {
			$this->assertSame( '', $output );
		} else {
			$this->assertStringContainsString( $present, $output );
		}

		foreach ( $absent as $needle ) {
			$this->assertStringNotContainsString( $needle, $output );
		}
	}

	/**
	 * The first two are the likeliest real corruption: another plugin, or a host reading and
	 * rewriting the option, leaves something behind that is not an array at all. The rest are
	 * per-entry rubbish, which is dropped without taking the well-formed entries with it.
	 *
	 * @return Generator<string,array{0:mixed,1:string|null,2:array<string>}>
	 */
	public static function malformed_queues(): Generator {
		yield 'a scalar instead of an array' => [ 'not-a-queue', null, [ 'not-a-queue', 'notice' ] ];

		yield 'an object instead of an array' => [
			(object) [ 'a:merge' => 'Nope.' ],
			null,
			[ 'Nope.', 'notice' ],
		];

		yield 'entries that are not strings' => [
			[
				'a:merge' => 'Fine.',
				'b:merge' => [ 'nested' ],
				'c:merge' => null,
				'd:merge' => 42,
			],
			'Fine.',
			[ 'Array', '42' ],
		];

		yield 'an empty message' => [ [ 'a:merge' => '' ], null, [ 'notice' ] ];

		// A message that is only whitespace would otherwise print an empty notice box.
		yield 'a whitespace-only message' => [ [ 'a:merge' => "  \n\t" ], null, [ 'notice' ] ];
	}

	public function test_a_corrupted_queue_heals_on_the_next_write(): void {
		$this->seed_queue( [ 'a:merge' => [ 'nested' ] ] );

		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertSame( [ 'give-recurring:merge' ], array_keys( $this->queue() ) );
	}

	public function test_the_option_is_keyed_by_the_hook_prefix(): void {
		Config_State::reset();
		Config::set_hook_prefix( 'woo' );

		$this->assertSame( 'woo_plugin_absorber_notices', Notices::option_name() );

		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertIsArray( get_site_option( 'woo_plugin_absorber_notices', false ) );
		$this->assertFalse( $this->queue_exists() );
	}

	public function test_queueing_needs_a_hook_prefix(): void {
		Config_State::reset();

		$this->expectException( Config_Exception::class );

		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );
	}

	/**
	 * The queue is empty on nearly every request and only ever read in the admin, so it must not
	 * ride along in the autoloaded bundle on every front-end request.
	 */
	public function test_the_queue_is_not_autoloaded(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Network options are not part of the per-site autoload bundle.' );
		}

		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

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
	 * Whether the option exists at all, which is what "render cleared the queue" means.
	 *
	 * @return bool
	 */
	private function queue_exists(): bool {
		return get_site_option( self::OPTION, false ) !== false;
	}

	/**
	 * The serialized option value straight out of the database, bypassing the object cache.
	 *
	 * @return string
	 */
	private function stored_row(): string {
		/** @var wpdb $wpdb */
		global $wpdb;

		if ( is_multisite() ) {
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d",
					self::OPTION,
					get_current_network_id()
				)
			);
		} else {
			$stored = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION )
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
	 * @param string $role Role to give the new user.
	 *
	 * @throws RuntimeException When the user cannot be created, rather than letting a later
	 *                          capability assertion fail for an unrelated reason.
	 *
	 * @return int
	 */
	private function create_user( string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => uniqid( 'absorber-' ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			]
		);

		if ( $user_id instanceof WP_Error ) {
			throw new RuntimeException( 'Could not create a ' . $role . ': ' . $user_id->get_error_message() );
		}

		return $user_id;
	}

	private function render_to_string( Notices $notices ): string {
		ob_start();

		try {
			$notices->render();
		} finally {
			// In a finally block so a throw from render() cannot leave the suite's own output
			// trapped in an abandoned buffer.
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
