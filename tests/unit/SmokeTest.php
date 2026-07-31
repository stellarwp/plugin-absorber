<?php
/**
 * Verifies the harness itself before any library code depends on it.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class SmokeTest extends WPTestCase {
	use UopzFunctions;

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	public function test_uopz_is_available(): void {
		$this->assertTrue( extension_loaded( 'uopz' ), 'uopz is required to stub WordPress functions.' );
		$this->assertTrue( function_exists( 'uopz_set_return' ) );
	}

	public function test_uopz_can_stub_a_function(): void {
		$this->setFunctionReturn( 'wp_get_referer', 'https://example.test/wp-admin/plugins.php' );

		$this->assertSame( 'https://example.test/wp-admin/plugins.php', wp_get_referer() );
	}
}
