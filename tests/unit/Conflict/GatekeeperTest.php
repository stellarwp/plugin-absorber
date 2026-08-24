<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;

/**
 * Who may have a conflict resolved, asked directly.
 *
 * The gates used to sit on the `Absorber` trampoline and could only be exercised through a full
 * resolution, which meant every one of these cases asserted on a deactivation that did not happen —
 * and an empty recorder is also what a stub that never installed produces. As a predicate each case
 * asserts the answer itself, and then flips the single condition it is about and asserts the answer
 * changes. That is what stops "false for some other reason" from passing.
 *
 * The two gates are asserted separately, because the conflict step asks them at different moments
 * and a case that only ever asked both together could not tell which one refused.
 *
 * setUp establishes the one request that may resolve: a plain admin GET of a list screen, by a user
 * who may manage plugins at this site's scope, with a hook prefix set. Every test below takes
 * exactly one thing away from it.
 *
 * @since 1.0.0
 */
class GatekeeperTest extends WPTestCase {
	use UopzFunctions;
	use WithContainer;
	use WithIncorrectUsage;
	use WithUsers;

	/**
	 * @var array<string,mixed>
	 */
	private $server = [];

	/**
	 * @var array<string,mixed>
	 */
	private $query_args = [];

	/**
	 * @var mixed
	 */
	private $pagenow;

	/**
	 * @var callable|null
	 */
	private $capability_denial;

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();

		// plugins_loaded fires on every request, so the shape of the request is part of what the gate
		// reads rather than something the harness happens to leave lying around. Snapshotting the
		// whole of each superglobal, rather than the keys these tests touch, is what keeps a case that
		// adds a key from having to remember to remove it again.
		$this->server     = $_SERVER;
		$this->query_args = $_GET;
		$this->pagenow    = $GLOBALS['pagenow'] ?? null;

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SCRIPT_NAME']    = '/wp-admin/plugins.php';
		$_SERVER['PHP_SELF']       = '/wp-admin/plugins.php';
		$_GET                      = [];

		// wp-settings.php requires the file that sets $pagenow before it loads any plugin, so this is
		// what the gate finds at plugins_loaded. Under the CLI the suite runs on, it names the test
		// runner instead of an admin screen, so every case states the screen it is about.
		$GLOBALS['pagenow'] = 'plugins.php';

