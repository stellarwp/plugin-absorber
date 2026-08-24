# Plugin Absorber

Safely load bundled WordPress plugins inside a host plugin — togglable or always-on — without
re-declaration fatal errors.

Requires PHP 7.4+ and WordPress 6.4+.

## Install

```bash
composer require stellarwp/plugin-absorber
```

**Use [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md)** — two
plugins shipping different versions of this library will collide otherwise. [Installing][installing]
has the one prefixing rule you must not get wrong.

## Quick start

```php
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Absorber;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );            // required — keys the hooks and options
    Config::set_container( give()->container );   // required — every part of the library comes from it

    Absorber::register( [
        'slug'                       => 'give-recurring',
        'bundled_plugin_file'        => __DIR__ . '/sub-plugins/recurring/give-recurring.php',
        'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION',
        'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
    ] );

    Absorber::boot();
}, 0 );
```

The container is required, and any StellarWP `ContainerInterface` implementation will do — the one
you already hand to Telemetry or Uplink.

If your plugin can run on multisite, add
`Config::set_host_plugin_basename( plugin_basename( __FILE__ ) )`. It is a no-op off a network, so set
it unconditionally: where it matters is the one topology the library must not deactivate a standalone
in — a network-active standalone whose host plugin is not itself network-activated, where a
network-wide deactivation would leave the network's other sites with no copy of it at all.

**Keep the `, 0`.** Anything below `plugins_loaded` priority 5 wires cleanly, but priority 0 is the
recommendation, in the block that owns your container rather than in a service provider. Booting at 5
or later still works and is reported through `_doing_it_wrong()`, with the whole sequence running
inline instead. [Configuration][configuration] explains both, and closes with a complete bootstrap —
two sub-plugins, every optional key.

## Docs

- [Installing][installing] — Composer, Strauss, and the constants Strauss must leave alone.
- [Configuration][configuration] — the hook prefix, the container, every sub-plugin key.
- [Recipes][recipes] — a settings toggle, a manifest of add-ons, and staging the absorption across
  releases.
- [Conflict handling][conflicts] — the policies, when they run, and the guard's limits.
- [Filters][filters] — the runtime overrides for policies and notice text.
- [Actions][actions] — the failures the library announces, and what each one carries.
- [Notices][notices] — where the queue lives, who may see it, and how to render it yourself.
- [Extending][extending] — swapping out a piece of the library.
- [Tests][tests] — running the suite, the fixtures and traits it offers, and every scenario it drives
  the library through.

`docs/` and `tests/` are both `export-ignore`d, so neither is in a vendored copy of this library —
these point at the repository rather than at a path that would be missing beside the installed
source.

[installing]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/installing.md
[configuration]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/configuration.md
[recipes]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/recipes.md
[conflicts]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/conflict-handling.md
[filters]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/filters.md
[actions]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/actions.md
[notices]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/notices.md
[extending]: https://github.com/stellarwp/plugin-absorber/blob/main/docs/extending.md
[tests]: https://github.com/stellarwp/plugin-absorber/blob/main/tests/README.md

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
