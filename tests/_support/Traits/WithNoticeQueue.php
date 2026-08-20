<?php
/**
 * Reads the notice queue under the name the library actually writes to.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Queue;

/**
 * One shared way to read and clear the queue, for every test that only cares about what landed in it.
 *
 * The name comes from `Queue::option_name()` rather than from a literal, because the literal was
 * copied into three unrelated test classes: renaming the option — or the segment `Config` builds
 * between the host's prefix and the key — then means finding all of them, and the one that is missed
 * asserts against an option nothing writes, which reads as "no notice was queued" rather than as a
 * failure to keep up.
 *
 * A test *about* the name pins it against a literal instead, and keeps its own reader. Reading it
 * from here as well would move both sides of that assertion together.
 *
 * @since 1.0.0
 */
trait WithNoticeQueue {
	/**
	 * Everything the queue holds, keyed `slug:type`.
	 *
	 * Always an array, so a caller can index, count and compare it against `[]` without a guard of
	 * its own — an unset option reads back as `false`, and a corrupted one as whatever was left
	 * behind. The values are deliberately *not* narrowed to strings: the tests that seed a malformed
	 * queue are asserting over exactly what the store dropped.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int|string,mixed>
	 */
	protected function queued_notices(): array {
		$option = $this->notice_option_name();

		if ( $option === null ) {
			return [];
		}

		// The queue is a network option on multisite, matching Store — and outside multisite
		// get_site_option() is get_option(), so this reads back what the store wrote either way.
		$queue = get_site_option( $option, [] );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * Take the queue away entirely. Call from setUp and tearDown.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function clear_notices(): void {
		$option = $this->notice_option_name();

		if ( $option === null ) {
			return;
		}

		// delete_site_option() is delete_option() outside multisite, so it matches Store::clear().
		delete_site_option( $option );
	}

	/**
	 * The option backing the queue, or null when no name can be derived.
	 *
	 * A missing hook prefix is not an error here. Several tests end with the prefix deliberately
	 * unset — that is what they are about — and their tearDown still clears the queue; throwing from
	 * cleanup would turn those into errors. Nothing can have been queued under a name that cannot be
	 * built, since `Store` throws the same exception on the way in, and the next setUp clears the
	 * queue again with a prefix in place.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	private function notice_option_name(): ?string {
		try {
			return Queue::option_name();
		} catch ( Config_Exception $exception ) {
			return null;
		}
	}
}
