Multisite Forms makes Statamic forms per-site in a multisite install. A form can be shown on some sites only, give each site its own title, email and storage choice, and keep each site's submissions apart in the Control Panel.

This wiki holds the documentation: a walkthrough, task guides by role, the reference, and troubleshooting. The [README](https://github.com/UAMS-Web/statamic-multisite-forms#readme) keeps an overview, the requirements and installation.

Some guides are still being written; their groups below say which ticket tracks them.

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/statamic-multisite-forms#installation): add the package to a multisite install.
- [Your first multisite form](Your-first-multisite-form.md): assign a form to one site, then two, and see each site's submissions and recipients.

## Guides for site builders

- [Showing a form on some sites only](Showing-a-form-on-some-sites-only.md): assign a form to some sites, and choose what the others show.
- [Giving each site its own title, email and store](Giving-each-site-its-own-title-email-and-store.md): a site override row, and showing the site's title in an email.
- [Checking a form's site in a template](Checking-a-forms-site-in-a-template.md): hide a heading, or show something else, where the form does not render.

## Guides for administrators

- [Giving editors access by site](Giving-editors-access-by-site.md): a role for one site, and what an editor with it sees and can change.
- [Attributing submissions from before the add-on](Attributing-submissions-from-before-the-add-on.md): record a site on older submissions so that site's editors see them.

## Guides for developers

Reading forms by site through the API and in code. Tracked in [#160](https://github.com/UAMS-Web/statamic-multisite-forms/issues/160).

## Reference

- [Configuration](Configuration.md): the `not_assigned` setting, and what the form tags do on a site a form is not assigned to.
- [Per-site title, email and store](Per-site-title-email-and-store.md): the `site_overrides` rows in a form file.
- [Control Panel access](Control-Panel-access.md): who sees which forms and submissions, and the Site filter.
- [Attribute command](Attribute-command.md): recording a site on older submissions.
- [API site filter](API-site-filter.md): the `site` filter on the REST and GraphQL form listings.
- [Form assigned tag](Form-assigned-tag.md): `form:assigned`, for checking a form's site in a template.
- [Site form view](Site-form-view.md): the read-only view of a form that templates, the submit route and listeners receive.

## Troubleshooting

One entry per symptom: the message or behavior you see, its cause and the fix. Tracked in [#161](https://github.com/UAMS-Web/statamic-multisite-forms/issues/161).

## Project

- [Report a problem](https://github.com/UAMS-Web/statamic-multisite-forms/issues)
- [Releases](https://github.com/UAMS-Web/statamic-multisite-forms/releases)
