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
Config::set_container( give()->container ); // optional — see Rebinding below
```

The hook prefix accepts letters, numbers, hyphens, and underscores. Anything else throws
`Config_Exception`, as does reading it before it is set.

## License

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [`LICENSE`](LICENSE).
