<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict\Contracts;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Decides what happens when a bundled sub-plugin's standalone counterpart is still active.
 *
 * Bind a replacement to change what happens about a conflict. One method, because the policy branch
 * is all this contract promises: finding the conflict belongs to `Conflict\Detector` and deciding
 * who may have one resolved to `Conflict\Gatekeeper`.
 *
 * Both of those are the caller's to ask, and the conflict step asks them before it builds an
 * implementation of this — so a replacement cannot drop a guard by omission, is never asked to
 * resolve on a request that fails one, and is not built at all on a request with nothing to
 * resolve.
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
