# Multisite Forms for Statamic

A Statamic add-on that makes forms per-site in a multisite install: site assignment, per-site overrides, and site-separated submissions in flat files.

## Status

Early development. Site assignment is in progress: a form's settings screen has a Sites field, a submit from a site the form is not assigned to answers 404, and the form tags follow the `not_assigned` setting below. The rest of "What it will do" is the plan, not what ships today. It is free, has no editions, and is licensed under MIT.

## What it will do

- **Site assignment:** choose which sites of a multisite install a form belongs to.
- **Per-site overrides:** override a form's settings for one site without touching the others.
- **Site-separated submissions:** store each site's submissions apart from the others, in Statamic's flat files.

## Requirements

- PHP 8.4 or later
- Statamic (`statamic/cms`) 6.35 or later, in a multisite install
- Submissions in Statamic's flat files, or in a database through `statamic/eloquent-driver`. The database driver is verified on SQLite only; MySQL and PostgreSQL are not verified yet.

## Installation

The package is not published on Packagist yet, so it cannot be installed with a plain `composer require`. Until it is, add the repository to the host's `composer.json` and require it from there:

```sh
composer config repositories.multisite-forms vcs https://github.com/UAMS-Web/statamic-multisite-forms
composer require uams-web/statamic-multisite-forms:@dev
```

Statamic discovers the add-on from the package, and it appears under Addons in the Control Panel. After a later `composer update`, `php artisan statamic:install` publishes its assets.

## Configuration

Publish the config file to change a setting:

```sh
php artisan vendor:publish --tag=statamic-multisite-forms-config
```

That writes `config/statamic-multisite-forms.php`. It has one setting.

### `not_assigned`

What the `{{ form }}` tags (`form:create`, the `{{ form:contact }}` shorthand, `form:fields`, `form:errors`, `form:success`, `form:submission` and `form:submissions`) do with a form that is not assigned to the site the page is rendered for:

- `empty` (the default) renders nothing. A warning naming the form and the site is logged at `warning` level, once per form and site per request, so a shared layout that renders the form on every page logs one line per page view rather than one per tag.
- `404` aborts the request with a 404.

A form assigned to the page's site, and a form with no sites, render as Statamic renders them. A submit to a form from a site it is not assigned to always answers 404, whatever `not_assigned` says.

## Development

```sh
composer install
composer ci    # Pint, Rector dry run, Larastan, Pest (Unit and Integration)
```

[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) describes the development loop against a real multisite host, and the project conventions are in [`AGENTS.md`](AGENTS.md).

## License

MIT. See [`LICENSE`](LICENSE).
