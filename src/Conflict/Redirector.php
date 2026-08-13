<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict;

/**
 * Where the user lands after a standalone has been deactivated.
 *
 * The point of the redirect is to re-render whatever the user asked for, now that the standalone is
 * gone -- so the destination is derived from the *current* request, not from the referrer. The
 * referrer is the page before this one: an admin who clicks through from a public post, or who
 * opens an admin screen from a bookmark, carries a referrer that names something other than what
 * they are looking at, and following it sends them somewhere they did not ask to go.
 *
 * The request URI is never trusted as a URL. Only the screen and the query survive it, and the
 * destination is assembled inside the admin URL space from those two parts.
 *
 * Decides where, and never goes there: `wp_safe_redirect()` and the `exit` after it stay in
 * Resolver. That is what lets every destination be asserted directly, without a test having to
 * stand in for the end of a request.
 *
 * @since 1.0.0
 */
class Redirector {
	/**
	 * Where to send the user after deactivating.
	 *
	 * Re-requesting the screen the user is already on is the point rather than a waste: the
	 * standalone's code is in memory for this request and only a fresh one sheds it. That includes
	 * the plugins list, which is why there is no "stay put" answer. It cannot loop, either --
	 * the next request finds no active standalone, so nothing resolves and nothing redirects.
	 *
	 * The update screens are the exception, because reloading either of them re-runs an update.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $request_uri The current request URI, i.e. $_SERVER['REQUEST_URI'].
	 *
	 * @return string Absolute admin URL to send the user to.
	 */
	public function after_deactivation( $request_uri ): string {
		if ( ! is_string( $request_uri ) || $request_uri === '' ) {
			return $this->admin_url_for( 'plugins.php' );
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );

		$screen = $this->screen_from_path( is_string( $path ) ? $path : '' );

		// Nothing that names an admin screen, so there is nothing to re-render: a front-end
		// permalink, a directory that is not an admin root, a traversal attempt. Those take the same
		// route as no request URI at all.
		if ( $screen === '' ) {
			return $this->admin_url_for( 'plugins.php' );
		}

		if ( $screen === 'update.php' || $screen === 'update-core.php' ) {
			return $this->admin_url_for( 'plugins.php' );
		}

		return $this->admin_url_for( $screen . $this->query_string( $request_uri ) );
	}

	/**
	 * The admin screen a request path names, or an empty string if it names none.
	 *
	 * Read from the path's basename rather than from the URI, and returned only once it looks like
	 * an admin screen. The request URI is a path on a site that may live in a subdirectory, may be
	 * behind a TLS-terminating proxy whose scheme disagrees with admin_url(), and on multisite may
	 * sit under the network or user admin -- so nothing built from admin_url() would recognise it.
	 * Taking the basename is also what keeps a crafted URI out of the destination: only a validated
	 * screen name leaves here, and admin_url_for() supplies everything in front of it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path component of the current request URI.
	 *
	 * @return string A screen name ending in '.php', or '' for a path that names no admin screen.
	 */
	private function screen_from_path( string $path ): string {
		$screen = basename( $path );

		// Anchored with \z rather than $, which in PCRE also matches immediately before a trailing
		// newline -- so "edit.php\n" would satisfy $ and a line break would leave here inside the
		// one value this class promises is validated.
		if ( (bool) preg_match( '/^[A-Za-z0-9_-]+\.php\z/', $screen ) ) {
			return $screen;
		}

		// The admin roots name the dashboard by leaving it out, the way core's own /wp-admin/ link
		// does -- so a path that resolves to one is not a nameless directory, it is index.php. The
		// network and user admins have roots of their own, and admin_url_for() picks the base to put
		// in front of the screen.
		//
		// Matched against the end of the path rather than against its last segment alone, because a
		// front-end permalink ending in /network/ would otherwise read as the network admin's root.
		$trimmed = rtrim( $path, '/' );

		foreach ( [ '/wp-admin', '/wp-admin/network', '/wp-admin/user' ] as $root ) {
			if ( substr( $trimmed, -strlen( $root ) ) === $root ) {
				return 'index.php';
			}
		}

		return '';
	}

