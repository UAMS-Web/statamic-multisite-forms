# Agent instructions for statamic-multisite-forms

This file is the single source of agent instructions for this repository. Claude Code, Cursor and Codex all read it directly; there is no `CLAUDE.md`, because Claude Code reads `AGENTS.md` only when `CLAUDE.md` is absent. Put instructions here, never in a `CLAUDE.md`.

Everything above the generated section below belongs to this repository and is edited here. The generated section is rewritten by the sync from `UAMS-Web/uams-claude-skills`; edit the shared skills and rules there.

## About this repository

A Statamic add-on (`uams-web/statamic-multisite-forms`, namespace `UAMSWeb\MultisiteForms`) that makes forms per-site in a multisite install: site assignment, per-site overrides, and site-separated submissions in flat files. It is a Composer library with no `artisan`, `.env`, database or front end; a host application boots it through `extra.laravel.providers`. The `statamic-package-conventions` skill covers what that changes.

### Commands

- `composer install`: installs dependencies and applies the `patches/` set to `pestphp/pest-plugin-mutate` (temporary, Windows-safe mutation testing; see `upstream-patch-lifecycle`).
- `composer ci`: the gate, run in cost order and stopping at the first failure: `test:pint`, `test:refactor` (Rector dry run), `stan` (Larastan), `test` (Pest `Unit` and `Integration`, run in parallel through the bundled paratest; `composer test:serial` runs the same suites in one process, which is the first thing to try when a failure looks order-dependent). GitHub Actions runs the same scripts as separate chained jobs (`.github/workflows/ci.yml`), plus `composer validate` and the synced-files check; change a script and its job together.
- `composer pint` and `composer refactor` apply formatting and the gating Rector profile; `composer refactor:sweep` runs the deliberate sweep profile.
- `composer mutate`: Pest mutation testing over `src/`. Read `pcov-setup` before trusting a score.

### Layout and tests

- `src/` holds the add-on (PHP floor `^8.4`, `statamic/cms` `^6.35`); `tests/Unit` boots nothing, `tests/Integration` boots a minimal Statamic through Testbench (`tests/TestCase.php`).
- Host-bound tests belong in `tests/Feature/` and are not part of `composer ci`; the `host-only-behavior-needs-a-host-run` rule says when a host run is required.
- Tests run in parallel workers, so a test must not share mutable state with another: no fixed file paths, cache keys or Stache/form storage directories. Give each worker its own directory (`ParallelTesting::token()` or `uniqid()`) when a test writes files.
- The static-analysis cache lives in `storage/phpstan` (git-ignored).

<!-- uams-claude-skills:begin -->
## Shared skills and rules

