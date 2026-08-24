<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Notices;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Traits\Guards_Plugin_Capability;

/**
 * Who may consume the queue, and what happens when they do.
 *
 * Separate from `Writer` because the two change for different reasons: one is asked "what does this
 * notice say", the other "may this user see the pending set, and is it gone once they have". Nothing
 * here words a notice, and nothing in the writer decides who reads one.
 *
 * The capability check lives here rather than in `Renderer` because it guards the clearing as much as
 * the drawing: the two have to be decided together, or a user who may not see the queue could still
 * destroy it. Which capability is `Traits\Guards_Plugin_Capability`'s answer, shared with the gate
 * that decides who may have a conflict resolved at all — the notices report what that gate let
 * happen, so consuming one is the same authority as causing it.
 *
 * The queue is single-consumer. Rendering consumes it for everybody, so the first eligible
 * administrator to load any admin screen is the only person who ever sees a given notice —
 * network-wide on multisite, where the queue is one network option. A host that wants every
 * administrator to see it has to track consumption per user itself.
 *
 * Bound by class name rather than behind a contract: the trampoline on `all_admin_notices` is the
 * only caller, so nothing in the library dispatches on this, and a host that wants no rendering of
 * ours takes that callback off rather than binding an implementation that does nothing.
 *
 * @since 1.0.0
 */
class Presenter {
	use Guards_Plugin_Capability;

	/**
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * @since 1.0.0
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * @since 1.0.0
	 *
	 * @param Store    $store    Where the queue is kept.
	 * @param Renderer $renderer How a queued notice is drawn.
	 */
	public function __construct( Store $store, Renderer $renderer ) {
		$this->store    = $store;
		$this->renderer = $renderer;
	}

	/**
	 * Draw the queue, then consume it.
	 *
	 * The store rather than the bound writer, deliberately: this draws what the *default* writer
	 * kept, and a host that binds a writer of its own has taken over where notices live along with
	 * what they say.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function render(): void {
		// Rendering clears the queue, so a user who cannot act on a notice must not be shown one: a
		// subscriber loading their profile page would otherwise silently swallow the only warning an
		// administrator was ever going to get, and on multisite the queue they swallowed it out of is
		// shared by every site on the network.
		if ( ! self::user_may_manage_plugins() ) {
			return;
		}

		$queue = $this->store->all();

		if ( $queue === [] ) {
			return;
		}

		$this->renderer->render( $queue );

		$this->store->clear();
	}
}
