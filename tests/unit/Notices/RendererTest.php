<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Notices;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Notices\Queue;
use Nexcess\PluginAbsorber\Notices\Renderer;

/**
 * The drawing half of the queue.
 *
 * Needs no hook prefix, no option and no current user: the renderer is handed messages and prints
 * them. That it can be tested this way is the point of it being its own class.
 *
 * @since 1.0.0
 */
class RendererTest extends WPTestCase {
	public function test_it_prints_dismissible_markup(): void {
		$output = $this->render( [ 'give-recurring:merge' => 'Bundled now.' ] );

		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	/**
	 * @dataProvider notice_severities
	 *
	 * @param string $key   Queue key to render under.
	 * @param string $class Expected `notice-*` class.
	 */
	public function test_the_type_half_of_the_key_picks_the_severity( string $key, string $class ): void {
		$this->assertStringContainsString(
			'notice ' . $class . ' is-dismissible',
			$this->render( [ $key => 'Something happened.' ] )
		);
	}

	/**
	 * A dependency notice reports a plugin that did not load, which is an error; the conflict pair
	 * report something the library handled, which is a warning. An unrecognised type is drawn as a
	 * warning rather than dropped — it may have been written by an older version, or by a host
	 * reading and rewriting the option.
	 *
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function notice_severities(): Generator {
		yield 'merge'         => [ 'give-recurring:' . Queue::TYPE_MERGE, 'notice-warning' ];
		yield 'conflict'      => [ 'give-recurring:' . Queue::TYPE_CONFLICT, 'notice-warning' ];
		yield 'dependency'    => [ 'give-recurring:' . Queue::TYPE_DEPENDENCY, 'notice-error' ];
		yield 'unknown type'  => [ 'give-recurring:invented', 'notice-warning' ];
		yield 'no type at all' => [ 'give-recurring', 'notice-warning' ];
	}

	/**
	 * A slug containing a colon still resolves to the type, because the type is the last segment
	 * rather than the second.
	 */
	public function test_the_type_is_the_last_segment_of_the_key(): void {
		$this->assertStringContainsString(
			'notice-error',
			$this->render( [ 'give:recurring:' . Queue::TYPE_DEPENDENCY => 'Requirements not met.' ] )
		);
	}

	/**
	 * `wp_kses_post()` drops a disallowed tag but keeps the text it wrapped, so the payload lands
	 * on the page as inert text rather than as markup the browser would run.
	 */
	public function test_it_strips_a_script_from_a_message(): void {
		$output = $this->render( [ 'a:merge' => 'Careful.<script>alert(1)</script>' ] );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'alert(1)', $output );
	}

	/**
	 * Messages come from the host's own configuration or filters rather than from user input, so a
	 * message is allowed to carry a link — to the knowledge-base article explaining the merge,
	 * typically — and it has to reach the screen as an anchor.
	 */
	public function test_it_keeps_a_link_in_a_message(): void {
		$output = $this->render( [ 'a:merge' => 'See <a href="https://example.com">the docs</a>.' ] );

		$this->assertStringContainsString( '<a href="https://example.com">the docs</a>', $output );
	}

	/**
	 * The allowlist is per attribute, not per tag: the anchor a host wants survives while the event
	 * handler it must never be able to ship does not. That split is what makes allowing markup safe.
	 */
	public function test_it_strips_an_event_handler_from_a_link(): void {
		$output = $this->render(
			[ 'a:merge' => 'See <a href="https://example.com" onclick="alert(1)">the docs</a>.' ]
		);

		$this->assertStringNotContainsString( 'onclick', $output );
		$this->assertStringContainsString( '<a href="https://example.com">the docs</a>', $output );
	}

	/**
	 * A bare message still has to reach the screen as a paragraph: `.notice` styles the `<p>` inside
	 * it, and text sitting directly in the div loses that spacing.
	 */
	public function test_it_wraps_a_bare_message_in_a_paragraph(): void {
		$this->assertStringContainsString( '<p>Bundled now.</p>', $this->render( [ 'a:merge' => 'Bundled now.' ] ) );
	}

	/**
	 * The same allowlist that keeps a link keeps a `<p>`, so a host may well send one. Wrapping that
	 * in another paragraph is markup no browser can honour: the parser closes the outer `<p>` at the
	 * inner one and leaves a stray `</p>` behind, so the notice renders with an empty leading line.
	 */
	public function test_it_does_not_wrap_a_paragraph_the_host_already_wrote(): void {
		$output = $this->render( [ 'a:merge' => '<p>Bundled now.</p>' ] );

		$this->assertStringNotContainsString( '<p><p>', $output );
		$this->assertSame( 1, substr_count( $output, '<p>' ), 'One paragraph in, one paragraph out.' );
	}

	/**
	 * The class docblock promises a list survives, and a `<ul>` inside a `<p>` is not a list that
	 * survived: the browser closes the paragraph before it, orphaning the closing tag.
	 */
	public function test_it_keeps_a_list_out_of_the_paragraph(): void {
		$output = $this->render( [ 'a:merge' => 'Requires:<ul><li>Give</li></ul>' ] );

		$this->assertMatchesRegularExpression( '#</p>\s*<ul>#', $output, 'The paragraph must close first.' );
		$this->assertStringContainsString( '<li>Give</li>', $output );
	}

	/**
	 * A blank line is the only paragraph break available to a host whose message is a plain
	 * translated string, so it has to survive as one rather than collapse into running text.
	 */
	public function test_a_blank_line_starts_a_second_paragraph(): void {
		$this->assertSame( 2, substr_count( $this->render( [ 'a:merge' => "One.\n\nTwo." ] ), '<p>' ) );
	}

	/**
	 * @dataProvider empty_messages
	 *
	 * @param string $message Message that must print nothing at all.
	 */
	public function test_it_skips_a_message_with_nothing_in_it( string $message ): void {
		$this->assertSame( '', $this->render( [ 'a:merge' => $message ] ) );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function empty_messages(): Generator {
		yield 'an empty message' => [ '' ];

		// Whitespace would otherwise print an empty notice box, which reads as a bug.
		yield 'a whitespace-only message' => [ "  \n\t" ];

		// So would a message that filtering empties: `wp_kses_post()` keeps the text a disallowed
		// tag wrapped, and here there is none.
		yield 'a message that is only disallowed markup' => [ '<script></script>' ];
	}

	public function test_an_empty_queue_prints_nothing(): void {
		$this->assertSame( '', $this->render( [] ) );
	}

	public function test_it_prints_every_message_it_is_given(): void {
		$output = $this->render(
			[
				'a:merge'      => 'First.',
				'b:dependency' => 'Second.',
			]
		);

		$this->assertStringContainsString( 'First.', $output );
		$this->assertStringContainsString( 'Second.', $output );
	}

	/**
	 * @param array<string,string> $queue Queue to draw.
	 *
	 * @return string
	 */
	private function render( array $queue ): string {
		ob_start();

		try {
			( new Renderer() )->render( $queue );
		} finally {
			// In a finally block so a throw from render() cannot leave the suite's own output
			// trapped in an abandoned buffer.
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
