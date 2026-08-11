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
 * This class decides *what a notice says* and *who is allowed to consume the queue*. Where the
 * queue is kept is Notice_Store's job and how it is drawn is Notice_Renderer's, so a host can
 * replace either one without inheriting the other, and neither has to be understood to reword a
 * message.
 *
 * Both collaborators are constructor arguments with defaults, so `new Notices()` still gives the
 * standard behaviour — which is what `Loader::resolve()` builds when the container has no binding.
 *
 * A host already using stellarwp/admin-notices can bind its own implementation of
 * Notices_Interface and read the same option, whose name is `self::option_name()`.
 *
 * @since 1.0.0
 */
class Notices implements Notices_Interface {
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
	 * @since 1.0.0
	 *
	 * @var Notice_Store
	 */
	private $store;

	/**
	 * @since 1.0.0
	 *
	 * @var Notice_Renderer
	 */
	private $renderer;

	/**
	 * @since 1.0.0
	 *
	 * @param Notice_Store|null    $store    Where the queue is kept.
	 * @param Notice_Renderer|null $renderer How a queued notice is drawn.
	 */
	public function __construct( ?Notice_Store $store = null, ?Notice_Renderer $renderer = null ) {
		$this->store    = $store ?? new Notice_Store();
		$this->renderer = $renderer ?? new Notice_Renderer();
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
	 * Draw the queue, then consume it.
	 *
	 * The capability check stays here rather than in the renderer because it guards the clearing
	 * as much as the drawing: the two have to be decided together or a user who may not see the
	 * queue could still destroy it.
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

		$queue = $this->store->all();

		if ( $queue === [] ) {
			return;
		}

		$this->renderer->render( $queue );

		$this->store->clear();
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
		return Notice_Store::option_name();
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
