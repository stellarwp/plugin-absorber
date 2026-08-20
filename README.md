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
use Nexcess\PluginAbsorber\Absorber;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );            // required — keys the hooks and options
    Config::set_container( give()->container );   // required — every collaborator resolves from it

    Absorber::register( [
        'slug'                       => 'give-recurring',
        'bundled_plugin_file'        => __DIR__ . '/sub-plugins/recurring/give-recurring.php',
        'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION',
        'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
    ] );

    Absorber::boot();
}, 0 );
```

The container is required — any StellarWP `ContainerInterface` implementation, the one you already
hand to Telemetry or Uplink. Every collaborator comes from it.

Boot before `plugins_loaded` priority 5, where conflict resolution runs: WordPress silently ignores a
callback added at or past the priority it is already dispatching. Later is reported through
`_doing_it_wrong()` and run inline, but the ordering guarantees are weaker.

Priority 0 is the recommendation, in the block that owns your container rather than in a service
provider: a host that builds one lazily and replaces it at priority 0 leaves us holding an orphan
whose bindings were discarded.

## Docs

- [Installing](docs/installing.md) — Composer, Strauss, and the constants Strauss must leave alone.
- [Configuration](docs/configuration.md) — the hook prefix, the container, every sub-plugin key.
- [Conflict handling](docs/conflict-handling.md) — the policies, when they run, and the guard's limits.
- [Filters](docs/filters.md) — the runtime overrides for policies and notice text.
- [Notices](docs/notices.md) — where the queue lives, who may see it, and how to render it yourself.

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
