<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

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
 * The two gates are asked at different moments, which is why they are two methods rather than one.
 * The request gate is cheap and reads nothing but the request, so it runs first and keeps a resolver
 * from being built at all. The user gate is asked last, once a conflict is known to exist, because
 * `current_user_can()` resolves the current user and caches it: called from plugins_loaded priority
 * 5 it would settle who is signed in before an SSO or JWT plugin that hooks `determine_current_user`
 * from its own plugins_loaded callback has been given the chance to, and that plugin's users would
 * then be treated as logged out for the rest of the request. A site with no conflict never pays it.
 *
 * @since 1.0.0
 */
class Gatekeeper {
	use Guards_Hook_Prefix;

	/**
	 * Admin scripts that exist only to perform work.
	 *
	 * Every one of them does its job and then redirects or prints a result, so there is no page here
	 * to resolve a conflict on — only work to interrupt.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const ACTION_ENDPOINTS = [
		'admin-post.php',
		'admin-ajax.php',
		'update.php',
		'export.php',
	];

	/**
	 * Query args that name an action for the current screen to perform.
	 *
	 * Both, because core's list tables put a bulk selector above the table and a second one below it,
	 * and the lower one submits as `action2` with `action` left at its empty value.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const ACTION_ARGS = [ 'action', 'action2' ];

	/**
	 * The value core gives an action arg when no bulk action is selected.
	 *
	 * A list table submits its selector whether or not anything was chosen, so this arrives on
	 * ordinary paging and search requests and means nothing is being asked for.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const NO_ACTION = '-1';

	/**
	 * Whether this request is the kind conflict resolution may run on.
	 *
	 * Reads nothing about who is making it: the capability lives in user_may_resolve(), so that
	 * asking this question does not decide the current user before the plugins that have an opinion
	 * about it have loaded.
	 *
	 * The hook prefix is checked last. It is the only one of these that reports to the developer, and
	 * resolution runs from plugins_loaded, so checking it first would put a _doing_it_wrong() in the
	 * log of every front-end request rather than of the admin request that was about to resolve
	 * something.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function request_may_resolve(): bool {
		return $this->is_interactive_admin_request()
			&& ! $this->is_action_request()
			&& self::has_hook_prefix();
	}

	/**
	 * Whether the current user may have a conflict resolved on their request.
	 *
	 * Reaching conflict resolution does not mean anyone is signed in. wp-admin/admin.php loads
	 * wp-load.php -- which dispatches plugins_loaded -- well before it calls auth_redirect(), so an
	 * unauthenticated GET of any admin URL gets this far. Without this check a stranger could turn
	 * the standalone off site-wide by requesting a page they are about to be bounced off.
	 *
	 * The capability asked for matches what resolution can do, which is why the two differ. The
	 * deactivation is network-wide: Deactivator leaves deactivate_plugins()'s $network_wide
	 * at its default, and core reads that as both scopes, so the standalone comes out of the
	 * network's active plugins whichever site the request arrived on. That is authority a single
	 * site's administrator does not hold, and asking for activate_plugins would not establish it --
	 * core only widens that capability into the network one while a network setting says to, so on a
	 * network that has said otherwise every subsite administrator would pass. Where a network exists,
	 * the network-scoped capability is the one whose reach matches the action's.
	 *
	 * Here rather than inside the default resolver, because it is the one thing about conflict
	 * resolution that must survive a host binding its own: whoever cannot activate a plugin must not
	 * be able to deactivate one, and a replacement that forgot to re-check would reopen exactly that.
	 *
	 * It gates every policy, not only the destructive one, and that costs nothing. The other
	 * policies queue a notice, and Notices\Presenter::render() will not render -- or clear -- for a user
	 * with no plugin capability at all. Queuing on a request that cannot act only parks the notice
	 * until an administrator who can act arrives, which is the request this gate lets resolution run
	 * on anyway. Nothing is consumed or suppressed by waiting: the standalone is still there to
	 * detect.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function user_may_resolve(): bool {
		return current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' );
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
	 * Whether this GET is asking wp-admin to do something rather than to draw something.
	 *
	 * A GET is not automatically safe to discard. `update.php?action=upgrade-plugin`,
	 * `plugins.php?action=activate` and an `admin-post.php` link are all admin GETs that perform
	 * work, and resolving on one deactivates, redirects and exits before core reaches the work: the
	 * user clicks "Update Now" and lands on a list screen with nothing updated. That is the same
	 * silent discard of a submitted action the POST branch exists to prevent, one verb over.
	 *
	 * `plugins.php?action=activate` matters twice, because it is the request
	 * plugin_sandbox_scrape() replays while activating a plugin -- an exit there aborts the
	 * activation itself and core reports the plugin as fatal.
	 *
	 * The test is deliberately blunt: any action arg at all, not a list of the dangerous ones. Half
	 * the screens in wp-admin take an `action`, plugins define their own, and a list of known-safe
	 * values would have to be right about every one of them forever. Refusing them all costs a
	 * deferral to the next plain page view, and the standalone is still there to be detected then.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_action_request(): bool {
		if ( in_array( $this->current_script(), self::ACTION_ENDPOINTS, true ) ) {
			return true;
		}

		foreach ( self::ACTION_ARGS as $arg ) {
			$raw = $_GET[ $arg ] ?? null;

			// Anything that is not a string names no action core could dispatch on: an array never
			// matches one of core's action names, and a missing arg asks for nothing at all. There is
			// no work on either to interrupt.
			if ( ! is_string( $raw ) ) {
				continue;
			}

			// Compared as it arrived rather than through sanitize_key(): admin.php dispatches
			// admin_action_{$action} on the raw value, so an action named outside a-z0-9_- -- a
			// non-Latin script, a bare '+' -- is work a plugin can be hooked to, and sanitizing
			// first would empty it and admit the very request this gate exists to refuse.
			$action = wp_unslash( $raw );

			if ( is_string( $action ) && $action !== '' && $action !== self::NO_ACTION ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The admin script this request is running, as a bare file name.
	 *
	 * $pagenow is what core's own admin code branches on, and wp-settings.php requires the file that
	 * sets it before it loads a single plugin, so it is already populated when plugins_loaded
	 * dispatches. It is also the value that has been reduced to a bare file name for all three
	 * admins -- site, network and user -- where matching on a path would have to know about each of
	 * those prefixes.
	 *
	 * The fallback is not superstition: core derives $pagenow from PHP_SELF, which some SAPI and
	 * proxy configurations leave empty, and a host is free to have unset the global. SCRIPT_NAME is
	 * consulted first there because it is the one every FastCGI SAPI fills in.
	 *
	 * @since 1.0.0
	 *
	 * @return string Lower-cased file name, or an empty string when the request names none.
	 */
	private function current_script(): string {
		$candidates = [
			$GLOBALS['pagenow'] ?? null,
			$_SERVER['SCRIPT_NAME'] ?? null,
			$_SERVER['PHP_SELF'] ?? null,
		];

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || $candidate === '' ) {
				continue;
			}

			$path = wp_unslash( $candidate );

			return strtolower( basename( is_string( $path ) ? $path : '' ) );
		}

		return '';
	}
}
