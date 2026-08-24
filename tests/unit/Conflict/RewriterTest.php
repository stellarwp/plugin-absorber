<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Rewriter;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Stub_Registry_Reader;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * The one conflict the load guard cannot prevent: WordPress includes the standalone after the
 * bundled copy has already loaded, the re-declaration is a real fatal, and core's activation sandbox
 * reports it as "the plugin triggered a fatal error" — true, and useless to whoever pressed the
 * button. This class covers rewriting that sentence, taking core's sandbox iframe out from under it,
 * and every reason not to do either.
 *
 * @since 1.0.0
 */
class RewriterTest extends WPTestCase {
	use WithSubPlugins;

	/**
	 * The standalone the registered sub-plugin claims.
	 *
	 * @var string
	 */
	private const STANDALONE = 'give-recurring/give-recurring.php';

	/**
	 * Core's sentence for a plugin that fataled while being activated, spelled out rather than built
	 * with `__()`.
	 *
	 * Restating it is the point: the rewrite is a `str_replace()` against this exact string, so if
	 * core ever rewords it the replacement silently stops happening, and a test that asked core for
	 * the needle would go on passing while the site showed the useless message again.
	 *
	 * @var string
	 */
	private const ACTIVATION_TEXT = 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.';

	/**
	 * Core's other wording for the same fatal, printed when recovery mode fails to resume the
	 * plugin (`wp-admin/plugins.php`, the `'resuming' === $_GET['error']` branch).
	 *
	 * @var string
	 */
	private const RESUME_TEXT = 'Plugin could not be resumed because it triggered a <strong>fatal error</strong>.';

