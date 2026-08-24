# AGENTS.md

This file provides guidance to coding agents when working with code in this repository. `CLAUDE.md`
is a symlink to it, so Claude Code reads the same document.

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
production dependency on another StellarWP library. Version negotiation is the one that gets
re-litigated, so: it existed in the prior art only because of a single LearnDash ProPanel quirk —
v2.x was absorbed while a completely different plugin was rebranded ProPanel v3.0 — and a callable
`conflict_policy` already covers that as config. Do not reshape the architecture around it.

**Prior art.** Two ad-hoc implementations this is distilled from. LearnDash `sfwd-lms` copied each
addon into `includes/<addon>/` with a per-addon loader
(`src/Core/Modules/Course_Grid/Legacy/Loader.php` and siblings); the three modules diverged in class
shape, in guard-constant type, and in whether version negotiation existed at all — the exact
inconsistency this library removes. `kadence-shop-kit`'s `inc/Common/Features/` is the more polished
config-array Strategy pattern, but its Provider → Repository → Resolver → Strategy indirection is
heavier than this needs and it is coupled to Kadence's option store and DI52 container; here one
load path handles every sub-plugin. The `wp_admin_notice_markup` rewrite follows LearnDash 4.21.4.

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

A two-class static facade — `Config` (hook prefix, container, and optionally the host plugin
basename) and `Absorber` (register/boot, plus accessors) — matching the shape of
`stellarwp/assets` and `stellarwp/admin-notices`. Everything else is an implementation detail
behind it. `Absorber` is
`final`: every member is static, the only property and the only helper are private, and every
internal call is `self::`, so a subclass could override nothing and would silently change nothing.

### Collaborators

Every collaborator is constructed by the container and injected. The interface-backed ones are the
seams a host may rebind:

| Interface | Bound to | Responsibility |
|---|---|---|
| `Registry\Contracts\Registrar_Interface` | `Registry\Registrar` | holds registered `Sub_Plugin` objects |
| `Notices\Contracts\Writer_Interface` | `Notices\Writer` | what each notice says |
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | one method: which policy branch a conflict takes |
| `Plugin\Contracts\Deactivator_Interface` | `Plugin\Deactivator` | deactivates the standalone, network-aware |
| `Plugin\Contracts\Checker_Interface` | `Plugin\Checker` | answers whether a plugin is active (either scope), and whether it is network-active |
| `Contracts\Activator_Interface` | `Activator` | run-once activation-callback tracking |

The rest — `Boot\Scheduler`, `Loader`, `Registry\Reader`, `Conflict\Detector`, `Conflict\Gatekeeper`,
`Conflict\Redirector`, `Conflict\Rewriter`, `Notices\Store`, `Notices\Renderer`, `Notices\Presenter` —
are bound as concrete classes. A host that wants one of them different rebinds the class name **after
`boot()`**, for the reason `bind_once()` gives below; there is no interface because nothing in the
library dispatches on one. `Provider` also binds the container under `ContainerInterface::class`,
first and before anything else, so that a container which builds unbound classes reflectively can
still satisfy the collaborators that take one.

An interface belonging to a folder-scoped concern lives in that folder's `Contracts\`, not beside its
implementation and not in the top-level `src/Contracts/`. What is left in `src/Contracts/` is the
interfaces whose implementations sit at the root — `Activator_Interface` — plus `Provider_Interface`,
which belongs to no folder because `Provider` is the file that names every folder.

**`Notices\Writer` and `Notices\Presenter` split because they change for different reasons.** One
answers "what does this notice say", the other "who may see the pending set, and is it gone once they
have" — a host already running `stellarwp/admin-notices` has an opinion about the first and none about
the second. Only `Writer` earns `Writer_Interface`: wording is what a host rebinds, and nothing in the
library dispatches on how a notice reaches the screen, since the trampoline on `all_admin_notices` is
`Presenter`'s only caller — a host that wants no rendering of ours removes that callback rather than
binding a no-op. The capability check stays on `Presenter`, next to the render-then-clear, rather than
moving to `Renderer` alongside the markup: it guards the clearing as much as the drawing, so deciding
those two separately would let a user who may not see the queue destroy it anyway, through a class
that never checked.

**The container is required.** `Config::get_container()` throws `Config_Exception` when unset, which
is what `uplink`, `telemetry`, `schema` and `harbor` all do; `has_container()` stays as the probe.
Optional was the outlier — of nineteen vendored StellarWP packages exactly one falls back to `new`.
Do not reintroduce a container-less path: "container binding when bound, `new $default_class`
otherwise" forces every default class to be constructible with no arguments, which forces
`?Peer $peer = null` constructor parameters, which forces a `protected` accessor per peer falling
back to a static — a service locator wearing a constructor signature, arrived at one reasonable step
at a time. The hosts qualify: `learndash-core` ships a container implementing this very contract,
exposes it as `App::container()`, and already hands it to Telemetry, Validation and Harbor. The
plugins with no container are the add-ons being absorbed, not the hosts doing the absorbing.

