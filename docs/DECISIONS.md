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

