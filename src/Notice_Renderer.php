<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

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
class Notice_Renderer {
	/**
	 * The `notice-*` class each notice type renders with.
	 *
	 * A dependency notice reports a plugin that did not load at all, which is `notice-error` by
	 * WordPress convention. The other two report a conflict the library has already handled — the
	 * site works, so they are warnings.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string,string>
	 */
	private const CLASSES = [
		Notices::TYPE_MERGE      => 'notice-warning',
		Notices::TYPE_CONFLICT   => 'notice-warning',
		Notices::TYPE_DEPENDENCY => 'notice-error',
	];

	/**
	 * Print every message in the queue.
	 *
	 * Messages are printed through `esc_html()`, so they are plain text: markup a host puts in a
	 * message renders as literal angle brackets rather than as a link.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $queue Queue to draw, keyed `slug:type`.
	 *
	 * @return void
	 */
	public function render( array $queue ): void {
		foreach ( $queue as $key => $message ) {
			$message = trim( $message );

			// A whitespace-only message would print an empty notice box, which reads as a bug.
			if ( $message === '' ) {
				continue;
			}

			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $this->notice_class( (string) $key ) ),
				esc_html( $message )
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
