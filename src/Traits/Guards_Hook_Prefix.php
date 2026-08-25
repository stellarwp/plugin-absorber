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
 * Every entry point this library puts on a hook needs the prefix — it names the should_load filter,
 * the conflict and notice filters, and the option the notice queue lives in — and every one of them
 * is reached from a core hook, where throwing would take the whole site down over a bootstrap
 * mistake. So the load pass, the conflict pass, the gatekeeper and both of the facade's admin
 * trampolines ask this first and stand down when the answer is no. A trait rather than a shared
 * collaborator because the answer comes from `Config` either way; all that is shared is how the
 * mistake is reported.
 *
 * @since 1.0.0
 */
trait Guards_Hook_Prefix {
	/**
	 * Whether a hook prefix has been set, reporting to the developer when it has not.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function has_hook_prefix(): bool {
		try {
			Config::get_hook_prefix();
		} catch ( Config_Exception $exception ) {
			_doing_it_wrong( self::class, $exception->getMessage(), '1.0.0' );

			return false;
		}

		return true;
	}
}
