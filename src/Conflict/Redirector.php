<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

/**
 * Where the user lands after a standalone has been deactivated.
 *
 * The point of the redirect is to re-render whatever the user was looking at now that the
 * standalone is gone. Two referrers are handled specially: the update screens, where reloading
 * would re-run an update, and the plugins list, which already reads plugin state fresh — so
 * sending them back there would only cost a round trip.
 *
 * Decides where, and never goes there: `wp_safe_redirect()` and the `exit` after it stay in
 * Resolver. That is what lets every destination be asserted directly, without a test having to
 * stand in for the end of a request.
 *
 * @since 1.0.0
 */
class Redirector {
	/**
	 * Where to send the user after deactivating, or false to stay put.
	 *
	 * @since 1.0.0
	 *
	 * @param string|false $referrer Result of wp_get_referer().
	 *
	 * @return string|false
	 */
	public function after_deactivation( $referrer ) {
		if ( ! is_string( $referrer ) || $referrer === '' ) {
			return admin_url( 'plugins.php' );
		}

		// Match on the screen, not on a substring of an absolute URL. wp_get_referer() prefers
		// the _wp_http_referer field that every nonce-bearing admin form carries, and that field
		// holds a bare path -- so comparing against admin_url() misses every admin form POST,
		// misses the network admin entirely, and misses any site behind a TLS-terminating proxy
		// where admin_url() says http and the referrer says https.
		$screen = basename( (string) wp_parse_url( $referrer, PHP_URL_PATH ) );

		if ( $screen === 'update.php' || $screen === 'update-core.php' ) {
			return admin_url( 'plugins.php' );
		}

		// Staying put. Core lands here after a bulk action, and the list is about to render the
		// deactivation we just made anyway.
		if ( $screen === 'plugins.php' ) {
			return false;
		}

		return $referrer;
	}
}
