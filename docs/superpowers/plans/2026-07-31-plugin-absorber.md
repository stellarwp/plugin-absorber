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
- **Storage keys:** option `"{$hook_prefix}_plugin_absorber_activations"`, transient `"{$hook_prefix}_plugin_absorber_notices"`.
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

## File Structure

```
plugin-absorber/
├── src/
│   ├── Config.php                        # static config facade: hook prefix, version, container
│   ├── Loader.php                        # static facade: resolve/register/boot/load loop
│   ├── Sub_Plugin.php                    # value object + every per-sub-plugin predicate
│   ├── Conflict_Policy.php               # three policy string constants
│   ├── Registrar.php                     # default slug => Sub_Plugin map
│   ├── Activation.php                    # default run-once activation tracking
│   ├── Notices.php                       # default notice queue + activation-error rewrite
│   ├── Conflict/
│   │   ├── Resolver.php                  # default standalone detection/deactivation/redirect
│   │   └── Resolver_Interface.php
│   ├── Contracts/
│   │   ├── Registrar_Interface.php
│   │   ├── Notices_Interface.php
│   │   └── Activation_Interface.php
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

## Task 1: Repo bootstrap

**PR 1** · branch `01-repo-bootstrap` from `main` · 6 source files

**Files:**
- Create: `composer.json`, `LICENSE`, `.gitignore`, `.gitattributes`, `.editorconfig`, `README.md`, `cspell.json`

**Interfaces:**
- Consumes: nothing.
- Produces: the `Nexcess\PluginAbsorber\` PSR-4 autoload root that every later task relies on, and the `composer test:analysis` script Task 5 wires into CI.

- [ ] **Step 1: Rename the working directory to match the remote**

```bash
cd /Users/owl/www/wp-plugins
mv sub-plugin-loader plugin-absorber
cd plugin-absorber
git remote -v   # expect: origin  https://github.com/stellarwp/plugin-absorber.git
```

- [ ] **Step 2: Cut the branch**

```bash
git checkout -b 01-repo-bootstrap
```

- [ ] **Step 3: Write `composer.json`**

```json
{
    "name": "stellarwp/plugin-absorber",
    "description": "Safely load bundled WordPress plugins inside a host plugin, togglable or always-on, without fatal errors.",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "minimum-stability": "stable",
    "authors": [
        {
            "name": "StellarWP",
            "email": "eric_defore@vendor.stellarwp.com"
        }
    ],
    "require": {
        "php": ">=7.4",
        "stellarwp/container-contract": "^1.0"
    },
    "require-dev": {
        "codeception/module-asserts": "^1.0",
        "codeception/util-universalframework": "^1.0",
        "lucatume/di52": "^3.0",
        "lucatume/wp-browser": "^3.6.5",
        "php-stubs/wordpress-stubs": "^6.4",
        "phpunit/phpunit": "^9.5",
        "szepeviktor/phpstan-wordpress": "^1.3"
    },
    "autoload": {
        "psr-4": {
            "Nexcess\\PluginAbsorber\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Nexcess\\PluginAbsorber\\Tests\\": "tests/",
            "Nexcess\\PluginAbsorber\\Tests\\Support\\": "tests/_support"
        }
    },
    "scripts": {
        "test:analysis": [
            "phpstan analyse -c phpstan.neon.dist --memory-limit=512M"
        ],
        "test:unit": [
            "slic run unit"
        ]
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "platform": {
            "php": "7.4"
        },
        "allow-plugins": {
            "phpstan/extension-installer": true
        }
    }
}
```

- [ ] **Step 4: Write `.gitignore`**

```gitignore
/vendor/
/composer.lock
/tests/_output/*
!/tests/_output/.gitkeep
/phpstan-cache/
/codeception.yml
/.env.testing.local
.DS_Store
.idea/
.vscode/
```

- [ ] **Step 5: Write `.gitattributes`**

Keeps development files out of consumer installs.

```gitattributes
/.github            export-ignore
/docs               export-ignore
/tests              export-ignore
/.editorconfig      export-ignore
/.env.testing       export-ignore
/.env.testing.slic  export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/codeception.dist.yml   export-ignore
/codeception.slic.yml   export-ignore
/cspell.json        export-ignore
/engineering-plan.md    export-ignore
/phpstan.neon.dist  export-ignore
```

- [ ] **Step 6: Write `.editorconfig`**

```editorconfig
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = tab

[*.{yml,yaml,json,md}]
indent_style = space
indent_size = 4

[*.md]
trim_trailing_whitespace = false
```

- [ ] **Step 7: Write `cspell.json`**

The spec review flagged that domain vocabulary trips the editor spell-checker. Fix the dictionary, not the prose.

```json
{
    "version": "0.2",
    "language": "en",
    "words": [
        "absorber",
        "Codeception",
        "codeception",
        "fatals",
        "Kadence",
        "learndash",
        "multisite",
        "Nexcess",
        "nexcess",
        "Packagist",
        "phpstan",
        "referer",
        "slic",
        "stellarwp",
        "StellarWP",
        "Strauss",
        "unhookable",
        "uopz",
        "Uopz",
        "wpunit"
    ],
    "ignorePaths": [
        "vendor/**",
        "tests/_output/**",
        "composer.lock"
    ]
}
```

- [ ] **Step 8: Add the GPL-2.0-or-later `LICENSE`**

```bash
curl -sL https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt -o LICENSE
head -3 LICENSE   # expect: GNU GENERAL PUBLIC LICENSE / Version 2, June 1991
```

- [ ] **Step 9: Write the README skeleton**

Every later PR appends its own section here, so the README is never out of sync with what has shipped. Keep it dense — no prose introduction, no FAQ.

```markdown
# Plugin Absorber

Safely load bundled WordPress plugins inside a host plugin — togglable or always-on — without
re-declaration fatal errors.

## Install

```bash
composer require stellarwp/plugin-absorber
```

**Use [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).**
Two or more plugins shipping different versions of this library will collide otherwise.

> **Do not let `extra.strauss.constant_prefix` rewrite a sub-plugin's `plugin_loaded_constant`.**
> Those are real, shared runtime constants — the whole safety mechanism depends on the bundled
> copy and the standalone defining the *same* name. Add them to `exclude_from_copy`.

Requires PHP 7.4+ and WordPress 6.4+.

## Usage

_Added as each piece lands._
```

- [ ] **Step 10: Verify Composer accepts the manifest**

Run: `composer validate --no-check-lock`
Expected: `./composer.json is valid`

- [ ] **Step 11: Commit**

```bash
git add composer.json LICENSE .gitignore .gitattributes .editorconfig cspell.json README.md
git commit -m "Add repo skeleton: composer manifest, license, dotfiles, README stub"
```

- [ ] **Step 12: Push and open the PR**

```bash
git push -u origin 01-repo-bootstrap
gh pr create --base main --title "Repo bootstrap" --body 'What: composer manifest, GPL-2.0 license, dotfiles, cspell dictionary, README skeleton.

Usage:

    composer require stellarwp/plugin-absorber

Why this way: `stellarwp/plugin-absorber` over `sub-plugin-loader` — Packagist returns 21 hits for
`plugin-loader` and the top five are WordPress mu-plugin autoloaders, so the term is taken and
misleading; `plugin-absorber` returns zero. Dropping `-loader` also kills the `PluginAbsorber\Loader`
stutter. `stellarwp/container-contract` is the only production dependency, matching uplink.

Verify: `composer validate --no-check-lock`. No tests — nothing here has behavior. The test harness
lands in the next PR and CI goes green in the one after.'
```

---

## Task 2: Codeception harness

**PR 2** · branch `02-codeception-harness` from `01-repo-bootstrap` · 0 source files

Configuration modelled on `stellarwp/harbor`, the closest sibling library. The `singlesite`/`multisite` envs matter — Task 12 needs multisite to test network-aware deactivation.

**Files:**
- Create: `codeception.dist.yml`, `codeception.slic.yml`, `tests/unit.suite.yml`, `tests/_bootstrap.php`, `tests/_support/UnitTester.php`, `tests/_support/Helper/Unit.php`, `.env.testing`, `.env.testing.slic`, `tests/_output/.gitkeep`

**Interfaces:**
- Consumes: the `Nexcess\PluginAbsorber\Tests\` autoload-dev root from Task 1.
- Produces: a `unit` suite runnable as `slic run unit`, with `--env singlesite` and `--env multisite`. Every later test class extends `\Codeception\TestCase\WPTestCase` and lives in `tests/unit/`.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 01-repo-bootstrap && git checkout -b 02-codeception-harness
```

- [ ] **Step 2: Write `codeception.dist.yml`**

```yaml
actor: Tester
bootstrap: _bootstrap.php
paths:
    tests: tests
    output: tests/_output
    data: tests/_data
    support: tests/_support
    envs: tests/_envs
actor_suffix: Tester
settings:
    colors: true
    memory_limit: 1024M
    error_level: E_ALL
params:
    - .env.testing
extensions:
    enabled:
        - Codeception\Extension\RunFailed
```

- [ ] **Step 3: Write `codeception.slic.yml`**

slic overlays this file to swap in the containerised database host.

```yaml
params:
    - .env.testing.slic
```

- [ ] **Step 4: Write `tests/unit.suite.yml`**

`multisite: false` is the default; the `multisite` env flips it. Note this suite loads WordPress — it is a "unit" suite by name only, matching the `admin-notices` convention.

```yaml
# Codeception Test Suite Configuration
# Loads a real WordPress via WPLoader. uopz stubs the functions WP cannot hook.
actor: UnitTester
bootstrap: _bootstrap.php
modules:
    enabled:
        - WPLoader
        - Asserts
        - "Nexcess\\PluginAbsorber\\Tests\\Support\\Helper\\Unit"
    config:
        WPLoader:
            wpRootFolder: "%WP_ROOT_FOLDER%"
            dbName: "%WP_TEST_DB_NAME%"
            dbHost: "%WP_TEST_DB_HOST%"
            dbUser: "%WP_TEST_DB_USER%"
            dbPassword: "%WP_TEST_DB_PASSWORD%"
            tablePrefix: test_
            domain: "%WP_DOMAIN%"
            adminEmail: admin@plugin-absorber.test
            title: "Plugin Absorber Tests"
            theme: twentytwentythree
            multisite: false

env:
    singlesite:
    multisite:
        modules:
            config:
                WPLoader:
                    multisite: true
```

- [ ] **Step 5: Write `tests/_bootstrap.php`**

```php
<?php
/**
 * Codeception bootstrap.
 *
 * @package Nexcess\PluginAbsorber
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
```

- [ ] **Step 6: Write `tests/_support/UnitTester.php`**

```php
<?php
/**
 * Unit suite actor.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

/**
 * Inherited methods are provided by Codeception at build time.
 *
 * @since 1.0.0
 */
class UnitTester extends \Codeception\Actor {
	use _generated\UnitTesterActions;
}
```

- [ ] **Step 7: Write `tests/_support/Helper/Unit.php`**

```php
<?php
/**
 * Unit suite helper.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Helper;

use Codeception\Module;

/**
 * Suite-level helper. Intentionally empty — per-test seams live in traits.
 *
 * @since 1.0.0
 */
