# Per-site forms: design spec

In a Statamic multisite install, forms are global: one definition, one submission pool, one set of recipients, visible on every site. This add-on makes a form belong to sites. A form can be limited to some sites, can differ per site where it matters (title, recipients, whether submissions are stored), and keeps its submissions attributable to the site they came from, with Control Panel access following the site access an editor already has.

This page is a design, not a record of built behavior. Every statement about core is from reading `statamic/cms` v6.35.1 (the version in the development host) and cites the file under `src/`. Statements marked **unverified** were not exercised and each has a spike in the phasing section. Decisions that close a fork go to `docs/DECISIONS.md` when they are made.

## What core does today

| Fact | Where it comes from |
| --- | --- |
| A form is one YAML file, `config('statamic.forms.forms')/{handle}.yaml`. | `Forms/Form.php` `path()` |
| Keys core does not recognize are kept: `hydrate()` merges them into the form's data and `save()` writes that data back. | `Forms/Form.php` `hydrate()`, `save()` |
| `email` is a list of email configs, or one config when it has a `to` key. Core iterates it as such. | `Forms/SendEmails.php` `emailConfigs()` |
| Add-ons can add sections of fields to the form config screen with `Form::appendConfigFields($handles, $display, $fields)`. Handles `title`, `honeypot`, `store` and `email` are reserved. | `Forms/FormRepository.php` |
| `Form::all()` feeds the Control Panel index, the REST and GraphQL queries, and the submissions Stache store's discovery of which forms exist. | `CP/Forms/FormsController.php`, `GraphQL/Queries/FormsQuery.php`, `Stache/Stores/SubmissionsStore.php` |
| On submit, the route's `form` parameter is bound by `Form::find()`, a missing form is a 404, and the site is `Site::findByUrl(URL::previous()) ?? Site::default()`. That site is passed to `SendEmails`, which uses it for the email locale and site globals. It is not stored on the submission. | `Providers/RouteServiceProvider.php`, `Http/Controllers/FormController.php` |
| The controller reads `$form->store()` from the bound form, and builds the submission with `$form->makeSubmission()`, which sets the submission's form to that same object. | `FormController.php`, `Forms/Form.php` |
| `FormSubmitted` is dispatched with the submission before it is saved and before emails are queued; a listener returning `false` ends the request as a silent success. | `FormController.php` |
| The `{{ form }}` tags look the form up with `Form::find()` cached under the Blink key `form-{handle}`, or accept a form object from context. | `Forms/Tags.php` `form()`, `formHandle()` |
| Submissions are Stache-backed. The store's file filter accepts only YAML files directly inside the form's own folder, and the form handle comes from that folder name. | `Stache/Stores/FormSubmissionsStore.php` |
| The submission query builder accepts arbitrary `where` columns in the general case (it special-cases only `form`, `forms` and the `datestamp` order). Whether an arbitrary data key is indexed is **unverified**. | `Stache/Query/SubmissionQueryBuilder.php` |
| Form permissions are per form: `view {handle} form submissions`, `delete {handle} form submissions`, plus `configure forms`. Core separately registers `access {site} site`. | `Policies/FormPolicy.php`, `Policies/FormSubmissionPolicy.php`, `Auth/CorePermissions.php` |
| `Form` is `__clone`-safe: it clones its data and supplements. | `Forms/Form.php` |

## Goals and non-goals

**Goals**

- Assign a form to one or more sites. Absent or empty means all sites, which is today's behavior.
- Override title, email configs and the store flag per site without cloning the form.
- Record which site each new submission came from, and let editors and exports work per site.
- Scope Control Panel access with the site access editors already have, without new permission keys to maintain.
- Stay additive: with the add-on removed, every form file is still a valid core form and behaves as a global form.

**Non-goals**

