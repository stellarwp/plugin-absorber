<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
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

	/**
	 * The list a host builds its own settings control from. Order is pinned because a control
	 * rendering the list as it is handed would otherwise shuffle its options between releases.
	 */
	public function test_it_lists_every_policy(): void {
		$this->assertSame(
			[
				Conflict_Policy::DEACTIVATE,
				Conflict_Policy::DEFER,
				Conflict_Policy::NOTICE_ONLY,
			],
			Conflict_Policy::all()
		);
	}

	/**
	 * Cross-checked against the constants, so a fourth policy declared without being added to
	 * the list fails here rather than going silently missing from every control built on it.
	 */
	public function test_the_list_holds_every_policy_constant(): void {
		$constants = ( new ReflectionClass( Conflict_Policy::class ) )->getConstants();

		$this->assertSame( array_values( $constants ), Conflict_Policy::all() );
	}

	public function test_the_default_is_to_deactivate(): void {
		$this->assertSame( Conflict_Policy::DEACTIVATE, Conflict_Policy::default() );
	}

	/**
	 * A default nobody understands would send every unconfigured sub-plugin down the branch a
	 * caller takes for garbage, which is not what "no policy configured" means.
	 */
	public function test_the_default_is_a_policy_the_library_understands(): void {
		$this->assertTrue( Conflict_Policy::is_valid( Conflict_Policy::default() ) );
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
	 * Drawn from the constants rather than a hand-written list, so a fourth policy declared
	 * without being added to the set behind is_valid() fails here instead of passing unnoticed.
	 *
	 * @return Generator<string,array{0:string}>
	 */
	public static function valid_policies(): Generator {
		$constants = ( new ReflectionClass( Conflict_Policy::class ) )->getConstants();

		foreach ( $constants as $name => $value ) {
			// A non-string constant is yielded as the empty string rather than skipped, so a policy
			// declared as something other than a string fails is_valid() here instead of vanishing
			// from the set this provider exists to keep complete.
			yield $name => [ is_string( $value ) ? $value : '' ];
		}
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
	 * @return Generator<string,array{0:string}>
	 */
	public static function invalid_policies(): Generator {
		yield 'typo'       => [ 'defered' ];
		yield 'empty'      => [ '' ];
		yield 'wrong case' => [ 'DEACTIVATE' ];
		yield 'constant'   => [ 'Conflict_Policy::DEFER' ];
	}
}
