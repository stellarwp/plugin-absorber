<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict\Redirector;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Plugin\Contracts\Deactivator_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Writer;
use Nexcess\PluginAbsorber\Tests\Support\Stub_Registry_Reader;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithHaltedRedirects;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithNoticeQueue;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithRequestMethod;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use RuntimeException;

/**
 * What the default resolver does once a conflict has been found, policy branch by policy branch.
 *
 * Who is allowed to have a conflict resolved is not asked here: that is `Conflict\Gatekeeper`, and it
 * is settled before `resolve_all()` is called at all. So every resolution test in this file is already
 * past the gate. Whether there is a conflict to resolve is not asked here either — `Conflict\Detector`
 * answers that, on its own contract, and `DetectorTest` is where both the answer and the fact that
 * asking changes nothing are pinned.
 *
 * The four collaborators are required constructor arguments, so the resolver under test is the one the
 * container builds — the same object a host gets. A test that is about who the resolver talks to binds
 * its own double, and *when* it binds depends on the kind of id: the provider stands down for an id
 * the container could not answer unprompted, which is true of an interface and false of a class. So an
 * interface seam is bound before the provider runs, exactly as a host would, and a concrete class after
 * it, because `has()` is true for any class that exists and the provider rebinds it regardless. The
 * rest let the real ones through and assert on what they left behind.
 *
 * @since 1.0.0
 */
class ResolverTest extends WPTestCase {
	use UopzFunctions;
	use WithContainer;
	use WithHaltedRedirects;
	use WithNoticeQueue;
	use WithIncorrectUsage;
	use WithRequestMethod;
	use WithSubPlugins;

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
	 * Standalones the stubbed WordPress keeps reporting as active however often they are deactivated.
	 *
	 * The site that filters `option_active_plugins` to put a plugin back, the host that rebound the
	 * deactivator to a no-op, and the rebound checker that means something else by "active" all look
	 * like this from in here: `deactivate_plugins()` was called and the plugin is still running.
	 *
	 * @var string[]
	 */
	private $immovable_standalones = [];

	/**
	 * @var string|null
	 */
	private $request_uri;

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();
		$this->clear_notices();

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->deactivations         = [];
		$this->immovable_standalones = [];

		// The request the whole file describes: an admin GET of an ordinary screen. Both keys are set
		// rather than inherited, because the redirect reads REQUEST_URI for the screen to re-request and
		// $_SERVER outlives whichever test wrote to it last. The method itself gates nothing here — that
		// is the gatekeeper's business — but a request with a destination and no method is not one.
		$this->set_request_method( 'GET' );
		$this->request_uri      = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php';

