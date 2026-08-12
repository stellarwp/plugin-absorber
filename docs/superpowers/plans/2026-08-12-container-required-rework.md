# Container-Required Rework

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the container mandatory, delete the machinery that existed only to make it optional, and
land the naming and responsibility corrections that the optional container was hiding.

**Status:** planned 2026-08-12. Nothing implemented. Branches `11-loader-load-path` … `16-readme-release`
are pushed with PRs open (11, 12, 14, 15, 16, 17) and will all be rewritten.

## Why

`Container\Resolution` exists to answer "container binding when bound, `new $default_class` otherwise".
That single requirement is what forces every collaborator to be constructible with no arguments, which is
what forces the `?Peer $peer = null` constructors and their `?? Resolution::peer()` accessors, which is
what makes a class named for an abstract noun feel necessary in the first place. Remove the optional
container and the whole chain goes with it.

Three surveys of the ecosystem, run 2026-08-12, say this is the normal shape and that it costs our hosts
nothing:

- **Optional is the outlier.** Across 19 vendored StellarWP packages: 10 have no container at all, 7
  require one and throw (`uplink`, `telemetry`, `schema`, `shepherd`, `harbor`, `validation`,
  `foundation`), and exactly one — `admin-notices` — is optional with a `new` fallback. We modelled
  `Resolution` on the single outlier.
- **The hosts qualify.** `learndash-core` ships `LearnDash\Core\Container extends DI52Container implements
  ContainerInterface`, already requires `stellarwp/container-contract` in production, exposes it as
  `App::container()`, and already hands it to Telemetry, Validation and Harbor. Give, Event Tickets and
  MemberDash are the same story. The plugins that lack a container are the *add-ons being absorbed*, not
  the hosts doing the absorbing.
- **The naming was unsupported.** A census of 3,343 classes across eight Nexcess/StellarWP codebases found
  zero classes named `Resolution`, zero named `Destination`, and zero named `Locator`. Abstract `-ion`
  nouns are folder names over agent-nouned classes — `Activation/` containing `Activator` and
  `Deactivator` — never class names themselves.

`learndash-core` also settles the question of whether this library is worth shipping: it already contains
**four hand-rolled copies** of it — ProPanel (`@since 4.17.0`), Hub (`4.18.0`), Course Grid (`4.21.4`),
Course Reviews (`4.25.1`) — 987 lines with no shared abstraction, wired on exactly our hooks:

```php
add_action( 'plugins_loaded', $this->container->callback( Loader::class, 'deactivate' ), 1 );
add_action( 'plugins_loaded', $this->container->callback( Loader::class, 'load' ), 2 );
add_filter( 'wp_admin_notice_markup', $this->container->callback( Loader::class, 'update_legacy_plugin_activation_notice' ) );
```

A fifth absorption is a fifth copy. Those four modules are the migration target, and what they get wrong
is the case for the library: all four call `deactivate_plugins()` **non-silent**, none has a capability or
interactive-request gate (deactivation runs on anonymous front-end requests), one stores its merge notice
in a **transient**, and all four derive the standalone basename by stripping `WP_PLUGIN_DIR` off the
**load-guard constant** — the double duty our invariants forbid.

## Decisions

### D1 — The container is required

`Config::get_container()` throws `Config_Exception` when unset, matching `Uplink\Config.php:64`,
`Telemetry\Config.php:71`, `Schema\Config.php:27`, `harbor/src/Harbor/Config.php:86`. `has_container()`
stays as the probe. `Config::set_container()` keeps its name — snake_case is the majority spelling in the
corpus — and keeps type-hinting `StellarWP\ContainerContract\ContainerInterface`.

Type-hinting the contract is safe under Strauss: the host prefixes both its own container and our copy of
the contract into one namespace, which is why Harbor's identical hint works in LearnDash, Give and
MemberDash today (`sfwd-lms/vendor-prefixed/stellarwp/harbor/src/Harbor/Config.php:7` and
`sfwd-lms/src/Core/Container.php:13` name the same class).

### D2 — `Container\Resolution` is deleted

Nothing replaces it. Collaborators are bound by a provider and read with `$container->get()`.
`Loader::registrar()` and `Loader::notices()` remain as public accessors, now reading from the container.
`tests/_support/Resolution_State.php` is deleted with it.

### D3 — One provider, our own one-method contract

`Contracts\Provider_Interface` declares `register(): void` and nothing else — Harbor's shape
(`harbor/src/Harbor/Contracts/Provider_Interface.php:5`), not Foundation's.

Foundation is rejected on three counts: a PHP **8.3** floor against our 7.4, a hard `lucatume/di52`
runtime dependency against our container-contract-only rule, and dead API — its `deferred`/`provides()`
are a fatal `TypeError` in practice, because di52 type-hints its own `ServiceProvider` base class which
Foundation's abstract does not extend.

