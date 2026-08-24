<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Traits;

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Throwable;

/**
 * The two channels a failure goes down, joined so neither can be reached without the other.
 *
 * `_doing_it_wrong()` is the developer channel and stays exactly as it was: it fires
 * `doing_it_wrong_run` and then prints nothing unless `WP_DEBUG` is on, which is right for a message
 * addressed to whoever is building the host plugin. It is also the whole reason a production site
 * sees nothing at all when a bundled plugin quietly fails to load, so an action carries the same
 * sentence to anyone who asked to be told — a log, a health check, a support tool.
 *
 * One method rather than a pair of calls at each site, because a pair drifts: the failure this
 * library is likeliest to add next is a new gate, and a new gate that reports through only one of
 * the two channels is invisible in exactly the way this exists to fix.
 *
 * Cross-cutting rather than folder-scoped — the load pass, the conflict pass, the boot sequence, the
 * facade, the registry read and the hook-prefix guard all report — so it lives here beside the other
 * trait every one of those uses.
 *
 * @since 1.0.0
 */
trait Reports_Errors {
	/**
	 * Tell the developer, and tell anyone listening.
	 *
	 * Never throws, whatever a listener does, because every caller is either inside a `catch` whose
	 * whole purpose is that nothing escapes it or on a path where nothing was catching in the first
	 * place. An error action that could take the request down would turn the library's diagnostics
	 * into its worst failure mode.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $function   Member the mistake is reported against, as
	 *                                    `_doing_it_wrong()` takes it.
	 * @param string          $message    What went wrong, in the words the developer needs.
	 * @param Sub_Plugin|null $sub_plugin Sub-plugin the failure belongs to, where it belongs to one.
	 *
	 * @return void
	 */
	private static function report_error( string $function, string $message, ?Sub_Plugin $sub_plugin = null ): void {
		_doing_it_wrong( $function, $message, '1.0.0' );

		// The hook prefix is what names every hook this library fires, so a bootstrap that never set
		// one has no name to announce anything under -- including the report that it never set one,
		// which is a failure this method is called for. Routing that case through here anyway, rather
		// than leaving the prefix guard to call `_doing_it_wrong()` by itself, is what makes "the
		// missing prefix is the one failure the action cannot carry" a property of one method instead
		// of an omission at one call site that the next reader has to notice.
		try {
			$hook = Config::get_hook_name( 'error' );
		} catch ( Config_Exception $exception ) {
			return;
		}

		try {
			do_action( $hook, $message, $sub_plugin );
		} catch ( Throwable $thrown ) {
			// A plain report, never a second call to this method: a listener that throws every time
			// would otherwise announce its own failure, throw again, and recurse until the stack ran
			// out -- from inside `plugins_loaded`, on every request. The developer channel is the one
			// that cannot be broken by whatever is on the hook.
			_doing_it_wrong(
				self::class,
				sprintf(
					'A listener on %s threw, and was abandoned: %s',
					$hook,
					$thrown->getMessage()
				),
				'1.0.0'
			);
		}
	}
}
