<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Traits;

/**
 * The one answer to "may this user act on a plugin, at the reach this library's acts have".
 *
 * Two places ask it about the same act. `Conflict\Gatekeeper` asks before a standalone is
 * deactivated, and `Notices\Presenter` asks before the queue reporting that deactivation is printed
 * — which also clears it, for everybody. So the two have to agree by construction: a consume gate
 * looser than the resolve gate hands the only copy of a notice to somebody who could not have caused
 * what it reports and cannot undo it.
 *
 * The capability is network-scoped wherever a network exists, because that is how far the act
 * reaches. `Plugin\Deactivator` leaves `deactivate_plugins()`'s `$network_wide` at its default, which
 * core reads as both scopes, so the standalone comes out of the network's active plugins whichever
 * site the request arrived on; and the notice queue is one network option, so consuming it consumes
 * it for every site. `activate_plugins` does not establish that authority and cannot be relied on to
 * imply it: core widens it into the network capability only while the `menu_items` site option keeps
 * the Plugins menu off, so on a network that has turned that menu on, every site administrator holds
 * the site capability outright and none of them holds the network one.
 *
 * A trait rather than a shared collaborator, for the same reason `Guards_Hook_Prefix` is one: the
 * answer comes from `current_user_can()` either way, and all that is shared is which capability to
 * name. A collaborator would want a container binding, a constructor argument on both classes and an
 * interface nothing dispatches on, to carry one boolean. What it may not be is a literal in each
 * class with a docblock in each pointing at the other — that is what the two had, and they drifted
 * apart without a single test noticing.
 *
 * @since 1.0.0
 */
trait Guards_Plugin_Capability {
	/**
	 * Whether the current user may manage plugins at the scope this library acts on.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function user_may_manage_plugins(): bool {
		return current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' );
	}
}
