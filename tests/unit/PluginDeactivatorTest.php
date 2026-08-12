<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Plugin_Deactivator;

/**
 * Turning a standalone off.
 *
 * The only destructive thing this library does, and the half of the old `Plugin_State` that mutates.
 *
 * @since 1.0.0
 */
class PluginDeactivatorTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @var Plugin_Deactivator
	 */
	private $deactivator;

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->deactivator = new Plugin_Deactivator();
	}

	public function test_it_implements_the_contract(): void {
		$this->assertInstanceOf( Plugin_Deactivator_Interface::class, $this->deactivator );
	}

	/**
	 * Silent, and with no third argument. A noisy deactivation runs the standalone's own deactivation
	 * hook at plugins_loaded — where a routine flush_rewrite_rules() in it 404s every custom permalink
	 * on the site — and a computed $network_wide would skip one of the two scopes core's null default
	 * covers, stranding an entry for a plugin active in both.
	 */
	public function test_it_deactivates_silently_in_every_scope(): void {
		$received = [];

		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( ...$arguments ) use ( &$received ): void {
				$received = $arguments;
			},
			true
		);

		$this->deactivator->deactivate( 'give-recurring/give-recurring.php' );

		$this->assertSame( [ 'give-recurring/give-recurring.php', true ], $received );
	}
}
