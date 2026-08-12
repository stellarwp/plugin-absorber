<?php
/**
 * @package Nexcess\PluginAbsorber
 */

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
 * The gates used to sit on the `Loader` trampoline and could only be exercised through a full
 * resolution, which meant every one of these cases asserted on a deactivation that did not happen —
 * and an empty recorder is also what a stub that never installed produces. As a predicate each case
 * asserts the answer itself, and then flips the single condition it is about and asserts the answer
 * changes. That is what stops "false for some other reason" from passing.
 *
 * setUp establishes the one request that may resolve: an admin GET by a user who can activate
 * plugins, with a hook prefix set. Every test below takes exactly one thing away from it.
 *
 * @since 1.0.0
 */
class GatekeeperTest extends WPTestCase {
	use UopzFunctions;
	use WithContainer;
	use WithIncorrectUsage;
	use WithUsers;

	/**
	 * @var string|null
	 */
	private $request_method;

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->set_up_container();

		// plugins_loaded fires on every request, so the request method is part of what the gate reads
		// rather than something the harness happens to leave lying around.
		$this->request_method      = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		set_current_screen( 'dashboard' );
		$this->become_plugin_administrator();
	}

	public function tearDown(): void {
		if ( $this->request_method === null ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

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
		$this->assertTrue( $this->gatekeeper()->may_resolve() );
	}

	/**
	 * Unguarded, resolution fires at plugins_loaded on every request: a visitor's checkout POST
	 * becomes a 302 that drops the order, and a front-end page load deactivates a plugin with nobody
	 * there to read the notice about it.
	 */
	public function test_it_refuses_a_front_end_request(): void {
		set_current_screen( 'front' );

		$this->assertFalse( $this->gatekeeper()->may_resolve() );

		set_current_screen( 'dashboard' );

		$this->assert_the_gate_opens_again();
	}

	/**
	 * admin-post.php and options.php define WP_ADMIN and never define DOING_AJAX, so is_admin() is
	 * true and wp_doing_ajax() is false. Deactivating and redirecting there turns a submitted form
	 * into a 302 the browser follows with a GET, and the submission is gone — the same data loss the
	 * gate exists to prevent, one layer in. Nothing is lost by waiting for the next page view.
	 */
	public function test_it_refuses_an_admin_form_submission(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertFalse( $this->gatekeeper()->may_resolve() );

		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assert_the_gate_opens_again();
	}

	/**
	 * wp-cron never reaches its event loop if the request it rides on is redirected away.
	 */
	public function test_it_refuses_a_cron_request(): void {
		$this->setConstant( 'DOING_CRON', true );

		$this->assertFalse( $this->gatekeeper()->may_resolve() );

		// unsetConstant() rather than a second setConstant(): it restores whatever the constant was
		// before this test touched it, where setting it twice would record the test's own value as
		// the one to put back.
		$this->unsetConstant( 'DOING_CRON' );

		$this->assert_the_gate_opens_again();
	}

	public function test_it_refuses_an_ajax_request(): void {
		$this->setConstant( 'DOING_AJAX', true );

		$this->assertFalse( $this->gatekeeper()->may_resolve() );

		$this->unsetConstant( 'DOING_AJAX' );

		$this->assert_the_gate_opens_again();
	}

	/**
	 * header() is a no-op under the CLI SAPI, so a WP-CLI command would exit 0 having printed
	 * nothing and having deactivated a plugin the operator never asked about.
	 */
	public function test_it_refuses_a_wp_cli_request(): void {
		$this->setConstant( 'WP_CLI', true );

		$this->assertFalse( $this->gatekeeper()->may_resolve() );

		$this->unsetConstant( 'WP_CLI' );

		$this->assert_the_gate_opens_again();
	}

	/**
	 * plugins_loaded is dispatched by wp-load.php, which wp-admin/admin.php requires long before it
	 * calls auth_redirect(). An unauthenticated GET of an admin URL therefore reaches the conflict
	 * step, and without the capability check a stranger could deactivate the standalone site-wide.
	 *
	 * @dataProvider users_who_cannot_activate_plugins
	 *
	 * @param string|null $role Role to ask as, or null for a logged-out visitor.
	 */
	public function test_it_refuses_a_user_who_cannot_activate_plugins( ?string $role ): void {
		wp_set_current_user( $role === null ? 0 : $this->create_user( $role ) );

		$this->assertFalse(
			$this->gatekeeper()->may_resolve(),
			'Only someone who can activate a plugin may deactivate one.'
		);

		$this->become_plugin_administrator();

		$this->assert_the_gate_opens_again();
	}

	/**
	 * @return Generator<string,array{0:string|null}>
	 */
	public static function users_who_cannot_activate_plugins(): Generator {
		yield 'a subscriber'         => [ 'subscriber' ];
		yield 'a logged-out visitor' => [ null ];
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

		$this->assertFalse( $this->gatekeeper()->may_resolve() );
		$this->assert_the_library_reported_incorrect_usage();

		Config::set_hook_prefix( 'give' );

		$this->assert_the_gate_opens_again();
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
	 * With the one condition under test put back, the gate has to open.
	 *
	 * Without this every test here would pass on a gatekeeper that refused everything, including the
	 * request it is supposed to admit.
	 *
	 * @return void
	 */
	private function assert_the_gate_opens_again(): void {
		$this->assertTrue(
			$this->gatekeeper()->may_resolve(),
			'The condition under test has to be the one deciding the answer.'
		);
	}
}