class Unit extends Module {
}
```

- [ ] **Step 8: Write `.env.testing`**

```dotenv
WP_ROOT_FOLDER=/var/www/html
WP_DOMAIN=plugin-absorber.test
WP_URL=http://plugin-absorber.test
WP_ADMIN_USERNAME=admin
WP_ADMIN_PASSWORD=password
WP_TABLE_PREFIX=wp_
WP_DB_HOST=db
WP_DB_NAME=test
WP_DB_USER=root
WP_DB_PASSWORD=password
WP_TEST_DB_HOST=db
WP_TEST_DB_NAME=test
WP_TEST_DB_USER=root
WP_TEST_DB_PASSWORD=password
```

- [ ] **Step 9: Write `.env.testing.slic`**

```dotenv
# Consumed by both CI and local slic runs.
WP_VERSION=latest
WP_ROOT_FOLDER=/var/www/html
WP_URL=http://plugin-absorber.test
WP_DOMAIN=plugin-absorber.test
WP_ADMIN_USERNAME=admin
WP_ADMIN_PASSWORD=password
WP_TABLE_PREFIX=wp_
WP_DB_PORT=3306
WP_DB_HOST=db
WP_DB_NAME=test
WP_DB_USER=root
WP_DB_PASSWORD=password
WP_TEST_DB_HOST=db
WP_TEST_DB_NAME=test
WP_TEST_DB_USER=root
WP_TEST_DB_PASSWORD=password
USING_CONTAINERS=1
```

- [ ] **Step 10: Create the output directory placeholder**

```bash
mkdir -p tests/_output tests/_data tests/unit && touch tests/_output/.gitkeep
```

- [ ] **Step 11: Verify Codeception builds the actor**

```bash
slic here
slic use plugin-absorber
slic composer install
slic cc build
```
Expected: `tests/_support/_generated/UnitTesterActions.php` is generated, no errors.

- [ ] **Step 12: Commit**

```bash
git add codeception.dist.yml codeception.slic.yml tests/ .env.testing .env.testing.slic
git commit -m "Add Codeception harness with singlesite and multisite envs"
```

- [ ] **Step 13: Push and open the PR**

```bash
git push -u origin 02-codeception-harness
gh pr create --base 01-repo-bootstrap --title "Codeception harness" --body 'What: Codeception + wp-browser config for a WPLoader-backed `unit` suite, with `singlesite` and `multisite` envs.

Usage:

    slic run unit
    slic run unit --env multisite

Why this way: modelled on `stellarwp/harbor`, the closest sibling library, rather than invented.
The suite is named `unit` but loads real WordPress — that is the `admin-notices` convention, and it
lets the Notices and Activation tests exercise real transients and options instead of mocks. The
`multisite` env exists because network-aware deactivation cannot be tested any other way.

Verify: `slic cc build` generates the actor. No test assertions yet — the first green run is the
next PR, which adds the smoke test and the CI workflow together.'
```

---

## Task 3: First green CI

**PR 3** · branch `03-ci-tests` from `02-codeception-harness` · 1 source file

**Files:**
- Create: `.github/workflows/tests-php.yml`, `tests/unit/SmokeTest.php`, `tests/_support/TestException.php`, `tests/README.md`

**Interfaces:**
- Consumes: the `unit` suite from Task 2.
- Produces: no local uopz trait. Every later test that stubs a WordPress function uses `lucatume\WPBrowser\Traits\UopzFunctions` from wp-browser — `setFunctionReturn( string $function, $value, bool $execute = false )`, with automatic teardown via the trait's own `@after resetUopzAlterations()`. Also produces `Nexcess\PluginAbsorber\Tests\Support\TestException`, thrown from a stubbed function to halt a code path in place of `exit`.

**Why not a local trait:** a hand-rolled `WithUopz` is duplicated across every StellarWP plugin repo and drifts. `UopzFunctions` is maintained by wp-browser's author, is already in the dependency tree, and exists as far back as the `^3.6.5` floor this library pins. Nothing to keep in sync.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 02-codeception-harness && git checkout -b 03-ci-tests
```

- [ ] **Step 2: Write the failing smoke test**

```php
<?php
/**
 * Verifies the harness itself before any library code depends on it.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class SmokeTest extends WPTestCase {
	use UopzFunctions;

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	public function test_uopz_is_available(): void {
		$this->assertTrue( extension_loaded( 'uopz' ), 'uopz is required to stub WordPress functions.' );
		$this->assertTrue( function_exists( 'uopz_set_return' ) );
	}

	public function test_uopz_can_stub_a_function(): void {
		$this->setFunctionReturn( 'wp_get_referer', 'https://example.test/wp-admin/plugins.php' );

		$this->assertSame( 'https://example.test/wp-admin/plugins.php', wp_get_referer() );
	}
}
```

There is deliberately no test that `exit` can be neutralised. See Step 4.

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `wp_get_referer()` returns the real value, so `test_uopz_can_stub_a_function` fails on the `assertSame`, until `use UopzFunctions` is in place.

- [ ] **Step 4: Add `TestException` and the tests README**

There is no local uopz trait to write. `use lucatume\WPBrowser\Traits\UopzFunctions;` in the smoke test is the entire change on the stubbing side: it ships with wp-browser, undoes every override via its own `@after resetUopzAlterations()`, and takes an explicit `$execute` flag instead of guessing whether a value is callable.

`UopzFunctions::preventExit()` exists, but this library does not use it. Neutralising `exit` lets a test keep running past the point where production would have stopped, so a test that should fail can report as passing and CI will not say otherwise. Tasks 8 and 13 instead stub the call immediately before `exit` and throw from it, which stops execution at a point the test controls.

```php
<?php
/**
 * Exception used to halt execution in place of exit().
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Exception;

/**
 * Thrown from a stubbed function to stop a code path right before it calls exit().
 *
 * Mocking exit() itself lets a test keep running past the point production would
 * have halted, which can report a failing test as passing. Throwing instead stops
 * execution at a point the test controls. See tests/README.md.
 *
 * @since 1.0.0
 */
class TestException extends Exception {
}
```

Also write `tests/README.md`, documenting how to run the suites, the `setFunctionReturn()` pattern, and the no-mocking-`exit` rule with a worked example. Tasks 8 and 13 follow it rather than rediscovering it.


- [ ] **Step 5: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 3 tests, 5 assertions.

- [ ] **Step 6: Write the tests CI workflow**

Adapted from `stellarwp/harbor`'s `tests-php.yml`, running the ends of the supported PHP range against WordPress `latest` and `nightly` — four legs, with the `nightly` ones non-blocking. Testing only 7.4 and 8.5 is deliberate: a deprecation introduced at any PHP version fires on every later one, so 8.5 catches whatever 8.0–8.4 would, and `config.platform.php` pins dependency resolution to 7.4 on every leg regardless of runtime, so there is no per-version dependency drift to catch either.

```yaml
# cspell:ignore DotReporter
name: PHP Tests

on:
  pull_request:
  push:
    branches:
      - main

# This workflow only reads the repository. Narrow the token accordingly.
permissions:
  contents: read

# A superseded PR run is a result nobody is waiting on any more. Pushes to a
# long-lived branch are left alone so their history stays complete.
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}

jobs:
  test:
    runs-on: ubuntu-latest
    timeout-minutes: 20

    # A broken WordPress nightly is upstream's problem, not this library's, so
    # those legs report without blocking the PR.
    continue-on-error: ${{ matrix.wp == 'nightly' }}

    strategy:
      fail-fast: false
      matrix:
        # The ends of the supported range only. A deprecation introduced at any
        # PHP version fires on every later one, so 8.5 catches what 8.0-8.4
        # would, and every leg resolves identical dependencies because
        # config.platform.php pins resolution to 7.4 regardless of runtime.
        php:
          - "7.4"
          - "8.5"
        # The nightly column is early warning for core regressions; when a
        # nightly leg is red, its latest counterpart on the same PHP tells you
        # whether WordPress or PHP is at fault.
        wp:
          - "latest"
          - "nightly"

    name: "Tests: PHP ${{ matrix.php }} / WP ${{ matrix.wp }}"

    steps:
      - name: Checkout the repository
        uses: actions/checkout@v6
        with:
          fetch-depth: 1

      - name: Checkout slic
        uses: actions/checkout@v6
        with:
          repository: stellarwp/slic
          ref: main
          path: slic
          fetch-depth: 1

      # Codeception refuses to start unless register_argc_argv is On. slic's
      # php.ini does not set it, so the base image default applies, and that
      # differs between PHP versions -- 7.4 is On, 8.5 is Off. This file is
      # bind-mounted into the slic container as conf.d/zz-docker.ini, which
      # loads after the main php.ini, so appending here wins.
      - name: Enable register_argc_argv for Codeception
        run: echo "register_argc_argv=On" >> ${GITHUB_WORKSPACE}/slic/containers/slic/php.ini

      - name: Set up slic env vars
        run: |
          echo "SLIC_BIN=${GITHUB_WORKSPACE}/slic/slic" >> $GITHUB_ENV
          echo "SLIC_WP_DIR=${GITHUB_WORKSPACE}/slic/_wordpress" >> $GITHUB_ENV
          echo "SLIC_WORDPRESS_DOCKERFILE=Dockerfile.base" >> $GITHUB_ENV

      - name: Set run context for slic
        run: echo "SLIC=1" >> $GITHUB_ENV && echo "CI=1" >> $GITHUB_ENV

      - name: Start ssh-agent
        run: |
          eval `ssh-agent -s`
          echo "SSH_AUTH_SOCK=${SSH_AUTH_SOCK}" >> $GITHUB_ENV

      - name: Set up slic for CI
        run: |
          cd ${GITHUB_WORKSPACE}/..
          ${SLIC_BIN} here
          ${SLIC_BIN} interactive off
          ${SLIC_BIN} build-prompt off
          ${SLIC_BIN} build-subdir off
          ${SLIC_BIN} xdebug off
          ${SLIC_BIN} debug on
          ${SLIC_BIN} php-version set ${{ matrix.php }} --skip-rebuild

      - name: Set up the library
        run: |
          ${SLIC_BIN} use plugin-absorber
          ${SLIC_BIN} composer set-version 2
          ${SLIC_BIN} composer validate
          ${SLIC_BIN} composer install

      # The slic image ships a fixed WordPress that varies by PHP version, and
      # WP_VERSION in .env.testing.slic does not change it. Without this step a
      # leg named "WP latest" silently tests whatever core the image happened to
      # bake in. WPLoader installs from this codebase, so pinning here is what
      # actually puts the suite on the version the leg claims.
      - name: Pin the WordPress version
        run: ${SLIC_BIN} site-cli core update --version=${{ matrix.wp }} --force

      - name: Build codeception
        run: ${SLIC_BIN} cc build

      - name: Run unit tests (singlesite)
        run: ${SLIC_BIN} run unit --env singlesite --ext DotReporter

      # Run even when singlesite failed: one run should report both envs rather
      # than making you fix one and rediscover the other.
      - name: Run unit tests (multisite)
        if: ${{ !cancelled() }}
        run: ${SLIC_BIN} run unit --env multisite --ext DotReporter

      - name: Upload test output
        if: failure()
        uses: actions/upload-artifact@v7
        with:
          name: "test-output-php${{ matrix.php }}-wp${{ matrix.wp }}"
          path: tests/_output
          if-no-files-found: ignore
          retention-days: 7
```

- [ ] **Step 7: Commit**

```bash
git add tests/unit/SmokeTest.php tests/_support/TestException.php tests/README.md .github/workflows/tests-php.yml
git commit -m "Add harness smoke test, exit policy, and PHP tests workflow"
```

- [ ] **Step 8: Push and confirm CI is actually green**