		set_current_screen( 'dashboard' );
		$this->become_plugin_administrator();
	}

	public function tearDown(): void {
		$this->remove_capability_denial();

		$_SERVER = $this->server;
		$_GET    = $this->query_args;

		if ( $this->pagenow === null ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->pagenow;
		}

		// The current user is left resolved, whichever way the test that just ran left it. One case
		// deliberately unsets it, and a global that is missing rather than empty is not a state any
		// later test should have to survive.
		wp_set_current_user( 0 );

		// In tearDown rather than at the end of the test body: a failing assertion would otherwise
		// leak an admin screen into every test that runs after it, since is_admin() checks the
		// current screen before WP_ADMIN.
		set_current_screen( 'front' );

		$this->stop_expecting_incorrect_usage();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_it_admits_an_interactive_admin_get(): void {
		$this->assertTrue( $this->gatekeeper()->request_may_resolve() );
		$this->assertTrue( $this->gatekeeper()->user_may_resolve() );
	}

	/**
	 * The gates answer about different things and are asked at different moments, so each has to be
	 * able to refuse on its own: an administrator who may deactivate anything still may not have it
	 * happen mid-action, and a plain page view by a subscriber is a perfectly good request that the
	 * wrong person is making.
	 */
	public function test_the_two_gates_refuse_independently(): void {
		$_GET['action'] = 'activate';

		$this->assertFalse(
			$this->gatekeeper()->request_may_resolve(),
			'A capable user does not make an action request resolvable.'
		);
		$this->assertTrue(
			$this->gatekeeper()->user_may_resolve(),
			'The user gate answers about the user, whatever the request is doing.'
		);

		unset( $_GET['action'] );
		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$this->assertTrue(
			$this->gatekeeper()->request_may_resolve(),
			'The request gate answers about the request, whoever is making it.'
		);
		$this->assertFalse(
			$this->gatekeeper()->user_may_resolve(),
			'Only someone who may manage plugins may have one deactivated for them.'
		);
	}

	/**
	 * The request gate runs at plugins_loaded priority 5, and resolving the current user there caches
	 * it for the rest of the request. An SSO or JWT plugin hooks determine_current_user from its own
	 * plugins_loaded callback at the default priority, so a capability check here would settle who is
	 * signed in before that plugin has been asked — and every one of its users would be treated as
	 * logged out. The capability moved to the second gate for this reason, and nothing may move back.
	 */
	public function test_the_request_gate_does_not_decide_who_is_signed_in(): void {
		// Built before the global goes, so that nothing between the unset and the assertion belongs to
		// the container rather than to the gate under test.
		$gatekeeper = $this->gatekeeper();
		$user_id    = get_current_user_id();

		unset( $GLOBALS['current_user'] );

		$this->assertTrue( $gatekeeper->request_may_resolve() );
		$this->assertArrayNotHasKey(
			'current_user',
			$GLOBALS,
			'The request gate must not resolve the current user.'
		);

		// The recorder has to be able to record. Without asking the other gate, this case would pass
		// just as well against a WordPress that cached the current user somewhere else entirely.
		$gatekeeper->user_may_resolve();

		$this->assertArrayHasKey(
			'current_user',
			$GLOBALS,
			'Asking about the capability is what resolves the user.'
		);

		wp_set_current_user( $user_id );
	}

	/**
	 * Unguarded, resolution fires at plugins_loaded on every request: a visitor's checkout POST
	 * becomes a 302 that drops the order, and a front-end page load deactivates a plugin with nobody
	 * there to read the notice about it.
	 */
	public function test_it_refuses_a_front_end_request(): void {
		set_current_screen( 'front' );

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		set_current_screen( 'dashboard' );

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * admin-post.php and options.php define WP_ADMIN and never define DOING_AJAX, so is_admin() is
	 * true and wp_doing_ajax() is false. Deactivating and redirecting there turns a submitted form
	 * into a 302 the browser follows with a GET, and the submission is gone — the same data loss the
	 * gate exists to prevent, one layer in. Nothing is lost by waiting for the next page view.
	 */
	public function test_it_refuses_an_admin_form_submission(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * wp-cron never reaches its event loop if the request it rides on is redirected away.
	 */
	public function test_it_refuses_a_cron_request(): void {
		$this->setConstant( 'DOING_CRON', true );

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		// unsetConstant() rather than a second setConstant(): it restores whatever the constant was
		// before this test touched it, where setting it twice would record the test's own value as
		// the one to put back.
		$this->unsetConstant( 'DOING_CRON' );

		$this->assert_the_request_gate_opens_again();
	}

	public function test_it_refuses_an_ajax_request(): void {
		$this->setConstant( 'DOING_AJAX', true );

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$this->unsetConstant( 'DOING_AJAX' );

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * header() is a no-op under the CLI SAPI, so a WP-CLI command would exit 0 having printed
	 * nothing and having deactivated a plugin the operator never asked about.
	 */
	public function test_it_refuses_a_wp_cli_request(): void {
		$this->setConstant( 'WP_CLI', true );

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$this->unsetConstant( 'WP_CLI' );

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * A GET is not automatically safe to discard. Resolution deactivates, redirects and exits, so on
	 * one of these the user clicks "Update Now" or "Activate" and lands on a list screen with nothing
	 * done — the same silent discard of a submitted action the POST case is about, one verb over.
	 *
	 * `plugins.php?action=activate` is also the request plugin_sandbox_scrape() replays while
	 * activating a plugin, where the exit aborts the activation and core reports the plugin as fatal.
	 *
	 * @dataProvider gets_that_carry_an_action
	 *
	 * @param string $arg   Query arg naming the action.
	 * @param string $value Action requested.
	 */
	public function test_it_refuses_a_get_that_carries_an_action( string $arg, string $value ): void {
		$_GET[ $arg ] = $value;

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		unset( $_GET[ $arg ] );

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function gets_that_carry_an_action(): Generator {
		yield 'activating a plugin'     => [ 'action', 'activate' ];
		yield 'updating a plugin'       => [ 'action', 'upgrade-plugin' ];
		yield 'installing a plugin'     => [ 'action', 'install-plugin' ];
		yield 'a bulk action'           => [ 'action', 'delete-selected' ];
		yield 'the lower bulk selector' => [ 'action2', 'deactivate-selected' ];

		// admin.php dispatches admin_action_{$action} on the value as it arrived, so a name made
		// entirely of characters sanitize_key() strips still names work a plugin can be hooked to.
		// Reading it through sanitize_key() would empty it and admit the one request the gate is
		// most confident it refuses.
		yield 'an action named outside the Latin alphabet' => [ 'action', 'экспорт' ];
		yield 'an action named in punctuation'             => [ 'action', '+' ];

		// Core adds its slashes in wp_magic_quotes(), which wp-settings.php calls after
		// do_action( 'plugins_loaded' ) — so at priority 5 these arrive exactly as the URL bar sent
		// them and there is nothing to unslash. Doing it anyway is a stripslashes() over user input,
		// and these are the two values it empties into the answers the gate reads as "no action
		// asked for": a request the gate then admits, and core goes on to dispatch
		// admin_action_\ for.
		yield 'an action a stripslashes would empty'           => [ 'action', '\\' ];
		yield 'an action a stripslashes would turn into no-op' => [ 'action2', '-\\1' ];
	}

	/**
	 * A list table submits its bulk selector on every paging and search request, whether or not
	 * anything was chosen, so `-1` arrives on ordinary page views and asks for nothing. Refusing it
	 * would leave the plugins list — the screen an admin is likeliest to be looking at while the
	 * standalone is still installed — unable to resolve anything at all.
	 *
	 * @dataProvider action_args_that_ask_for_nothing
	 *
	 * @param string $arg   Query arg naming the action.
	 * @param mixed  $value Value that names no action core could dispatch on.
	 */
	public function test_it_admits_a_get_whose_action_arg_asks_for_nothing( string $arg, $value ): void {
		$_GET[ $arg ] = $value;

		$this->assertTrue( $this->gatekeeper()->request_may_resolve() );

		// Proof that this is an arg the gate reads at all. Without it the case would pass just as
		// well against a gate that had never looked at the query string.
		$_GET[ $arg ] = 'activate';

		$this->assertFalse(
			$this->gatekeeper()->request_may_resolve(),
			'The arg under test has to be one the gate reads.'
		);
	}

	/**
	 * @return Generator<string,array{0:string,1:mixed}>
	 */
	public static function action_args_that_ask_for_nothing(): Generator {
		yield 'an unselected bulk action'         => [ 'action', '-1' ];
		yield 'an unselected lower bulk action'   => [ 'action2', '-1' ];
		yield 'an empty action'                   => [ 'action', '' ];
		// An array never matches one of core's action names, so it asks for nothing core could
		// dispatch on and there is no work here to interrupt.
		yield 'an action core cannot dispatch on' => [ 'action', [ 'activate' ] ];
	}

	/**
	 * These four exist only to do work and then redirect or print a result. There is no page here to
	 * resolve a conflict on, only work to interrupt — and admin-post.php carries the action in a query
	 * arg the handler names itself, so matching on the arg alone would not catch every link.
	 *
	 * @dataProvider endpoints_that_only_perform_work
	 *
	 * @param string $script Admin script the request is running.
	 */
	public function test_it_refuses_an_endpoint_that_only_performs_work( string $script ): void {
		$GLOBALS['pagenow'] = $script;

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$GLOBALS['pagenow'] = 'plugins.php';

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function endpoints_that_only_perform_work(): Generator {
		yield 'the form handler'  => [ 'admin-post.php' ];
		yield 'the ajax endpoint' => [ 'admin-ajax.php' ];
		yield 'the updater'       => [ 'update.php' ];
		yield 'the exporter'      => [ 'export.php' ];
	}

	/**
	 * $pagenow is derived from PHP_SELF, which some SAPI and proxy configurations leave empty, and a
	 * host is free to have unset the global. The endpoint then has to be recognised anyway, because
	 * the request it names is still the one an exit would abort.
	 */
	public function test_it_reads_the_script_name_when_pagenow_is_missing(): void {
		unset( $GLOBALS['pagenow'] );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/update.php';

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/plugins.php';

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * PHP_SELF last, and the network admin because that is where a network-wide deactivation gets
	 * noticed: the screen is what has to be matched, not the path in front of it.
	 */
	public function test_it_reads_php_self_when_no_script_name_is_set(): void {
		unset( $GLOBALS['pagenow'], $_SERVER['SCRIPT_NAME'] );
		$_SERVER['PHP_SELF'] = '/wp-admin/network/update.php';

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );

		$_SERVER['PHP_SELF'] = '/wp-admin/network/plugins.php';

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * plugins_loaded is dispatched by wp-load.php, which wp-admin/admin.php requires long before it
	 * calls auth_redirect(). An unauthenticated GET of an admin URL therefore reaches the conflict
	 * step, and without the capability check a stranger could deactivate the standalone site-wide.
	 *
	 * @dataProvider users_who_cannot_manage_plugins
	 *
	 * @param string|null $role Role to ask as, or null for a logged-out visitor.
	 */
	public function test_it_refuses_a_user_who_cannot_manage_plugins( ?string $role ): void {
		wp_set_current_user( $role === null ? 0 : $this->create_user( $role ) );

		$this->assertFalse(
			$this->gatekeeper()->user_may_resolve(),
			'Only someone who may manage plugins may have one deactivated for them.'
		);

		$this->become_plugin_administrator();

		$this->assert_the_user_gate_opens_again();
	}

	/**
	 * @return Generator<string,array{0:string|null}>
	 */
	public static function users_who_cannot_manage_plugins(): Generator {
		yield 'a subscriber'         => [ 'subscriber' ];
		yield 'a logged-out visitor' => [ null ];
	}

	/**
	 * The capability has to reach as far as the action does, and the action reaches the whole network:
	 * the deactivation leaves deactivate_plugins()'s $network_wide at its default, so the standalone
	 * comes out of the network's active plugins whichever site the request arrived on. Asking for
	 * activate_plugins would not establish that authority, because core only widens it into the
	 * network capability while a network setting says to — on a network that has said otherwise, every
	 * subsite administrator would pass a gate for an action they hold no capability for.
	 *
	 * The capability is refused through map_meta_cap rather than by building a user who holds one and
	 * not the other. A super admin is admitted before user_has_cap is consulted at all, and
	 * become_plugin_administrator() makes one on multisite, so a user-shaped fixture would describe a
	 * different situation in each of the two suite environments.
	 *
	 * Which pair applies comes from the environment, and is_multisite() is never stubbed to get at the
	 * other one. Its answer decides which half of WordPress was loaded at all — the ms-*.php files are
	 * only included on a real network — so forcing it sends core down branches whose functions do not
	 * exist in the process, and the fatal that follows outlives the test that caused it. Nothing is
	 * lost by reading the real answer: the suite runs both environments, so each branch is asserted by
	 * the run that has it, and neither run reaches this by skipping.
	 */
	public function test_it_requires_the_capability_that_matches_the_deactivation(): void {
		$required     = is_multisite() ? 'manage_network_plugins' : 'activate_plugins';
		$insufficient = is_multisite() ? 'activate_plugins' : 'manage_network_plugins';

		$this->deny_capability( $required );

		$this->assertFalse(
			$this->gatekeeper()->user_may_resolve(),
			'Refusing ' . $required . ' has to close the gate.'
		);

		$this->deny_capability( $insufficient );

		$this->assertTrue(
			$this->gatekeeper()->user_may_resolve(),
			'The gate must not be settling for ' . $insufficient . '.'
		);
	}

	/**
	 * The prefix names the conflict_policy filter and the option the notice queue lives in, so
	 * resolution without one has nowhere to put what it would do. It is the only gate that reports the
	 * mistake, and it is checked last so that report lands on the admin request that was about to
	 * resolve something rather than on every front-end request the site serves.
	 */
	public function test_it_refuses_a_request_with_no_hook_prefix(): void {
		$container = $this->container();

		// The prefix goes, the container stays: a gatekeeper that could not be built at all would
		// fail this test for the other reason.
		Config_State::reset();
		Config::set_container( $container );
		$this->expect_incorrect_usage();

		$this->assertFalse( $this->gatekeeper()->request_may_resolve() );
		$this->assert_the_library_reported_incorrect_usage();

		Config::set_hook_prefix( 'give' );

		$this->assert_the_request_gate_opens_again();
	}

	/**
	 * The gatekeeper as the container builds it, which is how the conflict step reaches it.
	 *
	 * @return Gatekeeper
	 */
	private function gatekeeper(): Gatekeeper {
		return $this->resolve( Gatekeeper::class );
	}

	/**
	 * Answer every capability question with yes, except the one named.
	 *
	 * 'do_not_allow' is the refusal core honours even for a super admin, whom nothing in user_has_cap
	 * can turn down, and 'exist' is the capability core grants every user — so one filter describes
	 * the same user in both suite environments.
	 *
	 * @param string $capability Capability to refuse.
	 *
	 * @return void
	 */
	private function deny_capability( string $capability ): void {
		$this->remove_capability_denial();

		$denial = static function ( $caps, $cap ) use ( $capability ): array {
			return $cap === $capability ? [ 'do_not_allow' ] : [ 'exist' ];
		};

		add_filter( 'map_meta_cap', $denial, 10, 2 );

		$this->capability_denial = $denial;
	}

	/**
	 * Put every capability back. Called from tearDown, and again before a second denial replaces the
	 * first, so that two filters are never answering the same question.
	 *
	 * @return void
	 */
	private function remove_capability_denial(): void {
		if ( $this->capability_denial === null ) {
			return;
		}

		remove_filter( 'map_meta_cap', $this->capability_denial );

		$this->capability_denial = null;
	}

	/**
	 * With the one condition under test put back, the request gate has to open.
	 *
	 * Without this every test here would pass on a gatekeeper that refused everything, including the
	 * request it is supposed to admit.
	 *
	 * @return void
	 */
	private function assert_the_request_gate_opens_again(): void {
		$this->assertTrue(
			$this->gatekeeper()->request_may_resolve(),
			'The condition under test has to be the one deciding the answer.'
		);
	}

	/**
	 * With the one condition under test put back, the user gate has to open.
	 *
	 * @return void
	 */
	private function assert_the_user_gate_opens_again(): void {
		$this->assertTrue(
			$this->gatekeeper()->user_may_resolve(),
			'The condition under test has to be the one deciding the answer.'
		);
	}
}
