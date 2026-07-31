# Design: `stellarwp/plugin-absorber`

Delivery design for the library specified in [`engineering-plan.md`](../../../engineering-plan.md).
That document defines *what* to build; this one records the decisions taken since, the
amendments to it, and *how* the work is sequenced into reviewable PRs.

Where the two disagree, this document wins.

---

## 1. Decisions locked

| Decision | Value |
|---|---|
| Composer package | `stellarwp/plugin-absorber` |
| Repository | `github.com/stellarwp/plugin-absorber` |
| Root namespace | `Nexcess\PluginAbsorber\` |
| Filter segment | `{hook_prefix}/plugin_absorber/…` |
| Option / transient | `{hook_prefix}_plugin_absorber_activations` / `…_notices` |
| PR strategy | Stacked branches, merged to `main` in order |
| Test strategy | Codeception + `wp-browser`, WPLoader (real WP) + `uopz` for unhookable functions |
| PR size cap | ≤ 10 files, tests excluded; no logic-bearing PR exceeds 4 source files |

### Naming rationale

`plugin-absorber` was chosen over `sub-plugin-loader`, `plugin-loader`, `sub-plugins`, and
`absorb`. Packagist returns 21 results for `plugin-loader`, of which the top five are all
WordPress plugin loaders meaning something else (`alleyinteractive/wp-plugin-loader`,
`boxuk/wp-muplugin-loader`, `humanmade/mu-plugins-loader`) — in WordPress, "plugin loader" is
established jargon for mu-plugin autoloading. `plugin-absorber` returns zero results.
It also avoids the `SubPluginLoader\Loader` stutter and matches the agent-noun shape of the
closest sibling library, `stellarwp/installer`.

`Sub_Plugin` remains the value-object name. "Sub-plugin" names *the registered thing*;
"absorb" names *what the library does to it*. Different concepts, different words.

---

## 2. Amendments to the engineering plan

### 2.1 Adopted

**A. Activation-error rewrite uses `wp_admin_notice_markup`, not an output buffer.**

The plan prescribes Kadence's `ob_start()` on `admin_head-plugins.php`. The newer reference —
LearnDash 4.21.4, `src/Core/Modules/Course_Grid/Legacy/Loader::update_legacy_plugin_activation_notice()` —
uses the `wp_admin_notice_markup` filter with the same nonce check against
`plugin-activation-error_{dir}/{file}` and the same `str_replace` of the `'default'`-domain
fatal string. The filter is strictly better: no buffering, no risk of mangling unrelated admin
output, and testable by calling the filter directly.

Consequences:
- `Notices_Interface` does **not** declare `start_buffer()`.
- `Loader::boot()` does **not** hook `admin_head-plugins.php`.
- The library requires **WordPress 6.4+** (when the filter landed). Stated in the README only —
  not enforceable through Composer, since WordPress is not a Composer dependency.

**C. `deactivate_plugins()` is network-aware.**

Both the plan and the LearnDash reference detect network activation via
`is_plugin_active_for_network()` but then call `deactivate_plugins( $basename )` with no
`$network_wide` argument. Against a network-activated plugin that call silently no-ops, so every
admin request re-attempts deactivation and re-redirects — an infinite redirect loop. This is
exactly what the plan's own E2E criterion ("reloading the plugins page doesn't loop") is meant
to catch, and it fails on multisite as written.

```php
deactivate_plugins(
    $sub_plugin->get_standalone_plugin_basename(),
    false,
    $sub_plugin->is_standalone_plugin_network_active()
);
```

`Sub_Plugin` gains `is_standalone_plugin_network_active(): bool`.

**D. `dependency_notice_message` added to the config schema.**

The plan defines `queue_dependency_notice()` but no message key for it to render, so as
specified it can only queue an empty notice. Added as `string|callable`, same shape as
`conflict_notice_message`, with a generic default when absent:

> "%s could not be loaded because its requirements are not met."

interpolating the sub-plugin slug.

### 2.2 Deferred — known issues, tracked for 1.0.1

**B. Conflict resolution runs on front-end requests.** `resolve_all()` is hooked at
`plugins_loaded` @1, which fires on every request. On a front-end hit `wp_get_referer()` returns
`false`, so `redirect_destination()` returns `admin_url( 'plugins.php' )` and a logged-out
visitor is bounced to the login screen. Wrapping `resolve_all()` in `is_admin()` would fix it and
is provably safe — on the front end the standalone loads first and defines the guard constant, so
`load()` skips the bundled copy and no fatal is possible. Deferred to stay faithful to both
reference implementations, which have the same behavior.

**E. `Activation::maybe_run()` can double-run under concurrency.** It reads the option, runs the
callback, then writes. Two simultaneous requests both observe the flag unset and both invoke the
callback — which may be `create_tables()`. Claiming the slot with `add_option()` before invoking
would close the window.

**F. `Config::get_version()` is never read.** Set by the consumer, unused by the library.

---

## 3. Architecture

Unchanged from the engineering plan except as amended above. Restated for self-containment.

```
Host bootstrap (plugins_loaded @0, or plugin-file top level)
  Config::set_hook_prefix( 'give' );
  Config::set_container( $container );        // optional
  Loader::register( [ …sub-plugin config… ] ); // once per sub-plugin
  Loader::boot();                              // idempotent

