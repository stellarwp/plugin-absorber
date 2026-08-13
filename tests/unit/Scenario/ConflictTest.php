<?php
/**
 * The conflict scenarios: what happens when a standalone copy is still installed.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Scenario;

use Nexcess\PluginAbsorber\Conflict_Policy;

/**
 * Every policy branch, driven end to end against a real WordPress.
 *
 * The bootstrap, the request helpers and the fixtures all come from `Bootstrap_Test_Case`; what this
 * file adds is the standalone. Each scenario puts a real basename into the real `active_plugins`
 * option and then asserts what the conflict step did about it — deactivated it through core's own
 * `deactivate_plugins()`, only talked about it, or deliberately stood aside — plus the one conflict
 * the load guard cannot prevent, where core's activation sandbox has already fataled.
 *
 * @since 1.0.0
 */
class ConflictTest extends Bootstrap_Test_Case {
	/**
	 * Core's own activation-error sentence, spelled out rather than built with `__()` — the rewrite
	 * is a `str_replace()` against this exact string.
	 *
	 * @var string
	 */
	private const CORE_TEXT = 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.';

	/**
	 * The notice core is about to print, as `wp_admin_notice_markup` hands it over.
	 *
	 * @var string
	 */
	private const MARKUP = '<div class="notice notice-error is-dismissible"><p>' . self::CORE_TEXT . '</p></div>';