- Replacing core forms, the form builder, or the Control Panel screens.
- Translating form content. This is about site ownership, not locale.
- Per-site blueprints. One blueprint per form stays the rule.
- Per-site storage folders or storage drivers (see the decision below).
- Paid editions. The add-on is free with no editions (`docs/DECISIONS.md`, #17).

## Principles

1. **Do not change `Form::all()` or `Form::find()` for everyone.** The submissions store discovers forms through `Form::all()`, so filtering it by site would hide submissions from core itself. Scoping is applied at the seams (frontend, submit, API, Control Panel policy), never in the repository.
2. **A site-resolved form is a read-only view.** If a resolved form were saved, its overrides would be written into the base file. The resolved type refuses `save()` and `delete()`.
3. **Reuse core's site access.** Editors already have `access {site} site`. Per-site form access is derived from it, not duplicated.
4. **Prefer core's extension points to replacing core classes**: `appendConfigFields`, events, policies, middleware. Replacing a container binding is a last resort and needs its own decision.

## Data model

The base form file stays where core puts it and gains two keys the add-on owns.

```yaml
# resources/forms/contact.yaml
title: Contact
store: true
email:
  - to: info@example.com
    subject: Website inquiry
sites: [en, es]              # absent or empty = all sites
site_overrides:
  es:
    title: Contacto
    email:
      - to: info-es@example.com
        subject: Consulta del sitio
    store: false
```

- `sites` assigns the form. A form listing one site is a per-site form; a form listing several is shared with the option to differ; no list is global.
- `site_overrides` is keyed by site handle. Only `title`, `email` and `store` are recognized in the first release. Unknown keys are ignored, never errors.
- **Resolution for a site:** `site_overrides[site][key]` if present, else the base value. An overriding `email` replaces the whole list; it is not merged, because merging two recipient lists silently sends mail to people nobody named for that site.
- **Why not keyed maps under core's own keys.** `email: {en: [...], es: [...]}` would be read by core as two email configs named `en` and `es`. Keeping core's keys exactly as core defines them is what makes removal safe.
- `success_message` and `redirect` are deliberately absent. Core has no such form keys: the redirect comes from tag parameters and `Form::getSubmissionRedirect()`. They become overridable only with a defined hook, as a later phase.

### Control Panel fields

`Form::appendConfigFields('*', 'Sites', [...])` adds a **Sites** section to the form config screen: a sites selector for `sites`, and a grid or replicator for `site_overrides`. This uses core's own extension point, so no Control Panel view is replaced. Whether core round-trips these handles through its edit and save path, and whether a nested overrides field renders there, is **unverified** (spike 1).

## Site determination

The add-on uses core's own rule for the submit request, `Site::findByUrl(URL::previous()) ?? Site::default()`, through one shared helper, so the site that gates a submission, the site recorded on it, and the site core hands to the email job cannot disagree. On frontend page renders the site is `Site::current()`.

**Stated limit.** The submit rule trusts the referrer. A missing or stripped referrer resolves to the default site. Assignment therefore gates which forms are offered and accepted; it is **not** a security boundary against a client that crafts requests. This is the same trust core already extends when it picks the email locale. A signed site value carried with the form would harden it and is deferred.

## Runtime behavior

| Surface | Behavior |
| --- | --- |
| `{{ form:create }}` and the other form tags | Resolve the form for `Site::current()`. If the form is not assigned, behave per `not_assigned` (below). Otherwise the tag renders the resolved view, so title and the rest follow the site. Mechanism **unverified** (spike 3). |
| Submit | Middleware on the form action route, after route binding, replaces the bound `form` with the resolved view for the request's site. A form not assigned to that site is a 404, matching core's missing-form 404. Because the controller reads `store()` and builds the submission from the bound object, the override of `store` and the email configs reach core with no change to core. Mechanism **unverified** (spike 5). |
| Recording the site | A `FormSubmitted` listener sets a reserved key on the submission data (`_site`). It runs before save and before emails are queued. Request values starting with `_` are treated by core as parameters, and only blueprint fields are processed into data, so a visitor cannot inject the key. |
| Email | The submission's form is the resolved view, so `SendEmails` reads the site's `email` list with no hook into core. The site passed to the job is core's own, already the same value. |
| REST and GraphQL | An optional `site` filter limits form listings to forms assigned to it. Without it, behavior is core's. Phase 3. |
| Static caching | Not changed. Whether static cache keys already separate sites (they are URL-based, and sites have distinct URLs) is **unverified**; confirm with the same URI on two domains before claiming it. |

`not_assigned` is a config value with two settings. `empty` renders nothing from the tag and logs a warning naming the form and site; `404` aborts the request. **The default is `empty`**: a page that embeds a form not offered on this site is an authoring slip and should degrade, while the warning keeps it from hiding. Submit is always a 404.

## Submissions

New submissions store the site in the data under `_site`. Core's store, indexes and file layout are untouched.

- **Existing submissions have no `_site`.** They are unattributed. They remain visible to anyone who could see them before. A `multisite-forms:attribute` command assigns a site to unattributed submissions of a form (with `--dry-run` and a log of each change), for installs that want to backfill.
- **Per-site listing and export** filter on `_site` through the query builder. Whether the key can be queried and indexed by the Stache is **unverified** (spike 2). If it cannot, the fallback is a post-filter, which is correct but not indexed.
- **Why not a folder per site, now.** The store's filter only accepts files directly in the form folder, so `contact/clinic/*.yaml` would be invisible to core. A folder layout would mean replacing the store, its key and path rules and its indexes from the first release, and tying the add-on to Stache internals that move between versions. A folder layout can be reached later by a deterministic per-file move, so this choice is reversible and the other is not cheap. The recorded decision is in `docs/DECISIONS.md` once the operator closes it.

## Control Panel

No Control Panel view is replaced.

- **Form visibility.** Core's index keeps only forms the user `can('view')`. The add-on adds a gate callback on the form and submission abilities: a form with a non-empty `sites` list is denied to a user who has no `access {site} site` for any assigned site. Super users are untouched. The index therefore scopes itself.
- **Submission visibility.** A user sees submissions whose `_site` is a site they can access, and unattributed submissions only when they can access every site the form is assigned to. Where the listing and export controllers read submissions without a hook that can apply this, a thin controller-level scope is needed (spike 4), and if that proves unclean the fallback is a site filter parameter rather than an altered screen.
- **Permissions.** No new keys. Per-site access is the intersection of a core form permission and a core site permission, so an administrator already has the controls. This differs from per-form-per-site keys on purpose: it is less expressive and far less to keep correct. If per-form-per-site grants are demanded, they are an addition.
- **Site selector.** Core's Control Panel already tracks a selected site. Making the forms index filter by the *selected* site, instead of by access, needs UI and is deferred.

## Events

None added in the first release. Core's `FormSubmitted`, `SubmissionSaved`, `FormSaved` and the rest are enough for the add-on and for anyone extending it. A resolved-form event is added when a consumer needs it.

## Compatibility and risks

| Risk | Mitigation |
| --- | --- |
| Core changes `Form::hydrate()`/`save()` handling of unknown keys. | A test asserts both keys survive a core save round trip; the CI compatibility jobs already run the lowest and the latest `statamic/cms`. |
| The Control Panel config screen changes how added sections render. | Only `appendConfigFields` is used; the host-bound test loads the screen. |
| The resolved view leaks into a save. | It refuses `save()` and `delete()`; a unit test asserts both throw. |
| The submit middleware runs before or after route binding differently across versions. | Test in the host on the lowest and latest supported version. |
| A site-denying gate callback conflicts with core's policy `before`. | Super users and `configure forms` pass before the callback; tests cover each. |
| `_site` collides with a user field. | Underscore-prefixed keys are core's parameter namespace and cannot be blueprint handles. |

## Phasing

Each phase ends with a host-bound test run in `statamic-multisite-forms-host` (`docs/DEVELOPMENT.md`).

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| 0. Spikes | Spikes 1 to 5 below, each recorded in `docs/DECISIONS.md` or on its ticket. | Every unverified item above is either verified or has a recorded fallback. |
| 1. Assignment and submit | `sites` key and Sites config section; resolved view; submit middleware and 404 for unassigned sites; `{{ form }}` tag gating; `_site` recorded on new submissions. | In the two-site host, a form assigned to `en` renders on `en`, 404s on submit from `es`, and its submissions carry `_site: en`. |
| 2. Overrides | `site_overrides` for title, email and store; overrides editor in the config section. | A form assigned to both sites emails a different recipient from each, and stores on one site only. |
| 3. Access and APIs | Site-access gate on forms and submissions; per-site listing and export; `site` filter on REST and GraphQL; `multisite-forms:attribute`. | An editor with access to only `es` cannot list, open or export an `en` submission, and cannot see an `en`-only form. |
| 4. Optional | Success message and redirect overrides with a defined hook; selected-site filtering in the forms index; signed site value; per-site submission folders. | Driven by demand. |

### Spikes

1. **Config round trip.** `appendConfigFields` with `sites` and a nested `site_overrides` field: does core's edit and save path persist both and render the nested field?
2. **Querying `_site`.** Does `FormSubmission::query()->where('_site', 'en')` filter correctly, and is it indexed?
3. **Tag resolution.** Seeding the `form-{handle}` Blink key per request, versus another seam, so the tags see the resolved view without rebinding `Form::find()`.
4. **Submission listing scope.** Which hook lets the Control Panel listing and the export apply the `_site` scope.
5. **Resolved view through the controller.** A resolved clone as the bound `form`: does `store()`, `makeSubmission()` and the queued email job read the overrides, and does the submission file stay in the form's own folder?

## Open questions

- [ ] Default for `not_assigned`: `empty` with a warning (proposed) or `404`.
- [ ] Unattributed legacy submissions: visible to everyone who could see them before (proposed), or only to users with access to all sites.
- [ ] Is access-based scoping (proposed) enough for the first release, or is a selected-site filter on the forms index required?
- [ ] Who may assign a form to a site: anyone who can edit the form (proposed), or only users with access to every site being added.
- [ ] Submissions as a flat store with `_site` (proposed) or a folder per site; this closes in `docs/DECISIONS.md` as a decision fork.

## Test plan

A two-site fixture in the host: one form assigned to both sites with different recipients, and one assigned to a single site. Unit tests cover resolution order, the read-only view and the key survival round trip; Integration tests cover the gate callback and the `FormSubmitted` listener; host-bound tests cover the Control Panel config screen, the submit middleware, the tags and the emailed recipient. The `host-only-behavior-needs-a-host-run` rule applies to every phase after the first.
