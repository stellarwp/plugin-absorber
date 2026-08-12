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
use Nexcess\PluginAbsorber\Loader;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );            // required — keys the hooks and options
    Config::set_container( give()->container );   // required — every collaborator resolves from it

    Loader::register( [
        'slug'                       => 'give-recurring',
        'bundled_plugin_file'        => __DIR__ . '/sub-plugins/recurring/give-recurring.php',
        'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION',
        'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
    ] );

    Loader::boot();
}, 0 );
```

The container is required — any StellarWP `ContainerInterface` implementation, the one you already
hand to Telemetry or Uplink. Every collaborator comes from it.

Keep the `, 0`. `boot()` wires the load at `plugins_loaded` priority 2, and WordPress silently
ignores a callback added at or past the priority it is already dispatching — so configuring the
library from a provider that itself runs at priority 2 or later races the library it is configuring.
Booting later is reported through `_doing_it_wrong()` and loaded inline, but the ordering guarantees
are weaker.

Put this in the block that owns your container, not in a service provider, and pass the container you
intend to keep: a host that builds one lazily and replaces it later leaves us holding an orphan whose
bindings were discarded.

## Docs

- [Installing](docs/installing.md) — Composer, Strauss, and the constants Strauss must leave alone.
- [Configuration](docs/configuration.md) — the hook prefix, the container, every sub-plugin key.
- [Conflict handling](docs/conflict-handling.md) — the policies, the load guard, and its limits.
- [Filters](docs/filters.md) — the runtime overrides for policies and notice text.
- [Notices](docs/notices.md) — where the queue lives, who may see it, and how to render it yourself.

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
