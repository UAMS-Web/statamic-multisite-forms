# Decision log

The canonical record of **why** this repository is the way it is: the reasoning and the **rejected alternatives** behind decisions that have closed. It is the guard against silently relitigating a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question, or picking an approach, check here first: the path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record (or make the case to revisit it); do not quietly re-decide.

**When a seam closes, record the verdict in both places.** Capture the *what*, *why* and rejected alternatives **here** (the cross-ticket index) **and** in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two in sync. This file does not replace the issue tracker, which stays the system of record for a single ticket's status.

See the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule for how a fork is surfaced and driven to resolution before its verdict lands here.

## How to add an entry

Oldest first, one entry per closed decision, so **a new entry is appended to the end of the file**. Keep it terse; the issue carries the depth. Each entry is preceded by exactly one blank line, which `scripts/check-decision-log-order.mjs` enforces along with the date order. The file is marked `merge=union` in `.gitattributes`, so two branches that each append an entry merge without a conflict.

```markdown
## YYYY-MM-DD: <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the GitHub issue holding the full reasoning>
```

---

## 2026-10-11: PHP floor is ^8.4 (repository setup)

**Decision.** `require.php` is `^8.4`, and Rector, PHPStan and CI target 8.4.

**Why.** The Pest 5 line requires PHP 8.4, and the mutation-testing instrument depends on it. A lower floor would have meant a second, older test toolchain.

**Rejected.** Keeping `^8.3` with Pest 4: it forfeits the Pest 5 mutation tooling the shared rules assume.

**Companion.** Commit history on `set-up-dev-tooling`.

## 2026-10-11: CI runs as chained jobs, not one job (repository setup)

**Decision.** `.github/workflows/ci.yml` runs one job per `composer ci` step, each `needs:` the previous one, in cost order.

**Why.** A failure names its step, and a cheap failure skips every more expensive job after it. The cost is that a green run takes the sum of the jobs, which is accepted for a library this small.

**Rejected.** A single job running `composer ci`: one opaque failure and no early exit between steps. A parallel matrix: faster but spends the expensive jobs on a branch that already failed a cheap one.

**Companion.** Commit history on `set-up-dev-tooling`.

**Superseded** by "CI jobs run in parallel" below.

## 2026-10-11: Local patches to pest-plugin-mutate are temporary (repository setup)

**Decision.** Seven patches under `patches/` are applied to `pestphp/pest-plugin-mutate` through `cweagans/composer-patches`, so mutation runs are trustworthy on Windows.

**Why.** Unpatched, a mutant child that cannot launch is scored as killed, so a run can report 100% having executed nothing. The patches are carried only until upstream fixes land, per `upstream-patch-lifecycle`.

**Rejected.** Running mutation testing on macOS only: both platforms are supported. Skipping mutation testing: it removes the check for false-green tests.

**Companion.** The `upstream-patch-lifecycle` and `adversarial-review` rules.

## 2026-10-12: MIT license and a statamic/cms compatibility matrix (#13)

**Decision.** The add-on is MIT licensed (`LICENSE`, `"license": "MIT"`), and CI runs Larastan and Pest on a lowest-`statamic/cms` leg and a PHP 8.5 leg after the main chain.

**Why.** A public Packagist and Marketplace package needs a declared license. The package promises `statamic/cms` at its `composer.json` constraint, and one tested combination cannot back that promise. The lowest leg derives its bound from the constraint with `--prefer-lowest`, so it follows the floor as code starts to need newer APIs, and its check name carries no version number so the required-checks list stays stable.

**Rejected.** A hard-coded lowest version in CI: it would drift from `composer.json`. A full `--prefer-lowest` over every dependency: it would downgrade the dev tooling and the patched mutate plugin, testing the tools instead of the add-on. A proprietary license: it can be revisited with editions.

**Companion.** #13.

## 2026-10-12: CI jobs run in parallel (#20)

**Decision.** No job in `ci.yml` declares `needs:`; every job, the `Compatibility` legs included, starts at once. Job names are unchanged, so the required-checks ruleset is unaffected. `composer ci` keeps its cost order locally.

**Why.** The chain exists in `UAMS-Web/uams-statamic` to protect a private repository's Actions minutes budget, and the entry above never recorded that reason. This repository is public, where standard runners are free, so the early exit saves nothing and the chain costs wall-clock time and shows failures one job at a time.

**Rejected.** Keeping the chain: it spends time to save a budget that does not exist here. Chaining only the cheap gates: a smaller version of the same cost for a saving of free minutes. Reverting to a chain is cheap if the repository ever goes private.

**Trade.** More concurrent runner use per push, and a pull request that fails an early job still runs the expensive ones.

**Companion.** #20.

## 2026-10-12: A check compares the required-checks ruleset with the job names (#11)

**Decision.** `check-required-checks.yml` runs `scripts/check-required-checks.mjs` on every pull request, every push to `main` and weekly. A name the ruleset requires and no job reports is always an error. A job the ruleset does not require is a warning on a pull request and an error on `main` and on the weekly run. The required list comes from `GET repos/{o}/{r}/rules/branches/main`.

**Why.** Nothing failed at the moment of a rename: the first symptom was a pull request waiting on a check no job reported. The effective-rules endpoint is readable without a credential on a public repository (measured: HTTP 200 and 15 contexts, unauthenticated), so no token decision was needed. The two directions are not equally harmful, and a pull request that adds a job cannot also pass a check demanding it, because requiring the name first blocks every other pull request. That is why only the blocking direction fails a pull request.

**Rejected.** Failing both directions on a pull request: adding or renaming a job could never merge. An allow-list of optional jobs: nothing here needs one yet, and an unrequired job already surfaces as a warning. Reading the organization ruleset itself: it needs the `admin:org` scope, which no workflow token has. A regex over the YAML in place of the `yaml` package: a matrix name such as `Compatibility (${{ matrix.name }})` needs real parsing.

**Companion.** #11. The shared version is `UAMS-Web/uams-claude-skills#170`, blocked by #169.

## 2026-10-12: A persistent multisite host serves the development loop (#16)

