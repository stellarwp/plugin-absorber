# Extending

Everything the library does past registration is a small object resolved from your container, so any
one piece can be swapped without replacing the rest. This is the one doc that names those classes;
nothing else in the docs asks you to know them.

## The seams

Bind any of these ids before or after `Absorber::boot()` — boot binds the defaults and skips an
*interface* your container already answers for, so your binding wins either way:

```php
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;

$container->singleton( Registrar_Interface::class, My_Registrar::class );
```

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | Holds the registered sub-plugins. |
| `Notices\Contracts\Writer_Interface` | `Notices\Writer` | Words the admin notices. |
| `Contracts\Plugin_Deactivator_Interface` | `Plugin_Deactivator` | Deactivates the standalone. |
| `Contracts\Plugin_Checker_Interface` | `Plugin_Checker` | Answers whether a plugin is active. |
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | Applies the policy to a conflict. |
| `Contracts\Activator_Interface` | `Activator` | Runs a sub-plugin's activation callback once, ever. |

**Rebind `Plugin_Checker_Interface` when your plugin filters `option_active_plugins` or
`site_option_active_sitewide_plugins`** — LearnDash injects and then strips a synthetic path — because
`is_plugin_active()` then does not report what is in the database.

**Rebind `Activator_Interface` to record "once, ever" somewhere else**: your own migration table, or
a per-site option on a large multisite network where one run for the whole network is not what you
want. See [the recipe](recipes.md#do-per-site-work-on-multisite).

## Class-name bindings must come after boot

Everything without an interface is bound by class name — `Notices\Store`, `Notices\Renderer`,
`Notices\Presenter`, `Conflict\Detector`, `Conflict\Gatekeeper`, `Conflict\Redirector`,
`Conflict\Rewriter`, `Loader`, `Registry_Reader`, `Boot\Scheduler`. Bind one of those **after**
`Absorber::boot()`: di52 reports `has()` true for any class that exists, bound or not, so boot cannot
tell your binding from the container's own willingness to build the class, and replaces it.

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

`Absorber::registrar()`, `notices()` and `resolver()` check what your container hands back and throw
a `Config_Exception` naming the interface and the class that failed it, rather than letting a
`TypeError` blame this library for your typo inside `plugins_loaded`. Whatever your container raises
for a binding it cannot build at all comes through unwrapped. `Absorber::all()` drops anything a
rebound registrar returns that is not a `Sub_Plugin`.

Nothing is built at boot beyond the two objects that do the booting: each hook resolves its
collaborator when it fires, so a request that reaches none of them builds none of them, and you may
rebind right up until the hook runs.
