<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Notices;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * What a notice says, and under which key it is kept.
 *
 * One reason to change: the wording. Where the queue is kept is `Store`'s, how a pending notice is
 * drawn is `Renderer`'s, and who may consume the queue is `Presenter`'s — so a host can reword a
 * sentence without reading any of them, and none of them has to be understood to answer "what does
 * the merge notice say".
 *
 * Option-backed through `Store`, so what is written here survives the resolver's redirect: the
 * request that raises a notice is almost never the one that shows it.
 *
 * The collaborator is a required constructor argument, and `Provider` is what hands it over. No
 * defaults: a class that can build its own dependencies has a second way to be constructed that
 * bypasses every binding a host made, and it is the one a test or a stray `new` reaches for.
 *
 * A host already using stellarwp/admin-notices can bind its own implementation of Writer_Interface
 * and read the same option, whose name is `option_name()`.
 *
 * @since 1.0.0
 */
class Writer implements Writer_Interface {
	/**
	 * Notice types. Public because they are the second half of a queue key — an entry is stored
	 * under `slug:type`, and reading the queue yourself means matching against these.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_MERGE = 'merge';

	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_CONFLICT = 'conflict';

	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_DEPENDENCY = 'dependency';

	/**
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_STRANDING = 'stranding';

	/**
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * @since 1.0.0
	 *
	 * @param Store $store Where the queue is kept.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
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
	 * The "we left the standalone active to avoid stranding sites" notice.
	 *
	 * Its own type and its own default because it must not carry the conflict notice's "you can
	 * safely deactivate the standalone": on the topology it fires for -- a network-active standalone
	 * whose host is not network-activated -- a network-wide deactivation is the very thing that would
	 * strand the sites the host never reached. The wording lives on `Sub_Plugin` with the others.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function queue_stranding_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue( $sub_plugin, self::TYPE_STRANDING, $sub_plugin->get_stranding_notice_message() );
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
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function option_name(): string {
		return $this->store->option_name();
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
		$this->store->put( $sub_plugin->get_slug() . ':' . $type, $message );
	}
}
