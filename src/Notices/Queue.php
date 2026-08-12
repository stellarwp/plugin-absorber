<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Notices;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Default queue: option-backed, so it survives the resolver's redirect.
 *
 * This class decides *what a notice says* and *who is allowed to consume the queue*. Where the
 * queue is kept is Store's job and how it is drawn is Renderer's, so a host can replace either one
 * without inheriting the other, and neither has to be understood to reword a message.
 *
 * Both collaborators are required constructor arguments, and `Provider` is what hands them over.
 * No defaults: a class that can build its own dependencies has a second way to be constructed that
 * bypasses every binding a host made, and it is the one a test or a stray `new` reaches for.
 *
 * A host already using stellarwp/admin-notices can bind its own implementation of Queue_Interface
 * and read the same option, whose name is `self::option_name()`.
 *
 * @since 1.0.0
 */
class Queue implements Queue_Interface {
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
	 * @var Store
	 */
	private $store;

	/**
	 * @since 1.0.0
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * @since 1.0.0
	 *
	 * @param Store    $store    Where the queue is kept.
	 * @param Renderer $renderer How a queued notice is drawn.
	 */
	public function __construct( Store $store, Renderer $renderer ) {
		$this->store    = $store;
		$this->renderer = $renderer;
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
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return string
	 */
	public function filter_activation_error_markup( string $markup ): string {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return $markup;
		}

		$screen = get_current_screen();

		// Both plugin lists, because wp-admin/network/plugins.php is a one-line require of
		// wp-admin/plugins.php and draws the identical activation error -- but WP_Screen appends
		// `-network` to the id there. On a default multisite the subsite has no plugins UI at all,
		// so the network screen is the *only* place a super admin can reactivate the standalone,
		// and matching 'plugins' alone would decline on the one screen that matters most.
		if ( $screen === null || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) ) {
			return $markup;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below, once
		// the plugin named turns out to be one this library owns. Nothing is acted on until then.
		$basename = isset( $_GET['plugin'] )
			? sanitize_text_field( wp_unslash( $_GET['plugin'] ) )
			: '';

		if ( $basename === '' ) {
			return $markup;
		}

		// Looked up before the nonce is checked, deliberately: there is no nonce work to do for a
		// plugin this library does not own, and the nonce is still verified before any markup is
		// touched.
		$sub_plugin = $this->find_by_standalone_basename( $basename );

		if ( $sub_plugin === null ) {
			return $markup;
		}

		$nonce = isset( $_GET['_error_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_error_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'plugin-activation-error_' . $basename ) ) {
			return $markup;
		}

		$message = $sub_plugin->get_conflict_notice_message(
			sprintf(
				'%s is bundled with this plugin and loads automatically. The standalone copy cannot'
					. ' be activated alongside it.',
				$sub_plugin->get_slug()
			)
		);

		// Sanitised before the emptiness check rather than after. wp_kses_post( '<script></script>' )
		// is the empty string, and swapping WordPress's wording for nothing leaves a blank notice
		// box where the explanation should be. wp_kses_post() and not esc_html(), for the reason
		// the renderer uses it: these messages come from the host's own config, and a link to a
		// knowledge-base article has to survive.
		$message = trim( wp_kses_post( $message ) );

		if ( $message === '' ) {
			return $markup;
		}

		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's own string, matched on purpose.
		$core_text = __( 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.', 'default' );

		return str_replace( $core_text, $message, $markup );
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
		return Store::option_name();
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

	/**
	 * The registered sub-plugin a standalone basename belongs to, if any.
	 *
	 * Read through `Loader::all()` rather than a registrar of this queue's own, for the reason the
	 * resolver and the load pass do: it flushes the registrations still buffered on the facade
	 * before it reads, and a registrar asked directly would miss anything registered since the
	 * last read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Standalone plugin basename named by the request.
	 *
	 * @throws Config_Exception When the container cannot produce a usable registrar, or two
	 *                          sub-plugins were registered under one slug.
	 *
	 * @return Sub_Plugin|null
	 */
	private function find_by_standalone_basename( string $basename ): ?Sub_Plugin {
		foreach ( Loader::all() as $sub_plugin ) {
			if ( $sub_plugin->get_standalone_plugin_basename() === $basename ) {
				return $sub_plugin;
			}
		}

		return null;
	}
}
