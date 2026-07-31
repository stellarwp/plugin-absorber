# Plugin Absorber

Safely load bundled WordPress plugins inside a host plugin — togglable or always-on — without
re-declaration fatal errors.

## Install

```bash
composer require nexcess/plugin-absorber
```

**Use [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).**
Two or more plugins shipping different versions of this library will collide otherwise.

> **Do not let `extra.strauss.constant_prefix` rewrite a sub-plugin's `plugin_loaded_constant`.**
> Those are real, shared runtime constants — the whole safety mechanism depends on the bundled
> copy and the standalone defining the *same* name. Add them to `exclude_from_copy`.

Requires PHP 7.4+ and WordPress 6.4+.

## Usage

_Added as each piece lands._
