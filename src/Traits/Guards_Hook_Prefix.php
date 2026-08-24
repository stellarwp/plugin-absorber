<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Traits;

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Turns "no hook prefix has been set" into something a developer sees and a request survives.
 *
 * Both of this library's hook callbacks need the prefix — one for the should_load filter, the
 * other for the option the notice queue lives in — and both are reached from a core action, where
 * throwing would take the whole site down over a bootstrap mistake. A trait rather than a shared
 * collaborator because the answer comes from `Config` either way; all that is shared is how the
 * mistake is reported.
 *
 * @since 1.0.0
 */
trait Guards_Hook_Prefix {
	use Reports_Errors;

	/**
	 * Whether a hook prefix has been set, reporting to the developer when it has not.
	 *
	 * Reported through the shared channel even though this is the one failure the error action can
	 * never carry — the prefix is what names that action too, so there is nothing to fire it under.
	 * The alternative, a bare `_doing_it_wrong()` here, would read as an oversight and would be one
	 * the moment somebody made the prefix optional.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function has_hook_prefix(): bool {
		try {
			Config::get_hook_prefix();
		} catch ( Config_Exception $exception ) {
			self::report_error( self::class, $exception->getMessage() );

			return false;
		}

		return true;
	}
}