		// Stated rather than inherited, because under CLI the real answer is whatever the test runner
		// has printed so far: PHP records headers as sent the moment anything reaches the output layer,
		// so an unstubbed headers_sent() would make every redirect assertion in this file depend on
		// which printer PHPUnit was configured with. The test that is about the sent-headers branch says
		// so itself.
		$this->setFunctionReturn( 'headers_sent', false );

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
		if ( $this->request_uri === null ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		$this->stop_expecting_incorrect_usage();
		$this->restore_request_method();
		$this->clear_notices();
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_the_loader_resolves_the_default_resolver(): void {
		$this->assertInstanceOf( Resolver::class, Absorber::resolver() );
	}

	public function test_the_default_resolver_satisfies_the_contract(): void {
		$this->assertInstanceOf( Resolver_Interface::class, $this->resolver() );
	}

	/**
	 * The point of the required constructor arguments. Every peer arrives from the container, so a host
	 * that rebinds one has it reached — and nothing in the run touches the option the default notice
	 * queue is backed by, which is what makes the resolver testable without standing up global state.
	 *
	 * Detection arrives as a peer like any other, which is the shape the resolver gained when the probe
	 * left its contract: how a conflict is found is `Conflict\Detector`'s to change, and what to do
	 * about one is this class's.
	 */
	/**
	 * The registry is a peer like the other four, and this is what that buys: the sub-plugin
	 * resolved here was never registered, so the run reaches no buffer and no registrar. A resolver
	 * that read the facade instead would find nothing to resolve and deactivate nothing.
	 */
	public function test_it_resolves_the_registry_it_was_handed(): void {
		$reader = new Stub_Registry_Reader(
			[
				$this->make_sub_plugin(
					[ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ]
				),
			]
		);

		// After the provider, because a class id is one the container reports it can build whether or
		// not anybody bound it -- so the provider binds its own regardless, and a double put in first
		// would be silently replaced.
		$this->container()->singleton(
			Reader::class,
			static function () use ( $reader ): Reader {
				return $reader;
			}
		);

		$this->standalone_is( true );

		$this->capture_resolution();

		$this->assertSame(
			[ 'give-recurring/give-recurring.php' ],
			array_column( $this->deactivations, 'plugins' ),
			'The standalone deactivated has to be the one the reader handed over.'
		);
		$this->assertSame(
			[],
			Absorber::registrar()->all(),
			'Nothing was ever registered: the whole run came off the injected reader.'
		);
	}

	/**
	 * The policy may be a host callable, the notice message is another, and `deactivate_plugins()`
	 * runs the standalone's own deactivation hook — all of it arbitrary code, all of it inside
	 * `plugins_loaded`. A throw from one sub-plugin must not cost the site the admin screen it would
	 * be fixed from, nor leave a second standalone running with nothing said about it.
	 */
	public function test_a_sub_plugin_that_throws_does_not_stop_the_others(): void {
		$this->expect_incorrect_usage();
		$this->standalone_is( true );

		$this->register(
			[
				'conflict_policy' => static function (): string {
					throw new RuntimeException( 'the policy option could not be read' );
				},
			]
		);
		$this->register_fee_recovery();

		$this->capture_resolution();

		$this->assertSame(
			[ 'give-fee-recovery/give-fee-recovery.php' ],
			array_column( $this->deactivations, 'plugins' ),
			'The sub-plugin behind the one that threw still has to be resolved.'
		);
		$this->assert_the_library_reported_incorrect_usage();
	}

	public function test_the_collaborators_come_from_the_container(): void {
		$detector = new class() extends Detector {
			/**
			 * @var string[]
			 */
			public $asked = [];

			/**
			 * No plugin checker, and no parent constructor call. What this class stands in for is the
			 * answer, not the way it is reached — and how a detector reaches one is DetectorTest's.
			 */
			public function __construct() {
			}

			/**
			 * In conflict when first asked about a sub-plugin, and gone when asked again — which is
			 * what the bound deactivator standing between the two questions is meant to have
			 * accomplished. Frozen at true it would describe a standalone that survived, which is
			 * `test_a_standalone_that_survives_deactivation_does_not_redirect`'s subject and not this
			 * test's.
			 *
			 * @param Sub_Plugin $sub_plugin Sub-plugin to test.
			 *
			 * @return bool
			 */
			public function is_in_conflict( Sub_Plugin $sub_plugin ): bool {
				$slug      = $sub_plugin->get_slug();
				$first_ask = ! in_array( $slug, $this->asked, true );

				$this->asked[] = $slug;

				return $first_ask;
			}
		};

		$deactivator = new class() implements Deactivator_Interface {
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

		$notices = new Spy_Writer();

		// The interface seams go in first, which is where a host binds them and where the guarantee
		// lives: nothing can build an interface unprompted, so the container answering to one means a
		// binding was made, and the provider leaves it alone.
		$container = new Test_Container();
		$container->singleton(
			Deactivator_Interface::class,
			static function () use ( $deactivator ): Deactivator_Interface {
				return $deactivator;
			}
		);
		$container->singleton(
			Writer_Interface::class,
			static function () use ( $notices ): Writer_Interface {
				return $notices;
			}
		);

		$this->set_up_container( $container );

		// The detector is a concrete class, so the same question has no answer before the provider
		// runs: the container reports it can return a Detector whether or not anyone bound one, and
		// the provider cannot tell a deliberate binding from mere autowirability. It therefore binds
		// its own, and a double put in above would be silently replaced by the real class — asserted
		// against here as a detector that was never asked.
		$container->singleton(
			Detector::class,
			static function () use ( $detector ): Detector {
				return $detector;
			}
		);

		$this->register();

		$this->capture_resolution();

		// Twice: once to find the conflict, and once after the deactivation to find out whether it is
		// still there. The second ask is what decides the redirect.
		$this->assertSame( [ 'give-recurring', 'give-recurring' ], $detector->asked );
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

	/**
	 * The redirector is handed the current request, unslashed — not the referrer. What the user is
	 * looking at is what has to be re-rendered without the standalone's code in memory, and core slashes
	 * $_SERVER on the way in, so a query string with an apostrophe in it would otherwise gain a
	 * backslash every time a conflict was resolved.
	 */
	public function test_it_asks_the_redirector_where_to_send_the_current_request(): void {
		$redirector = new class() extends Redirector {
			/**
			 * @var string[]
			 */
			public $asked = [];

			/**
			 * @param mixed $request_uri Request URI under test.
			 *
			 * @return string
			 */
			public function after_deactivation( $request_uri ): string {
				$this->asked[] = is_string( $request_uri ) ? $request_uri : '';

				return admin_url( 'tools.php' );
			}
		};

		// Bound after the provider has run, which for a concrete class is the only order that leaves the
		// double in place: the container reports it can return a `Redirector` whether or not one was
		// ever bound, so the provider cannot read an existing answer as a host's choice and binds its
		// own over the top. Binding first — the order an interface seam wants — would hand the real
		// redirector to the resolver and leave `$redirector->asked` empty for a reason no assertion
		// here would name.
		$container = $this->set_up_container();
		$container->singleton(
			Redirector::class,
			static function () use ( $redirector ): Redirector {
				return $redirector;
			}
		);

		$this->standalone_is( true );
		$this->register();
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?s=O\\\'Brien';

		$location = $this->capture_resolution();

		$this->assertSame( [ '/wp-admin/edit.php?s=O\'Brien' ], $redirector->asked );
		$this->assertSame( admin_url( 'tools.php' ), $location );
	}

	/**
	 * A request with no REQUEST_URI is not hypothetical: the too-late fallback runs this sequence
	 * inline, and a host may have booted from something that never set one.
	 */
	public function test_it_survives_a_request_with_no_uri(): void {
		$redirector = new class() extends Redirector {
			/**
			 * @var string[]
			 */
			public $asked = [];

			/**
			 * @param mixed $request_uri Request URI under test.
			 *
			 * @return string
			 */
			public function after_deactivation( $request_uri ): string {
				$this->asked[] = is_string( $request_uri ) ? $request_uri : '';

				return admin_url( 'plugins.php' );
			}
		};

		// After the provider, for the reason the test above states: a concrete class id is one the
		// provider rebinds whatever was there before it.
		$container = $this->set_up_container();
		$container->singleton(
			Redirector::class,
			static function () use ( $redirector ): Redirector {
				return $redirector;
			}
		);

		$this->standalone_is( true );
		$this->register();
		unset( $_SERVER['REQUEST_URI'] );

		$location = $this->capture_resolution();

		$this->assertSame( [ '' ], $redirector->asked );
		$this->assertSame( admin_url( 'plugins.php' ), $location );
	}

	public function test_deactivate_deactivates_notifies_and_redirects(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		$location = $this->capture_resolution();

		$this->assertCount( 1, $this->deactivations );
		$this->assertSame( 'give-recurring/give-recurring.php', $this->deactivations[0]['plugins'] );
		$this->assertArrayHasKey( 'give-recurring:merge', $this->queued_notices() );

		// Through the real redirector, so the screen the user was on is the screen they get back. Which
		// exact URL each referrer maps to is RedirectorTest's subject; that it is this screen and not
		// some default is this one's.
		$this->assertStringContainsString( 'options-general.php', $location );
	}

	/**
	 * Two bundled plugins, both standalones active. Resolution has to reach the second one: an `exit`
	 * inside the loop would leave its standalone running with nothing said about it, and would take the
	 * load pass at the next priority down with it.
	 *
	 * One redirect, not two, is asserted by the shape of the stub — it throws, so a redirect taken on
	 * the first sub-plugin could not have been followed by the second's deactivation.
	 */
	public function test_it_resolves_every_conflict_and_redirects_once(): void {
		$this->standalone_is( true );
		$this->register();
		$this->register_fee_recovery();

		$this->capture_resolution();

		$this->assertSame(
			[ 'give-recurring/give-recurring.php', 'give-fee-recovery/give-fee-recovery.php' ],
			array_column( $this->deactivations, 'plugins' )
		);

		$queued = $this->queued_notices();
		$this->assertArrayHasKey( 'give-recurring:merge', $queued );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queued, 'The second conflict is the one an early exit loses.' );
	}

	/**
	 * The inline fallback runs immediately after a _doing_it_wrong() that prints on a debugging site, so
	 * the headers are already gone by the time this code runs. Redirecting there sets no Location and
	 * the exit behind it would end the request on a blank page — with the merge notice queued and
	 * nothing left able to draw it.
	 */
	public function test_it_deactivates_without_redirecting_once_the_headers_are_sent(): void {
		// Overrides the false setUp establishes for every other test here.
		$this->setFunctionReturn( 'headers_sent', true );

		$this->standalone_is( true );
		$this->register();

		$this->resolve_all();

		$this->assertCount( 1, $this->deactivations );
		$this->assertArrayHasKey(
			'give-recurring:merge',
			$this->queued_notices(),
			'The request goes on rendering, so the notice it will render must still be there.'
		);
	}

	/**
	 * The redirect exists to re-request the screen with the standalone's code out of memory, and a
	 * standalone that is still active has none of that to offer. Redirecting anyway is a loop: the next
	 * request detects the same conflict, deactivates to no effect, redirects again, and the browser
	 * gives up with the admin out of reach. The merge notice is what makes it silent — a request that
	 * exits never reaches `all_admin_notices`, so the one explanation the site owner would get is
	 * destroyed by the loop that made them need it.
	 *
	 * Nothing exotic is required to get here: a site or mu-plugin filtering `option_active_plugins`
	 * puts the standalone straight back into the active list, which is a pattern `learndash-core`
	 * itself uses.
	 */
	public function test_a_standalone_that_survives_deactivation_does_not_redirect(): void {
		$this->standalone_is( true );
		$this->standalone_survives_deactivation( 'give-recurring/give-recurring.php' );
		$this->register();

		$this->resolve_all();

		$this->assertCount( 1, $this->deactivations, 'The policy still runs: deactivation is asked for.' );
		$this->assertArrayHasKey(
			'give-recurring:merge',
			$this->queued_notices(),
			'The request goes on rendering, so the notice explaining the deactivation must survive to be drawn.'
		);
	}

	/**
	 * The same failure through the seam a host owns rather than through the site's own filters: a
	 * bound `Deactivator_Interface` that records and does nothing leaves the standalone exactly where
	 * it was, and the checker behind the detector says so without any help from this test.
	 */
	public function test_a_rebound_deactivator_that_turns_nothing_off_does_not_redirect(): void {
		$deactivator = new class() implements Deactivator_Interface {
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

		// Before the provider, which is where an interface seam goes: nothing can build an interface
		// unprompted, so the provider reads the binding as the host's and leaves it alone.
		$container = new Test_Container();
		$container->singleton(
			Deactivator_Interface::class,
			static function () use ( $deactivator ): Deactivator_Interface {
				return $deactivator;
			}
		);

		$this->set_up_container( $container );

		$this->standalone_is( true );
		$this->register();

		$this->resolve_all();

		$this->assertSame( [ 'give-recurring/give-recurring.php' ], $deactivator->deactivated );
		$this->assertSame(
			[],
			$this->deactivations,
			'The bound deactivator is what deactivates, so nothing was ever taken out of the active list.'
		);
		$this->assertArrayHasKey( 'give-recurring:merge', $this->queued_notices() );
	}

	/**
	 * The multisite stranding guard: when the detector reports that deactivating this standalone would
	 * strand sites -- a network-active standalone whose host is not itself network-active -- the
	 * DEACTIVATE policy declines. Nothing is deactivated, so there is no merge notice and no redirect;
	 * a stranding notice explains why the standalone is still there. Whether that condition holds is
	 * `DetectorTest`'s; that the resolver obeys it is this test's, so the detector is a double.
	 */
	public function test_it_declines_to_deactivate_when_that_would_strand_sites(): void {
		$detector = new class() extends Detector {
			/**
			 * No plugin checker and no parent constructor: this stands in for the two answers the
			 * resolver reads, not for how a real detector reaches them.
			 */
			public function __construct() {
			}

			/**
			 * @param Sub_Plugin $sub_plugin Sub-plugin to test.
			 *
			 * @return bool
			 */
			public function is_in_conflict( Sub_Plugin $sub_plugin ): bool {
				return true;
			}

			/**
			 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
			 *
			 * @return bool
			 */
			public function deactivation_would_strand_sites( Sub_Plugin $sub_plugin ): bool {
				return true;
			}
		};

		$container = new Test_Container();
		$this->set_up_container( $container );

		// A concrete class, so it is bound after the provider, which would otherwise replace it.
		$container->singleton(
			Detector::class,
			static function () use ( $detector ): Detector {
				return $detector;
			}
		);

		$this->register();
		$this->resolve_all();

		$this->assertSame(
			[],
			$this->deactivations,
			'A standalone whose deactivation would strand sites must be left active.'
		);

		$queued = $this->queued_notices();
		$this->assertArrayHasKey(
			'give-recurring:stranding',
			$queued,
			'The superadmin has to be told why the standalone was left active.'
		);
		$this->assertArrayNotHasKey(
			'give-recurring:merge',
			$queued,
			'Nothing was deactivated, so there is no merge to report.'
		);
	}

	/**
	 * One stubborn standalone must not cost the site the redirect the other one earned. The request
	 * still has a plugin's code in memory that a fresh one would shed, so it is still worth taking —
	 * and it cannot loop for ever on the strength of the first, because the request it lands on
	 * deactivates to no effect and stops there.
	 */
	public function test_it_still_redirects_when_one_of_two_standalones_really_went_away(): void {
		$this->standalone_is( true );
		$this->standalone_survives_deactivation( 'give-recurring/give-recurring.php' );
		$this->register();
		$this->register_fee_recovery();

		$this->capture_resolution();

		$this->assertSame(
			[ 'give-recurring/give-recurring.php', 'give-fee-recovery/give-fee-recovery.php' ],
			array_column( $this->deactivations, 'plugins' )
		);

		$queued = $this->queued_notices();
		$this->assertArrayHasKey( 'give-recurring:merge', $queued );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queued );
	}

	public function test_deactivate_is_the_default_policy(): void {
		$this->standalone_is( true );
		$this->register();

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
	 * Mixed policies on one site. The talking branch and the deactivating branch both run, and the one
	 * redirect at the end belongs to the deactivation — a notice-only conflict on its own never reaches
	 * it, which is what `test_notice_only_notifies_without_deactivating` pins.
	 */
	public function test_a_notice_only_conflict_alongside_a_deactivation_still_redirects(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::NOTICE_ONLY ] );
		$this->register_fee_recovery( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		$this->capture_resolution();

		$queued = $this->queued_notices();
		$this->assertArrayHasKey( 'give-recurring:conflict', $queued );
		$this->assertArrayHasKey( 'give-fee-recovery:merge', $queued );
		$this->assertCount( 1, $this->deactivations, 'Only the deactivating sub-plugin is deactivated.' );
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
		Absorber::register(
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
	 * Without a prefix there is no notice to raise, so there is no honest way to explain a
	 * deactivation to the site owner — and this runs inside `plugins_loaded`, where throwing is not
	 * an option however wrong the host's bootstrap is. It stands down whole rather than turning a
	 * plugin off and going quiet, which is what the load pass does with the same missing prefix.
	 */
	public function test_it_stands_down_without_a_hook_prefix(): void {
		$this->expect_incorrect_usage();
		$this->standalone_is( true );
		$this->register();

		$resolver  = $this->resolver();
		$container = $this->container();

		// The prefix goes, the container stays: this is about the missing prefix, and a resolver that
		// could not reach its registrar would stand down for the other reason.
		Config_State::reset();
		Config::set_container( $container );

		$this->without_ending_the_request(
			static function () use ( $resolver ): void {
				$resolver->resolve_all();
			}
		);

		$this->assertSame( [], $this->deactivations, 'Nothing may be turned off that cannot be explained.' );
		$this->assert_the_library_reported_incorrect_usage();
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
	 * @return void
	 */
	private function resolve_all(): void {
		$resolver = $this->resolver();

		$this->without_ending_the_request(
			static function () use ( $resolver ): void {
				$resolver->resolve_all();
			}
		);
	}

	/**
	 * Run something that must return rather than redirect.
	 *
	 * The redirect is stubbed to throw rather than left alone: unstubbed, a resolver that redirected
	 * anyway would reach the real `wp_safe_redirect()` and the `exit` behind it, taking the whole test
	 * process with it. Throwing instead turns that into one failed test naming the path it happened on.
	 *
	 * @param callable $action The call under test.
	 *
	 * @return void
	 */
	private function without_ending_the_request( callable $action ): void {
		$message = 'The resolver must not end the request on this path.';

		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () use ( $message ) {
				throw new TestException( $message );
			},
			true
		);

		try {
			$action();
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
		Absorber::register(
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
	 * A second bundled sub-plugin, for the tests about a site that absorbed more than one.
	 *
	 * @param array<string,mixed> $overrides Config overrides.
	 *
	 * @return void
	 */
	private function register_fee_recovery( array $overrides = [] ): void {
		$this->register(
			array_merge(
				[
					'slug'                       => 'give-fee-recovery',
					'bundled_plugin_file'        => '/tmp/give-fee-recovery.php',
					'plugin_loaded_constant'     => 'GIVE_FEE_RECOVERY_VERSION_FIXTURE',
					'standalone_plugin_basename' => 'give-fee-recovery/give-fee-recovery.php',
				],
				$overrides
			)
		);
	}

	/**
	 * Only is_plugin_active(), which is the one function Checker::is_active() calls — and it
	 * ORs the network check in itself, so stubbing is_plugin_active_for_network() alongside it
	 * would be inert and would read as though a network path were being exercised.
	 *
	 * Active *until it has been deactivated*, rather than active for ever. The resolver asks a second
	 * time before it redirects, so a stub frozen at true would describe a site where deactivation
	 * never works and every deactivating test would assert against that instead of the ordinary case.
	 * The recorded calls are what it reads, so the two stubs answer the same question consistently and
	 * a standalone deliberately made immovable stays that way.
	 *
	 * @param bool $active Whether the standalone starts out active.
	 *
	 * @return void
	 */
	private function standalone_is( bool $active ): void {
		if ( ! $active ) {
			$this->setFunctionReturn( 'is_plugin_active', false );

			return;
		}

		// uopz runs a replacement with no class scope, so $this is fatal inside the closure. Both
		// properties are bound by reference instead, which is also what lets a test arrange the
		// immovable list after this call. See tests/README.md.
		$deactivations = &$this->deactivations;
		$immovable     = &$this->immovable_standalones;

		$this->setFunctionReturn(
			'is_plugin_active',
			static function ( $basename ) use ( &$deactivations, &$immovable ): bool {
				if ( in_array( $basename, $immovable, true ) ) {
					return true;
				}

				foreach ( $deactivations as $deactivation ) {
					if ( in_array( $basename, (array) $deactivation['plugins'], true ) ) {
						return false;
					}
				}

				return true;
			},
			true
		);
	}

	/**
	 * A standalone WordPress keeps reporting as active however often it is deactivated.
	 *
	 * @param string $basename Standalone plugin basename.
	 *
	 * @return void
	 */
	private function standalone_survives_deactivation( string $basename ): void {
		$this->immovable_standalones[] = $basename;
	}
}
