# Extending

Everything the library does past registration is a small object resolved from your container, so any
one piece can be swapped without replacing the rest. This is the one doc that names those classes;
nothing else in the docs asks you to know them.

## The seams

Bind any of these ids before or after `Absorber::boot()` — boot binds the defaults and skips an
*interface* your container already answers for, so your binding wins either way:

```php
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;

$container->singleton( Registrar_Interface::class, My_Registrar::class );
```

| Interface | Default | Responsibility |
|---|---|---|
| `Registry\Contracts\Registrar_Interface` | `Registry\Registrar` | Holds the registered sub-plugins. |
| `Notices\Contracts\Writer_Interface` | `Notices\Writer` | Words the admin notices. |
| `Plugin\Contracts\Deactivator_Interface` | `Plugin\Deactivator` | Deactivates the standalone. |
| `Plugin\Contracts\Checker_Interface` | `Plugin\Checker` | Answers whether a plugin is active. |
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | Applies the policy to a conflict. |
| `Contracts\Activator_Interface` | `Activator` | Runs a sub-plugin's activation callback once, ever. |

**Rebind `Plugin\Contracts\Checker_Interface` when your plugin filters `option_active_plugins` or
`site_option_active_sitewide_plugins`** — LearnDash injects and then strips a synthetic path — because
`is_plugin_active()` then does not report what is in the database.

