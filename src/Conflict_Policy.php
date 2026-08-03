<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

/**
 * What to do when a sub-plugin's standalone counterpart is still active.
 *
 * @since 1.0.0
 */
final class Conflict_Policy {
	/**
	 * Deactivate the standalone, load the bundled copy, notify, redirect. The default.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DEACTIVATE = 'deactivate';

	/**
	 * Leave the standalone alone and let it win. The load guard stands the bundled copy down.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DEFER = 'defer';

	/**
	 * Leave the standalone active but ask the user to deactivate it.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const NOTICE_ONLY = 'notice_only';
}
