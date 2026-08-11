# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this library is

`stellarwp/plugin-absorber` gives a host WordPress plugin one standardized way to load *bundled*
sub-plugins — plugins that used to ship standalone and have since been absorbed. The headline risk
it exists to prevent is a fatal re-declaration error when the bundled copy and a still-installed
standalone copy both load in the same request.

Safety rests on two independent, single-purpose config keys per sub-plugin:

- **`plugin_loaded_constant`** — the load guard. `defined()` is checked before `require_once`, so
  whichever copy loads first stands the other down.
- **`standalone_plugin_basename`** — used to detect the standalone and, under the default policy,
  deactivate it.

Deliberately out of scope: version negotiation, any opinion on toggle UI or storage, and any
production dependency on another StellarWP library.

## Commands

Tests run through [slic](https://github.com/stellarwp/slic) against a real WordPress. There is no
bare `phpunit.xml` — you cannot run this suite with plain PHPUnit.

```bash
slic use plugin-absorber
slic composer install
slic cc build

slic run unit                     # singlesite
slic run unit --env multisite     # multisite

# A single file, and a single method
slic run unit tests/unit/ConfigTest.php
slic run unit "tests/unit/ConfigTest.php:test_it_rejects_invalid_hook_prefixes"

composer test:analysis            # PHPStan level 8 — must stay green on every PR
```

`slic run` takes no `--filter`; narrowing is the positional `<test>` argument shown above.

## Architecture

### Public surface

A two-class static facade — `Config` (hook prefix + optional container) and `Loader`
(resolve/register/boot/load) — matching the shape of `stellarwp/assets` and
`stellarwp/admin-notices`. Everything else is an implementation detail behind it.

### Collaborators

Four interface-backed collaborators, each with a default implementation:

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | holds registered `Sub_Plugin` objects |
| `Contracts\Notices_Interface` | `Notices` | notice queue + activation-error rewrite |
| `Conflict\Resolver_Interface` | `Conflict\Resolver` | standalone detection, deactivation, redirect |
| `Contracts\Activation_Interface` | `Activation` | run-once activation-callback tracking |

All four come through one generic helper — `Loader::resolve( string $interface, string
$default_class ): object` — which returns the container binding when `$container->has()`, otherwise
`new $default_class()`, memoized either way. Collaborators reach each other through the accessors
(`Loader::registrar()`, `resolver()`, `notices()`, `activation()`), so rebinding one in the host's
container flows everywhere.

The container is **never** used to wire hooks. Hooks stay plain static trampolines, which is what
keeps the container genuinely optional.

`Sub_Plugin` is a value object that holds *every* per-sub-plugin predicate (`is_enabled()`,
`is_already_loaded()`, `is_standalone_plugin_active()`, `get_conflict_policy()`, …). Keeping them
there is what lets collaborators stay thin and lets the predicates be tested without hooks.

### Boot lifecycle

```
Config::set_hook_prefix( 'give' );
Config::set_container( $container );          // optional
Loader::register( [ …config… ] );             // once per sub-plugin; last-wins dedupe by slug
Loader::boot();                               // idempotent

plugins_loaded @1      → Conflict\Resolver::resolve_all()
plugins_loaded @2      → Loader::load_all()
admin_notices          → Loader::render_notices()                 [is_admin() only]
wp_admin_notice_markup → Loader::filter_activation_error_markup() [is_admin() only]
```

`load_all()` gates each sub-plugin in order, skipping on the first failure: enabled → dependencies
met → not already loaded → file exists → `should_load` filter → `require_once` → activation
callback (only after a *successful* require).

`Conflict\Resolver` switches on the policy: `DEFER` no-ops, `NOTICE_ONLY` queues a notice, and
`DEACTIVATE` (the default) deactivates network-aware, queues a merge notice, and redirects.
`redirect_destination()` returns `false` when the referrer is already `plugins.php`, so an inline
update is never interrupted.

An unknown policy must be handled as its own case via `Conflict_Policy::is_valid()`, never left to
a `default:` fallthrough — a typo like `'defered'` would otherwise deactivate a plugin the site
owner deliberately turned on.

### Keys

- Filters: `{$hook_prefix}/plugin_absorber/should_load`, `{$hook_prefix}/plugin_absorber/conflict_policy`
- Option: `{$hook_prefix}_plugin_absorber_activations`
- Transient: `{$hook_prefix}_plugin_absorber_notices`

## Conventions

**Namespace is `Nexcess\PluginAbsorber\`, not `StellarWP\`.** The Composer package is
`stellarwp/plugin-absorber`; the two deliberately do not match. Tests are `…\Tests\`, support
classes `…\Tests\Support\`.

**Floors:** PHP `>=7.4`, WordPress 6.4 (for the `wp_admin_notice_markup` filter). WordPress is not a
Composer dependency, so its floor lives in the README only.

**Production dependencies:** `stellarwp/container-contract` only. `lucatume/di52` is dev-only.

**Class naming is `Snake_Case`** (`Sub_Plugin`, `Conflict_Policy`, `Config_Exception`). Method names
are fully spelled out; config keys are descriptive and WordPress-centric.

**Method order is public, then protected, then private.** No private helper sits above the public
API it serves.

**Docblocks:** every file in `src/` carries a file-level docblock with `@package
Nexcess\PluginAbsorber`, and every method a docblock with `@since 1.0.0`. This binds `src/` only —
test and support classes keep the file-level docblock but need no `@since`.

**Comments describe behaviour, not the plan.** Never reference a task or plan-step number in a code
comment; the code outlives the plan.

Tabs for PHP, 4 spaces for yml/yaml/json/md (see `.editorconfig`).

### No test-only seams in `src/`

Production classes do not get a `reset()` for the suite's benefit — that becomes API the library
supports forever. Tests clear static state by reflection through a helper under `tests/_support/`.
`Config` is served by `Tests\Support\Config_State::reset()`; `Registrar` and `Loader` get the same
treatment. Any older sketch showing `Config::reset()` or `Loader::reset()` means the support helper.

### Testing rules

`tests/unit/` mirrors `src/`. Full detail lives in `tests/README.md`; the rules that bite hardest:

- **Never mock `exit()`.** `UopzFunctions::preventExit()` exists — do not use it. It lets a test run
  past the point where production would have stopped, so a test that should fail reports as passing.
  Instead stub the call immediately before `exit` (e.g. `wp_safe_redirect`), throw
  `Tests\Support\TestException` from it, catch it, and assert both a `$halted` flag *and* the
  message. Dropping the flag turns "never redirected at all" into a silent pass.
- **A stub closure has no class scope.** uopz executes the replacement outside the test object, so
  `$this` and `self::` are fatal inside it. Bind a reference (`$x = &$this->prop;`), resolve
  constants to locals, `use` both, and mark the closure `static`.
- **Stub with wp-browser's `UopzFunctions` trait.** Do not add a local `WithUopz` trait — this
  library deliberately does not maintain one.
- **Container tests must use `Tests\Support\Test_Container`.** `lucatume\DI52\Container` implements
  PSR-11's `ContainerInterface`, not StellarWP's, so passing it to `Config::set_container()` is a
  `TypeError`.
- **Each load-path test writes its own bundled fixture file.** `require_once` caches by resolved
  path per PHP process, so a shared fixture makes every later test silently pass.
- Tasks follow RED→GREEN: the failing test lands before the implementation.

## Branch and PR workflow

Branching is **stacked**: each branch cuts from the previous one and merges to `main` in order.
Branch names are `NN-topic` and map 1:1 to task numbers in the plan. Never open PR N+1 before PR N's
branch exists. `main` is releasable after every merge.

- **PR size cap:** ≤10 files, tests and test infrastructure excluded. No logic-bearing PR exceeds 4
  source files.
- **Commits: no co-author trailers, ever.**
- **PR body is exactly four parts, nothing else** — no boilerplate headings, no restating the diff,
  no checklists:

  ```
  What: one line.

  Usage: the snippet this PR makes possible.

  Why this way: the trade-off taken, and against what.

  Verify: the command, and what is deliberately not covered.
  ```

- The README grows section-by-section with each PR so it is never ahead of what has shipped. Target
  ~120 lines: if it runs materially longer, cut rather than add.
- New dev-only files belong in `.gitattributes` as `export-ignore` so they stay out of consumer
  installs.

## Documentation precedence

`docs/superpowers/specs/2026-07-31-plugin-absorber-design.md` is authoritative. Where it and
`engineering-plan.md` disagree, **the spec wins** — the engineering plan is a superseded first draft
still carrying the old `stellarwp/sub-plugin-loader` package name, the `Nexcess\SubPluginLoader\`
namespace, a `Config::set_version()` that was removed, and an `ob_start()` approach replaced by the
`wp_admin_notice_markup` filter. `docs/superpowers/plans/2026-07-31-plugin-absorber.md` holds the
task-by-task breakdown.
