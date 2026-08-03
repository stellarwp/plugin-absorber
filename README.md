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
Config::set_version( GIVE_VERSION );        // optional
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
| `plugin_loaded_constant` | `string` | ✔ | A constant the plugin defines when it loads. Both copies must define the *same* name. `defined()` ⇒ skip, which is what prevents re-declaration fatals. **Load guard only.** |
| `standalone_plugin_basename` | `string` | | The standalone's `dir/file.php` basename. Used for `is_plugin_active()` and `deactivate_plugins()`. Omit when there is no standalone. **Detection only.** |
| `enabled` | `bool\|callable` | | `true` by default. A callable is re-evaluated on every load. |
| `conflict_policy` | `string\|callable` | | `Conflict_Policy::DEACTIVATE` by default. A `callable( Sub_Plugin ): string` decides at runtime. |
| `conflict_notice_message` | `string\|callable` | | Shown on auto-deactivation and on a re-activation attempt. Use a callable to defer `__()` past `init`. |
| `dependency_notice_message` | `string\|callable` | | Shown when `dependency_check` fails. Defaults to a generic English sentence. |
| `activation_callback` | `callable` | | Runs **exactly once, ever**, per slug. |
| `dependency_check` | `callable` | | Returns `bool`. Skips the load and queues a notice when false. |

The load guard and the standalone basename are deliberately **two separate keys**. No constant does
double duty as both a guard and a path resolver.

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
