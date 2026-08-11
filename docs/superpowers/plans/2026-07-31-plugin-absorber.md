# Plugin Absorber Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `stellarwp/plugin-absorber` 1.0.0 — a dependency-light PHP library that lets a WordPress host plugin safely load formerly-standalone plugins bundled inside it, without re-declaration fatals.

**Architecture:** A static facade (`Config` + `Loader`) over four interface-backed collaborators (`Registrar`, `Notices`, `Conflict\Resolver`, `Activation`), each resolvable from an optional PSR-style container and otherwise instantiated directly. `Loader::boot()` wires two `plugins_loaded` hooks: conflict resolution at priority 1, then the load loop at priority 2. Safety rests on two independent config keys — a load-guard constant checked with `defined()` before `require_once`, and the standalone's plugin basename used for active-detection and deactivation.

**Tech Stack:** PHP 7.4+, WordPress 6.4+, `stellarwp/container-contract` (only production dependency), Codeception + `lucatume/wp-browser` 3.x with the WPLoader module, `uopz` for stubbing unhookable WordPress functions, PHPStan level 5 with `szepeviktor/phpstan-wordpress`, slic as the test runner.

## Global Constraints

Every task's requirements implicitly include this section.

- **Composer package:** `stellarwp/plugin-absorber`. **Repository:** `github.com/stellarwp/plugin-absorber`.
- **Root namespace:** `Nexcess\PluginAbsorber\`. Tests: `Nexcess\PluginAbsorber\Tests\`. Support: `Nexcess\PluginAbsorber\Tests\Support\`.
- **PHP floor:** `>=7.4`. **WordPress floor:** 6.4 (the `wp_admin_notice_markup` filter). Stated in the README only — WordPress is not a Composer dependency, so it is not enforceable in `require`.
- **Class naming:** `Snake_Case` (`Sub_Plugin`, `Conflict_Policy`, `Config_Exception`). Methods fully spelled out and readable. Config keys descriptive and WordPress-centric.
- **Filter names:** `"{$hook_prefix}/plugin_absorber/should_load"` and `"{$hook_prefix}/plugin_absorber/conflict_policy"`.
- **Storage keys:** option `"{$option_prefix}_plugin_absorber_activations"`, option `"{$option_prefix}_plugin_absorber_notices"`. Both are assembled by `Config::get_option_name( string $name )` and nowhere else, alongside `Config::get_hook_name()` for filters. **Amended 2026-08-11:** `{$option_prefix}` is the hook prefix lowercased with hyphens folded to underscores, so `Give-Core` yields the option `give_core_plugin_absorber_notices` while still yielding the filter `Give-Core/plugin_absorber/should_load` — the prefix validator admits `A-Z` and `-`, and a hook-naming value should not reach a storage key verbatim. The two normalisations stay separate: folding case into the hook side would silently rename the host's own filters. **Amended 2026-08-03 (PR 10 review):** the notice queue was specified as a *transient* and is now an option. `set_transient()` returns before touching the database whenever an external object cache is present, so on any Redis or Memcached site the queue would live only in the cache — where a routine `wp_cache_flush()` from a deploy script or a "purge cache" button destroys it. The merge notice is raised exactly once and never re-queued, so losing it means a site owner is never told their plugin was deactivated. On multisite both this and the activation option are network options, because the resolver deactivates network-wide.
- **Production dependencies:** `stellarwp/container-contract` only. `lucatume/di52` is dev-only. No other StellarWP library.
- **PR size cap:** ≤10 files per PR, tests and test infrastructure excluded. No logic-bearing PR exceeds 4 source files.
- **PR body format** — exactly four parts, nothing else. No boilerplate headings, no restating the diff, no checklists:
  ```
  What: one line.

  Usage: the snippet this PR makes possible.

  Why this way: the trade-off taken, and against what.

  Verify: the command, and what is deliberately not covered.
  ```
- **Branching:** stacked. Each branch cuts from the previous branch, and merges to `main` in order. Never open PR N+1 before PR N's branch exists.
- **Commits:** no co-author trailers, ever.
- **Every source file** carries a file-level docblock with `@package Nexcess\PluginAbsorber` and every method a docblock with `@since 1.0.0`. This binds `src/` only. Test classes and test support classes keep the file-level docblock, but their methods do not need `@since` — the test code in this plan's own tasks is written that way deliberately (ruled 2026-07-31).
- **Container tests use the test-support adapter, never di52 directly** (verified 2026-07-31 against `vendor/`). `lucatume\DI52\Container` implements `ArrayAccess` and **PSR's** `Psr\Container\ContainerInterface` — not `StellarWP\ContainerContract\ContainerInterface`; `stellarwp/container-contract` ships an adapter example at `examples/di52/Container.php` precisely because DI52 must be wrapped. Passing `new Container()` to `Config::set_container()` is a `TypeError`. Tests use `Nexcess\PluginAbsorber\Tests\Support\Test_Container`, which wraps a di52 container and implements the contract's four methods (`bind`, `get`, `has`, `singleton`); **every `use lucatume\DI52\Container;` in the task blocks below means `Test_Container`.** `Config::set_container()`'s signature is unchanged — the StellarWP contract stays the public API, per the production-dependency constraint above.
- **`Config` carries no version handling** (ruled 2026-08-11, PR 4 review). `set_version()`/`get_version()` and the `$version` property were removed: nothing in the library reads a host version, and the one scenario that would want it — telling a bundled copy apart from a standalone at a specific release — is the host's problem. This closes spec known-issue F by deletion rather than by use.
- **No test-only seams in `src/`** (ruled 2026-08-11, PR 4 review). Production classes do not carry a `reset()` for the suite's benefit — that is API the library then supports forever. Tests clear static state by reflection instead, through a helper under `tests/_support/`. `Config` is served by `Nexcess\PluginAbsorber\Tests\Support\Config_State::reset()`; **every `Config::reset()` in the task blocks below means `Config_State::reset()`.** `Loader` is served by `Tests\Support\Loader_State::reset()`, which returns each of `Loader`'s static properties to its default by reflection. There is no `Registrar_State`: `Registrar_Interface` declares only `register()` and `all()`, so there is nothing to empty a registrar with, and dropping the memo is enough for the default one. A test that binds a registrar of its own owns that instance and builds a fresh one.

## File Structure

```
plugin-absorber/
├── src/
│   ├── Config.php                        # static config facade: hook prefix, container, name building
│   ├── Loader.php                        # static facade: resolve/register/boot/load loop
│   ├── Sub_Plugin.php                    # value object + every per-sub-plugin predicate
│   ├── Conflict_Policy.php               # three policy string constants
│   ├── Plugin_State.php                  # the only file touching WordPress plugin functions
│   ├── Registrar.php                     # default slug => Sub_Plugin map
│   ├── Activation.php                    # default run-once activation tracking
│   ├── Conflict/
│   │   ├── Contracts/
│   │   │   └── Resolver_Interface.php
│   │   └── Resolver.php                  # default standalone detection/deactivation/redirect
│   ├── Contracts/                        # interfaces whose implementations sit at the src/ root
│   │   ├── Activation_Interface.php
│   │   ├── Plugin_State_Interface.php
│   │   └── Registrar_Interface.php
│   ├── Notices/
│   │   ├── Contracts/
│   │   │   └── Queue_Interface.php
│   │   ├── Queue.php                     # default queue: wording + capability gate
│   │   ├── Renderer.php                  # markup and severity
│   │   └── Store.php                     # the option the queue lives in
│   └── Exceptions/
│       └── Config_Exception.php
├── tests/
│   ├── _bootstrap.php  _support/  _data/  _output/
│   ├── unit.suite.yml                    # WPLoader; singlesite + multisite envs
│   └── unit/                             # mirrors src/
├── .github/workflows/{tests-php.yml,static-analysis.yml}
├── composer.json  phpstan.neon.dist  cspell.json
├── codeception.dist.yml  codeception.slic.yml
├── .env.testing  .env.testing.slic
├── .editorconfig  .gitattributes  .gitignore
├── LICENSE  README.md  CHANGELOG.md
└── docs/                                 # spec + this plan; export-ignored
```

One responsibility per file. `Sub_Plugin` holds every predicate so the collaborators stay thin and the predicates are testable without WordPress hooks. `Loader` holds only static wiring — all behavior lives behind an interface.

---

Tasks 1–6 (repo bootstrap, Codeception harness, first green CI, `Config`, static analysis in CI,
`Conflict_Policy`) shipped in PRs #1–#6 and their sections have been removed. Git history has them if
you need to read one back.

---

## Task 9: `Loader` resolution and registration

**PR 9** · branch `09-loader-resolve` from `08-registrar` · 1 source file

**Files:**
- Create: `src/Loader.php`, `tests/_support/Loader_State.php`, `tests/_support/Spy_Registrar.php`, `tests/unit/LoaderResolveTest.php`
- Modify: `docs/configuration.md`

**Interfaces:**
- Consumes: `Config::get_container()` (Task 4), `Registrar_Interface`/`Registrar` (Task 8), `Sub_Plugin` (Task 7).
- Produces:
  - `Loader::resolve( string $interface, string $default_class ): object` — private; container-or-`new`, memoized
  - `Loader::registrar(): Registrar_Interface`
  - `Loader::register( array $config ): void` — validates, then buffers
  - `Loader::all(): array` — drains the buffer, then reads the registrar
  - `Loader::flush(): void` — private; hands the buffer to the registrar
  - `Tests\Support\Loader_State::reset(): void` — clears the memo and the buffer, for the suite only
  - `Tests\Support\Spy_Registrar` — a `Registrar_Interface` that records what it was handed

  Tasks 10, 12 and 13 each add one accessor alongside their own interface.

**Registration is deferred.** `register()` resolves nothing: it builds a `Sub_Plugin` — which is what
validates the configuration — and buffers it in a private static `$pending`. The buffer drains into
the registrar on the first read, through a private `flush()` that `all()` calls before it asks the
registrar for anything.

The reason is ordering. Resolution reads the container, and a host that registers its sub-plugins
before it calls `Config::set_container()` would pin the default registrar for the whole request and
silently ignore the binding. With registration buffered, the container is a configuration call like
every other one: it may arrive at any point before boot, rather than carrying an unwritten "before
your first `register()`" rule that fails quietly when it is broken.

What this costs: a duplicate slug is now reported at the first read — boot — instead of at the second
`register()` call. Invalid configuration still throws from `register()`, at the call the host can see
in its own stack trace, because building the `Sub_Plugin` is what rejects it. The registrar remains
the single source of truth for duplicate-slug detection and for ordering; the buffer is a pre-store
that restates neither rule in a second dialect.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 08-registrar && git checkout -b 09-loader-resolve
```

- [ ] **Step 2: Write `tests/_support/Spy_Registrar.php` and `tests/_support/Loader_State.php`**

`Loader` is the second static facade in the library, and like `Config` it needs clearing between
tests without carrying a public `reset()` for the suite's benefit. `Loader_State` lands here, in the
task that first needs it, modelled on `Config_State`: the properties are walked by reflection and an
unknown one is a `LogicException` rather than a silent leak, so state added to `Loader` later fails
loudly instead of surviving into the next test.

`Spy_Registrar` is a named class rather than an anonymous one repeated per test: a test that reads
`$spy->register_calls` off a value typed as `Registrar_Interface` is reading a property the interface
does not declare, and PHPStan at level 9 rightly rejects it. The call counter is what catches a
buffer that forgets to empty itself — the slug map alone cannot see the same slug registered twice.

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * A registrar that records what was done to it, for tests about who the Loader talks to.
 *
 * @since 1.0.0
 */
class Spy_Registrar implements Registrar_Interface {
	/**
	 * Everything handed to register(), keyed by slug.
	 *
	 * @var array<string,Sub_Plugin>
	 */
	public $sub_plugins = [];

	/**
	 * How many times register() was called.
	 *
	 * @var int
	 */
	public $register_calls = 0;

	public function register( Sub_Plugin $sub_plugin ): void {
		++$this->register_calls;

		$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
	}