**The container is typed as `container-contract`'s `ContainerInterface`, not
`stellarwp/foundation-container`'s.** Not a rejection of Foundation — the two are the same target.
`StellarWP\Foundation\Container\Contracts\Container` *extends* `ContainerInterface`, so
`ContainerAdapter` already satisfies `Config::set_container()` and a Foundation host needs nothing
from us; typing against the superset would only subtract. It would floor us at PHP 8.3, which every
released `foundation-container` (1.0 through 1.3) pins, against our 7.4. It would add
`lucatume/di52 >=4.1`, `adbario/php-dot-notation` and `vlucas/phpdotenv` as production dependencies
of a library Strauss copies into a host plugin that already bundles its own di52 — and a `.env`
loader has nothing to do inside a WordPress plugin. And it would narrow the hint from *any*
container-contract implementation to Foundation's alone, disqualifying `learndash-core`'s
`App::container()`, which is the host this library exists for. What it buys is `callback()`,
`register()` and contextual binding; `callback()` is the only one ever wanted, and `Boot\Scheduler`'s
closures already provide the lazy resolution that was the actual requirement. Revisit if the PHP
floor ever reaches 8.3 *and* something needs `when()`/`needs()` — not before.

**One provider, one method.** `Provider::register()` performs every binding, behind a
`Contracts\Provider_Interface` declaring `register(): void` and nothing else — Harbor's shape, not
`stellarwp/foundation`'s. Foundation floors at PHP 8.3 against our 7.4, hard-depends on
`lucatume/di52` against our container-contract-only rule, and its `deferred`/`provides()` API is a
fatal `TypeError` in practice, because di52 type-hints its own `ServiceProvider` base class which
Foundation's abstract does not extend.

**`Provider` never overwrites an *interface* binding.** `bind_once()` stands down on
`! class_exists( $id ) && $this->container->has( $id )`, so the guarantee covers the seven interface
ids and not the ten class-name ones, which are re-bound unconditionally. That is deliberate:
`has()` means "can return an entry", not "the host bound this" — di52 answers it with `isBound() ||
class_exists()` — so for a class id it is already true before anything is bound, and dropping the
`class_exists()` half would stand down every concrete binding, the explicit factories included,
leaving those collaborators autowired where the container autowires, broken where it does not, and
singletons nowhere. What it costs is that a host replacing one of the concrete workers has to bind
after `boot()`; the interface seams, which are the ones a host is invited to replace, win whenever
they are bound. Everything is a singleton: each binding is either a registry whose contents are the
point — a second `Registry\Registrar` would hold a second, emptier list — or a stateless worker with
nothing to gain from a second copy.

The container is **never** used to wire hooks, and the reason is no longer that it is optional.
`Boot\Scheduler` wires callbacks that resolve *inside* the callback — a closure over the container,
or a static trampoline on `Absorber` that resolves `Notices\Presenter` or `Conflict\Rewriter` when it
fires — so wiring instantiates nothing, a host may rebind right up until the hook fires, and a
request that reaches none of them builds none of them.
`$container->callback()` reads better and is what the hand-rolled copies in `learndash-core` use, but
it is di52-only: `stellarwp/container-contract` declares `bind`, `get`, `has` and `singleton`, and
nothing else. `[ $resolved_object, 'method' ]` is the other wrong answer — it forces every
collaborator to be built at boot.

`Absorber` keeps the public surface. `registrar()`, `notices()`, `resolver()` and `all()` are one-line
delegations to `$container->get()`, so what a host calls is unchanged; what changed is that a *collaborator* now
depends on the peer it was handed rather than on the facade.

**No collaborator reaches the registry through `Absorber`.** The rule is about the registration
buffer, not about the name: `Boot\Scheduler` spells `Absorber::class` three lines in, because the two
admin hooks are wired as `[ Absorber::class, … ]` pairs and the name is what `remove_filter()` needs
to reach. The registration buffer belongs to `Registry\Reader`, which is also what reads it back
out: `Absorber::register()` pushes a `Sub_Plugin` into it and
`Absorber::all()` delegates to it, while `Conflict\Detector`, `Conflict\Resolver` and `Loader` are
each handed one. The buffer is static because it must be — `register()` is a static call a host makes
at plugin-file scope, before there is a container to resolve a registrar from — and what is decided
is only which class pays for that. Leaving it on the facade left an edge pointing back up: the passes
the facade boots read the registry by calling the facade, so `Absorber` sat both above and below its
own collaborators, and a pass could not be handed a registry to work on. The arrows run one way now,
and a pass is complete the moment it is built.

`Sub_Plugin` is a value object answering the per-sub-plugin questions it can answer **without a
container-bound collaborator** (`is_enabled()`, `is_already_loaded()`, `has_standalone_plugin()`,
`get_conflict_policy()`, …). Note that this is not the same as "config alone": `is_already_loaded()`
reads the global constant table and `is_enabled()` may invoke a host callable that queries anything
it likes. The line is about *dependency direction* — anything needing `Plugin\Contracts\Checker_Interface` or
the notice queue would drag a container resolution into `Absorber::register()`, which deliberately
resolves nothing so the container can arrive at any point before boot. So `Sub_Plugin` only *names*
the plugin to ask about, and the collaborator does the asking.

### What exists today

The library is feature-complete for 1.0.0. Every behaviour described above is built, and the suite
that drives the whole of it against a real WordPress is `tests/unit/Scenario/`.

