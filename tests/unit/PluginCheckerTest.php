<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use LogicException;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Plugin_Checker;

/**
 * Asking WordPress whether a plugin is active.
 *
 * Split from the deactivator because the two answer to different callers: a host rebinding the check
 * — which `learndash-core` has to, since it filters `option_active_plugins` so `is_plugin_active()`
 * does not report what is in the database — should not have to reimplement a deactivation to do it.
 *
 * @since 1.0.0
 */
class PluginCheckerTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @var Plugin_Checker
	 */
	private $checker;

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->checker = new Plugin_Checker();
	}

	public function test_it_implements_the_contract(): void {
		$this->assertInstanceOf( Plugin_Checker_Interface::class, $this->checker );
	}

	public function test_it_reports_an_active_plugin(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );

		$this->assertTrue( $this->checker->is_active( 'give-recurring/give-recurring.php' ) );
	}

	public function test_it_reports_an_inactive_plugin(): void {
		$this->setFunctionReturn( 'is_plugin_active', false );

		$this->assertFalse( $this->checker->is_active( 'give-recurring/give-recurring.php' ) );
	}

	/**
	 * The basename is what reaches deactivate_plugins() next, so asserting only the return value
	 * would let the wrong plugin be turned off unnoticed.
	 */
	public function test_it_passes_the_basename_through_to_wordpress(): void {
		$received = null;

		$this->setFunctionReturn(
			'is_plugin_active',
			static function ( $basename ) use ( &$received ) {
				$received = $basename;

				return true;
			},
			true
		);

		$this->checker->is_active( 'give-recurring/give-recurring.php' );

		$this->assertSame( 'give-recurring/give-recurring.php', $received );
	}

	/**
	 * is_plugin_active() already ORs in the network check, so one call answers both scopes. A second
	 * get_site_option() per sub-plugin per request would buy nothing.
	 */
	public function test_the_active_check_costs_one_call(): void {
		$calls = 0;

		$this->setFunctionReturn(
			'is_plugin_active',
			static function () use ( &$calls ) {
				++$calls;

				return true;
			},
			true
		);
		$this->setFunctionReturn(
			'is_plugin_active_for_network',
			static function () {
				throw new LogicException( 'The network check is redundant and must not be called.' );
			},
			true
		);

		$this->checker->is_active( 'give-recurring/give-recurring.php' );

		$this->assertSame( 1, $calls );
	}
}
