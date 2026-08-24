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
 * This is the one conflict the load guard cannot prevent. WordPress includes the plugin being
 * activated *after* the bundled copy is in memory, so the re-declaration is a real fatal; core
 * catches it in its activation sandbox and reports "the plugin triggered a fatal error" — true, and
 * useless to whoever pressed the button. All this library gets to do about it is reword that one
 * sentence, and the wording is the sub-plugin's own `conflict_notice_message`.
 *
 * Rewording is not the whole of that screen, though. Core appends an `error_scrape` iframe to the
 * sentence, and the iframe re-runs the activation sandbox with `display_errors` forced on — so the
 * raw `Cannot redeclare …` fatal prints directly beneath the friendly explanation unless the iframe
 * comes out with the sentence.
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
	 * Handed back untouched unless the request really is a nonce-verified activation or resume
	 * error, on a plugins screen, for a standalone this library has registered — and unless one of
	 * the two sentences core reports a plugin fatal with is still there to swap out.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this read *is* the nonce.
		$nonce = isset( $_GET['_error_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_error_nonce'] ) )
			: '';

		if ( $nonce === '' ) {
			return $markup;
		}

		$sub_plugin = $this->find_by_activation_error( $nonce ) ?? $this->find_by_resume_error( $nonce );

		if ( $sub_plugin === null ) {
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

		foreach ( $this->sentences_core_reports_a_fatal_with() as $sentence ) {
			if ( strpos( $markup, $sentence ) === false ) {
				continue;
			}

			return $this->without_the_error_scrape( str_replace( $sentence, $message, $markup ) );
		}

		// Neither sentence is in there, so this notice was authored by something other than the
		// screen this class knows how to improve. The iframe stays with it: a notice nobody
		// explained is bad, and a notice nobody explained with its one diagnostic quietly deleted
		// is worse.
		return $markup;
	}

	/**
	 * The two sentences core reports a sandboxed plugin fatal with, exactly as it prints them.
	 *
	 * Read back through `__()` against core's own text domain rather than written out as literals,
	 * so the swap still happens on a site running WordPress in another language: `wp_admin_notice()`
	 * receives the translated sentence, and an English needle would match nothing there.
	 *
	 * The resume wording belongs beside the activation one because the conflict outlives the
	 * activation screen. Core's fatal-error handler pauses the plugin its sandbox died in, so the
	 * standalone comes back on the plugins list with a Resume link, and pressing that re-runs the
	 * same sandbox into the same re-declaration — reported just as uselessly, in a different verb.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private function sentences_core_reports_a_fatal_with(): array {
		return [
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's own string, matched on purpose.
			__( 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.', 'default' ),
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's own string, matched on purpose.
			__( 'Plugin could not be resumed because it triggered a <strong>fatal error</strong>.', 'default' ),
		];
	}

	/**
	 * The registered sub-plugin an activation-error request names, if the nonce agrees.
	 *
	 * The `plugin` argument is unslashed and no further. Core mints the activation-error nonce from
	 * `wp_unslash( $_REQUEST['plugin'] )` verbatim (wp-admin/plugins.php), so sanitizing here would
	 * verify an action core never signed: a plugin whose folder name holds a '%xx' sequence, a '<'
	 * or a leading space comes back changed from sanitize_text_field(), and both the nonce check and
	 * the registry lookup would then miss -- silently, and on the one screen this class exists to
	 * improve. Nothing sanitizing would remove is needed here either: the value is compared against
	 * a basename the host configured and hashed into a nonce action, and never reaches the page.
	 * What does reach it is the sub-plugin's message.
	 *
	 * is_string() because sanitize_text_field() was doing that job: '?plugin[]=x' arrives as an
	 * array, and an array reaching wp_verify_nonce() is a string conversion, not a refusal.
	 *
	 * The registry is read before the nonce is verified, deliberately: there is no nonce work to do
	 * for a plugin this library does not own, and the nonce is still verified before any markup is
	 * touched.
	 *
	 * @since 1.0.0
	 *
	 * @param string $nonce The `_error_nonce` this request carries.
	 *
	 * @return Sub_Plugin|null
	 */
	private function find_by_activation_error( string $nonce ): ?Sub_Plugin {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below, once the
		// plugin named turns out to be one this library owns. Nothing is acted on until then.
		$basename = isset( $_GET['plugin'] ) ? wp_unslash( $_GET['plugin'] ) : '';

		if ( ! is_string( $basename ) || $basename === '' ) {
			return null;
		}

		$sub_plugin = $this->find_by_standalone_basename( $basename );

		if ( $sub_plugin === null ) {
			return null;
		}

		return wp_verify_nonce( $nonce, 'plugin-activation-error_' . $basename ) ? $sub_plugin : null;
	}

	/**
	 * The registered sub-plugin a resume-error request is about, if the nonce agrees.
	 *
	 * Identified from the nonce alone, because core's resume redirect carries no `plugin` argument
	 * to read: `resume_plugin()` appends `_error_nonce` and nothing else, minted from
	 * `plugin-resume-error_` and the basename. The nonce is therefore the only thing on the request
	 * that names a plugin, so every registered standalone is offered to it in turn and the one it
	 * was signed for answers. A forged value still matches nothing -- being unable to name the
	 * plugin does not make the check weaker, only the loop longer.
	 *
	 * A sub-plugin with no standalone configured is skipped rather than tested against an action
	 * that ends in nothing, which is a question about a plugin that does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $nonce The `_error_nonce` this request carries.
	 *
	 * @return Sub_Plugin|null
	 */
	private function find_by_resume_error( string $nonce ): ?Sub_Plugin {
		foreach ( $this->registry->all() as $sub_plugin ) {
			if ( ! $sub_plugin->has_standalone_plugin() ) {
				continue;
			}

			$action = 'plugin-resume-error_' . $sub_plugin->get_standalone_plugin_basename();

			if ( wp_verify_nonce( $nonce, $action ) ) {
				return $sub_plugin;
			}
		}

		return null;
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

	/**
	 * The same notice with core's activation-sandbox iframe taken out of it.
	 *
	 * Core appends `<iframe … src="…plugins.php?action=error_scrape&…">` to the sentence just
	 * replaced (wp-admin/plugins.php). That request runs `plugin_sandbox_scrape()` again with
	 * `display_errors` forced on, so the raw `Cannot redeclare …` fatal prints inside the notice,
	 * directly under the explanation — and rewording alone leaves the owner reading both, the second
	 * one contradicting the first.
	 *
	 * Matched on `error_scrape` within the opening tag rather than on the whole element core built.
	 * Rebuilding that string would mean reproducing `add_query_arg()`, `urlencode()` and `esc_url()`
	 * over a URL assembled from `admin_url()`, and a filter on any of them makes the removal miss
	 * without saying so. `[^>]*` cannot run past the end of the tag, and `action=error_scrape` is
	 * the one request in wp-admin that re-runs the fatal — so an iframe some other plugin appended,
	 * and every other thing core put in this notice, is out of the pattern's reach.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup whose sentence has already been rewritten.
	 *
	 * @return string
	 */
	private function without_the_error_scrape( string $markup ): string {
		$stripped = preg_replace( '#<iframe\b[^>]*\berror_scrape\b[^>]*>\s*</iframe>#i', '', $markup );

		// Null means the match itself failed -- a backtrack limit, or invalid UTF-8 carried in by
		// the message just substituted. The reworded sentence is worth keeping on its own.
		return is_string( $stripped ) ? $stripped : $markup;
	}
}
