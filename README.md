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
[Installing][installing] for the one prefixing rule you must not get wrong.

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

Keep the `, 0`. `boot()` wires conflict resolution at `plugins_loaded` priority 5 and the load at
priority 6, and WordPress silently ignores a callback added at or past the priority it is already
dispatching — so anything below priority 5 wires cleanly, the priority 1 where several hosts wire
their container today included. Booting later is reported through `_doing_it_wrong()` and both steps
run inline instead — which on an admin page view can end the request in a redirect before `boot()`
returns.

Priority 0 is the recommendation, in the block that owns your container rather than in a service
provider: a host that builds one lazily and replaces it at priority 0 leaves us holding an orphan
whose bindings were discarded.

A [complete bootstrap][configuration] — two sub-plugins, every optional key — closes the
configuration doc.

## Docs

- [Installing][installing] — Composer, Strauss, and the constants Strauss must leave alone.
- [Configuration][configuration] — the hook prefix, the container, every sub-plugin key.
- [Conflict handling][conflicts] — the policies, when they run, and the guard's limits.
- [Filters][filters] — the runtime overrides for policies and notice text.
- [Notices][notices] — where the queue lives, who may see it, and how to render it yourself.
- [Tests][tests] — running the suite, the fixtures and traits it offers, and every scenario it drives
  the library through.

`docs/` and `tests/` are both `export-ignore`d, so neither is in a vendored copy of this library —
these point at the repository rather than at a path that would be missing beside the installed
source.

[installing]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/installing.md
[configuration]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/configuration.md
[conflicts]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/conflict-handling.md
[filters]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/filters.md
[notices]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/notices.md
[tests]: https://github.com/stellarwp/plugin-absorber/blob/main/tests/README.md

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
