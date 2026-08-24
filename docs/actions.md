# Actions

What the library tells you as it runs. [Filters](filters.md) are the other direction — the values
you override. `{prefix}` is the value passed to `Config::set_hook_prefix()`.

| Action | Arguments | Fires when |
|---|---|---|
| `{prefix}/plugin_absorber/loaded` | `Sub_Plugin $sub_plugin` | A bundled file was required, and its activation callback has already run. |
| `{prefix}/plugin_absorber/skipped` | `Sub_Plugin $sub_plugin`, `string $reason` | A gate turned a sub-plugin away. |

Between them they say what happened to every sub-plugin you registered: one or the other fires
once per registration, every load pass.

## Loading

`loaded` is the answer to "is this sub-plugin here?" without a `defined()` check of your own, and it
is where code that builds on a sub-plugin belongs:

```php
add_action( 'give/plugin_absorber/loaded', function ( $sub_plugin ) {
    if ( $sub_plugin->get_slug() === 'give-recurring' ) {
        My_Recurring_Bridge::init();
    }
} );
```

It fires **after** the activation callback, so on a first-ever load the tables and options that
callback creates are already there.

## Skip reasons

The second argument is one of five values. Compare against the constant, not the string:

| Constant | Value | Meaning |
|---|---|---|
| `Loader::SKIPPED_DISABLED` | `disabled` | The `enabled` key, or the callable behind it, said no. |
| `Loader::SKIPPED_ALREADY_LOADED` | `already_loaded` | The guard constant was already defined — usually a standalone copy that loaded first. |
| `Loader::SKIPPED_DEPENDENCIES_UNMET` | `dependencies_unmet` | `dependency_check` said the requirements are not met. The one skip that also queues a [notice](notices.md). |
| `Loader::SKIPPED_FILE_UNREADABLE` | `file_unreadable` | `bundled_plugin_file` does not name a readable file. |
| `Loader::SKIPPED_FILTERED` | `filtered` | The [`should_load` filter](filters.md#the-load-gate) returned something falsy. |

```php
use Nexcess\PluginAbsorber\Loader;

add_action( 'give/plugin_absorber/skipped', function ( $sub_plugin, $reason ) {
    if ( $reason === Loader::SKIPPED_ALREADY_LOADED ) {
        my_log( sprintf( '%s deferred to a standalone copy.', $sub_plugin->get_slug() ) );
    }
}, 10, 2 );
```

The values are fixed API and will not change. New reasons may be added, so treat one you do not
recognise as a plain skip rather than as an error.

`loaded` and `skipped` do not add up to everything registered, so do not count on them to. A
sub-plugin whose `enabled`, `dependency_check` or `should_load` callable throws, or whose bundled
file throws as it is required, announces neither; and a `DEACTIVATE` conflict redirects before the
load pass runs at all, so on that request no sub-plugin announces anything.

## Your listener cannot take the site down

These fire from inside `plugins_loaded`, so a listener that throws is caught rather than allowed
out. It costs nothing: by the time `loaded` fires the require has happened, the guard constant is
defined and the activation callback has run, and a `skipped` announcement is the last thing that
happens to that sub-plugin either way. The throw is reported through `_doing_it_wrong()` as what it
is — a listener, named by the hook it is on — rather than as the sub-plugin having failed, so a host
reading its log does not mistake its own bug for a load that broke. That is a backstop, not a
licence — a listener here runs on every request the site serves, so keep it cheap and keep it quiet.