### D4 — Hooks wire as closures over the container

`$container->callback()` is di52-specific and absent from `stellarwp/container-contract`, which declares
only `bind`, `get`, `has`, `singleton`. There are zero uses of `callback()` anywhere in the library corpus.
Harbor's idiom is what we copy:

```php
add_action( 'plugins_loaded', function () use ( $container ) {
    $container->get( Runner::class )->load_all();
}, 2 );
```

This keeps resolution lazy — registering hooks instantiates nothing — where `[ $resolved_object, 'method' ]`
would force every collaborator to be built at boot, as Telemetry does.

### D5 — No fallback `new` in constructors

Every collaborator takes required constructor arguments and is bound by the provider. The
`?Peer $peer = null` parameters and the `protected` accessors falling back to `Resolution::` are deleted.
This is platform-cloud's `AGENTS.md:97` rule verbatim: *"Do not make service dependencies nullable to
instantiate defaults inside constructors. The container should provide collaborators."*

**The line:** collaborators come from the container; value objects are constructed inline.
`Loader::register()` keeps `new Sub_Plugin( $config )` — one value object per host-supplied config array,
built at the call the host can see in its own stack trace, which is what lets registration validate
eagerly while resolving nothing. `throw new Config_Exception( … )` likewise. No `Sub_Plugin_Factory`: it
would put a container resolution in front of the one method that deliberately performs none.

### D6 — Names

| Was | Becomes | Reason |
|---|---|---|
| `Container\Resolution` | *deleted* | zero `-ion` class names in 3,343 |
| `Conflict\Destination` | `Conflict\Redirector` | `Login_Redirector::redirect_after_login(): string` is direct precedent for an agent noun that returns a URL and never navigates |
| `Plugin_State` | `Plugin_Deactivator` + `Plugin_Checker` | it takes an argument and mutates; fact nouns in this corpus report ambient state with no arguments |
| `Contracts\Plugin_State_Interface` | `Contracts\Plugin_Deactivator_Interface` + `Contracts\Plugin_Checker_Interface` | follows the split |
| `Activation` (unbuilt) | `Activator` | `Activation/` is a folder name over `Activator`/`Deactivator` |

Unchanged: `Config`, `Loader`, `Sub_Plugin`, `Conflict_Policy`, `Registrar`, `Conflict\Resolver`,
`Notices\Queue`, `Notices\Store`, `Notices\Renderer`.

**Naming rules this establishes**, for `CLAUDE.md`:

- The suffix names the collaborator's **role in the wiring**, not whether it causes a side effect. Six of
  ten agent-nouned classes in platform-cloud return a value rather than calling an API — that is the shape
  of a WordPress filter — and are still named `Redirector`, `Registrar`, `Granter`, `Highlighter`.
- **Bare until a sibling doing the same job lands.** `Conflict\Resolver` stays bare beside `Gatekeeper` and
  `Redirector`, because none of the three does the other's job; the qualifier arrives only with a second
  implementation of the *same* role, as LearnDash's `Scheduler` sits bare beside `Retry_Scheduler`.
  LearnDash repeats a namespace segment in only 10% of its classes.
- An abstract `-ion`/`-ance` noun is a **directory**, never a class.

### D7 — The load path splits out of `Loader`

`Loader` keeps the public API, the registration buffer, and one-line trampolines. Out go:

- **`Boot\Scheduler`** — `SEQUENCE`, `LOAD_PRIORITY`, `wiring_window_has_closed()`, and the inline
  fallback for a host that boots too late.
- **the load loop** — the gate chain (`is_enabled` → `is_already_loaded` → dependencies → `is_file` →
  `should_load` → `require_once` → activation callback).
- **`Conflict\Gatekeeper`** — `is_interactive_admin_request()` and `can_resolve_conflicts()`, which today
  sit on `Loader` so a host binding its own `Resolver_Interface` cannot drop either by omission. As a
  separately bound collaborator the same guarantee holds, because the trampoline resolves the gatekeeper
  rather than the resolver.

### D8 — The pending-registration buffer stays

`Loader::$pending` and `flush()` survive the container becoming mandatory, so `register()` still resolves
nothing and registration stays order-free.

Deleting them would be actively unsafe on our primary host: `App::container()` builds a container lazily
when none is set, and `sfwd_lms.php:116` then **replaces** it at `plugins_loaded` 0. Anything that touched
the container before that point holds an orphan whose bindings are discarded. A host registering at
plugin-file scope — which the spec sanctions — would register into the throwaway container and silently
load nothing.

