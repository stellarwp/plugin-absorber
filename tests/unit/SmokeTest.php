<?php
/**
 * Verifies the harness itself before any library code depends on it.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class SmokeTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * Message carried by the exception that stands in for exit().
	 *
	 * Asserted on rather than merely caught, so an unrelated TestException cannot
	 * make this test pass for the wrong reason.
	 */
	private const HALTED_AT_EXIT = 'Halted where production calls exit().';

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

	/**
	 * The no-mocking-exit rule rests entirely on this mechanism working.
	 *
	 * Every later test of a redirect branch stubs the call before exit() and throws
	 * from it, so the exception has to survive being raised inside a uopz-replaced
	 * function. Proving that here means a later redirect test that fails is a real
	 * bug rather than a broken technique. See tests/README.md.
	 *
	 * @since 1.0.0
	 */
	public function test_a_stub_can_throw_to_halt_a_code_path(): void {
		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () {
				throw new TestException( self::HALTED_AT_EXIT );
			},
			true
		);

		$halted = false;

		try {
			wp_safe_redirect( 'https://example.test/wp-admin/plugins.php' );
		} catch ( TestException $e ) {
			$halted = true;

			$this->assertSame( self::HALTED_AT_EXIT, $e->getMessage() );
		}

		$this->assertTrue( $halted, 'A stubbed function must be able to throw in place of exit().' );
	}

	/**
	 * Proves the multisite env is actually a network, on its own tables.
	 *
	 * Without this the multisite CI leg only re-runs the singlesite assertions, and
	 * an env that silently failed to install a network would still report green.
	 * Checking the prefix alongside is_multisite() also covers the per-env
	 * tablePrefix that keeps the two envs from clobbering each other's tables.
	 *
	 * @since 1.0.0
	 */
	public function test_the_env_matches_its_table_prefix(): void {
		$prefix = $GLOBALS['wpdb']->base_prefix;

		$this->assertContains(
			$prefix,
			[ 'test_', 'mstest_' ],
			'Unexpected table prefix. The envs are declared in tests/unit.suite.yml.'
		);

		$this->assertSame(
			'mstest_' === $prefix,
			is_multisite(),
			sprintf(
				'The %s prefix belongs to the %s env, but is_multisite() disagrees.',
				$prefix,
				'mstest_' === $prefix ? 'multisite' : 'singlesite'
			)
		);
	}
}
