<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Conflict_Policy;

/**
 * @since 1.0.0
 */
class ConflictPolicyTest extends WPTestCase {
	public function test_the_policy_values_are_stable(): void {
		$this->assertSame( 'deactivate', Conflict_Policy::DEACTIVATE );
		$this->assertSame( 'defer', Conflict_Policy::DEFER );
		$this->assertSame( 'notice_only', Conflict_Policy::NOTICE_ONLY );
	}

	public function test_the_policies_are_distinct(): void {
		$policies = [
			Conflict_Policy::DEACTIVATE,
			Conflict_Policy::DEFER,
			Conflict_Policy::NOTICE_ONLY,
		];

		$this->assertCount( 3, array_unique( $policies ) );
	}
}
