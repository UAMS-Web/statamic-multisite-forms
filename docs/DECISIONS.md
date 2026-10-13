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

## 2026-10-11: Local patches to pest-plugin-mutate are temporary (repository setup)

**Decision.** Seven patches under `patches/` are applied to `pestphp/pest-plugin-mutate` through `cweagans/composer-patches`, so mutation runs are trustworthy on Windows.

**Why.** Unpatched, a mutant child that cannot launch is scored as killed, so a run can report 100% having executed nothing. The patches are carried only until upstream fixes land, per `upstream-patch-lifecycle`.

**Rejected.** Running mutation testing on macOS only: both platforms are supported. Skipping mutation testing: it removes the check for false-green tests.

**Companion.** The `upstream-patch-lifecycle` and `adversarial-review` rules.
