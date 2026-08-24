<?php
/**
 * Fixtures for the guard that pulls `wp-admin/includes/plugin.php` in.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use RuntimeException;

/**
 * One shared way to put a caller of `Plugin\Loads_Plugin_Functions` into each of the two states its
 * guard distinguishes, and to count what the guard included.
 *
 * The missing-functions state is built rather than found. Every test that stubs a plugin function has
 * to require the real `wp-admin/includes/plugin.php` first, because uopz cannot stub a function that
 * does not exist yet; a `require_once` cannot be undone for the rest of the process; and no test may
 * depend on having run before whichever other one loads it. So ABSPATH is pointed at a fixture root
 * and `function_exists()` answers about the guard's one name the way it does on a front-end request,
 * where WordPress has loaded none of this.
 *
 * That name is spelled here rather than passed in, because which one it is, is the invariant:
 * `deactivate_plugins()`, never `is_plugin_active()`, which is a common third-party shim. Both callers
 * of the trait ask the same question, so neither can drift into proving the other one's guard.
 *
 * Requires `lucatume\WPBrowser\Traits\UopzFunctions` on the same test class, for the constant and the
 * function stub.
 *
 * @since 1.0.0
 */
trait WithPluginFunctions {
	/**
	 * Throwaway WordPress roots written by this test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $wordpress_roots = [];

	/**
	 * Run a call with ABSPATH at a fixture root and the plugin functions reported missing.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $call The call under test.
	 *
	 * @return string[] Every name `function_exists()` was asked about, in order.
	 */
	protected function with_plugin_functions_missing( callable $call ): array {
		$asked = [];
		$root  = $this->make_wordpress_root();

		$restore_root  = $this->setConstant( 'ABSPATH', $root );
		$restore_probe = $this->setFunctionReturn(
			'function_exists',
			static function ( $name ) use ( &$asked ) {
				$asked[] = is_string( $name ) ? $name : '';

				// Every other question is answered as it really stands. is_callable() rather than a
				// recursive function_exists(), which this closure has replaced.
				return $name === 'deactivate_plugins' ? false : is_callable( $name );
			},
			true
		);

		try {
			$call();
		} finally {
			// Undone the moment the call under test returns rather than in tearDown, and in a finally
			// so a throw above cannot strand either one. Both are process-global -- everything in the
			// process reads ABSPATH and asks function_exists(), the test's own assertions included --
			// so the window they are wrong in has to be the call and nothing else. UopzFunctions'
			// `@after` is the backstop.
			$restore_probe();
			$restore_root();
		}

		return $asked;
	}

	/**
	 * Run a call with ABSPATH at a fixture root and the plugin functions really in memory.
	 *
	 * The root comes back so the caller can include the fixture itself: "the guard included nothing"
	 * is a claim about a counter, and a counter that never counts satisfies it however the guard
	 * behaved.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $call The call under test.
	 *
	 * @throws RuntimeException When the plugin functions are not in memory, rather than running the
	 *                          missing-functions state under the present-functions name.
	 *
	 * @return string The fixture root, with a trailing slash, as ABSPATH carries.
	 */
	protected function with_plugin_functions_present( callable $call ): string {
		// All this helper does is repoint ABSPATH; what makes the functions present is the caller's
		// setUp having required the real file. Unchecked, a caller that forgot would get the other
		// state under this name, and every assertion about the guard standing down would pass for
		// exactly the reason it is supposed to rule out.
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			throw new RuntimeException( 'The plugin functions are not in memory; require wp-admin/includes/plugin.php in setUp.' );
		}

		$root    = $this->make_wordpress_root();
		$restore = $this->setConstant( 'ABSPATH', $root );

		try {
			$call();
		} finally {
			$restore();
		}

		return $root;
	}

	/**
	 * How many times a fixture `plugin.php` has been included this test.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	protected function plugin_functions_loads(): int {
		$loads = $GLOBALS['absorber_plugin_functions_loads'] ?? 0;

		return is_int( $loads ) ? $loads : 0;
	}

	/**
	 * Put the counter back to nothing. Call from setUp, so a test never reads a neighbour's total.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function forget_plugin_functions_loads(): void {
		unset( $GLOBALS['absorber_plugin_functions_loads'] );
	}

	/**
	 * Remove every fixture root written, and forget the counter. Call from tearDown.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down_plugin_functions(): void {
		foreach ( $this->wordpress_roots as $root ) {
			$file = $root . 'wp-admin/includes/plugin.php';

			if ( file_exists( $file ) ) {
				unlink( $file );
			}

			foreach ( [ 'wp-admin/includes', 'wp-admin', '' ] as $directory ) {
				$path = $root . $directory;

				if ( is_dir( $path ) ) {
					rmdir( $path );
				}
			}
		}

		$this->wordpress_roots = [];

		$this->forget_plugin_functions_loads();
	}

	/**
	 * Write a WordPress root whose `wp-admin/includes/plugin.php` records that it was included.
	 *
	 * A new root every call. `require_once` dedupes by resolved path for the lifetime of the PHP
	 * process, so a fixture shared between two tests lets the second one pass without including
	 * anything at all.
	 *
	 * @since 1.0.0
	 *
	 * @throws RuntimeException When the fixture cannot be written, rather than reporting a load that
	 *                          never had anywhere to happen.
	 *
	 * @return string Root with a trailing slash, as ABSPATH carries.
	 */
	private function make_wordpress_root(): string {
		$root = sys_get_temp_dir() . '/absorber-abspath-' . uniqid( '', true ) . '/';

		if ( ! mkdir( $root . 'wp-admin/includes', 0777, true ) ) {
			throw new RuntimeException( 'Could not write a WordPress root fixture at ' . $root );
		}

		$this->wordpress_roots[] = $root;

		file_put_contents(
			$root . 'wp-admin/includes/plugin.php',
			'<?php' . PHP_EOL
			. '$GLOBALS["absorber_plugin_functions_loads"] = ( $GLOBALS["absorber_plugin_functions_loads"] ?? 0 ) + 1;'
			. PHP_EOL
		);

		return $root;
	}
}
