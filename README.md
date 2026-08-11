# Plugin Absorber

Safely load bundled WordPress plugins inside a host plugin — togglable or always-on — without
re-declaration fatal errors.

## Install

```bash
composer require stellarwp/plugin-absorber
```

**Use [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).**
Two or more plugins shipping different versions of this library will collide otherwise.

> **Do not let `extra.strauss.constant_prefix` rewrite a sub-plugin's `plugin_loaded_constant`.**
> Those are real, shared runtime constants — the whole safety mechanism depends on the bundled
> copy and the standalone defining the *same* name. Add them to `exclude_from_copy`.

Requires PHP 7.4+ and WordPress 6.4+.

## Usage

### Configure

```php
use Nexcess\PluginAbsorber\Config;

Config::set_hook_prefix( 'give' );          // required — keys hooks, transients, options
Config::set_container( give()->container ); // optional — lets you rebind collaborators
```

The hook prefix accepts letters, numbers, hyphens, and underscores. Anything else throws
`Config_Exception`, as does reading it before it is set.

### Conflict policies

When a sub-plugin's standalone counterpart is still active:

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and redirect; the bundled copy loads on the next request. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |

### Sub-plugin configuration

| Key | Type | Required | Meaning |
|---|---|:--:|---|
| `slug` | `string` | ✔ | Unique id — registry key, notice id, activation-tracking key. |
| `bundled_plugin_file` | `string` | ✔ | Absolute path to the **bundled** plugin's main file. This is what gets `require_once`d. |
| `plugin_loaded_constant` | `string` | ✔ | A constant the plugin defines when it loads. Both copies must define the *same* name, **at file scope**. `defined()` ⇒ skip, which is what prevents re-declaration fatals. **Load guard only.** |
| `standalone_plugin_basename` | `string` | | The standalone's `dir/file.php` basename. Used for `is_plugin_active()` and `deactivate_plugins()`. Omit when there is no standalone. **Detection only.** |
| `enabled` | `bool\|callable` | | `true` by default. A `callable( Sub_Plugin ): bool` is re-evaluated on every call, not cached. |
| `conflict_policy` | `string\|callable` | | `Conflict_Policy::DEACTIVATE` by default. A `callable( Sub_Plugin ): string` decides at runtime. The `conflict_policy` filter below overrides both. |
| `conflict_notice_message` | `string\|callable` | | Shown on auto-deactivation and on a re-activation attempt. Empty by default. Use a callable to defer `__()` past `init`. |
| `dependency_notice_message` | `string\|callable` | | Shown when `dependency_check` fails. Defaults to a generic, untranslated sentence naming the raw slug. |
| `activation_callback` | `callable( Sub_Plugin )` | | Runs **exactly once, ever**, per slug. |
| `dependency_check` | `callable( Sub_Plugin ): bool` | | Skips the load and queues a notice when it returns false. |

The load guard and the standalone basename are deliberately **two separate keys**. No constant does
double duty as both a guard and a path resolver.

Sub-plugins load in **registration order**, so register a dependency before anything that extends it
at include time.

Register each slug exactly once. A slug also names the sub-plugin's notices and its once-ever
activation record, so a second registration under the same slug is refused with a
`Config_Exception` naming both bundled files rather than quietly dropping one of the two from the
load. Register unconditionally and put anything you cannot decide up front — a licence that may not
be active, a setting the site owner can change — in `enabled`, which is re-evaluated on every load.

A value that is a `string` is always used as a value, never called — even when a function of that
name exists. `dependency_check` and `activation_callback` are the two keys that are only ever
callables, and a value under either that cannot be called is rejected when the sub-plugin is
registered rather than ignored at the point of use.

The guard constant must be defined at **file scope**. A standalone that defines it from a bootstrap
hooked at `plugins_loaded` or later has not defined it yet at the moment the guard is read, and the
bundled copy would load on top of it.

The guard also cannot help on the request that *activates* the standalone: WordPress includes it
after the bundled copy has already loaded, so that re-declaration is a real fatal. WordPress catches
it in its activation sandbox, and this library rewrites the resulting error screen into an
explanation.

### Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/conflict_policy` | `string $policy`, `Sub_Plugin $sub_plugin` | Final say over the conflict policy, after the config value and any callable. |

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
