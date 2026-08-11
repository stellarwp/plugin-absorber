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

## Sub-plugin keys

| Key | Type | Required | Meaning |
|---|---|:--:|---|
| `slug` | `string` | ✔ | Unique id — registry key, notice id, activation-tracking key. |
| `bundled_plugin_file` | `string` | ✔ | Absolute path to the **bundled** plugin's main file. This is what gets `require_once`d. |
| `plugin_loaded_constant` | `string` | ✔ | A constant the plugin defines when it loads. Both copies must define the *same* name, **at file scope**. See [Conflict handling](conflict-handling.md). **Load guard only.** |
| `standalone_plugin_basename` | `string` | | The standalone's `dir/file.php` basename. Used for `is_plugin_active()` and `deactivate_plugins()`. Omit when there is no standalone. **Detection only.** |
| `enabled` | `bool\|callable` | | `true` by default. A `callable( Sub_Plugin ): bool` is re-evaluated on every call, not cached. |
| `conflict_policy` | `string` | | `Conflict_Policy::DEACTIVATE` by default. |
| `conflict_notice_message` | `string` | | Shown on auto-deactivation and on a re-activation attempt. Empty by default. |
| `dependency_notice_message` | `string` | | Shown when `dependency_check` fails. Defaults to a generic, untranslated sentence naming the raw slug. |
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
load. Register unconditionally and put anything you cannot decide up front — a licence that may not
be active, a setting the site owner can change — in `enabled`, which is re-evaluated on every load.

## Values or callables, never both

Every key is either a value or a callable. The four `string` keys take strings only — a string
function name is indistinguishable from a string value, and honouring both would make the outcome
depend on whatever else the site has loaded. A non-string under one of them throws
`Config_Exception` when the sub-plugin is registered.

So a function name under one of them is stored and returned as text. It is never called, however
real the function is:

```php
// Returns the literal string 'give_recurring_conflict_message'. It is not invoked.
'conflict_notice_message' => 'give_recurring_conflict_message',
```

To decide a policy or a message at runtime — or to defer `__()` until after the textdomain
loads — use the [filters](filters.md):

```php
add_filter(
    'give/plugin_absorber/conflict_notice_message',
    static function ( string $message, Sub_Plugin $sub_plugin ): string {
        return give_recurring_conflict_message( $sub_plugin );
    },
    10,
    2
);
```

`dependency_check`, `activation_callback`, and `enabled` have nothing a string could collide with,
so they accept every callable form, a plain function name included. A key in that group that holds
something uncallable also throws `Config_Exception` at registration.
