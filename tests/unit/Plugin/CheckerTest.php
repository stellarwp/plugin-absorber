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
use RuntimeException;

/**
 * Asking WordPress whether a plugin is active.
 *
 * Split from the deactivator because the two answer to different callers: a host rebinding the check
 * — which `learndash-core` has to, since it filters `option_active_plugins` so `is_plugin_active()`
 * does not report what is in the database — should not have to reimplement a deactivation to do it.
 *
 * `Plugin\Loads_Plugin_Functions` is exercised here too, through the caller that reaches it on every
 * request. The trait is two lines and no state, so it earns no file of its own — but which function
 * name its guard tests is an invariant, and this is where a caller asks it the question.
 *
 * @since 1.0.0
 */
class CheckerTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @var Checker
	 */
	private $checker;

	/**
	 * Throwaway WordPress roots written by this test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $wordpress_roots = [];

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->checker = new Checker();

		unset( $GLOBALS['absorber_plugin_functions_loads'] );
	}

	public function tearDown(): void {
		$this->remove_wordpress_roots();

		unset( $GLOBALS['absorber_plugin_functions_loads'] );

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

	/**
	 * `Plugin\Loads_Plugin_Functions` guards on `deactivate_plugins()`, and it has to keep doing so.
	 * `is_plugin_active()` is a common third-party shim: guarded on that name, something else defining
	 * it stands the require down, the rest of `wp-admin/includes/plugin.php` never loads, and the
	 * first call to a function nobody shimmed is a fatal — on a site whose only symptom is having
	 * installed one more plugin. Nothing about the swap is visible until then.
	 *
	 * The missing-functions state is built rather than found. Every test in this file requires the
	 * real file in setUp so uopz has something to stub, a `require_once` cannot be undone for the rest
	 * of the process, and no test may depend on having run before whichever other one loads it — so
	 * ABSPATH is pointed at a fixture root and `function_exists()` answers about that one name the way
	 * it does on a front-end request, where WordPress has loaded none of this.
	 */
	public function test_it_loads_the_plugin_functions_when_they_are_missing(): void {
		$asked = [];
		$root  = $this->make_wordpress_root();

		$restore_root  = $this->setConstant( 'ABSPATH', $root );
		$restore_probe = $this->setFunctionReturn(
			'function_exists',
			static function ( $name ) use ( &$asked ) {
				$asked[] = $name;

				// Every other question is answered as it really stands. is_callable() rather than a
				// recursive function_exists(), which this closure has replaced.
				return $name === 'deactivate_plugins' ? false : is_callable( $name );
			},
			true
		);

		try {
			$this->checker->is_active( 'give-recurring/give-recurring.php' );
		} finally {
			// Undone the moment the call under test returns rather than in tearDown, and in a finally
			// so a throw above cannot strand either one. Both are process-global -- everything in the
			// process reads ABSPATH and asks function_exists(), this test's own assertions included --
			// so the window they are wrong in has to be the call and nothing else. The trait's `@after`
			// is the backstop.
			$restore_probe();
			$restore_root();
		}

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
		$root    = $this->make_wordpress_root();
		$restore = $this->setConstant( 'ABSPATH', $root );

		try {
			$this->checker->is_active( 'give-recurring/give-recurring.php' );
		} finally {
			$restore();
		}

		$this->assertSame( 0, $this->plugin_functions_loads() );

		// The recorder has to be shown to work: a fixture that records nothing at all would leave the
		// same zero however the guard had behaved.
		require_once $root . 'wp-admin/includes/plugin.php';

		$this->assertSame( 1, $this->plugin_functions_loads(), 'The fixture really does record its own load.' );
	}

	/**
	 * Write a WordPress root whose `wp-admin/includes/plugin.php` records that it was included.
	 *
	 * A new root every call. `require_once` dedupes by resolved path for the lifetime of the PHP
	 * process, so a fixture shared between two tests lets the second one pass without including
	 * anything at all.
	 *
	 * @throws RuntimeException When the fixture cannot be written, rather than reporting a load that
	 *                          never had anywhere to happen.
	 *
	 * @return string Root with a trailing slash, as ABSPATH carries.
	 */
	private function make_wordpress_root(): string {
		$root = sys_get_temp_dir() . '/absorber-abspath-' . uniqid( '', true ) . '/';

		if ( ! mkdir( $root . 'wp-admin/includes', 0777, true ) ) {
			throw new RuntimeException( 'Could not write a WordPress root fixture at ' . $root );
		}

		$this->wordpress_roots[] = $root;

		file_put_contents(
			$root . 'wp-admin/includes/plugin.php',
			'<?php' . PHP_EOL
			. '$GLOBALS["absorber_plugin_functions_loads"] = ( $GLOBALS["absorber_plugin_functions_loads"] ?? 0 ) + 1;'
			. PHP_EOL
		);

		return $root;
	}

	/**
	 * How many times a fixture plugin.php has been included this test.
	 *
	 * @return int
	 */
	private function plugin_functions_loads(): int {
		$loads = $GLOBALS['absorber_plugin_functions_loads'] ?? 0;

		return is_int( $loads ) ? $loads : 0;
	}

	/**
	 * @return void
	 */
	private function remove_wordpress_roots(): void {
		foreach ( $this->wordpress_roots as $root ) {
			$file = $root . 'wp-admin/includes/plugin.php';

			if ( file_exists( $file ) ) {
				unlink( $file );
			}

			foreach ( [ 'wp-admin/includes', 'wp-admin', '' ] as $directory ) {
				$path = $root . $directory;

				if ( is_dir( $path ) ) {
					rmdir( $path );
				}
			}
		}

		$this->wordpress_roots = [];
	}
}
