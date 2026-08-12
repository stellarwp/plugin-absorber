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

composer test:unit                # wraps `slic run unit`
composer test:analysis            # PHPStan level 9 — must stay green on every PR
```

`slic run` takes no `--filter`; narrowing is the positional `<test>` argument shown above.

`slic use <target>` only sees directories under slic's configured plugins dir. A checkout outside it
— a sibling git worktree, say — cannot be targeted until `slic here` is re-pointed at its parent.

PHPStan runs at **level 9** over `src` and `tests`, with `treatPhpDocTypesAsCertain: false` — runtime
type guards must survive even where the PHPDoc says they cannot fail. The PHP floor is 7.4, pinned in
`config.platform`; do not use 8.x syntax (no enums, no promoted properties, no `mixed` type
declarations).

## Architecture

### Public surface

A two-class static facade — `Config` (hook prefix + container) and `Absorber` (register/boot, plus
accessors) — matching the shape of `stellarwp/assets` and `stellarwp/admin-notices`. Everything
else is an implementation detail behind it. `Absorber` is
`final`: every member is private static and every internal call is `self::`, so a subclass could
override nothing and would silently change nothing.

### Collaborators

Every collaborator is constructed by the container and injected. The interface-backed ones are the
seams a host may rebind:

| Interface | Bound to | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | holds registered `Sub_Plugin` objects |
| `Notices\Contracts\Queue_Interface` | `Notices\Queue` | notice queue + activation-error rewrite |
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | one method: which policy branch a conflict takes |
| `Contracts\Plugin_Deactivator_Interface` | `Plugin_Deactivator` | deactivates the standalone, network-aware |
| `Contracts\Plugin_Checker_Interface` | `Plugin_Checker` | answers whether a plugin is active |
| `Contracts\Activator_Interface` | `Activator` | run-once activation-callback tracking |

The rest — `Boot\Scheduler`, `Loader`, `Conflict\Detector`, `Conflict\Gatekeeper`,
`Conflict\Redirector`, `Notices\Store`, `Notices\Renderer` — are bound as concrete classes. A host
that wants one of them different rebinds the class name; there is no interface because nothing in the
library dispatches on one.

An interface belonging to a folder-scoped concern lives in that folder's `Contracts\`, not beside its
implementation and not in the top-level `src/Contracts/`. `src/Contracts/` is for the interfaces whose
implementations sit at the root — `Registrar`, `Plugin_Deactivator`, `Plugin_Checker`, `Activator` —
plus `Provider_Interface`.

**The container is required.** `Config::get_container()` throws `Config_Exception` when unset, which
is what `uplink`, `telemetry`, `schema` and `harbor` all do; `has_container()` stays as the probe.
Optional was the outlier — of nineteen vendored StellarWP packages exactly one falls back to `new`,
and we had modelled ourselves on it. One requirement is what that outlier costs. "Container binding
when bound, `new $default_class` otherwise" forces every default class to be constructible with no
arguments, which forces `?Peer $peer = null` constructor parameters, which forces a `protected`
accessor per peer falling back to a static. `Container\Resolution` was the class holding that chain
together, and the chain is deleted with it. The hosts qualify: `learndash-core` ships a container
implementing this very contract, exposes it as `App::container()`, and already hands it to Telemetry,
Validation and Harbor. The plugins with no container are the add-ons being absorbed, not the hosts
doing the absorbing.

**One provider, one method.** `Provider::register()` performs every binding, behind a
`Contracts\Provider_Interface` declaring `register(): void` and nothing else — Harbor's shape, not
`stellarwp/foundation`'s. Foundation floors at PHP 8.3 against our 7.4, hard-depends on
`lucatume/di52` against our container-contract-only rule, and its `deferred`/`provides()` API is a
fatal `TypeError` in practice, because di52 type-hints its own `ServiceProvider` base class which
Foundation's abstract does not extend.

**`Provider` never overwrites a binding.** It binds only what the container does not already have, so
a host that bound its own implementation wins, and the order in which the host calls
`set_container()` and `boot()` stops deciding which implementation it gets. Everything is a
singleton: each binding is either a registry whose contents are the point — a second `Registrar`
would hold a second, emptier list — or a stateless worker with nothing to gain from a second copy.

The container is **never** used to wire hooks, and the reason is no longer that it is optional.
`Boot\Scheduler` wires callbacks that resolve *inside* the callback — a closure over the container,
or a static trampoline reading `Absorber::notices()` — so wiring instantiates nothing, a host may
rebind right up until the hook fires, and a request that reaches none of them builds none of them.
`$container->callback()` reads better and is what the hand-rolled copies in `learndash-core` use, but
it is di52-only: `stellarwp/container-contract` declares `bind`, `get`, `has` and `singleton`, and
nothing else. `[ $resolved_object, 'method' ]` is the other wrong answer — it forces every
collaborator to be built at boot.

`Absorber` keeps the public surface. `registrar()`, `notices()` and `resolver()` are one-line
delegations to `$container->get()`, so what a host calls is unchanged; what changed is that a *collaborator* now
depends on the peer it was handed rather than on the facade.

`Sub_Plugin` is a value object answering the per-sub-plugin questions it can answer **without a
container-bound collaborator** (`is_enabled()`, `is_already_loaded()`, `has_standalone_plugin()`,
`get_conflict_policy()`, …). Note that this is not the same as "config alone": `is_already_loaded()`
reads the global constant table and `is_enabled()` may invoke a host callable that queries anything
it likes. The line is about *dependency direction* — anything needing `Plugin_Checker_Interface` or
the notice queue would drag a container resolution into `Absorber::register()`, which deliberately
resolves nothing so the container can arrive at any point before boot. So `Sub_Plugin` only *names*
the plugin to ask about, and the collaborator does the asking.

### What exists today

`Activator` is not built yet. Currently:

| Path | What |
|---|---|
| `src/Config.php` | Static facade: hook prefix + container. |
| `src/Absorber.php` | Static facade: the registration buffer, `boot()`, and the accessors. |
| `src/Provider.php` | Binds every collaborator; the only file that names a default implementation. |
| `src/Boot/Scheduler.php` | Hook wiring and boot timing: the sequence, the priorities, and the fallback for a host that boots too late. |
| `src/Loader.php` | The load pass: the gate chain, the `require_once`, the activation callback. |
| `src/Sub_Plugin.php` | Value object; validates config and answers what it can without a container-bound collaborator. |
| `src/Conflict_Policy.php` | The three policy constants, `default()`, `is_valid()`. |
| `src/Plugin_Deactivator.php`, `src/Plugin_Checker.php` | The only files that touch WordPress plugin functions, through `Traits\Loads_Plugin_Functions`. |
| `src/Registrar.php` | Holds registered `Sub_Plugin` objects. |
| `src/Conflict/` | `Detector` (whether a standalone is in the way), `Resolver` (which policy branch to take), `Gatekeeper` (which requests, and which users, may have one resolved), `Redirector` (where the user lands afterwards), `Contracts\Resolver_Interface`. |
| `src/Traits/` | `Loads_Plugin_Functions` (pulls in `wp-admin/includes/plugin.php`), `Guards_Hook_Prefix` (a missing prefix warns and stands down rather than throwing). |
| `src/Notices/` | `Queue` (what a notice says, who may consume it), `Store` (keeps it), `Renderer` (draws it), `Contracts\Queue_Interface`. |
| `src/Contracts/`, `src/Exceptions/` | `Provider_Interface`, `Registrar_Interface`, `Plugin_Deactivator_Interface`, `Plugin_Checker_Interface`, `Config_Exception`. |

### Boot lifecycle

```
Config::set_hook_prefix( 'give' );
Config::set_container( $container );          // required
Absorber::register( [ …config… ] );             // once per sub-plugin; a duplicate slug throws
Absorber::boot();                               // idempotent
    → Provider::register()                    // every binding
    → Boot\Scheduler                          // every hook, as a closure over the container