**Rebind `Activator_Interface` to record "once, ever" somewhere else**: your own migration table, or
a per-site option on a large multisite network where one run for the whole network is not what you
want. See [the recipe](recipes.md#do-per-site-work-on-multisite).

## What each seam must implement

The table names the job; these are the methods, and every one of them is required — PHP refuses to
load a class that leaves one out, at class-declaration time rather than when the library first calls
it. Two of this library's types appear below:

```php
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;
```

`Sub_Plugin` is one registered configuration, and its accessors — `get_slug()`,
`get_standalone_plugin_basename()`, the message accessors named below — are how an implementation
reads the config keys and runs [their filters](filters.md). A throw from any of these is caught at
the hook boundary and reported through `_doing_it_wrong()`, so it costs that one step rather than the
site; nothing retries it on the same request.

### `Registry\Contracts\Registrar_Interface`

```php
public function register( Sub_Plugin $sub_plugin ): void;
public function all(): array;
```

`register()` stores one sub-plugin, and a slug may only be registered once — throw a
`Config_Exception` on the second rather than overwriting, or a copy-pasted slug silently replaces the
sub-plugin it collided with. `all()` returns every registered sub-plugin keyed by slug, in
registration order: `array<string,Sub_Plugin>`. The library narrows that array to `Sub_Plugin`
instances before reading it, so anything else in it is dropped rather than fataling — which loses a
sub-plugin quietly, and is worth not doing.

### `Notices\Contracts\Writer_Interface`

```php
public function queue_merge_notice( Sub_Plugin $sub_plugin ): void;
public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void;
public function queue_stranding_notice( Sub_Plugin $sub_plugin ): void;
public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void;
public function option_name(): string;
```

- `queue_merge_notice()` — a deactivation has just been performed. Raised exactly once and never
  re-queued, and the resolver redirects immediately afterwards, so it has to be kept somewhere that
  outlives the request that wrote it. Word it from
  `$sub_plugin->get_conflict_notice_message( $your_default )`, which applies the config key and its
  filter over the default you pass.
- `queue_conflict_notice()` — `NOTICE_ONLY`: the standalone is still running and the user is being
  asked to turn it off. Same accessor, a default that asks rather than reports.
- `queue_stranding_notice()` — multisite only, and recurring rather than once-ever: a network-active
  standalone was left active because deactivating it network-wide would strand the sites the host
  plugin never reaches. Its wording is `$sub_plugin->get_stranding_notice_message()`, and it must not
  tell the user to deactivate the standalone — see
  [the stranding guard](conflict-handling.md#the-multisite-stranding-guard).
- `queue_dependency_notice()` — `dependency_check` returned false, so the sub-plugin did not load at
  all. `$sub_plugin->get_dependency_notice_message()`.
- `option_name()` — where *your* implementation keeps the queue. It is on the contract rather than on
  the default class because `Absorber::notices()->option_name()` is what a host
  [rendering the queue itself](notices.md#rendering-them-yourself) reads: name an option nothing
  writes to and that host reads an empty one.

### `Plugin\Contracts\Deactivator_Interface`

```php
public function deactivate( string $basename ): void;
```

One method, one argument: a plugin basename like `give-recurring/give-recurring.php`, the only
identifier WordPress itself accepts. Called unattended during `plugins_loaded`, under the
`DEACTIVATE` policy, on behalf of a user who did not ask for it. It reports nothing — whether the
standalone actually went away is asked of the checker afterwards, not of you — so a no-op
implementation is the honest way to say "plugin state is managed outside WordPress here", and an
implementation that means to deactivate but does not leaves two copies of the plugin to load.

### `Plugin\Contracts\Checker_Interface`

```php
public function is_active( string $basename ): bool;
public function is_network_active( string $basename ): bool;
```

Two methods, asked for different reasons. `is_active()` answers "this plugin's code is going to run
this request", so both scopes count: a network activation runs it as surely as a site one.
`is_network_active()` is network scope only, and it is the one question the
[stranding guard](conflict-handling.md#the-multisite-stranding-guard) asks — "would deactivating this
reach every site". Return `false` whenever the site is not multisite, exactly as core's
`is_plugin_active_for_network()` does: callers lean on that instead of guarding with `is_multisite()`
themselves, so answering `true` off a network can have the guard decline a deactivation on a site
with nothing to strand.

```php
use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;

class My_Checker implements Checker_Interface {
    public function is_active( string $basename ): bool {
        // Whatever your plugin considers the real list, before your own filters touch it.
        return in_array( $basename, my_plugin_stored_active_plugins(), true )
            || $this->is_network_active( $basename );
    }

    public function is_network_active( string $basename ): bool {
        if ( ! is_multisite() ) {
            return false;
        }

        $network_active = get_site_option( 'active_sitewide_plugins', [] );

        return is_array( $network_active ) && array_key_exists( $basename, $network_active );
    }
}
```

### `Conflict\Contracts\Resolver_Interface`

```php
public function resolve_all(): void;
```

Called once per request, at `plugins_loaded` priority 5, and only after the
[request and capability gates](conflict-handling.md#when-resolution-runs) pass and a conflict has
been found. It is handed nothing: read the registry from the container your
implementation was built with, and resolve every sub-plugin whose standalone is active — including
the ones behind the first, which is why the default catches per sub-plugin. Ending the request is
allowed, and the default does (`wp_safe_redirect()` then `exit`) — but after the loop, never inside
it, or a site with two active standalones never reaches the second.

### `Contracts\Activator_Interface`

```php
public function maybe_run( Sub_Plugin $sub_plugin ): void;
```

Called by the load pass after a `require_once` that actually happened, on every request, for every
sub-plugin that loaded. Deciding it has already run for this slug is the whole job:
`$sub_plugin->get_activation_callback()` hands back the configured `callable` or `null`, it is
invoked with the `Sub_Plugin`, and where "already run" is recorded is yours. Record it after the
callback returns rather than before, so a callback that throws is retried on the next request instead
of being marked done half-finished.

## Class-name bindings must come after boot

Everything without an interface is bound by class name — `Notices\Store`, `Notices\Renderer`,
`Notices\Presenter`, `Conflict\Detector`, `Conflict\Gatekeeper`, `Conflict\Redirector`,
`Conflict\Rewriter`, `Loader`, `Registry\Reader`, `Boot\Scheduler`. Bind one of those **after**
`Absorber::boot()`: di52 reports `has()` true for any class that exists, bound or not, so boot cannot
tell your binding from the container's own willingness to build the class, and replaces it.

Boot also binds `StellarWP\ContainerContract\ContainerInterface` to the container itself, first and
before anything else, so a container that builds unbound classes reflectively can still satisfy the
library classes that take one. That id is an interface rather than a class, so the skip above applies
to it: a container that already answers for it keeps whatever it has, whenever you bound it.

## What rebinding does not buy you

Binding your own `Conflict\Contracts\Resolver_Interface` does not put you in charge of *when*
resolution may run. The request and capability gates — [an interactive admin `GET` carrying no
action, and the capability to deactivate across the
network](conflict-handling.md#when-resolution-runs) — are asked before your resolver is built at all,
so an implementation that never thought about either is still safe. Everything the resolver *does* —
which policy branch, what the notice says, where the user lands — is yours.

## The notice queue

Four objects, so you can replace the part you have an opinion about:

- `Notices\Writer` decides what a notice says. The one behind an interface, and the seam for a host
  already running its own notices library.
- `Notices\Store` keeps the queue. Rebind to store it elsewhere.
- `Notices\Renderer` draws it. Rebind to change the markup and leave the storage alone.
- `Notices\Presenter` decides who may consume it, and does the render-then-clear.

Rendering the queue yourself needs none of this — read
[Notices](notices.md#rendering-them-yourself) instead.

## Removing the built-in admin hooks

Both admin-side hooks are named callbacks, so `remove_filter()` reaches them:

```php
// Keep core's wording on the plugin activation error screen.
remove_filter( 'wp_admin_notice_markup', [ Absorber::class, 'filter_activation_error_markup' ] );

// Render the notice queue yourself, and nowhere else.
remove_action( 'all_admin_notices', [ Absorber::class, 'render_notices' ] );
```

## When a binding is wrong

`Absorber::registrar()`, `notices()`, `resolver()` and `all()` check what your container hands back
and throw a `Config_Exception` naming the id they asked for and the class that failed it, rather than
letting a `TypeError` blame this library for your typo inside `plugins_loaded`. A binding your
container cannot build at all is reported the same way and not raised at you raw: whatever it threw
is caught and wrapped in a `Config_Exception` that names the id, keeping the original as
`getPrevious()`. `Absorber::boot()` is the one that does not wrap — a container that cannot build the
provider or the scheduler throws its own exception out of your `boot()` call. Past the check,
`Absorber::all()` also drops anything a rebound registrar returns that is not a `Sub_Plugin`.

Nothing is built at boot beyond the two objects that do the booting: each hook resolves its
collaborator when it fires, so a request that reaches none of them builds none of them, and you may
rebind right up until the hook runs.