Hooks wired by boot():
  plugins_loaded @1      → Loader::run_conflict_resolution() → Conflict\Resolver::resolve_all()
  plugins_loaded @2      → Loader::load_all()
  admin_notices          → Loader::render_notices()          [is_admin() only]
  wp_admin_notice_markup → Loader::filter_activation_error_markup()  [is_admin() only]

load_all() per sub-plugin, in order:
  is_enabled()?                       skip if false
  are_dependencies_met()?             skip + queue dependency notice
  is_already_loaded()?                skip — constant defined, avoids re-declaration fatal
  file_exists( bundled_plugin_file )? skip
  …/plugin_absorber/should_load?      skip if false
  require_once bundled_plugin_file
  activation()->maybe_run( $sub_plugin )
```

### Collaborators

Each is interface-backed and container-resolvable through one `Loader::resolve( $interface,
$default_class )` helper: container binding if present, otherwise `new $default_class()`, memoized
either way. The container is optional and is **not** used to wire hooks — those stay plain static
trampolines, per the `admin-notices` precedent.

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | holds registered `Sub_Plugin` objects |
| `Contracts\Notices_Interface` | `Notices` | notice queue + activation-error rewrite |
| `Conflict\Resolver_Interface` | `Conflict\Resolver` | standalone detection, deactivation, redirect |
| `Contracts\Activation_Interface` | `Activation` | run-once activation-callback tracking |

### Config schema

Per the engineering plan, plus `dependency_notice_message`:

| Key | Type | Req |
|---|---|---|
| `slug` | string | ✔ |
| `bundled_plugin_file` | string | ✔ |
| `plugin_loaded_constant` | string | ✔ |
| `standalone_plugin_basename` | string | |
| `enabled` | bool \| callable | |
| `conflict_policy` | string \| `callable( Sub_Plugin ): string` | |
| `conflict_notice_message` | string \| callable | |
| `dependency_notice_message` | string \| callable | |
| `activation_callback` | callable | |
| `dependency_check` | callable | |

---

## 4. PR sequence

Stacked: each branch cuts from the previous and merges to `main` in order. `main` is releasable
after every merge. "Src" counts source files only — tests and test infrastructure are excluded
from the size cap.

| # | Branch | Src | Source files |
|---|---|---|---|
| 1 | `01-repo-bootstrap` | 6 | `composer.json`, `LICENSE`, `.gitignore`, `.gitattributes`, `.editorconfig`, README skeleton |
| 2 | `02-codeception-harness` | 0 | 8 test-infra files |
| 3 | `03-ci-tests` | 1 | `.github/workflows/tests-php.yml` |
| 4 | `04-config` | 3 | `Config`, `Exceptions\Config_Exception`, README |
| 5 | `05-ci-static-analysis` | 2 | `phpstan.neon.dist`, `.github/workflows/static-analysis.yml` |
| 6 | `06-conflict-policy` | 2 | `Conflict_Policy`, README |
| 7 | `07-sub-plugin` | 2 | `Sub_Plugin`, README |
| 8 | `08-registrar` | 3 | `Contracts\Registrar_Interface`, `Registrar`, README |
| 9 | `09-loader-resolve` | 2 | `Loader`, README |
| 10 | `10-notices-queue` | 4 | `Contracts\Notices_Interface`, `Notices`, `Loader` mod, README |
| 11 | `11-loader-load-path` | 2 | `Loader` mod, README |
| 12 | `12-conflict-resolver` | 4 | `Conflict\Resolver_Interface`, `Conflict\Resolver`, `Loader` mod, README |
| 13 | `13-activation` | 4 | `Contracts\Activation_Interface`, `Activation`, `Loader` mod, README |
| 14 | `14-activation-error-notice` | 3 | `Notices` mod, `Loader` mod, README |
| 15 | `15-e2e-fixtures` | 1 | README |
| 16 | `16-readme-release` | 3 | README, `CHANGELOG.md`, `.gitattributes` mod |

### Why this order

Dependency-forced, not arbitrary:

- PRs 1–3 exist to prove CI is green on an empty repo before any real code depends on the
  harness. PR 3 is the first green build.
- PHPStan (5) lands after the first `src/` file, because it errors on an empty `src/`.
- `Loader::load()` calls `notices()->queue_dependency_notice()`, and `Conflict\Resolver` calls
  `Loader::notices()`. Notices (10) therefore precedes the load path (11) and the Resolver (12).
- Each `Contracts\*` interface ships **with** its default implementation and its tests, rather
  than all four landing up front. `Loader::resolve()` is generic — `(interface, default_class)` —
  so it never needs the other interfaces to exist; only the accessors do, and each accessor lands
  with its pair.
- `Conflict_Policy` (6) is split from `Sub_Plugin` (7) so PR 7 is purely predicate logic, which is
  the part that needs real review attention.

---

## 5. Test coverage per PR

Every PR that ships behavior is covered. PRs 1, 2, 5 and 16 carry no tests of their own and this
is deliberate: 1 is boilerplate with no logic, 2 *is* the test harness, 5 is PHPStan
configuration (verified by CI running it), and 16 is documentation plus the release tag.

`WithUopz` follows the established StellarWP trait (see
`learndash-seats-plus/tests/_support/Traits/WithUopz.php`). uopz is present in the slic image
(`containers/slic/docker-php-ext-uopz.ini`) with `uopz.exit=1`, so `exit` is live by default —
redirect tests call `uopz_allow_exit( false )` per test.

- **3 — smoke.** WP bootstrapped; `uopz` loaded; `uopz_allow_exit( false )` works.
- **4 — `Config`.** Prefix regex rejects invalid characters (throws); `get_hook_prefix()` throws
  when unset; version set/get; container set/get/has; `reset()` clears all three.
- **6 — `Conflict_Policy`.** Constant values; the three are distinct.
- **7 — `Sub_Plugin`.** Each of the three required keys missing throws `Config_Exception`;
  `is_enabled()` bool vs callable; `is_already_loaded()` reacts to `define()`;
  `is_standalone_plugin_active()` and `is_standalone_plugin_network_active()` against uopz-stubbed
  `is_plugin_active` / `is_plugin_active_for_network`; `are_dependencies_met()` with and without a
  callable; `get_conflict_policy()` for string, for callable, and with the
  `…/conflict_policy` filter overriding both; both message getters, string and callable.
- **8 — `Registrar`.** register; `all()`; last-wins dedupe by slug; `reset()`.
- **9 — `Loader::resolve()`.** No container → default instance; di52 container binding a custom
  `Registrar_Interface` → bound instance returned; memoized (identical instance twice);
  `Loader::reset()` clears both the memo and the registry.
- **10 — `Notices` queue.** Each queue method writes the transient; the same slug queued twice does
  not duplicate; `render()` emits escaped `notice notice-warning is-dismissible` markup and then
  clears the transient; empty queue emits nothing; dependency notice falls back to the default
  message when `dependency_notice_message` is absent.
- **11 — `Loader` load path.** `require_once` fires exactly once (fixture increments a global);
  skipped when disabled, when dependencies are unmet (and the notice is queued), when the constant
  is already defined, when the file is missing, and when `…/should_load` returns false; the filter
  receives `(bool, Sub_Plugin)`; `boot()` called twice wires each hook once, at priorities 1 and 2.
- **12 — `Conflict\Resolver`.** With `uopz_allow_exit( false )`: DEACTIVATE calls
  `deactivate_plugins` **with** the network flag when network-active and **without** when not,
  queues the merge notice, and redirects; DEFER no-ops; NOTICE_ONLY queues without deactivating; a
  callable `conflict_policy` selects the branch per sub-plugin; disabled sub-plugins are skipped;
  sub-plugins whose standalone is inactive are skipped. `redirect_destination()` across all five
  referrer cases: `false`, `update.php`, `update-core.php`, `plugins.php` (returns `false`, no
  redirect), and any other referrer (returned unchanged).
- **13 — `Activation`.** Runs exactly once ever per slug; a second load does not re-run; no
  callback → no option write; multiple slugs tracked independently; option namespaced by hook
  prefix.
- **14 — activation-error rewrite.** Markup is rewritten only when all three gates pass (plugins
  screen, `$_GET['plugin']` matching a registered standalone basename, valid nonce); each gate
  failing individually leaves markup untouched; a sub-plugin with no `conflict_notice_message`
  leaves markup untouched.
- **15 — end-to-end.** Against committed fixture plugins under `tests/_data/plugins/`
  (`absorber-host`, `fake-bundled` which defines the guard constant, `fake-standalone`): fresh load
  defines the constant and runs the activation callback once; a second request does not re-run it;
  DEACTIVATE deactivates the standalone and queues the merge notice with no fatal; a re-activation
  attempt yields the friendly message; DEFER leaves the standalone active and the bundled copy
  stood down; NOTICE_ONLY notices without deactivating; toggling `enabled` off loads nothing; two
  sub-plugins in one request produce no fatals.

---

## 6. README shape

Roughly 120 lines. Minimal, dense, no padding. In order:

1. One-sentence statement of what the library does.
2. `composer require stellarwp/plugin-absorber`, immediately followed by the Strauss
   recommendation, a link to `stellarwp/global-docs/docs/strauss-setup.md`, and the warning that
   `extra.strauss.constant_prefix` must **not** rewrite a sub-plugin's `plugin_loaded_constant` —
   those are real shared runtime constants.
3. The complete `Config` + `Loader::register()` + `Loader::boot()` bootstrap, one worked example.
4. The config-key table.
5. The three conflict policies, three lines.
6. The two extension seams — callable `conflict_policy` and the two filters — one snippet each.
7. Container rebinding, four lines.
8. WordPress 6.4+ / PHP 7.4+ requirement line.

Excluded: prose introduction, FAQ, "why this library", changelog narrative, anything that restates
a method signature already visible in the table.

---

## 7. PR body template

Four parts, nothing else:

```
What: one line.

