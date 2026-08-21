<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Notices;

/**
 * How a queued notice is drawn.
 *
 * Deliberately minimal markup so the library stays dependency-free, and deliberately its own class:
 * a host that only wants different markup — one already using stellarwp/admin-notices, say — has
 * one small thing to replace rather than the whole queue.
 *
 * Knows nothing about where the queue came from or who is allowed to see it. It is handed messages
 * and prints them.
 *
 * @since 1.0.0
 */
class Renderer {
	/**
	 * The `notice-*` class each notice type renders with.
	 *
	 * A dependency notice reports a plugin that did not load at all, which is `notice-error` by
	 * WordPress convention. The other three report a conflict the library has already handled or held
	 * off — the site works, so they are warnings.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string,string>
	 */
	private const CLASSES = [
		Writer::TYPE_MERGE      => 'notice-warning',
		Writer::TYPE_CONFLICT   => 'notice-warning',
		Writer::TYPE_STRANDING  => 'notice-warning',
		Writer::TYPE_DEPENDENCY => 'notice-error',
	];

	/**
	 * Print every message in the queue.
	 *
	 * Messages go through `wp_kses_post()`, the standard WordPress post-content allowlist, so a
	 * link to a knowledge-base article, emphasis or a list survives, while a script or an event
	 * handler attribute does not.
	 *
	 * The paragraph comes from `wpautop()` rather than a literal `<p>` around the message. That
	 * same allowlist keeps a `<p>`, so a host may well send one already wrapped, and a hard wrap
	 * around it is markup no browser can honour: the parser closes the outer paragraph at the
	 * inner one and leaves a stray `</p>`, which draws an empty line above the notice. A `<ul>`
	 * fares worse — it cannot legally sit in a `<p>` at all, so the list the allowlist just
	 * preserved would break straight back out of it. `wpautop()` wraps only what needs wrapping,
	 * and turns the blank line a plain translated string uses as a break into a real one.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $queue Queue to draw, keyed `slug:type`.
	 *
	 * @return void
	 */
	public function render( array $queue ): void {
		foreach ( $queue as $key => $message ) {
			// Filtered before the emptiness check rather than after it, because filtering can
			// empty a message on its own: `wp_kses_post( '<script></script>' )` is the empty
			// string. Either that or a whitespace-only message would print an empty notice box,
			// which reads as a bug.
			$message = trim( wp_kses_post( $message ) );

			if ( $message === '' ) {
				continue;
			}

			printf(
				'<div class="notice %s is-dismissible">%s</div>',
				esc_attr( $this->notice_class( (string) $key ) ),
				// Already filtered: escaping it again here would undo the whole point and print a
				// link as literal angle brackets. Trimmed because `wpautop()` leaves a trailing
				// newline, which would otherwise sit inside the div on every notice.
				trim( wpautop( $message ) )
			);
		}
	}

	/**
	 * The `notice-*` class for a queue entry, taken from the type half of its `slug:type` key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Queue key.
	 *
	 * @return string
	 */
	private function notice_class( string $key ): string {
		$parts = explode( ':', $key );
		$type  = (string) end( $parts );

		// An entry written by an older version, or by a host reading and rewriting the option, is
		// shown rather than dropped: a warning is the safe severity for something unrecognised.
		return self::CLASSES[ $type ] ?? 'notice-warning';
	}
}