**Decision.** A Statamic 6 host named `statamic-multisite-forms-host` lives in the maintainer's Herd directory and installs the add-on from a local checkout through a path repository. `tests/Feature/` holds host-bound tests that extend the host's `Tests\TestCase`; PHPStan excludes it and the package has no `Feature` suite. The procedure is `docs/DEVELOPMENT.md`.

**Why.** Per-site forms behavior needs a real multisite, and Testbench boots a minimal one. A persistent, clearly named host means the host-app run the `host-only-behavior-needs-a-host-run` rule requires has somewhere to happen, and the existing `uams-statamic` host stays unmodified.

**Rejected.** Pointing an existing project's `composer.json` at the add-on: it edits a real site and leaves a path repository behind. A throwaway host per run: a Composer create-project and a multisite conversion every time. Running `php please multisite` to convert: it hangs without a terminal.

**Companion.** #16.

## 2026-10-12: The Control Panel assets build with Vite and the output is committed (#15)

**Decision.** `vite.config.js` builds `resources/js/cp.js` and `resources/css/cp.css` into `resources/dist`, and the provider registers the build through `$vite`. The built files are committed and stay in release archives; the tooling (`package.json`, the lockfile, `vite.config.js`) is export-ignored. The `Built assets (resources/dist matches source)` job rebuilds and fails when anything under `resources/dist` differs. `vite` is `^6.4.4` and `laravel-vite-plugin` is `^1.3.0`, the lines Statamic's guide names; `laravel-vite-plugin` 3 needs Vite 8.

**Why.** A Composer install does not run npm, so a release that does not carry the build ships nothing the Control Panel can load. A stale committed build is the failure that does not announce itself, so a job compares it with a fresh one.

**`.npmrc` policy.** No change was needed. With `ignore-scripts`, `min-release-age=3` and `engine-strict` in force, `npm install` resolved the Vite toolchain and `npm run build` produced the manifest on Windows 11 (npm 11.19.0). `@statamic/cms` is a `file:` link, so it is not subject to the release-age floor, and every job that runs `npm ci` now runs the Composer setup first.

**Rejected.** Building in release tooling only and leaving `resources/dist` out of git: a path-repository or VCS install would then have no assets. Ignoring the dev build: Statamic's guide says not to commit it, and this commits only the production build.

**Companion.** #15.

## 2026-10-12: The add-on is free with no editions (#17)

