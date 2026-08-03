<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Admin notices raised by the absorber. Bind a replacement to render them your own way.
 *
 * @since 1.0.0
 */
interface Notices_Interface {
	/**
	 * Queue the "we deactivated the standalone for you" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Queue the "please deactivate the standalone yourself" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Queue the "requirements not met" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Render every queued notice, then clear the queue.
	 *
	 * An implementation must survive the request that queued it: the resolver redirects, so the
	 * notice is almost never rendered by the request that raised it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void;
}
