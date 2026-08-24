<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Plugin;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Plugin\Contracts\Deactivator_Interface;
use Nexcess\PluginAbsorber\Plugin\Deactivator;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithPluginFunctions;

/**
 * Turning a standalone off.
 *
 * The only destructive thing this library does, and the half of the old `Plugin_State` that mutates.
 *
 * Half of these tests stub `deactivate_plugins()` and read back what it was handed, which is the only
 * way to assert on an argument that was deliberately *not* passed. The other half lets core's own
 * function run against the real `active_plugins` and `active_sitewide_plugins`, because the argument
 * this class omits is only interesting for what core does with the default in its place — a recorder
 * would go on agreeing with a claim about core that had stopped being true.
 *
 * @since 1.0.0
 */
class DeactivatorTest extends WPTestCase {
	use UopzFunctions;
	use WithPluginFunctions;

	/**
	 * A basename naming no installed plugin, so core's own deactivation has nothing to include.
	 */
	private const STANDALONE = 'absorber-fixture/absorber-fixture.php';

	/**
	 * @var Deactivator
	 */
	private $deactivator;

	public function setUp(): void {
		parent::setUp();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->deactivator = new Deactivator();

		$this->forget_plugin_functions_loads();
	}

	public function tearDown(): void {
		$this->tear_down_plugin_functions();

		// Seeded by the tests that let core write for real. Cleared here rather than at the end of the
		// test body so a failed assertion cannot leave a fixture basename in a live option for
		// whatever runs next in the process.
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );

		parent::tearDown();
	}

	public function test_it_implements_the_contract(): void {
		$this->assertInstanceOf( Deactivator_Interface::class, $this->deactivator );
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

		$this->deactivator->deactivate( self::STANDALONE );

		$this->assertSame( [ self::STANDALONE, true ], $received );
	}

	/**
	 * The deactivator runs at plugins_loaded on requests WordPress has loaded no admin file for, so the
	 * guard in `Plugin\Loads_Plugin_Functions` is the reason `deactivate_plugins()` exists to be called
	 * at all. Nothing else in the suite proves it: every test that reaches a deactivator requires the
	 * real file in its own setUp, and a `require_once` is process-wide, so deleting the guarded require
	 * from this class leaves the suite green and the first front-end conflict fatal on an undefined
	 * function.
	 *
	 * Which name the guard asks about is the second half. `is_plugin_active()` is a common third-party
	 * shim, so a guard on that name is stood down by somebody else's plugin, the rest of
	 * `wp-admin/includes/plugin.php` never loads, and the call below is the fatal.
	 */
	public function test_it_loads_the_plugin_functions_when_they_are_missing(): void {
		$received    = [];
		$deactivator = $this->deactivator;
		$standalone  = self::STANDALONE;

		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( ...$arguments ) use ( &$received ): void {
				$received = $arguments;
			},
			true
		);

		$asked = $this->with_plugin_functions_missing(
			static function () use ( $deactivator, $standalone ): void {
				$deactivator->deactivate( $standalone );
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
		$this->assertSame(
			[ $standalone, true ],
			$received,
			'Loading the functions is the step before the deactivation, not instead of it.'
		);
	}

	/**
	 * The other half. In the admin, and on any request where something else has already loaded the
	 * file, the guard stands down rather than stat-ing it again per conflicted sub-plugin.
	 */
	public function test_it_does_not_reload_the_plugin_functions_when_they_are_there(): void {
		$received    = [];
		$deactivator = $this->deactivator;
		$standalone  = self::STANDALONE;

		// Stubbed rather than left to core, so that nothing is written to a live option while ABSPATH
		// points somewhere that is not a WordPress installation.
		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( ...$arguments ) use ( &$received ): void {
				$received = $arguments;
			},
			true
		);

		$root = $this->with_plugin_functions_present(
			static function () use ( $deactivator, $standalone ): void {
				$deactivator->deactivate( $standalone );
			}
		);

		$this->assertSame( 0, $this->plugin_functions_loads() );
		$this->assertSame( [ $standalone, true ], $received, 'The deactivation itself still happened.' );

		// The counter has to be shown to work: a fixture that records nothing at all would leave the
		// same zero however the guard had behaved.
		require_once $root . 'wp-admin/includes/plugin.php';

		$this->assertSame( 1, $this->plugin_functions_loads(), 'The fixture really does record its own load.' );
	}

	/**
	 * Against core's own deactivate_plugins() and the real option, because everything above this asserts
	 * on a recorder — and a recorder agrees with the arguments it was handed whatever WordPress would
	 * have done with them.
	 */
	public function test_it_really_deactivates_a_site_active_plugin(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->assertContains(
			self::STANDALONE,
			$this->active_plugins(),
			'The plugin has to be active before deactivating it can mean anything.'
		);

		$this->deactivator->deactivate( self::STANDALONE );

		$this->assertNotContains( self::STANDALONE, $this->active_plugins() );
	}

	/**
	 * The topology the omitted $network_wide is *for*: a standalone active network-wide and, separately,
	 * on this site. Core enters its network branch on `false !== $network_wide` and its blog branch on
	 * `true !== $network_wide`, so the null default is the one value that takes both — a computed true
	 * clears the network entry and leaves this site's, which then loads the standalone again on the very
	 * next request and takes a second deactivation to clear.
	 *
	 * One call, both assertions. Splitting it into a site case and a network case is what lets a true
	 * pass: each of those is green on the branch it names.
	 */
	public function test_it_really_clears_both_scopes_in_one_call(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network activation only exists on multisite.' );
		}

		update_option( 'active_plugins', [ self::STANDALONE ] );
		update_site_option( 'active_sitewide_plugins', [ self::STANDALONE => time() ] );

		$this->assertContains( self::STANDALONE, $this->active_plugins(), 'Seeded site activation.' );
		$this->assertArrayHasKey(
			self::STANDALONE,
			$this->network_active_plugins(),
			'Seeded network activation.'
		);

		$this->deactivator->deactivate( self::STANDALONE );

		$this->assertArrayNotHasKey(
			self::STANDALONE,
			$this->network_active_plugins(),
			'Omitting $network_wide must still clear a network activation.'
		);
		$this->assertNotContains(
			self::STANDALONE,
			$this->active_plugins(),
			'And must not strand the site entry a computed true would have skipped.'
		);
	}

	/**
	 * What WordPress holds as active on this site, as the real option holds it.
	 *
	 * @return array<mixed>
	 */
	private function active_plugins(): array {
		return (array) get_option( 'active_plugins', [] );
	}

	/**
	 * What WordPress holds as active across the network, keyed by basename.
	 *
	 * @return array<mixed>
	 */
	private function network_active_plugins(): array {
		return (array) get_site_option( 'active_sitewide_plugins', [] );
	}
}