	/**
	 * The current request's query, re-encoded, ready to append to a screen name.
	 *
	 * The query carries which list, which page and which filter the user was looking at, so
	 * dropping it would re-render the screen showing something else. It is taken apart and rebuilt
	 * rather than carried over verbatim, because it arrives from the URL bar and nothing about it
	 * has been checked.
	 *
	 * http_build_query() rather than add_query_arg(), which is the usual answer: add_query_arg()
	 * writes the array it is given straight into the result -- build_query() passes $urlencode as
	 * false -- so a value holding an '&' or a '#' would go on to add a parameter or a fragment of
	 * its own. Here both halves of every pair are encoded, which is the whole reason for rebuilding.
	 *
	 * @since 1.0.0
	 *
	 * @param string $request_uri The current request URI.
	 *
	 * @return string Either an empty string or a leading-'?' query string.
	 */
	private function query_string( string $request_uri ): string {
		$query = wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( ! is_string( $query ) || $query === '' ) {
			return '';
		}

		$args = [];
		wp_parse_str( $query, $args );

		$args = $this->without_line_breaks( $args );

		if ( $args === [] ) {
			return '';
		}

		$rebuilt = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

		return $rebuilt === '' ? '' : '?' . $rebuilt;
	}

	/**
	 * The parsed query with CR, LF and NUL taken out of every string in it, and nothing else.
	 *
	 * The property being protected is that the destination cannot end a header: it is handed to
	 * wp_safe_redirect(), which puts it in a Location. That is all that is being protected, because
	 * it is all that is left to protect -- http_build_query() re-encodes both halves of every pair
	 * with PHP_QUERY_RFC3986, so no value can add a parameter, open a fragment or arrive as markup,
	 * whatever it holds.
	 *
	 * Deliberately not sanitize_text_field(), which is what stood here and must not be restored.
	 * wp_parse_str() has already url-decoded these values, and _sanitize_text_fields() deletes every
	 * '%xx' sequence it can find and entity-encodes a bare '<' -- so a search for '100%ab' would be
	 * re-run as '100', and one for 'a<b' as 'a&lt;b'. Re-rendering the screen the user asked for is
	 * the entire point of the redirect, and that quietly re-renders a different one.
	 *
	 * @since 1.0.0
	 *
	 * @param array<array-key,mixed> $args Query arguments as wp_parse_str() produced them.
	 *
	 * @return array<array-key,mixed>
	 */
	private function without_line_breaks( array $args ): array {
		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				$args[ $key ] = $this->without_line_breaks( $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$args[ $key ] = str_replace( [ "\r", "\n", "\0" ], '', $value );
			}
		}

		return $args;
	}

	/**
	 * An absolute admin URL for a screen, on whichever of the three admins this request belongs to.
	 *
	 * admin_url() always names a site's own admin, so a super admin resolving a conflict from
	 * /wp-admin/network/ would be thrown out of the network admin and onto the current blog's
	 * screens -- where a network-activated standalone is not manageable at all. The user admin under
	 * /wp-admin/user/ is the same failure with a different victim: someone editing their profile
	 * across a network would land on whichever site the request happened to resolve against, which
	 * they need not even be a member of.
	 *
	 * The constants both of these read are defined by wp-admin/network/admin.php and
	 * wp-admin/user/admin.php before either reaches wp-load.php, so they are already answerable at
	 * plugins_loaded, where conflict resolution runs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $screen Screen name, optionally with its query string.
	 *
	 * @return string
	 */
	private function admin_url_for( string $screen ): string {
		if ( is_network_admin() ) {
			return network_admin_url( $screen );
		}

		if ( is_user_admin() ) {
			return user_admin_url( $screen );
		}

		return admin_url( $screen );
	}
}
