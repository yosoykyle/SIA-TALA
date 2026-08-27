# Contributing to TALA

This guide prepares a fresh clone and a developer's AI tools for TALA work. It is setup guidance, not product or workflow authority. [`AGENTS.md`](AGENTS.md), the [TALA Orchestrator Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md), the owning GitHub Issue, and the canonical product documents govern the work.

## 1. Install and verify the application

Follow the root [`README.md`](README.md) first. Before taking an Issue, confirm that the development and disposable test databases are separate and that these commands succeed in the clone:

```powershell
php -v
composer --version
node --version
npm --version
git --version
php artisan --version
npm run build
php artisan test --compact
```

Use the README's [database maintenance steps](README.md#keep-both-local-schemas-current) on a fresh clone and after pulling schema changes. Report the actual development and test database targets, pending migrations, and any observed schema mismatch separately; passing tests alone cannot establish that the browser's development database is current. Both databases use the committed migrations, while each developer keeps their own local data.

Do not copy another developer's `.env`, credentials, database contents, MCP configuration, Serena data, or personal Codex memories.

## 2. Verify the collaboration capabilities

TALA itself requires no MCP server or Codex plugin. The following requirements apply only when an AI agent contributes to the repository.

| Capability | When required | Accepted route |
| --- | --- | --- |
| Laravel-aware inspection and documentation | Every Codex-driven Laravel, Filament, Livewire, or package-sensitive change | Laravel Boost from the committed Composer development dependency |
| GitHub Issue, Project, and pull-request access | Tracked orchestration work | One authenticated route: GitHub CLI, the GitHub Codex plugin, or another approved GitHub interface |
| Browser acceptance evidence | Any applicable UI-bearing Issue criterion | One working route such as Codex Browser, Playwright MCP, Chrome DevTools, or attributable manual verification |
| Project skills | Whenever the task enters their domain | Use the applicable Git-tracked skill under `.agents/skills`; do not install duplicate copies |
| Serena semantic inspection | Only when the owning Issue, accepted plan, or task needs it | Install and activate Serena locally; rebuild its index in the clone |
| Product Design or other plugins | Only when the accepted task materially benefits from their specialized workflow | Install locally as needed; plugins never become product authority |

`composer install` installs Boost's server package; Codex must also have a working MCP configuration for this clone. If it is missing, register the server from the repository root (Codex CLI required):

```powershell
codex mcp add laravel-boost -- php (Join-Path (Get-Location).Path 'artisan') boost:mcp
```

Use this clone's absolute Artisan path, then start a new Codex task and verify a Boost call. Do not assume another developer's machine configuration transfers with Git. See the [official Codex MCP configuration guide](https://developers.openai.com/codex/mcp).

Verify GitHub access without exposing credentials:

```powershell
gh auth status
```

Do not paste raw MCP configuration or unredacted MCP inventory output into an Issue or chat. Machine-specific files such as `.mcp.json`, `.env`, `.codex/`, and `.serena/` remain ignored.

## 3. Re-anchor a new developer's Codex task

Assign work through the GitHub Issue's **Assignees** field. GitHub subscribes assignees to the Issue; delivery follows their [notification settings](https://docs.github.com/en/subscriptions-and-notifications/concepts/about-notifications). The notification informs the developer; it does not start a Codex task or authorize implementation.

Use this once after the repository, dependencies, and assigned Issue are available:

```text
Re-anchor this fresh TALA clone for assigned Issue #NN as its implementation owner.

Read AGENTS.md, CONTRIBUTING.md, the TALA Orchestrator Protocol, the owning
Issue, its accepted plan or execution handoff, and the minimum relevant
canonical documents and implementation surfaces. Verify the current branch,
HEAD relationship to origin/main, clean or attributable working state,
assignment, dependencies, workspace and database isolation, actual development
and test database targets, migration status and any observed schema mismatch
for each, GitHub access, Laravel Boost, applicable project skills, and the
browser-verification route required by the Issue. Treat Serena and other plugins
as conditional unless the Issue or accepted plan requires them.

Report what is ready, what is missing, the exact safe remedy, and whether
implementation may begin. Read-only: do not edit files, mutate GitHub, create a
branch, commit, publish, merge, deploy, or change credentials yet.
```

After readiness passes, the normal `Plan #NN`, `Complete #NN`, and `Publish #NN` boundaries remain unchanged.

## 4. Missing-capability handling

| Finding | Action |
| --- | --- |
| Boost is unavailable | Confirm `composer install` completed, register `php artisan boost:mcp`, restart the Codex task, and verify it again before Laravel code changes |
| GitHub access is unavailable | Authenticate one approved GitHub route; do not copy another person's token |
| Browser tooling is unavailable for a UI Issue | Configure one accepted browser route or leave the affected criterion `Unverified` |
| Serena is unavailable | Continue unless the Issue or accepted plan requires it; otherwise install, activate the clone, and rebuild the index |
| Either local database has pending migrations | Report the affected target and migration names; review and apply them only within an authorized setup or implementation action using the README's maintenance steps |
| A fresh clone lacks the account, roles, or academic data required by its Issue | Check the README's baseline seeding step, then propose the missing task-specific fixture/account setup for authorization; do not invent default admin credentials or copy another developer's database |
| Tests report a column from an already-recorded migration as missing | Verify the configured database is exactly `test_tala_db`, then use the root README's disposable-schema rebuild command; never run it against `tala_db` |
| The clone is behind `main`, dirty for an unknown reason, or on the wrong branch | Stop before implementation and reconcile the Git state without discarding another person's work |
| Prototype comparison evidence is needed | Use the tracked [Human-Centered Operations evidence pack](00_Project_Documents/design-evidence/human-centered-operations/README.md); never copy its generated behavior into production |

## 5. Parallel-development gate

The [TALA CI workflow](.github/workflows/ci.yml) runs on GitHub-hosted Linux machines for pull requests and pushes to `main`. It installs locked dependencies, builds frontend assets, migrates its own disposable MySQL database, and runs PHPUnit. Check the current result in [GitHub Actions](https://github.com/yosoykyle/SIA-TALA/actions/workflows/ci.yml). Passing CI supports verification; it does not replace criterion-level acceptance or required browser evidence.

Solo work may continue through the approved direct-`main` path. Before a second implementation Issue becomes active concurrently, the repository must have verified pull-request CI and protection of `main` as required by the TALA Orchestrator Protocol. Planning and onboarding may occur before that gate; concurrent implementation may not.
