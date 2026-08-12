<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict\Contracts;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Decides what happens when a bundled sub-plugin's standalone counterpart is still active.
 *
 * Bind a replacement to change conflict handling globally. An implementation decides what a
 * conflict means and what to do about it; it does not decide who may have it resolved.
 * `Conflict\Gatekeeper` has already established that this is an interactive admin GET made by
 * someone who can activate plugins, so a replacement cannot drop either guard by omission — and
 * equally, it will not be asked to resolve at all on a request that fails them.
 *
 * @since 1.0.0
 */
interface Resolver_Interface {
	/**
	 * Resolve the conflict for every registered sub-plugin whose standalone is active.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return void
	 */
	public function resolve_all(): void;
}
