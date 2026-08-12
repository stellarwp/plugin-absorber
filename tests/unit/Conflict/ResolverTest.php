<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Redirector;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Queue;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithHaltedRedirects;

/**
 * What the default resolver does once a conflict has been found, policy branch by policy branch.
 *
 * Who is allowed to have a conflict resolved is not asked here: that is `Conflict\Gatekeeper`, and it
 * is settled before this class is built at all. So every test in this file is already past the gate.
 *
 * The four collaborators are required constructor arguments, so the resolver under test is the one the
 * container builds — the same object a host gets. A test that is about who the resolver talks to binds
 * its own double before the provider runs; the rest let the real ones through and assert on what they
 * left behind.
 *
 * @since 1.0.0
 */
class ResolverTest extends WPTestCase {
	use UopzFunctions;
	use WithContainer;
	use WithHaltedRedirects;

	/**
	 * Every deactivate_plugins() call, as the arguments it was made with.
	 *
	 * Recorded from the function rather than from a double, because two of these tests are about the
	 * arguments core is left to default — which a double would only restate.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $deactivations = [];

	/**
	 * @var string|null
	 */
	private $request_method;

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->clear_notices();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->deactivations = [];

		// Set explicitly rather than inherited from the harness. Nothing here is gated on the request
		// method any more, but wp_get_referer() reads $_REQUEST and $_SERVER, so the tests that assert
		// a destination depend on the request looking like the one they describe.
		$this->request_method      = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// uopz runs a replacement with no class scope, so $this and self:: are both fatal inside this
		// closure. Bind a reference to the property instead. See tests/README.md.
		$deactivations = &$this->deactivations;

