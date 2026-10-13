# Multisite Forms for Statamic

A Statamic add-on that makes forms per-site in a multisite install: site assignment, per-site overrides, and site-separated submissions in flat files.

## Status

Early development. Site assignment is in progress: a form's settings screen has a Sites field, a submit from a site the form is not assigned to answers 404, the form tags follow the `not_assigned` setting below, and a new submission records the site it was made from under `_site`. The rest of "What it will do" is the plan, not what ships today. It is free, has no editions, and is licensed under MIT.

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

What the `{{ form }}` tags (`form:create`, the `{{ form:contact }}` shorthand, `form:errors`, `form:success`, `form:submission` and `form:submissions`) do with a form that is not assigned to the site the page is rendered for:

- `empty` (the default) renders nothing, and `form:submissions` renders as it does for a form with no submissions, so a `no_results` branch still shows. A warning naming the form and the site is logged at `warning` level, once per form and site per request, so a shared layout that renders the form on every page logs one line per page view rather than one per tag.
- `404` aborts the request with the site's 404 page, as Statamic's `{{ 404 }}` tag does. If the 404 page's layout renders the same form, it renders nothing there rather than aborting again.

The setting is checked each time the add-on boots, for every request and `artisan` command: any other value, including `null` from an unset `env()` variable, is logged at `error` level naming the setting and the value. A page that meets an unassigned form with such a value then fails with the same message, since the add-on cannot tell which behavior was meant. Pages with no unassigned form are unaffected.

`form:fields` inside `form:create` needs no gate of its own: it renders only the fields `form:create` hands it.

Fetched with no template to render, as `Statamic::tag('form:create')->params(['in' => 'contact'])->fetch()` does in Blade or PHP, `form:create` returns the form's data as an array, as Statamic's does. Under `empty`, an unassigned form returns an empty array instead, so guard the markup that reads it:

```blade
@php($form = Statamic::tag('form:create')->params(['in' => 'contact'])->fetch())
@if ($form)
    <form method="{{ $form['attrs']['method'] }}" action="{{ $form['attrs']['action'] }}">
        ...
    </form>
@endif
```

Without the guard, reading `$form['attrs']` fails on a site the form is not assigned to.

A form assigned to the page's site, and a form with no sites, render as Statamic renders them. A submit to a form from a site it is not assigned to always answers 404, whatever `not_assigned` says.

## Checking a form's site in a template

`form:assigned` answers whether a form renders on the page's site, so a template can hide what surrounds it, such as a heading or an introduction. It is true for a form assigned to the site or one with no sites, and false for a form assigned elsewhere or one that does not exist. It never aborts or logs, whatever `not_assigned` says.

In Antlers, use it as a condition, or as a tag pair that renders its contents only when the form is assigned:

```antlers
{{ if {form:assigned in="contact"} }}
    <h2>Contact us</h2>
{{ /if }}

{{ form:assigned in="contact" }}
    <p>We reply within two working days.</p>
{{ /form:assigned }}
```

In Blade, fetch it, or use the tag pair as a component:

```blade
@if (Statamic::tag('form:assigned')->params(['in' => 'contact'])->fetch())
    <h2>Contact us</h2>
@endif

<s:form:assigned in="contact">
    <p>We reply within two working days.</p>
</s:form:assigned>
```

The tag names the form the way `form:create` does: with `in`, `handle`, `is`, `form` or `formset`, or from a form in the template's context. Like Statamic's own `form:create` and `form:errors`, its name takes precedence over a form whose handle is `assigned` in the `{{ form:assigned }}` shorthand; render such a form with `{{ form:create in="assigned" }}`.

## Forms seen from a site

The add-on hands templates and the submit route a read-only view of a form for the page's site, a `UAMSWeb\MultisiteForms\Forms\SiteForm`. It carries the stored form's values and refuses to be saved or deleted. A submission made on the front end is built from that view, so `$submission->form()` in an event listener returns it.

The view is always a subclass of Statamic's own `Statamic\Forms\Form`, whatever class your forms are stored as. Where a driver stores forms in its own class, such as `statamic/eloquent-driver`'s `Statamic\Eloquent\Forms\Form`, that class's methods are not on the view: `model()` throws, and an `instanceof` check against the driver's class is false. Reach them through `base()`, which returns the stored form in its own class:

```php
use Statamic\Events\FormSubmitted;
use UAMSWeb\MultisiteForms\Forms\SiteForm;

Event::listen(function (FormSubmitted $event) {
    $form = $event->submission->form();

    if ($form instanceof SiteForm) {
        $form = $form->base();
    }

    $row = $form->model(); // the driver's own method
});
```

`site()` on the view returns the handle of the site it was resolved for.

## Development

```sh
composer install
composer ci    # Pint, Rector dry run, Larastan, Pest (Unit and Integration)
```

[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) describes the development loop against a real multisite host, and the project conventions are in [`AGENTS.md`](AGENTS.md).

## License

MIT. See [`LICENSE`](LICENSE).
