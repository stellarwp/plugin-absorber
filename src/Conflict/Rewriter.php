<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Rewrites the fatal-error screen an absorbed standalone earns when someone activates it.
 *
 * The one conflict the load guard cannot prevent: core includes the plugin being activated *after*
 * the bundled copy, so the re-declaration really does fatal and all this can do is reword core's
 * sentence, with the sub-plugin's own `conflict_notice_message`. Not `final`: bound by class name.
 *
 * @since 1.0.0
 */
class Rewriter {
	/**
	 * @since 1.0.0
	 *
	 * @var Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @param Reader $registry Which sub-plugins are registered.
	 */
	public function __construct( Reader $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The markup to print in place of the one WordPress was about to.
	 *
	 * Handed back untouched unless the request is a nonce-verified activation error, on a plugins
	 * screen, for a standalone this library has registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function rewrite( string $markup ): string {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return $markup;
		}

		$screen = get_current_screen();

		// Both plugin lists: wp-admin/network/plugins.php is a one-line require of the other, but
		// WP_Screen appends `-network` to the id there -- and on a default multisite it is the only
		// screen an absorbed standalone can be reactivated from.
		if ( $screen === null || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) ) {
			return $markup;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below, before
		// anything is acted on.
		$basename = isset( $_GET['plugin'] ) ? wp_unslash( $_GET['plugin'] ) : '';

		// Unslashed and no further: core mints the nonce from the unslashed value verbatim, so
		// sanitizing a folder name holding a '%xx' sequence would make both the nonce check and the
		// registry lookup miss, silently. is_string() because '?plugin[]=x' arrives as an array.
		if ( ! is_string( $basename ) || $basename === '' ) {
			return $markup;
		}

		// Looked up first: there is no nonce work to do for a plugin this library does not own.
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

		// Sanitised before the emptiness check rather than after: wp_kses_post( '<script></script>' )
		// is the empty string, and swapping core's wording for nothing leaves a blank notice box.
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
	 * Read through the reader rather than a registrar of its own: it drains the buffered
	 * registrations first.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Standalone plugin basename named by the request.
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
