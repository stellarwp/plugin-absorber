# Configuration

## Host configuration

```php
use Nexcess\PluginAbsorber\Config;

Config::set_hook_prefix( 'give' );          // required — keys hooks and options
Config::set_container( give()->container ); // optional — lets you rebind collaborators
```

The hook prefix accepts letters, numbers, hyphens, and underscores. Anything else throws
`Config_Exception`, as does reading the prefix before it is set. Hook names repeat it verbatim;
option names lowercase it and turn hyphens into underscores, so `Give-Core` hooks
`Give-Core/plugin_absorber/should_load` and stores `give_core_plugin_absorber_notices`.

The container is optional. Without one, the library instantiates its own collaborators; with one,
a host can rebind them.

## Rebinding a collaborator

Every collaborator is interface-backed. With a container set, bind one to override the library
globally; with no container, the defaults are used and nothing is required.

```php
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;

$container->singleton( Registrar_Interface::class, My_Registrar::class );
Config::set_container( $container );
```

| Interface | Default | Responsibility |
|---|---|---|
| `Contracts\Registrar_Interface` | `Registrar` | Holds the registered sub-plugins. |
| `Notices\Contracts\Queue_Interface` | `Notices\Queue` | Queues and renders the admin notices. |

`set_container()` is a configuration call like `set_hook_prefix()`, and order does not matter: it may
come before or after your `Loader::register()` calls, so long as it comes before boot. Registering
buffers the sub-plugin and resolves nothing, so nothing is decided until the first read.

A binding that does not implement the interface it is bound to throws `Config_Exception` when it is
resolved, rather than being cached and failing later somewhere less obvious. So does a binding whose
factory throws — with the original failure kept as the previous exception.

The container is **not** used to wire hooks — those stay plain static callbacks, so the container
stays genuinely optional.

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
| `activation_callback` | `callable( Sub_Plugin )` | | Runs **exactly once, ever**, per slug. |
| `dependency_check` | `callable( Sub_Plugin ): bool` | | Skips the load and queues a notice when it returns false. |

The load guard and the standalone basename are deliberately two separate keys. No constant does
double duty as both a guard and a path resolver.

## Registration

Sub-plugins load in **registration order**, so register a dependency before anything that extends it
at include time.

Register each slug exactly once. A slug also names the sub-plugin's notices and its once-ever
activation record, so a second registration under the same slug is refused with a
`Config_Exception` naming both bundled files rather than quietly dropping one of the two from the
load. Because registrations are buffered until boot, that collision is reported at boot rather than
at the second `register()` call; a config array the library cannot use is still rejected on the spot.
Register unconditionally and put anything you cannot decide up front — a licence that may not be
active, a setting the site owner can change — in `enabled`, which is re-evaluated on every load.

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