| Path | What |
|---|---|
| `src/Config.php` | Static facade: hook prefix, container, and the optional host plugin basename. |
| `src/Absorber.php` | Static facade: registration, `boot()`, the accessors, and the two notice trampolines. Holds no collaborator's state. |
| `src/Provider.php` | Binds every collaborator; the only file that names a default implementation. |
| `src/Boot/Scheduler.php` | Hook wiring and boot timing: the sequence, the priorities, and the fallback for a host that boots too late. |
| `src/Loader.php` | The load pass: the gate chain, the `require_once`, the activation callback, and the `loaded`/`skipped` announcements. |
| `src/Sub_Plugin.php` | Value object; validates config and answers what it can without a container-bound collaborator. |
| `src/Conflict_Policy.php` | The three policy constants, `default()`, `is_valid()`. |
| `src/Skip_Reason.php` | The five reasons the `skipped` action carries, one per gate in the load pass. |
| `src/Plugin/` | `Deactivator` (turns the standalone off), `Checker` (answers whether a plugin is active in either scope, and whether it is network-active — `is_plugin_active_for_network()`, which is `false` off a network so no caller needs an `is_multisite()` guard), `Loads_Plugin_Functions` (pulls in `wp-admin/includes/plugin.php`), `Contracts\Deactivator_Interface`, `Contracts\Checker_Interface`. The only files that touch WordPress plugin functions. |
| `src/Registry/` | `Registrar` (holds registered `Sub_Plugin` objects), `Reader` (the registration buffer, drained into the registrar on the way past; the object every pass reads the registry through), `Contracts\Registrar_Interface`. |
| `src/Activator.php` | Runs a sub-plugin's activation callback once ever, recorded in one option. |
| `src/Conflict/` | `Detector` (whether a standalone is in the way), `Resolver` (which policy branch to take), `Gatekeeper` (which requests, and which users, may have one resolved), `Redirector` (where the user lands afterwards), `Rewriter` (rewrites the activation-error screen for a registered standalone), `Contracts\Resolver_Interface`. |
| `src/Traits/` | `Guards_Hook_Prefix` (a missing prefix warns and stands down rather than throwing), `Guards_Plugin_Capability` (which capability a plugin act asks for, shared by the conflict gate and the notice queue). Cross-cutting only: a trait used by one folder lives in that folder. |
| `src/Notices/` | `Writer` (what a notice says, stored under `slug:type` — `merge`, `conflict`, `stranding`, `dependency`), `Presenter` (who may consume it, render-then-clear), `Store` (keeps it), `Renderer` (draws it, `notice-error` for `dependency` and `notice-warning` for the rest), `Contracts\Writer_Interface`. |
| `src/Contracts/`, `src/Exceptions/` | `Provider_Interface`, `Activator_Interface`, `Config_Exception`. |

### Boot lifecycle

