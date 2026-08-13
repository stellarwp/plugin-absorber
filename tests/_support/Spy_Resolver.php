<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;

/**
 * A conflict resolver that records being asked and resolves nothing.
 *
 * A named class rather than an anonymous one: a test reading `$spy->resolve_calls` off a value typed
 * as `Resolver_Interface` is reading a property the interface does not declare, and static analysis
 * rightly rejects it. Named, the spy's own type carries the counter.
 *
 * It resolves nothing, which is the point — a test that binds this one proves the conflict step
 * reached a resolver at all without deactivating anything or ending the request.
 *
 * @since 1.0.0
 */
class Spy_Resolver implements Resolver_Interface {
	/**
	 * How many times resolve_all() was called.
	 *
	 * @var int
	 */
	public $resolve_calls = 0;

	/**
	 * @return void
	 */
	public function resolve_all(): void {
		++$this->resolve_calls;
	}
}
