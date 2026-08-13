# Configuration

## Host configuration

```php
use Nexcess\PluginAbsorber\Config;

Config::set_hook_prefix( 'give' );          // required — keys hooks and options
Config::set_container( give()->container ); // required — every collaborator resolves from it
```

The hook prefix accepts letters, numbers, hyphens, and underscores. Anything else throws
`Config_Exception`, as does reading the prefix before it is set. Hook names repeat it verbatim;
option names lowercase it and turn hyphens into underscores, so `Give-Core` hooks
`Give-Core/plugin_absorber/should_load` and stores `give_core_plugin_absorber_notices`.

## The container

Both calls are required, and both belong at `plugins_loaded` priority 0, in your own container
block rather than in a service provider.

Any implementation of StellarWP's `ContainerInterface` will do — the one your plugin already hands
to Telemetry, Uplink or Harbor. `Config::get_container()` throws `Config_Exception` when none is
set; `Config::has_container()` is the probe if you need to ask.

Priority matters twice, for two unrelated reasons. Conflict resolution runs at `plugins_loaded`
priority 5 and the load at priority 6, and WordPress silently ignores a callback added at or past the
priority it is already dispatching — so boot has to land before 5, which leaves 0 through 4. And a
host that builds its container lazily may *replace* it at priority 0; hand us the container before
that happens and we hold an orphan whose bindings were discarded. It is that second one that picks 0
out of the five, so if your container is already built by then, anywhere below 5 works.

## Rebinding a collaborator

`Absorber::boot()` binds the defaults, and skips any id your container already has — so your binding
wins whether you make it before boot or after, and nothing is resolved until `plugins_loaded`
priority 5 in any case:

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
| `Conflict\Contracts\Resolver_Interface` | `Conflict\Resolver` | Detects the active standalone and applies the policy. |
| `Contracts\Activator_Interface` | `Activator` | Runs a sub-plugin's activation callback once, ever. |

`Plugin_Checker_Interface` is the seam to rebind when your plugin filters `option_active_plugins` or
`site_option_active_sitewide_plugins` — LearnDash injects and then strips a synthetic path — because
`is_plugin_active()` then does not report what is in the database.

