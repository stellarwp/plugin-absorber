<?php
/**
 * Writes throwaway bundled plugin files for the load path to require.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * A bundled plugin file that records its own load.
 *
 * Every call writes a *new* file under a unique name. That is not tidiness: `require_once` dedupes by
 * resolved path for the lifetime of the PHP process, so a fixture shared between two tests would let
 * the second one pass without loading anything at all.
 *
 * The load counter lives in a global because the fixture is executed by `require_once` at whatever
 * scope the loader calls it from, and a global is the only thing it can reach from there.
 *
 * @since 1.0.0
 */
trait WithBundledPlugins {
	/**
	 * Files written by this test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $bundled_plugin_files = [];

	/**
	 * Write a bundled plugin that counts its own loads and defines its guard constant.
	 *
	 * The counter is what separates "loaded twice" and "never loaded" from "loaded once". The
	 * constant alone cannot tell those three apart, because it ends up defined exactly once either
	 * way.
	 *
	 * The constant is defined inside a `defined()` check, because the file stands in for a real
	 * plugin: a bundled copy that redeclared a constant the standalone had already defined would
	 * raise a notice, and the guard is what a plugin actually ships.
	 *
	 * @since 1.0.0
	 *
	 * @param string $constant Guard constant the file defines, as a bundled plugin's own header would.
	 *
	 * @return string
	 */
	protected function make_bundled_plugin_file( string $constant ): string {
		$path = sys_get_temp_dir() . '/absorber-' . uniqid( '', true ) . '.php';

		file_put_contents(
			$path,
			'<?php' . PHP_EOL
			. '$GLOBALS["absorber_loads"] = ( $GLOBALS["absorber_loads"] ?? 0 ) + 1;' . PHP_EOL
			. 'if ( ! defined( "' . $constant . '" ) ) { define( "' . $constant . '", "1.0.0" ); }' . PHP_EOL
		);

		$this->bundled_plugin_files[] = $path;

		return $path;
	}

	/**
	 * A guard constant name no other test can collide with.
	 *
	 * The fixture defines its constant for real, and a `define()` lasts for the whole PHP process: a
	 * name reused by a later test would make its sub-plugin read as already loaded and skip the load
	 * it was written to exercise.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function make_guard_constant(): string {
		return 'ABSORBER_FIXTURE_' . strtoupper( bin2hex( random_bytes( 4 ) ) );
	}

	/**
	 * A path no bundled plugin was ever written to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function missing_bundled_plugin_file(): string {
		return sys_get_temp_dir() . '/absorber-does-not-exist-' . uniqid( '', true ) . '.php';
	}

	/**
	 * How many times a bundled fixture has been executed this test.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	protected function bundled_plugin_loads(): int {
		$loads = $GLOBALS['absorber_loads'] ?? 0;

		return is_int( $loads ) ? $loads : 0;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function reset_bundled_plugin_loads(): void {
		$GLOBALS['absorber_loads'] = 0;
	}

	/**
	 * Remove every fixture this test wrote.
	 *
	 * Runs itself, as PHPUnit's own `@after` hook, and is safe to call from tearDown as well — which
	 * is where the tests that clear other state alongside it do call it. A test body must never be
	 * the only thing that removes these: a failed assertion aborts the test where it stands, so a
	 * cleanup line at the end of the body is exactly the one that does not run on the day it matters.
	 *
	 * @after
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function remove_bundled_plugin_files(): void {
		foreach ( $this->bundled_plugin_files as $path ) {
			if ( file_exists( $path ) ) {
				// A test that made a file unreadable to exercise that gate cannot unlink it until the
				// permissions come back.
				chmod( $path, 0644 );
				unlink( $path );
			}
		}

		$this->bundled_plugin_files = [];

		unset( $GLOBALS['absorber_loads'] );
	}
}
