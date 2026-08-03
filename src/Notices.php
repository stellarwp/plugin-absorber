<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Notices_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Default notices: an option-backed queue that survives the resolver's redirect.
 *
 * An option rather than a transient. With an external object cache, `set_transient()` never
 * touches the database at all — the queue would live only in Redis or Memcached, where a routine
 * `wp_cache_flush()` from a deploy script or a "purge cache" button destroys it. The merge notice
 * is raised once and never again, so losing it means a site owner is never told their plugin was
 * deactivated. This queue is not a cache.
 *
 * Deliberately minimal markup so the library stays dependency-free. A host already using
 * stellarwp/admin-notices can bind its own implementation and read the same option, whose name is
 * `self::option_name()`.
 *
 * @since 1.0.0
 */
class Notices implements Notices_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TYPE_MERGE = 'merge';

	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TYPE_CONFLICT = 'conflict';

	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TYPE_DEPENDENCY = 'dependency';

	/**
	 * Capability required to see, and thereby consume, the queue.
	 *
	 * Rendering clears the queue, so a user who cannot act on a notice must not be shown one:
	 * a subscriber loading their profile page would otherwise silently swallow the only warning
	 * an administrator was ever going to get.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const CAPABILITY = 'activate_plugins';

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
		self::TYPE_MERGE      => 'notice-warning',
		self::TYPE_CONFLICT   => 'notice-warning',
		self::TYPE_DEPENDENCY => 'notice-error',
	];

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue(
			$sub_plugin,
			self::TYPE_MERGE,
			$sub_plugin->get_conflict_notice_message(
				sprintf(
					'%s has been deactivated because it is now bundled and loaded automatically.',
					$sub_plugin->get_slug()
				)
			)
		);
	}

	/**
	 * The default differs from the merge notice's on purpose: this one asks the user to act,
	 * where that one reports something already done.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue(
			$sub_plugin,
			self::TYPE_CONFLICT,
			$sub_plugin->get_conflict_notice_message(
				sprintf(
					'%s is now bundled and loaded automatically. You can safely deactivate the standalone plugin.',
					$sub_plugin->get_slug()
				)
			)
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue( $sub_plugin, self::TYPE_DEPENDENCY, $sub_plugin->get_dependency_notice_message() );
	}

	/**
	 * Messages are printed through `esc_html()`, so they are plain text: markup a host puts in a
	 * message renders as literal angle brackets rather than as a link.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$queue = $this->get_queue();

		if ( $queue === [] ) {
			return;
		}

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

		$this->clear_queue();
	}

	/**
	 * The option name backing the queue. Read it directly to render these notices yourself.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public static function option_name(): string {
		return Config::get_hook_prefix() . '_plugin_absorber_notices';
	}

	/**
	 * Store one notice, keyed by slug and type so different types can coexist.
	 *
	 * A sub-plugin can legitimately earn a merge notice while the conflict is resolved and a
	 * dependency notice while the load is attempted, in the same request. Keying by slug alone
	 * would silently drop one of them.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 * @param string     $type       Notice type.
	 * @param string     $message    Resolved message.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	private function queue( Sub_Plugin $sub_plugin, string $type, string $message ): void {
		$queue = $this->get_queue();

		$queue[ $sub_plugin->get_slug() . ':' . $type ] = $message;

		// One call covers both install types: outside multisite `update_site_option()` runs
		// `update_option( $option, $value, false )`, which is also exactly the autoload=false the
		// queue wants — it is empty on almost every request and only the admin ever reads it.
		update_site_option( self::option_name(), $queue );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return array<string,string>
	 */
	private function get_queue(): array {
		// Outside multisite `get_site_option()` is `get_option()`, so this reads back whatever
		// queue() wrote on either install type.
		$queue = get_site_option( self::option_name(), [] );

		if ( ! is_array( $queue ) ) {
			return [];
		}

		// Anything that is not a string message is dropped rather than printed. queue() writes
		// the filtered array back, so a corrupted entry heals itself.
		return array_filter( $queue, 'is_string' );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	private function clear_queue(): void {
		// Outside multisite `delete_site_option()` is `delete_option()`.
		delete_site_option( self::option_name() );
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
