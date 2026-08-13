<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Registry_Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Rewrites the fatal-error screen an absorbed standalone earns when someone activates it.
 *
 * This is the one conflict the load guard cannot prevent. WordPress includes the plugin being
 * activated *after* the bundled copy is in memory, so the re-declaration is a real fatal; core
 * catches it in its activation sandbox and reports "the plugin triggered a fatal error" — true, and
 * useless to whoever pressed the button. All this library gets to do about it is reword that one
 * sentence, and the wording is the sub-plugin's own `conflict_notice_message`.
 *
 * Its own class rather than a method on the notice writer, though the message is shared with the
 * merge notice. Nothing here is stored, drawn or queued: it reads the request, checks the screen,
 * verifies a nonce and edits markup core wrote. The writer would have needed a registry to find out
 * which sub-plugin the screen is even about — a collaborator only that one method used — and every
 * host binding its own `Notices\Contracts\Writer_Interface` would have had to implement an error
 * screen to get its notices worded.
 *
 * In `Conflict\` rather than `Notices\` for the same reason: what it is about is the standalone
 * conflict, and it changes when that story does, alongside `Detector`, `Resolver` and `Redirector`.
 *
 * Not `final`: it is bound by class name, which is the seam a host rebinds and a test subclasses.
 *
 * @since 1.0.0
 */
class Rewriter {
	/**
	 * @since 1.0.0
	 *
	 * @var Registry_Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @param Registry_Reader $registry Which sub-plugins are registered.
	 */
	public function __construct( Registry_Reader $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The markup to print in place of the one WordPress was about to.
	 *
	 * Handed back untouched unless the request really is a nonce-verified activation error, on a
	 * plugins screen, for a standalone this library has registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @throws Config_Exception When no hook prefix has been set, or two sub-plugins were registered
	 *                          under one slug.
	 *
	 * @return string
	 */
	public function rewrite( string $markup ): string {
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
	 * The registered sub-plugin a standalone basename belongs to, if any.
	 *
	 * Read through the reader this class was handed rather than a registrar of its own, for the
	 * reason the conflict and load passes do: the reader drains the registrations still buffered
	 * before it reads, and a registrar asked directly would miss anything registered since the last
	 * read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Standalone plugin basename named by the request.
	 *
	 * @throws Config_Exception When two sub-plugins were registered under one slug.
	 *
	 * @return Sub_Plugin|null
	 */
	private function find_by_standalone_basename( string $basename ): ?Sub_Plugin {
		foreach ( $this->registry->all() as $sub_plugin ) {
			if ( $sub_plugin->get_standalone_plugin_basename() === $basename ) {
				return $sub_plugin;
			}
		}

		return null;
	}
}
