# Installing

```bash
composer require stellarwp/plugin-absorber
```

Requires PHP 7.4+ and WordPress 6.4+. The WordPress floor comes from the `wp_admin_notice_markup`
filter; WordPress is not a Composer dependency, so nothing enforces it at install time.

## Strauss

Prefix this library with [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).
Two plugins shipping different versions of it will collide otherwise.

> **Nothing may rewrite a sub-plugin's `plugin_loaded_constant`.** The bundled copy and the
> standalone must define the *same* name, or the guard matches nothing. This library only ever reads
> such a name out of your config, so its own source is safe to prefix in full — but if your build
> also runs the bundled plugin's files through Strauss, keep `extra.strauss.constant_prefix` away
> from them.