plugins_loaded @5      → Conflict\Gatekeeper, Conflict\Detector, then Conflict\Resolver::resolve_all()
plugins_loaded @6      → Loader::load_all()
all_admin_notices      → Absorber::render_notices()           [is_admin() only]
wp_admin_notice_markup → Absorber::filter_activation_error_markup() [is_admin() only]
```

**A host calls `Config::set_container()` at `plugins_loaded` priority 0, from its own container
block and not from a service provider.** This is a recommendation, not the barrier: booting anywhere
below priority 5 wires cleanly. It is priority 0 rather than plugin-file scope because LearnDash's
`App::container()` builds a container lazily when none is set and the plugin then *replaces* it at
priority 0 — anything that grabbed the container earlier holds an orphan whose bindings are
discarded. Its own block rather than a service provider, for the same reason: a provider runs
whenever the host's bootstrap happens to run it. This is also why `Absorber::register()` buffers and
resolves nothing — registration at plugin-file scope, which the spec sanctions, would otherwise
register into the throwaway.

**The too-late barrier measures against the first step in the sequence, not the last.**
`Boot\Scheduler` compares the priority `plugins_loaded` is already dispatching against the lowest
priority it has to wire — conflict resolution at 5, not the load at 6 — and over that line it runs
the whole sequence inline in hook order rather than wiring any of it. Measuring against the load
would let a host booting at priority 5 wire the load and silently lose the conflict pass, which is
the half of the sequence a fatal depends on. The comparison is inclusive, because a callback added
at the priority currently being dispatched is accepted and never reached.

**Resolution sits at 5, not at 1, so the barrier leaves a host somewhere to stand.** At 1 the only
slot left was 0, which turned a documented convention into a hard requirement — and LearnDash and
MemberDash both wire Harbor's `set_container()` at `plugins_loaded` priority 1, so a host copying the
habit it already has landed exactly on the barrier and silently got the inline fallback. Five slots
cost the load pass four priorities it was not using. The load stays one behind resolution, because a
standalone that survives the conflict defines the guard constant as it loads and the load pass has to
see that.

`load_all()` gates each sub-plugin in order, skipping on the first failure: enabled → not already
loaded → dependencies met → file exists → `should_load` filter → `require_once` → activation
callback (only after a *successful* require).

The guard constant is checked **before** the dependency check, not after. It is one `defined()`, it
carries the whole re-declaration guarantee, and it is the only gate meaning "this plugin is already
running" — warning that requirements are unmet for a plugin the admin can watch working would send
them after the wrong problem. `docs/filters.md` and the spec agree.

`Absorber::all()` narrows to `Sub_Plugin` instances itself, so no caller repeats that guard. A host
may bind a registrar returning anything, and PHP 7.4 cannot express `array<string,Sub_Plugin>` in
the interface signature — so it is filtered once where the untrusted value enters. Both passes read
through `Absorber::all()` rather than through the registrar they could resolve for themselves, because
it flushes the pending registrations before it reads and a registrar asked directly would miss
anything registered since the last flush.

**Both passes also catch `Config_Exception` around that read.** The flush is where a duplicate slug
throws and the registrar resolution is where a missing container does, and both arrive inside
`plugins_loaded`, the hook that exists to prevent a fatal — so it is the last place allowed to cause
one. The conflict pass needs the guard more than the load pass, not less: its request gate means the
only requests reaching it are admin page views, so an escaping throw lands on exactly the screens the
mistaken registration would have to be corrected from.

`Conflict\Resolver` switches on the policy: `DEFER` no-ops, `NOTICE_ONLY` queues a notice, and
`DEACTIVATE` (the default) deactivates network-aware, queues a merge notice, and redirects. It is
the worked example of required injection — `Conflict\Detector` to say which sub-plugins are in
conflict, `Plugin_Deactivator_Interface` to turn the standalone off, `Queue_Interface` for the notice
and `Conflict\Redirector` for the destination, all four constructor arguments with no default — so
the object a test builds is the object the provider builds, and a host's rebinding of either plugin
seam reaches it, the deactivator directly and the checker through the detector, without the resolver
knowing a container exists.

`Conflict\Redirector::after_deactivation( $request_uri )` decides where the user lands and never goes
there: `wp_safe_redirect()` and the `exit` after it stay in the resolver, so the policy action and
the admin-URL knowledge change for separate reasons, and every destination is assertable without a
test standing in for the end of a request. It reads the *current* request, not the referrer, because
the point of the redirect is to re-render what the user asked for now that the standalone's code is
out of memory — a referrer names the page before, which for an admin arriving from a bookmark or a
front-end link is somewhere they never asked to go. There is no "stay put" answer for the same
reason: re-requesting the screen already on display is the point, `plugins.php` included, and it
cannot loop, since the next request finds no active standalone. `update.php` and `update-core.php`
are the exception and go to `plugins.php`, because reloading either re-runs an update; so does a URI
naming no admin screen at all. It matches on the screen basename, not on a substring of an absolute
URL: the request URI is a bare path, on a site that may sit in a subdirectory, behind a
TLS-terminating proxy whose scheme disagrees with `admin_url()`, or under the network or user admin,
so nothing built from `admin_url()` would recognise it. The basename is also what keeps a crafted URI
out of the destination — only a validated screen name and a re-encoded query leave the class, and
`admin_url()`, or `network_admin_url()`/`user_admin_url()` in the other two admins, supplies
everything in front of them.

**Who may have a conflict resolved is `Conflict\Gatekeeper`'s business, not the resolver's.** Two
methods, because the two gates are asked at different moments. `request_may_resolve()` reads the
request and nothing else: an interactive admin `GET` (`plugins_loaded` fires on every request,
including cron, CLI and a visitor's POST), and not one carrying an action — a redirect-and-`exit`
discards the work behind `update.php?action=upgrade-plugin` exactly as it would a POST's, and
`plugins.php?action=activate` is the request `plugin_sandbox_scrape()` replays, so exiting there
makes core report the plugin being activated as fatal. Any action arg at all, never a list of the
dangerous ones: half of wp-admin takes an `action`, plugins add their own, and a known-safe list
would have to stay right about every one of them forever. `user_may_resolve()` asks for
`manage_network_plugins` on multisite and `activate_plugins` otherwise — `plugins_loaded` runs
before `auth_redirect()`, so an unauthenticated GET of an admin URL gets that far, and the
capability has to match the reach of the act: `deactivate_plugins()` left at the default
`$network_wide` takes the standalone out of the *network's* active plugins, which a single site's
administrator holds no authority to do.

`Boot\Scheduler::sequence()` asks the two halves either side of `Conflict\Detector::has_conflict()`,
and that order is the point. `current_user_can()` resolves and caches the current user, so asking it
on every admin GET would settle who is signed in at `plugins_loaded` priority 5 — ahead of an SSO or
JWT plugin adding its `determine_current_user` filter from its own `plugins_loaded` callback, whose
users are then treated as logged out for the rest of the request, on requests with nothing to
resolve. The detector reports and changes nothing, so it is the cheap question that goes in front of
the expensive one. All three live in the step rather than in the resolver, so a host binding its own
cannot drop one by omission — and a request that fails any of them never builds a resolver. The
capability gate covers every policy, not just the destructive one, and that is free: the other
branches only queue a notice, and `Notices\Queue::render()` refuses to render *or clear* for a user
without the same capability, so queuing earlier would only park it until a capable admin arrives.

An unknown policy is normalised to `NOTICE_ONLY` through `Conflict_Policy::is_valid()` before the
switch, never decided by wherever a `switch` happens to fall through — a typo like `'defered'` would
otherwise deactivate a plugin the site owner deliberately turned on. The `default:` branch that
remains is the one that only queues a notice, so a policy nobody wrote is never read as consent to
turn a plugin off.

### Keys

- Filters: `{$hook_prefix}/plugin_absorber/should_load`, `{$hook_prefix}/plugin_absorber/conflict_policy`
- Options: `{$option_prefix}_plugin_absorber_activations`, `{$option_prefix}_plugin_absorber_notices`

Both are built in `Config` — `get_hook_name()` and `get_option_name()` — so nothing else assembles
the segment between the host's prefix and the key's own name. The two differ in one respect:
`{$option_prefix}` is the hook prefix lowercased with hyphens folded to underscores, because the
prefix validator admits `A-Z` and `-` and a hook-naming value should not reach a storage key
verbatim. Hook names keep the host's casing exactly as it passed it.

The notice queue is an option, not a transient: with a persistent object cache a transient never
reaches the database, so a `wp_cache_flush()` would destroy a merge notice that is raised exactly
once and never re-queued. It is read and written through `get_site_option()`/`update_site_option()`,
so it is a network option on multisite — matching `deactivate_plugins()`, which is network-wide.

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
comment; the code outlives the plan. Comments earn their place by explaining *why*, especially where
a plausible alternative is wrong.

Tabs for PHP, 4 spaces for yml/yaml/json/md (see `.editorconfig`).

### No test-only seams in `src/`

Production classes do not get a `reset()` for the suite's benefit — that becomes API the library
supports forever. Tests clear static state by reflection through a helper under `tests/_support/`.
`Config` is served by `Tests\Support\Config_State::reset()`; `Registrar` and `Absorber` get the same
treatment. Any older sketch showing `Config::reset()` or `Absorber::reset()` means the support helper.

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
- **Undo uopz stubs and `define()`s in `tearDown()`, not at the end of the test body.** A failed
  assertion would otherwise strand a constant for the rest of the process, and a later test reads it
  as a real load.
- **Asserting a call did *not* happen needs a working recorder.** Record calls into an array, assert
  it is empty, then invoke the stubbed function once and assert the recorder caught it — otherwise a
  hook that failed to install passes the test for the wrong reason.
- **Every test that touches a collaborator sets a container**, since there is no longer a fallback to
  fall back to — and it must be `Tests\Support\Test_Container`. `lucatume\DI52\Container` implements
  PSR-11's `ContainerInterface`, not StellarWP's, so passing it to `Config::set_container()` is a
  `TypeError`.
- **Each load-path test writes its own bundled fixture file.** `require_once` caches by resolved
  path per PHP process, so a shared fixture makes every later test silently pass.
- **Shared fixtures go in a trait**, not a per-test helper: `tests/_support/Traits/WithSubPlugins.php`
  builds `Sub_Plugin` objects for the whole suite.
- **Data providers are generators** returning `Generator<string,array{…}>` with named cases.
- Tasks follow RED→GREEN: the failing test lands before the implementation.

## Invariants — do not "simplify" these away

- **The guard constant and the standalone basename are two separate keys.** No constant does double
  duty as both a load guard and a path resolver.
- **`get_hook_name()` and `get_option_name()` do not share a normalisation.** Folding case into the
  hook prefix itself would silently rename the host's filters; leaving the raw prefix in an option
  name puts `A-Z` and `-` into a storage key. Only the option side normalises, and collapsing the two
  code paths breaks whichever end it is collapsed toward.
- **Notice messages are rendered through `wp_kses_post()`, not escaped.** They come from the host's
  own config or filter, never from user input, so a knowledge-base link survives. Tightening this to
  `esc_html()` after 1.0 would break every host that shipped one.
- **A configured string is never called; every other callable form is.** A string function name is
  indistinguishable from a string value, so honouring it would make the result depend on what else
  the site loaded — `date`, `flush` and `key` are all real functions and plausible values. Closures,
  `[ class, method ]` pairs and invokable objects say "call me" and nothing else.
- **`conflict_notice_message` and `dependency_notice_message` reject a string outright.** Not just
  string callables — any string. A config array is built before `init`, so a translated string under
  one of these can only have been produced too early, and nothing in the value distinguishes it from
  one that was not. Refusing both costs the host a `static fn()` and fails the first time the code
  runs, where an eager `__()` is the `_load_textdomain_just_in_time` notice in someone else's log.
  This is a deliberate departure from the spec's `string | callable`.
- **`conflict_policy` takes either**, because a policy is usually a `Conflict_Policy` constant with
  nothing to defer, and is never text a user reads. `standalone_plugin_basename` is string-only: it
  names a file already on disk.
- **Callable-only keys reject non-callables at registration**, not at read time — otherwise "not
  configured" and "configured but uncallable" collapse into the same answer, and a typo'd
  `dependency_check` reports dependencies met.
- **Required keys must be non-empty strings**, checked with `is_string()` rather than truthiness: an
  array passes truthiness and then casts to `"Array"`, which every sub-plugin with the same mistake
  would share as its registry key.
- **`get_conflict_policy()` does not validate its result.** A filter may return anything; rejecting
  it there would hide the override. Callers that dispatch on it use `Conflict_Policy::is_valid()`
  and must not treat an unrecognised value as consent to deactivate.
- **Filters run last** — after the configured value and any fallback — which is what makes deferred
  translation work. A non-scalar filter return becomes `''`, never a fatal cast.
- **`deactivate_plugins()` is called silent, with no `$network_wide` argument.** Silent because a
  `flush_rewrite_rules()` in the standalone's deactivation hook at `plugins_loaded` 404s the site.
  The `null` default takes both the network and blog branches; a computed `true` strands an entry.
- **`Traits\Loads_Plugin_Functions` guards on `deactivate_plugins()`**, not `is_plugin_active()` —
  the latter is a common third-party shim.
- **Strauss must not rewrite `plugin_loaded_constant` values.** They are shared runtime constants;
  prefixing them defeats the entire mechanism.
- **Never write a literal guard-constant *name* in `src/`.** Hosts run Strauss with a
  `constant_prefix` — `learndash-core` uses `LEARNDASH_`, with an empty exclude list — so a literal
  `'GIVE_RECURRING_VERSION'` in our source is rewritten at build time and the `defined()` check then
  matches nothing, silently, on the one path whose whole job is preventing a fatal. A constant name
  arriving as a config *value* is a string in the host's array and is safe, which is why the design
  holds: the library only ever receives these names, never spells one.
- **Collaborators come from the container; value objects and exceptions are constructed inline.** No
  `?Peer $peer = null` constructor parameter and no `?? new X()` fallback — a nullable dependency
  instantiating its own default is a service locator wearing a constructor signature, and it is what
  made every default class owe a no-argument constructor. `new Sub_Plugin( $config )` in
  `Absorber::register()` and `throw new Config_Exception( … )` stay: one value object per host-supplied
  array, built in the call the host can see in its own stack trace, which is what lets registration
  validate eagerly while resolving nothing. A `Sub_Plugin_Factory` would put a container resolution
  in front of the one method that deliberately performs none.
- **Naming: the suffix names the collaborator's role in the wiring, not whether it causes a side
  effect.** A class that returns a URL from a filter still earns `-or` — `Redirector` alongside
  `Registrar` and `Granter`, none of which need to perform anything for the name to be right. A class
  stays bare while nothing in its folder does the same job (`Conflict\Resolver`, not
  `Conflict\Standalone_Resolver`, even once `Gatekeeper` and `Redirector` sit beside it) and takes a
  qualifier only when a second class of the same kind lands — `Scheduler` beside a later
  `Retry_Scheduler`, never a qualifier bought in advance. An abstract
  `-ion`/`-ance` noun is a directory name over agent nouns — `Activation/` holding `Activator` — and
  never a class name; a census of 3,343 classes across eight Nexcess/StellarWP codebases found zero.

## Branch and PR workflow

Branching is **stacked**: each branch cuts from the previous one and merges to `main` in order.
Branch names are `NN-topic` and map 1:1 to task numbers in the plan. Never open PR N+1 before PR N's
branch exists. `main` is releasable after every merge.

- **PR size cap:** ≤10 files, tests and test infrastructure excluded. No logic-bearing PR exceeds 4
  source files.
- **Commits: no co-author trailers, ever.**
- **PR body is exactly three parts, nothing else** — no boilerplate headings, no restating the diff,
  no checklists, and no "Verify" section: the commands are in this file and the coverage is in the
  diff, so restating them per PR is filler a reviewer learns to scroll past.

  ```
  What: one line, naming every hook or entry point the PR wires.

  Usage: the snippet this PR makes possible.

  Why this way:

  **The claim, in bold.** One or two sentences: the trade-off taken, and against what.

  **The next claim.** Same again.
  ```

  `What` is one line but not a narrow one — a PR that wires two hooks names both, or the second goes
  unreviewed. `Why this way` is one bold-led block per decision, never a single paragraph running
  several arguments together: a reviewer reads the bold leads, stops at the one they doubt, and the
  rest costs them nothing. Cut the connective throat-clearing between claims, never the claims.

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
task-by-task breakdown, and
`docs/superpowers/plans/2026-08-12-container-required-rework.md` supersedes it wherever the two
disagree about the container, the collaborator seams or the class names — that plan is what branches
11 through 16 now implement, and the older plan still describes the optional-container design it
replaced.

Once a task's PR merges to `main`, delete that task's section from the plan in the next branch that
touches the file; git history keeps it. A shipped task's plan describes code that already exists in
`src/`, so all it can still do is make an agent read past it to reach what is unbuilt — and since
the plan is edited on every branch of a stacked series, an oversized one is a standing
merge-conflict surface. Never renumber what survives: the numbers map 1:1 to branch names. The spec
is the durable document; the plan is scaffolding and should shrink toward empty as the series lands.

Human-facing docs are `README.md` plus `docs/installing.md`, `docs/configuration.md`,
`docs/conflict-handling.md`, `docs/filters.md`, and `docs/notices.md`. Keep them short and keep
rationale here or in code comments — do not grow the README back.
