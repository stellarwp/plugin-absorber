# Filters

`{prefix}` is the value passed to `Config::set_hook_prefix()`.

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/conflict_policy` | `string $policy`, `Sub_Plugin $sub_plugin` | Final say over the conflict policy. |
| `{prefix}/plugin_absorber/conflict_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the conflict notice text. Receives the configured message, or the caller's fallback when nothing is configured. |
| `{prefix}/plugin_absorber/dependency_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the dependency notice text. Receives the configured message, or the generic default sentence when nothing is configured. |

Each runs last, after the configured value and any fallback. Because they fire when the value is
asked for rather than when the sub-plugin is registered, they are also the place to call `__()` —
by then the textdomain is loaded.

A filter returning a non-scalar yields an empty string rather than a fatal cast.

## The load gate

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/should_load` | `bool $should_load`, `Sub_Plugin $sub_plugin` | Last word before `require_once`. |

```php
add_filter( 'give/plugin_absorber/should_load', function ( $should_load, $sub_plugin ) {
    return $sub_plugin->get_slug() === 'give-recurring' ? false : $should_load;
}, 10, 2 );
```

It is consulted only for a sub-plugin that would otherwise have loaded — after the enabled check,
the guard constant, the dependency check and the file check, in that order. So returning `true`
cannot force a load past the guard constant: nothing overrides that. Anything other than a truthy
return skips the load, which is the safe direction.