Usage: the snippet this PR makes possible.

Why this way: the trade-off taken, and against what.

Verify: the command, and what is deliberately not covered.
```

No boilerplate headings, no restating the diff, no checklists.

---

## 8. Verification

Per PR: `composer test:analysis` clean (from PR 5 onward) and `slic run unit` green. Both gate in
CI.

At PR 16, before tagging `1.0.0`: the full E2E matrix from PR 15 passes in CI, and `main` is
green.

---

## 9. Open items

- ~~Packagist name `nexcess/plugin-absorber` under repository `stellarwp/plugin-absorber`.~~
  Resolved in PR 1: the package is `stellarwp/plugin-absorber`. Packagist protects a vendor prefix
  once anyone publishes under it, and `nexcess` is already held by `nexcess/magento-turpentine`
  (maintainer `miguelbalparda`), which is outside our control. The `stellarwp` vendor is already
  ours across 38 packages, so this ships without chasing access. The PHP namespace stays
  `Nexcess\PluginAbsorber\` — Composer does not require the two to match.
- The local working directory is still `sub-plugin-loader/` while the remote is
  `plugin-absorber`. Rename before PR 1.
- `engineering-plan.md` is committed at the repository root alongside this spec. PR 1 decides
  whether both move under `docs/` and whether `.gitattributes` marks them `export-ignore` so they
  stay out of consumer installs.