The files listed here are synced from [UAMS-Web/uams-claude-skills](https://github.com/UAMS-Web/uams-claude-skills) (`shared/`) and are edited there, never here; a sync pull request brings changes into this repository. This section is generated; everything outside it belongs to this repository.

Each rule below is a standing instruction for every task in this repository. Its full text is delivered three times: `.claude/rules/<name>.md`, which Claude Code loads in every session; `.cursor/rules/<name>.mdc`, which Cursor always applies; and the skill `.agents/skills/rule-<name>/`, which Codex selects by its description. Before work a rule governs, read its full text. Skills are in `.claude/skills/` and, as the same files, in `.agents/skills/`, and load when their description matches the task.

Profiles for this repository: core, php, pest, laravel, laravel-package, statamic, statamic-package, tests, quality.

### Rules

- `adversarial-review`: Adversarially verify before it reaches anyone else. Full text: [`.claude/rules/adversarial-review.md`](.claude/rules/adversarial-review.md).
- `american-english-prose`: American English for prose, and a dictionary entry that overrides it needs a stated reason. Full text: [`.claude/rules/american-english-prose.md`](.claude/rules/american-english-prose.md).
- `an-empty-result-is-not-evidence`: A result is not evidence until the instrument has been shown to tell the two answers apart. Full text: [`.claude/rules/an-empty-result-is-not-evidence.md`](.claude/rules/an-empty-result-is-not-evidence.md).
- `closing-a-ticket`: Finish a ticket where it is visible, not just where it builds. Full text: [`.claude/rules/closing-a-ticket.md`](.claude/rules/closing-a-ticket.md).
- `coordination-plumbing-stays-out-of-artifacts`: Keep session-coordination plumbing out of GitHub artifacts and documentation. Full text: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).
- `cutting-releases`: Cut a release each business day at 10:00 Central when there is unreleased work. Full text: [`.claude/rules/cutting-releases.md`](.claude/rules/cutting-releases.md).
- `design-decision-forks`: Presenting design-decision forks (lead with a compare/contrast). Full text: [`.claude/rules/design-decision-forks.md`](.claude/rules/design-decision-forks.md).
- `filing-defects-across-repos`: File a defect in the repository that owns it, and claim it first when a race is likely. Full text: [`.claude/rules/filing-defects-across-repos.md`](.claude/rules/filing-defects-across-repos.md).
- `github-api-budget`: Spend the REST quota to preserve the GraphQL one. Full text: [`.claude/rules/github-api-budget.md`](.claude/rules/github-api-budget.md).
- `host-only-behavior-needs-a-host-run`: Behavior only the host app exercises needs a host-app run before the PR is ready. Full text: [`.claude/rules/host-only-behavior-needs-a-host-run.md`](.claude/rules/host-only-behavior-needs-a-host-run.md).
- `impersonal-voice-in-github-artifacts`: Write GitHub artifacts in an impersonal voice. Full text: [`.claude/rules/impersonal-voice-in-github-artifacts.md`](.claude/rules/impersonal-voice-in-github-artifacts.md).
- `long-running-commands`: Bound every long-running command, and resolve what you kill. Full text: [`.claude/rules/long-running-commands.md`](.claude/rules/long-running-commands.md).
- `measurement-parity`: Mirror the *job*, not the command, before comparing to a baseline. Full text: [`.claude/rules/measurement-parity.md`](.claude/rules/measurement-parity.md).
- `no-em-dashes`: No em dashes in prose, docs, comments, or strings. Full text: [`.claude/rules/no-em-dashes.md`](.claude/rules/no-em-dashes.md).
- `no-emoji-in-durable-records`: No emoji in durable records. Full text: [`.claude/rules/no-emoji-in-durable-records.md`](.claude/rules/no-emoji-in-durable-records.md).
- `pre-merge-check`: Re-verify at the merge, not only at the build. Full text: [`.claude/rules/pre-merge-check.md`](.claude/rules/pre-merge-check.md).
- `reading-exit-status`: Read the result, not the exit code. Full text: [`.claude/rules/reading-exit-status.md`](.claude/rules/reading-exit-status.md).
- `supported-platforms`: MacOS and Windows are both supported; name the platform, write paths repo-relative. Full text: [`.claude/rules/supported-platforms.md`](.claude/rules/supported-platforms.md).
- `sync-pr-branch`: Sync every PR branch from `origin/main` before the gating run. Full text: [`.claude/rules/sync-pr-branch.md`](.claude/rules/sync-pr-branch.md).
- `worktrees`: Work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout). Full text: [`.claude/rules/worktrees.md`](.claude/rules/worktrees.md).

Skills: [`code-quality`](.claude/skills/code-quality/SKILL.md), [`host-app-tests`](.claude/skills/host-app-tests/SKILL.md), [`laravel-best-practices`](.claude/skills/laravel-best-practices/SKILL.md), [`pcov-setup`](.claude/skills/pcov-setup/SKILL.md), [`pest-testing`](.claude/skills/pest-testing/SKILL.md), [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md), [`php-documentation`](.claude/skills/php-documentation/SKILL.md), [`phpunit-to-pest`](.claude/skills/phpunit-to-pest/SKILL.md), [`security-audit`](.claude/skills/security-audit/SKILL.md), [`statamic-conventions`](.claude/skills/statamic-conventions/SKILL.md), [`statamic-package-conventions`](.claude/skills/statamic-package-conventions/SKILL.md), [`synced-files`](.claude/skills/synced-files/SKILL.md), [`tests`](.claude/skills/tests/SKILL.md), [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md), [`upstream-patch-lifecycle`](.claude/skills/upstream-patch-lifecycle/SKILL.md), [`writing-comments`](.claude/skills/writing-comments/SKILL.md), [`writing-commits`](.claude/skills/writing-commits/SKILL.md), [`writing-issues`](.claude/skills/writing-issues/SKILL.md), [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md), [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md).
<!-- uams-claude-skills:end -->
