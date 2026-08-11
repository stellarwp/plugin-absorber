<?php
/**
 * @package Nexcess\PluginAbsorber
 */

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

	public function test_it_escapes_the_message(): void {
		$output = $this->render( [ 'a:merge' => '<script>alert(1)</script>' ] );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * Escaping is `esc_html()` on purpose, so a message is plain text and a host that ships a link
	 * gets literal angle brackets. Pinned here because loosening it later is safe and tightening it
	 * later is not.
	 */
	public function test_it_does_not_allow_markup_in_a_message(): void {
		$output = $this->render( [ 'a:merge' => 'See <a href="https://example.com">the docs</a>.' ] );

		$this->assertStringNotContainsString( '<a href', $output );
		$this->assertStringContainsString( '&lt;a href=', $output );
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