**Decision.** The add-on is free, declares no `extra.statamic.editions`, and stays MIT (#13). Nothing is submitted to Packagist or the Statamic Marketplace by this decision; submitting remains a separate operator decision.

**Why.** `src/` has no logic yet, so there is nothing to put behind a paid edition. Declaring `["free", "pro"]` now would commit edition handles to the Marketplace, where they must match and the first one is the default, for a Pro edition with nothing in it. Going free first is expected to leave a later move to editions non-breaking, since `free` listed first stays the default for installs that never chose one; that is an inference and is checked in #27.

**Rejected.** Declaring `free` and `pro` now: handles committed before any Pro feature exists. Paid from the start: needs bank details, a license rework away from MIT and an institutional sign-off. A free tier plus paid Pro: the costs of paid, and needs features to put behind Pro.

**Companion.** #17. Follow-ups: #25 (README), #26 (submission checklist), #27 (editions can be added later).

## 2026-10-13: Submissions stay in core's layout and record their site in a `_site` key (#43)

**Decision.** A submission is stored where core stores it, `<submissions dir>/<form handle>/<id>.yaml`, and the add-on records the originating site in a reserved `_site` data key. Submissions saved before the add-on carry no key and are unattributed. No per-site folder is introduced.

**Why.** It replaces nothing in core. Core's `FormSubmissionsStore` reads only files directly inside a form's folder and takes the handle from that folder name, so a per-site folder would need its own store, handle parsing and `Submission::path()`, all Stache internals that move between versions. The `_site` key is measured to work: it filters correctly, including `whereNull` for unattributed submissions, and is indexed on first use (spike 2, #35), and a site-resolved form keeps its handle, so its submissions land in the form's own folder (spike 5, #38). Removing the add-on leaves every submission visible to core. Per-site Control Panel isolation comes from the query scope, not the directory.

**Rejected.** A folder per site (`<handle>/<site>/<id>.yaml`): it replaces core's store, needs a migration command for existing data, and hides submissions from core if the add-on is removed. Its benefit, per-site directories visible in Git, stays reachable later by a one-way, file-by-file move, which is why choosing the flat layout first is the reversible order.

**Companion.** #43. Spec: `docs/SPEC.md`.

## 2026-10-13: Submissions are scoped by site by decorating the query builder contract (#49)

**Decision.** The add-on scopes Control Panel submission reads by site with `app()->extend()` on the `SubmissionQueryBuilder` contract: whatever builder the container produces gets a `_site` where-clause (the user's accessible sites, plus unattributed submissions as #40 decides). The scope applies only to Control Panel requests by non-super users. No core class or controller is replaced.

**Why.** Every Control Panel read (listing, detail view, delete, export and the forms index count) resolves that contract. Decorating it was measured to scope each path while core's builder stays bound (spike 4, #37, and the follow-up on #49), and it uses only the public query API, so it should still apply on top of a different submissions driver.

**Rejected.** Binding a subclass of core's Stache builder: replaces the bound class and would replace another driver's builder. Overriding the Control Panel controllers: a larger surface that misses any read it does not override. A filter parameter only: a convenience, not access control.

**Risk accepted.** The same builder serves the API and the `{{ form:submissions }}` tag, so the request-context condition is load-bearing: tests must show it scopes Control Panel requests and leaves the others alone.

**Companion.** #49. Spec: `docs/SPEC.md`.

## 2026-10-13: Unattributed submissions are visible only to users who cover every assigned site (#40)

**Decision.** A submission with no `_site` is shown to a non-super user only when that user can access every site the form is assigned to; for a global form (no `sites` list) that means every site. The `multisite-forms:attribute` command assigns a site to unattributed submissions for installs that want them visible to single-site editors.

**Why.** Unattributed is an ongoing category, not only pre-install history: any submission saved without the submit route has no `_site`. Showing those to anyone who can view the form would let a single-site editor read another site's data whenever the key is missing, which is the leak the add-on exists to prevent and the wrong default for form submissions that may hold personal information. Users trusted with every site the form serves keep full visibility.

**Rejected.** Visible to anyone who can view the form: no change on install, but it leaks across sites indefinitely. Super users only until attributed: safe, but hides ordinary history from everyone with broad access.

**Cost accepted.** Single-site editors lose sight of pre-install submissions until they are attributed. The release that ships the scope says so in its notes.

**Companion.** #40. Spec: `docs/SPEC.md`. Related: #49 (the scope mechanism).

## 2026-10-13: `configure forms` does not bypass the site scope (#54)

**Decision.** A non-super user holding `configure forms` is scoped to their accessible sites like any other non-super user, for both forms and submissions. Only super users see every site. The form-level denial is a global `Gate::before()` callback, because core's form policies return true from their own `before()` for `configure forms`, and Laravel lets a global `before` callback decide ahead of any policy while an `after` callback can change only a null result.

**Why.** In a multisite install the people who build forms for one site are the ones given `configure forms`. If that permission bypassed the scope, per-site isolation would protect only plain viewers, and every site's form builders would read every site's submissions.

**Rejected.** Letting `configure forms` bypass the scope, as core's policies do today: no surprise for an existing form admin, but it leaves isolation to the least-privileged users only.

**Cost and residual.** This narrows what core grants `configure forms`; the release that ships the scope says so. A scoped `configure forms` user can still edit another site's `site_overrides` and redirect its future email; who may edit a site's settings is #42.

**Companion.** #54. Spec: `docs/SPEC.md`. Related: #40, #49.

## 2026-10-13: Only users who cover every assigned site may edit a form (#42)

**Decision.** A non-super user may edit a form's settings (its sites, base values and `site_overrides`) only when they can access every site the form is assigned to; for a global form, every site. The denial is the global `Gate::before()` already chosen in #54, applied to the `edit` ability. A form created by a user who does not cover every site is assigned to the sites they can access.

**Why.** Base `title`, `email` and `store` reach every assigned site without an override row, so editing them changes other sites, and a scoped admin could otherwise reroute another site's email. Reusing the `Gate::before()` adds no new mechanism and avoids a save-time check, which would meet a trap in core: `FormsController::update()` reports "Saved" without checking whether `save()` succeeded. Single-site forms stay fully editable by their site's admins.

**Rejected.** Any form admin edits everything (core's behavior): allows cross-site email rerouting. Per-site editing (own sites' rows and membership; base values only when covering all): the right refinement if shared-form friction proves real, but it needs per-field diffing at save time and a rejection the Control Panel can show, neither verified.

**Cost and residual.** On a shared form a per-site admin cannot edit even their own site's row and needs a cross-site admin. A scoped admin can still view a shared form's settings; whether to deny viewing too is not decided. How the creation default is set is unmeasured.

**Companion.** #42. Spec: `docs/SPEC.md`. Related: #54.

## 2026-10-13: The first release scopes by site access only; a Site filter follows (#41)

**Decision.** The first release offers no site selector: editors see forms and submissions for the sites they can access, and nothing narrows that further. The next step is a Site filter on the Control Panel submissions listing (#58).

**Why.** Isolation is complete by access, so a selector is a convenience for users with several sites, not part of the protection. The cheapest selector is a core filter class declared `visibleTo('form-submissions')`, which core's listing and export already apply through `queryFilters()`; it needs no Vue change. That was read in `statamic/cms` v6.35.1 and not exercised, so it follows as its own ticket rather than gating the first release.

**Rejected.** Shipping the Site filter in the first release: reasonable, but unexercised. A switcher that follows the Control Panel's selected site (`Site::selected()`): it needs changes to core's Vue pages, the upgrade-fragile surface the spec avoids.

**Cost.** Until #58 ships, a user with access to several sites sees their submissions mixed in one listing.

**Companion.** #41. Follow-up: #58. Spec: `docs/SPEC.md`.

## 2026-10-13: A form tag renders nothing on a site the form is not assigned to (#39)

**Decision.** When a form is not assigned to the current site, the `{{ form:create }}` family renders nothing and logs a warning naming the form and the site. The `not_assigned` config value can be set to `404` to abort the request instead; `empty` is the default. A submit to an unassigned site is always a 404.

**Why.** In a multisite install the common way to reach this case is a shared template, such as a footer or layout partial, rendered on every site with a form meant for some of them. On the other sites that is intended, so a 404 or an error would break working pages. The warning leaves a trace for a genuine authoring slip.

**Rejected.** A 404 as the default: the whole page fails on every non-assigned site that shares the template. Throwing, as core does for a missing form (`Tags::getForm()`): a 500 is never what an editor wants.

**Residual.** The warning's level and frequency are settled at build time. A template check of assignment, to hide surrounding content, is #60.

**Companion.** #39. Follow-up: #60. Spec: `docs/SPEC.md`.

## 2026-10-13: Viewing a shared form's settings needs no rule of its own (#57)

**Decision.** No separate rule. Core authorizes the form settings screen on the `edit` ability (`FormsController::edit()` calls `authorize('edit', $form)`), and the #42 denial of `edit` to users who do not cover every assigned site therefore also hides the settings from them. The submissions page (`show()`, authorized on `view`) carries no email configuration.

**Correction.** The #42 entry's residual says a scoped admin can still view a shared form's settings. That was wrong: denying `edit` already denies the settings screen.

**Related finding.** The front-end `{{ form:create }}` tag exposes added config fields to templates as `form_config`, which would include every site's `site_overrides`; keeping them out is #62.

**Companion.** #57. Related: #42, #62.


## 2026-10-13: The form tags are gated by a subclass of core's tags, not by a Blink seed alone (#73)

**Decision.** The add-on registers `FormTags`, a subclass of core's `Statamic\Forms\Tags`, under core's `form` handle. Each tag that reads a form resolves it for the page's site; an unassigned form renders nothing or aborts with 404, per `not_assigned`. Under `empty`, `create` returns an empty string, `errors` and `success` return `false`, and `submission` returns null, as core's does before a submit. `submissions` returns an empty list, which a tag pair renders as it renders a form with no submissions, since that is the value a template already handles. `form:fields` is left to core, because it reads only the fields `form:create` hands it. Under `404` the tag throws Statamic's `NotFoundHttpException`, as core's `{{ 404 }}` tag does, so the response is the site's 404 page in its layout; once the request is aborting, a further gated tag (in a layout the error page shares) renders nothing instead of throwing again, which would make the response a 500. The warning for `empty` is logged at `warning` level, once per form and site per request.

**Why.** The spec's mechanism, seeding core's `form-{handle}` Blink key with the site's view (spike 3), cannot meet the ticket alone. Core's `formHandle()` puts a form passed in from context straight into that key, replacing the seed, so a form handed to the tag as an object would render on every site. And a missing form makes core throw (`getForm()`), so nothing seeded can make the tag render empty. Resolving inside the tag closes both and needs no early-request hook. Once per request at `warning` keeps a shared layout to one line per page view while still surfacing an authoring slip.

**Rejected.** Seeding Blink from a middleware: misses the context case and cannot render empty. Seeding every form on every front-end request: the same gaps, plus a lookup of every form per request. Logging every render: a layout with the form in several places floods the log. Logging at `debug`: hides the authoring slip the warning exists to show.

**Residual.** Another add-on, or an app-level `app/Tags` class, that also registers the `form` handle conflicts with this one: core registers app tags before add-ons boot, so this add-on silently replaces an app's, and between two add-ons whichever registers last wins. An invalid `not_assigned` value is reported only when a page meets an unassigned form. When the tag is fetched with no parser (`Statamic::tag('form:create')->fetch()`), core returns the form's data as an array, and a gated tag returns an empty string instead, which a template indexing into the result does not expect. Decided in #89: the gated tag returns an empty array on that path (#100).

**Companion.** #73. Earlier decision: #39. Spec: `docs/SPEC.md`.


## 2026-10-13: The submit site is recorded on FormSubmitted, with a SubmissionCreating backstop on the submit route (#74)

**Decision.** `RecordSubmitSite` sets `_site` on the submission from `FormSubmitted`, which core dispatches from its submit route after building the data and before saving it or queuing its emails, using the shared submit-site helper. It also listens to `SubmissionCreating`: on the submit route, a new submission of the submitted form that reaches its first save without `_site` gets it then. Both are registered explicitly in the service provider.

**Why.** Core dispatches `FormSubmitted` halting on the first listener that returns anything other than null, and Laravel runs listeners in registration order, so an app's listener registered before the add-on's that returns `true` would skip the recording and the submission would save unattributed. The backstop closes that for stored submissions, and is limited to the submit route and the submitted form, so a submission saved any other way (including another form's, saved by a listener during the submit request) stays unattributed, as the spec defines.

**Rejected.** Recording only on `SubmissionCreating`: it fires only when submissions are stored, so the queued email would never carry the key. Recording only on `FormSubmitted`: the halting gap above. Reordering the dispatcher's listeners so the add-on's runs first: it reaches into the dispatcher's internals. Discovering the listener from a `Listeners` folder: explicit registration keeps the two events in one visible place.

**Residual.** `SubmissionCreating` halts the same way, so an app that halts both events with listeners registered before the add-on's still saves the submission unattributed. When `FormSubmitted` is halted and submissions are not stored, the email job receives the submission without `_site`; nothing reads `_site` from an email. The site comes from the referrer, which the visitor controls, so a visitor can attribute a submission to any site the form is assigned to, as visiting that site's page would; core's email site follows the same header.

**Companion.** #74. Spec: `docs/SPEC.md` (Recording the site).


## 2026-10-13: A gated `form:create` fetched with no parser returns an empty array (#89)

**Decision.** When `form:create` is gated for an unassigned form and the tag is fetched with no parser (`Statamic::tag('form:create')->fetch()`), it returns an empty array. With a parser, in Antlers and Blade tag pairs, it keeps returning an empty string.

**Why.** Core returns an array on that path, so an empty array keeps the type a template expects, and it is the value core itself returns there when it has nothing: `user:passkeys` returns `[]` when it cannot parse contents. It is falsy, so the guard a template author naturally writes, `@if ($form)`, skips it, and `foreach` and `count()` over the result work. Measured against the ways a template reads the result, with warnings raised as exceptions as Laravel raises them: an empty string errors on `foreach ($form['fields'])` and on `foreach ($form)`; an empty array errors only on an unguarded index.

**Rejected.** Keeping the empty string: it costs nothing to build and leaves the return type depending on assignment. An array with the keys a template reads (`attrs`, `params`, `fields`, `sections`), all empty: it is truthy, so `@if ($form)` passes and the template renders a form with no `action`, which then errors. `null`: falsy like an empty array, but not the type core returns.

**Residual.** A template that reads `$form['attrs']['action']` without a guard errors on an unassigned form whatever the tag returns: only a real form satisfies it, and rendering one is what the gate prevents. The README documents the guard. Under the `404` setting the tag aborts on every path, so this applies to `empty` only.

**Companion.** #89; build in #100. Earlier decisions: #39, #73.


## 2026-10-13: The Sites field does not check site access, as core's collection sites do not (#85)

**Decision.** The Sites field on a form's settings screen accepts any configured site, whether or not the signed-in editor may view it. Its dropdown still lists only the sites the editor may view, as core's `sites` fieldtype filters it.

**Why.** Only a super user or a user with `configure forms` reaches the screen (`FormPolicy::before`), a structural permission that already lets them change the form's blueprint and settings or delete it. Core treats the matching collection setting the same way: `CollectionsController::update` saves a collection's `sites` without checking site access, and core checks site access for content within a site (entries, terms, globals, navigation), not for structure configuration. Refusing a site would make forms stricter than collections for no gain over what the permission already grants.

**Rejected.** Refusing any handle the editor cannot view, and merging an existing assignment to such a site back in on save: stricter than core, and it needs a rule that reads the current user's permissions. Refusing only additions: the same cost, with the same small gain.

**Residual.** Saving the screen keeps an existing assignment to a site the editor cannot view (measured in #101): the field lists that site by its handle rather than its name, and posts it back with the rest. An editor can still remove it from the field on purpose, as with any selected site.

**Companion.** #85; measurement in #101. Earlier decision: #70.


## 2026-10-13: An invalid `not_assigned` value is logged at boot, not thrown (#90)

**Decision.** The add-on checks `not_assigned` each time it boots and logs an invalid value at `error` level, with the setting's key and the value. A page that meets an unassigned form still throws, as before.

**Why.** Before this, an invalid value, most likely `null` from an unset `env()` variable, surfaced only as a 500 on a page that met an unassigned form, possibly long after deployment and on some sites only. Logging at boot reports it on the first request or `artisan` command after the change, and leaves every page that renders no unassigned form working.

**Rejected.** Throwing at boot: it stops every `artisan` command, `package:discover` included, so a deploy fails at `composer install`, and it takes down every page for a value that affects only some. Throwing outside the console only: the same site-wide outage on the web. A doctor-style command: it reports only when someone runs it, which is the gap the ticket describes. Falling back to `empty` for an invalid value: it would hide a configuration error behind a guess at what was meant.

**Residual.** The error is logged on every boot until the value is fixed, which is one line per request. A log nobody reads still reports nothing, so the page that meets an unassigned form keeps failing loudly.

**Companion.** #90. Earlier decision: #73.


## 2026-10-13: Templates check a form's site with a `form:assigned` tag (#60)

**Decision.** `{{ form:assigned in="contact" }}` answers whether a form renders on the page's site: true for a form assigned to it or one with no sites, false for a form assigned elsewhere or a missing one. As a single tag it returns that boolean, for an Antlers `if` or `Statamic::tag('form:assigned')->fetch()` in Blade; as a tag pair it renders its contents only when the form is assigned. It never aborts or logs, whatever `not_assigned` says.

**Why.** It follows core's own `form:errors`, which is a boolean as a single tag and a conditional block as a pair, so it reads like the tags it sits beside and works in Antlers, Blade components and fetched form. It resolves the form exactly as the gated tags do, through the same resolver and `Site::current()`, so it cannot disagree with what `form:create` renders.

**Rejected.** A variable injected into every view: it needs a form handle to mean anything, so it would be one variable per form, computed on every page whether or not a template reads it. A single tag only: an Antlers condition covers it, but a pair is the shorter form for wrapping markup and costs one branch. Throwing for a missing form, as `form:create` does: the check is for deciding what to show, and a template asking about a form that does not exist wants the answer "no".

**Residual.** The name takes precedence over a form whose handle is `assigned` in the `{{ form:{handle} }}` shorthand, as core's tag names already do for `create`, `errors` and the rest; such a form renders with `{{ form:create in="assigned" }}`.

**Companion.** #60. Earlier decisions: #39, #73.


## 2026-10-13: A resolved view stays a core `Form`; driver-specific code reads `base()` (#107)

**Decision.** A site-resolved view (`SiteForm`) stays a subclass of core's `Statamic\Forms\Form` whatever class the form contract is bound to. Where a driver binds its own class, as `statamic/eloquent-driver` does with `Statamic\Eloquent\Forms\Form`, that class's own methods are reached through `SiteForm::base()`, which returns the stored form. The README documents it (#109).

**Why.** Measured in #83, a view carries the same values as its base under the Eloquent forms driver; the only difference is type identity, so `model()` throws on a view and `instanceof` the driver's class is false. Neither the add-on nor the driver calls either on a view: the driver uses `model()` only in its own `save()` and `delete()`, which a view refuses, and its submissions read only the form's handle. The case that can meet it is outside code holding `$submission->form()`, and `base()` already serves it for any binding, not only this driver. Adding forwarding later is not breaking, so nothing is closed off.

**Rejected.** Forwarding the methods a view does not declare to its base through `__call`: `model()` would work but `instanceof` still would not, and a blanket forward lets a setter such as `model($model)` write to the base form, which the read-only view exists to prevent, so it would need an allow-list kept in step with each driver, for no caller known to need it. A view class per driver, built from a shared trait and interface: it gives `instanceof` for one driver only, misses any other binding, and changes `FormResolver::resolve()`'s return type, which breaks code type-hinting `SiteForm`.

**Residual.** Any app that binds its own form class has the same gap and the same answer. Control Panel form screens and the Eloquent submissions driver were not measured with the forms driver on (spike 7).

**Companion.** #107; measurement in #83; documentation in #109.

## 2026-10-13: Deleting a form and editing its fields need every site, as editing does (#77)

**Decision.** The site rules on form access are one global `Gate::before()` callback (`UAMSWeb\MultisiteForms\Access\FormGate`). For a form, or a submission through its form, it denies a non-super user who can access none of the form's sites. For the abilities that change a form (`edit`, and also `delete` and `configure form fields`) it requires every site the form is assigned to, or every configured site for a global form. Site access is asked of core's site policy (`can('view', $site)`), and a handle naming no configured site is never accessible, so a form still listing a removed site is open to super users only. The callback never grants: everything it does not deny is left to core's policies.

**Why.** #42 decided that editing needs every site because a change to the base values reaches each one. Deleting a form removes it from every site at once, and its fields are its blueprint, shared by every site, so both reach further than editing and earn the same rule. Asking core's site policy keeps one source of truth for site access, including core's answer on a single-site install, where every user can access the one site and the rules change nothing. Failing closed on an unknown handle keeps a stale assignment from opening a form to everyone.

**Rejected.** Applying the every-site rule to `edit` only, as #77's summary words it: a user who may not edit a shared form could still delete it for every site. Treating a removed site's handle as absent from the list: the form would turn global for every user, the opposite of what its assignment says.

**Residual.** Core's form blueprint editor authorizes `configure form fields` without naming the form, so the callback cannot reach it; the route stays open to such a user and is tracked in #116. Core's Duplicate action authorizes `create` on the form class without naming the form it copies, tracked in #117. #79 then assigns a new form to its creator's sites, so a form created by a user who does not cover every site stays within that user's edit. Whether saving `sites` should refuse a site the editor cannot access, now that `configure forms` is no bypass, is the question in #118.

**Companion.** #77. Earlier decisions: #42, #54, #57.


## 2026-10-13: A new form takes its creator's sites on `FormCreating`, whatever created it (#79)

**Decision.** A listener on core's `FormCreating` event sets `sites` on a new form to the sites the signed-in user can access, when that user is not a super user and cannot access every configured site. It leaves the form as core made it when the form already lists sites, when no user is signed in, when the user covers every site (always the case on a single-site install), and when the user can access no site.

**Why.** #42 decided that editing needs every site and that a scoped creator's form is assigned to their sites, so they can still edit it; #77 put the edit rule in place. `FormCreating` fires once, before a new form is first written, for every way a form is made (the create screen, the Duplicate action, code), so no controller is replaced and an existing form is never reassigned by a later save. Keeping sites a form already lists respects an assignment made in code and a duplicate's copy of its source's.

**Rejected.** Hooking the create screen's request (a middleware on `forms.store`): it misses the Duplicate action and forms made in code by a signed-in user. Assigning a creator who can access no site to an empty list: an empty list already means every site, so it would change nothing while reading as a decision. Refusing the creation for such a user: a listener that cancels the save leaves core's create screen redirecting to a form that does not exist.

**Residual.** Not measured with `statamic/eloquent-driver`'s forms; whether their `save()` dispatches `FormCreating` as core's does was not checked, and #83 measured their views, not creation.

**Companion.** #79. Earlier decisions: #42, #77.

## 2026-10-13: Control Panel submission reads keep unattributed ones by id when the user covers only some forms (#78)

**Decision.** `UAMSWeb\MultisiteForms\Access\SubmissionScope` decorates the bound `SubmissionQueryBuilder` with `app()->extend()`, as #49 decided, and adds one nested condition on a Control Panel request by a signed-in non-super user. When the user covers every form (can access every site of each), it keeps `_site` in the user's sites or `_site` missing (null). Otherwise it reads, with a separate unscoped query, the ids of the submissions with no `_site` of the forms the user covers, drops any id a submission of another form also carries, and keeps `_site` in the user's sites or `id` in that list. A `_site` of an empty string counts as neither, so it is hidden from every non-super user. Between core's `FormDeleting` and `FormDeleted` events the request reads unscoped, marked on the request rather than in a static, so deleting a form deletes all of its submissions. A bound builder that extends neither of core's query builders is refused with a `LogicException` rather than read unscoped, and a form that is not core's form class counts as one the user does not cover.

**Why.** #40 makes an unattributed submission's visibility depend on its form, so the condition has to say "this form and no site". Core's Stache builder takes `form` out of the conditions and applies it to the whole query, and ignores it inside a nested group, so the per-form rule cannot be written as a group; reading the ids first is the form of it that runs through the public query API. Plucking `id` from the Stache builder returns its `form::id` keys, not the submission ids, so the ids are read from the submissions. An id is a submission's file name and is not unique across forms, while the id rule names no form, so an id shared with a form the user does not cover is dropped rather than shown. `Form::delete()` deletes its submissions through the same builder, so without the delete exception a user allowed to delete a form left behind the submissions they could not see, and a new form with the handle would show them; core dispatches `FormDeleting` without halting, so once it fires the delete runs. Failing loudly on an unknown builder, and closed on an unknown form, keeps a driver nobody tested from showing another site's submissions.

**Rejected.** One nested group per covered form (`form` and an empty `_site`): the Stache builder ignores the `form` inside it, so every unattributed submission would show. Caching the id list per request: a delete in the same request would leave it stale, and the read is one query per scoped builder. Hiding every unattributed submission from a user who does not cover every form: it breaks #40 for the forms the user does cover.

**Residual.** Measured against core's Stache builder, in the Testbench app and in the host (`tests/Feature/SubmissionSiteScopeTest.php`). Not measured with `statamic/eloquent-driver`'s submissions, which this repository does not install: core's `Statamic\Query\EloquentQueryBuilder::whereNested`, which a driver's builder inherits unless it overrides it, builds the inner query through `app(static::class)`, which resolves the concrete class rather than the contract and so should not carry the scope a second time; that was read from the code, not run, and #53's measurements covered views, not this scope. Every scoped builder for a user covering only some forms reads those forms' unattributed submissions, and the forms index resolves one builder per form, so that page's cost grows with forms times unattributed submissions; not measured. A form deleted with `deleteQuietly()` fires neither event and is still scoped; core's Control Panel does not do that. On a Control Panel request, saving an existing submission the user cannot see makes core treat it as new (`Submission::save()` looks it up through the scoped builder), so `SubmissionCreating` fires; core's Control Panel never saves a submission. `Statamic::isCpRoute()` compares the path with the raw `statamic.cp.route` value; a value with a leading slash serves the Control Panel while it is false, and #122 replaced it.

**Companion.** #78. Earlier decisions: #40, #49, #54.

## 2026-10-13: The form fields editor is guarded by a route middleware in Statamic's Control Panel group (#116)

**Decision.** `UAMSWeb\MultisiteForms\Http\Middleware\AuthorizeFormBlueprint` is pushed onto the router's `statamic.cp.authenticated` middleware group. On the `statamic.cp.blueprints.forms.edit` and `statamic.cp.blueprints.forms.update` routes only, it authorizes `configure form fields` with the form, so the add-on's gate applies the rule for changing a form (every site it is assigned to, or every site for a form with no sites). Every other route, and a handle naming no form, passes through to core.

**Why.** Core's `FormBlueprintController` authorizes the ability in its constructor without the form, so the gate cannot see which form is asked for; a second authorization that names the form closes the route without replacing the controller. The group is registered on the router by Statamic rather than owned by the HTTP kernel, so pushing onto it survives the kernel's later group copies, and it travels with the routes whether or not they are cached.

**Rejected.** Replacing or extending core's controller: it ties the add-on to that class's internals. A middleware on the front-end `web` group, as the submit gate uses: it would run on every request for two Control Panel routes. Checking the route's URI rather than its name: the Control Panel prefix is configurable.

**Residual.** Three places in core still list every form by title, each through `Form::all()`, so a user sees the titles of forms on sites they cannot access, though following a link to one is now refused: the editor's breadcrumb (`FormBlueprintController::edit()`), the blueprints index for a user with `configure fields` and `configure form fields` (`BlueprintController::forms()`), and the command palette for a user with `configure fields` (`Palette::buildFields()`). The breadcrumb is #124; the blueprints index and the palette are #133. A user with `configure fields` can also edit a global fieldset that a form's blueprint imports; fieldsets carry no sites, and that permission is not a form permission.

**Companion.** #116. Earlier decisions: #77.

## 2026-10-13: The Duplicate action is replaced under core's handle by one that also asks `view` (#117)

**Decision.** `UAMSWeb\MultisiteForms\Actions\DuplicateForm` extends core's `Statamic\Actions\DuplicateForm` and adds `view` on the form to its `authorize()`. Statamic registers the actions under an add-on's `src/Actions` by handle while booting the add-on, after core has registered its own, and the class's short name gives it core's handle, `duplicate_form`, so it replaces core's entry. Running the action and listing it, for one form or several, both go through `authorize()`.

**Why.** Core's action asks only `create` on the form class, so the gate never sees the form being copied, and a user with `configure forms` could copy a form of a site they cannot access, with its recipients, data and blueprint. Measured: with core's class registered again, the refusal tests answer 200 and copy the form.

**Rejected.** A middleware on the form action route: it would have to read the posted action name and selections, which core's controller already parses. Binding core's class to the add-on's in the container: Statamic's registration by handle already replaces it, and two mechanisms for one replacement leave a reader unsure which one acts. Asking `edit` rather than `view`: the copy is a new form, and the ticket asks for `view`.

**Residual.** A copy keeps its source's sites, so a user who may view a form shared with a site they cannot access, such as an `es`-only user on an `en` and `es` form, can create a form assigned to that site too. They cannot change the copy afterwards, since changing a form needs every one of its sites. Another add-on, or the host, registering its own `duplicate_form` after this one would replace it in turn.

**Companion.** #117. Earlier decisions: #77, #79.

## 2026-10-13: The submissions Site filter offers the user's sites the form is assigned to (#58)

**Decision.** `UAMSWeb\MultisiteForms\Query\Scopes\Filters\SubmissionSite`, registered by Statamic from `src/Query/Scopes/Filters`, is a filter on the `form-submissions` listing with the handle `submission_site`. It narrows the query by `_site` to one site. It offers the sites the user can access that the form is assigned to (every site, for a form with no sites), and is visible only when that leaves at least two. A chosen site leaves out submissions with no recorded site; with none chosen, the submission scope (#78) decides as before. A site handle the filter does not offer is applied as given, so it finds no submission the user can see rather than lifting the filter. Its badge names only an offered site, and shows any other by its handle, since core returns the badge for whatever handle a request names; site names are translated, as core's entries filter translates them.

**Why.** Core's listing and export both apply the filters a request names, so one filter class narrows both and needs no change to core's pages. Offering only sites both reachable by the user and covered by the form keeps the choice to sites that can hold visible submissions.

**Rejected.** The handle `site`, which a class named `Site` would take: it is core's entries site filter, and Statamic registers filters by handle, so it would replace core's. Offering every site the user can access: a form assigned to one site would show a choice with nothing behind it.

**Residual.** A submission recorded for a site the form is no longer assigned to cannot be chosen in the filter, though it still shows unfiltered to a user who can access that site.

**Companion.** #58. Earlier decisions: #41, #78.

## 2026-10-13: The attribute command refuses a site the form is not assigned to, and saves quietly (#81)

**Decision.** `multisite-forms:attribute {form} {site}` records `_site` on every submission of the form that has none, read with `whereNull('_site')` through the submission query builder. An unknown form or site, and a site the form is not assigned to, is an error with a non-zero exit. The form is matched by its exact handle, not with `Form::find()`, which on a filesystem that ignores case returns a form under the handle as typed and saves its submissions through another Stache store. Submissions are saved with `saveQuietly()`. Each change is logged at `info` level with the form, the submission's id and the site; `--dry-run` reports the same list and writes and logs nothing.

**Why.** A submission recorded for a site its form does not cover is one the Site filter cannot offer (#58), so the command would create the case that decision leaves as a residual. Core's own data migrations (`statamic:migrate-dates-to-utc`) save quietly: a backfill is not a new submission, so it should fire no event a listener, such as a Git integration committing each save, would act on. Core sends a form's email only from its submit route, so no save sends one. Reading through the query builder serves the Stache and the Eloquent driver alike, both measured.

**Rejected.** Allowing any configured site: it would let a backfill hide submissions from the form's own editors. Saving with events: a large backfill would trigger a listener per submission for a change no listener needs to see.

**Residual.** A search index that indexes submissions is not told of the change, as with core's data migrations. The unattributed submissions are read at once, which on the Eloquent driver holds them all in memory; not measured at volume. A submission whose `_site` is an empty string is neither read as unattributed nor shown by the Control Panel scope to anyone but super users; the add-on never writes one, and #130 decides it.

**Companion.** #81. Earlier decisions: #40, #58.

## 2026-10-13: The API form listings take a `site` filter through bound subclasses of core's (#80)

**Decision.** `UAMSWeb\MultisiteForms\Api\FormsController` and `UAMSWeb\MultisiteForms\GraphQL\FormsQuery` extend core's REST forms controller and GraphQL `forms` query, and are bound in the container in their place. The REST listing reads a `site` query parameter, as core's entries endpoints do; the GraphQL query takes a `site` argument. Either lists the forms the API exposes that are assigned to the site, or have no sites. An unknown site is a validation error: 422 with a message from REST, a `validation` error from GraphQL. Without the filter, or with a null GraphQL argument, the listings are core's.

**Why.** Laravel builds a route's controller, and the GraphQL library each query class, through the container, so a binding adds the filter without touching core's routes or the schema Statamic registers. Filtering after core's allowed-resources check keeps the API's own configuration in charge of what is exposed.

**Rejected.** A 404 for an unknown site, as core's entries endpoints answer: the ticket asks for a validation error, which says what was wrong. `nullable` on the GraphQL argument: Laravel skips other rules for an empty string, so `site: ""` would have listed only the forms with no sites; the rules apply only to a non-null value, and require it.

**Residual.** A host or another add-on binding its own class to either of core's would replace this one, or be replaced by it, depending on order.

**Companion.** #80.

## 2026-10-13: The fields editor's breadcrumb is filtered on the prepared Inertia response (#124)

**Decision.** `UAMSWeb\MultisiteForms\Http\BlueprintBreadcrumbs` listens for Laravel's `PreparingResponse` event. When the response is an Inertia response on the `statamic.cp.blueprints.forms.edit` route, it sets the `additionalBreadcrumbs` page prop to Statamic's pushed breadcrumbs with every link to a form's fields editor the user may not open removed: the forms for which the gate denies `configure form fields`. Every other response passes through.

**Why.** Measured before the change: an `es`-only user with `configure form fields` opening an `es`-only form's editor received the titles and editor links of every other form, `en`-only forms included, in the page's `additionalBreadcrumbs` prop, and in the HTML of a full page load. Core's `FormBlueprintController::edit()` pushes the breadcrumb, and `Breadcrumbs::push()` shares the result with Inertia at once, so nothing before the controller can filter it. Route middleware receives the response only after `Router::prepareResponse()` has rendered it, while `PreparingResponse` fires with the Inertia response still open, and a page prop set with `with()` overrides a shared one of the same name. Limiting links to editors the user may open, rather than to forms they may view, is narrower than the ticket asks: a link to an editor that #116 refuses would lead to a 403.

**Rejected.** Replacing or extending core's controller: it ties the add-on to that class's internals. Filtering in the editor route's middleware: the response is already rendered by then. Rewriting `Breadcrumbs::$pushed` before the controller runs: the controller pushes after any middleware's first half. Limiting links to the forms the user may view: an `es`-only user would see links to an `en` and `es` form's editor, which refuses them.

**Residual.** The blueprints index and the command palette still list every form by title (#133). Another listener that sets `additionalBreadcrumbs` on the same response after this one would replace the filtered prop.

**Companion.** #124. Earlier decisions: #77, #116.

## 2026-10-13: The submission scope recognizes a Control Panel request by the trimmed route prefix (#122)

**Decision.** `SubmissionScope::isControlPanelRequest()` replaces core's `Statamic::isCpRoute()` as the scope's test for a Control Panel request. It is false when the Control Panel is disabled or `statamic.cp.route` is not a string; otherwise it trims slashes from the configured route and from the URL-decoded request path, and is true when the path is the route or starts with the route and a slash. An empty route, which puts the Control Panel's routes at the root where they collide with the front end, makes every path count as one.

**Why.** Measured in the host (Windows 11, `statamic/cms` v6.35.1, PHP 8.4.20) by handling requests in process under each `CP_ROUTE`, with `cp` as the control: with `/cp` and with `/cp/`, `/cp/auth/login` answered 200 and `/cp` and `/cp/forms` redirected to the login page, each on its `statamic.cp.*` route, while `isCpRoute()` was false on all three; with `cp/` it was false on `/cp` itself. Core registers the routes with the configured value as a prefix, which Laravel trims, while `isCpRoute()` compares the raw value, so under those values every Control Panel read went unscoped. Trimming both sides matches the prefix the routes are registered under. Under `/cp` the Control Panel only partly works, which the host's scope tests showed: core's route bindings test the route's URI against the raw value too (`RouteServiceProvider::isCpRoute()`), so every Control Panel route that binds a form receives the handle as a string, and those tests answered 403, 500 and redirects where they pass under `cp`. The trimmed check therefore matters there for pages that bind no form, such as the forms index and its counts. The path is decoded because the router matches the decoded path while `path()` keeps it encoded: before the change, in the Testbench app, an `es`-only user listing a form's submissions through `/%63p/forms/shared/submissions` or `/cp%2Fforms/shared/submissions` was served the `en` and unattributed submissions too, since `isCpRoute()` is false on the encoded path; core's check has the same gap.

**Rejected.** Testing the matched route's name for the `statamic.cp.` prefix: a read made before routing, or outside a route, has no route to ask, and the scope's tests and callers decide by path today. Normalizing `statamic.cp.route` in config at boot: it would change the value core and other add-ons read.

**Residual.** Core's own uses of `isCpRoute()` are unchanged, so other core behavior under a slashed route or an encoded path is as core has it. Under an empty route, front-end and API reads by a signed-in non-super user are scoped as well, which hides submissions rather than showing them; that configuration already collides with the front end. A path with a doubled leading slash counts as a Control Panel one though the Control Panel's routes do not match it, as in core, which also errs toward scoping.

**Companion.** #122. Earlier decisions: #49, #78.

## 2026-10-13: The blueprints index and the command palette are filtered on the prepared response, as the breadcrumb is (#133)

**Decision.** `UAMSWeb\MultisiteForms\Http\BlueprintListings` listens for `PreparingResponse`. On the `statamic.cp.blueprints.index` route, while the response is an Inertia response, it sets the `forms` page prop to core's `BlueprintController::forms()` less every form whose fields editor the user may not open. On the `statamic.cp.command-palette.index` route, where core's controller returns the palette as a collection, it removes the items linking to such an editor from that collection in place, keeping it a list. `UAMSWeb\MultisiteForms\Access\RefusedFormEditors` names the refused editors' URLs, built as core builds its links, for this listener and the breadcrumb's (#124).

**Why.** Measured before the change, in the Testbench app: for an `es`-only user with `configure fields` and `configure form fields`, the index's `forms` prop and its HTML carried all four test forms' titles and editor links, and the palette's response linked all four forms' editors, each named by its blueprint's title, which defaults to one made from the form's handle. The palette is cached forever per user (`Palette::cacheKey()`), so filtering the response, not the cache, needs no invalidation and keeps the cache as core builds it. The rule is the editor's own, as for the breadcrumb, so every link left opens.

**Rejected.** Replacing or extending core's controllers or palette: it ties the add-on to their internals. Adding to the palette's build through its public `add()`: it can only add items, not remove them. Reading the index's `forms` prop back from the Inertia response: the response has no public reader for it, so the listener asks core's controller for the same list again.

**Residual.** The palette's search endpoint (`command-palette.search`) searches the `cp` search index, which is not filtered here; core's default for that index searches `content`, `users` and `addons` (`IndexManager::indexesConfig()`), so it holds no forms unless a host adds them. Another listener that changes the same prop or collection after this one would act on the filtered list.

**Companion.** #133. Earlier decisions: #116, #124.