	/**
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}
}
```

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use LogicException;
use Nexcess\PluginAbsorber\Loader;
use ReflectionClass;
use ReflectionProperty;

/**
 * Restores `Loader`'s static state between tests.
 *
 * `Loader` has no public way to clear itself, and deliberately so: a reset method would be API the
 * library then has to support forever for the sake of its own test suite, and a host that reached
 * for it mid-request would discard the registrations the load loop is about to read. Reflection
 * keeps that seam on this side of the fence.
 *
 * Dropping the memo is enough for the default collaborators, which are built per resolve. A
 * collaborator bound into a container as a singleton comes back populated on the next resolve, so
 * a test that binds one must build a fresh instance rather than expect this to empty it.
 */
class Loader_State {
	/**
	 * The value each of `Loader`'s static properties starts life with.
	 *
	 * Spelled out rather than read from `ReflectionClass::getDefaultProperties()`, which reports a
	 * static property's *current* value on PHP below 8.3 — a reset built on it is a silent no-op on
	 * the 7.4 leg.
	 *
	 * @var array<string,mixed>
	 */
	protected const DEFAULTS = [
		'resolved' => [],
		'pending'  => [],
	];

	/**
	 * Return every static property of `Loader` to its default.
	 *
	 * @throws LogicException When `Loader` has grown a static property this helper does not know
	 *                        about, rather than leaving it to leak between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$reflection = new ReflectionClass( Loader::class );

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$name = $property->getName();

			if ( ! array_key_exists( $name, self::DEFAULTS ) ) {
				throw new LogicException(
					sprintf( 'Loader::$%s has no default in %s. Add one.', $name, self::class )
				);
			}

			$property->setAccessible( true );
			$property->setValue( null, self::DEFAULTS[ $name ] );
		}
	}
}
```

There is no `Registrar_State` helper. Emptying a registrar by reflection would only ever serve the
memoized default instance, and `Registrar_Interface` declares `register()` and `all()` and nothing
else — a container-bound singleton registrar cannot be emptied at all. A test that binds one builds
a fresh instance instead, which is the honest shape: the fake belongs to the test that made it.

- [ ] **Step 3: Write the failing test**

`tests/unit/LoaderResolveTest.php`, a `WPTestCase` that calls `Loader_State::reset()` and
`Config_State::reset()` in both `setUp()` and `tearDown()` — a memo stranded by a failed assertion
would otherwise be read by the next test as a real resolve. Container tests use
`Tests\Support\Test_Container`, never `lucatume\DI52\Container`, which implements PSR-11's
`ContainerInterface` rather than StellarWP's. A private `sub_plugin_config( string $slug )` builds the
raw array a host writes — the shared `WithSubPlugins` trait builds `Sub_Plugin` objects, and building
that object is the part of registration under test here — with a `_FIXTURE`-suffixed guard constant
nothing ever defines, since a `define()` lasts for the whole PHP process.

Resolution:

- `test_it_falls_back_to_the_default_registrar_without_a_container`
- `test_it_memoizes_the_resolved_collaborator`
- `test_it_resolves_a_bound_registrar_from_the_container` — data provider `container_binding_methods`
  (`singleton`, `bind`); the memo is what makes even a `bind()`, which the container rebuilds per
  call, resolve exactly once
- `test_it_ignores_a_container_with_no_binding` — asserts `has()` is false first, since DI52 reports
  true for any existing *class* name and this binding must stay an interface
- `test_it_rejects_a_binding_that_does_not_implement_the_interface` — data provider
  `unusable_bindings` (wrong class, the class name instead of an instance, `null`); the message names
  what came back, and the bad instance must not have been memoized
- `test_it_reports_a_container_that_throws_as_a_configuration_error` — the original stays reachable
  through the previous chain, walked rather than read one level deep because a container is entitled
  to wrap what a factory threw
- `test_a_container_set_after_the_first_resolve_does_not_change_the_memo`

Registration:

- `test_register_builds_a_sub_plugin_and_stores_it`
- `test_register_rejects_an_invalid_config`
- `test_register_resolves_nothing_until_the_first_read`
- `test_a_container_set_after_register_takes_effect`
- `test_register_delegates_to_a_bound_registrar`
- `test_reading_twice_does_not_register_twice`
- `test_a_duplicate_slug_is_refused_at_the_first_read`
- `test_registrations_survive_a_container_that_throws`
- `test_all_is_empty_before_anything_is_registered`
- `test_the_state_helper_clears_buffered_registrations`

The two that pin the deferral down:

```php
/**
 * Registering must not resolve anything, or the first register() call would pin the default
 * registrar for the whole request.
 */
public function test_register_resolves_nothing_until_the_first_read(): void {
	$builds    = 0;
	$container = new Test_Container();
	$container->singleton(
		Registrar_Interface::class,
		static function () use ( &$builds ): Registrar_Interface {
			++$builds;

			return new Spy_Registrar();
		}
	);
	Config::set_container( $container );

	Loader::register( $this->sub_plugin_config( 'give-recurring' ) );

	$this->assertSame( 0, $builds, 'register() must not reach the container.' );

	Loader::all();

	$this->assertSame( 1, $builds, 'The first read is what resolves the registrar.' );
}

/**
 * Deferring registration moves the duplicate-slug report from the second register() call to the
 * first read. It still names both bundled files, which is what the host needs to find them.
 */
public function test_a_duplicate_slug_is_refused_at_the_first_read(): void {
	Loader::register( $this->sub_plugin_config( 'give-recurring' ) );
	Loader::register(
		[
			'slug'                   => 'give-recurring',
			'bundled_plugin_file'    => '/tmp/other/other.php',
			'plugin_loaded_constant' => 'OTHER_VERSION_FIXTURE',
		]
	);

	try {
		Loader::all();
		$this->fail( 'Expected a Config_Exception.' );
	} catch ( Config_Exception $exception ) {
		$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
		$this->assertStringContainsString( '/tmp/other/other.php', $exception->getMessage() );
	}
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Loader" not found`.

- [ ] **Step 5: Write `src/Loader.php`**

Only resolution and registration in this PR. `boot()` and the load loop land in Task 11.

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Throwable;

/**
 * Static facade: collaborator resolution, registration, hook wiring, and the load loop.
 *
 * @since 1.0.0
 */
class Loader {
	/**
	 * Resolved collaborators, memoized by interface name.
	 *
	 * @var array<string,object>
	 */
	private static $resolved = [];

	/**
	 * Sub-plugins registered but not yet handed to the registrar.
	 *
	 * @var Sub_Plugin[]
	 */
	private static $pending = [];

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable instance.
	 *
	 * @return Registrar_Interface
	 */
	public static function registrar(): Registrar_Interface {
		return self::resolve( Registrar_Interface::class, Registrar::class );
	}

	/**
	 * Register one bundled sub-plugin. Call once per sub-plugin, before boot().
	 *
	 * The sub-plugin is buffered rather than handed straight to the registrar, so that registering
	 * resolves nothing. Resolution needs the container, and a host that registers before it calls
	 * Config::set_container() would otherwise pin the default registrar and silently ignore the
	 * binding. Buffering is what lets the container arrive at any point before boot, like every
	 * other configuration call.
	 *
	 * The configuration is still validated here: building the Sub_Plugin is what rejects it, and
	 * that happens at the call the host can see in its own stack trace.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @throws Config_Exception When the configuration is unusable.
	 *
	 * @return void
	 */
	public static function register( array $config ): void {
		self::$pending[] = new Sub_Plugin( $config );
	}

	/**
	 * Every registered sub-plugin, keyed by slug, in registration order.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable registrar, or two
	 *                          sub-plugins were registered under one slug.
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public static function all(): array {
		self::flush();

		return self::registrar()->all();
	}

	/**
	 * Hand every buffered registration to the registrar.
	 *
	 * The registrar stays the single source of truth: the buffer is a pre-store that needs no
	 * container, and duplicate-slug detection and ordering remain the registrar's alone rather
	 * than being restated here in a second dialect.
	 *
	 * The buffer is emptied before the loop, so a second read cannot re-register what the
	 * registrar already holds and trip its duplicate-slug guard. It is emptied *after* the
	 * registrar resolves, so a container binding that throws leaves the registrations buffered
	 * for the next read rather than dropping them on the floor.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When the container cannot produce a usable registrar, or two
	 *                          sub-plugins were registered under one slug.
	 *
	 * @return void
	 */
	private static function flush(): void {
		if ( self::$pending === [] ) {
			return;
		}

		$registrar = self::registrar();
		$pending   = self::$pending;

		self::$pending = [];

		foreach ( $pending as $sub_plugin ) {
			$registrar->register( $sub_plugin );
		}
	}

	/**
	 * Resolve an interface from the container when bound, else construct the default.
	 *
	 * The container is never required — with none set, every collaborator is a plain `new`, so
	 * every default class must be constructible with no arguments. Resolution is memoized, and
	 * nothing resolves until the first read, which is boot: that is what lets a host set its
	 * container at any point beforehand. Swapping a collaborator after that would be the worse
	 * behaviour, since anything already holding the old instance would keep it.
	 *
	 * @since 1.0.0
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $interface     Interface to resolve.
	 * @param class-string<T> $default_class Concrete class to build when nothing is bound.
	 *
	 * @throws Config_Exception When the container throws while building the binding, or returns
	 *                          something that does not implement the interface it was asked for.
	 *
	 * @return T
	 */
	private static function resolve( string $interface, string $default_class ): object {
		if ( isset( self::$resolved[ $interface ] ) ) {
			/** @var T $memoized */
			$memoized = self::$resolved[ $interface ];

			return $memoized;
		}

		$container = Config::get_container();

		if ( $container !== null && $container->has( $interface ) ) {
			// has() true only promises the binding exists, not that it can be built: a host factory
			// closure is free to throw, and a container asked for a class with an unsatisfiable
			// dependency throws its own exception type. Uncaught, either one leaves the host's
			// plugins_loaded with a fatal from a vendor namespace that names neither this library
			// nor the binding at fault.
			try {
				$instance = $container->get( $interface );
			} catch ( Throwable $thrown ) {
				throw new Config_Exception(
					sprintf(
						'The container failed to build the binding for %s: %s',
						$interface,
						$thrown->getMessage()
					),
					0,
					$thrown
				);
			}

			// Checked before it is memoized. Without this the bad instance is cached, and every
			// accessor throws a TypeError blaming this library rather than the binding.
			if ( ! $instance instanceof $interface ) {
				throw new Config_Exception(
					sprintf(
						'The container binding for %s must implement it. Got %s.',
						$interface,
						is_object( $instance ) ? get_class( $instance ) : gettype( $instance )
					)
				);
			}

			self::$resolved[ $interface ] = $instance;

			return $instance;
		}

		self::$resolved[ $interface ] = new $default_class();

		return self::$resolved[ $interface ];
	}
}
```

> **Design notes.**
>
> *Registration is deferred on purpose.* Handing the `Sub_Plugin` straight to
> `self::registrar()->register()` is shorter and was the first sketch, but it makes the first
> `register()` call the moment the container is read, and a host that sets its container one line
> later gets the default registrar with no error to explain why its binding did nothing. The buffer
> is what makes `Config::set_container()` order-independent, at the price of moving the duplicate-slug
> report to the first read. That is the right trade: a duplicate slug is a mistake caught the first
> time the code runs either way, and it is reported with both bundled file paths wherever it fires,
> while a silently ignored container binding is a mistake that looks like it worked.
>
> *The facade has no `reset()`.* Nothing in production ever needs to un-resolve a collaborator or
> discard a registration — the memo is built once per request and dies with it — so the only caller
> would be the suite, and a public static method is a promise to every host that reads the class.
> `Tests\Support\Loader_State::reset()` does the job by reflection from `tests/_support/`, clearing
> the memo and the buffer together.
>
> *Nothing reaches into a registrar to empty it.* `Registrar_Interface` declares `register()` and
> `all()` only, so a container-bound singleton registrar has no way to be emptied and reflection into
> the shipped `Registrar` would not touch it. A test that binds a registrar of its own owns that
> fake and builds a fresh one per test — test-side code, which is exactly where this seam belongs.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 17 test methods, 20 cases with the two data providers expanded.

- [ ] **Step 7: Confirm static analysis is still clean**

Run: `composer test:analysis`
Expected: `[OK] No errors`.

- [ ] **Step 8: Append to `docs/configuration.md`**

The container documentation goes in `docs/configuration.md`, not the README: the human docs are split
out of the README and it is not to grow back.

```markdown
## Rebinding a collaborator

Every collaborator is interface-backed. With a container set, bind any of them to override the
library globally; with no container, the defaults are used and nothing is required.

```php
$container->singleton( Registrar_Interface::class, My_Registrar::class );
Config::set_container( $container );
```

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | Holds the registered sub-plugins. |
| `Notices\Contracts\Queue_Interface` | `Notices\Queue` | Notice queue and the activation-error rewrite. |
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | Standalone detection, deactivation, redirect. |
| `Contracts\Activation_Interface` | `Activation` | Run-once activation tracking. |

`Config::set_container()` may be called at any point before `Loader::boot()` — before or after your
`Loader::register()` calls. Nothing is resolved until the first read, so a registration made before
the container was set still reaches the bound registrar. After boot, a collaborator is fixed for the
request: swapping one then would strand whatever already holds the old instance.

The container is **not** used to wire hooks — those stay plain static callbacks, so the container
stays genuinely optional.
```

- [ ] **Step 9: Commit, push, open the PR**

```bash
git add src/Loader.php tests/_support/Loader_State.php tests/_support/Spy_Registrar.php tests/unit/LoaderResolveTest.php docs/configuration.md
git commit -m "Add Loader resolution and registration"
git push -u origin 09-loader-resolve
gh pr create --base 08-registrar --title "Loader resolution and registration" --body 'What: `Loader::resolve()`, the `registrar()` accessor, `register()`, and `all()`.

Usage:

    Loader::register( [ "slug" => "give-recurring", ... ] );
    Loader::all();   // [ "give-recurring" => Sub_Plugin ]

    // Optional, and in any order relative to register().
    $container->singleton( Registrar_Interface::class, My_Registrar::class );
    Config::set_container( $container );

Why this way: one generic `resolve( $interface, $default_class )` rather than four bespoke accessors
with their own fallback logic, so adding a collaborator is one line. `register()` validates the
config by building the `Sub_Plugin` and then buffers it, so registration resolves nothing: the
container may be set at any point before boot instead of before the first `register()`. The cost is
that a duplicate slug is reported at the first read rather than at the second `register()` — against
a container binding that would otherwise be ignored with no error at all.

The facade carries no `reset()`: nothing in production un-resolves a collaborator, so it would be a
public promise made for the test suite alone. `Tests\Support\Loader_State` clears the memo and the
buffer by reflection instead.

Verify: `slic run unit` — 17 test methods covering both the bound and unbound paths, a container
that throws, a binding of the wrong type, and the registration order cases. Not covered here:
`boot()` and the load loop, which land in the next PR.'
```

---

## Task 11: `Loader` boot and load path

**PR 11** · branch `11-loader-load-path` from `10-notices-queue` · 2 source files

**Files:**
- Modify: `src/Loader.php`, `tests/_support/Loader_State.php` (the new `$booted` property needs a default), `README.md`
- Create: `tests/unit/LoaderLoadTest.php`, `tests/unit/LoaderBootTest.php`

**Interfaces:**
- Consumes: `Sub_Plugin` predicates (Task 7), `Loader::notices()` (Task 10), `Config::get_hook_name()` (Task 4).
- Produces:
  - `Loader::boot(): void` — idempotent
  - `Loader::load_all(): void`
  - `Loader::render_notices(): void`
  - `Loader::load( Sub_Plugin ): void` — private
  - the `"{$prefix}/plugin_absorber/should_load"` filter, args `(bool $should_load, Sub_Plugin $sub_plugin)`

  Task 12 adds the `plugins_loaded` @1 hook to `boot()`; Task 13 adds the activation call to `load()`.

**Design note:** `boot()` wires only the @2 load hook and `all_admin_notices` in this PR. The @1 conflict-resolution hook arrives in Task 12 with the resolver it delegates to — wiring a trampoline to a collaborator that does not exist yet would not run.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 10-notices-queue && git checkout -b 11-loader-load-path
```

- [ ] **Step 2: Write the failing load-path test**

Each test writes its own fixture file. `require_once` caches by resolved path for the whole PHP process, so a shared fixture would make the second test in the run silently pass.

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;

/**
 * @since 1.0.0
 */
class LoaderLoadTest extends WPTestCase {
	/**
	 * @var array<int,string>
	 */
	private $fixtures = [];

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
		$GLOBALS['absorber_loads'] = 0;
	}

	public function tearDown(): void {
		foreach ( $this->fixtures as $fixture ) {
			if ( file_exists( $fixture ) ) {
				unlink( $fixture );
			}
		}
		$this->fixtures = [];

		unset( $GLOBALS['absorber_loads'] );
		delete_transient( 'give_plugin_absorber_notices' );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Write a throwaway bundled plugin that counts its own loads and defines its guard constant.
	 *
	 * A unique path per test is required: require_once caches by resolved path for the lifetime of
	 * the PHP process, so a shared fixture would make later tests pass without loading anything.
	 *
	 * @param string $constant Guard constant to define.
	 */
	private function make_fixture( string $constant ): string {
		$path = sys_get_temp_dir() . '/absorber-' . uniqid( '', true ) . '.php';

		file_put_contents(
			$path,
			'<?php' . PHP_EOL
			. '$GLOBALS["absorber_loads"] = ( $GLOBALS["absorber_loads"] ?? 0 ) + 1;' . PHP_EOL
			. 'if ( ! defined( "' . $constant . '" ) ) { define( "' . $constant . '", "1.0.0" ); }' . PHP_EOL
		);

		$this->fixtures[] = $path;

		return $path;
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function register( array $overrides = [], ?string $constant = null ): string {
		$constant = $constant ?? 'ABSORBER_FIXTURE_' . strtoupper( bin2hex( random_bytes( 4 ) ) );
		$path     = $this->make_fixture( $constant );

		Loader::register(
			array_merge(
				[
					'slug'                   => 'give-recurring',
					'bundled_plugin_file'    => $path,
					'plugin_loaded_constant' => $constant,
				],
				$overrides
			)
		);

		return $constant;
	}

	public function test_it_requires_the_bundled_file(): void {
		$constant = $this->register();

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
		$this->assertTrue( defined( $constant ) );
	}

	public function test_it_requires_the_bundled_file_exactly_once(): void {
		$this->register();

		Loader::load_all();
		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->register( [ 'enabled' => false ] );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	public function test_it_skips_when_dependencies_are_unmet_and_queues_a_notice(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
		$this->assertArrayHasKey(
			'give-recurring:dependency',
			get_transient( 'give_plugin_absorber_notices' )
		);
	}

	public function test_it_skips_when_the_guard_constant_is_already_defined(): void {
		define( 'ABSORBER_ALREADY_LOADED_GUARD', '1.0.0' );

		$this->register( [], 'ABSORBER_ALREADY_LOADED_GUARD' );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'], 'A defined constant means the code is already present.' );
	}

	public function test_it_skips_when_the_bundled_file_is_missing(): void {
		Loader::register(
			[
				'slug'                   => 'give-recurring',
				'bundled_plugin_file'    => '/tmp/absorber-does-not-exist-' . uniqid( '', true ) . '.php',
				'plugin_loaded_constant' => 'ABSORBER_MISSING_FILE_GUARD',
			]
		);

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	public function test_the_should_load_filter_can_veto_the_load(): void {
		$this->register();

		add_filter( 'give/plugin_absorber/should_load', '__return_false' );

		Loader::load_all();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	public function test_the_should_load_filter_receives_the_sub_plugin(): void {
		$this->register();

		$received = null;
		add_filter(
			'give/plugin_absorber/should_load',
			static function ( $should_load, $sub_plugin ) use ( &$received ) {
				$received = $sub_plugin;

				return $should_load;
			},
			10,
			2
		);

		Loader::load_all();

		$this->assertInstanceOf( \Nexcess\PluginAbsorber\Sub_Plugin::class, $received );
		$this->assertSame( 'give-recurring', $received->get_slug() );
	}

	public function test_it_loads_every_registered_sub_plugin(): void {
		$this->register( [ 'slug' => 'give-recurring' ] );
		$this->register( [ 'slug' => 'give-fee-recovery' ] );

		Loader::load_all();

		$this->assertSame( 2, $GLOBALS['absorber_loads'] );
	}
}
```

- [ ] **Step 3: Write the failing boot test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;

/**
 * @since 1.0.0
 */
class LoaderBootTest extends WPTestCase {
	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
	}

	public function tearDown(): void {
		remove_all_actions( 'plugins_loaded' );
		remove_all_actions( 'all_admin_notices' );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	public function test_it_wires_the_load_hook_at_priority_two(): void {
		Loader::boot();

		$this->assertSame(
			2,
			has_action( 'plugins_loaded', [ Loader::class, 'load_all' ] )
		);
	}

	public function test_booting_twice_wires_the_hook_only_once(): void {
		Loader::boot();
		Loader::boot();

		$callbacks = $GLOBALS['wp_filter']['plugins_loaded']->callbacks[2] ?? [];

		$this->assertCount( 1, $callbacks, 'boot() must be idempotent.' );
	}

	public function test_it_wires_the_admin_notices_hook_in_the_admin(): void {
		set_current_screen( 'dashboard' );

		Loader::boot();

		$this->assertNotFalse( has_action( 'all_admin_notices', [ Loader::class, 'render_notices' ] ) );

		set_current_screen( 'front' );
	}
}
```

- [ ] **Step 4: Run both to verify they fail**

Run: `slic run unit`
Expected: FAIL — `Call to undefined method Nexcess\PluginAbsorber\Loader::boot()`.

- [ ] **Step 5: Add boot and the load path to `src/Loader.php`**

Add the `$booted` property beside `$resolved`:

```php
	/**
	 * @var bool
	 */
	private static $booted = false;
```

Append these methods:

```php
	/**
	 * Wire the WordPress hooks. Idempotent — safe to call from more than one code path.
	 *
	 * Hooks are plain static trampolines rather than container callbacks, which is what keeps the
	 * container optional. Each trampoline delegates to the resolved collaborator, so rebinding
	 * still takes effect.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'plugins_loaded', [ self::class, 'load_all' ], 2 );

		if ( is_admin() ) {
			// all_admin_notices, not admin_notices. WordPress dispatches admin_notices,
			// network_admin_notices and user_admin_notices as mutually exclusive branches, so a
			// superadmin working in the network admin -- exactly where a network-wide
			// deactivation gets noticed -- would never see the queue rendered.
			add_action( 'all_admin_notices', [ self::class, 'render_notices' ] );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_all(): void {
		foreach ( self::all() as $sub_plugin ) {
			// Registrar_Interface::all() only declares `array`. A host binding its own registrar
			// that returns anything else would otherwise fatal inside plugins_loaded on the first
			// predicate call -- the exact failure this library exists to prevent.
			if ( ! $sub_plugin instanceof Sub_Plugin ) {
				continue;
			}

			self::load( $sub_plugin );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		self::notices()->render();
	}

	/**
	 * Load one sub-plugin, in the order the checks are cheapest and most decisive.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to load.
	 *
	 * @return void
	 */
	private static function load( Sub_Plugin $sub_plugin ): void {
		if ( ! $sub_plugin->is_enabled() ) {
			return;
		}

		if ( ! $sub_plugin->are_dependencies_met() ) {
			self::notices()->queue_dependency_notice( $sub_plugin );

			return;
		}

		// The constant is defined => the code is already present, from either copy. Loading the
		// bundled file now would be a re-declaration fatal.
		if ( $sub_plugin->is_already_loaded() ) {
			return;
		}

		if ( ! file_exists( $sub_plugin->get_bundled_plugin_file() ) ) {
			return;
		}

		$should_load = apply_filters(
			Config::get_hook_name( 'should_load' ),
			true,
			$sub_plugin
		);

		if ( ! $should_load ) {
			return;
		}

		require_once $sub_plugin->get_bundled_plugin_file();
	}
```

- [ ] **Step 6: Teach `tests/_support/Loader_State.php` about the boot flag**

`Loader_State::reset()` walks `Loader`'s static properties and refuses one it has no default for,
so until `$booted` is listed every test that resets throws a `LogicException` naming it. That is
the helper doing its job: a boot flag left standing would wire the hooks once and then let every
later test's `boot()` no-op.

```php
	protected const DEFAULTS = [
		'resolved' => [],
		'booted'   => false,
	];
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 9 load tests + 3 boot tests.

- [ ] **Step 8: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 9: Append to the README**

```markdown
### Bootstrap

```php
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );

    Loader::register( [
        'slug'                   => 'give-recurring',
        'bundled_plugin_file'    => GIVE_PLUGIN_DIR . 'subs/give-recurring/give-recurring.php',
        'plugin_loaded_constant' => 'GIVE_RECURRING_VERSION',
    ] );

    Loader::boot();
}, 0 ); // priority 0 — before the absorber's own @1 and @2 hooks fire
```

The bundled file must define its guard constant inside a `defined()` check:

```php
if ( ! defined( 'GIVE_RECURRING_VERSION' ) ) {
    define( 'GIVE_RECURRING_VERSION', '2.4.0' );
}
```

### Load gate

Applied immediately before `require_once`:

```php
add_filter( 'give/plugin_absorber/should_load', function ( $should_load, $sub_plugin ) {
    return $sub_plugin->get_slug() === 'give-recurring' ? false : $should_load;
}, 10, 2 );
```

A sub-plugin is skipped when it is disabled, its dependencies are unmet, its guard constant is
already defined, its bundled file is missing, or this filter returns false.
```

- [ ] **Step 10: Commit, push, open the PR**

```bash
git add src/Loader.php tests/_support/Loader_State.php tests/unit/LoaderLoadTest.php tests/unit/LoaderBootTest.php README.md
git commit -m "Add Loader boot and the load path"
git push -u origin 11-loader-load-path
gh pr create --base 10-notices-queue --title "Loader boot and load path" --body 'What: `boot()`, `load_all()`, the five-gate load path, and the `should_load` filter.

Usage:

    Loader::register( [ ... ] );
    Loader::boot();   // wires plugins_loaded @2 and all_admin_notices

    add_filter( "give/plugin_absorber/should_load", function ( $should_load, $sub_plugin ) {
        return $should_load;
    }, 10, 2 );

Why this way: gate order is deliberate — `is_enabled()` and `are_dependencies_met()` are cheap
config checks, `is_already_loaded()` is the one that actually prevents the fatal, and the filter
runs last so a host override cannot accidentally re-introduce a re-declaration. The
already-loaded check sits before `file_exists()` because it is both cheaper and more important.

`boot()` wires only the @2 hook here; the @1 conflict-resolution hook lands with the resolver it
delegates to, since a trampoline pointing at a collaborator that does not exist yet would not run.

Verify: `slic run unit` — 12 tests. Each writes its own fixture file, because `require_once` caches
by resolved path for the whole PHP process and a shared fixture would make later tests pass without
loading anything.'
```

---

## Task 12: `Conflict\Resolver`

**PR 12** · branch `12-conflict-resolver` from `11-loader-load-path` · 4 source files

**Files:**
- Create: `src/Conflict/Contracts/Resolver_Interface.php`, `src/Conflict/Resolver.php`, `tests/unit/Conflict/ResolverTest.php`
- Modify: `src/Loader.php` (add `resolver()` and the @1 hook), `README.md`

**Interfaces:**
- Consumes: `Loader::all()` (Task 9), `Loader::notices()` (Task 10), `Sub_Plugin::is_standalone_plugin_active()` / `is_standalone_plugin_network_active()` / `get_conflict_policy()` (Task 7), `Conflict_Policy::*` (Task 6).
- Produces:
  - `Conflict\Contracts\Resolver_Interface` with `resolve_all(): void` — a folder-scoped concern owns its own contract, so this lives in `src/Conflict/Contracts/`, not in the top-level `src/Contracts/` and not beside `Resolver`. Matches `Notices\Contracts\Queue_Interface` from Task 10.
  - `Conflict\Resolver::redirect_destination( $referrer )` — `protected`, returns `string|false`
  - `Loader::resolver(): Resolver_Interface`
  - `Loader::run_conflict_resolution(): void`

- [ ] **Step 1: Cut the branch**

```bash
git checkout 11-loader-load-path && git checkout -b 12-conflict-resolver
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict\Resolver;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class ResolverTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * Message carried by the exception that stands in for exit().
	 *
	 * Asserted on rather than merely caught, so an unrelated TestException
	 * cannot make one of these tests pass for the wrong reason.
	 */
	private const HALTED_AT_EXIT = 'Resolver halted where production calls exit().';

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private $deactivations = [];

	/**
	 * @var array<int,string>
	 */
	private $redirects = [];

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->deactivations = [];
		$this->redirects     = [];

		$this->setFunctionReturn(
			'deactivate_plugins',
			function ( $plugins, $silent = false, $network_wide = null ) {
				$this->deactivations[] = [
					'plugins'      => $plugins,
					'silent'       => $silent,
					'network_wide' => $network_wide,
				];
			},
			true
		);

		// Throwing here stops the resolver exactly where production calls exit,
		// without mocking exit itself. See tests/README.md.
		$this->setFunctionReturn(
			'wp_safe_redirect',
			function ( $location ) {
				$this->redirects[] = $location;

				throw new TestException( self::HALTED_AT_EXIT );
			},
			true
		);
	}

	public function tearDown(): void {
		delete_transient( 'give_plugin_absorber_notices' );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function register( array $overrides = [] ): void {
		Loader::register(
			array_merge(
				[
					'slug'                       => 'give-recurring',
					'bundled_plugin_file'        => '/tmp/give-recurring.php',
					'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION_RESOLVER',
					'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
				],
				$overrides
			)
		);
	}

	private function standalone_is( bool $active, bool $network_active = false ): void {
		$this->setFunctionReturn( 'is_plugin_active', $active );
		$this->setFunctionReturn( 'is_plugin_active_for_network', $network_active );
	}

	/**
	 * Runs the resolver, absorbing the TestException that stands in for exit().
	 *
	 * Paths that redirect halt inside wp_safe_redirect(); paths that do not run
	 * to completion. Either way the assertions afterwards see the same state
	 * production would have left behind.
	 *
	 * @return void
	 */
	private function resolve(): void {
		try {
			( new Resolver() )->resolve_all();
		} catch ( TestException $e ) {
			$this->assertSame( self::HALTED_AT_EXIT, $e->getMessage() );
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function queued_notices(): array {
		$queue = get_transient( 'give_plugin_absorber_notices' );

		return is_array( $queue ) ? $queue : [];
	}

	public function test_the_loader_resolves_the_default_resolver(): void {
		$this->assertInstanceOf( Resolver::class, Loader::resolver() );
	}

	public function test_deactivate_deactivates_notifies_and_redirects(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->resolve();

		$this->assertCount( 1, $this->deactivations );
		$this->assertSame( 'give-recurring/give-recurring.php', $this->deactivations[0]['plugins'] );
		$this->assertArrayHasKey( 'give-recurring:merge', $this->queued_notices() );
		$this->assertCount( 1, $this->redirects );
	}

	public function test_deactivate_is_the_default_policy(): void {
		$this->standalone_is( true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->resolve();

		$this->assertCount( 1, $this->deactivations );
	}

	public function test_it_passes_the_network_flag_for_a_network_active_standalone(): void {
		$this->standalone_is( false, true );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->resolve();

		$this->assertTrue(
			$this->deactivations[0]['network_wide'],
			'Without $network_wide, deactivate_plugins() no-ops on a network-activated plugin and the redirect loops forever.'
		);
	}

	public function test_it_omits_the_network_flag_for_a_normally_active_standalone(): void {
		$this->standalone_is( true, false );
		$this->register();
		$this->setFunctionReturn( 'wp_get_referer', false );

		$this->resolve();

		$this->assertFalse( $this->deactivations[0]['network_wide'] );
	}

	public function test_defer_does_nothing_at_all(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEFER ] );

		$this->resolve();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame( [], $this->redirects );
		$this->assertSame( [], $this->queued_notices() );
	}

	public function test_notice_only_notifies_without_deactivating(): void {
		$this->standalone_is( true );
		$this->register( [ 'conflict_policy' => Conflict_Policy::NOTICE_ONLY ] );

		$this->resolve();

		$this->assertSame( [], $this->deactivations );
		$this->assertSame( [], $this->redirects );
		$this->assertArrayHasKey( 'give-recurring:conflict', $this->queued_notices() );
	}

	public function test_a_callable_policy_selects_the_branch(): void {
		$this->standalone_is( true );
		$this->register(
			[
				'conflict_policy' => static function ( Sub_Plugin $sub_plugin ) {
					return $sub_plugin->get_slug() === 'give-recurring'
						? Conflict_Policy::DEFER
						: Conflict_Policy::DEACTIVATE;
				},
			]
		);

		$this->resolve();

		$this->assertSame( [], $this->deactivations, 'The callable chose DEFER for this slug.' );
	}

	public function test_it_skips_a_disabled_sub_plugin(): void {
		$this->standalone_is( true );
		$this->register( [ 'enabled' => false ] );

		$this->resolve();

		$this->assertSame( [], $this->deactivations );
	}

	public function test_it_skips_when_the_standalone_is_not_active(): void {
		$this->standalone_is( false, false );
		$this->register();

		$this->resolve();

		$this->assertSame( [], $this->deactivations );
	}

	public function test_it_skips_a_sub_plugin_with_no_standalone(): void {
		$this->standalone_is( true );
		Loader::register(
			[
				'slug'                   => 'give-fee-recovery',
				'bundled_plugin_file'    => '/tmp/give-fee-recovery.php',
				'plugin_loaded_constant' => 'GIVE_FEE_RECOVERY_VERSION_RESOLVER',
			]
		);

		$this->resolve();

		$this->assertSame( [], $this->deactivations );
	}

	/**
	 * Exposes the protected redirect logic so it can be asserted directly.
	 *
	 * Defined once and reused — the four referrer cases differ only in their input.
	 */
	private function redirect_resolver(): Resolver {
		return new class() extends Resolver {
			/**
			 * @param string|false $referrer Referrer under test.
			 *
			 * @return string|false
			 */
			public function destination_for( $referrer ) {
				return $this->redirect_destination( $referrer );
			}
		};
	}

	public function test_it_redirects_to_the_plugins_page_without_a_referrer(): void {
		$this->assertSame( admin_url( 'plugins.php' ), $this->redirect_resolver()->destination_for( false ) );
	}

	public function test_it_redirects_to_the_plugins_page_from_an_update_screen(): void {
		$resolver = $this->redirect_resolver();

		$this->assertSame( admin_url( 'plugins.php' ), $resolver->destination_for( admin_url( 'update.php?action=x' ) ) );
		$this->assertSame( admin_url( 'plugins.php' ), $resolver->destination_for( admin_url( 'update-core.php' ) ) );
	}

	public function test_it_does_not_redirect_during_an_inline_update_on_the_plugins_page(): void {
		$this->assertFalse(
			$this->redirect_resolver()->destination_for( admin_url( 'plugins.php' ) ),
			'Redirecting here would interrupt an inline update.'
		);
	}

	public function test_it_returns_any_other_referrer_unchanged(): void {
		$this->assertSame(
			admin_url( 'options-general.php' ),
			$this->redirect_resolver()->destination_for( admin_url( 'options-general.php' ) )
		);
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Conflict\Resolver" not found`.

- [ ] **Step 4: Write `src/Conflict/Contracts/Resolver_Interface.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict\Contracts;

/**
 * Decides what happens when a sub-plugin's standalone counterpart is still active.
 *
 * Bind a replacement to change conflict handling globally.
 *
 * @since 1.0.0
 */
interface Resolver_Interface {
	/**
	 * Act on every registered sub-plugin whose standalone is active.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function resolve_all(): void;
}
```

- [ ] **Step 5: Write `src/Conflict/Resolver.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Default conflict resolution: detect the active standalone and act per policy.
 *
 * @since 1.0.0
 */
class Resolver implements Resolver_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function resolve_all(): void {
		foreach ( Loader::all() as $sub_plugin ) {
			if ( ! $sub_plugin->is_enabled() || ! $sub_plugin->is_standalone_plugin_active() ) {
				continue;
			}

			$this->resolve( $sub_plugin );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @return void
	 */
	protected function resolve( Sub_Plugin $sub_plugin ): void {
		$policy = $sub_plugin->get_conflict_policy();

		// A host may persist a policy in an option and a filter may return anything. Falling
		// through to deactivate() would turn off a plugin the site owner deliberately activated
		// on the strength of a typo, so an unrecognised policy takes the conservative branch.
		if ( ! Conflict_Policy::is_valid( $policy ) ) {
			$policy = Conflict_Policy::NOTICE_ONLY;
		}

		switch ( $policy ) {
			case Conflict_Policy::DEFER:
				// The standalone wins. Its own constant makes the load path skip the bundled copy.
				return;

			case Conflict_Policy::NOTICE_ONLY:
				Loader::notices()->queue_conflict_notice( $sub_plugin );

				return;

			case Conflict_Policy::DEACTIVATE:
			default:
				$this->deactivate( $sub_plugin );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @return void
	 */
	protected function deactivate( Sub_Plugin $sub_plugin ): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// The network flag is evaluated before the call, while the plugin is still active.
		// Omitting it makes deactivate_plugins() a silent no-op for a network-activated plugin,
		// so the next request would deactivate nothing and redirect again, forever.
		deactivate_plugins(
			$sub_plugin->get_standalone_plugin_basename(),
			false,
			$sub_plugin->is_standalone_plugin_network_active()
		);

		Loader::notices()->queue_merge_notice( $sub_plugin );

		$destination = $this->redirect_destination( wp_get_referer() );

		if ( $destination !== false ) {
			wp_safe_redirect( $destination );

			exit;
		}
	}

	/**
	 * Where to send the user after deactivating, or false to stay put.
	 *
	 * Never trap the user mid-update: an inline update on the plugins list must not be
	 * interrupted, and the update screens must not be reloaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string|false $referrer Result of wp_get_referer().
	 *
	 * @return string|false
	 */
	protected function redirect_destination( $referrer ) {
		if ( $referrer === false || $referrer === '' ) {
			return admin_url( 'plugins.php' );
		}

		foreach ( [ admin_url( 'update.php' ), admin_url( 'update-core.php' ) ] as $update_url ) {
			if ( strpos( $referrer, $update_url ) !== false ) {
				return admin_url( 'plugins.php' );
			}
		}

		if ( strpos( $referrer, admin_url( 'plugins.php' ) ) !== false ) {
			return false;
		}

		return $referrer;
	}
}
```

- [ ] **Step 6: Add the resolver accessor and the @1 hook to `src/Loader.php`**

Add `use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;` and `use Nexcess\PluginAbsorber\Conflict\Resolver;` to the imports, then:

```php
	/**
	 * @since 1.0.0
	 *
	 * @return Resolver_Interface
	 */
	public static function resolver(): Resolver_Interface {
		/** @var Resolver_Interface $resolver */
		$resolver = self::resolve( Resolver_Interface::class, Resolver::class );

		return $resolver;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function run_conflict_resolution(): void {
		self::resolver()->resolve_all();
	}
```

And add the hook to `boot()`, **before** the existing @2 line:

```php
		add_action( 'plugins_loaded', [ self::class, 'run_conflict_resolution' ], 1 );
		add_action( 'plugins_loaded', [ self::class, 'load_all' ], 2 );
```

- [ ] **Step 7: Add a boot assertion for the new hook**

Append to `tests/unit/LoaderBootTest.php`:

```php
	public function test_it_wires_conflict_resolution_at_priority_one(): void {
		Loader::boot();

		$this->assertSame(
			1,
			has_action( 'plugins_loaded', [ Loader::class, 'run_conflict_resolution' ] )
		);
	}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 15 resolver tests plus the new boot test.

- [ ] **Step 9: Run the multisite leg**

Run: `slic run unit --env multisite`
Expected: PASS. The network-flag tests stub `is_plugin_active_for_network`, so they assert the same way in both envs; the multisite run proves nothing else breaks under `MULTISITE`.

- [ ] **Step 10: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 11: Append to the README**

```markdown
### Per-sub-plugin policy override

`conflict_policy` accepts a `callable( Sub_Plugin ): string`, so one sub-plugin can decide at
runtime without a container and without touching the library:

```php
'conflict_policy' => static function ( Sub_Plugin $sub_plugin ) {
    // Stand down if a newer standalone supersedes the bundled copy.
    return my_standalone_version_at_least( $sub_plugin, '3.0.0' )
        ? Conflict_Policy::DEFER
        : Conflict_Policy::DEACTIVATE;
},
```

The `"{$prefix}/plugin_absorber/conflict_policy"` filter runs after that and wins:

```php
add_filter( 'give/plugin_absorber/conflict_policy', function ( $policy, $sub_plugin ) {
    return $policy;
}, 10, 2 );
```
```

- [ ] **Step 12: Commit, push, open the PR**

```bash
git add src/Conflict/ src/Loader.php tests/unit/Conflict/ tests/unit/LoaderBootTest.php README.md
git commit -m "Add conflict resolver with network-aware deactivation"
git push -u origin 12-conflict-resolver
gh pr create --base 11-loader-load-path --title "Conflict resolver" --body 'What: the three conflict policies, network-aware deactivation, the safe redirect, and the
`plugins_loaded` @1 hook.

Usage:

    // Automatic once booted. Per-sub-plugin override without a container:
    "conflict_policy" => static function ( Sub_Plugin $sub_plugin ) {
        return my_standalone_version_at_least( $sub_plugin, "3.0.0" )
            ? Conflict_Policy::DEFER
            : Conflict_Policy::DEACTIVATE;
    },

Why this way: `deactivate_plugins()` receives `$network_wide` — a change from the engineering plan,
which detects network activation and then drops the flag. Without it the call silently no-ops
against a network-activated plugin, so every admin request deactivates nothing and redirects again.
That is an infinite redirect loop on multisite, and it is exactly what the plan is own E2E
criterion ("reloading the plugins page does not loop") was meant to catch.

`redirect_destination()` returns false on a plugins.php referrer so an inline update is never
interrupted, and rewrites update.php / update-core.php referrers so the user is not bounced back
into an update screen.

Known limitation, deliberate: `resolve_all()` runs on front-end requests too, matching both
reference implementations. Tracked as issue B in the spec.

Verify: `slic run unit` and `slic run unit --env multisite` — 15 tests. `exit` is never mocked: the
stubbed `wp_safe_redirect()` throws `TestException`, which halts the resolver exactly where
production calls `exit` while leaving a failing test free to report as failing.'
```

---

## Task 13: `Activation`

**PR 13** · branch `13-activation` from `12-conflict-resolver` · 4 source files

**Files:**
- Create: `src/Contracts/Activation_Interface.php`, `src/Activation.php`, `tests/unit/ActivationTest.php`
- Modify: `src/Loader.php` (add `activation()` and call it from `load()`), `README.md`

**Interfaces:**
- Consumes: `Config::get_option_name()` (Task 4), `Sub_Plugin::get_activation_callback()` / `get_slug()` (Task 7), the `WithSubPlugins` trait (Task 7) for its fixtures, `Loader::resolve()` (Task 9).
- Produces: `Activation_Interface` with `maybe_run( Sub_Plugin $sub_plugin ): void`, and `Loader::activation(): Activation_Interface`.

**Design note:** the option key comes from `Config::get_option_name( 'activations' )`, never from
concatenating `Config::get_hook_prefix()`. The prefix validator admits `A-Z` and `-`, so a host
registering `Give-Core` would otherwise write to `Give-Core_plugin_absorber_activations`;
`get_option_name()` normalises that to `give_core_plugin_absorber_activations` while
`get_hook_name()` leaves filter names byte-for-byte as the host wrote them. `Notices\Store` uses the
same helper, so the two storage keys cannot drift apart.

**Why this exists:** `register_activation_hook()` never fires for a `require_once`'d file, so the absorbed plugin's original activation routine would otherwise never run. Tracked per slug in one option, run exactly once ever. It is **not** a place for ongoing upgrade logic — a merged sub-plugin handles version upgrades with its own idempotent, version-gated migrations on load.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 12-conflict-resolver && git checkout -b 13-activation
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Activation;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * @since 1.0.0
 */
class ActivationTest extends WPTestCase {
	use WithSubPlugins;

	private const OPTION = 'give_plugin_absorber_activations';

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
		delete_option( self::OPTION );
	}

	public function tearDown(): void {
		delete_option( self::OPTION );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	public function test_the_loader_resolves_the_default_activation(): void {
		$this->assertInstanceOf( Activation::class, Loader::activation() );
	}

	public function test_it_runs_the_callback(): void {
		$runs = 0;

		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => static function () use ( &$runs ) { ++$runs; } ] ) );

		$this->assertSame( 1, $runs );
	}

	public function test_it_never_runs_the_callback_twice(): void {
		$runs       = 0;
		$callback   = static function () use ( &$runs ) { ++$runs; };
		$activation = new Activation();

		$activation->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => $callback ] ) );
		$activation->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => $callback ] ) );

		$this->assertSame( 1, $runs, 'The callback must run exactly once, ever.' );
	}

	public function test_a_fresh_instance_still_sees_the_flag(): void {
		$runs     = 0;
		$callback = static function () use ( &$runs ) { ++$runs; };

		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => $callback ] ) );
		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => $callback ] ) );

		$this->assertSame( 1, $runs, 'The flag lives in an option, not in memory.' );
	}

	public function test_it_records_the_slug(): void {
		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => static function () {} ] ) );

		$this->assertSame( [ 'give-recurring' => true ], get_option( self::OPTION ) );
	}

	public function test_it_does_nothing_without_a_callback(): void {
		( new Activation() )->maybe_run( $this->make_sub_plugin() );

		$this->assertFalse( get_option( self::OPTION ), 'No callback means no option write at all.' );
	}

	public function test_it_tracks_slugs_independently(): void {
		$recurring = 0;
		$fees      = 0;

		$activation = new Activation();
		$activation->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => static function () use ( &$recurring ) { ++$recurring; } ] ) );
		$activation->maybe_run(
			$this->make_sub_plugin(
				[
					'slug'                => 'give-fee-recovery',
					'activation_callback' => static function () use ( &$fees ) { ++$fees; },
				]
			)
		);

		$this->assertSame( 1, $recurring );
		$this->assertSame( 1, $fees );
		$this->assertSame( [ 'give-recurring' => true, 'give-fee-recovery' => true ], get_option( self::OPTION ) );
	}

	public function test_it_recovers_from_a_corrupted_option(): void {
		update_option( self::OPTION, 'not-an-array' );

		$runs = 0;
		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => static function () use ( &$runs ) { ++$runs; } ] ) );

		$this->assertSame( 1, $runs );
		$this->assertSame( [ 'give-recurring' => true ], get_option( self::OPTION ) );
	}

	public function test_the_option_is_namespaced_by_hook_prefix(): void {
		Config::reset();
		Config::set_hook_prefix( 'learndash' );

		( new Activation() )->maybe_run( $this->make_sub_plugin( [ 'activation_callback' => static function () {} ] ) );

		$this->assertSame( [ 'give-recurring' => true ], get_option( 'learndash_plugin_absorber_activations' ) );
		$this->assertFalse( get_option( self::OPTION ) );

		delete_option( 'learndash_plugin_absorber_activations' );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Activation" not found`.

- [ ] **Step 4: Write `src/Contracts/Activation_Interface.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Runs a sub-plugin's one-time activation routine.
 *
 * @since 1.0.0
 */
interface Activation_Interface {
	/**
	 * Run the activation callback if it has never run for this slug.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin that was just loaded.
	 *
	 * @return void
	 */
	public function maybe_run( Sub_Plugin $sub_plugin ): void;
}
```

- [ ] **Step 5: Write `src/Activation.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Activation_Interface;

/**
 * Run-once activation tracking.
 *
 * register_activation_hook() never fires for a require_once'd file, so the absorbed plugin's
 * original activation routine would otherwise never run. One option holds a per-slug "has this
 * ever run?" flag.
 *
 * This is not a place for ongoing upgrade logic. A merged sub-plugin handles version upgrades
 * with its own idempotent, version-gated migrations on load.
 *
 * @since 1.0.0
 */
class Activation implements Activation_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin that was just loaded.
	 *
	 * @return void
	 */
	public function maybe_run( Sub_Plugin $sub_plugin ): void {
		$callback = $sub_plugin->get_activation_callback();

		if ( $callback === null ) {
			return;
		}

		$done = get_option( $this->option_name(), [] );
		$done = is_array( $done ) ? $done : [];

		if ( ! empty( $done[ $sub_plugin->get_slug() ] ) ) {
			return;
		}

		$callback();

		$done[ $sub_plugin->get_slug() ] = true;

		update_option( $this->option_name(), $done, false );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function option_name(): string {
		return Config::get_option_name( 'activations' );
	}
}
```

- [ ] **Step 6: Wire it into `src/Loader.php`**

Add `use Nexcess\PluginAbsorber\Contracts\Activation_Interface;`, then the accessor:

```php
	/**
	 * @since 1.0.0
	 *
	 * @return Activation_Interface
	 */
	public static function activation(): Activation_Interface {
		/** @var Activation_Interface $activation */
		$activation = self::resolve( Activation_Interface::class, Activation::class );

		return $activation;
	}
```

And append to `load()`, after the `require_once`:

```php
		require_once $sub_plugin->get_bundled_plugin_file();

		self::activation()->maybe_run( $sub_plugin );
```

- [ ] **Step 7: Assert the load path invokes it**

Append to `tests/unit/LoaderLoadTest.php`:

```php
	public function test_it_runs_the_activation_callback_after_loading(): void {
		$runs = 0;

		$this->register( [ 'activation_callback' => static function () use ( &$runs ) { ++$runs; } ] );

		Loader::load_all();

		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
		$this->assertSame( 1, $runs );

		delete_option( 'give_plugin_absorber_activations' );
	}

	public function test_it_does_not_run_the_activation_callback_when_the_load_is_skipped(): void {
		$runs = 0;

		$this->register(
			[
				'enabled'             => false,
				'activation_callback' => static function () use ( &$runs ) { ++$runs; },
			]
		);

		Loader::load_all();

		$this->assertSame( 0, $runs, 'Activation must follow a successful require, not precede it.' );
	}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 9 activation tests plus 2 new load tests.

- [ ] **Step 9: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 10: Append to the README**

```markdown
### Activation

`register_activation_hook()` never fires for a `require_once`'d file, so supply the absorbed
plugin's original activation routine directly. It runs **exactly once, ever**, tracked per slug:

```php
'activation_callback' => static function () {
    \Give\Recurring\Install::create_tables();
},
```

This reproduces the original activation only. Ongoing upgrades belong in the sub-plugin's own
idempotent, version-gated migrations — not here.
```

- [ ] **Step 11: Commit, push, open the PR**

```bash
git add src/Activation.php src/Contracts/Activation_Interface.php src/Loader.php tests/unit/ActivationTest.php tests/unit/LoaderLoadTest.php README.md
git commit -m "Add run-once activation tracking"
git push -u origin 13-activation
gh pr create --base 12-conflict-resolver --title "Activation" --body 'What: run-once-ever activation tracking, wired into the load path.

Usage:

    "activation_callback" => static function () {
        \Give\Recurring\Install::create_tables();
    },

Why this way: `register_activation_hook()` never fires for a `require_once`d file, so a plugin
absorbed into a host would never run its original install routine. One option holds a per-slug
flag, and the callback fires after a successful require — never when the load was skipped, which
would otherwise create tables for code that is not loaded.

A single option rather than one per slug keeps this to one autoloaded row no matter how many
sub-plugins a host bundles.

Known limitation, deliberate: read-then-write is not atomic, so two simultaneous first requests can
both run the callback. Tracked as issue E in the spec; `add_option()` as a claim would close it.

Verify: `slic run unit` — 11 tests, including a corrupted-option recovery and hook-prefix
namespacing.'
```

---

## Task 14: Activation-error rewrite

**PR 14** · branch `14-activation-error-notice` from `13-activation` · 3 source files

When a user tries to re-activate an absorbed standalone, WordPress kills the request and reports a
generic *"Plugin could not be activated because it triggered a fatal error."* — technically true and
completely unhelpful. Swap in the sub-plugin's own explanation.

**Files:**
- Modify: `src/Notices/Contracts/Queue_Interface.php`, `src/Notices/Queue.php`, `src/Loader.php`, `README.md`
- Create: `tests/unit/Notices/QueueActivationErrorTest.php`

**Interfaces:**
- Consumes: `Loader::all()` (Task 9), `Sub_Plugin::get_standalone_plugin_basename()` / `get_conflict_notice_message()` (Task 7).
- Produces:
  - `Notices\Contracts\Queue_Interface::filter_activation_error_markup( string $markup ): string` — **an addition to the interface shipped in Task 10**
  - `Loader::filter_activation_error_markup( $markup ): string`

**Design note (amendment A):** the engineering plan prescribed `ob_start()` on `admin_head-plugins.php`, copied from Kadence. The newer LearnDash reference (`Course_Grid/Legacy/Loader::update_legacy_plugin_activation_notice()`) uses the `wp_admin_notice_markup` filter instead. Same nonce check, same `str_replace`, but no buffering and no risk of mangling unrelated admin output — and it is testable by calling the filter directly. This is why the library requires WordPress 6.4+.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 13-activation && git checkout -b 14-activation-error-notice
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;

/**
 * @since 1.0.0
 */
class NoticesActivationErrorTest extends WPTestCase {
	private const BASENAME = 'give-recurring/give-recurring.php';

	/**
	 * The exact string WordPress emits, in the default text domain.
	 *
	 * @var string
	 */
	private $wordpress_markup;

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
		set_current_screen( 'plugins' );

		$this->wordpress_markup = '<div class="notice notice-error"><p>'
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- matching WP's own string.
			. __( 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.', 'default' )
			. '</p></div>';

		$_GET['plugin']       = self::BASENAME;
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_' . self::BASENAME );
	}

	public function tearDown(): void {
		unset( $_GET['plugin'], $_GET['_error_nonce'] );
		set_current_screen( 'front' );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function register( array $overrides = [] ): void {
		Loader::register(
			array_merge(
				[
					'slug'                       => 'give-recurring',
					'bundled_plugin_file'        => '/tmp/give-recurring.php',
					'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION_ERROR_NOTICE',
					'standalone_plugin_basename' => self::BASENAME,
					'conflict_notice_message'    => 'Give Recurring is now bundled with Give.',
				],
				$overrides
			)
		);
	}

	public function test_it_replaces_the_fatal_error_text(): void {
		$this->register();

		$result = ( new Notices() )->filter_activation_error_markup( $this->wordpress_markup );

		$this->assertStringContainsString( 'Give Recurring is now bundled with Give.', $result );
		$this->assertStringNotContainsString( 'fatal error', $result );
	}

	public function test_it_leaves_the_markup_alone_off_the_plugins_screen(): void {
		$this->register();
		set_current_screen( 'dashboard' );

		$this->assertSame(
			$this->wordpress_markup,
			( new Notices() )->filter_activation_error_markup( $this->wordpress_markup )
		);
	}

	public function test_it_leaves_the_markup_alone_for_an_unregistered_plugin(): void {
		$this->register();
		$_GET['plugin']       = 'some-other-plugin/some-other-plugin.php';
		$_GET['_error_nonce'] = wp_create_nonce( 'plugin-activation-error_some-other-plugin/some-other-plugin.php' );

		$this->assertSame(
			$this->wordpress_markup,
			( new Notices() )->filter_activation_error_markup( $this->wordpress_markup )
		);
	}

	public function test_it_leaves_the_markup_alone_with_a_bad_nonce(): void {
		$this->register();
		$_GET['_error_nonce'] = 'not-a-valid-nonce';

		$this->assertSame(
			$this->wordpress_markup,
			( new Notices() )->filter_activation_error_markup( $this->wordpress_markup )
		);
	}

	public function test_it_leaves_the_markup_alone_with_no_plugin_parameter(): void {
		$this->register();
		unset( $_GET['plugin'] );

		$this->assertSame(
			$this->wordpress_markup,
			( new Notices() )->filter_activation_error_markup( $this->wordpress_markup )
		);
	}

	public function test_it_leaves_the_markup_alone_without_a_configured_message(): void {
		$this->register( [ 'conflict_notice_message' => '' ] );

		$this->assertSame(
			$this->wordpress_markup,
			( new Notices() )->filter_activation_error_markup( $this->wordpress_markup ),
			'With nothing to say, keep WordPress own wording rather than blanking it.'
		);
	}

	public function test_it_strips_unsafe_markup_from_the_replacement_but_keeps_a_link(): void {
		$this->register(
			[ 'conflict_notice_message' => '<script>alert(1)</script><a href="https://example.com/kb">Read more</a>' ]
		);

		$result = ( new Notices() )->filter_activation_error_markup( $this->wordpress_markup );

		$this->assertStringNotContainsString( '<script>', $result );

		// The replacement goes through wp_kses_post(), not esc_html(), so the host's own link
		// reaches the screen instead of arriving as visible angle brackets.
		$this->assertStringContainsString( '<a href="https://example.com/kb">Read more</a>', $result );
	}

	public function test_the_loader_trampoline_delegates(): void {
		$this->register();

		$this->assertStringContainsString(
			'Give Recurring is now bundled with Give.',
			Loader::filter_activation_error_markup( $this->wordpress_markup )
		);
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Call to undefined method Nexcess\PluginAbsorber\Notices::filter_activation_error_markup()`.

- [ ] **Step 4: Add the method to `src/Notices/Contracts/Queue_Interface.php`**

```php
	/**
	 * Replace WordPress's generic fatal-activation text with the sub-plugin's own explanation.
	 *
	 * Filters `wp_admin_notice_markup`. Returns the markup untouched unless the current request
	 * is a nonce-verified activation error for a registered standalone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @return string
	 */
	public function filter_activation_error_markup( string $markup ): string;
```

- [ ] **Step 5: Implement it in `src/Notices/Queue.php`**

```php
	/**
	 * @since 1.0.0
	 *
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @return string
	 */
	public function filter_activation_error_markup( string $markup ): string {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return $markup;
		}

		$screen = get_current_screen();

		if ( $screen === null || $screen->id !== 'plugins' ) {
			return $markup;
		}

		$basename = isset( $_GET['plugin'] )
			? sanitize_text_field( wp_unslash( $_GET['plugin'] ) )
			: '';

		if ( $basename === '' ) {
			return $markup;
		}

		$sub_plugin = $this->find_by_standalone_basename( $basename );

		if ( $sub_plugin === null ) {
			return $markup;
		}

		$nonce = isset( $_GET['_error_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_error_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'plugin-activation-error_' . $basename ) ) {
			return $markup;
		}

		// The default matters: without one, a host that never configured a message gets
		// WordPress's raw "triggered a fatal error" screen -- the exact outcome this rewrite
		// exists to prevent -- and the rewrite would silently do nothing.
		$message = $sub_plugin->get_conflict_notice_message(
			sprintf(
				'%s is bundled with this plugin and loads automatically. The standalone copy cannot be activated alongside it.',
				$sub_plugin->get_slug()
			)
		);

		// wp_kses_post(), matching Notices\Renderer: the message comes from the host's own config
		// or filter, never from user input, so a knowledge-base link has to survive. Same message,
		// same screen -- the two rendering paths must not sanitise differently.
		//
		// Sanitising before the emptiness check, not after, is what makes the check hold: a
		// message that is nothing but disallowed markup filters down to '', and replacing
		// WordPress's wording with an empty string leaves the user staring at a blank notice.
		$message = trim( wp_kses_post( $message ) );

		if ( $message === '' ) {
			return $markup;
		}

		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately matching WordPress's own string.
		$wordpress_text = __( 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.', 'default' );

		return str_replace( $wordpress_text, $message, $markup );
	}

	/**
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename from the request.
	 *
	 * @return Sub_Plugin|null
	 */
	private function find_by_standalone_basename( string $basename ): ?Sub_Plugin {
		foreach ( Loader::all() as $sub_plugin ) {
			if ( $sub_plugin->get_standalone_plugin_basename() === $basename ) {
				return $sub_plugin;
			}
		}

		return null;
	}
```

- [ ] **Step 6: Add the trampoline and the hook to `src/Loader.php`**

```php
	/**
	 * @since 1.0.0
	 *
	 * @param mixed $markup Notice markup WordPress is about to print.
	 *
	 * @return string
	 */
	public static function filter_activation_error_markup( $markup ): string {
		return self::notices()->filter_activation_error_markup( (string) $markup );
	}
```

And inside `boot()`'s `is_admin()` block, beside the existing `all_admin_notices` line:

```php
		if ( is_admin() ) {
			add_action( 'all_admin_notices', [ self::class, 'render_notices' ] );
			add_filter( 'wp_admin_notice_markup', [ self::class, 'filter_activation_error_markup' ] );
		}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 8 tests.

- [ ] **Step 8: Confirm static analysis is still clean**

Run: `composer test:analysis`
If PHPStan objects to unslashed superglobal access, confirm `wp_unslash()` wraps every `$_GET` read.

- [ ] **Step 9: Append to the README**

```markdown
### Re-activation attempts

If a user tries to re-activate an absorbed standalone, WordPress reports a generic fatal error.
With `conflict_notice_message` set, that text is replaced by your own — nonce-verified, and only
for the matching plugin:

```php
'conflict_notice_message' => static fn() => __( 'Give Recurring is now bundled with Give and can be deactivated.', 'give' ),
```

Requires WordPress 6.4+ for the `wp_admin_notice_markup` filter.
```

- [ ] **Step 10: Commit, push, open the PR**

```bash
git add src/Notices/Queue.php src/Notices/Contracts/Queue_Interface.php src/Loader.php tests/unit/Notices/QueueActivationErrorTest.php README.md
git commit -m "Replace WordPress fatal-activation text for absorbed standalones"
git push -u origin 14-activation-error-notice
gh pr create --base 13-activation --title "Activation-error rewrite" --body 'What: replaces WordPress generic "triggered a fatal error" notice with the sub-plugin own
explanation when a user re-activates an absorbed standalone.

Usage:

    "conflict_notice_message" => static fn() => __( "Now bundled with Give.", "give" ),

Why this way: a change from the engineering plan, which specified `ob_start()` on
`admin_head-plugins.php` copied from Kadence. The newer LearnDash reference uses the
`wp_admin_notice_markup` filter — same nonce check, same str_replace, but no output buffering, no
risk of mangling unrelated admin output, and directly unit-testable. The cost is a WordPress 6.4
floor, which is when that filter landed.

Three gates before touching anything: the plugins screen, a `plugin` parameter matching a
registered standalone basename, and a valid `plugin-activation-error_{basename}` nonce. Failing any
one returns the markup untouched, as does having no configured message — better WordPress wording
than none.

This adds a method to `Notices\Contracts\Queue_Interface`, which shipped in PR 10. Pre-1.0 with no
consumers.

Verify: `slic run unit` — 8 tests, one per gate plus `wp_kses_post()` sanitising and the Loader
trampoline.'
```

---

## Task 15: End-to-end suite

**PR 15** · branch `15-e2e-fixtures` from `14-activation-error-notice` · 1 source file

Exercises the whole matrix from the engineering plan's verification section against real WordPress state — the real `active_plugins` option, real `deactivate_plugins()`, real transients and options. Only `wp_safe_redirect` and `wp_get_referer` are stubbed; the redirect throws `TestException` so the request halts where production calls `exit`, without mocking `exit` itself.

**Files:**
- Create: `tests/_data/plugins/absorber-host/absorber-host.php`, `tests/_data/plugins/fake-standalone/fake-standalone.php`, `tests/_support/Traits/WithBundledPlugins.php`, `tests/unit/EndToEndTest.php`
- Modify: `tests/unit/LoaderLoadTest.php` (use the new bundled-file trait), `README.md`

**Interfaces:**
- Consumes: everything from Tasks 4 through 14.
- Produces: `WithBundledPlugins` trait with `make_bundled_plugin( string $constant ): string`, `unique_guard_constant(): string`, and `remove_bundled_plugins(): void` (`@after`). This is the second fixture trait and the one that touches the filesystem: `WithSubPlugins` (Task 7) builds `Sub_Plugin` config objects in memory, `WithBundledPlugins` writes throwaway plugin files to disk and deletes them again. Tests that need a real file to `require_once` want this one.

**Why generated bundled files:** `require_once` caches by resolved path for the whole PHP process. A committed bundled fixture would execute once across the entire suite, so every test after the first would silently pass without loading anything. Each test that asserts a load therefore writes its own file. The two committed fixtures are the ones that must be *readable* rather than *loaded*: `absorber-host.php` documents the consumer bootstrap, `fake-standalone.php` gives the standalone a real plugin header.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 14-activation-error-notice && git checkout -b 15-e2e-fixtures
```

- [ ] **Step 2: Extract the bundled-file helper into a shared trait**

`LoaderLoadTest` writes throwaway plugin files and the end-to-end test needs the same thing, so the
generator moves next to `WithSubPlugins`. The two do not overlap: this one writes files to disk,
`WithSubPlugins` builds config objects.

```php
<?php
/**
 * Generates throwaway bundled plugin files.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * @since 1.0.0
 */
trait WithBundledPlugins {
	/**
	 * @var array<int,string>
	 */
	private $bundled_plugin_files = [];

	/**
	 * Write a bundled plugin that counts its loads and defines its guard constant.
	 *
	 * A unique path per call is required: require_once caches by resolved path for the lifetime
	 * of the PHP process, so a shared file would execute once for the whole suite and every later
	 * test would pass without loading anything.
	 *
	 * @since 1.0.0
	 *
	 * @param string $constant Guard constant the bundled file defines.
	 *
	 * @return string Absolute path to the generated file.
	 */
	protected function make_bundled_plugin( string $constant ): string {
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
	 * Generate a guard constant no other test will collide with.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function unique_guard_constant(): string {
		return 'ABSORBER_GUARD_' . strtoupper( bin2hex( random_bytes( 5 ) ) );
	}

	/**
	 * @since 1.0.0
	 *
	 * @after
	 *
	 * @return void
	 */
	protected function remove_bundled_plugins(): void {
		foreach ( $this->bundled_plugin_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$this->bundled_plugin_files = [];
	}
}
```

- [ ] **Step 3: Refactor `tests/unit/LoaderLoadTest.php` to use it**

Delete its private `make_fixture()` and `$fixtures` property plus the unlink loop in `tearDown()`, add `use WithBundledPlugins;` to the class, and change `$this->make_fixture( $constant )` to `$this->make_bundled_plugin( $constant )` and the inline constant generation to `$this->unique_guard_constant()`.

- [ ] **Step 4: Run the suite to confirm the refactor is clean**

Run: `slic run unit`
Expected: PASS — same counts as before Task 15.

- [ ] **Step 5: Write the committed fixture plugins**

`tests/_data/plugins/absorber-host/absorber-host.php` — the worked consumer example, read by humans, not executed by the suite:

```php
<?php
/**
 * Plugin Name: Absorber Host
 * Description: Reference host plugin showing how to register and boot the absorber.
 * Version:     1.0.0
 *
 * @package Nexcess\PluginAbsorber
 */

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Loader;

add_action(
	'plugins_loaded',
	static function () {
		Config::set_hook_prefix( 'absorber_host' );

		Loader::register(
			[
				'slug'                       => 'fake-standalone',
				'bundled_plugin_file'        => __DIR__ . '/subs/fake-standalone/fake-standalone.php',
				'plugin_loaded_constant'     => 'FAKE_STANDALONE_VERSION',
				'standalone_plugin_basename' => 'fake-standalone/fake-standalone.php',
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
				'conflict_notice_message'    => 'Fake Standalone is now bundled with Absorber Host.',
				'activation_callback'        => static function () {
					update_option( 'absorber_host_fake_standalone_installed', true );
				},
			]
		);

		Loader::boot();
	},
	0
);
```

`tests/_data/plugins/fake-standalone/fake-standalone.php` — the standalone counterpart:

```php
<?php
/**
 * Plugin Name: Fake Standalone
 * Description: Stands in for a formerly-standalone plugin that a host has absorbed.
 * Version:     1.0.0
 *
 * @package Nexcess\PluginAbsorber
 */

if ( ! defined( 'FAKE_STANDALONE_VERSION' ) ) {
	define( 'FAKE_STANDALONE_VERSION', '1.0.0' );
}
```

- [ ] **Step 6: Write the end-to-end test**

```php
<?php
/**
 * The engineering plan's verification matrix, against real WordPress state.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Tests\Support\Loader_State;
use Nexcess\PluginAbsorber\Tests\Support\TestException;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithBundledPlugins;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class EndToEndTest extends WPTestCase {
	use WithBundledPlugins;
	use UopzFunctions;

	private const STANDALONE     = 'fake-standalone/fake-standalone.php';
	private const TRANSIENT      = 'absorber_host_plugin_absorber_notices';
	private const OPTION         = 'absorber_host_plugin_absorber_activations';
	private const HALTED_AT_EXIT = 'Request halted where production calls exit().';

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'absorber_host' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$GLOBALS['absorber_loads'] = 0;

		// Only the two calls that would end the request are stubbed; everything
		// else — active_plugins, deactivate_plugins(), transients — is real.
		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function () {
				throw new TestException( self::HALTED_AT_EXIT );
			},
			true
		);
		$this->setFunctionReturn( 'wp_get_referer', false );
	}

	public function tearDown(): void {
		delete_transient( self::TRANSIENT );
		delete_option( self::OPTION );
		update_option( 'active_plugins', [] );
		unset( $GLOBALS['absorber_loads'] );
		Loader_State::reset();
		Config::reset();
		parent::tearDown();
	}

	private function activate_standalone(): void {
		update_option( 'active_plugins', [ self::STANDALONE ] );
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function register( array $overrides = [] ): string {
		$constant = $this->unique_guard_constant();

		Loader::register(
			array_merge(
				[
					'slug'                       => 'fake-standalone',
					'bundled_plugin_file'        => $this->make_bundled_plugin( $constant ),
					'plugin_loaded_constant'     => $constant,
					'standalone_plugin_basename' => self::STANDALONE,
				],
				$overrides
			)
		);

		return $constant;
	}

	private function run_request(): void {
		try {
			Loader::run_conflict_resolution();
		} catch ( TestException $e ) {
			$this->assertSame( self::HALTED_AT_EXIT, $e->getMessage() );

			// Production exits inside the redirect, so load_all() never runs on
			// this request. Returning here is what makes the assertion that a
			// deactivating request does not also load the sub-plugin meaningful.
			return;
		}

		Loader::load_all();
	}

	/**
	 * @return array<string,string>
	 */
	private function queued_notices(): array {
		$queue = get_transient( self::TRANSIENT );

		return is_array( $queue ) ? $queue : [];
	}

	public function test_fresh_load_defines_the_constant_and_runs_activation_once(): void {
		$runs     = 0;
		$constant = $this->register( [ 'activation_callback' => static function () use ( &$runs ) { ++$runs; } ] );

		$this->run_request();

		$this->assertTrue( defined( $constant ) );
		$this->assertSame( 1, $GLOBALS['absorber_loads'] );
		$this->assertSame( 1, $runs );

		// A second request must not re-run activation.
		$this->run_request();

		$this->assertSame( 1, $runs, 'The activation callback runs exactly once, ever.' );
	}

	public function test_deactivate_policy_deactivates_the_standalone_and_notifies(): void {
		$this->activate_standalone();
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		$this->run_request();

		$this->assertFalse(
			is_plugin_active( self::STANDALONE ),
			'The standalone must actually be gone from active_plugins.'
		);
		$this->assertArrayHasKey( 'fake-standalone:merge', $this->queued_notices() );
	}

	public function test_a_second_request_after_deactivation_does_not_loop(): void {
		$this->activate_standalone();
		$this->register();

		$this->run_request();
		delete_transient( self::TRANSIENT );

		$this->run_request();

		$this->assertSame(
			[],
			$this->queued_notices(),
			'With the standalone already deactivated there is nothing left to resolve.'
		);
	}

	public function test_defer_policy_leaves_the_standalone_active(): void {
		$this->activate_standalone();
		$this->register( [ 'conflict_policy' => Conflict_Policy::DEFER ] );

		$this->run_request();

		$this->assertTrue( is_plugin_active( self::STANDALONE ) );
		$this->assertSame( [], $this->queued_notices() );
	}

	public function test_notice_only_policy_notifies_without_deactivating(): void {
		$this->activate_standalone();
		$this->register( [ 'conflict_policy' => Conflict_Policy::NOTICE_ONLY ] );

		$this->run_request();

		$this->assertTrue( is_plugin_active( self::STANDALONE ) );
		$this->assertArrayHasKey( 'fake-standalone:conflict', $this->queued_notices() );
	}

	public function test_the_bundled_copy_stands_down_when_the_guard_constant_exists(): void {
		define( 'ABSORBER_E2E_STANDALONE_PRESENT', '1.0.0' );

		Loader::register(
			[
				'slug'                       => 'fake-standalone',
				'bundled_plugin_file'        => $this->make_bundled_plugin( 'UNUSED_CONSTANT_E2E' ),
				'plugin_loaded_constant'     => 'ABSORBER_E2E_STANDALONE_PRESENT',
				'standalone_plugin_basename' => self::STANDALONE,
			]
		);

		$this->run_request();

		$this->assertSame( 0, $GLOBALS['absorber_loads'], 'The load guard is what prevents the fatal.' );
	}

	public function test_toggling_a_sub_plugin_off_loads_nothing(): void {
		$this->register( [ 'enabled' => static fn() => false ] );

		$this->run_request();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
	}

	public function test_two_sub_plugins_load_in_one_request(): void {
		$first  = $this->unique_guard_constant();
		$second = $this->unique_guard_constant();

		Loader::register(
			[
				'slug'                   => 'sub-one',
				'bundled_plugin_file'    => $this->make_bundled_plugin( $first ),
				'plugin_loaded_constant' => $first,
			]
		);
		Loader::register(
			[
				'slug'                   => 'sub-two',
				'bundled_plugin_file'    => $this->make_bundled_plugin( $second ),
				'plugin_loaded_constant' => $second,
			]
		);

		$this->run_request();

		$this->assertSame( 2, $GLOBALS['absorber_loads'] );
		$this->assertTrue( defined( $first ) );
		$this->assertTrue( defined( $second ) );
		$this->assertSame( [], $this->queued_notices(), 'No conflicts, so no notices.' );
	}

	public function test_an_unmet_dependency_blocks_the_load_and_explains_why(): void {
		$this->register( [ 'dependency_check' => static fn() => false ] );

		$this->run_request();

		$this->assertSame( 0, $GLOBALS['absorber_loads'] );
		$this->assertArrayHasKey( 'fake-standalone:dependency', $this->queued_notices() );
	}
}
```

- [ ] **Step 7: Run both envs**

Run: `slic run unit`
Expected: PASS — 9 end-to-end tests plus everything prior.

Run: `slic run unit --env multisite`
Expected: PASS.

- [ ] **Step 8: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 9: Commit, push, open the PR**

```bash
git add tests/ README.md
git commit -m "Add end-to-end suite against real WordPress state"
git push -u origin 15-e2e-fixtures
gh pr create --base 14-activation-error-notice --title "End-to-end suite" --body 'What: the engineering plan verification matrix as automated tests, plus a reference host plugin.

Usage: `tests/_data/plugins/absorber-host/absorber-host.php` is the worked consumer example —
register, set a policy, supply an activation callback, boot.

Why this way: these drive the real `active_plugins` option and let `deactivate_plugins()` actually
run, rather than stubbing it as the unit tests do. Only `wp_safe_redirect` and `wp_get_referer` are
stubbed; the redirect throws `TestException` so the request halts where production calls `exit`,
without mocking `exit` itself. That makes this a genuine integration check of the load guard,
the three policies, and the run-once activation working together.

Bundled fixtures are generated per test rather than committed: `require_once` caches by resolved
path for the whole PHP process, so a committed bundled file would execute once for the entire suite
and every later test would pass without loading anything. The generator moved into a shared
`WithBundledPlugins` trait — the on-disk counterpart to `WithSubPlugins` from PR 7 — and
`LoaderLoadTest` now uses it too.

Verify: `slic run unit` and `slic run unit --env multisite` — 9 end-to-end tests. Not covered: real
HTTP requests and a real browser; the redirect is asserted as a call, not followed.'
```

---

## Task 16: README pass and release

**PR 16** · branch `16-readme-release` from `15-e2e-fixtures` · 3 source files

**Files:**
- Modify: `README.md`, `.gitattributes`
- Create: `CHANGELOG.md`

**Interfaces:**
- Consumes: everything.
- Produces: the `1.0.0` tag.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 15-e2e-fixtures && git checkout -b 16-readme-release
```

- [ ] **Step 2: Read the README end to end and tighten it**

It was assembled section by section across twelve PRs. Check, in order:

1. Total length is roughly 120 lines. If it is materially longer, cut — do not add.
2. Sections read in the order a newcomer needs them: install → configure → bootstrap → config table → policies → seams → rebinding → activation → re-activation.
3. No section restates a method signature already visible in the config table.
4. No duplicated example. Twelve incremental PRs tend to leave two near-identical bootstrap snippets; keep one.
5. Every code sample uses `Nexcess\PluginAbsorber\`, never `Nexcess\SubPluginLoader\`.
6. No prose introduction, no FAQ, no "why this library".

- [ ] **Step 3: Add the requirements line and the complete worked example**

At the end of the README:

```markdown
## Requirements

PHP 7.4+, WordPress 6.4+ (for the `wp_admin_notice_markup` filter).

## Complete example

```php
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Loader;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );
    Config::set_container( give()->container ); // optional

    Loader::register( [
        'slug'                       => 'give-recurring',
        'bundled_plugin_file'        => GIVE_PLUGIN_DIR . 'subs/give-recurring/give-recurring.php',
        'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION',
        'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
        'enabled'                    => static fn() => give_is_gateway_enabled( 'recurring' ),
        'conflict_policy'            => Conflict_Policy::DEACTIVATE,
        'conflict_notice_message'    => static fn() => __( 'Give Recurring is now bundled with Give and can be deactivated.', 'give' ),
        'activation_callback'        => static function () { \Give\Recurring\Install::create_tables(); },
        'dependency_check'           => static fn() => class_exists( 'Give' ),
    ] );

    Loader::boot();
}, 0 );
```
```

- [ ] **Step 4: Write `CHANGELOG.md`**

```markdown
# Changelog

## 1.0.0

Initial release.

- `Config` / `Loader` static facade with an optional container for rebinding any collaborator.
- Load-guard constant checked before `require_once`, preventing re-declaration fatals regardless
  of load order.
- Three conflict policies — `DEACTIVATE` (default), `DEFER`, `NOTICE_ONLY` — settable per
  sub-plugin as a constant, a callable, or via the `…/conflict_policy` filter.
- Network-aware deactivation, so a network-activated standalone does not cause a redirect loop.
- Run-once-ever activation callbacks, tracked per slug.
- Self-contained admin notices, and replacement of WordPress's generic fatal-activation text on a
  re-activation attempt.
- `…/should_load` filter as a final per-sub-plugin gate.
```

- [ ] **Step 5: Add `CHANGELOG.md` handling to `.gitattributes`**

Leave `CHANGELOG.md` shipped (consumers benefit); confirm `docs/` and `engineering-plan.md` are still `export-ignore`d from Task 1.

- [ ] **Step 6: Verify a consumer install contains only runtime files**

```bash
git archive --format=tar HEAD | tar -t
```
Expected: `src/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`. **No** `tests/`, `docs/`, `.github/`, `engineering-plan.md`, or dotfiles.

- [ ] **Step 7: Full verification**

```bash
composer validate --no-check-lock
composer test:analysis
slic run unit
slic run unit --env multisite
```
Expected: all green.

- [ ] **Step 8: Commit, push, open the PR**

```bash
git add README.md CHANGELOG.md .gitattributes
git commit -m "Tighten README, add changelog for 1.0.0"
git push -u origin 16-readme-release
gh pr create --base 15-e2e-fixtures --title "README pass and 1.0.0" --body 'What: README tightened after twelve incremental additions, changelog, release prep.

Usage: see the complete worked example at the end of the README.

Why this way: the README was built up section by section as each PR landed, so it never drifted
from what actually shipped. This pass removes the duplication that approach leaves behind and
checks the ordering reads for a newcomer rather than in merge order.

Verify: `git archive --format=tar HEAD | tar -t` shows only src/, composer.json, LICENSE, README,
CHANGELOG — no tests, docs, or CI config in a consumer install. Both suites and PHPStan green.'
```

- [ ] **Step 9: Merge the stack and tag**

Merge PRs 1 through 16 in order, then:

```bash
git checkout main && git pull
git tag -a 1.0.0 -m "1.0.0"
git push origin 1.0.0
```

- [ ] **Step 10: Submit to Packagist**

Submit `https://github.com/stellarwp/plugin-absorber` at <https://packagist.org/packages/submit> under the `nexcess` vendor, and enable the GitHub service hook so future tags publish automatically.

---

## Deferred, tracked for 1.0.1

Recorded in the spec, deliberately not fixed in 1.0.0:

- **B** — `resolve_all()` runs on front-end requests. With no referrer it redirects to
  `admin_url( 'plugins.php' )`, bouncing a logged-out visitor to the login screen. Wrapping it in
  `is_admin()` fixes it and is safe, since the load guard already prevents any front-end fatal.
  Matches both reference implementations as-is.
- **E** — `Activation::maybe_run()` reads the option, runs the callback, then writes. Two
  simultaneous first requests can both run it. `add_option()` as an atomic claim would close it.
- ~~**F** — `Config::get_version()` is stored but never read.~~ Closed 2026-08-11 by removing
  version handling from `Config` outright; see the Global Constraint on version handling.

## Self-review

Checked against `docs/superpowers/specs/2026-07-31-plugin-absorber-design.md`:

- **Spec coverage.** All 16 PRs map to tasks 1–16. Amendment A → Task 14; C → Task 7
  (`is_standalone_plugin_network_active()`) + Task 12 (the `$network_wide` argument); D → Task 7
  (`get_dependency_notice_message()`) + Task 10 (rendering). Deferred B/E/F are recorded above and
  in the PR bodies that touch them. Every spec test bullet has a corresponding test method.
- **Interface consistency.** `Notices\Contracts\Queue_Interface` grows one method in Task 14 —
  flagged in both the task and the PR body rather than left implicit. `Loader_State::reset()` is written once in Task 9 and
  extended once in Task 11 (`$booted`), shown in full both times. `redirect_destination()` is
  `protected`, matching how the Task 12 tests subclass it.
- **Placeholder scan.** No TBD, no "add error handling", no "similar to Task N". Every code step
  carries the code.
- **Corrections applied during review.** Task 11's `boot()` wires only the @2 hook, with the @1 hook
  added in Task 12 alongside the resolver it calls — wiring it earlier would point a trampoline at a
  non-existent accessor. Two fixture traits land, in the task that first needs one rather than in
  the task that would have copied it: Task 7 extracts `WithSubPlugins`, the in-memory `Sub_Plugin`
  builder that Tasks 8, 10 and 13 would each have redeclared, and Task 15 extracts
  `WithBundledPlugins`, the on-disk plugin-file generator, refactoring Task 11's test onto it
  rather than duplicating six lines across two files.