```
Config::set_hook_prefix( 'give' );
Config::set_container( $container );          // required
Absorber::register( [ …config… ] );           // once per sub-plugin; an unusable config throws here
Absorber::boot();                             // idempotent
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
resolves nothing — registration at plugin-file scope is a shape a host is entitled to use, and it
would otherwise register into the throwaway.

**A duplicate slug is `Registry\Registrar::register()`'s exception, not `Absorber::register()`'s.** What
`Absorber::register()` throws is config validation, from the `Sub_Plugin` constructor, in the call
the host can see in its own stack trace. The buffer reaches the registrar at the first read —
`plugins_loaded` priority 5 on a request that passes the gatekeeper, priority 6 otherwise — so the
collision surfaces from inside a core action. Both are `Config_Exception`; only one of them can name
the line the host wrote.

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
loaded → dependencies met → file is a readable file → `should_load` filter → `require_once` →
activation callback (only after a *successful* require). The file gate is `is_file() &&
is_readable()`, not `file_exists()`: that last is true for a directory and for a file with no read
permission, and `require_once` fatals on both. Only the dependency gate queues a notice; an
unreadable file is a broken build in the host plugin and reports through `_doing_it_wrong()`.

**Each gate's reason is a `Skip_Reason` constant, not a constant on `Loader`.** What the five values
belong to is the `skipped` action rather than the pass that fires it: `Loader` is bound by class name
and a host may replace it outright, and a replacement announcing the same vocabulary should not have
to import the implementation it swapped out to name one — the reason `Conflict_Policy` sits at the
root rather than on `Conflict\Resolver`. Unlike `Conflict_Policy` it carries no behaviour: the library
only ever emits a reason, never receives one, so an `is_valid()` there would have no caller.

The activation callback is the last of those and runs through `Activator_Interface`, which `Loader`
takes as a constructor argument like the writer and the registry reader. Last, because a bundled
plugin is included rather
than activated: `register_activation_hook()` never fires for it, so the callback stands in for
whatever that hook would have done, and it has to run with the plugin's own code already in memory.
Only after a require that happened, because creating tables and seeding options for a sub-plugin
whose code is *not* loaded is worse than not creating them — and the once-ever record would then
stand the callback down for good, the first time the sub-plugin really did load. The record is
written after the callback returns, never before, so a callback that throws is retried next request
rather than marked done.

The guard constant is checked **before** the dependency check, not after. It is one `defined()`, it
carries the whole re-declaration guarantee, and it is the only gate meaning "this plugin is already
running" — warning that requirements are unmet for a plugin the admin can watch working would send
them after the wrong problem. `docs/filters.md` says the same.

`Registry\Reader::all()` narrows to `Sub_Plugin` instances itself, so no caller repeats that guard. A
host may bind a registrar returning anything, and PHP 7.4 cannot express `array<string,Sub_Plugin>` in
the interface signature — so it is filtered once where the untrusted value enters. All three readers —
the load pass, the conflict pass and `Conflict\Rewriter` — read through the reader they were
constructed with rather than through the registrar they could resolve for themselves, because it
drains the pending registrations before it reads and a registrar asked directly would miss anything
registered since the last flush.

**Both passes also catch `Config_Exception` around that read.** A duplicate slug is only found when
the buffer reaches the registrar, which is a read — long after both `register()` calls returned — and
it arrives inside `plugins_loaded`, the hook that exists to prevent a fatal, so this is the last place
allowed to cause one. The conflict pass needs the guard more than the load pass, not less: its request
gate means the only requests reaching it are admin page views, so an escaping throw lands on exactly
the screens the mistaken registration would have to be corrected from.

The container is no longer the other half of that. A pass is handed a reader that already holds its
registrar, so a container that cannot supply one fails while the *pass* is being built — where an
unbuildable `Writer_Interface` or `Plugin\Contracts\Checker_Interface` has always failed. Read-time and
build-time failures stopped being the same event when the registry became an argument, and the
registrar now fails like every other binding rather than being the one collaborator whose broken
binding surfaced late and politely.

`Conflict\Resolver` switches on the policy: `DEFER` no-ops, `NOTICE_ONLY` queues a notice, and
`DEACTIVATE` (the default) deactivates network-aware, queues a merge notice, and redirects — unless
the multisite stranding guard, `Conflict\Detector::deactivation_would_strand_sites()`, declines the
deactivation, in which case it queues a stranding notice and leaves the standalone alone. It is
the worked example of required injection — `Conflict\Detector` to say which sub-plugins are in
conflict and whether deactivating one would strand sites, `Plugin\Contracts\Deactivator_Interface`
to turn the standalone off, `Writer_Interface` for the notice and `Conflict\Redirector` for the
destination, all four constructor arguments with no default — so
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
would have to stay right about every one of them forever. `Traits\Guards_Hook_Prefix` is the last of
the three, because it is the only one that reports to the developer and a missing prefix logged from
every front-end request would bury it. `user_may_resolve()` asks for `manage_network_plugins` on
multisite and `activate_plugins` otherwise — `plugins_loaded` runs before `auth_redirect()`, so an
unauthenticated GET of an admin URL gets that far, and the capability has to match the reach of the
act: `deactivate_plugins()` left at the default `$network_wide` takes the standalone out of the
*network's* active plugins, which a single site's administrator holds no authority to do.

`Boot\Scheduler::sequence()` asks the two halves either side of `Conflict\Detector::has_conflict()`,
and that order is the point. `current_user_can()` resolves and caches the current user, so asking it
on every admin GET would settle who is signed in at `plugins_loaded` priority 5 — ahead of an SSO or
JWT plugin adding its `determine_current_user` filter from its own `plugins_loaded` callback, whose
users are then treated as logged out for the rest of the request, on requests with nothing to
resolve. The detector reports and changes nothing, so it is the cheap question that goes in front of
the expensive one. All three live in the step rather than in the resolver, so a host binding its own
cannot drop one by omission — and a request that fails any of them never builds a resolver. The
capability gate covers every policy, not just the destructive one, and that is free: the other
branches only queue a notice, and `Notices\Presenter::render()` refuses to render *or clear* for a user
without the same capability, so queuing earlier would only park it until a capable admin arrives.

**That ordering covers the no-conflict case and only that, and the rest is known and not fixed.** A
site that *is* in conflict stays in conflict until a capable admin opens a plain admin screen — days,
on a site nobody administers in a browser — and every admin GET in between reaches
`current_user_can()` at `plugins_loaded` priority 5, ahead of an SSO or JWT plugin that adds
`determine_current_user` from its own `plugins_loaded` callback. There is no earlier answer available:
nothing may deactivate a plugin on a user's behalf without first asking what that user is allowed to
do. A host that hits it adds its `determine_current_user` filter at plugin-file scope instead. What
does *not* happen is a bounce to `wp-login.php` — `auth_redirect()` reads `wp_validate_auth_cookie()`
directly rather than the cached user.

An unknown policy is normalised to `NOTICE_ONLY` through `Conflict_Policy::is_valid()` before the
switch, never decided by wherever a `switch` happens to fall through — a typo like `'defered'` would
otherwise deactivate a plugin the site owner deliberately turned on. The `default:` branch that
remains is the one that only queues a notice, so a policy nobody wrote is never read as consent to
turn a plugin off.

**The activation-error rewrite is its own class, `Conflict\Rewriter`, not a method on
`Notices\Writer`.** It is the one conflict the load guard cannot prevent — core includes the plugin
being activated *after* the bundled copy is in memory, so the re-declaration really does fatal — and
all this library gets to do about it is reword the sentence core's sandbox prints. That sentence is
`conflict_notice_message`, the same message the merge notice carries, but wording it is as far as the
two share: nothing in `Rewriter` is stored, drawn or authored through the notice machinery — it reads
the request, checks the screen, verifies a nonce and edits markup core already wrote. Putting it on
`Writer` anyway would have needed a `Registry\Reader` argument for the one method that used it — a
collaborator only that method needs — and would have forced every host binding its own
`Notices\Contracts\Writer_Interface` to implement an error screen just to get its notices worded. It
sits in `Conflict\` rather than `Notices\` because what it is about is the standalone conflict — the
same subject `Detector`, `Resolver` and `Redirector` share — not because it touches notice machinery.
`Absorber::filter_activation_error_markup()` is the trampoline, and it
takes an **untyped** argument: a filter receives whatever the filter before it returned, and a
`string` declaration would turn another plugin's sloppy return into a TypeError raised from here, on
the screen least able to afford a second one. The rewrite refuses unless the screen is `plugins` or
`plugins-network` — `wp-admin/network/plugins.php` is a one-line require of the other and draws the
identical error, and on a default multisite it is the only screen an absorbed standalone can be
reactivated from — the `plugin` query arg names a registered standalone and `_error_nonce` verifies.
And it sanitises with `wp_kses_post()` *before* testing for emptiness, since a message that filters
down to nothing must leave core's wording standing rather than blank the notice box.

**`Boot\Scheduler` wires `wp_admin_notice_markup` as a named static callback, not a closure.** Both
admin-only hooks are `[ Absorber::class, … ]` pairs that resolve their own collaborator — the
presenter, or `Conflict\Rewriter` — when they fire, so neither builds anything at boot; what the name buys on
this one is `remove_filter()`, which a host wanting core's wording back has no other way to reach.
The `plugins_loaded` steps are closures for a reason these two do not share — that sequence has to be
runnable inline as well as wirable.

### Keys

- Filters: `{$hook_prefix}/plugin_absorber/should_load` (`Loader`),
  `{$hook_prefix}/plugin_absorber/conflict_policy`,
  `{$hook_prefix}/plugin_absorber/conflict_notice_message`,
  `{$hook_prefix}/plugin_absorber/dependency_notice_message` and
  `{$hook_prefix}/plugin_absorber/stranding_notice_message` (all four `Sub_Plugin`)
- Actions: `{$hook_prefix}/plugin_absorber/loaded` and `{$hook_prefix}/plugin_absorber/skipped`
  (`Loader`, one per sub-plugin the load pass finished with; the skip reasons are `Skip_Reason`
  constants)
- Options: `{$option_prefix}_plugin_absorber_activations` (`Activator`),
  `{$option_prefix}_plugin_absorber_notices` (`Notices\Store`)

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
`stellarwp/plugin-absorber`; the two deliberately do not match, and Composer does not require them
to. Packagist protects a vendor prefix once anyone publishes under it, and `nexcess` is already held
by an unrelated package outside our control, while `stellarwp` is ours across 38 packages — so the
package ships under the vendor we hold and the namespace stays what the code is. Tests are
`…\Tests\`, support classes `…\Tests\Support\`.

**The name is `plugin-absorber`, not `plugin-loader`.** In WordPress, "plugin loader" is established
jargon for mu-plugin autoloading — Packagist's top results for it are all that, and
`plugin-absorber` collides with nothing. `Sub_Plugin` stays the value-object name regardless:
"sub-plugin" names the registered thing, "absorb" names what the library does to it.

**Floors:** PHP `>=7.4`, WordPress 6.4 (for the `wp_admin_notice_markup` filter). WordPress is not a
Composer dependency, so its floor lives in the README only.

**Production dependencies:** `stellarwp/container-contract` at `^1.1`, and nothing else. `lucatume/di52`
is dev-only.

The floor is `^1.1` because `^1.0` claims two things that are not true of this library. Below 1.0.2
the interface is `StellarWP\Container\ContainerInterface`, so the class `Config::set_container()`
type-hints does not exist. Below 1.1.0 it exists but `get()` carries no `@template`, so every
container resolution in `src/` is `mixed` and level 9 can type none of them. Raise it, never lower
it.

It is accurate rather than load-bearing, and does not earn a CI leg that resolves it. `uplink`,
`telemetry` and `schema` all require `^1.0`, which admits 1.1.2 — so Composer hands a real host tree
the top of the range whatever we declare, and reaching the floor takes a stale lock or an explicit
pin. A job installing the lower bound would guard a line nobody has a reason to edit, at the price
of going red whenever a dev dependency moved the contract.

**Class naming is `Snake_Case`** (`Sub_Plugin`, `Conflict_Policy`, `Config_Exception`). Method names
are fully spelled out; config keys are descriptive and WordPress-centric.

**Method order is public, then protected, then private.** No private helper sits above the public
API it serves.

**Docblocks:** every file in `src/` carries a file-level docblock with `@package
Nexcess\PluginAbsorber`, and every method a docblock with `@since 1.0.0`. This binds `src/` only —
test and support classes keep the file-level docblock but need no `@since`.

**Every PHP file declares `declare( strict_types=1 );`** — `src/` and `tests/` alike, one blank line
below the file-level docblock and one above the `namespace`. A new file without it is incomplete.
The declaration governs the *call site*, so what it buys is our own calls outward: an `int` or a
`null` reaching a WordPress function that declared `string` fails on the line that passed it, rather
than coercing to `"0"` or `""` and surfacing as a wrong value somewhere downstream. What it
deliberately does not change is the hook boundary — WordPress's own files are not strict, so core
calling our typed methods still coerces weakly, and a callback that receives a loose type from a
filter behaves exactly as it did.

**Comments describe behaviour, not the plan.** Never reference a task or plan-step number in a code
comment; the code outlives the plan. Comments earn their place by explaining *why*, especially where
a plausible alternative is wrong.

Tabs for PHP, 4 spaces for yml/yaml/json/md (see `.editorconfig`).

### No test-only seams in `src/`

Production classes do not get a `reset()` for the suite's benefit — that becomes API the library
supports forever. Tests clear static state by reflection through a helper under `tests/_support/`:
`Tests\Support\Config_State::reset()` for `Config`, and `Tests\Support\Absorber_State::reset()` for
`Absorber` plus the registration buffer on `Registry\Reader`, which also unwires the hooks `boot()`
added. Any older sketch showing `Config::reset()` or `Absorber::reset()` means the support helper.
`Registry\Registrar` needs no such helper — its state is instance state behind a container binding, so a
fresh container *is* the reset.

### Testing rules

`tests/unit/` mirrors `src/`, with one exception: `tests/unit/Scenario/` is named for what its files
describe rather than for a class, and drives the library end to end through the hooks a host fires,
against real WordPress state. `Bootstrap_Test_Case.php` is the abstract parent of `LoadTest.php`,
`ConflictTest.php` and `HostTest.php`. Full detail — every scenario, with a diagram — lives in
`tests/README.md`; the rules that bite hardest:

- **Never mock `exit()`.** `UopzFunctions::preventExit()` exists — do not use it. It lets a test run
  past the point where production would have stopped, so a test that should fail reports as passing.
  Instead stub the call immediately before `exit` (e.g. `wp_safe_redirect`), throw
  `Tests\Support\TestException` from it, catch it, and assert both a `$halted` flag *and* the
  message. Dropping the flag turns "never redirected at all" into a silent pass. That shape is
  factored into `Traits\WithHaltedRedirects` — use `$this->capture_redirect( … )` rather than
  hand-rolling it, so the flag cannot be the thing a new test forgets.
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
  `TypeError`. `Traits\WithContainer` is the one-line form: `set_up_container()`, `resolve()`,
  `tear_down_container()`.
- **Each load-path test writes its own bundled fixture file.** `require_once` caches by resolved
  path per PHP process, so a shared fixture makes every later test silently pass.
- **Shared fixtures go in a trait**, not a per-test helper. `Traits\WithSubPlugins` builds
  `Sub_Plugin` objects for the whole suite; `Traits\WithBundledPlugins` owns the fixture files and
  their guard constants; `Traits\WithUsers` supplies a user who can activate plugins, and
  `Traits\WithIncorrectUsage` asserts on `_doing_it_wrong()`. The spies — `Spy_Registrar`,
  `Spy_Writer`, `Spy_Presenter`, `Spy_Resolver`, `Spy_Gatekeeper`, `Spy_Rewriter`, `Spy_Activator` —
  are what gets bound into the test container in place of a real collaborator.
- **Data providers are generators** returning `Generator<string,array{…}>` with named cases.
- Tasks follow RED→GREEN: the failing test lands before the implementation.

## Invariants — do not "simplify" these away

- **Nothing reached from a hook is allowed to throw.** Configuration still throws: `Absorber::register()`
  rejects a bad config array on the spot, at a call in the developer's own stack trace, before
  anything is hooked. Past that point this library is code on somebody's live site, and a white screen
  is never the better answer — so every entry point it puts on a hook catches `Throwable`, reports
  with `_doing_it_wrong()` and abandons that step alone: both `plugins_loaded` steps in
  `Boot\Scheduler`, and `Absorber::render_notices()` on `all_admin_notices`. `Loader::load_all()` and
  `Conflict\Resolver::resolve_all()` catch *per sub-plugin* as well, because one sub-plugin's throw
  must not take the ones behind it in the registration order with it. Everything past those catches is
  somebody else's code — `enabled`, `dependency_check`, `activation_callback`, `conflict_policy`, the
  notice messages, the `should_load` filter, the bundled file a `require` runs top to bottom, the
  standalone's own deactivation hook, and every listener on the actions this library fires.
  `Loader::announce()` is the one that catches its own rather than falling to the enclosing catch,
  because by then the require has happened and the activation callback has run — left to the
  per-sub-plugin catch, a listener's bug would be reported as the sub-plugin having been abandoned,
  on the channel a host built its log line on. The one failure none of this can catch is a re-declaration
  fatal, which PHP does not raise as a `Throwable`; the guard constant, checked before the require, is
  what prevents that one.
- **The guard constant and the standalone basename are two separate keys.** No constant does double
  duty as both a load guard and a path resolver.
- **`get_hook_name()` and `get_option_name()` do not share a normalisation.** Folding case into the
  hook prefix itself would silently rename the host's filters; leaving the raw prefix in an option
  name puts `A-Z` and `-` into a storage key. Only the option side normalises, and collapsing the two
  code paths breaks whichever end it is collapsed toward.
- **Notice messages are rendered through `wp_kses_post()`, not escaped.** They come from the host's
  own config or filter, never from user input, so a knowledge-base link survives. Tightening this to
  `esc_html()` after 1.0 would break every host that shipped one.
- **On a key that also takes a string, a configured string is never called; every other callable
  form is.** That is `conflict_policy` and the two message keys — the three
  `Sub_Plugin::resolve_deferred()` reads, and the only three the rule was ever about. There a string
  function name is indistinguishable from a string value, so honouring it would make the result
  depend on what else the site loaded — `date`, `flush` and `key` are all real functions and
  plausible values. Closures, `[ class, method ]` pairs and invokable objects say "call me" and
  nothing else. `enabled`, `dependency_check` and `activation_callback` take no string at all, so
  nothing under them is ambiguous: a plain function name there is called, deliberately, and
  `SubPluginTest` pins that.
- **`conflict_notice_message` and `dependency_notice_message` reject a string outright.** Not just
  string callables — any string. A config array is built before `init`, so a translated string under
  one of these can only have been produced too early, and nothing in the value distinguishes it from
  one that was not. Refusing both costs the host a `static fn()` and fails the first time the code
  runs, where an eager `__()` is the `_load_textdomain_just_in_time` notice in someone else's log.
  `string | callable` is the shape this was drafted with and it is deliberately not what shipped.
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
- **`deactivate_plugins()` is called silent, with no `$network_wide` argument — and, under
  `DEACTIVATE`, only after the stranding guard clears.** Silent because a `flush_rewrite_rules()` in
  the standalone's deactivation hook at `plugins_loaded` 404s the site. The `null` default takes both
  the network and blog branches; a computed `true` strands an entry. The one topology the `null`
  default over-reaches is a **network-active standalone whose host is not network-active**: pulling it
  network-wide removes it from the sites the host never loads on, where nothing stands in for it.
  There `Conflict\Detector::deactivation_would_strand_sites()` reports the danger and `Conflict\Resolver`
  declines — the standalone stays, the load guard defers the bundled copy network-wide, and a stranding
  notice explains it. The guard is opt-in via `Config::set_host_plugin_basename()`; unset, behaviour is
  exactly the `null` default in every topology, so no host regresses.
- **`Plugin\Loads_Plugin_Functions` guards on `deactivate_plugins()`**, not `is_plugin_active()` —
  the latter is a common third-party shim.
- **Strauss must not rewrite `plugin_loaded_constant` values.** They are shared runtime constants;
  prefixing them defeats the entire mechanism.
- **`enabled` is the one key with no type behind it.** It is read as a boolean when it is not
  callable, so an array or an object there evaluates as enabled. That is documented rather than
  validated: adding a check is fine, but do not describe the other keys' registration-time rejection
  as covering this one.
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
  `Retry_Scheduler`, never a qualifier bought in advance. An abstract `-ion`/`-ance` noun may name a
  directory over agent nouns, the way `Boot/` holds `Scheduler`, but never a class: a census of 3,343
  classes across eight Nexcess/StellarWP codebases found zero. `Activator` sits at the root rather
  than under an `Activation/` of its own, because one class is not a folder.

## Branch and PR workflow

Branching is **stacked**: each branch cuts from the previous one and merges to `main` in order.
Branch names are `NN-topic`. Never open PR N+1 before PR N's branch exists. `main` is releasable
after every merge.

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

### Stacking with `gh stack`

**Stack whenever the work divides into portions a human could review separately.** The size cap
above is the floor, not the test: a change that fits in ten files still gets split if a reviewer
would have to hold two unrelated arguments in their head to sign it off. The unit is a decision a
reviewer can accept or reject on its own — one hook wired, one collaborator extracted, one
invariant enforced — with its own tests and its own `Why this way` block. A reviewer who can finish
a PR in one sitting reads it; a reviewer facing everything at once skims it, and skimming is how a
wrong decision reaches `main` with an approval on it.

What that rules out is splitting for its own sake. Each branch has to leave `main` releasable, so a
portion that cannot compile, pass the suite or be described in one `What` line is not a portion —
it belongs with the branch that completes it. When the work genuinely is one decision, one PR is
the right answer and a stack of one is nothing but ceremony.

**Every PR in a stack is titled `<shared prefix> [X/Y]: <rest of the title>`** — so
`Conflict handling [2/4]: resolve the standalone conflict` sits between the `[1/4]` and the `[3/4]`
of the same prefix. The prefix names the work all of them belong to and `X/Y` names the position and
the size, which is the whole of what a reviewer scanning the PR list needs: that these belong
together, which one to open first, and how much more is coming. `Y` is a count, so appending a
branch renumbers every PR already open — retitle them in the same pass as the `gh stack add`, or the
numbering says the series is shorter than it is. Nothing about the prefix survives `gh stack submit
--auto`, which titles a single-commit branch with that commit's subject and a multi-commit branch
with its own name, hyphens and underscores turned into spaces. There is no flag for a title, so set
it with `gh pr edit --title` after submitting; a later submit leaves what is already there alone.

**A PR based on another branch is not automatically a stack.** The commonest reason to cut from an
open branch instead of `main` is that both touch the same file and cutting from `main` would
guarantee a conflict — that is a base branch and nothing more. Point the PR's base at the branch
below it and stop; do not run `gh stack init`. A Stack on GitHub is a claim to the reviewer that the
PRs are one piece of work meant to be read in order, and making that claim about two changes that
merely share a file sends them looking for a through-line that was never there. Reach for the
tooling below only when the ordering is the argument.

The stack is managed with the **`github/gh-stack`** extension, invoked `gh stack`. Do not hand-roll
the stack with merges: `rebase` and `submit` force-update the branches the stack owns, which is what
keeps each PR's diff to its own commits. Four operations cover the whole workflow, and none of them
are guessable from `--help`, so use these verbatim.

**Adopt existing branches into a stack**, bottom to top, naming the trunk with `--base` so the trunk
gets no PR of its own:

```bash
gh stack init --base main 34-first 35-second 36-third
gh stack view --json              # confirm the order before submitting
```

`view` takes `--json` because a bare `gh stack view` is the interactive TUI; `--short` is the
one-line-per-branch form for a human reading along.

`--base` is whatever the bottom branch cut from. That is `main` for an ordinary series — and it is
the **tip branch of the lower stack** when a second stack is built on top of one that is still open,
which is the only way to keep the lower stack's PRs out of the new one.

**Append one already-existing branch** to a stack that exists. `gh stack init` refuses this with
"already exists in a stack", and `gh stack add` is what takes it: a name that is already a branch in
git is adopted, and only a name that is not gets created, which is the same rule `init` follows.
`add` has to run from the **top** of the stack, so get there with `gh stack top` rather than by
naming a branch — anywhere else it exits `5` with "can only add branches on top of the stack":

```bash
gh stack top
gh stack add 37-fourth
```

`add` leaves the working tree alone, so uncommitted changes follow you onto the branch it checks
out; commit or stash first. `gh stack modify` also adds branches, but it is an interactive TUI, so
it cannot be driven from a script or by an agent.

**Propagate an edit made low in the stack** by cascading rebase, never by merging the lower branch
upward: commit the edit on the lower branch, check out the **lowest changed** branch, and rebase
that branch and everything above it:

```bash
git checkout 35-second
gh stack rebase --upstack --preserve-dates
```

`--preserve-dates` — the alias for `--committer-date-is-author-date` — goes on *every* rebase, not
just this one. Without it each rebase restamps the committer date of every commit it rewrites, and
a stack is rebased repeatedly, so the whole history walks forward to whenever the last submit
happened.

`--upstack` is the other half: a rebase with no scope flag re-creates *every* commit in the stack, so
the submit after it force-pushes lower branches nothing changed on. Take the full-stack form only
when the trunk moved or the bottom branch itself did — and with the same flag:

```bash
gh stack rebase --preserve-dates
```

**Publish** with `gh stack submit --auto`: it pushes every branch, force where the rebase made that
necessary, and links them as a Stack on GitHub. It preserves existing PR titles, bodies and draft
state, so it is safe to re-run; `--open` is the flag that marks them ready for review. A
non-interactive run implies `--auto`.

## Known, and deliberately not fixed in 1.0.0

**`Activator::maybe_run()` can double-run under concurrency.** It reads the option, runs the
callback, then writes. Two simultaneous first requests both see the flag unset and both invoke the
callback — which may be a `create_tables()`. Claiming the slot with `add_option()` before invoking
would close the window. The ordering is not the bug and must not be "fixed" by recording first: the
callback runs before the record so that a fatal mid-callback retries on the next request instead of
freezing a half-finished migration in place, permanently and invisibly.

## Documentation

**This file is the durable document.** The design spec, the task-by-task implementation plan, the
container-required rework plan and the superseded `engineering-plan.md` first draft were all deleted
once the last PR of the 1.0.0 series was written. Every decision they still carried is restated
above; git history has them in full if a rationale needs reading back.

Do not reintroduce them, and do not write a new plan file for work that is already built. A plan
section describing code that exists in `src/` cannot do anything but make the next reader scroll past
it, and a plan edited on every branch of a stacked series is a standing merge-conflict surface. The
first draft was worse than useless by the end: it still named the package `stellarwp/sub-plugin-loader`
under a `Nexcess\SubPluginLoader\` namespace, with a `Config::set_version()` that was removed and an
`ob_start()` approach the `wp_admin_notice_markup` filter replaced.

Human-facing docs are `README.md` plus `docs/installing.md`, `docs/configuration.md`,
`docs/recipes.md`, `docs/conflict-handling.md`, `docs/filters.md`, `docs/notices.md` and
`docs/extending.md`. Keep them short and keep rationale here or in code comments — do not grow the
README back. They are written for a host developer integrating the library, not for a maintainer:
`docs/extending.md` is the only one that names internal classes, and every other file describes
behaviour instead. `docs/` is `export-ignore`d and
`README.md` is not, so a link from the README into `docs/` must be an absolute repository URL; links
*between* files inside `docs/` stay relative.
