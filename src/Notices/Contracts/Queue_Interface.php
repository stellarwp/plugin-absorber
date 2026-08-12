<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Notices\Contracts;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Admin notices raised by the absorber. Bind a replacement to render them your own way.
 *
 * @since 1.0.0
 */
interface Queue_Interface {
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
	 * Messages may carry markup. They come from the host's own configuration or from its filters
	 * rather than from user input, so the default implementation prints them through
	 * `wp_kses_post()` — the standard WordPress post-content allowlist — and a link to a
	 * knowledge-base article, emphasis or a list reaches the screen intact while a script or an
	 * event handler attribute is stripped. An implementation bound in place of the default owns
	 * its own escaping.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function render(): void;

	/**
	 * Replace WordPress's generic fatal-activation text with the sub-plugin's own explanation.
	 *
	 * Filters `wp_admin_notice_markup`. This is the one conflict the load guard cannot prevent:
	 * WordPress includes a plugin being activated after the bundled copy has already loaded, so
	 * the re-declaration is a real fatal, caught in core's activation sandbox and reported as
	 * "the plugin triggered a fatal error" — true, and useless to whoever pressed the button.
	 *
	 * An implementation must return the markup untouched unless the request really is a
	 * nonce-verified activation error, on the plugins screen, for a standalone this library
	 * knows about.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return string
	 */
	public function filter_activation_error_markup( string $markup ): string;
}
