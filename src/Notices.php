<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Notices_Interface;

/**
 * Default notices: a transient-backed queue that survives the resolver's redirect.
 *
 * Deliberately minimal markup so the library stays dependency-free. A host already using
 * stellarwp/admin-notices can bind its own implementation and render from the same store.
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
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
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
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue( $sub_plugin, self::TYPE_DEPENDENCY, $sub_plugin->get_dependency_notice_message() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$queue = $this->get_queue();

		if ( $queue === [] ) {
			return;
		}

		delete_transient( $this->transient_name() );

		foreach ( $queue as $message ) {
			if ( $message === '' ) {
				continue;
			}

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
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
	 * @return void
	 */
	private function queue( Sub_Plugin $sub_plugin, string $type, string $message ): void {
		$queue = $this->get_queue();

		$queue[ $sub_plugin->get_slug() . ':' . $type ] = $message;

		// No expiry: the queue has to outlive the redirect and wait for the next admin load.
		set_transient( $this->transient_name(), $queue, 0 );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	private function get_queue(): array {
		$queue = get_transient( $this->transient_name() );

		if ( ! is_array( $queue ) ) {
			return [];
		}

		// The store is shared with whatever else can write an option, and the render path prints
		// what it finds. Anything that is not a string message is dropped rather than coerced.
		return array_filter( $queue, 'is_string' );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function transient_name(): string {
		return Config::get_hook_prefix() . '_plugin_absorber_notices';
	}
}