### D9 — No admin-notices adapter

`AdminNotices::show()` does not persist anything: the host calls it every request and admin-notices draws
on `admin_notices`, with dismissal in per-user meta. Our merge notice is raised once, at deactivation, and
must survive to a later request — so the storage half cannot be delegated.

Worse, the obvious adapter is silently broken. Core fires `admin_notices` **before** `all_admin_notices`,
which is where we render; a renderer calling `AdminNotices::show()` registers one hook too late to be
drawn, and `Queue::render()` has already cleared the store. A once-only notice, lost.

So: no `Renderer_Interface`, no adapter class, no `class_exists()` guard, no `suggest` entry. `Queue::option_name()`
is already public for exactly this purpose, and `docs/notices.md` grows the fifteen lines a host needs to
read the option at `admin_init` and render it however it likes.

`Notices\Renderer` and `Notices\Store` still become container-bound, because **everything** does (D5) —
not as a special case for this.

### D10 — The exit rule stays; the boilerplate moves into a helper

We do **not** adopt platform-cloud's `platform_cloud_exit()` function seam. It is a test-only seam in
`src/`, which `CLAUDE.md` bans outright, and on PHP 7.4 we cannot write `never`, so it would need a
docblock-only `@phpstan-return never` plus a `files` autoload entry loaded on every request of every host.

We keep stubbing `wp_safe_redirect` and throwing `TestException`, but the discipline moves into one shared
trait so no test hand-rolls it and no test can silently drop the assertion that the request halted:

```php
protected function capture_redirect( callable $action ): string {
    // stub wp_safe_redirect -> record $location, throw TestException
    try {
        $action();
        $this->fail( 'Expected the action to redirect and terminate.' );
    } catch ( TestException $exception ) {
        // Terminated as expected.
    }

    return $location;
}
```

## What the host must now do

Documented in `README.md` and `docs/configuration.md`:

```php
add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'learndash' );
    Config::set_container( App::container() );

    Loader::register( [ /* … */ ] );
    Loader::boot();
}, 0 );
```

**Priority 0, in the host's own container block — not from a service provider.** LearnDash and MemberDash
both wire Harbor's `set_container()` at `plugins_loaded` **1**, which is where our conflict resolution
runs; a host copying that habit races us. Priority 0 is also after LearnDash replaces its container, so
the binding is the real one.

## Invariants to add or amend in `CLAUDE.md`

- **New:** never write a literal guard-constant name in `src/`. `learndash-core` runs Strauss with
  `constant_prefix: "LEARNDASH_"` and an empty exclude list, so a literal would be rewritten and the guard
  would stop matching. Constant names arriving as config *values* are safe, which is why the design holds.
- **New:** the naming rules from D6.
- **New:** collaborators from the container, value objects inline (D5).
- **Amend:** the architecture section's account of `Container\Resolution`, the four-collaborator table, and
  "the container is genuinely optional" throughout.
- **Amend:** `Notices\Store`, `Notices\Renderer` and `Conflict\Redirector` are no longer "defaulted eagerly
  with `?? new X()` because there is nothing to wait for" — that reasoning dies with D1.

## Documented losses and clashes

Called out in `docs/conflict-handling.md` and `docs/filters.md` so a migrating host is not surprised:

- **Version negotiation.** ProPanel deliberately does not deactivate a standalone at or above
  `3.0.0-dev`, using `get_plugin_data()` and `version_compare`. We keep this out of scope; the host
  expresses it by returning `Conflict_Policy::DEFER` from the `conflict_policy` filter after its own
  version check.
- **Renamed standalone directories.** Course Grid finds a standalone in a renamed directory by stripping
  `WP_PLUGIN_DIR` off its guard constant. Our string `standalone_plugin_basename` cannot, and the invariant
  forbidding one key from doing both jobs is why.
- **Filter polarity.** LearnDash gates modules on `learndash_module_{x}_disabled` where true means *do not
  load*; our `should_load` is true means *do load*. Wiring one to the other inverts the gate.
- **Detection is host-filterable.** `learndash-core` installs `option_active_plugins` and
  `site_option_active_sitewide_plugins` filters that inject and then strip a synthetic path
  (`includes/class-ld-lms.php:175`, `:5173`), so `is_plugin_active()` does not report what is in the
  database. `Plugin_Checker` is the seam a host rebinds to correct that.

## Branch surgery

Every branch from `11-loader-load-path` up is rewritten, so all six open PRs change.

1. Snapshot every remote tip first: `git branch -f backup/<name> origin/<branch>` for 11 … 16.
2. Rework `11-loader-load-path`, then `gh stack rebase --committer-date-is-author-date` upward.
3. On a conflict, continue with plain `git rebase --continue` — `gh stack rebase --continue` forwards the
   flag and exits 129 — then re-run the stack rebase for the branches above.