Rebinding `Resolver_Interface` does not put you in charge of *when* resolution may run. Both gates —
[an interactive admin `GET` that carries no action, and the capability to deactivate across the
network](conflict-handling.md#when-resolution-runs) — live in `Conflict\Gatekeeper`, which the hook
consults rather than the resolver, so an implementation that never thought about either is still
safe. Everything the resolver *does* — which policy branch, what the notice says,
where the user lands — is yours.

`set_container()` is a configuration call like `set_hook_prefix()`, and order does not matter among
the configuration calls: it may come before or after your `Absorber::register()` calls, so long as it
comes before boot. Registering buffers the sub-plugin and resolves nothing, so nothing is decided
until the first read.

A binding that does not implement the interface it is bound to throws `Config_Exception` when it is
resolved, rather than being cached and failing later somewhere less obvious. So does a binding whose
factory throws — with the original failure kept as the previous exception.

The container is **not** used to wire hooks. Those are closures that resolve when they fire, so
registering them instantiates nothing and a request that triggers none builds none.

## Sub-plugin keys

| Key | Type | Required | Meaning |
|---|---|:--:|---|
| `slug` | `string` | ✔ | Unique id — registry key, notice id, activation-tracking key. |
| `bundled_plugin_file` | `string` | ✔ | Absolute path to the **bundled** plugin's main file. This is what gets `require_once`d. |
| `plugin_loaded_constant` | `string` | ✔ | A constant the plugin defines when it loads. Both copies must define the *same* name, **at file scope**. See [Conflict handling](conflict-handling.md). **Load guard only.** |
| `standalone_plugin_basename` | `string` | | The standalone's `dir/file.php` basename. Used for `is_plugin_active()` and `deactivate_plugins()`. Omit when there is no standalone. **Detection only.** |
| `enabled` | `bool\|callable` | | `true` by default. A `callable( Sub_Plugin ): bool` is re-evaluated on every call, not cached. |
| `conflict_policy` | `string\|callable` | | `Conflict_Policy::DEACTIVATE` by default. |
| `conflict_notice_message` | `callable` | | Shown on auto-deactivation and on a re-activation attempt. Empty by default. |
| `dependency_notice_message` | `callable` | | Shown when `dependency_check` fails. Defaults to a generic, untranslated sentence naming the raw slug. |
| `activation_callback` | `callable( Sub_Plugin )` | | Runs **once, ever**, per slug, after a successful load. Make it idempotent. |
| `dependency_check` | `callable( Sub_Plugin ): bool` | | Skips the load and queues a notice when it returns false. |

The load guard and the standalone basename are deliberately two separate keys. No constant does
double duty as both a guard and a path resolver.

## Registration

Sub-plugins load in **registration order**, so register a dependency before anything that extends it
at include time.

Register each slug exactly once. A slug also names the sub-plugin's notices and its once-ever
activation record, so a second registration under the same slug is refused with a
`Config_Exception` naming both bundled files rather than quietly dropping one of the two from the
load. Registrations are buffered and nothing reads them until `plugins_loaded` — the conflict pass at
priority 5 on an admin page view, the load pass at priority 6 on everything else — so that is where
the collision surfaces, not at the second `register()` call and not at `boot()`. Whichever pass
reads first reports it with `_doing_it_wrong()`, and that request resolves no conflict and loads no
sub-plugin at all, rather than throwing out of a core hook. A config array the library cannot use is still rejected on the spot.
Register unconditionally and put anything you cannot decide up front — a licence that may not be
active, a setting the site owner can change — in `enabled`, which is re-evaluated on every load.

## Activation

A bundled plugin is `require_once`d, not activated, so `register_activation_hook()` never fires for
it — whatever that hook would have done, creating a table or seeding options, would otherwise never
happen at all. [`activation_callback`](#sub-plugin-keys) fills that gap:

```php
'activation_callback' => static function ( Sub_Plugin $sub_plugin ) {
    \Give\Recurring\Install::create_tables();
},
```

It runs once ever per slug, is passed the `Sub_Plugin`, and runs only after a require that actually
happened — never for a sub-plugin whose load was skipped, because a schema appearing for a plugin
that is not loaded is worse than no schema at all.

**Write it to be idempotent.** "Once, ever" is bookkeeping, not a lock: the record is read, the
callback runs, and the record is written, so two requests arriving together on a site that has never
run it can both pass the check, and a callback that fails is deliberately left unrecorded to be
retried. A `dbDelta()` migration or a `CREATE TABLE IF NOT EXISTS` already survives both; a blind
`INSERT` of seed rows does not.

The record lives in the `{option_prefix}_plugin_absorber_activations` option, a network option on
multisite for the same reason the [notice queue](notices.md) is one: `deactivate_plugins()` is
network-wide, so a merge that happened network-wide must not re-run the callback on every site. The
slug is recorded *after* the callback returns, so a callback that fails is retried on the next
request rather than marked done and silently skipped forever. A callback that throws cannot take the
site down with it: the load pass reports it with `_doing_it_wrong()`, loads the sub-plugins behind it
as usual, and leaves the record unwritten.

One record for the network also means one *run* for the network, in whichever site's request reached
the load pass first. Per-site work — a `$wpdb->prefix` table, a per-site option — is the callback's
own job to loop over `get_sites()` for, or bind `Activator_Interface` and record "once, ever"
somewhere else: your own migration table, or a per-site option.

## The bundled file is included from a function, not from global scope

WordPress includes plugins from `wp-settings.php` at global scope; this library includes them from
inside a method. Variables assigned at the top level of the bundled file are therefore function-local
and do not become globals:

```php
// In the bundled plugin's main file.
$my_plugin = new My_Plugin();             // Not a global. `global $my_plugin;` elsewhere sees null.
$GLOBALS['my_plugin'] = new My_Plugin();  // Works.
```

Everything else — function and class declarations, `define()`, hook registration, `__FILE__` — is
unaffected. Bundle a plugin that publishes its instance through `$GLOBALS`, a singleton or a
container, which is what plugins written in the last decade do anyway. No amount of wrapping on this
side can hand a required file the global scope it would have had.

## Messages are callables, never strings

Your config array is built at plugin load — before `init`, and before your textdomain. Calling
`__()` there is what raises WordPress's `_load_textdomain_just_in_time` notice. So the two message
keys take something to call, and refuse a string outright:

```php
'conflict_notice_message' => static fn() => __( 'Recurring ships with Give now.', 'give' ),
'conflict_notice_message' => [ Give_Recurring::class, 'get_conflict_message' ],
'conflict_notice_message' => static fn() => give()->container->get( Conflict_Message::class ),
```

```php
// Config_Exception at registration. Translated or not, a string here can only have been produced
// too early -- and nothing in the value says which it was.
'conflict_notice_message' => __( 'Recurring ships with Give now.', 'give' ),
```

Each callable is passed the `Sub_Plugin` and called on every read, so nothing is resolved at
registration. A return that will not cast to a string is treated as though nothing were configured.

**A plain function name is text, not a call.** `date`, `flush` and `key` are all real functions and
all plausible values, so wherever a string *is* accepted it is the value itself. That bars both
string spellings of a callable — `'give_recurring_conflict_message'` and
`'Give_Recurring::get_conflict_message'` — in favour of the array and closure forms above.

`conflict_policy` is the one key that takes either. A policy is usually a `Conflict_Policy`
constant with nothing to defer, and it is never text a user reads:

```php
'conflict_policy' => Conflict_Policy::DEFER,
'conflict_policy' => static fn( Sub_Plugin $sub_plugin ) => give_conflict_policy_for( $sub_plugin ),
```

`standalone_plugin_basename` takes a string only: it names a file already on disk, so there is
nothing to wait for.

`dependency_check`, `activation_callback` and `enabled` have nothing a string could collide with, so
they accept every callable form, a plain function name included.

Every key rejects a shape it cannot use at registration rather than at read time — including a
`[ class, method ]` pair naming a method that does not exist.

The [filters](filters.md) are the other way in, and they run last — after the configured value and
any fallback, so they see the default text too.
