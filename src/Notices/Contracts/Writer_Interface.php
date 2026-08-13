<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Notices\Contracts;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * What the absorber has to say about a sub-plugin. Bind a replacement to word or keep it your own way.
 *
 * The seam, and the only one in this folder: what a notice says is the thing a host has an opinion
 * about, and a host already running `stellarwp/admin-notices` binds its own here. How a pending
 * notice reaches the screen is `Notices\Presenter`'s, which is a class rather than a contract because
 * nothing in the library dispatches on it — the trampoline on `all_admin_notices` is the only caller,
 * and a host that wants it gone takes the callback off.
 *
 * @since 1.0.0
 */
interface Writer_Interface {
	/**
	 * Queue the "we deactivated the standalone for you" notice.
	 *
	 * Queued after the deactivation has already happened, and raised exactly once — nothing
	 * re-queues it on a later request, so an implementation that drops it drops the only warning
	 * the site owner gets.
	 *
	 * Whatever an implementation writes has to survive the request that wrote it: the resolver
	 * redirects, so the notice is almost never read by the request that raised it.
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
	 * Where these notices are kept, so a host can render them itself without replacing the writer.
	 *
	 * On the contract rather than on the default implementation, and an instance method rather than
	 * a static one, because the honest answer depends on which writer a site is running: an
	 * implementation bound in place of the default keeps its notices where it likes, and a host
	 * reading a name off the default class would read an option nothing writes to.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function option_name(): string;
}