4. Force-push, then update each PR body to match what its branch now does.

## Tasks, in rebase order

Inventory figures below are measured, not estimated: 176 test methods exist across 12 classes; 31 break
under the mandatory container, 26 under the non-nullable constructors. PHPStan runs over `tests/` at
level 9, so most breakage surfaces from `composer test:analysis` without standing up WordPress.

### Task 11 — `11-loader-load-path`

- [ ] `Config::get_container()` throws; `has_container()` kept (it has no `src/` callers, but it is the probe a host uses).
- [ ] Delete `src/Container/Resolution.php` and `src/Container/`. Its only `Config::get_container()` call site goes with it.
- [ ] Add `Contracts\Provider_Interface` and `Provider`; bind each default only when `! $container->has( $id )`.
- [ ] Add `Boot\Scheduler` (`LOAD_PRIORITY`, `SEQUENCE`, `wiring_window_has_closed()`, inline fallback, hook wiring as closures).
- [ ] Add `Load\Runner` (`load_all()`, the gate chain, `has_hook_prefix()`).
- [ ] Split `Plugin_State` → `Plugin_Deactivator` + `Plugin_Checker` + `Traits\Loads_Plugin_Functions`; split the interface. **Note:** these files landed on branch 10, so the split appears in PR 11's diff.
- [ ] `Notices\Queue::__construct( Store $store, Renderer $renderer )` — required.
- [ ] Slim `Loader` to accessors, `register()`, `all()`, `flush()`, `boot()`, `render_notices()`.
- [ ] Tests: delete `Resolution_State`, drop its call from `Loader_State::reset():57`, add a `WithContainer` trait, move load/boot coverage into `Load\RunnerTest` and `Boot\SchedulerTest`, add `ProviderTest`, add `WithHaltedRedirects`.
- [ ] Delete, do not fix: `LoaderResolveTest::test_it_falls_back_to_the_default_registrar_without_a_container`, `::test_it_ignores_a_container_with_no_binding`, `PluginStateTest::test_it_constructs_without_arguments`.
- [ ] `Config_State::reset()` still resets the container to `null`; every test class sets one in `setUp()` instead.
- [ ] Docs on this branch: `CLAUDE.md`, `README.md:26`, `docs/configuration.md:17-18` and its "Rebinding a collaborator" section.

### Task 12 — `12-conflict-resolver`

- [ ] `Conflict\Destination` → `Conflict\Redirector`; bind it in `Provider` (it stops being a plain `new`).
- [ ] `Conflict\Resolver::__construct()` takes required `Plugin_Checker_Interface`, `Plugin_Deactivator_Interface`, `Queue_Interface`, `Redirector`; delete the `?? Resolution::` accessors.
- [ ] Move `is_interactive_admin_request()` / `can_resolve_conflicts()` into `Conflict\Gatekeeper`; the trampoline resolves the gatekeeper, so a host binding its own `Resolver_Interface` still cannot drop them.
- [ ] `Loader::plugin_state()` splits or drops; `Loader::resolver()` reads from the container.
- [ ] Tests: `ResolverTest` (29 methods, sets no container today), `Exposed_Resolver` (constructed zero-arg), `DestinationTest` → `RedirectorTest`.
- [ ] Fix the documented contradiction at `docs/configuration.md:39-42`, which says the capability gate lives in the resolver while the code and `docs/conflict-handling.md` say `Loader`.

### Task 13 — `13-activation`

- [x] `Activation` → `Activator`, `Activation_Interface` → `Activator_Interface`; constructor injection; bind in `Provider`.

### Task 14 — `14-activation-error-rewrite`

- [x] Rebase; de-null any collaborator constructor the branch adds; keep `wp_admin_notice_markup` wiring in `Boot\Scheduler`.

### Task 15 — `15-e2e-suite`

- [x] The end-to-end bootstrap now hands `Config::set_container()` a bare container and lets `Loader::boot()`
  run `Provider::register()` over it, which is the sequence a host runs. A test about a rebinding host binds
  its own implementations into that container first.

### Task 16 — `16-readme-release`

- [ ] Final docs pass. Container-optional survivors to rewrite: `CLAUDE.md` L69, L121, L124-125, L142, L158; `docs/configuration.md` L7, L21-22, L40-41, L125.
- [ ] README gains the priority-0 `Config::set_container()` call the branch previously deleted from its snippet.
- [ ] `docs/notices.md` grows the fifteen-line `admin_init` example for a host rendering through its own notice system.
- [ ] Keep the branch's deletion of the spec and the old plan; delete this plan file too once the stack lands.
