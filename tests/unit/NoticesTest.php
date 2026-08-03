<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Notices_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
class NoticesTest extends WPTestCase {
	private const TRANSIENT = 'give_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Loader::reset();
		Config::reset();
		Config::set_hook_prefix( 'give' );
		delete_transient( self::TRANSIENT );
	}

	public function tearDown(): void {
		delete_transient( self::TRANSIENT );
		Loader::reset();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function make_sub_plugin( array $overrides = [] ): Sub_Plugin {
		return new Sub_Plugin(
			array_merge(
				[
					'slug'                   => 'give-recurring',
					'bundled_plugin_file'    => '/tmp/give-recurring.php',
					'plugin_loaded_constant' => 'GIVE_RECURRING_VERSION_NOTICES',
				],
				$overrides
			)
		);
	}

	private function render_to_string( Notices $notices ): string {
		ob_start();
		$notices->render();

		return (string) ob_get_clean();
	}

	public function test_the_loader_resolves_the_default_notices(): void {
		$this->assertInstanceOf( Notices::class, Loader::notices() );
	}

	public function test_the_default_notices_satisfy_the_contract(): void {
		$this->assertInstanceOf( Notices_Interface::class, new Notices() );
	}

	public function test_it_queues_a_merge_notice_into_the_transient(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$queue = get_transient( self::TRANSIENT );

		$this->assertIsArray( $queue );
		$this->assertArrayHasKey( 'give-recurring:merge', $queue );
		$this->assertSame( 'Bundled now.', $queue['give-recurring:merge'] );
	}

	public function test_the_merge_notice_falls_back_to_a_default_message(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$queue = get_transient( self::TRANSIENT );

		$this->assertStringContainsString( 'give-recurring', $queue['give-recurring:merge'] );
		$this->assertNotSame( '', $queue['give-recurring:merge'] );
	}

	public function test_it_queues_a_conflict_notice(): void {
		( new Notices() )->queue_conflict_notice( $this->make_sub_plugin() );

		$this->assertArrayHasKey( 'give-recurring:conflict', get_transient( self::TRANSIENT ) );
	}

	/**
	 * The two conflict-flavoured notices say opposite things — one reports a deactivation that
	 * already happened, the other asks the user to do it. Sharing a default would be wrong.
	 */
	public function test_the_merge_and_conflict_defaults_differ(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_conflict_notice( $this->make_sub_plugin() );

		$queue = get_transient( self::TRANSIENT );

		$this->assertNotSame( $queue['give-recurring:merge'], $queue['give-recurring:conflict'] );
	}

	public function test_a_configured_message_is_used_for_both_conflict_types(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Ours.' ] ) );
		$notices->queue_conflict_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Ours.' ] ) );

		$queue = get_transient( self::TRANSIENT );

		$this->assertSame( 'Ours.', $queue['give-recurring:merge'] );
		$this->assertSame( 'Ours.', $queue['give-recurring:conflict'] );
	}

	public function test_it_queues_a_dependency_notice_using_the_sub_plugin_message(): void {
		( new Notices() )->queue_dependency_notice( $this->make_sub_plugin( [ 'dependency_notice_message' => 'Needs Give.' ] ) );

		$this->assertSame( 'Needs Give.', get_transient( self::TRANSIENT )['give-recurring:dependency'] );
	}

	public function test_the_dependency_notice_falls_back_to_the_sub_plugin_default(): void {
		( new Notices() )->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertSame(
			'give-recurring could not be loaded because its requirements are not met.',
			get_transient( self::TRANSIENT )['give-recurring:dependency']
		);
	}

	public function test_queueing_the_same_slug_and_type_twice_does_not_duplicate(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertCount( 1, get_transient( self::TRANSIENT ) );
	}

	public function test_one_slug_can_hold_notices_of_different_types(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertCount( 2, get_transient( self::TRANSIENT ) );
	}

	public function test_different_slugs_do_not_collide(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );

		$queue = get_transient( self::TRANSIENT );

		$this->assertCount( 2, $queue );
		$this->assertArrayHasKey( 'give-recurring:merge', $queue );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queue );
	}

	public function test_render_outputs_dismissible_warning_markup(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringContainsString( 'notice notice-warning is-dismissible', $output );
		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	public function test_render_escapes_the_message(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => '<script>alert(1)</script>' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_render_clears_the_queue(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->render_to_string( $notices );

		$this->assertFalse( get_transient( self::TRANSIENT ) );
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

	public function test_the_queue_survives_a_simulated_redirect(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		// A redirect ends the request; the next one builds a fresh object against the same store.
		$output = $this->render_to_string( new Notices() );

		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	/**
	 * The queue outlives the request that filled it, so it must not expire before the admin load
	 * that renders it. WordPress stores a no-expiry transient without a timeout option.
	 */
	public function test_the_queue_is_stored_without_an_expiry(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertFalse( get_option( '_transient_timeout_' . self::TRANSIENT ) );
	}

	public function test_the_transient_is_keyed_by_the_hook_prefix(): void {
		Config::reset();
		Config::set_hook_prefix( 'woo' );

		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertIsArray( get_transient( 'woo_plugin_absorber_notices' ) );
		$this->assertFalse( get_transient( self::TRANSIENT ) );

		delete_transient( 'woo_plugin_absorber_notices' );
	}
}
