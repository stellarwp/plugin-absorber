<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Conflict_Policy;
use ReflectionClass;

/**
 * @since 1.0.0
 */
class ConflictPolicyTest extends WPTestCase {
	public function test_the_policy_values_are_stable(): void {
		$this->assertSame( 'deactivate', Conflict_Policy::DEACTIVATE );
		$this->assertSame( 'defer', Conflict_Policy::DEFER );
		$this->assertSame( 'notice_only', Conflict_Policy::NOTICE_ONLY );
	}

	/**
	 * Pins the whole set, not just the three names. A fourth policy added without teaching
	 * the resolver about it would otherwise be swallowed by that switch's default branch.
	 */
	public function test_no_policy_is_added_or_removed_unnoticed(): void {
		$constants = ( new ReflectionClass( Conflict_Policy::class ) )->getConstants();

		$this->assertSame(
			[
				'DEACTIVATE'  => 'deactivate',
				'DEFER'       => 'defer',
				'NOTICE_ONLY' => 'notice_only',
			],
			$constants
		);
	}

	public function test_all_returns_every_policy(): void {
		$this->assertSame(
			[ 'deactivate', 'defer', 'notice_only' ],
			Conflict_Policy::all()
		);
	}

	/**
	 * @dataProvider valid_policies
	 *
	 * @param string $policy Policy under test.
	 */
	public function test_it_accepts_a_known_policy( string $policy ): void {
		$this->assertTrue( Conflict_Policy::is_valid( $policy ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function valid_policies(): array {
		return [
			'deactivate'  => [ Conflict_Policy::DEACTIVATE ],
			'defer'       => [ Conflict_Policy::DEFER ],
			'notice_only' => [ Conflict_Policy::NOTICE_ONLY ],
		];
	}

	/**
	 * @dataProvider invalid_policies
	 *
	 * @param string $policy Policy under test.
	 */
	public function test_it_rejects_an_unknown_policy( string $policy ): void {
		$this->assertFalse( Conflict_Policy::is_valid( $policy ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function invalid_policies(): array {
		return [
			'typo'       => [ 'defered' ],
			'empty'      => [ '' ],
			'wrong case' => [ 'DEACTIVATE' ],
			'constant'   => [ 'Conflict_Policy::DEFER' ],
		];
	}
}