```bash
git push -u origin 03-ci-tests
gh pr create --base 02-codeception-harness --title "First green CI" --body 'What: a smoke test that proves the harness, the `TestException` and README that set the stubbing rules, and the PHP tests workflow.

Usage:

    class SomeTest extends WPTestCase {
        use UopzFunctions; // from wp-browser, not a local trait.

        public function test_something(): void {
            $this->setFunctionReturn( "is_plugin_active", true );
        }
    }

Why this way: the smoke test asserts WordPress is loaded, uopz is present, and a function can
actually be stubbed — the assumptions every later test rests on. Proving them here means a later
failure is a real bug rather than a harness problem.

No local `WithUopz`: `lucatume\WPBrowser\Traits\UopzFunctions` ships with wp-browser, is maintained
by its author, undoes overrides through its own `@after`, and exists as far back as the `^3.6.5`
floor this library pins. One less copy to drift across repos.

`exit` is never mocked. Neutralising it lets a test keep running past the point production would
have stopped, so a test that should fail can report as passing. Redirect branches are tested by
stubbing the call immediately before `exit` and throwing `TestException` from it — worked example
in tests/README.md.

Verify: `slic run unit` — 3 tests. CI runs both envs across PHP 7.4 through 8.5 against WordPress
latest and nightly — four legs, with the nightly ones non-blocking. Static analysis is not
wired yet; it lands after the first src/ file, because PHPStan errors on an empty directory.'

gh run watch
```
Expected: all matrix legs green. **Do not proceed until they are** — every later task assumes this harness works.

---

## Task 4: `Config`

**PR 4** · branch `04-config` from `03-ci-tests` · 3 source files

**Files:**
- Create: `src/Config.php`, `src/Exceptions/Config_Exception.php`, `tests/unit/ConfigTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Config_Exception extends \RuntimeException`
  - `Config::set_hook_prefix( string ): void` — throws `Config_Exception` on characters outside `[a-zA-Z0-9_-]`
  - `Config::get_hook_prefix(): string` — throws `Config_Exception` when unset
  - `Config::set_version( string ): void` / `Config::get_version(): string`
  - `Config::set_container( ContainerInterface ): void` / `get_container(): ?ContainerInterface` / `has_container(): bool`
  - `Config::reset(): void`

  Every later task calls `Config::set_hook_prefix()` in `setUp()` and `Config::reset()` in `tearDown()`.

> **Deviation from the engineering plan, deliberate:** the plan's sketch throws bare
> `RuntimeException`. This throws `Config_Exception`, which extends `RuntimeException`, so the
> documented contract still holds while callers get one catchable type across the whole library.

> **Second deviation, deliberate (added 2026-08-03):** `set_hook_prefix()` also rejects the empty
> string. The character-class check alone would accept `''` — it contains no invalid character —
> and the failure would resurface at `get_hook_prefix()` as the misleading "You must call
> `Config::set_hook_prefix()`" long after the real mistake.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 03-ci-tests && git checkout -b 04-config
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\DI52\Container;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * @since 1.0.0
 */
class ConfigTest extends WPTestCase {
	public function tearDown(): void {
		Config::reset();
		parent::tearDown();
	}

	public function test_it_stores_and_returns_the_hook_prefix(): void {
		Config::set_hook_prefix( 'give' );

		$this->assertSame( 'give', Config::get_hook_prefix() );
	}

	public function test_it_accepts_letters_numbers_hyphens_and_underscores(): void {
		Config::set_hook_prefix( 'give-recurring_2' );

		$this->assertSame( 'give-recurring_2', Config::get_hook_prefix() );
	}

	/**
	 * @dataProvider invalid_hook_prefixes
	 *
	 * @param string $prefix Prefix under test.
	 */
	public function test_it_rejects_invalid_hook_prefixes( string $prefix ): void {
		$this->expectException( Config_Exception::class );

		Config::set_hook_prefix( $prefix );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function invalid_hook_prefixes(): array {
		return [
			'slash'     => [ 'give/recurring' ],
			'space'     => [ 'give recurring' ],
			'dot'       => [ 'give.recurring' ],
			'backslash' => [ 'give\\recurring' ],
		];
	}

	public function test_it_throws_when_the_hook_prefix_was_never_set(): void {
		$this->expectException( Config_Exception::class );

		Config::get_hook_prefix();
	}

	public function test_it_stores_and_returns_the_version(): void {
		Config::set_version( '3.0.0' );

		$this->assertSame( '3.0.0', Config::get_version() );
	}

	public function test_the_version_defaults_to_an_empty_string(): void {
		$this->assertSame( '', Config::get_version() );
	}

	public function test_it_reports_no_container_by_default(): void {
		$this->assertFalse( Config::has_container() );
		$this->assertNull( Config::get_container() );
	}

	public function test_it_stores_and_returns_a_container(): void {
		$container = new Container();

		Config::set_container( $container );

		$this->assertTrue( Config::has_container() );
		$this->assertSame( $container, Config::get_container() );
	}

	public function test_reset_clears_every_value(): void {
		Config::set_hook_prefix( 'give' );
		Config::set_version( '3.0.0' );
		Config::set_container( new Container() );

		Config::reset();

		$this->assertSame( '', Config::get_version() );
		$this->assertFalse( Config::has_container() );
		$this->assertNull( Config::get_container() );

		$this->expectException( Config_Exception::class );
		Config::get_hook_prefix();
	}
}
```

> **CORRECTION (2026-07-31, verified against vendor/):** `lucatume\DI52\Container` does **not**
> implement `StellarWP\ContainerContract\ContainerInterface`. It implements `ArrayAccess` and
> **PSR's** `Psr\Container\ContainerInterface`. `stellarwp/container-contract` ships an adapter
> example at `examples/di52/Container.php` precisely because DI52 must be wrapped.
> `new Container()` therefore cannot be passed to `Config::set_container()` — it is a `TypeError`.
>
> Tests must use the test-support adapter `Nexcess\PluginAbsorber\Tests\Support\Test_Container`
> (wraps a DI52 container, implements the StellarWP contract's four methods: `bind`, `get`,
> `has`, `singleton`). This affects **Task 4 and Task 10** — both of their test blocks below still
> show the incorrect `use lucatume\DI52\Container;`. `Config::set_container()`'s signature is
> unchanged: the StellarWP contract stays the public API, per the Global Constraint that
> `stellarwp/container-contract` is the only production dependency.

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Config" not found`.

- [ ] **Step 4: Write `src/Exceptions/Config_Exception.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Exceptions;

use RuntimeException;

/**
 * Thrown when the library is configured incorrectly.
 *
 * Extends RuntimeException so callers may catch either type.
 *
 * @since 1.0.0
 */
class Config_Exception extends RuntimeException {
}
```

- [ ] **Step 5: Write `src/Config.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * Static configuration facade.
 *
 * @since 1.0.0
 */
class Config {
	/**
	 * @var string
	 */
	protected static $hook_prefix = '';

	/**
	 * @var string
	 */
	protected static $version = '';

	/**
	 * @var ContainerInterface|null
	 */
	protected static $container = null;

	/**
	 * Set the unique per-host slug that keys hooks, transients, and the activation option.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Host slug.
	 *
	 * @throws Config_Exception When the prefix contains unsupported characters.
	 *
	 * @return void
	 */
	public static function set_hook_prefix( string $prefix ): void {
		if ( preg_match( '/[^a-zA-Z0-9_-]/', $prefix ) ) {
			throw new Config_Exception(
				'Hook prefix must only contain letters, numbers, hyphens, and underscores.'
			);
		}

		self::$hook_prefix = $prefix;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no prefix has been set.
	 *
	 * @return string
	 */
	public static function get_hook_prefix(): string {
		if ( self::$hook_prefix === '' ) {
			throw new Config_Exception(
				'You must call Config::set_hook_prefix() before booting the Plugin Absorber.'
			);
		}

		return self::$hook_prefix;
	}

	/**
	 * @since 1.0.0
	 *
	 * @param string $version Host plugin version.
	 *
	 * @return void
	 */
	public static function set_version( string $version ): void {
		self::$version = $version;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_version(): string {
		return self::$version;
	}

	/**
	 * Share the host's container so collaborators become bindable.
	 *
	 * Entirely optional — with no container the library instantiates its own defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Host container.
	 *
	 * @return void
	 */
	public static function set_container( ContainerInterface $container ): void {
		self::$container = $container;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return ContainerInterface|null
	 */
	public static function get_container(): ?ContainerInterface {
		return self::$container;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_container(): bool {
		return self::$container !== null;
	}

	/**
	 * Reset all static state. Test seam.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$hook_prefix = '';
		self::$version     = '';
		self::$container   = null;
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 13 tests (4 from the data provider).

- [ ] **Step 7: Append to the README**

Replace the `_Added as each piece lands._` placeholder with:

```markdown
### Configure

```php
use Nexcess\PluginAbsorber\Config;

Config::set_hook_prefix( 'give' );          // required — keys hooks, transients, options
Config::set_version( GIVE_VERSION );        // optional
Config::set_container( give()->container ); // optional — see Rebinding below
```

The hook prefix accepts letters, numbers, hyphens, and underscores. Anything else throws
`Config_Exception`, as does reading it before it is set.
```

- [ ] **Step 8: Commit**

```bash
git add src/Config.php src/Exceptions/Config_Exception.php tests/unit/ConfigTest.php README.md
git commit -m "Add Config facade and Config_Exception"
```

- [ ] **Step 9: Push and open the PR**

```bash
git push -u origin 04-config
gh pr create --base 03-ci-tests --title "Config facade" --body 'What: `Config` static facade — hook prefix, version, optional container — plus `Config_Exception`.

Usage:

