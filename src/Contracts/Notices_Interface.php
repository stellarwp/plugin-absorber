<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
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
	 * Queued after the deactivation has already happened, and raised exactly once — nothing
	 * re-queues it on a later request, so an implementation that drops it drops the only warning
	 * the site owner gets.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
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
	 * @throws Config_Exception When no hook prefix has been set.
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
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Render every queued notice, then clear the queue.
	 *
	 * Two obligations an implementation must honour. It has to survive the request that queued
	 * it, because the resolver redirects and the notice is almost never rendered by the request
	 * that raised it. And because rendering consumes the queue, it must not render for a user who
	 * cannot act on the notice — otherwise any logged-in user loading an admin page swallows a
	 * warning meant for an administrator.
	 *
	 * The queue is single-consumer. Rendering consumes it for everybody, so the first eligible
	 * administrator to load any admin screen is the only person who ever sees a given notice —
	 * network-wide on multisite, where the queue is one network option. An implementation that
	 * wants every administrator to see it has to track consumption per user itself.
	 *
	 * Messages are plain text. The default implementation prints them through `esc_html()`, so a
	 * message containing a link renders as literal angle brackets rather than as an anchor. That
	 * is deliberate for 1.0: escaping can be loosened later without breaking anyone, but it
	 * cannot be tightened.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function render(): void;
}