	/**
	 * The notice core is about to print, as `wp_admin_notice_markup` hands it over.
	 *
	 * Rebuilt per test rather than held as a constant, because the iframe core appends carries the
	 * request's own plugin name and nonce, and a constant cannot call `admin_url()`.
	 *
	 * @var string
	 */
	private $markup = '';

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );

		// is_admin() reads the current screen before WP_ADMIN, so this is what puts the request in
		// the admin as well as on the right screen.
		set_current_screen( 'plugins' );

		// Every test starts from the request core actually redirects to after a sandboxed fatal, and
		// states only the part it is about.
		$_GET['error']        = 'true';
		$_GET['plugin']       = self::STANDALONE;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . self::STANDALONE );

		$this->markup = $this->activation_error_notice();
	}

	public function tearDown(): void {
		// In tearDown rather than at the end of each test body: a failed assertion would otherwise
		// leave an admin screen and a half-built activation-error request standing for every test
		// that runs afterwards in this process.
		unset( $_GET['error'], $_GET['plugin'], $_GET['_error_nonce'] );
		set_current_screen( 'front' );

		Config_State::reset();
		parent::tearDown();
	}

	public function test_it_replaces_the_fatal_error_text_with_the_configured_message(): void {
		$rewriter = $this->make_rewriter(
			$this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Give Recurring is already bundled with Give.' ] )
		);

		$filtered = $rewriter->rewrite( $this->markup );

		$this->assertStringContainsString( 'Give Recurring is already bundled with Give.', $filtered );
		$this->assertStringNotContainsString( self::ACTIVATION_TEXT, $filtered );

		// The notice box stays core's to draw — its id, its classes, its wrapper. Only what is
		// inside the paragraph belongs to this library.
		$this->assertStringStartsWith( '<div id="message" class="notice error"><p>', $filtered );
		$this->assertStringEndsWith( '</p></div>', $filtered );
	}

	public function test_the_default_names_the_sub_plugin(): void {
		$filtered = $this->make_rewriter( $this->standalone_owner() )->rewrite( $this->markup );

		// The fallback is not pinned word for word — it is allowed to be reworded, as long as it
		// still names the sub-plugin and still displaces core's sentence.
		$this->assertStringContainsString( 'give-recurring', $filtered );
		$this->assertStringNotContainsString( self::ACTIVATION_TEXT, $filtered );
	}

	/**
	 * Rewording the sentence is only half the screen. Core appends an iframe requesting
	 * `plugins.php?action=error_scrape`, which runs `plugin_sandbox_scrape()` again with
	 * `display_errors` forced on — so the raw `Cannot redeclare …` fatal prints inside the same
	 * notice box, immediately under the friendly explanation that just said the situation is
	 * handled. Whichever of the two the owner believes, one of them wasted their afternoon.
	 */
	public function test_it_removes_the_activation_sandbox_iframe(): void {
		// The fixture really is core's: the iframe is in it before the rewrite runs.
		$this->assertStringContainsString( 'error_scrape', $this->markup );
		$this->assertStringContainsString( '<iframe', $this->markup );

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$filtered = $rewriter->rewrite( $this->markup );

		$this->assertStringContainsString( 'Ours.', $filtered );
		$this->assertStringNotContainsString( '<iframe', $filtered );
		$this->assertStringNotContainsString( 'error_scrape', $filtered );

		// Nothing else core wrote went with it. Asserted against core's own builder rather than a
		// literal box, so this stays a claim about the iframe and not about the day core adds a
		// class to its notices.
		$this->assertSame( $this->notice_box( 'Ours.' ), $filtered );
	}

	/**
	 * The removal is aimed at one request in wp-admin — the one that re-runs the fatal — and not at
	 * iframes. Another plugin filtering this notice ahead of us is entitled to have put something of
	 * its own in it, and deleting that would be this library breaking a screen it came to fix.
	 */
	public function test_it_leaves_an_iframe_that_is_not_the_sandbox_scrape_alone(): void {
		$foreign = '<iframe style="border:0" width="100%" height="70px" src="https://example.com/help"></iframe>';

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$filtered = $rewriter->rewrite(
			str_replace( '</p></div>', $foreign . '</p></div>', $this->markup )
		);

		$this->assertStringContainsString( 'Ours.', $filtered );
		$this->assertStringContainsString( $foreign, $filtered );
		$this->assertStringNotContainsString( 'error_scrape', $filtered );
	}

	/**
	 * Core's second wording for the same conflict. Its fatal-error handler pauses the plugin the
	 * activation sandbox died in, so the standalone reappears on the plugins list with a Resume
	 * link; pressing it re-runs the same sandbox into the same re-declaration, and core reports
	 * "could not be resumed" instead of "could not be activated". Matching only the activation
	 * wording leaves the owner reading core's useless sentence on the second screen after having
	 * been given a real explanation on the first.
	 */
	public function test_it_replaces_the_resume_wording(): void {
		$this->arrange_resume_error();

		$markup   = $this->resume_error_notice();
		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$filtered = $rewriter->rewrite( $markup );

		$this->assertStringContainsString( 'Ours.', $filtered );
		$this->assertStringNotContainsString( self::RESUME_TEXT, $filtered );
		$this->assertStringStartsWith( '<div id="message" class="notice error"><p>', $filtered );
	}

	/**
	 * The resume redirect carries no `plugin` argument at all — `resume_plugin()` appends only an
	 * `_error_nonce`, minted from `plugin-resume-error_` and the basename — so the nonce is the one
	 * thing on the request naming a plugin, and a nonce signed for somebody else's names somebody
	 * else's.
	 */
	public function test_it_leaves_the_resume_markup_alone_for_a_plugin_no_sub_plugin_claims(): void {
		$this->arrange_resume_error();

		$markup = $this->resume_error_notice();

		$this->assertStringContainsString(
			'Ours.',
			$this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) )
				->rewrite( $markup )
		);

		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-resume-error_akismet/akismet.php' );

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$this->assertSame( $markup, $rewriter->rewrite( $markup ) );
	}

	/**
	 * The request names one plugin, and only the sub-plugin that claims that basename may speak for
	 * it. Reading the first registration instead would put one bundled plugin's explanation on
	 * another's activation error.
	 */
	public function test_it_uses_the_sub_plugin_whose_standalone_the_request_names(): void {
		$rewriter = $this->make_rewriter(
			$this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'The wrong one.' ] ),
			$this->make_sub_plugin(
				[
					'slug'                       => 'give-fee-recovery',
					'standalone_plugin_basename' => 'give-fee-recovery/give-fee-recovery.php',
					'conflict_notice_message'    => static fn() => 'The right one.',
				]
			)
		);

		$_GET['plugin']       = 'give-fee-recovery/give-fee-recovery.php';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_give-fee-recovery/give-fee-recovery.php' );

		$filtered = $rewriter->rewrite( $this->activation_error_notice() );

		$this->assertStringContainsString( 'The right one.', $filtered );
		$this->assertStringNotContainsString( 'The wrong one.', $filtered );
	}

	/**
	 * Core mints the activation-error nonce from `wp_unslash( $_REQUEST['plugin'] )` and nothing
	 * else (wp-admin/plugins.php), so the value this class verifies against has to be the same one.
	 * Sanitizing it first signs one action and checks another, and the rewrite then declines on
	 * exactly the screen it exists to improve — silently, because declining looks identical to
	 * "this plugin is none of ours".
	 *
	 * @dataProvider standalones_sanitizing_would_alter
	 *
	 * @param string $standalone Basename of a standalone whose folder name sanitizing changes.
	 */
	public function test_it_rewrites_for_a_standalone_whose_basename_sanitizing_would_alter( string $standalone ): void {
		$rewriter = $this->make_rewriter(
			$this->make_sub_plugin(
				[
					'standalone_plugin_basename' => $standalone,
					'conflict_notice_message'    => static fn() => 'Ours.',
				]
			)
		);

		$_GET['plugin']       = $standalone;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . $standalone );

		$this->assertStringContainsString( 'Ours.', $rewriter->rewrite( $this->activation_error_notice() ) );
	}

	/**
	 * Every one of these is a directory a plugin can really be unzipped into, and every one of them
	 * comes back changed from `sanitize_text_field()`: '%xx' sequences are deleted outright, a bare
	 * '<' is entity-encoded, and leading whitespace is trimmed.
	 *
	 * @return Generator<string,array{0:string}>
	 */
	public static function standalones_sanitizing_would_alter(): Generator {
		yield 'a folder name holding a percent sequence' => [ 'give%20recurring/give-recurring.php' ];
		yield 'a folder name holding a less-than'        => [ 'give<recurring/give-recurring.php' ];
		yield 'a folder name holding a leading space'    => [ ' give-recurring/give-recurring.php' ];
	}

	/**
	 * Nothing draws an admin notice on the front end, and `get_current_screen()` does not exist
	 * there — the guard is what keeps this filter from fataling if another plugin ever applies
	 * `wp_admin_notice_markup` outside wp-admin.
	 */
	public function test_it_leaves_the_markup_alone_outside_the_admin(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		set_current_screen( 'front' );

		$this->assertSame( $this->markup, $rewriter->rewrite( $this->markup ) );
	}

	/**
	 * The activation error is only ever reported on the plugins screen. Every other admin screen
	 * that prints a notice carrying core's sentence — a bulk action reported elsewhere, a plugin
	 * quoting it — is somebody else's.
	 */
	public function test_it_leaves_the_markup_alone_off_the_plugins_screen(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		set_current_screen( 'dashboard' );

		$this->assertSame( $this->markup, $rewriter->rewrite( $this->markup ) );
	}

	/**
	 * wp-admin/network/plugins.php is a one-line require of wp-admin/plugins.php, so it draws the
	 * same activation error — but WP_Screen appends `-network` to the id. On a default multisite
	 * the subsite has no plugins UI, so this is the only screen a super admin can reactivate an
	 * absorbed standalone from, and matching 'plugins' alone would decline exactly there.
	 */
	public function test_it_rewrites_the_markup_on_the_network_plugins_screen(): void {
		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		set_current_screen( 'plugins-network' );

		$this->assertStringContainsString( 'Ours.', $rewriter->rewrite( $this->markup ) );
	}

	/**
	 * The nonce is valid here, so the ownership lookup is the only thing that can stop the rewrite:
	 * an activation error for a plugin this library knows nothing about keeps core's wording, which
	 * for that plugin is the accurate one — and keeps the sandbox iframe, which for that plugin is
	 * the only diagnostic anyone has.
	 */
	public function test_it_leaves_the_markup_alone_for_a_plugin_no_sub_plugin_claims(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$_GET['plugin']       = 'akismet/akismet.php';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_akismet/akismet.php' );

		$markup = $this->activation_error_notice();

		$filtered = $rewriter->rewrite( $markup );

		$this->assertSame( $markup, $filtered );
		$this->assertStringContainsString( 'error_scrape', $filtered );
	}

	/**
	 * @dataProvider requests_that_are_not_an_activation_error
	 *
	 * @param callable $arrange Turns the request in setUp() into the one this case is about.
	 */
	public function test_it_leaves_the_markup_alone( callable $arrange ): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$arrange();

		$this->assertSame( $this->markup, $rewriter->rewrite( $this->markup ) );
	}

	/**
	 * The nonce cases are the ones that matter most: `plugin` is attacker-controlled, and without
	 * verification any link could make an arbitrary admin page quote a sub-plugin's message back at
	 * whoever followed it. The nonce is built inside the closure rather than in the provider,
	 * because a provider runs before setUp() and a nonce is bound to the user current at the time.
	 *
	 * @return Generator<string,array{0:callable}>
	 */
	public static function requests_that_are_not_an_activation_error(): Generator {
		yield 'a nonce for another plugin' => [
			static function (): void {
				$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_akismet/akismet.php' );
			},
		];

		yield 'a nonce that is not one' => [
			static function (): void {
				$_GET['_error_nonce'] = 'not-a-nonce';
			},
		];

		yield 'an empty nonce' => [
			static function (): void {
				$_GET['_error_nonce'] = '';
			},
		];

		yield 'no nonce at all' => [
			static function (): void {
				unset( $_GET['_error_nonce'] );
			},
		];

		yield 'no plugin named' => [
			static function (): void {
				unset( $_GET['plugin'] );
			},
		];

		yield 'an empty plugin name' => [
			static function (): void {
				$_GET['plugin'] = '';
			},
		];

		// Not a string at all: `plugin[]=x` in the query string arrives as an array, which
		// sanitize_text_field() would fatal on if it were not unslashed and sanitised as one.
		yield 'a plugin name that is an array' => [
			static function (): void {
				$_GET['plugin'] = [ self::STANDALONE ];
			},
		];
	}

	/**
	 * A notice carrying neither of core's two sentences was authored by something else, and this
	 * library has nothing to say about it. The iframe stays with it: a notice nobody explained is
	 * bad, and a notice nobody explained with its one diagnostic quietly deleted is worse.
	 */
	public function test_it_leaves_a_notice_holding_neither_sentence_alone(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) );

		$markup = str_replace( self::ACTIVATION_TEXT, 'Something else went wrong.', $this->markup );

		$filtered = $rewriter->rewrite( $markup );

		$this->assertSame( $markup, $filtered );
		$this->assertStringContainsString( 'error_scrape', $filtered );
	}

	/**
	 * The message is sanitised before it is checked for emptiness, and this is the case that pins
	 * the order: `wp_kses_post( '<script></script>' )` is the empty string, so swapping core's
	 * wording for it would leave an empty notice box where the explanation should be. Leaving
	 * core's sentence in place is the better of the two bad outcomes — and the iframe stays under
	 * it, because a screen still showing core's wording is a screen this library did not improve,
	 * and its raw fatal is the only thing left explaining anything.
	 */
	public function test_a_message_that_sanitises_away_leaves_the_markup_alone(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter(
			$this->standalone_owner( [ 'conflict_notice_message' => static fn() => '<script></script>' ] )
		);

		$filtered = $rewriter->rewrite( $this->markup );

		$this->assertSame( $this->markup, $filtered );
		$this->assertStringContainsString( 'error_scrape', $filtered );
	}

	/**
	 * A message that is only whitespace is the same failure with a friendlier shape.
	 */
	public function test_a_whitespace_only_message_leaves_the_markup_alone(): void {
		$this->assert_the_arrangement_rewrites();

		$rewriter = $this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => "  \n\t" ] ) );

		$this->assertSame( $this->markup, $rewriter->rewrite( $this->markup ) );
	}

	/**
	 * `wp_kses_post()` and not `esc_html()`, for the reason the renderer uses it: these messages
	 * come from the host's own configuration and filters rather than from user input, so the link to
	 * the knowledge-base article explaining the merge has to reach the screen intact — while the
	 * event handler and the script a message must never be able to ship do not.
	 */
	public function test_it_strips_unsafe_markup_from_the_replacement_but_keeps_a_link(): void {
		$rewriter = $this->make_rewriter(
			$this->standalone_owner(
				[
					'conflict_notice_message' => static fn() => 'See <a href="https://example.com" onclick="alert(1)">the docs</a>.'
						. '<script>alert(2)</script>',
				]
			)
		);

		$filtered = $rewriter->rewrite( $this->markup );

		$this->assertStringContainsString( '<a href="https://example.com">the docs</a>', $filtered );
		$this->assertStringNotContainsString( 'onclick', $filtered );
		$this->assertStringNotContainsString( '<script', $filtered );

		// The disallowed tag goes and the text it wrapped stays, so the payload survives as inert
		// text rather than as markup the browser would run.
		$this->assertStringContainsString( 'alert(2)', $filtered );
	}

	/**
	 * The request setUp() leaves behind really does earn a rewrite.
	 *
	 * Every "leaves the markup alone" test asserts that the markup came back unchanged, and unchanged
	 * markup is also what a broken arrangement produces: a renamed screen id, a nonce action core
	 * reworded, a fixture that stopped claiming the standalone. Without a control saying the
	 * arrangement was rewriting a moment ago, all of those pass instead of failing. It builds its own
	 * rewriter with a message of its own, so the tests about the *message* are controlled too.
	 */
	private function assert_the_arrangement_rewrites(): void {
		$this->assertStringContainsString(
			'Ours.',
			$this->make_rewriter( $this->standalone_owner( [ 'conflict_notice_message' => static fn() => 'Ours.' ] ) )
				->rewrite( $this->markup )
		);
	}

	/**
	 * Turn the request in setUp() into the one core redirects to when recovery mode cannot resume a
	 * plugin: `resume_plugin()` appends an `_error_nonce` for `plugin-resume-error_<basename>` to
	 * `plugins.php?error=resuming&…`, and there is no `plugin` argument anywhere on it.
	 */
	private function arrange_resume_error(): void {
		unset( $_GET['plugin'] );

		$_GET['error']        = 'resuming';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-resume-error_' . self::STANDALONE );
	}

	/**
	 * The activation-error notice as `wp-admin/plugins.php` assembles it, for whatever plugin and
	 * nonce the request currently names.
	 *
	 * Transcribed from core rather than approximated, because both halves of the rewrite are
	 * decided by what is really in that string: the sentence core's `else` branch chooses, and the
	 * `error_scrape` iframe appended after it whenever the request carries a verifying
	 * `_error_nonce` — which, on every request this class acts on, it does. A fixture ending at the
	 * sentence would leave the iframe removal proven by nothing.
	 */
	private function activation_error_notice(): string {
		$plugin = isset( $_GET['plugin'] ) && is_string( $_GET['plugin'] ) ? $_GET['plugin'] : '';
		$nonce  = isset( $_GET['_error_nonce'] ) && is_string( $_GET['_error_nonce'] ) ? $_GET['_error_nonce'] : '';

		$iframe_url = add_query_arg(
			[
				'action'   => 'error_scrape',
				'plugin'   => urlencode( $plugin ),
				'_wpnonce' => urlencode( $nonce ),
			],
			admin_url( 'plugins.php' )
		);

		return $this->notice_box(
			self::ACTIVATION_TEXT
				. '<iframe style="border:0" width="100%" height="70px" src="' . esc_url( $iframe_url ) . '"></iframe>'
		);
	}

	/**
	 * The resume-error notice as `wp-admin/plugins.php` assembles it.
	 *
	 * No iframe under this one, and that is core's doing rather than a shortcut here: the iframe is
	 * appended only when `_error_nonce` verifies against `plugin-activation-error_` plus the
	 * `plugin` argument, and the resume redirect carries neither a `plugin` argument nor that kind
	 * of nonce.
	 */
	private function resume_error_notice(): string {
		return $this->notice_box( self::RESUME_TEXT );
	}

	/**
	 * The box core wraps either sentence in, built by core's own function so the id, the classes and
	 * the paragraph wrap are whatever this WordPress really produces.
	 *
	 * @param string $errmsg The message core assembled.
	 *
	 * @return string
	 */
	private function notice_box( string $errmsg ): string {
		return wp_get_admin_notice(
			$errmsg,
			[
				'id'                 => 'message',
				'additional_classes' => [ 'error' ],
			]
		);
	}

	/**
	 * A sub-plugin claiming the standalone this suite's request names.
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 *
	 * @return Sub_Plugin
	 */
	private function standalone_owner( array $overrides = [] ): Sub_Plugin {
		return $this->make_sub_plugin(
			array_merge( [ 'standalone_plugin_basename' => self::STANDALONE ], $overrides )
		);
	}

	/**
	 * The rewriter under test, built directly rather than resolved.
	 *
	 * Its one collaborator is a required argument, and it is a stub rather than the registration
	 * buffer behind `Absorber::register()`: what the rewrite finds is then what the test handed it,
	 * with no container, no registrar and no static state in between.
	 *
	 * @param Sub_Plugin ...$sub_plugins Sub-plugins the registry holds, in registration order.
	 *
	 * @return Rewriter
	 */
	private function make_rewriter( Sub_Plugin ...$sub_plugins ): Rewriter {
		return new Rewriter( new Stub_Registry_Reader( $sub_plugins ) );
	}
}