	/**
	 * Here rather than in the parent because only this file builds an activation-error request: a
	 * `$_GET` left standing would make a later test look like one, and the rewrite would fire on a
	 * screen that never asked for it.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_GET['plugin'], $_GET['_error_nonce'] );

		parent::tearDown();
	}

	/**
	 * The default policy, against core's own `deactivate_plugins()` and the real `active_plugins`
	 * option rather than a stub of either.
	 */
	public function test_deactivate_deactivates_notifies_and_redirects(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
			]
		);

		$this->boot();

		$location = $this->run_halted_request();

		$this->assertNotContains( self::STANDALONE, $this->active_plugins() );
		$this->assertArrayHasKey( self::SLUG . ':merge', $this->queued_notices() );

		// The destination, not merely that one was asked for: a redirect somewhere else entirely
		// would satisfy "the request ended in a redirect" without sending anyone anywhere useful.
		$this->assertSame( admin_url( 'plugins.php' ), $location );

		// The request really ended in the resolver. The bundled copy loads on the next one, which is
		// what the standalone's own guard constant forces in production.
		$this->assertSame( 0, $this->bundled_plugin_loads() );
	}

	/**
	 * All the way to the screen. The merge notice is the one this library raises exactly once and
	 * never re-queues, so the admin page load after the deactivation has to draw it — and consume it,
	 * or the owner reads the same deactivation report for ever.
	 */
	public function test_the_merge_notice_renders_on_the_next_admin_screen_and_clears(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		$this->boot();
		$this->run_halted_request();

		$rendered = $this->render_admin_notices();

		$this->assertStringContainsString( self::SLUG, $rendered );
		$this->assertStringContainsString( 'has been deactivated', $rendered );
		$this->assertStringContainsString(
			'notice-warning',
			$rendered,
			'A conflict the library has already handled is a warning, not an error.'
		);
		$this->assertSame( [], $this->queued_notices(), 'Rendering consumes the queue.' );
	}

	/**
	 * The failure mode a merge notice queued on every request would produce: a redirect loop, or an
	 * admin screen that reports the same deactivation for ever. Nothing is re-registered between the
	 * two requests — a duplicate slug throws — because this is the next page view, not a second
	 * bootstrap.
	 */
	public function test_the_request_after_a_deactivation_does_not_loop(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$constant = $this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		$this->boot();
		$this->run_halted_request();

		$this->assertArrayHasKey( self::SLUG . ':merge', $this->queued_notices() );

		// The owner has been told. Emptying the queue is what makes a second notice visible at all:
		// re-queuing writes the same `slug:merge` key, so a queue left as it is would look identical
		// whether or not the resolver ran again.
		$this->clear_notices();

		// This one must not halt, and run_request() fails the test if it does — which is the
		// redirect loop, asserted rather than described.
		$this->run_request();

		$this->assertSame( [], $this->queued_notices(), 'Nothing is left to resolve, so nothing is left to say.' );
		$this->assertSame( 1, $this->bundled_plugin_loads(), 'With the standalone gone the bundled copy takes over.' );
		$this->assertTrue( defined( $constant ) );
	}

	/**
	 * DEFER hands the request to the standalone, and WordPress includes an active plugin from
	 * wp-settings.php long before plugins_loaded — so by the time the resolver runs, the standalone
	 * has already defined the guard constant. Defining it up front is what makes this the scenario
	 * the policy actually describes rather than a resolver that merely declined to act.
	 */
	public function test_defer_leaves_the_standalone_active_and_loads_nothing(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$constant = $this->define_guard( 'ABSORBER_E2E_DEFERRED_GUARD' );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEFER,
			],
			$constant
		);

		$this->boot();
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins() );
		$this->assertSame( 0, $this->bundled_plugin_loads(), 'The standalone won; the guard stands the bundled copy down.' );
		$this->assertSame( [], $this->queued_notices() );
	}

	/**
	 * A policy that only talks: the standalone stays exactly where it was, and the host's own sentence
	 * is what the owner is left with.
	 */
	public function test_notice_only_notifies_without_deactivating(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::NOTICE_ONLY,
				'conflict_notice_message'    => static fn() => 'Deactivate the standalone when you get a chance.',
			]
		);

		$this->boot();

		// A policy that only talks must not end the request, which is what run_request() asserts.
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins() );
		$this->assertSame(
			[ self::SLUG . ':conflict' => 'Deactivate the standalone when you get a chance.' ],
			$this->queued_notices()
		);
	}

	/**
	 * The gate that survives every policy and every rebinding: whoever cannot activate a plugin must
	 * not be able to deactivate one by loading an admin page. Nothing is consumed by refusing — the
	 * standalone is still there to detect on the next request, from someone who can act on it, which
	 * is what the second half asserts.
	 */
	public function test_a_user_who_cannot_activate_plugins_resolves_nothing(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$this->register( [ 'standalone_plugin_basename' => self::STANDALONE ] );

		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$this->boot();
		$this->run_request();

		$this->assertContains( self::STANDALONE, $this->active_plugins(), 'A subscriber must not deactivate anything.' );
		$this->assertSame( [], $this->queued_notices(), 'A user who could never read the notice must not consume it.' );

		$this->become_plugin_administrator();

		$this->run_halted_request();

		$this->assertNotContains( self::STANDALONE, $this->active_plugins() );
		$this->assertArrayHasKey( self::SLUG . ':merge', $this->queued_notices() );
	}

	/**
	 * The conflict the load guard cannot prevent: the owner reinstalls the standalone and presses
	 * Activate, WordPress includes it on top of the bundled copy, and the re-declaration is a real
	 * fatal that core's sandbox reports as "the plugin triggered a fatal error" — true, and useless.
	 *
	 * Driven through `Absorber::boot()` and core's own filter dispatch rather than by calling
	 * `Conflict\Rewriter` directly, because the wiring is half of what has to work: an admin-only
	 * `add_filter()` that never ran leaves the useless sentence on the screen.
	 */
	public function test_a_reactivation_attempt_yields_the_friendly_message(): void {
		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_notice_message'    => static fn() => 'Recurring is already bundled with the host plugin.',
			]
		);

		$this->boot();

		// The request core redirects to once the sandboxed activation has fataled.
		$_GET['plugin']       = self::STANDALONE;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . self::STANDALONE );

		$rewritten = apply_filters( 'wp_admin_notice_markup', self::MARKUP, self::CORE_TEXT, [] );

		$this->assertIsString( $rewritten, 'The filter must hand back markup, whatever it did with it.' );

		$filtered = is_string( $rewritten ) ? $rewritten : '';

		$this->assertStringContainsString( 'Recurring is already bundled with the host plugin.', $filtered );
		$this->assertStringNotContainsString( self::CORE_TEXT, $filtered );

		// The notice box stays core's to draw — its classes, its dismiss button, its wrapper. Only
		// the sentence inside belongs to this library.
		$this->assertStringStartsWith( '<div class="notice notice-error is-dismissible"><p>', $filtered );
	}
}
