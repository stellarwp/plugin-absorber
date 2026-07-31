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
so tests never need their own uopz cleanup. `setFunctionReturn()` calls
`markTestSkipped()` when the uopz extension is missing, so a machine without
uopz reports skips rather than confusing failures. Pass `true` as the third
argument to execute a closure in place of the function rather than returning it:

```php
$this->setFunctionReturn( 'wp_safe_redirect', static fn( $location ) => true, true );
```

### A stub closure has no class scope

uopz executes the replacement outside the test object, so neither `$this` nor
`self::` is available inside it. Both are fatal errors, not warnings:

```php
// Fatal: Using $this when not in object context.
// Fatal: Cannot access "self" when no class scope is active.
$this->setFunctionReturn(
	'deactivate_plugins',
	function ( $plugins ) {
		$this->deactivations[] = $plugins;

		throw new TestException( self::HALTED_AT_EXIT );
	},
	true
);
```

Arguments arrive normally, and `use` works — including by reference. So bind a
reference to the property first, resolve any class constant into a local, and
capture both. Writes through the reference land on the property, so the rest of
the test reads `$this->deactivations` as usual:

```php
$deactivations = &$this->deactivations;
$halt_message  = self::HALTED_AT_EXIT;

$this->setFunctionReturn(
	'deactivate_plugins',
	static function ( $plugins ) use ( &$deactivations, $halt_message ) {
		$deactivations[] = $plugins;

		throw new TestException( $halt_message );
	},
	true
);
```

Marking the closure `static` costs nothing and makes the constraint obvious to
the next reader, since `$this` was never usable in the first place.

## Never mock `exit()`

`UopzFunctions::preventExit()` exists, but do not use it. Neutralising `exit`
lets a test keep running past the point where production would have stopped, so
a test that should fail can report as passing and CI will not tell you.

Instead, stub the call immediately before `exit` and throw `TestException` from
it. Execution stops at a point the test controls, and the assertion is about
behaviour rather than about uopz.

Given code under test that ends a request:

```php
class Deactivator {
	public function redirect_back( string $destination ): void {
		wp_safe_redirect( $destination );

		exit;
	}
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

			throw new TestException( 'Halted where production calls exit().' );
		},
		true
	);

	$subject = new Deactivator();
	$halted  = false;

	try {
		$subject->redirect_back( 'https://example.test/wp-admin/plugins.php' );
	} catch ( TestException $e ) {
		$halted = true;

		$this->assertSame( 'Halted where production calls exit().', $e->getMessage() );
	}

	$this->assertTrue( $halted, 'The redirect must halt where production calls exit().' );
	$this->assertSame( [ 'https://example.test/wp-admin/plugins.php' ], $redirects );
}
```

The `$halted` flag is the part that cannot be dropped. Catching the exception
without asserting that it actually arrived turns "the code under test never
redirected at all" into a silent pass — the same class of failure this section
opens by warning about, moved out of `preventExit()` and into the test body.
Matching on the message as well as the class keeps an unrelated `TestException`
thrown earlier from satisfying the catch for the wrong reason.

A bare `expectException( TestException::class )` is fine when the test only
cares that the halt happened and asserts nothing about state afterwards. The
try/catch shape exists so assertions can run after the halt; there is no reason
to use both mechanisms in one test.

`tests/unit/SmokeTest.php` covers this with
`test_a_stub_can_throw_to_halt_a_code_path`, which is the executable proof that
a stub really can throw to stop a code path before it reaches `exit`.
