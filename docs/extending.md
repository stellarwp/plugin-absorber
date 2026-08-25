# Extending

Everything the library does past registration is a small object resolved from your container, so
any one piece can be swapped on its own. This is the one doc that names those classes.

## The seams

Bind any of these interface ids before or after `Absorber::boot()` — boot binds the defaults and
skips an interface your container already answers for, so your binding wins either way;
[class-name ids are different](#class-name-bindings-must-come-after-boot):

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
`site_option_active_sitewide_plugins`**: `is_plugin_active()` then does not report what is in the
database.

**Rebind `Activator_Interface` to record "once, ever" somewhere else**: your own migration table, or
a per-site option on a large network. See [the recipe](recipes.md#do-per-site-work-on-multisite).

## What each seam must implement

Every method below is required. Two of this library's types appear in them:

```php
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;
```

`Sub_Plugin` is one registered configuration; its accessors — `get_slug()` and the message
accessors below — read the config keys and run [their filters](filters.md). A throw from one is
caught at the hook boundary and reported through `_doing_it_wrong()`; nothing is recorded, so the
next request tries again.

### `Registry\Contracts\Registrar_Interface`

```php
public function register( Sub_Plugin $sub_plugin ): void;
public function all(): array;
```

`register()` stores one sub-plugin; throw a `Config_Exception` on a slug already registered rather
than overwriting it. `all()` returns `array<string,Sub_Plugin>`, keyed by slug, in registration
order — anything else in it is dropped quietly.

### `Notices\Contracts\Writer_Interface`

```php
public function queue_merge_notice( Sub_Plugin $sub_plugin ): void;
public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void;
public function queue_stranding_notice( Sub_Plugin $sub_plugin ): void;
public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void;
public function option_name(): string;
```

- `queue_merge_notice()` — a deactivation has just been performed. Raised once, never re-queued,
  and followed by a redirect, so keep it somewhere that outlives the request. Word it from
  `$sub_plugin->get_conflict_notice_message( $your_default )`, which applies the config key and its
  filter over it.
- `queue_conflict_notice()` — `NOTICE_ONLY`: the standalone is still running and the user is being
  asked to turn it off. Same accessor, a default that asks rather than reports.
- `queue_stranding_notice()` — multisite only, and recurring rather than once-ever: a network-active
  standalone was left running rather than strand sites. Word it from
  `$sub_plugin->get_stranding_notice_message()`, and do not tell the user to deactivate it — see
  [the stranding guard](conflict-handling.md#the-multisite-stranding-guard).
- `queue_dependency_notice()` — `dependency_check` returned false, so the sub-plugin did not load at
  all. `$sub_plugin->get_dependency_notice_message()`.
- `option_name()` — where *your* implementation keeps the queue, and what a host
  [rendering the queue itself](notices.md#rendering-them-yourself) reads through
  `Absorber::notices()->option_name()`.

### `Plugin\Contracts\Deactivator_Interface`

```php
public function deactivate( string $basename ): void;
```

One argument: a plugin basename like `give-recurring/give-recurring.php`. Called unattended during
`plugins_loaded`, under the `DEACTIVATE` policy. It reports nothing — the checker is asked
afterwards whether the standalone went away — so a deliberate no-op is fine, while a silent
failure leaves two copies to load.

### `Plugin\Contracts\Checker_Interface`

```php
public function is_active( string $basename ): bool;
public function is_network_active( string $basename ): bool;
```

`is_active()` answers "this plugin's code is going to run this request", so both scopes count.
`is_network_active()` is network scope only, and is what the
[stranding guard](conflict-handling.md#the-multisite-stranding-guard) asks: return `false` off
multisite, as core's `is_plugin_active_for_network()` does, since `true` there has the guard decline
a deactivation with nothing to strand.

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

Called once per request at `plugins_loaded` priority 5, after the
[request and capability gates](conflict-handling.md#when-resolution-runs) pass and a conflict is
found. It is handed nothing: read the registry from the container it was built with and resolve
every sub-plugin whose standalone is active, catching per sub-plugin. Ending the request is allowed
— the default does `wp_safe_redirect()` then `exit` — but after the loop, never inside it.

### `Contracts\Activator_Interface`

```php
public function maybe_run( Sub_Plugin $sub_plugin ): void;
```

Called on every request, for every sub-plugin whose `require_once` actually happened; deciding it
has already run for this slug is the whole job. `$sub_plugin->get_activation_callback()` hands back
the configured `callable` or `null` — invoke it with the `Sub_Plugin` and record "already run"
after the callback returns, so one that throws is retried rather than marked done.

## Class-name bindings must come after boot

Everything without an interface is bound by class name — `Notices\Store`, `Notices\Renderer`,
`Notices\Presenter`, `Conflict\Detector`, `Conflict\Gatekeeper`, `Conflict\Redirector`,
`Conflict\Rewriter`, `Loader`, `Registry\Reader`, `Boot\Scheduler`. Bind those **after**
`Absorber::boot()`: di52 reports `has()` true for any class that exists, bound or not, so boot
cannot tell your binding from an autowirable class, and overwrites it.

Boot also binds `StellarWP\ContainerContract\ContainerInterface` to the container itself, so library
classes that take one can be built reflectively — an interface id, so your own binding stands.

## What rebinding does not buy you

Binding your own `Conflict\Contracts\Resolver_Interface` does not put you in charge of *when*
resolution runs: the gates — [an interactive admin `GET` carrying no action,
and the capability to deactivate across the
network](conflict-handling.md#when-resolution-runs) — are asked before your resolver is built. What
it *does* is yours.

## The notice queue

Four objects, so you can replace the part you have an opinion about:

- `Notices\Writer` decides what a notice says — the one behind an interface.
- `Notices\Store` keeps the queue; rebind to store it elsewhere.
- `Notices\Renderer` draws it; rebind for the markup alone.
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
and throw a `Config_Exception` naming the id and the class that failed it. A binding the container
cannot build at all is wrapped the same way, keeping the original as `getPrevious()` — except in
`Absorber::boot()`, where a container that cannot build the provider or the scheduler throws its own
exception out of your `boot()` call. Past the check, `Absorber::all()` drops anything a rebound
registrar returns that is not a `Sub_Plugin`, and `Absorber::registrar()` hands back a registrar with
every registration made so far already in it, so the two answer alike whenever you ask.

`Absorber::resolver()` hands you the resolver, not the gates in front of it: `resolve_all()`
re-checks neither, so calling it yourself can deactivate a plugin and `exit` on a POST, a cron run,
or a request from someone who may not deactivate anything. Make both checks first.