    Config::set_hook_prefix( "give" );
    Config::set_container( give()->container ); // optional

Why this way: the plan sketched bare `RuntimeException`; this throws `Config_Exception extends
RuntimeException`, so the documented contract still holds and callers get one catchable type across
the library. `set_hook_prefix()` validates eagerly rather than at use, because a bad prefix
otherwise surfaces as a silently-never-firing filter much later.

Verify: `slic run unit` — 13 tests covering the validation regex, the unset-prefix throw, container
storage, and `reset()`. `get_version()` is stored but not yet read by anything; see the spec is
known-issue F.'
```

---

## Task 5: Static analysis in CI

**PR 5** · branch `05-ci-static-analysis` from `04-config` · 2 source files

Lands here rather than in Task 1 because PHPStan errors on an empty `src/`.

**Files:**
- Create: `phpstan.neon.dist`, `.github/workflows/static-analysis.yml`

**Interfaces:**
- Consumes: `src/Config.php` from Task 4 — the first file to analyse.
- Produces: a green `composer test:analysis`. Every later task must keep it green.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 04-config && git checkout -b 05-ci-static-analysis
```

- [ ] **Step 2: Write `phpstan.neon.dist`**

Level 5 per the engineering plan. `szepeviktor/phpstan-wordpress` supplies WordPress function signatures.

```neon
includes:
	- vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
	phpVersion: 70400
	level: 5
	tmpDir: phpstan-cache
	treatPhpDocTypesAsCertain: false
	reportUnmatchedIgnoredErrors: false

	paths:
		- src

	scanDirectories:
		- vendor/stellarwp/container-contract/src
```

- [ ] **Step 3: Run it and confirm it is clean**

Run: `composer test:analysis`
Expected: `[OK] No errors`.

If `Config::$container` reports an unknown `ContainerInterface`, confirm
`stellarwp/container-contract` installed and that `scanDirectories` points at its real `src` path.

- [ ] **Step 4: Write the static analysis workflow**

Adapted from `stellarwp/harbor`'s `static-analysis.yml`.

```yaml
# cspell:ignore shivammathur ramsey reqs
name: PHPStan

on:
  pull_request:
  push:
    branches:
      - main

jobs:
  phpstan:
    name: phpstan
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v6

      - name: Configure PHP environment
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.0"
          extensions: mbstring, intl
          coverage: none

      - uses: ramsey/composer-install@v3
        with:
          composer-options: "--ignore-platform-reqs --optimize-autoloader"
          dependency-versions: highest

      - name: Restore PHPStan cache
        uses: actions/cache/restore@v4
        with:
          path: phpstan-cache
          key: v1-phpstan-${{ runner.os }}-${{ github.ref_name }}-${{ github.run_id }}
          restore-keys: |
            v1-phpstan-${{ runner.os }}-${{ github.ref_name }}-
            v1-phpstan-${{ runner.os }}-
            v1-phpstan-

      - name: Run PHPStan static analysis
        run: composer test:analysis

      - name: Save PHPStan cache
        uses: actions/cache/save@v4
        if: ${{ !cancelled() }}
        with:
          path: phpstan-cache
          key: v1-phpstan-${{ runner.os }}-${{ github.ref_name }}-${{ github.run_id }}
```

- [ ] **Step 5: Commit**

```bash
git add phpstan.neon.dist .github/workflows/static-analysis.yml
git commit -m "Add PHPStan level 5 and the static analysis workflow"
```

- [ ] **Step 6: Push, open the PR, confirm green**

```bash
git push -u origin 05-ci-static-analysis
gh pr create --base 04-config --title "Static analysis" --body 'What: PHPStan level 5 with `szepeviktor/phpstan-wordpress`, plus its CI workflow.

Usage:

    composer test:analysis

Why this way: level 5 per the engineering plan rather than harbor is level max — this library has
almost no generics or array shapes to model, so max would mostly generate baseline noise. It lands
now rather than in the bootstrap PR because PHPStan errors on an empty `src/`, so it needed a real
file to analyse.

Verify: `composer test:analysis` is clean. From this PR on, both workflows gate every merge.'

gh run watch
```

---

## Task 6: `Conflict_Policy`

**PR 6** · branch `06-conflict-policy` from `05-ci-static-analysis` · 2 source files

Split from `Sub_Plugin` so that PR 7 is purely predicate logic.

**Files:**
- Create: `src/Conflict_Policy.php`, `tests/unit/ConflictPolicyTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `Conflict_Policy::DEACTIVATE` (`'deactivate'`), `Conflict_Policy::DEFER` (`'defer'`), `Conflict_Policy::NOTICE_ONLY` (`'notice_only'`). Tasks 7 and 12 both depend on these exact string values.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 05-ci-static-analysis && git checkout -b 06-conflict-policy
```

- [ ] **Step 2: Write the failing test**

The values are asserted literally because they are a public contract — a host may store one in an option, and changing a value later would silently break it.

> **Deviation, deliberate (added 2026-08-03, from PR 6 review):** the class also ships
> `all(): string[]` and `is_valid( string ): bool`. Without them nothing rejects an unknown policy:
> `Sub_Plugin::get_conflict_policy()` returns whatever the config or the filter hands back, and
> `Conflict\Resolver::resolve()` switches on it with `default:` falling into `deactivate()`. A typo
> like `'defered'`, or a stale filter return, would therefore deactivate a plugin the site owner
> deliberately turned on — the most surprising and least recoverable of the three outcomes, reached
> by accident. Task 12 must call `is_valid()` and treat an unknown policy as its own case rather
> than relying on the fallthrough. The reflection test pins the constant set so a fourth policy
> cannot be added without that switch being revisited.

```php
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
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Conflict_Policy" not found`.

- [ ] **Step 4: Write `src/Conflict_Policy.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

/**
 * What to do when a sub-plugin's standalone counterpart is still active.
 *
 * @since 1.0.0
 */
final class Conflict_Policy {
	/**
	 * Deactivate the standalone, load the bundled copy, notify, redirect. The default.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DEACTIVATE = 'deactivate';

	/**
	 * Leave the standalone alone and let it win. The load guard stands the bundled copy down.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DEFER = 'defer';

	/**
	 * Leave the standalone active but ask the user to deactivate it.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const NOTICE_ONLY = 'notice_only';
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 2 tests.

- [ ] **Step 6: Append to the README**

```markdown
### Conflict policies

When a sub-plugin's standalone counterpart is still active:

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, load the bundled copy, notify, redirect. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |
```

- [ ] **Step 7: Commit, push, open the PR**

```bash
git add src/Conflict_Policy.php tests/unit/ConflictPolicyTest.php README.md
git commit -m "Add Conflict_Policy constants"
git push -u origin 06-conflict-policy
gh pr create --base 05-ci-static-analysis --title "Conflict policy constants" --body 'What: the three conflict-policy string constants.

Usage:

