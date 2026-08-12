<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;

/**
 * Whether this request may have a conflict resolved at all.
 *
 * Separate from the resolver, and resolved ahead of it, because the two answer different questions:
 * a resolver decides what a conflict means and what to do about it, while this decides who is
 * allowed to have one resolved. That split is what makes the guarantee survive rebinding — the
 * conflict step asks the gatekeeper first and only then builds a `Resolver_Interface`, so a host
 * that binds its own resolver cannot drop a gate by omission, and equally is never asked to resolve
 * on a request that fails one.
 *
 * @since 1.0.0
 */
class Gatekeeper {
	use Guards_Hook_Prefix;

	/**
	 * Whether conflict resolution may run on this request.
	 *
	 * The hook prefix is checked last of the three. It is the only one of them that reports to the
	 * developer, and resolution runs from plugins_loaded, so checking it first would put a
	 * _doing_it_wrong() in the log of every front-end request rather than of the admin request that
	 * was actually about to resolve something.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function may_resolve(): bool {
		return $this->is_interactive_admin_request()
			&& $this->can_resolve_conflicts()
			&& self::has_hook_prefix();
	}

	/**
	 * Whether this request is one a person is watching in wp-admin.
	 *
	 * Conflict resolution deactivates a plugin and ends the request, so it must only run where
	 * someone is there to see the result. Unguarded it fires at plugins_loaded on every request:
	 * a visitor's checkout POST becomes a 302 that drops the order, a login POST bounces back to
	 * a blank form, wp-cron never reaches its event loop, and a WP-CLI command exits 0 having
	 * printed nothing, because header() is a no-op under the CLI SAPI.
	 *
	 * is_admin() alone is not enough: admin-ajax.php and admin-post.php both define WP_ADMIN.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_interactive_admin_request(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}

		// Only a GET. A redirect discards the request, and the browser follows it with a GET, so
		// anything submitted is gone -- which is exactly what would happen to a form posted to
		// admin-post.php or options.php, both of which define WP_ADMIN and neither of which
		// wp_doing_ajax() catches. Core draws the same line in wp_cron(). Deferring resolution to
		// the next page view costs nothing: the standalone is still there to detect.
		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
			return false;
		}

		return is_admin();
	}

	/**
	 * Whether the current user may have a conflict resolved on their request.
	 *
	 * Reaching conflict resolution does not mean anyone is signed in. wp-admin/admin.php loads
	 * wp-load.php -- which dispatches plugins_loaded -- well before it calls auth_redirect(), so an
	 * unauthenticated GET of any admin URL gets this far. Without this check a stranger could turn
	 * the standalone off site-wide by requesting a page they are about to be bounced off.
	 *
	 * Here rather than inside the default resolver, because it is the one thing about conflict
	 * resolution that must survive a host binding its own: whoever cannot activate a plugin must not
	 * be able to deactivate one, and a replacement that forgot to re-check would reopen exactly that.
	 *
	 * It gates every policy, not only the destructive one, and that costs nothing. The other
	 * policies queue a notice, and Notices\Queue::render() will not render -- or clear -- for a user
	 * without this same capability. Queuing on a request that cannot act only parks the notice until
	 * a capable administrator arrives, which is the request this gate lets resolution run on anyway.
	 * Nothing is consumed or suppressed by waiting: the standalone is still there to detect.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function can_resolve_conflicts(): bool {
		return current_user_can( 'activate_plugins' );
	}
}
