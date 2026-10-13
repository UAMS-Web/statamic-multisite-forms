# Development loop against a multisite host

The gate (`composer ci`) boots a minimal Statamic through Testbench. A per-site forms add-on is about behavior that needs a real multisite: site-aware form assignment, per-site overrides and flat-file submissions on disk. This page is the loop for exercising the add-on in a real host, and the setup the `host-only-behavior-needs-a-host-run` rule and the `host-app-tests` skill assume.

## The host

A persistent Statamic 6 host exists for this purpose on the maintainer's Windows machine, in the Herd directory, named for the add-on it serves:

```
statamic-multisite-forms-host
```

Herd serves it as `statamic-multisite-forms-host.test`. It is a stock `statamic/statamic` project with three changes, all recorded in the host's own files so it can be rebuilt from this page:

1. `config/statamic/system.php` sets `'multisite' => true`, and `resources/sites.yaml` defines two sites, `en` (`/`) and `es` (`/es/`).
2. The starter content is in the multisite layout, because the single-site layout breaks the Stache in a multisite host (`Undefined array key 1` in `CollectionEntriesStore`): the home entry is `content/collections/pages/en/home.md`, the tree is `content/trees/collections/en/pages.yaml`, and `content/collections/pages.yaml` lists `sites: [en, es]`.
3. `composer.json` carries a path repository pointing at the add-on checkout, and requires `uams-web/statamic-multisite-forms:@dev`.

`php please multisite` is the supported conversion, but it waits for input and hangs when run without a terminal, so the host was converted by hand.

## Rebuilding the host

Run these in the Herd directory. `<path-to-addon-checkout>` is the absolute path of the primary checkout or the slot being tested; it differs per machine, so it is written as a placeholder here.

```bash
composer create-project statamic/statamic statamic-multisite-forms-host
cd statamic-multisite-forms-host
# multisite: set 'multisite' => true in config/statamic/system.php, add resources/sites.yaml,
# then move the starter content into the per-site layout described above
composer config repositories.addon path "<path-to-addon-checkout>"
composer config minimum-stability dev
composer config prefer-stable true
composer require uams-web/statamic-multisite-forms:@dev
```

## Picking up a change

A path repository installs by symlink where the filesystem allows it, so an edit in the checkout is live in the host with no further step. Measured on Windows 11, Composer 2.10.2: `vendor/uams-web/statamic-multisite-forms` is a symlink to the checkout. Where a symlink cannot be created Composer copies instead, and a change then needs `composer reinstall uams-web/statamic-multisite-forms`.

A change to `composer.json` (a new provider, a new `extra.statamic` key) needs `composer update uams-web/statamic-multisite-forms` from the host root, and the `Discovered Addon:` line at the end of the output confirms discovery.

## Confirming the add-on loaded

From the host root:

```bash
php artisan package:discover                      # lists uams-web/statamic-multisite-forms
php artisan tinker --execute='echo \Statamic\Facades\Addon::all()->map->package()->implode(",");'
```

The Control Panel page `/cp/addons` was requested in process as a temporary super user against this host: HTTP 200, and the page data lists one add-on, `Multisite Forms`, with the version of the checked-out branch. A browser run of the same page has not been done.

## Running the host-bound tests

`tests/Feature/` holds tests that extend the host's `Tests\TestCase`. They are not part of `composer ci`: the package has no `Feature` suite and PHPStan excludes the directory, because `Tests\TestCase` does not exist in this package. From the host root:

```bash
php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/HostRegistersAddonTest.php
```

The skeleton asserts that the host lists the add-on, registers its service provider and is multisite. Real feature tests arrive with the features. The `host-only-behavior-needs-a-host-run` rule says when a run is required, and the `host-app-tests` skill covers claiming a slot and reading results.

## Patch leakage

The add-on's dev patches (`extra.patches` for `pestphp/pest-plugin-mutate`) must not touch the host. Checked against the host's lock after the install: `pestphp/pest-plugin-mutate` and `cweagans/composer-patches` are not in the host's `vendor/` or its `composer.lock` package lists, so no patch was applied to any host package. The add-on's own entry in the host's `composer.lock` carries its `extra` block, patches included, as metadata only.

## Platforms

Verified on Windows 11 with PHP 8.4.20 and Laravel 13.35.0. macOS is not yet measured: the host would be created the same way under Herd on a Mac, and whether the path repository symlinks there is expected but unmeasured.