		$this->setFunctionReturn(
			'deactivate_plugins',
			static function ( $plugins, $silent = false, $network_wide = null ) use ( &$deactivations ) {
				$deactivations[] = [
					'plugins'      => $plugins,
					'silent'       => $silent,
					'network_wide' => $network_wide,
				];
			},
			true
		);
	}

	public function tearDown(): void {
		if ( $this->request_method === null ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

		$this->clear_notices();
		Loader_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_the_loader_resolves_the_default_resolver(): void {
		$this->assertInstanceOf( Resolver::class, Loader::resolver() );
	}

	public function test_the_default_resolver_satisfies_the_contract(): void {
		$this->assertInstanceOf( Resolver_Interface::class, $this->resolver() );
	}

	/**
	 * The point of the required constructor arguments. Every peer arrives from the container, so a host
	 * that rebinds one has it reached — and nothing in the run touches the option the default notice
	 * queue is backed by, which is what makes the resolver testable without standing up global state.
	 */
	public function test_the_collaborators_come_from_the_container(): void {
		$checker = new class() implements Plugin_Checker_Interface {
			/**
			 * @var string[]
			 */
			public $asked = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return bool
			 */
			public function is_active( string $basename ): bool {
				$this->asked[] = $basename;

				return true;
			}
		};

		$deactivator = new class() implements Plugin_Deactivator_Interface {
			/**
			 * @var string[]
			 */
			public $deactivated = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return void
			 */
			public function deactivate( string $basename ): void {
				$this->deactivated[] = $basename;
			}
		};

		$notices = new Spy_Queue();

		$container = new Test_Container();
		$container->singleton(
			Plugin_Checker_Interface::class,
			static function () use ( $checker ): Plugin_Checker_Interface {
				return $checker;
			}
		);
		$container->singleton(
			Plugin_Deactivator_Interface::class,
			static function () use ( $deactivator ): Plugin_Deactivator_Interface {
				return $deactivator;
			}
		);
		$container->singleton(
			Queue_Interface::class,
			static function () use ( $notices ): Queue_Interface {
				return $notices;
			}
		);
		$this->set_up_container( $container );

		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', admin_url( 'plugins.php' ) );

		$this->resolve_all();

		$this->assertSame( [ 'give-recurring/give-recurring.php' ], $checker->asked );
		$this->assertSame( [ 'give-recurring/give-recurring.php' ], $deactivator->deactivated );
		$this->assertSame( [ 'give-recurring' ], $notices->merge_notices );
		$this->assertSame(
			[],
			$this->queued_notices(),
			'The bound queue stands in for the option-backed one, which must be left untouched.'
		);
		$this->assertSame(
			[],
			$this->deactivations,
			'A bound deactivator is what deactivates; WordPress must not have been called as well.'
		);
	}

	public function test_it_asks_the_redirector_it_was_given(): void {
		$redirector = new class() extends Redirector {
			/**
			 * @var array<int,string|false>
			 */
			public $asked = [];

			/**
			 * @param string|false $referrer Referrer under test.
			 *
			 * @return string|false
			 */
			public function after_deactivation( $referrer ) {
				$this->asked[] = $referrer;

				return admin_url( 'tools.php' );
			}
		};

		$container = new Test_Container();
		$container->singleton(
			Redirector::class,
			static function () use ( $redirector ): Redirector {
				return $redirector;
			}
		);
		$this->set_up_container( $container );

		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', admin_url( 'options-general.php' ) );

		$location = $this->capture_resolution();

		$this->assertSame( [ admin_url( 'options-general.php' ) ], $redirector->asked );
		$this->assertSame( admin_url( 'tools.php' ), $location );
	}

	public function test_deactivate_deactivates_notifies_and_redirects(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );
		$this->setFunctionReturn( 'wp_get_referer', false );

		$location = $this->capture_resolution();

		$this->assertCount( 1, $this->deactivations );
		$this->assertSame( 'give-recurring/give-recurring.php', $this->deactivations[0]['plugins'] );
		$this->assertArrayHasKey( 'give-recurring:merge', $this->queued_notices() );

		// The destination, not merely that a redirect happened: a resolver that redirected somewhere
		// else entirely would satisfy the count without sending anyone anywhere useful.
		$this->assertSame( admin_url( 'plugins.php' ), $location );
	}

	public function test_deactivate_is_the_default_policy(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->capture_resolution();

		$this->assertCount( 1, $this->deactivations );
	}

	/**
	 * Silent, and with no $network_wide argument — core's default of null is what handles both
	 * scopes, and the standalone's deactivation hook must not run at plugins_loaded.
	 *
	 * Asserted here as well as in PluginDeactivatorTest, because this is the path that actually
	 * deactivates a site's plugin: a resolver that reached WordPress by some other route would leave
	 * that unit test green and the site 404ing.
	 */
	public function test_it_deactivates_silently_and_lets_core_decide_the_scope(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->capture_resolution();

		$this->assertTrue( $this->deactivations[0]['silent'], 'An unattended deactivation must be silent.' );
		$this->assertNull(
			$this->deactivations[0]['network_wide'],
			'Core enters the network branch on false !== $network_wide and the blog branch on true !== $network_wide, so null takes both.'
		);
	}

	/**
	 * Against real core rather than a stub, because the whole reason the scope argument was
	 * dropped is a claim about what core does with the default.
	 */
	public function test_it_really_deactivates_a_site_active_standalone(): void {
		$this->unsetFunctionReturn( 'deactivate_plugins' );

		$basename = 'absorber-fixture/absorber-fixture.php';
		update_option( 'active_plugins', [ $basename ] );

		$this->register( [ 'standalone_plugin_basename' => $basename ] );
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->capture_resolution();

		$this->assertNotContains( $basename, (array) get_option( 'active_plugins', [] ) );

		delete_option( 'active_plugins' );
	}

	public function test_it_really_deactivates_a_network_active_standalone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network activation only exists on multisite.' );
		}

		$this->unsetFunctionReturn( 'deactivate_plugins' );

		$basename = 'absorber-fixture/absorber-fixture.php';
		update_site_option( 'active_sitewide_plugins', [ $basename => time() ] );

		$this->register( [ 'standalone_plugin_basename' => $basename ] );
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->capture_resolution();

		$this->assertArrayNotHasKey(
			$basename,
			(array) get_site_option( 'active_sitewide_plugins', [] ),
			'Omitting $network_wide must still clear a network activation.'
		);

		delete_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * The notice is queued after the deactivation, so it must not depend on the plugin still
	 * being active — and it is the only record the site owner gets.
	 */
	public function test_the_merge_notice_is_queued_before_the_redirect_halts_the_request(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->capture_resolution();

		$this->assertArrayHasKey( 'give-recurring:merge', $this->queued_notices() );
	}

	public function test_defer_does_nothing_at_all(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEFER ] );

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame( [], $this->queued_notices() );
	}

	public function test_notice_only_notifies_without_deactivating(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::NOTICE_ONLY ] );

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
		$this->assertArrayHasKey( 'give-recurring:conflict', $this->queued_notices() );
	}

	/**
	 * A policy read from an option, or returned by someone else's filter, can be anything.
	 * Falling through to the destructive branch on a typo would turn off a plugin the site owner
	 * deliberately activated.
	 *
	 * @dataProvider unknown_policies
	 *
	 * @param string $policy Policy under test.
	 */
	public function test_an_unknown_policy_takes_the_conservative_branch( string $policy ): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => $policy ] );

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations, 'An unrecognised policy must never deactivate.' );
		$this->assertArrayHasKey( 'give-recurring:conflict', $this->queued_notices() );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function unknown_policies(): Generator {
		yield 'typo'       => [ 'defered' ];
		yield 'empty'      => [ '' ];
		yield 'wrong case' => [ 'DEACTIVATE' ];
	}

	public function test_a_callable_policy_selects_the_branch(): void {
		$this->standalone_is( true );
		$this->register(
			[
				'conflict_policy' => static function ( Sub_Plugin $sub_plugin ) {
					return $sub_plugin->get_slug() === 'give-recurring'
						? Conflict_Policy::DEFER
						: Conflict_Policy::DEACTIVATE;
				},
			]
		);

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations, 'The callable chose DEFER for this slug.' );
	}

	public function test_the_filter_can_override_the_policy(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function () {
				return Conflict_Policy::DEFER;
			}
		);

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->standalone_is( true );
		$this->register( [ 'enabled' => false ] );

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame( [], $this->queued_notices(), 'A skipped sub-plugin has nothing to say to the site owner.' );
	}

	public function test_it_skips_when_the_standalone_is_not_active(): void {
		$this->standalone_is( false );
		$this->register();

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame( [], $this->queued_notices(), 'There is no conflict to report when the standalone is gone.' );
	}

	public function test_it_skips_a_sub_plugin_with_no_standalone(): void {
		$this->standalone_is( true );
		Loader::register(
			[
				'slug'                   => 'give-fee-recovery',
				'bundled_plugin_file'    => '/tmp/give-fee-recovery.php',
				'plugin_loaded_constant' => 'GIVE_FEE_RECOVERY_VERSION_FIXTURE',
			]
		);

		$this->resolve_all();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame(
			[],
			$this->queued_notices(),
			'A sub-plugin that names no standalone can never be in conflict with one.'
		);
	}

	/**
	 * Where a referrer sends the user is the redirector's own decision and is covered case by case in
	 * RedirectorTest. What belongs here is that the resolver asks it and honours a false.
	 */
	public function test_it_redirects_to_where_the_redirector_says(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', admin_url( 'options-general.php' ) );

		$this->assertSame( admin_url( 'options-general.php' ), $this->capture_resolution() );
	}

	/**
	 * A false from the redirector means stay put, and staying put has to include not ending the
	 * request. `resolve_all()` fails on a redirect, which is what makes the absence of one an
	 * assertion rather than an assumption — coming from the plugins list there is nothing to send the
	 * user back to.
	 */
	public function test_it_deactivates_without_redirecting_from_the_plugins_page(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', admin_url( 'plugins.php' ) );

		$this->resolve_all();

		$this->assertCount( 1, $this->deactivations );
	}

	public function test_resolve_all_needs_a_hook_prefix(): void {
		$this->standalone_is( true );
		$this->register();

		$resolver  = $this->resolver();
		$container = $this->container();

		// The prefix goes, the container stays: this is about the missing prefix, and a resolver that
		// could not reach its registrar would throw the same exception for the other reason.
		Config_State::reset();
		Config::set_container( $container );

		$this->expectException( Config_Exception::class );

		$resolver->resolve_all();
	}

	/**
	 * The resolver the container builds, which is the one the conflict step reaches.
	 *
	 * @return Resolver_Interface
	 */
	private function resolver(): Resolver_Interface {
		return $this->resolve( Resolver_Interface::class );
	}

	/**
	 * Run resolution on a path that must not end the request.
	 *
	 * The redirect is stubbed to throw rather than left alone: unstubbed, a resolver that redirected
	 * anyway would reach the real `wp_safe_redirect()` and the `exit` behind it, taking the whole test
	 * process with it. Throwing instead turns that into one failed test naming the path it happened on.
	 *
	 * @return void
	 */
	private function resolve_all(): void {
		$message = 'The resolver must not end the request on this path.';

		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () use ( $message ) {
				throw new TestException( $message );
			},
			true
		);

		try {
			$this->resolver()->resolve_all();
		} catch ( TestException $exception ) {
			$this->fail( $exception->getMessage() );
		} finally {
			// In a finally block so a failed assertion cannot strand the stub for the rest of the
			// process, where a later test's redirect would throw for no reason it can see.
			$this->unsetFunctionReturn( 'wp_safe_redirect' );
		}
	}

	/**
	 * Run resolution on a path that must redirect and terminate, and return where it sent the user.
	 *
	 * @return string
	 */
	private function capture_resolution(): string {
		$resolver = $this->resolver();

		return $this->capture_redirect(
			static function () use ( $resolver ): void {
				$resolver->resolve_all();
			}
		);
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 *
	 * @return void
	 */
	private function register( array $overrides = [] ): void {
		Loader::register(
			array_merge(
				[
					'slug'                       => 'give-recurring',
					'bundled_plugin_file'        => '/tmp/give-recurring.php',
					'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION_FIXTURE',
					'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
				],
				$overrides
			)
		);
	}

	/**
	 * Only is_plugin_active(), which is the one function Plugin_Checker::is_active() calls — and it
	 * ORs the network check in itself, so stubbing is_plugin_active_for_network() alongside it
	 * would be inert and would read as though a network path were being exercised.
	 *
	 * @param bool $active Whether the standalone is active.
	 *
	 * @return void
	 */
	private function standalone_is( bool $active ): void {
		$this->setFunctionReturn( 'is_plugin_active', $active );
	}

	private function clear_notices(): void {
		delete_site_option( 'give_plugin_absorber_notices' );
	}

	/**
	 * The queue is stored as a site option on every install — on single site that call falls through
	 * to the plain option table — so there is one place to read it from.
	 *
	 * @return array<string,string>
	 */
	private function queued_notices(): array {
		$queue = get_site_option( 'give_plugin_absorber_notices', [] );

		return is_array( $queue ) ? $queue : [];
	}
}
