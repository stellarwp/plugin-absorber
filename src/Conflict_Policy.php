<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

/**
 * What to do when a sub-plugin's standalone counterpart is still active.
 *
 * @since 1.0.0
 */
final class Conflict_Policy {
	/**
	 * Deactivate the standalone, notify, and redirect. The bundled copy loads on the next
	 * request, since the standalone has already defined the guard constant on this one.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DEACTIVATE = 'deactivate';

	/**
	 * Leave the standalone alone and let it win. The load guard stands the bundled copy down.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DEFER = 'defer';

	/**
	 * Leave the standalone active but ask the user to deactivate it.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const NOTICE_ONLY = 'notice_only';

	/**
	 * The policy that applies when a sub-plugin configures none.
	 *
	 * Deactivating is the default because two copies of the same plugin are the failure this
	 * library exists to prevent, and a sub-plugin that has not thought about the question wants
	 * the outcome where its bundled copy ends up running.
	 *
	 * Distinct from the branch a caller takes for a policy it does not recognise: not configuring
	 * one is a choice to accept the default, whereas an unrecognised value is a value nobody
	 * chose, and reading it as consent to deactivate would act on a typo.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function default(): string {
		return self::DEACTIVATE;
	}

	/**
	 * Whether a policy string is one this library understands.
	 *
	 * Hosts may persist a policy in an option and filters may return anything, so callers that
	 * dispatch on a policy should reject unknown values here rather than letting them fall
	 * through to a default branch — deactivating a plugin the site owner deliberately turned on
	 * is the most surprising of the three outcomes to arrive at by accident.
	 *
	 * @since 1.0.0
	 *
	 * @param string $policy Policy to check.
	 *
	 * @return bool
	 */
	public static function is_valid( string $policy ): bool {
		return in_array( $policy, self::all(), true );
	}

	/**
	 * Every policy this library understands.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function all(): array {
		return [
			self::DEACTIVATE,
			self::DEFER,
			self::NOTICE_ONLY,
		];
	}
}
