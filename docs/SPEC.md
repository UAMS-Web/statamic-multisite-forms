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
| The submission query builder accepts arbitrary `where` columns (it special-cases only `form`, `forms` and the `datestamp` order). An arbitrary data key is filtered and gets a `Value` index on first use (spike 2). | `Stache/Query/SubmissionQueryBuilder.php` |
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
  - site: es
    title: Contacto
    store: false
    email:
      - to: info-es@example.com
        subject: Consulta del sitio
```

- `sites` assigns the form. A form listing one site is a per-site form; a form listing several is shared with the option to differ; no list is global.
- `site_overrides` is a list of rows, one per site, each with a `site` handle. This is the shape a grid field saves (spike 1), so the Control Panel editor needs no custom fieldtype. Only `title`, `email` and `store` are recognized in the first release. Unknown keys are ignored, never errors, and a second row for the same site is a validation error.
- **Resolution for a site:** the value in the row whose `site` matches, if present, else the base value. An overriding `email` replaces the whole list; it is not merged, because merging two recipient lists silently sends mail to people nobody named for that site.
- **Why not keyed maps under core's own keys.** `email: {en: [...], es: [...]}` would be read by core as two email configs named `en` and `es`. Keeping core's keys exactly as core defines them is what makes removal safe.
- `success_message` and `redirect` are deliberately absent. Core has no such form keys: the redirect comes from tag parameters and `Form::getSubmissionRedirect()`. They become overridable only with a defined hook, as a later phase.

### Control Panel fields

`Form::appendConfigFields('*', 'Sites', [...])` adds a **Sites** section to the form config screen: a sites selector for `sites`, and a grid or replicator for `site_overrides`. This uses core's own extension point, so no Control Panel view is replaced. Core's edit and save path round-trips both handles, including a grid nested in a grid (spike 1, below). Whether the Control Panel renders the nested grid in a browser was not checked.

## Site determination

The add-on uses core's own rule for the submit request, `Site::findByUrl(URL::previous()) ?? Site::default()`, through one shared helper, so the site that gates a submission, the site recorded on it, and the site core hands to the email job cannot disagree. On frontend page renders the site is `Site::current()`.

**Stated limit.** The submit rule trusts the referrer. A missing or stripped referrer resolves to the default site. Assignment therefore gates which forms are offered and accepted; it is **not** a security boundary against a client that crafts requests. This is the same trust core already extends when it picks the email locale. A signed site value carried with the form would harden it and is deferred.

## Runtime behavior

| Surface | Behavior |
| --- | --- |
| `{{ form:create }}` and the other form tags | Resolve the form for `Site::current()`. If the form is not assigned, behave per `not_assigned` (below). Otherwise the tag renders the resolved view, so title and the rest follow the site. Mechanism: seed the `form-{handle}` Blink key with the resolved view early in the request (spike 3). |
| Submit | Middleware on the form action route, after route binding, replaces the bound `form` with the resolved view for the request's site. A form not assigned to that site is a 404, matching core's missing-form 404. Because the controller reads `store()` and builds the submission from the bound object, the override of `store` and the email configs reach core with no change to core. Verified through the real controller (spike 5). |
| Recording the site | A `FormSubmitted` listener sets a reserved key on the submission data (`_site`). It runs before save and before emails are queued. Request values starting with `_` are treated by core as parameters, and only blueprint fields are processed into data, so a visitor cannot inject the key. |
| Email | The submission's form is the resolved view, so `SendEmails` reads the site's `email` list with no hook into core. The site passed to the job is core's own, already the same value. |
| REST and GraphQL | An optional `site` filter limits form listings to forms assigned to it. Without it, behavior is core's. Phase 3. |
| Static caching | Not changed. Whether static cache keys already separate sites (they are URL-based, and sites have distinct URLs) is **unverified**; confirm with the same URI on two domains before claiming it. |

`not_assigned` is a config value with two settings. `empty` renders nothing from the tag and logs a warning naming the form and site; `404` aborts the request. **The default is `empty`**: a page that embeds a form not offered on this site is an authoring slip and should degrade, while the warning keeps it from hiding. Submit is always a 404.

## Submissions

New submissions store the site in the data under `_site`. Core's store, indexes and file layout are untouched.

- **Existing submissions have no `_site`.** They are unattributed. They remain visible to anyone who could see them before. A `multisite-forms:attribute` command assigns a site to unattributed submissions of a form (with `--dry-run` and a log of each change), for installs that want to backfill.
- **Per-site listing and export** filter on `_site` through the query builder. The Stache filters on the key correctly and indexes it on first use (spike 2, below), so no post-filter is needed.
- **Why not a folder per site, now.** The store's filter only accepts files directly in the form folder, so `contact/clinic/*.yaml` would be invisible to core. A folder layout would mean replacing the store, its key and path rules and its indexes from the first release, and tying the add-on to Stache internals that move between versions. A folder layout can be reached later by a deterministic per-file move, so this choice is reversible and the other is not cheap. Decided in `docs/DECISIONS.md` (#43).

## Control Panel

No Control Panel view is replaced.

- **Form visibility.** Core's index keeps only forms the user `can('view')`. The add-on adds a gate callback on the form and submission abilities: a form with a non-empty `sites` list is denied to a user who has no `access {site} site` for any assigned site. Super users are untouched. The index therefore scopes itself.
- **Submission visibility.** A user sees submissions whose `_site` is a site they can access, and unattributed submissions only when they can access every site the form is assigned to. Every Control Panel read goes through `Form::querySubmissions()` or `FormSubmission::find()`, which resolve to the container-bound `SubmissionQueryBuilder` contract. The add-on decorates that contract with `app()->extend()`, adding the site scope through the public query API, so the listing, detail view, delete, export and the forms index count are scoped without replacing a controller or core's builder class (spike 4, decided in #49). The scope applies only to Control Panel requests by non-super users, because the same builder serves the API and the `{{ form:submissions }}` tag.
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

1. **Config round trip.** Resolved (#34); see the results below.
2. **Querying `_site`.** Resolved (#35); see the results below.
3. **Tag resolution.** Resolved (#36); see the results below.
4. **Submission listing scope.** Resolved (#37); see the results below.
5. **Resolved view through the controller.** Resolved (#38); see the results below.

## Open questions

- [ ] Default for `not_assigned`: `empty` with a warning (proposed) or `404`.
- [ ] Unattributed legacy submissions: visible to everyone who could see them before (proposed), or only to users with access to all sites.
- [ ] Is access-based scoping (proposed) enough for the first release, or is a selected-site filter on the forms index required?
- [ ] Who may assign a form to a site: anyone who can edit the form (proposed), or only users with access to every site being added.
- [x] Submissions as a flat store with `_site` or a folder per site. Decided: flat store with `_site` (#43, `docs/DECISIONS.md`).
- [x] How submissions are scoped by site in the Control Panel. Decided: decorate the `SubmissionQueryBuilder` contract with `app()->extend()` (#49, `docs/DECISIONS.md`).

## Test plan

A two-site fixture in the host: one form assigned to both sites with different recipients, and one assigned to a single site. Unit tests cover resolution order, the read-only view and the key survival round trip; Integration tests cover the gate callback and the `FormSubmitted` listener; host-bound tests cover the Control Panel config screen, the submit middleware, the tags and the emailed recipient. The `host-only-behavior-needs-a-host-run` rule applies to every phase after the first.

## Spike results

Each result names what was measured, where, and what it did not cover. Measured on Windows 11 with PHP 8.4.20, `statamic/cms` v6.35.1 and Laravel 13.35.0, in `statamic-multisite-forms-host`. macOS is not measured.

### Spike 1: config round trip (#34)

`Form::appendConfigFields('*', 'Sites', ...)` was registered with a `sites` field (the `sites` fieldtype) and a `site_overrides` grid whose rows hold a `site`, a `title`, a `store` toggle and a nested `email` grid. A probe then drove the same code path as `FormsController::edit()` and `update()`: built the edit blueprint, validated and processed a request body, merged the non-core values into the form, saved, reloaded from disk, and rebuilt the edit values.

| Check | Result |
| --- | --- |
| The edit blueprint contains `sites` and `site_overrides`. | Yes |
| `process()` returns both handles alongside core's. | Yes |
| The file on disk after save. | `sites: [en]`, and `site_overrides` as a list of rows with the nested `email` list intact |
| Reloaded form data and the values `edit()` would hand the screen. | Identical to what was saved |
| Control: a handle that was never appended. | Absent from the blueprint |

A grid saves a list of rows, not a map keyed by site, which is why the data model uses a list. Not covered: rendering in a browser, validation messages for the nested grid, and duplicate-site validation (the add-on must supply it).

### Spike 2: querying a reserved `_site` key (#35)

Four submissions were saved to a probe form through `$submission->save()`: two with `_site: en`, one with `_site: es`, and one with no `_site`. They were queried from a separate process, so the Stache was cold.

| Query | Result |
| --- | --- |
| `where('form', ...)` alone | all four |
| `where('_site', 'en')` | the two `en` submissions; `count()` returns 2 |
| `where('_site', 'es')` | the one `es` submission |
| `whereIn('_site', ['en', 'es'])` | the three attributed submissions |
| `whereNull('_site')` | the one unattributed submission |
| Control: `where('_site', 'xx')` | none |

After the queries, the form's store listed a `_site` index of class `Statamic\Stache\Indexes\Value`, alongside core's `id`, `path`, `form` and `date`. The index is created on first use and is not declared by the add-on, so nothing needs registering. Not covered: query performance at volume, and behavior when the key is absent from every submission of a form.

### Spike 3: resolving the site view in the form tags (#36)

The `create` tag looks its form up with `Blink::once("form-{handle}", ...)`. A probe instantiated `Statamic\Forms\Tags` as core does, rendered `create` with a content template of `[{{ honeypot }}]`, and compared three states of the Blink key. The honeypot was used as the observable because the tag exposes it from the form it resolved.

| State | Rendered honeypot |
| --- | --- |
| Control: nothing seeded | `base_trap`, the value on disk |
| A clone with honeypot `site_trap` seeded under `form-spike_t` | `site_trap` |
| The seeded key forgotten | `base_trap` again |

The base file on disk still read `base_trap` after the seeded render, and the form's action URL was unchanged (`/!/forms/spike_t`). So the tags accept a resolved view through Blink with no change to `Form::find()` or the repository. The seed must happen before the tag runs and within the same request, because Blink is request-scoped. Not covered: a render through a real page request and view, since `Antlers::parse` in a console returned empty output for every tag tried, including a control tag, so the probe went through the tag class directly.

The `create` tag also runs `attrs`, `after-open` and `before-close` hooks (`Forms/Tags.php`). `after-open` is a candidate seam for carrying a signed site value in a hidden field later, the hardening named under Site determination. It was read in source, not exercised.

### Spike 4: scoping submissions by site (#37)

Core's submission listing, export, detail view and delete all read through the container-bound `Statamic\Contracts\Forms\SubmissionQueryBuilder` (`Form::querySubmissions()` and `FormSubmission::find()`). A probe bound a subclass of the core builder, built with the `form-submissions` store the way core's own binding builds it, that adds `(_site in the user's accessible sites) or _site is null` for a non-super user. It then made real in-process Control Panel requests as a user holding `access cp`, `view` and `delete` on the form and `access en site` but not `access es site`. Three submissions existed: `en`, `es` and unattributed.

| Request | Scope off (control) | Scope on |
| --- | --- | --- |
| `GET /cp/forms/{form}/submissions` | `a`, `b`, `c` (all three) | `a`, `c` |
| Detail view of the `es` submission | 200 | 404 |
| Detail view of the `en` submission | 200 | 200 |

The scoped builder reached the controllers with no controller override. Findings the table does not show:

- A plain `app()->bind(Contract, Subclass)` fails, because the core builder needs a `Store` and the container cannot supply one. The binding must be a closure that passes the `form-submissions` store, as core's does.
- The same builder serves the API, the `{{ form:submissions }}` tag and the forms index count, so the scope has to be conditioned on a Control Panel request and a non-super user. The probe conditioned on the current user only; a request-context condition is still to be designed.
- The forms index count (`$form->querySubmissions()->count()`) would also be scoped, which is the intended behavior and avoids leaking other sites' counts.
- The export was not requested. Without filters it reads `$form->submissions()`, which calls `FormSubmission::whereForm()`, which uses the same builder by reading the code; that is not a measurement.
- Delete was not requested. It resolves its submission through `$form->submission($id)`, which calls the same `find()` the detail view uses.

### Spike 5: a resolved form through the submit controller (#38)

A probe added a route middleware to the real `FormController@submit` route that, when switched on, replaced the bound `form` parameter with a clone whose `email` and `store` differed from the file. It then posted a submission through the full request stack with the queue set to `sync` and the mailer faked. The probe read the email config core handed to each mailable, because the fake mailer records a mailable before core's `build()` sets recipients.

| Run | Email config handed to the mailable | Submissions stored |
| --- | --- | --- |
| A, control: middleware off | `base@example.com` with subject `Base` | 1 |
| B: middleware swaps in the clone | `es@example.com` with subject `ES` | still 1 (nothing new stored) |

Also observed: the form file on disk kept `base@example.com` and `store: true`; the stored submission lives in the form's own folder (`spike_s`); the clone kept the base handle, so the action URL and the folder are unchanged.

Route middleware order for that route was `HandlePrecognitiveRequests`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`, `ThrottleRequests:statamic.forms`, `SubstituteBindings`, then the probe middleware. The swap ran after binding, which is the order the design needs.

Not covered, and each is a limit on this result:

- The probe attached its middleware to the route object. Attaching from an add-on, through a middleware group, was not tried. Group middleware is sorted by Laravel's priority list, so a class outside the list should land after `SubstituteBindings`, but that is reasoning, not a measurement.
- CSRF protection (`PreventRequestForgery`) was removed from the probe route to submit without a token. The design does not touch it.
- The first two attempts returned 419 and a mail check that saw no mail even in the control. Both instruments were fixed before any result was read, and the table above is from the run where the control produced the base recipient.

Follow-up measurement for #49: the same scope applied with `app()->extend()` on the contract, instead of a subclass, left core's `Statamic\Stache\Query\SubmissionQueryBuilder` bound and scoped every read path tried. With the extender off (control) a listing returned `a,b,c`, `find()` of the `es` submission found it and the count was 3; with it on, the listing returned `a,c`, `find()` returned null, the count was 2, and `whereForm()` (the export path) returned `a,c`. Switching the extender off returned the listing to `a,b,c`. Measured in a console, not through Control Panel requests.
