<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Notices;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Queue;
use Nexcess\PluginAbsorber\Notices\Renderer;
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;

/**
 * The one conflict the load guard cannot prevent: WordPress includes the standalone after the
 * bundled copy has already loaded, the re-declaration is a real fatal, and core's activation sandbox
 * reports it as "the plugin triggered a fatal error" — true, and useless to whoever pressed the
 * button. This class covers rewriting that sentence, and every reason not to.
 *
 * @since 1.0.0
 */
class QueueActivationErrorTest extends WPTestCase {
	use WithContainer;

	/**
	 * The standalone the registered sub-plugin claims.
	 *
	 * @var string
	 */
	private const STANDALONE = 'give-recurring/give-recurring.php';

	/**
	 * Core's own sentence, spelled out rather than built with `__()`.
	 *
	 * Restating it is the point: the rewrite is a `str_replace()` against this exact string, so if
	 * core ever rewords it the replacement silently stops happening, and a test that asked core for
	 * the needle would go on passing while the site showed the useless message again.
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

	public function setUp(): void {
		parent::setUp();

		Loader_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );

		// The rewrite reads the registry through Loader::all(), which resolves the registrar, so
		// these tests need a container like every other test that reaches a collaborator.
		$this->set_up_container();

		// is_admin() reads the current screen before WP_ADMIN, so this is what puts the request in
		// the admin as well as on the right screen.
		set_current_screen( 'plugins' );

		// Every test starts from the request core actually redirects to after a sandboxed fatal, and
		// states only the part it is about.
		$_GET['plugin']       = self::STANDALONE;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . self::STANDALONE );
	}

	public function tearDown(): void {
		// In tearDown rather than at the end of each test body: a failed assertion would otherwise
		// leave an admin screen and a half-built activation-error request standing for every test
		// that runs afterwards in this process.
		unset( $_GET['plugin'], $_GET['_error_nonce'] );
		set_current_screen( 'front' );

		Loader_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_it_replaces_the_fatal_error_text_with_the_configured_message(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Give Recurring is already bundled with Give.' ] );

		$filtered = $this->make_queue()->filter_activation_error_markup( self::MARKUP );

		$this->assertStringContainsString( 'Give Recurring is already bundled with Give.', $filtered );
		$this->assertStringNotContainsString( self::CORE_TEXT, $filtered );

		// The notice box stays core's to draw — its classes, its dismiss button, its wrapper. Only
		// the sentence inside belongs to this library.
		$this->assertStringStartsWith( '<div class="notice notice-error is-dismissible"><p>', $filtered );
		$this->assertStringEndsWith( '</p></div>', $filtered );
	}

	public function test_the_default_names_the_sub_plugin(): void {
		$this->register();

		$filtered = $this->make_queue()->filter_activation_error_markup( self::MARKUP );

		// The fallback is not pinned word for word — it is allowed to be reworded, as long as it
		// still names the sub-plugin and still displaces core's sentence.
		$this->assertStringContainsString( 'give-recurring', $filtered );
		$this->assertStringNotContainsString( self::CORE_TEXT, $filtered );
	}

	/**
	 * The request names one plugin, and only the sub-plugin that claims that basename may speak for
	 * it. Reading the first registration instead would put one bundled plugin's explanation on
	 * another's activation error.
	 */
	public function test_it_uses_the_sub_plugin_whose_standalone_the_request_names(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'The wrong one.' ] );
		$this->register(
			[
				'slug'                       => 'give-fee-recovery',
				'standalone_plugin_basename' => 'give-fee-recovery/give-fee-recovery.php',
				'conflict_notice_message'    => static fn() => 'The right one.',
			]
		);

		$_GET['plugin']       = 'give-fee-recovery/give-fee-recovery.php';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_give-fee-recovery/give-fee-recovery.php' );

		$filtered = $this->make_queue()->filter_activation_error_markup( self::MARKUP );

		$this->assertStringContainsString( 'The right one.', $filtered );
		$this->assertStringNotContainsString( 'The wrong one.', $filtered );
	}

	/**
	 * Nothing draws an admin notice on the front end, and `get_current_screen()` does not exist
	 * there — the guard is what keeps this filter from fataling if another plugin ever applies
	 * `wp_admin_notice_markup` outside wp-admin.
	 */
	public function test_it_leaves_the_markup_alone_outside_the_admin(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Ours.' ] );

		set_current_screen( 'front' );

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
	}

	/**
	 * The activation error is only ever reported on the plugins screen. Every other admin screen
	 * that prints a notice carrying core's sentence — a bulk action reported elsewhere, a plugin
	 * quoting it — is somebody else's.
	 */
	public function test_it_leaves_the_markup_alone_off_the_plugins_screen(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Ours.' ] );

		set_current_screen( 'dashboard' );

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
	}

	/**
	 * wp-admin/network/plugins.php is a one-line require of wp-admin/plugins.php, so it draws the
	 * same activation error — but WP_Screen appends `-network` to the id. On a default multisite
	 * the subsite has no plugins UI, so this is the only screen a super admin can reactivate an
	 * absorbed standalone from, and matching 'plugins' alone would decline exactly there.
	 */
	public function test_it_rewrites_the_markup_on_the_network_plugins_screen(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Ours.' ] );

		set_current_screen( 'plugins-network' );

		$this->assertStringContainsString(
			'Ours.',
			$this->make_queue()->filter_activation_error_markup( self::MARKUP )
		);
	}

	/**
	 * The nonce is valid here, so the ownership lookup is the only thing that can stop the rewrite:
	 * an activation error for a plugin this library knows nothing about keeps core's wording, which
	 * for that plugin is the accurate one.
	 */
	public function test_it_leaves_the_markup_alone_for_a_plugin_no_sub_plugin_claims(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Ours.' ] );

		$_GET['plugin']       = 'akismet/akismet.php';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_akismet/akismet.php' );

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
	}

	/**
	 * @dataProvider requests_that_are_not_an_activation_error
	 *
	 * @param callable $arrange Turns the request in setUp() into the one this case is about.
	 */
	public function test_it_leaves_the_markup_alone( callable $arrange ): void {
		$this->register( [ 'conflict_notice_message' => static fn() => 'Ours.' ] );

		$arrange();

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
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
	 * The message is sanitised before it is checked for emptiness, and this is the case that pins
	 * the order: `wp_kses_post( '<script></script>' )` is the empty string, so swapping core's
	 * wording for it would leave an empty notice box where the explanation should be. Leaving
	 * core's sentence in place is the better of the two bad outcomes.
	 */
	public function test_a_message_that_sanitises_away_leaves_the_markup_alone(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => '<script></script>' ] );

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
	}

	/**
	 * A message that is only whitespace is the same failure with a friendlier shape.
	 */
	public function test_a_whitespace_only_message_leaves_the_markup_alone(): void {
		$this->register( [ 'conflict_notice_message' => static fn() => "  \n\t" ] );

		$this->assertSame( self::MARKUP, $this->make_queue()->filter_activation_error_markup( self::MARKUP ) );
	}

	/**
	 * `wp_kses_post()` and not `esc_html()`, for the reason the renderer uses it: these messages
	 * come from the host's own configuration and filters rather than from user input, so the link to
	 * the knowledge-base article explaining the merge has to reach the screen intact — while the
	 * event handler and the script a message must never be able to ship do not.
	 */
	public function test_it_strips_unsafe_markup_from_the_replacement_but_keeps_a_link(): void {
		$this->register(
			[
				'conflict_notice_message' => static fn() => 'See <a href="https://example.com" onclick="alert(1)">the docs</a>.'
					. '<script>alert(2)</script>',
			]
		);

		$filtered = $this->make_queue()->filter_activation_error_markup( self::MARKUP );

		$this->assertStringContainsString( '<a href="https://example.com">the docs</a>', $filtered );
		$this->assertStringNotContainsString( 'onclick', $filtered );
		$this->assertStringNotContainsString( '<script', $filtered );

		// The disallowed tag goes and the text it wrapped stays, so the payload survives as inert
		// text rather than as markup the browser would run.
		$this->assertStringContainsString( 'alert(2)', $filtered );
	}

	/**
	 * Register a sub-plugin that claims the standalone this suite's request names.
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 *
	 * @return void
	 */
	private function register( array $overrides = [] ): void {
		$slug = isset( $overrides['slug'] ) && is_string( $overrides['slug'] ) && $overrides['slug'] !== ''
			? $overrides['slug']
			: 'give-recurring';

		Loader::register(
			array_merge(
				[
					'slug'                       => $slug,
					'bundled_plugin_file'        => "/tmp/{$slug}/{$slug}.php",
					'plugin_loaded_constant'     => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
					'standalone_plugin_basename' => self::STANDALONE,
				],
				$overrides
			)
		);
	}

	/**
	 * The queue under test, built directly rather than resolved.
	 *
	 * Its two collaborators are required arguments and neither is reached by the rewrite — nothing
	 * here is stored and nothing here is drawn — so handing over the real pair states plainly that
	 * this file is about one method.
	 *
	 * @return Queue
	 */
	private function make_queue(): Queue {
		return new Queue( new Store(), new Renderer() );
	}
}