    "conflict_policy" => Conflict_Policy::DEACTIVATE,  // or DEFER, or NOTICE_ONLY

Why this way: string constants rather than an enum because the PHP floor is 7.4, and rather than
bare strings because a host may persist one in an option. The test asserts the literal values for
that reason — they are a public contract, not an implementation detail.

Verify: `slic run unit` — 2 tests. Split out from Sub_Plugin so that PR reviews as pure predicate
logic.'
```

---

## Task 7: `Sub_Plugin`

**PR 7** · branch `07-sub-plugin` from `06-conflict-policy` · 2 source files

The largest genuine review surface in the project. Every per-sub-plugin decision lives here so the collaborators stay thin and every predicate is testable without hooks.

**Files:**
- Create: `src/Sub_Plugin.php`, `tests/_support/Traits/WithSubPlugins.php`, `tests/unit/SubPluginTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Config::get_hook_prefix()` (Task 4), `Conflict_Policy::*` (Task 6), `Config_Exception` (Task 4).
- Produces — every later task calls into these:
  - `__construct( array $config )` — throws `Config_Exception` if `slug`, `bundled_plugin_file`, or `plugin_loaded_constant` is empty
  - `get_slug(): string`, `get_bundled_plugin_file(): string`, `get_plugin_loaded_constant(): string`
  - `get_conflict_policy(): string`, `is_enabled(): bool`, `is_already_loaded(): bool`
  - `has_standalone_plugin(): bool`, `get_standalone_plugin_basename(): string`
  - `is_standalone_plugin_active(): bool`, `is_standalone_plugin_network_active(): bool`
  - `are_dependencies_met(): bool`
  - `get_conflict_notice_message(): string`, `get_dependency_notice_message(): string`
  - `get_activation_callback(): ?callable`
- Produces for the tests: `Tests\Support\Traits\WithSubPlugins` with
  `make_sub_plugin( array $overrides = [] ): Sub_Plugin`. Tasks 8, 10 and 13 use it instead of each
  declaring their own fixture helper.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 06-conflict-policy && git checkout -b 07-sub-plugin
```

- [ ] **Step 2: Write `tests/_support/Traits/WithSubPlugins.php`**

Every test from here on builds sub-plugins the same way, so the builder is a trait rather than a
private method each class repeats. `tests/_support` is already PSR-4 mapped to
`Nexcess\PluginAbsorber\Tests\Support\` (Task 1), so no composer change is needed.

```php
<?php
/**
 * Builds Sub_Plugin fixtures.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
trait WithSubPlugins {
	/**
	 * Build a well-formed sub-plugin, overriding only what the test is about.
	 *
	 * The two remaining required keys are derived from the slug, so fixtures for different
	 * sub-plugins never share a bundled file path or a guard constant. The constant carries a
	 * `_FIXTURE` suffix that nothing ever defines: `define()` lasts for the whole PHP process, so
	 * a default some other test defined would report `is_already_loaded()` true for the rest of
	 * the suite. Tests that need a defined constant pass their own name for it.
	 *
	 * Overrides are merged last, so an invalid value can still be handed to the constructor to
	 * assert that it is rejected; the derived defaults fall back to the default slug in that case.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 *
	 * @return Sub_Plugin
	 */
	protected function make_sub_plugin( array $overrides = [] ): Sub_Plugin {
		$slug = isset( $overrides['slug'] ) && is_string( $overrides['slug'] ) && '' !== $overrides['slug']
			? $overrides['slug']
			: 'give-recurring';

		return new Sub_Plugin(
			array_merge(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => "/tmp/{$slug}/{$slug}.php",
					'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
				],
				$overrides
			)
		);
	}
}
```

- [ ] **Step 3: Write the failing test**

`wp-admin/includes/plugin.php` is required in `setUp()` because uopz cannot stub a function that does not yet exist.

> **Deviations, deliberate (added 2026-08-03, from the PR 7 review):**
>
> 1. **`is_callable()` is no longer the type discriminator.** It returns true for *any* string
>    naming an existing function, and `conflict_policy` is explicitly designed to be readable from
>    an option. A stored value of `date` or `flush` would have been invoked rather than used —
>    a `TypeError` at `plugins_loaded` on PHP 8, a silent `''` on 7.4. Strings and bools are now
>    always values; only real callables are called.
> 2. **The constructor type-checks.** Required keys must be non-empty *strings*: an array survived
>    the old `empty()` check and then cast to `"Array"`, which every misconfigured sub-plugin would
>    have collided on as its registry key, activation key, and notice id. `dependency_check` and
>    `activation_callback` must be callable when present — `is_callable()` at read time conflated
>    "not configured" with "configured but uncallable", so a `dependency_check` pointing at a
>    private method reported dependencies *met* and let the load proceed into the fatal it exists
>    to prevent.
> 3. **The active check no longer ORs in `is_plugin_active_for_network()`.** Verified against core:
>    `is_plugin_active()` already does, so the OR was dead code costing a second
>    `get_site_option()` per sub-plugin per request. The test that pinned it described a state
>    WordPress cannot produce. *(The check has since moved off `Sub_Plugin` — see deviation 8.)*
> 4. **`get_conflict_notice_message()` takes a `$default`.** Task 14 has no fallback of its own, so
>    an unconfigured host would have been shown WordPress's raw fatal-error screen.
> 5. **The filter result is `is_scalar()`-guarded.** A filter returning `WP_Error` would otherwise
>    be a fatal on cast; `''` is simply not a valid policy and routes to the conservative branch.

> **Deviations, deliberate (added 2026-08-07, from the PR 7 follow-up review):**
>
> 6. **`Conflict_Policy` owns the default policy.** `get_conflict_policy()` used to name
>    `Conflict_Policy::DEACTIVATE` as its fallback, which put "which policy applies when none is
>    configured" in the object that merely holds one sub-plugin's config. It asks
>    `Conflict_Policy::default()` now. The value is unchanged, and the two fallbacks stay
>    deliberately different: *unconfigured* means the sub-plugin accepted the default, whereas an
>    *unrecognised* policy — the conservative `NOTICE_ONLY` branch a dispatching caller takes — is
>    a value nobody chose, and reading a typo as consent to deactivate is the outcome worth
>    refusing.
> 7. **`Plugin_State_Interface` is the library's only route to WordPress's plugin functions.**
>    `Sub_Plugin` was a config value object that also queried global plugin state and
>    `require_once`'d `wp-admin/includes/plugin.php`. That is a second reason to change, and it is
>    what forced this task's tests to stub WordPress functions to exercise plain config reads. The
>    gateway owns the reads *and* the deactivation, so the include exists once, guarded on
>    `deactivate_plugins` — a function the library actually calls, and still not `is_plugin_active`,
>    whose third-party shims would short-circuit the require. This supersedes the guard named in
>    deviation 4 of the original block.
> 8. **`is_standalone_plugin_active()` is deleted, not delegated.** Delegating would have bought
>    `Sub_Plugin` a collaborator to answer a question that was never about its configuration. It
>    keeps `has_standalone_plugin()` and `get_standalone_plugin_basename()` — which name the plugin
>    to ask about — and the consumer pairs them with the gateway. Wiring that up needs `Loader` and
>    `Conflict\Resolver`, neither of which exists at this task, so it lands with Task 12.
> 9. **`is_standalone_plugin_network_active()` is deleted outright.** No production caller ever
>    appeared. Its stated reason for existing — that `deactivate_plugins()` needs a computed
>    `$network_wide` — was disproved in Task 12: core's `null` default already covers both scopes,
>    and passing a computed `true` *skips* the blog branch.
>
> `Sub_Plugin` now makes no global WordPress call beyond the `defined()` that is intrinsic to it,
> and its tests no longer stub `is_plugin_active`.

> **Deviation, deliberate (added 2026-08-11, from the PR 8 review):**
>
> 10. **The fixture helper is a shared trait, not a private method copied into each test class.**
>     `make_sub_plugin()` moves to `tests/_support/Traits/WithSubPlugins.php`, keeping the
>     `$overrides` signature this task already had. Tasks 8, 10 and 13 each declared their own
>     helper, so the same scaffolding was about to be written a fourth time, and the reviewer asked
>     for the `$overrides` shape to be the one every test gets rather than the one this class
>     happened to have. The extraction lands here, at the bottom of the stack, so the later PRs
>     consume it; done in PR 8 instead, it would have meant reaching back to edit a test file that
>     its own base branch is still revising.
>
>     The trait derives the guard constant from the slug and suffixes it `_FIXTURE`, replacing the
>     per-class `_TEST`, `_NOTICES` and `_ACTIVATION` names. Nothing anywhere defines the derived
>     default, and that is the point: `define()` lasts for the whole PHP process, so a default that
>     some test defined would make `is_already_loaded()` report true for every test that ran after
>     it. Tests that need a defined constant pass their own name for it.

> **CORRECTION (2026-08-03, hit while implementing):** the fixture helper was first named `make()`,
> which collides with `Codeception\Test\Unit::make()` — a public method on `WPTestCase`'s ancestor.
> Declaring it `private` is a fatal at class-compile time: *"Access level to
> SubPluginTest::make() must be public"*. The suite does not fail, it fails to start. Renamed to
> `make_sub_plugin()`, which is the name `WithSubPlugins` carries. The constraint outlives the
> move: a trait method overrides the inherited one, so any fixture helper must still avoid
> Codeception's own `Unit` API — `make`, `makeEmpty`, `construct`, and `constructEmpty` are all
> taken.

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;
use lucatume\WPBrowser\Traits\UopzFunctions;

/**
 * @since 1.0.0
 */
class SubPluginTest extends WPTestCase {
	use UopzFunctions;
	use WithSubPlugins;

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	public function tearDown(): void {
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider required_keys
	 *
	 * @param string $missing_key Key to omit.
	 */
	public function test_it_requires_every_required_key( string $missing_key ): void {
		$config = [
			'slug'                   => 'give-recurring',
			'bundled_plugin_file'    => '/tmp/x.php',
			'plugin_loaded_constant' => 'X_VERSION',
		];
		unset( $config[ $missing_key ] );

		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( $missing_key );

		new Sub_Plugin( $config );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function required_keys(): array {
		return [
			'slug'                   => [ 'slug' ],
			'bundled_plugin_file'    => [ 'bundled_plugin_file' ],
			'plugin_loaded_constant' => [ 'plugin_loaded_constant' ],
		];
	}

	public function test_it_exposes_the_required_values(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertSame( 'give-recurring', $sub_plugin->get_slug() );
		$this->assertSame( '/tmp/give-recurring/give-recurring.php', $sub_plugin->get_bundled_plugin_file() );
		$this->assertSame( 'GIVE_RECURRING_VERSION_FIXTURE', $sub_plugin->get_plugin_loaded_constant() );
	}

	public function test_it_is_enabled_by_default(): void {
		$this->assertTrue( $this->make_sub_plugin()->is_enabled() );
	}

	public function test_it_honours_a_boolean_enabled_flag(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'enabled' => false ] )->is_enabled() );
	}

	public function test_it_resolves_a_callable_enabled_flag_at_call_time(): void {
		$switch     = false;
		$sub_plugin = $this->make_sub_plugin(
			[
				'enabled' => static function () use ( &$switch ) {
					return $switch;
				},
			]
		);

		$this->assertFalse( $sub_plugin->is_enabled() );

		$switch = true;

		$this->assertTrue( $sub_plugin->is_enabled(), 'The callable must be re-evaluated on each call.' );
	}

	public function test_it_reports_not_loaded_when_the_constant_is_undefined(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_NEVER_DEFINED' ] )->is_already_loaded() );
	}

	public function test_it_reports_loaded_once_the_constant_is_defined(): void {
		define( 'ABSORBER_TEST_LOADED_CONSTANT', '1.0.0' );

		$this->assertTrue( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_TEST_LOADED_CONSTANT' ] )->is_already_loaded() );
	}

	public function test_it_reports_no_standalone_when_the_basename_is_absent(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertFalse( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( '', $sub_plugin->get_standalone_plugin_basename() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_active() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_network_active() );
	}

	public function test_it_never_calls_wordpress_without_a_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$this->assertFalse(
			$this->make_sub_plugin()->is_standalone_plugin_active(),
			'Absent a standalone basename the predicate must short-circuit.'
		);
	}

	public function test_it_detects_a_normally_active_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
		$this->setFunctionReturn( 'is_plugin_active_for_network', false );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->is_standalone_plugin_active() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_network_active() );
	}

	public function test_it_detects_a_network_active_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', false );
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->is_standalone_plugin_active() );
		$this->assertTrue( $sub_plugin->is_standalone_plugin_network_active() );
	}

	public function test_it_detects_an_inactive_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', false );
		$this->setFunctionReturn( 'is_plugin_active_for_network', false );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertFalse( $sub_plugin->is_standalone_plugin_active() );
	}

	public function test_dependencies_are_met_without_a_check(): void {
		$this->assertTrue( $this->make_sub_plugin()->are_dependencies_met() );
	}

	public function test_it_honours_the_dependency_check(): void {
		$this->assertFalse(
			$this->make_sub_plugin( [ 'dependency_check' => static fn() => false ] )->are_dependencies_met()
		);
		$this->assertTrue(
			$this->make_sub_plugin( [ 'dependency_check' => static fn() => true ] )->are_dependencies_met()
		);
	}

	public function test_the_conflict_policy_defaults_to_deactivate(): void {
		$this->assertSame( Conflict_Policy::DEACTIVATE, $this->make_sub_plugin()->get_conflict_policy() );
	}

	public function test_it_resolves_a_string_conflict_policy(): void {
		$this->assertSame(
			Conflict_Policy::DEFER,
			$this->make_sub_plugin( [ 'conflict_policy' => Conflict_Policy::DEFER ] )->get_conflict_policy()
		);
	}

	public function test_it_resolves_a_callable_conflict_policy_and_passes_itself(): void {
		$received   = null;
		$sub_plugin = $this->make_sub_plugin(
			[
				'conflict_policy' => static function ( Sub_Plugin $passed ) use ( &$received ) {
					$received = $passed;

					return Conflict_Policy::NOTICE_ONLY;
				},
			]
		);

		$this->assertSame( Conflict_Policy::NOTICE_ONLY, $sub_plugin->get_conflict_policy() );
		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_filter_overrides_the_resolved_policy(): void {
		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function () {
				return Conflict_Policy::DEFER;
			}
		);

		$sub_plugin = $this->make_sub_plugin( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		$this->assertSame(
			Conflict_Policy::DEFER,
			$sub_plugin->get_conflict_policy(),
			'The filter runs after the config value and the callable, and wins.'
		);
	}

	public function test_the_filter_receives_the_sub_plugin(): void {
		$received = null;

		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function ( $policy, $passed ) use ( &$received ) {
				$received = $passed;

				return $policy;
			},
			10,
			2
		);

		$sub_plugin = $this->make_sub_plugin();
		$sub_plugin->get_conflict_policy();

		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_conflict_notice_message_defaults_to_empty(): void {
		$this->assertSame( '', $this->make_sub_plugin()->get_conflict_notice_message() );
	}

	public function test_it_resolves_conflict_notice_messages_from_strings_and_callables(): void {
		$this->assertSame(
			'Bundled now.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] )->get_conflict_notice_message()
		);
		$this->assertSame(
			'Deferred.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Deferred.' ] )->get_conflict_notice_message()
		);
	}

	public function test_the_dependency_notice_message_falls_back_to_a_default(): void {
		$this->assertSame(
			'give-recurring could not be loaded because its requirements are not met.',
			$this->make_sub_plugin()->get_dependency_notice_message()
		);
	}

	public function test_it_resolves_dependency_notice_messages_from_strings_and_callables(): void {
		$this->assertSame(
			'Needs WooCommerce.',
			$this->make_sub_plugin( [ 'dependency_notice_message' => 'Needs WooCommerce.' ] )->get_dependency_notice_message()
		);
		$this->assertSame(
			'Needs Give.',
			$this->make_sub_plugin( [ 'dependency_notice_message' => static fn() => 'Needs Give.' ] )->get_dependency_notice_message()
		);
	}

	public function test_the_activation_callback_is_null_by_default(): void {
		$this->assertNull( $this->make_sub_plugin()->get_activation_callback() );
	}

	public function test_it_returns_the_activation_callback(): void {
		$callback = static function () {};

		$this->assertSame( $callback, $this->make_sub_plugin( [ 'activation_callback' => $callback ] )->get_activation_callback() );
	}
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Sub_Plugin" not found`.

- [ ] **Step 5: Write `src/Sub_Plugin.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * One registered sub-plugin: its configuration and every decision about it.
 *
 * @since 1.0.0
 */
class Sub_Plugin {
	/**
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @throws Config_Exception When a required key is missing or empty.
	 */
	public function __construct( array $config ) {
		foreach ( [ 'slug', 'bundled_plugin_file', 'plugin_loaded_constant' ] as $required ) {
			if ( empty( $config[ $required ] ) ) {
				throw new Config_Exception( "Sub-plugin config is missing required key: {$required}" );
			}
		}

		$this->config = $config;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return (string) $this->config['slug'];
	}

	/**
	 * Absolute path to the bundled plugin's main file — the file we require_once.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_bundled_plugin_file(): string {
		return (string) $this->config['bundled_plugin_file'];
	}

	/**
	 * Constant both the bundled copy and the standalone define when they load.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_plugin_loaded_constant(): string {
		return (string) $this->config['plugin_loaded_constant'];
	}

	/**
	 * Resolve the policy from a string or callable, then let the filter override it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_conflict_policy(): string {
		$policy = $this->config['conflict_policy'] ?? Conflict_Policy::DEACTIVATE;

		if ( is_callable( $policy ) ) {
			$policy = $policy( $this );
		}

		return (string) apply_filters(
			Config::get_hook_prefix() . '/plugin_absorber/conflict_policy',
			$policy,
			$this
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = $this->config['enabled'] ?? true;

		return (bool) ( is_callable( $enabled ) ? $enabled() : $enabled );
	}

	/**
	 * True when the plugin's code is already present, from either copy. The fatal guard.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_already_loaded(): bool {
		return defined( $this->get_plugin_loaded_constant() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_standalone_plugin(): bool {
		return ! empty( $this->config['standalone_plugin_basename'] );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_standalone_plugin_basename(): string {
		return (string) ( $this->config['standalone_plugin_basename'] ?? '' );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_standalone_plugin_active(): bool {
		if ( ! $this->has_standalone_plugin() ) {
			return false;
		}

		$this->load_plugin_functions();

		return is_plugin_active( $this->get_standalone_plugin_basename() )
			|| is_plugin_active_for_network( $this->get_standalone_plugin_basename() );
	}

	/**
	 * Whether the standalone is network-activated.
	 *
	 * Deactivating it requires passing $network_wide to deactivate_plugins(); without that the
	 * call silently no-ops and the resolver redirects forever.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_standalone_plugin_network_active(): bool {
		if ( ! $this->has_standalone_plugin() ) {
			return false;
		}

		$this->load_plugin_functions();

		return is_plugin_active_for_network( $this->get_standalone_plugin_basename() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function are_dependencies_met(): bool {
		$check = $this->config['dependency_check'] ?? null;

		return is_callable( $check ) ? (bool) $check() : true;
	}

	/**
	 * Shown when the standalone is auto-deactivated, and when the user tries to re-activate it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_conflict_notice_message(): string {
		return $this->resolve_message( $this->config['conflict_notice_message'] ?? '' );
	}

	/**
	 * Shown when a dependency_check fails. Falls back to a generic, untranslated sentence —
	 * pass a callable returning __() to localise it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_dependency_notice_message(): string {
		$message = $this->resolve_message( $this->config['dependency_notice_message'] ?? '' );

		if ( $message !== '' ) {
			return $message;
		}

		return sprintf(
			'%s could not be loaded because its requirements are not met.',
			$this->get_slug()
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function get_activation_callback(): ?callable {
		$callback = $this->config['activation_callback'] ?? null;

		return is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Resolve a string-or-callable message.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $message Configured message.
	 *
	 * @return string
	 */
	private function resolve_message( $message ): string {
		return (string) ( is_callable( $message ) ? $message() : $message );
	}

	/**
	 * WordPress only loads these in the admin, and we run at plugins_loaded on every request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_plugin_functions(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 26 tests.

- [ ] **Step 7: Confirm static analysis is still clean**

Run: `composer test:analysis`
Expected: `[OK] No errors`.

- [ ] **Step 8: Append the config table to the README**

```markdown
### Sub-plugin configuration

