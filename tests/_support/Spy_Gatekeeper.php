<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Conflict\Gatekeeper;

/**
 * A gatekeeper with a fixed answer, which records having been asked for it.
 *
 * Whether a given request may resolve is `GatekeeperTest`'s subject. What this stands in for is the
 * asking: a test about the conflict step needs to pin that the step consults the gate at all, and to
 * drive both answers without arranging a whole request to produce each one.
 *
 * A named class rather than an anonymous one, so a test can read `$spy->may_resolve_calls` off a
 * value the container handed back.
 *
 * @since 1.0.0
 */
class Spy_Gatekeeper extends Gatekeeper {
	/**
	 * How many times may_resolve() was called.
	 *
	 * @var int
	 */
	public $may_resolve_calls = 0;

	/**
	 * @var bool
	 */
	private $answer;

	/**
	 * @param bool $answer The answer this gatekeeper always gives.
	 */
	public function __construct( bool $answer ) {
		$this->answer = $answer;
	}

	/**
	 * @return bool
	 */
	public function may_resolve(): bool {
		++$this->may_resolve_calls;

		return $this->answer;
	}
}
