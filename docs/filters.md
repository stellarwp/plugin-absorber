# Filters

What you override. [Actions](actions.md) are the other direction — what the library tells you as it
runs. `{prefix}` is the value passed to `Config::set_hook_prefix()`.

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/conflict_policy` | `string $policy`, `Sub_Plugin $sub_plugin` | Final say over the conflict policy. |
| `{prefix}/plugin_absorber/conflict_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the conflict notice text. Receives the configured message, or the caller's fallback when nothing is configured. |
| `{prefix}/plugin_absorber/dependency_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the dependency notice text. Receives the configured message, or the generic default sentence when nothing is configured. |
| `{prefix}/plugin_absorber/stranding_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the multisite stranding notice text. Receives the generic default; this notice has no config key, so the filter is its only override. |

Each runs last, after the configured value and any fallback, and fires when the value is asked for
rather than when the sub-plugin is registered — so it is also the place to call `__()`. A
non-scalar return yields an empty string rather than a fatal cast, and a `conflict_policy` return
that is not one of the three constants is treated as [`NOTICE_ONLY`, never as consent to
deactivate](conflict-handling.md#policies).

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
the guard constant, the dependency check and the file check. Returning `true` cannot force a load
past the guard constant; anything other than a truthy return skips the load, which is the safe
direction.

**Watch the polarity when you wire an existing gate to this one.** `should_load` is true means *do
load*. A host filter named for the opposite — LearnDash's `learndash_module_{x}_disabled`, where
true means *do not load* — inverts the gate if passed through unnegated, and the failure is silent
in the direction that loads a plugin the site turned off.
