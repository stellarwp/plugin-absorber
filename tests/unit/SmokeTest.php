<?php
/**
 * Verifies the harness itself before any library code depends on it.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUopz;

/**
 * @since 1.0.0
 */
class SmokeTest extends WPTestCase {
	use WithUopz;

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	public function test_uopz_is_available(): void {
		$this->assertTrue( extension_loaded( 'uopz' ), 'uopz is required to stub WordPress functions.' );
		$this->assertTrue( function_exists( 'uopz_set_return' ) );
	}

	public function test_uopz_can_stub_a_function(): void {
		$this->set_function_return( 'wp_get_referer', 'https://example.test/wp-admin/plugins.php' );

		$this->assertSame( 'https://example.test/wp-admin/plugins.php', wp_get_referer() );
	}

	public function test_exit_can_be_neutralised(): void {
		$this->allow_exit( false );

		$reached = false;

		( static function () {
			exit;
		} )();

		$reached = true;

		$this->assertTrue( $reached, 'exit must be a no-op so the resolver redirect path is testable.' );
	}
}
