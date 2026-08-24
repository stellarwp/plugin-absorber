<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Plugin;

use Codeception\TestCase\WPTestCase;
use LogicException;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Plugin\Checker;
use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithPluginFunctions;

/**
 * Asking WordPress whether a plugin is active.
 *
 * Split from the deactivator because the two answer to different callers: a host rebinding the check
 * — which `learndash-core` has to, since it filters `option_active_plugins` so `is_plugin_active()`
 * does not report what is in the database — should not have to reimplement a deactivation to do it.
 *
 * `Plugin\Loads_Plugin_Functions` is exercised here too, through the caller that reaches it on every
 * request. The trait is two lines and no state, so it earns no file of its own — but which function
 * name its guard tests is an invariant, and this is one of the two callers that ask it the question.
 * `DeactivatorTest` is the other, and both ask through `WithPluginFunctions`.
 *
 * @since 1.0.0
 */
class CheckerTest extends WPTestCase {
	use UopzFunctions;
	use WithPluginFunctions;

	/**
	 * @var Checker
	 */
	private $checker;

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->checker = new Checker();

		$this->forget_plugin_functions_loads();
	}

	public function tearDown(): void {
		$this->tear_down_plugin_functions();

		parent::tearDown();
	}

	public function test_it_implements_the_contract(): void {
		$this->assertInstanceOf( Checker_Interface::class, $this->checker );
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

	public function test_it_reports_a_network_active_plugin(): void {
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$this->assertTrue( $this->checker->is_network_active( 'give-recurring/give-recurring.php' ) );
	}

	public function test_it_reports_a_plugin_that_is_not_network_active(): void {
		$this->setFunctionReturn( 'is_plugin_active_for_network', false );

		$this->assertFalse( $this->checker->is_network_active( 'give-recurring/give-recurring.php' ) );
	}

	/**
	 * The basename reaches the stranding guard's comparison next, so asserting only the return value
	 * would let the wrong plugin decide whether a network-wide deactivation is safe.
	 */
	public function test_it_passes_the_basename_through_to_the_network_check(): void {
		$received = null;

		$this->setFunctionReturn(
			'is_plugin_active_for_network',
			static function ( $basename ) use ( &$received ) {
				$received = $basename;

				return true;
			},
			true
		);

		$this->checker->is_network_active( 'give-recurring/give-recurring.php' );

		$this->assertSame( 'give-recurring/give-recurring.php', $received );
	}

	/**
	 * The mirror of the single-scope check: is_network_active() asks the network function and only
	 * that one. is_plugin_active() ORs the network check into its answer, so reaching for it here
	 * would report a merely site-active standalone as network-active and wave through a network-wide
	 * deactivation the stranding guard exists to refuse.
	 */
	public function test_the_network_check_asks_only_the_network_function(): void {
		$this->setFunctionReturn(
			'is_plugin_active',
			static function () {
				throw new LogicException( 'is_network_active() must not ask the site-scope function.' );
			},
			true
		);
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$this->assertTrue( $this->checker->is_network_active( 'give-recurring/give-recurring.php' ) );
	}

	/**
	 * `Plugin\Loads_Plugin_Functions` guards on `deactivate_plugins()`, and it has to keep doing so.
	 * `is_plugin_active()` is a common third-party shim: guarded on that name, something else defining
	 * it stands the require down, the rest of `wp-admin/includes/plugin.php` never loads, and the
	 * first call to a function nobody shimmed is a fatal — on a site whose only symptom is having
	 * installed one more plugin. Nothing about the swap is visible until then.
	 *
	 * `WithPluginFunctions` builds the missing-functions state rather than finding it: every test in
	 * this file requires the real file in setUp so uopz has something to stub, a `require_once` cannot
	 * be undone for the rest of the process, and no test may depend on having run before whichever
	 * other one loads it.
	 */
	public function test_it_loads_the_plugin_functions_when_they_are_missing(): void {
		$checker = $this->checker;

		$asked = $this->with_plugin_functions_missing(
			static function () use ( $checker ): void {
				$checker->is_active( 'give-recurring/give-recurring.php' );
			}
		);

		$this->assertSame(
			'deactivate_plugins',
			$asked[0] ?? '',
			'The guard has to ask about the one function no third party shims.'
		);
		$this->assertSame(
			1,
			$this->plugin_functions_loads(),
			'A missing deactivate_plugins() has to pull ABSPATH . wp-admin/includes/plugin.php in.'
		);
	}

	/**
	 * The other half. With the functions already in memory — every admin request, and every request
	 * at all once anything else has loaded them — the guard stands down, so the check does not stat a
	 * file per sub-plugin per request.
	 */
	public function test_it_does_not_reload_the_plugin_functions_when_they_are_there(): void {
		$checker = $this->checker;

		$root = $this->with_plugin_functions_present(
			static function () use ( $checker ): void {
				$checker->is_active( 'give-recurring/give-recurring.php' );
			}
		);

		$this->assertSame( 0, $this->plugin_functions_loads() );

		// The recorder has to be shown to work: a fixture that records nothing at all would leave the
		// same zero however the guard had behaved.
		require_once $root . 'wp-admin/includes/plugin.php';

		$this->assertSame( 1, $this->plugin_functions_loads(), 'The fixture really does record its own load.' );
	}
}
