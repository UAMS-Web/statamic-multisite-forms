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
