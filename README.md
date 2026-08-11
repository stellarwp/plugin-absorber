# Plugin Absorber

Safely load bundled WordPress plugins inside a host plugin — togglable or always-on — without
re-declaration fatal errors.

Requires PHP 7.4+ and WordPress 6.4+.

## Install

```bash
composer require stellarwp/plugin-absorber
```

**Use [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md)** — two
plugins shipping different versions of this library will collide otherwise. See
[Installing](docs/installing.md) for the one prefixing rule you must not get wrong.

## Quick start

```php
use Nexcess\PluginAbsorber\Config;

Config::set_hook_prefix( 'give' );          // required — keys hooks, transients, options
Config::set_container( give()->container ); // optional — lets you rebind collaborators
```

Each sub-plugin is then described by a config array:

```php
[
    'slug'                       => 'give-stripe',
    'bundled_plugin_file'        => __DIR__ . '/sub-plugins/give-stripe/give-stripe.php',
    'plugin_loaded_constant'     => 'GIVE_STRIPE_VERSION',
    'standalone_plugin_basename' => 'give-stripe/give-stripe.php',
]
```

## Docs

- [Installing](docs/installing.md) — Composer, Strauss, and the constants Strauss must leave alone.
- [Configuration](docs/configuration.md) — the hook prefix, the container, every sub-plugin key.
- [Conflict handling](docs/conflict-handling.md) — the policies, the load guard, and its limits.
- [Filters](docs/filters.md) — the runtime overrides for policies and notice text.

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
