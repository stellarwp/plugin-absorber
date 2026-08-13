<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Notices;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Notices\Presenter;
use Nexcess\PluginAbsorber\Notices\Renderer;
use Nexcess\PluginAbsorber\Notices\Store;
use Nexcess\PluginAbsorber\Notices\Writer;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithUsers;

/**
 * Who may consume the queue, and what happens when they do.
 *
 * What a notice says and which `slug:type` key it lands under is `WriterTest`'s: this class asks
 * only about `render()` — the markup, the severities, the capability gate, and clearing — so every
 * notice here is queued through a plain `Writer` over the same option-backed `Store` a `Presenter`
 * reads by default.
 *
 * @since 1.0.0
 */
class PresenterTest extends WPTestCase {
	use WithSubPlugins;
	use WithUsers;

	private const OPTION = 'give_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );
		$this->clear_queue();

		// render() consumes the queue, so it is gated on a capability. Most tests care about the
		// queue rather than the gate, so they run as someone who has it — which on multisite is a
		// network administrator, see test_a_site_administrator_on_multisite_cannot_consume_the_queue().
		$this->become_plugin_administrator();
	}

	public function tearDown(): void {
		$this->clear_queue();
		Config_State::reset();
		parent::tearDown();
	}

	public function test_render_outputs_dismissible_markup(): void {
		$this->queue_notice(
			'queue_merge_notice',
			[ 'conflict_notice_message' => static fn() => 'Bundled now.' ]
		);

		$output = $this->render_to_string( $this->make_presenter() );

		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	/**
	 * @dataProvider notice_severities
	 *
	 * @param string $method Method on Writer that queues the notice.
	 * @param string $class  Expected `notice-*` class.
	 */
	public function test_render_uses_the_severity_of_the_notice_type( string $method, string $class ): void {
		$this->queue_notice(
			$method,
			[ 'conflict_notice_message' => static fn() => 'Something happened.' ]
		);

		$this->assertStringContainsString(
			'notice ' . $class . ' is-dismissible',
			$this->render_to_string( $this->make_presenter() )
		);
	}

	/**
	 * A dependency notice reports a plugin that did not load, which is an error; the conflict
	 * pair report something the library handled, which is a warning.
	 *
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function notice_severities(): Generator {
		yield 'merge'      => [ 'queue_merge_notice', 'notice-warning' ];
		yield 'conflict'   => [ 'queue_conflict_notice', 'notice-warning' ];
		yield 'dependency' => [ 'queue_dependency_notice', 'notice-error' ];
	}

	public function test_render_strips_a_script_from_the_message(): void {
		$this->queue_notice(
			'queue_merge_notice',
			[ 'conflict_notice_message' => static fn() => 'Careful.<script>alert(1)</script>' ]
		);

		$output = $this->render_to_string( $this->make_presenter() );

		// `wp_kses_post()` drops the disallowed tag and keeps the text it wrapped, so the payload
		// survives as inert text rather than as markup the browser would run.
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'alert(1)', $output );
	}

	/**
	 * Messages come from the host's own configuration or filters rather than from user input, so a
	 * message is allowed to carry a link — to the knowledge-base article explaining the merge,
	 * typically — while the event handler a message must never be able to ship is stripped.
	 */
	public function test_render_keeps_a_link_but_not_an_event_handler(): void {
		$this->queue_notice(
			'queue_merge_notice',
			[
				'conflict_notice_message' => static fn() =>
					'See <a href="https://example.com" onclick="alert(1)">the docs</a>.',
			]
		);

		$output = $this->render_to_string( $this->make_presenter() );

		$this->assertStringContainsString( '<a href="https://example.com">the docs</a>', $output );
		$this->assertStringNotContainsString( 'onclick', $output );
	}

	public function test_render_clears_the_queue(): void {
		$this->queue_notice( 'queue_merge_notice' );

		// The queue has to be there before rendering can be what took it away: a writer that never
		// wrote, or an option name the two halves disagree about, would satisfy the assertion below
		// without a presenter having cleared anything.
		$this->assertTrue( $this->queue_exists(), 'The queue must exist before it is rendered.' );

		$presenter = $this->make_presenter();
		$this->render_to_string( $presenter );

		$this->assertFalse( $this->queue_exists() );
		$this->assertSame( '', $this->render_to_string( $presenter ), 'A second render must output nothing.' );
	}

	public function test_render_outputs_every_queued_notice(): void {
		$this->queue_notice( 'queue_merge_notice', [ 'conflict_notice_message' => static fn() => 'First.' ] );
		$this->queue_notice(
			'queue_dependency_notice',
			[ 'dependency_notice_message' => static fn() => 'Second.' ]
		);

		$output = $this->render_to_string( $this->make_presenter() );

		$this->assertStringContainsString( 'First.', $output );
		$this->assertStringContainsString( 'Second.', $output );
	}

	public function test_render_outputs_nothing_when_the_queue_is_empty(): void {
		$this->assertSame( '', $this->render_to_string( $this->make_presenter() ) );
	}

	/**
	 * The other half of the writer/store seam: the renderer is a constructor argument too, so a
	 * host wanting different markup — one already using stellarwp/admin-notices, say — has one
	 * small thing to replace rather than the whole presenter.
	 */
	public function test_a_replacement_renderer_draws_the_queue(): void {
		$renderer = new class() extends Renderer {
			/**
			 * @param array<string,string> $queue Queue to draw.
			 *
			 * @return void
			 */
			public function render( array $queue ): void {
				echo '<p class="mine">' . count( $queue ) . '</p>';
			}
		};

		$this->queue_notice( 'queue_merge_notice' );

		$output = $this->render_to_string( $this->make_presenter( null, $renderer ) );

		$this->assertSame( '<p class="mine">1</p>', $output );
		$this->assertFalse( $this->queue_exists(), 'A replacement renderer still consumes the queue.' );
	}

	/**
	 * The capability gate guards the clearing as much as the drawing, so it has to sit in front of
	 * the renderer rather than inside it: a user who may not see the queue must not destroy it.
	 */
	public function test_a_replacement_renderer_is_never_reached_without_the_capability(): void {
		$renderer = new class() extends Renderer {
			/**
			 * @var bool
			 */
			public $called = false;

			/**
			 * @param array<string,string> $queue Queue to draw.
			 *
			 * @return void
			 */
			public function render( array $queue ): void {
				$this->called = true;
			}
		};

		$presenter = $this->make_presenter( null, $renderer );

		// The control belongs in this test rather than in a sibling: a presenter that reached no
		// renderer at all, or a renderer this one never received, would satisfy the assertion at the
		// end for a reason that has nothing to do with the capability.
		$this->queue_notice( 'queue_merge_notice' );

		$this->render_to_string( $presenter );

		$this->assertTrue( $renderer->called, 'This renderer must be reachable for someone who may consume the queue.' );

		$renderer->called = false;

		$this->queue_notice( 'queue_merge_notice' );

		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$this->render_to_string( $presenter );

		$this->assertFalse( $renderer->called );
		$this->assertTrue( $this->queue_exists() );
	}

	/**
	 * The capability is asked before the queue is read, not after.
	 *
	 * The gate gets its authority from being in front of both halves: reading is what the clearing
	 * follows from, so a presenter that read the queue and then decided who may see it would already
	 * have taken the notice out of the store by the time it turned the subscriber away. Asserting on
	 * the output alone cannot see that difference, so the store here counts the calls it receives.
	 */
	public function test_the_capability_is_checked_before_the_queue_is_read(): void {
		$store = new class() extends Store {
			/**
			 * @var int
			 */
			public $reads = 0;

			/**
			 * @var int
			 */
			public $clears = 0;

			/**
			 * @return array<string,string>
			 */
			public function all(): array {
				++$this->reads;

				return [ 'give-recurring:merge' => 'Bundled now.' ];
			}

			/**
			 * @return void
			 */
			public function clear(): void {
				++$this->clears;
			}
		};

		$presenter = $this->make_presenter( $store );

		wp_set_current_user( $this->create_user( 'subscriber' ) );

		$this->assertSame( '', $this->render_to_string( $presenter ) );
		$this->assertSame( 0, $store->reads, 'The queue must not be read at all for a user who may not consume it.' );
		$this->assertSame( 0, $store->clears, 'And it must certainly not be cleared.' );

		// The recorder has to be shown working, or "never read" and "never wired to anything" are the
		// same result: the same store, in the same presenter, for a user who does have the capability.
		$this->become_plugin_administrator();

		$this->assertStringContainsString( 'Bundled now.', $this->render_to_string( $presenter ) );
		$this->assertSame( 1, $store->reads );
		$this->assertSame( 1, $store->clears );
	}

	/**
	 * Rendering consumes the queue, so a user who cannot act on the notice must neither see it
	 * nor destroy it. The merge notice is raised once and never re-queued.
	 *
	 * @dataProvider users_who_cannot_activate_plugins
	 *
	 * @param string|null $role Role to render as, or null for a logged-out visitor.
	 */
	public function test_render_does_nothing_for_a_user_who_cannot_activate_plugins( ?string $role ): void {
		$this->queue_notice(
			'queue_merge_notice',
			[ 'conflict_notice_message' => static fn() => 'Bundled now.' ]
		);

		wp_set_current_user( $role === null ? 0 : $this->create_user( $role ) );

		$this->assertSame( '', $this->render_to_string( $this->make_presenter() ) );
		$this->assertTrue( $this->queue_exists(), 'The queue must survive for someone who can act on it.' );
	}

	/**
	 * @return Generator<string,array{0:string|null}>
	 */
	public static function users_who_cannot_activate_plugins(): Generator {
		yield 'a subscriber'         => [ 'subscriber' ];
		yield 'a logged-out visitor' => [ null ];
	}

	/**
	 * Surprising but intended: on multisite `activate_plugins` maps through
	 * `manage_network_plugins`, which only a super admin has unless the network has opened the
	 * plugins menu to site admins. So the person who installed the plugin on their own site is
	 * not the person who sees the notice — a network administrator is.
	 */
	public function test_a_site_administrator_on_multisite_cannot_consume_the_queue(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Outside multisite an administrator simply has activate_plugins.' );
		}

		$this->queue_notice(
			'queue_merge_notice',
			[ 'conflict_notice_message' => static fn() => 'Bundled now.' ]
		);

		wp_set_current_user( $this->create_user( 'administrator' ) );

		$this->assertSame( '', $this->render_to_string( $this->make_presenter() ) );
		$this->assertTrue( $this->queue_exists(), 'The queue must survive for the network administrator.' );
	}

	/**
	 * @dataProvider malformed_queues
	 *
	 * @param mixed         $stored  Raw option value to seed.
	 * @param string|null   $present Substring the output must contain, or null when nothing at
	 *                               all should be rendered.
	 * @param array<string> $absent  Substrings the output must not contain.
	 */
	public function test_render_ignores_anything_that_is_not_a_message( $stored, ?string $present, array $absent ): void {
		$this->seed_queue( $stored );

		$output = $this->render_to_string( $this->make_presenter() );

		if ( $present === null ) {
			$this->assertSame( '', $output );
		} else {
			$this->assertStringContainsString( $present, $output );
		}

		foreach ( $absent as $needle ) {
			$this->assertStringNotContainsString( $needle, $output );
		}
	}

	/**
	 * The first two are the likeliest real corruption: another plugin, or a host reading and
	 * rewriting the option, leaves something behind that is not an array at all. The rest are
	 * per-entry rubbish, which is dropped without taking the well-formed entries with it.
	 *
	 * @return Generator<string,array{0:mixed,1:string|null,2:array<string>}>
	 */
	public static function malformed_queues(): Generator {
		yield 'a scalar instead of an array' => [ 'not-a-queue', null, [ 'not-a-queue', 'notice' ] ];

		yield 'an object instead of an array' => [
			(object) [ 'a:merge' => 'Nope.' ],
			null,
			[ 'Nope.', 'notice' ],
		];

		yield 'entries that are not strings' => [
			[
				'a:merge' => 'Fine.',
				'b:merge' => [ 'nested' ],
				'c:merge' => null,
				'd:merge' => 42,
			],
			'Fine.',
			[ 'Array', '42' ],
		];

		yield 'an empty message' => [ [ 'a:merge' => '' ], null, [ 'notice' ] ];

		// A message that is only whitespace would otherwise print an empty notice box.
		yield 'a whitespace-only message' => [ [ 'a:merge' => "  \n\t" ], null, [ 'notice' ] ];
	}

	/**
	 * Queue a notice through a plain Writer over the default, option-backed Store — the same one
	 * a Presenter built with no store of its own reads.
	 *
	 * @param string              $method    Method on Writer that queues the notice.
	 * @param array<string,mixed> $overrides Sub-plugin config overrides.
	 *
	 * @return void
	 */
	private function queue_notice( string $method, array $overrides = [] ): void {
		( new Writer( new Store() ) )->{$method}( $this->make_sub_plugin( $overrides ) );
	}

	/**
	 * @param mixed $queue Raw queue contents, well-formed or not.
	 */
	private function seed_queue( $queue ): void {
		update_site_option( self::OPTION, $queue );
	}

	private function clear_queue(): void {
		delete_site_option( self::OPTION );
	}

	/**
	 * Whether the option exists at all, which is what "render cleared the queue" means.
	 *
	 * @return bool
	 */
	private function queue_exists(): bool {
		return get_site_option( self::OPTION, false ) !== false;
	}

	/**
	 * The presenter as the container builds it, or with either collaborator replaced.
	 *
	 * Both arguments are required — nothing in `src/` defaults a collaborator to a `new` of its
	 * own any more — so the standard pair is spelled out once here rather than in every test that
	 * only cares about who may consume the queue.
	 *
	 * @param Store|null    $store    Where the queue is kept.
	 * @param Renderer|null $renderer How a queued notice is drawn.
	 *
	 * @return Presenter
	 */
	private function make_presenter( ?Store $store = null, ?Renderer $renderer = null ): Presenter {
		return new Presenter( $store ?? new Store(), $renderer ?? new Renderer() );
	}

	private function render_to_string( Presenter $presenter ): string {
		ob_start();

		try {
			$presenter->render();
		} finally {
			// In a finally block so a throw from render() cannot leave the suite's own output
			// trapped in an abandoned buffer.
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