| Key | Type | Required | Meaning |
|---|---|:--:|---|
| `slug` | `string` | ✔ | Unique id — registry key, notice id, activation-tracking key. |
| `bundled_plugin_file` | `string` | ✔ | Absolute path to the **bundled** plugin's main file. This is what gets `require_once`d. |
| `plugin_loaded_constant` | `string` | ✔ | A constant the plugin defines when it loads. Both copies must define the *same* name. `defined()` ⇒ skip, which is what prevents re-declaration fatals. **Load guard only.** |
| `standalone_plugin_basename` | `string` | | The standalone's `dir/file.php` basename. Used for `is_plugin_active()` and `deactivate_plugins()`. Omit when there is no standalone. **Detection only.** |
| `enabled` | `bool\|callable` | | `true` by default. A callable is re-evaluated on every load. |
| `conflict_policy` | `string\|callable` | | `Conflict_Policy::DEACTIVATE` by default. A `callable( Sub_Plugin ): string` decides at runtime. |
| `conflict_notice_message` | `string\|callable` | | Shown on auto-deactivation and on a re-activation attempt. Use a callable to defer `__()` past `init`. |
| `dependency_notice_message` | `string\|callable` | | Shown when `dependency_check` fails. Defaults to a generic English sentence. |
| `activation_callback` | `callable` | | Runs **exactly once, ever**, per slug. |
| `dependency_check` | `callable` | | Returns `bool`. Skips the load and queues a notice when false. |

The load guard and the standalone basename are deliberately **two separate keys**. No constant does
double duty as both a guard and a path resolver.
```

- [ ] **Step 9: Commit, push, open the PR**

```bash
git add src/Sub_Plugin.php tests/_support/Traits/WithSubPlugins.php tests/unit/SubPluginTest.php README.md
git commit -m "Add Sub_Plugin value object and its predicates"
git push -u origin 07-sub-plugin
gh pr create --base 06-conflict-policy --title "Sub_Plugin value object" --body 'What: the per-sub-plugin value object and every predicate the loader and resolver ask it.

Usage:

    $sub_plugin = new Sub_Plugin( [
        "slug"                       => "give-recurring",
        "bundled_plugin_file"        => GIVE_PLUGIN_DIR . "subs/give-recurring/give-recurring.php",
        "plugin_loaded_constant"     => "GIVE_RECURRING_VERSION",
        "standalone_plugin_basename" => "give-recurring/give-recurring.php",
        "enabled"                    => static fn() => give_is_gateway_enabled( "recurring" ),
        "conflict_policy"            => Conflict_Policy::DEACTIVATE,
    ] );

    $sub_plugin->is_already_loaded();               // defined( GIVE_RECURRING_VERSION )
    $sub_plugin->is_standalone_plugin_active();     // is_plugin_active() || ..._for_network()

Why this way: every predicate lives here rather than in the collaborators, so the collaborators stay
thin and each decision is testable without wiring a hook. `is_standalone_plugin_network_active()` is
an addition to the engineering plan — the plan detects network activation but then calls
`deactivate_plugins()` without `$network_wide`, which silently no-ops on multisite and makes the
resolver redirect forever. PR 12 consumes this.

`dependency_notice_message` is the other addition: the plan defines `queue_dependency_notice()` but
no message for it to render, so as written it could only ever queue an empty notice. Default is
untranslated English by design — the library ships no text domain, so localisation is the host is
job via a callable.

Verify: `slic run unit` — 26 tests, including the policy resolution order (config -> callable ->
filter, last wins) and both network-activation branches.'
```

---

## Task 8: `Registrar`

**PR 8** · branch `08-registrar` from `07-sub-plugin` · 3 source files

**Files:**
- Create: `src/Contracts/Registrar_Interface.php`, `src/Registrar.php`, `tests/unit/RegistrarTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Sub_Plugin` (Task 7), and the `WithSubPlugins` trait (Task 7) for its fixtures — the test builds no sub-plugin of its own.
- Produces: `Registrar_Interface` with `register( Sub_Plugin $sub_plugin ): void`, `all(): array` returning `array<string,Sub_Plugin>` keyed by slug, and `reset(): void`. Task 9 resolves this interface; Tasks 11 and 12 iterate `all()`.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 07-sub-plugin && git checkout -b 08-registrar
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * @since 1.0.0
 */
class RegistrarTest extends WPTestCase {
	use WithSubPlugins;

	public function test_it_starts_empty(): void {
		$this->assertSame( [], ( new Registrar() )->all() );
	}

	public function test_it_keys_registrations_by_slug(): void {
		$registrar  = new Registrar();
		$sub_plugin = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );

		$registrar->register( $sub_plugin );

		$this->assertSame( [ 'give-recurring' => $sub_plugin ], $registrar->all() );
	}

	public function test_it_keeps_multiple_registrations(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );

		$this->assertCount( 2, $registrar->all() );
		$this->assertArrayHasKey( 'give-recurring', $registrar->all() );
		$this->assertArrayHasKey( 'give-fee-recovery', $registrar->all() );
	}

	public function test_registering_the_same_slug_twice_lets_the_last_one_win(): void {
		$registrar = new Registrar();
		$first     = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );
		$second    = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );

		$registrar->register( $first );
		$registrar->register( $second );

		$this->assertCount( 1, $registrar->all() );
		$this->assertSame( $second, $registrar->all()['give-recurring'] );
	}

	/**
	 * A host registers every bundled sub-plugin up front, then re-registers one with a narrower
	 * config once something it could not know at registration time resolves — a licence check, a
	 * site option, a settings save — so the same routine runs twice for one slug. The second call
	 * must update in place rather than move that slug behind the ones registered after it: for an
	 * add-on that extends another sub-plugin's class, that ordering is the difference between
	 * loading and a fatal.
	 */
	public function test_re_registering_keeps_the_original_position(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $registrar->all() )
		);
	}

	public function test_reset_empties_the_registry(): void {
		$registrar = new Registrar();
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$registrar->reset();

		$this->assertSame( [], $registrar->all() );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Registrar" not found`.

- [ ] **Step 4: Write `src/Contracts/Registrar_Interface.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Holds the registered sub-plugins. Bind a replacement to change registration behavior globally.
 *
 * @since 1.0.0
 */
interface Registrar_Interface {
	/**
	 * Register a sub-plugin. Registering an existing slug replaces it.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void;

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin> Keyed by slug.
	 */
	public function all(): array;

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset(): void;
}
```

- [ ] **Step 5: Write `src/Registrar.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;

/**
 * Default registry: a plain slug => Sub_Plugin map.
 *
 * @since 1.0.0
 */
class Registrar implements Registrar_Interface {
	/**
	 * @var array<string,Sub_Plugin>
	 */
	private $sub_plugins = [];

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void {
		$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->sub_plugins = [];
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 6 tests.

- [ ] **Step 7: Commit, push, open the PR**

```bash
git add src/Contracts/Registrar_Interface.php src/Registrar.php tests/unit/RegistrarTest.php README.md
git commit -m "Add Registrar and its interface"
git push -u origin 08-registrar
gh pr create --base 07-sub-plugin --title "Registrar" --body 'What: the sub-plugin registry and its contract.

Usage:

    $registrar = new Registrar();
    $registrar->register( $sub_plugin );
    $registrar->all();   // [ "give-recurring" => Sub_Plugin ]

Why this way: keyed by slug so re-registering the same slug replaces rather than duplicates — a host
that conditionally registers in two code paths gets one entry, not two loads. The interface ships in
this PR rather than in a contracts-only PR so it arrives with an implementation and tests.

Verify: `slic run unit` — 6 tests.'
```

---

## Task 9: `Loader` resolution and registration

**PR 9** · branch `09-loader-resolve` from `08-registrar` · 2 source files

**Files:**
- Create: `src/Loader.php`, `tests/unit/LoaderResolveTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Config::get_container()` (Task 4), `Registrar_Interface`/`Registrar` (Task 8), `Sub_Plugin` (Task 7).
- Produces:
  - `Loader::resolve( string $interface, string $default_class ): object` — private; container-or-`new`, memoized
  - `Loader::registrar(): Registrar_Interface`
  - `Loader::register( array $config ): void`
  - `Loader::all(): array`
  - `Loader::reset(): void` — clears the memo **and** the registry

  Tasks 10, 12 and 13 each add one accessor alongside their own interface.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 08-registrar && git checkout -b 09-loader-resolve
```

