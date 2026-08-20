<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Conflict\Gatekeeper;

/**
 * A gatekeeper that records being asked and answers whatever it was built to answer.
 *
 * A named class rather than an anonymous one: a test reading `$spy->user_may_resolve_calls` off a
 * value typed as `Gatekeeper` is reading a property the class does not declare, and static analysis
 * rightly rejects it. Named, the spy's own type carries the counters.
 *
 * A subclass rather than an implementation of a contract, because there is no contract: the
 * gatekeeper is bound by class name, which is the seam a host rebinds and a test extends. The parent
 * has no constructor of its own, so nothing is left unbuilt by not calling one.
 *
 * Both gates are counted separately, because they are asked at different moments and one of them may
 * not be reached at all — the request gate runs first and turns away everything that is not an
 * interactive admin GET, while the capability gate is asked only once a conflict is known to exist.
 * A single counter would read a request that never got past the first gate as one that passed both.
 *
 * @since 1.0.0
 */
class Spy_Gatekeeper extends Gatekeeper {
	/**
	 * How many times request_may_resolve() was called.
	 *
	 * @var int
	 */
	public $request_may_resolve_calls = 0;

	/**
	 * How many times user_may_resolve() was called.
	 *
	 * @var int
	 */
	public $user_may_resolve_calls = 0;

	/**
	 * What both gates answer.
	 *
	 * @var bool
	 */
	private $may_resolve;

	/**
	 * @param bool $may_resolve What both gates answer, for the life of this spy.
	 */
	public function __construct( bool $may_resolve ) {
		$this->may_resolve = $may_resolve;
	}

	/**
	 * @return bool
	 */
	public function request_may_resolve(): bool {
		++$this->request_may_resolve_calls;

		return $this->may_resolve;
	}

	/**
	 * @return bool
	 */
	public function user_may_resolve(): bool {
		++$this->user_may_resolve_calls;

		return $this->may_resolve;
	}
}
