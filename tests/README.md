# Tests

Codeception on top of a real WordPress, run through [slic](https://github.com/stellarwp/slic).

## Running

```bash
slic use plugin-absorber
slic composer install
slic cc build

slic run unit                     # singlesite
slic run unit --env multisite     # multisite
```

CI runs both envs on PHP 7.4 and 8.5 — the ends of the supported range — against WordPress
`latest` and `nightly`. Four legs, with the `nightly` ones non-blocking.

## Stubbing functions

Use `UopzFunctions` from wp-browser. Do not add a local `WithUopz` trait — this
library deliberately does not maintain one, so there is nothing to keep in sync
with the other plugin repos.

```php
use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;

class SomeTest extends WPTestCase {
	use UopzFunctions;

	public function test_something(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
	}
}
```

Overrides are undone automatically after each test by the trait's `@after` hook,
so tests never need their own uopz cleanup. Pass `true` as the third argument to
execute a closure in place of the function rather than returning it:

```php
$this->setFunctionReturn( 'wp_safe_redirect', static fn( $location ) => true, true );
```

## Never mock `exit()`

`UopzFunctions::preventExit()` exists, but do not use it. Neutralising `exit`
lets a test keep running past the point where production would have stopped, so
a test that should fail can report as passing and CI will not tell you.

Instead, stub the call immediately before `exit` and throw `TestException` from
it. Execution stops at a point the test controls, and the assertion is about
behaviour rather than about uopz.

Given code under test that ends a request:

```php
public function redirect_back( string $destination ): void {
	wp_safe_redirect( $destination );

	exit;
}
```

Assert it like this:

```php
use Nexcess\PluginAbsorber\Tests\Support\TestException;

public function test_redirects_back(): void {
	$redirects = [];

	$this->setFunctionReturn(
		'wp_safe_redirect',
		static function ( $location ) use ( &$redirects ) {
			$redirects[] = $location;

			throw new TestException( 'Avoiding an exit(), which breaks failed test reporting.' );
		},
		true
	);

	$this->expectException( TestException::class );
	$this->expectExceptionMessage( 'Avoiding an exit(), which breaks failed test reporting.' );

	try {
		$subject->redirect_back( 'https://example.test/wp-admin/plugins.php' );
	} catch ( TestException $e ) {
		$this->assertSame( [ 'https://example.test/wp-admin/plugins.php' ], $redirects );

		// Re-throw so the expectException above is satisfied and the method
		// stops before reaching exit.
		throw $e;
	}
}
```

Match on the message as well as the class, so an unrelated `TestException`
thrown earlier cannot make the test pass for the wrong reason.