- [ ] **Step 2: Write the failing test**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\DI52\Container;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Registrar;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
class LoaderResolveTest extends WPTestCase {
	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
	}

	public function tearDown(): void {
		Loader::reset();
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @param string $slug Sub-plugin slug.
	 *
	 * @return array<string,mixed>
	 */
	private function config( string $slug ): array {
		return [
			'slug'                   => $slug,
			'bundled_plugin_file'    => "/tmp/{$slug}/{$slug}.php",
			'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION',
		];
	}

	public function test_it_falls_back_to_the_default_registrar_without_a_container(): void {
		$this->assertInstanceOf( Registrar::class, Loader::registrar() );
	}

	public function test_it_memoizes_the_resolved_collaborator(): void {
		$this->assertSame( Loader::registrar(), Loader::registrar() );
	}

	public function test_it_resolves_a_bound_registrar_from_the_container(): void {
		$bound = new class() implements Registrar_Interface {
			/** @var array<string,Sub_Plugin> */
			public $sub_plugins = [];

			public function register( Sub_Plugin $sub_plugin ): void {
				$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
			}

			public function all(): array {
				return $this->sub_plugins;
			}

			public function reset(): void {
				$this->sub_plugins = [];
			}
		};

		$container = new Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		$this->assertSame( $bound, Loader::registrar() );
	}

	public function test_it_ignores_a_container_with_no_binding(): void {
		Config::set_container( new Container() );

		$this->assertInstanceOf( Registrar::class, Loader::registrar() );
	}

	public function test_register_builds_a_sub_plugin_and_stores_it(): void {
		Loader::register( $this->config( 'give-recurring' ) );

		$all = Loader::all();

		$this->assertCount( 1, $all );
		$this->assertInstanceOf( Sub_Plugin::class, $all['give-recurring'] );
		$this->assertSame( 'give-recurring', $all['give-recurring']->get_slug() );
	}

	public function test_register_delegates_to_a_bound_registrar(): void {
		$bound = new class() implements Registrar_Interface {
			/** @var array<string,Sub_Plugin> */
			public $sub_plugins = [];

			public function register( Sub_Plugin $sub_plugin ): void {
				$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
			}

			public function all(): array {
				return $this->sub_plugins;
			}

			public function reset(): void {
				$this->sub_plugins = [];
			}
		};

		$container = new Container();
		$container->singleton( Registrar_Interface::class, static fn() => $bound );
		Config::set_container( $container );

		Loader::register( $this->config( 'give-recurring' ) );

		$this->assertArrayHasKey( 'give-recurring', $bound->sub_plugins );
	}

	public function test_reset_clears_both_the_memo_and_the_registry(): void {
		Loader::register( $this->config( 'give-recurring' ) );
		$first = Loader::registrar();

		Loader::reset();

		$this->assertSame( [], Loader::all(), 'The registry must be empty after reset.' );
		$this->assertNotSame( $first, Loader::registrar(), 'The memo must be discarded after reset.' );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Loader" not found`.

- [ ] **Step 4: Write `src/Loader.php`**

Only resolution and registration in this PR. `boot()` and the load loop land in Task 11.

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;

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
	 * Resolve an interface from the container when bound, else construct the default.
	 *
	 * The container is never required — with none set, every collaborator is a plain `new`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $interface     Interface to resolve.
	 * @param string $default_class Concrete class to build when nothing is bound.
	 *
	 * @return object
	 */
	private static function resolve( string $interface, string $default_class ): object {
		if ( isset( self::$resolved[ $interface ] ) ) {
			return self::$resolved[ $interface ];
		}

		$container = Config::get_container();

		if ( $container !== null && $container->has( $interface ) ) {
			self::$resolved[ $interface ] = $container->get( $interface );

			return self::$resolved[ $interface ];
		}

		self::$resolved[ $interface ] = new $default_class();

		return self::$resolved[ $interface ];
	}

	/**
	 * @since 1.0.0
	 *
	 * @return Registrar_Interface
	 */
	public static function registrar(): Registrar_Interface {
		/** @var Registrar_Interface $registrar */
		$registrar = self::resolve( Registrar_Interface::class, Registrar::class );

		return $registrar;
	}

	/**
	 * Register one bundled sub-plugin. Call once per sub-plugin, before boot().
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @return void
	 */
	public static function register( array $config ): void {
		self::registrar()->register( new Sub_Plugin( $config ) );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public static function all(): array {
		return self::registrar()->all();
	}

	/**
	 * Discard every resolved collaborator and the registry. Test seam.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		if ( isset( self::$resolved[ Registrar_Interface::class ] ) ) {
			self::registrar()->reset();
		}

		self::$resolved = [];
	}
}
```

> `reset()` empties the registrar before discarding the memo. Dropping the memo alone is not
> enough: when the registrar came from a container binding as a singleton, the container hands back
> the same populated instance on the next resolve.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 7 tests.

- [ ] **Step 6: Confirm static analysis is still clean**

Run: `composer test:analysis`
Expected: `[OK] No errors`.

- [ ] **Step 7: Append to the README**

```markdown
### Rebinding a collaborator

Every collaborator is interface-backed. With a container set, bind any of them to override the
library globally; with no container, the defaults are used and nothing is required.

```php
$container->singleton( Registrar_Interface::class, My_Registrar::class );
Config::set_container( $container );
```

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | Holds the registered sub-plugins. |
| `Contracts\Notices_Interface` | `Notices` | Notice queue and the activation-error rewrite. |
| `Conflict\Resolver_Interface` | `Conflict\Resolver` | Standalone detection, deactivation, redirect. |
| `Contracts\Activation_Interface` | `Activation` | Run-once activation tracking. |

The container is **not** used to wire hooks — those stay plain static callbacks, so the container
stays genuinely optional.
```

- [ ] **Step 8: Commit, push, open the PR**

```bash
git add src/Loader.php tests/unit/LoaderResolveTest.php README.md
git commit -m "Add Loader resolution and registration"
git push -u origin 09-loader-resolve
gh pr create --base 08-registrar --title "Loader resolution and registration" --body 'What: `Loader::resolve()`, the `registrar()` accessor, `register()`, `all()`, and `reset()`.

Usage:

    Loader::register( [ "slug" => "give-recurring", ... ] );
    Loader::all();   // [ "give-recurring" => Sub_Plugin ]

    // Optional: override globally.
    $container->singleton( Registrar_Interface::class, My_Registrar::class );
    Config::set_container( $container );

Why this way: one generic `resolve( $interface, $default_class )` rather than four bespoke
accessors with their own fallback logic, so adding a collaborator is one line. The container is
checked with `has()` before `get()`, which is what keeps it optional — a host with no container, or
with one that binds nothing, gets plain `new` instances.

`reset()` empties the registrar before dropping the memo. Discarding the memo alone is not enough:
a container-bound singleton hands back the same populated instance on the next resolve, so the
registry would survive a reset and leak between tests.

Verify: `slic run unit` — 7 tests, covering both the bound and unbound paths with a real di52
container.'
```

---

## Task 10: `Notices` queue

**PR 10** · branch `10-notices-queue` from `09-loader-resolve` · 4 source files

Lands before the load path and the resolver because both call into it.

**Files:**
- Create: `src/Contracts/Notices_Interface.php`, `src/Notices.php`, `tests/unit/NoticesTest.php`
- Modify: `src/Loader.php` (add the `notices()` accessor), `README.md`

**Interfaces:**
- Consumes: `Config::get_hook_prefix()` (Task 4), `Sub_Plugin` message getters (Task 7), the `WithSubPlugins` trait (Task 7) for its fixtures, `Loader::resolve()` (Task 9).
- Produces:
  - `Notices_Interface` with `queue_merge_notice( Sub_Plugin ): void`, `queue_conflict_notice( Sub_Plugin ): void`, `queue_dependency_notice( Sub_Plugin ): void`, `render(): void`
  - `Loader::notices(): Notices_Interface`

  Task 11 calls `queue_dependency_notice()`; Task 12 calls `queue_merge_notice()` and `queue_conflict_notice()`; Task 14 extends this interface.

**Design notes:**
- Transient `"{$hook_prefix}_plugin_absorber_notices"`, no expiry, so the queue survives the resolver's `wp_safe_redirect()` and renders on the next admin load.
- Queue entries are keyed `"{$slug}:{$type}"`, not by slug alone. A sub-plugin can legitimately earn a merge notice at `plugins_loaded` @1 and a dependency notice at @2 in the same request; keying by slug alone would silently drop one.
- Default messages live **here**, not in `Sub_Plugin`. `get_conflict_notice_message()` returns `''` when unconfigured (Task 7 asserts this), and each notice type supplies its own fallback sentence — so auto-deactivating a plugin can never leave the user with no explanation.

- [ ] **Step 1: Cut the branch**

```bash
git checkout 09-loader-resolve && git checkout -b 10-notices-queue
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
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * @since 1.0.0
 */
class NoticesTest extends WPTestCase {
	use WithSubPlugins;

	private const TRANSIENT = 'give_plugin_absorber_notices';

	public function setUp(): void {
		parent::setUp();

		Config::set_hook_prefix( 'give' );
		delete_transient( self::TRANSIENT );
	}

	public function tearDown(): void {
		delete_transient( self::TRANSIENT );
		Loader::reset();
		Config::reset();
		parent::tearDown();
	}

	private function render_to_string( Notices $notices ): string {
		ob_start();
		$notices->render();

		return (string) ob_get_clean();
	}

	public function test_the_loader_resolves_the_default_notices(): void {
		$this->assertInstanceOf( Notices::class, Loader::notices() );
	}

	public function test_it_queues_a_merge_notice_into_the_transient(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$queue = get_transient( self::TRANSIENT );

		$this->assertIsArray( $queue );
		$this->assertArrayHasKey( 'give-recurring:merge', $queue );
		$this->assertSame( 'Bundled now.', $queue['give-recurring:merge'] );
	}

	public function test_the_merge_notice_falls_back_to_a_default_message(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin() );

		$queue = get_transient( self::TRANSIENT );

		$this->assertStringContainsString( 'give-recurring', $queue['give-recurring:merge'] );
		$this->assertNotSame( '', $queue['give-recurring:merge'] );
	}

	public function test_it_queues_a_conflict_notice(): void {
		( new Notices() )->queue_conflict_notice( $this->make_sub_plugin() );

		$this->assertArrayHasKey( 'give-recurring:conflict', get_transient( self::TRANSIENT ) );
	}

	public function test_it_queues_a_dependency_notice_using_the_sub_plugin_message(): void {
		( new Notices() )->queue_dependency_notice( $this->make_sub_plugin( [ 'dependency_notice_message' => 'Needs Give.' ] ) );

		$this->assertSame( 'Needs Give.', get_transient( self::TRANSIENT )['give-recurring:dependency'] );
	}

	public function test_the_dependency_notice_falls_back_to_the_sub_plugin_default(): void {
		( new Notices() )->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertSame(
			'give-recurring could not be loaded because its requirements are not met.',
			get_transient( self::TRANSIENT )['give-recurring:dependency']
		);
	}

	public function test_queueing_the_same_slug_and_type_twice_does_not_duplicate(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->assertCount( 1, get_transient( self::TRANSIENT ) );
	}

	public function test_one_slug_can_hold_notices_of_different_types(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );
		$notices->queue_dependency_notice( $this->make_sub_plugin() );

		$this->assertCount( 2, get_transient( self::TRANSIENT ) );
	}

	public function test_render_outputs_dismissible_warning_markup(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringContainsString( 'notice notice-warning is-dismissible', $output );
		$this->assertStringContainsString( 'Bundled now.', $output );
	}

	public function test_render_escapes_the_message(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => '<script>alert(1)</script>' ] ) );

		$output = $this->render_to_string( $notices );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_render_clears_the_queue(): void {
		$notices = new Notices();
		$notices->queue_merge_notice( $this->make_sub_plugin() );

		$this->render_to_string( $notices );

		$this->assertFalse( get_transient( self::TRANSIENT ) );
		$this->assertSame( '', $this->render_to_string( $notices ), 'A second render must output nothing.' );
	}

	public function test_render_outputs_nothing_when_the_queue_is_empty(): void {
		$this->assertSame( '', $this->render_to_string( new Notices() ) );
	}

	public function test_the_queue_survives_a_simulated_redirect(): void {
		( new Notices() )->queue_merge_notice( $this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] ) );

		// A redirect ends the request; the next one builds a fresh object against the same store.
		$output = $this->render_to_string( new Notices() );

		$this->assertStringContainsString( 'Bundled now.', $output );
	}
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `slic run unit`
Expected: FAIL — `Class "Nexcess\PluginAbsorber\Notices" not found`.

- [ ] **Step 4: Write `src/Contracts/Notices_Interface.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Admin notices raised by the absorber. Bind a replacement to render them your own way.
 *
 * @since 1.0.0
 */
interface Notices_Interface {
	/**
	 * Queue the "we deactivated the standalone for you" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Queue the "please deactivate the standalone yourself" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Queue the "requirements not met" notice.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void;

	/**
	 * Render every queued notice, then clear the queue.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void;
}
```

