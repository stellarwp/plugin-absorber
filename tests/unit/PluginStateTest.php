<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Contracts\Plugin_State_Interface;
use Nexcess\PluginAbsorber\Plugin_State;

/**
 * @since 1.0.0
 */
class PluginStateTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @var Plugin_State
	 */
	private $plugin_state;

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->plugin_state = new Plugin_State();
	}

	public function test_it_implements_the_contract(): void {
		$this->assertInstanceOf( Plugin_State_Interface::class, $this->plugin_state );
	}

	/**
	 * The loader builds every unbound collaborator with a bare `new`, so a constructor that grew a
	 * required argument would fatal at plugins_loaded rather than here.
	 */
	public function test_it_constructs_without_arguments(): void {
		$this->assertInstanceOf( Plugin_State::class, new Plugin_State() );
	}

	public function test_it_reports_an_active_plugin(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );

		$this->assertTrue( $this->plugin_state->is_active( 'give-recurring/give-recurring.php' ) );
	}

	public function test_it_reports_an_inactive_plugin(): void {
		$this->setFunctionReturn( 'is_plugin_active', false );

		$this->assertFalse( $this->plugin_state->is_active( 'give-recurring/give-recurring.php' ) );
	}

	/**
	 * The basename is what reaches deactivate_plugins(), so asserting only the return value would
	 * let the wrong plugin be turned off unnoticed.
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

		$this->plugin_state->is_active( 'give-recurring/give-recurring.php' );

		$this->assertSame( 'give-recurring/give-recurring.php', $received );
	}

	/**
	 * is_plugin_active() already ORs in the network check, so one call answers both scopes. A
	 * second get_site_option() per sub-plugin per request would buy nothing.
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
				throw new \LogicException( 'The network check is redundant and must not be called.' );
			},
			true
		);

		$this->plugin_state->is_active( 'give-recurring/give-recurring.php' );

		$this->assertSame( 1, $calls );
	}

	/**
	 * Silent, and with no third argument. A noisy deactivation runs the standalone's own
	 * deactivation hook at plugins_loaded, and a computed $network_wide would skip one of the two
	 * scopes core's null default covers.
	 */
	public function test_it_deactivates_silently_in_every_scope(): void {
		$received = [];

		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( ...$arguments ) use ( &$received ) {
				$received = $arguments;
			},
			true
		);

		$this->plugin_state->deactivate( 'give-recurring/give-recurring.php' );

		$this->assertSame( [ 'give-recurring/give-recurring.php', true ], $received );
	}
}