- [ ] **Step 5: Write `src/Notices.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Notices_Interface;

/**
 * Default notices: a transient-backed queue that survives the resolver's redirect.
 *
 * Deliberately minimal markup so the library stays dependency-free. A host already using
 * stellarwp/admin-notices can bind its own implementation and render from the same store.
 *
 * @since 1.0.0
 */
class Notices implements Notices_Interface {
	/**
	 * @var string
	 */
	private const TYPE_MERGE = 'merge';

	/**
	 * @var string
	 */
	private const TYPE_CONFLICT = 'conflict';

	/**
	 * @var string
	 */
	private const TYPE_DEPENDENCY = 'dependency';

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue(
			$sub_plugin,
			self::TYPE_MERGE,
			$this->message_or_default(
				$sub_plugin->get_conflict_notice_message(),
				sprintf(
					'%s has been deactivated because it is now bundled and loaded automatically.',
					$sub_plugin->get_slug()
				)
			)
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue(
			$sub_plugin,
			self::TYPE_CONFLICT,
			$this->message_or_default(
				$sub_plugin->get_conflict_notice_message(),
				sprintf(
					'%s is now bundled and loaded automatically. You can safely deactivate the standalone plugin.',
					$sub_plugin->get_slug()
				)
			)
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
		$this->queue( $sub_plugin, self::TYPE_DEPENDENCY, $sub_plugin->get_dependency_notice_message() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$queue = $this->get_queue();

		if ( $queue === [] ) {
			return;
		}

		delete_transient( $this->transient_name() );

		foreach ( $queue as $message ) {
			if ( $message === '' ) {
				continue;
			}

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Store one notice, keyed by slug and type so different types can coexist.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 * @param string     $type       Notice type.
	 * @param string     $message    Resolved message.
	 *
	 * @return void
	 */
	private function queue( Sub_Plugin $sub_plugin, string $type, string $message ): void {
		$queue = $this->get_queue();

		$queue[ $sub_plugin->get_slug() . ':' . $type ] = $message;

		set_transient( $this->transient_name(), $queue, 0 );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	private function get_queue(): array {
		$queue = get_transient( $this->transient_name() );

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * @since 1.0.0
	 *
	 * @param string $message Configured message.
	 * @param string $default Fallback used when nothing was configured.
	 *
	 * @return string
	 */
	private function message_or_default( string $message, string $default ): string {
		return $message !== '' ? $message : $default;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function transient_name(): string {
		return Config::get_hook_prefix() . '_plugin_absorber_notices';
	}
}
```

- [ ] **Step 6: Add the `notices()` accessor to `src/Loader.php`**

Insert after `registrar()`, and add `use Nexcess\PluginAbsorber\Contracts\Notices_Interface;` to the imports.

```php
	/**
	 * @since 1.0.0
	 *
	 * @return Notices_Interface
	 */
	public static function notices(): Notices_Interface {
		/** @var Notices_Interface $notices */
		$notices = self::resolve( Notices_Interface::class, Notices::class );

		return $notices;
	}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 13 tests.

- [ ] **Step 8: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 9: Commit, push, open the PR**

```bash
git add src/Contracts/Notices_Interface.php src/Notices.php src/Loader.php tests/unit/NoticesTest.php README.md
git commit -m "Add transient-backed notice queue"
git push -u origin 10-notices-queue
gh pr create --base 09-loader-resolve --title "Notice queue" --body 'What: the transient-backed notice queue, its contract, and the `Loader::notices()` accessor.

Usage:

    Loader::notices()->queue_merge_notice( $sub_plugin );
    Loader::notices()->render();   // hooked to admin_notices by boot()

    // Or supply your own copy:
    "conflict_notice_message" => static fn() => __( "Now bundled with Give.", "give" ),

Why this way: a transient rather than a static property, because the resolver deactivates the
standalone and then redirects — a static queue would not survive the request boundary and the user
would never see why their plugin turned off.

Entries are keyed `slug:type`, not by slug. A sub-plugin can earn a merge notice at plugins_loaded
@1 and a dependency notice at @2 in the same request; keying by slug alone silently drops one.

Default messages live here rather than in `Sub_Plugin`, which returns "" when unconfigured. That
way auto-deactivating a plugin can never leave the user with a blank explanation, while a host that
does configure a message still gets exactly its own text.

Verify: `slic run unit` — 13 tests, including escaping, queue-clearing on render, and a simulated
redirect (queue with one instance, render with another).'
```

---

## Task 11: `Loader` boot and load path

**PR 11** · branch `11-loader-load-path` from `10-notices-queue` · 2 source files

**Files:**
- Modify: `src/Loader.php`, `README.md`
- Create: `tests/unit/LoaderLoadTest.php`, `tests/unit/LoaderBootTest.php`

**Interfaces:**
- Consumes: `Sub_Plugin` predicates (Task 7), `Loader::notices()` (Task 10), `Config::get_hook_prefix()` (Task 4).
- Produces:
  - `Loader::boot(): void` — idempotent
  - `Loader::load_all(): void`
  - `Loader::render_notices(): void`
  - `Loader::load( Sub_Plugin ): void` — private
  - the `"{$prefix}/plugin_absorber/should_load"` filter, args `(bool $should_load, Sub_Plugin $sub_plugin)`

  Task 12 adds the `plugins_loaded` @1 hook to `boot()`; Task 13 adds the activation call to `load()`.

**Design note:** `boot()` wires only the @2 load hook and `admin_notices` in this PR. The @1 conflict-resolution hook arrives in Task 12 with the resolver it delegates to — wiring a trampoline to a collaborator that does not exist yet would not run.

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
		Loader::reset();
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
		remove_all_actions( 'admin_notices' );
		Loader::reset();
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

		$this->assertNotFalse( has_action( 'admin_notices', [ Loader::class, 'render_notices' ] ) );

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

Append these methods, and extend `reset()` as shown at the end:

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
			add_action( 'admin_notices', [ self::class, 'render_notices' ] );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_all(): void {
		foreach ( self::all() as $sub_plugin ) {
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
			Config::get_hook_prefix() . '/plugin_absorber/should_load',
			true,
			$sub_plugin
		);

		if ( ! $should_load ) {
			return;
		}

		require_once $sub_plugin->get_bundled_plugin_file();
	}
```

Then extend `reset()` so the boot flag clears too:

```php
	public static function reset(): void {
		if ( isset( self::$resolved[ Registrar_Interface::class ] ) ) {
			self::registrar()->reset();
		}

		self::$resolved = [];
		self::$booted   = false;
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `slic run unit`
Expected: PASS — 9 load tests + 3 boot tests.

- [ ] **Step 7: Confirm static analysis is still clean**

Run: `composer test:analysis`

- [ ] **Step 8: Append to the README**

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

- [ ] **Step 9: Commit, push, open the PR**

```bash
git add src/Loader.php tests/unit/LoaderLoadTest.php tests/unit/LoaderBootTest.php README.md
git commit -m "Add Loader boot and the load path"
git push -u origin 11-loader-load-path
gh pr create --base 10-notices-queue --title "Loader boot and load path" --body 'What: `boot()`, `load_all()`, the five-gate load path, and the `should_load` filter.

Usage:

    Loader::register( [ ... ] );
    Loader::boot();   // wires plugins_loaded @2 and admin_notices

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
- Create: `src/Conflict/Resolver_Interface.php`, `src/Conflict/Resolver.php`, `tests/unit/Conflict/ResolverTest.php`
- Modify: `src/Loader.php` (add `resolver()` and the @1 hook), `README.md`

**Interfaces:**
- Consumes: `Loader::all()` (Task 9), `Loader::notices()` (Task 10), `Sub_Plugin::is_standalone_plugin_active()` / `is_standalone_plugin_network_active()` / `get_conflict_policy()` (Task 7), `Conflict_Policy::*` (Task 6).
- Produces:
  - `Conflict\Resolver_Interface` with `resolve_all(): void`
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
		Loader::reset();
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

- [ ] **Step 4: Write `src/Conflict/Resolver_Interface.php`**

```php
<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

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

Add `use Nexcess\PluginAbsorber\Conflict\Resolver_Interface;` and `use Nexcess\PluginAbsorber\Conflict\Resolver;` to the imports, then:

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
- Consumes: `Config::get_hook_prefix()` (Task 4), `Sub_Plugin::get_activation_callback()` / `get_slug()` (Task 7), the `WithSubPlugins` trait (Task 7) for its fixtures, `Loader::resolve()` (Task 9).
- Produces: `Activation_Interface` with `maybe_run( Sub_Plugin $sub_plugin ): void`, and `Loader::activation(): Activation_Interface`.

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
		Loader::reset();
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
		return Config::get_hook_prefix() . '_plugin_absorber_activations';
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
- Modify: `src/Contracts/Notices_Interface.php`, `src/Notices.php`, `src/Loader.php`, `README.md`
- Create: `tests/unit/NoticesActivationErrorTest.php`

**Interfaces:**
- Consumes: `Loader::all()` (Task 9), `Sub_Plugin::get_standalone_plugin_basename()` / `get_conflict_notice_message()` (Task 7).
- Produces:
  - `Notices_Interface::filter_activation_error_markup( string $markup ): string` — **an addition to the interface shipped in Task 10**
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
		Loader::reset();
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

	public function test_it_escapes_the_replacement(): void {
		$this->register( [ 'conflict_notice_message' => '<script>alert(1)</script>' ] );

		$result = ( new Notices() )->filter_activation_error_markup( $this->wordpress_markup );

		$this->assertStringNotContainsString( '<script>', $result );
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

- [ ] **Step 4: Add the method to `src/Contracts/Notices_Interface.php`**

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

- [ ] **Step 5: Implement it in `src/Notices.php`**

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

		if ( $message === '' ) {
			return $markup;
		}

		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately matching WordPress's own string.
		$wordpress_text = __( 'Plugin could not be activated because it triggered a <strong>fatal error</strong>.', 'default' );

		return str_replace( $wordpress_text, esc_html( $message ), $markup );
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

And inside `boot()`'s `is_admin()` block, beside the existing `admin_notices` line:

```php
		if ( is_admin() ) {
			add_action( 'admin_notices', [ self::class, 'render_notices' ] );
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
git add src/Notices.php src/Contracts/Notices_Interface.php src/Loader.php tests/unit/NoticesActivationErrorTest.php README.md
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

This adds a method to `Notices_Interface`, which shipped in PR 10. Pre-1.0 with no consumers.

Verify: `slic run unit` — 8 tests, one per gate plus escaping and the Loader trampoline.'
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
		Loader::reset();
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
- **F** — `Config::get_version()` is stored but never read.
- **G** — PHPStan analyses `src/` only. Hand-written test-support code is thin for now, and adding
  `tests/` wholesale would drag in Codeception's generated actor and `WPTestCase` module magic —
  a well-known source of level-5 false positives. Once real support classes accumulate, put
  `tests/_support` alone under a second config rather than widening `paths`.

## Self-review

Checked against `docs/superpowers/specs/2026-07-31-plugin-absorber-design.md`:

- **Spec coverage.** All 16 PRs map to tasks 1–16. Amendment A → Task 14; C → Task 7
  (`is_standalone_plugin_network_active()`) + Task 12 (the `$network_wide` argument); D → Task 7
  (`get_dependency_notice_message()`) + Task 10 (rendering). Deferred B/E/F are recorded above and
  in the PR bodies that touch them. Every spec test bullet has a corresponding test method.
- **Interface consistency.** `Notices_Interface` grows one method in Task 14 — flagged in both the
  task and the PR body rather than left implicit. `Loader::reset()` is written once in Task 9 and
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
