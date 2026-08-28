# Contributing to TALA

Accept your GitHub invitation, get TALA running, then connect your coding tools. You can do this before receiving an Issue. Run commands in PowerShell from your clone's root; skip installation steps for tools already working.

This is setup guidance. [`AGENTS.md`](AGENTS.md), the [TALA Orchestrator Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md), canonical product documents, and the owning Issue govern the work.

## 1. Install and verify the application

Follow the [README setup](README.md#local-setup), then its [developer verification](README.md#developer-verification):

- Create your own `.env` and use your own MySQL login.
- Use `tala_db` for browsing the app and `test_tala_db` for disposable automated tests. Check both schemas using the README; passing tests does not update `tala_db`.
- Confirm `composer dev` starts the app, the frontend builds, and `php artisan test --compact` passes.

Use the example environment's mock integrations initially. Keep credentials, machine-specific MCP settings, Serena indexes, and personal memories local; do not copy a teammate's whole `.env` or tool configuration.

## 2. Connect your coding tools

These tools help the coding assistant; TALA itself runs without them. Boost is required for AI-assisted Laravel/package work. Prepare Serena for code navigation; it is required only when the Issue or accepted plan calls for it. Use the applicable project skills, and add other plugins only when the task needs them.

### A. Install and sign in to the developer tools

Install the [Codex Windows app](https://developers.openai.com/codex/app/windows), [Codex CLI](https://developers.openai.com/codex/cli), and [GitHub CLI](https://cli.github.com/). Sign in with your own accounts, then check:

```powershell
codex --version
gh auth login --hostname github.com --web
gh auth status
gh repo view yosoykyle/SIA-TALA
```

Skip login if already signed in as the invited user. Viewing this public repository does not prove write access; verify your assignment and permissions before implementation. An already-working approved GitHub integration can replace the CLI route.

### B. Set up Laravel Boost

**Install the package, then connect the assistant.** The README's `composer setup` already installs the locked `laravel/boost` and `laravel/mcp` packages. Confirm with:

```powershell
composer show laravel/boost
composer show laravel/mcp
```

If missing, run `composer install` in this clone. There is no separate Laravel MCP application to install, and this existing project does not need `composer require laravel/boost` again.

For first-time assistant setup, run this **interactively yourself** from the repository root:

```powershell
php artisan boost:install
```

Select guidelines, skills, and MCP configuration, then select only your coding assistant; deselect the other preselected assistants. Leave Sail, Laravel Cloud, and Nightwatch unselected unless your task requires them.

Boost reads the shared `.ai/skills` sources and installed packages, then generates your assistant's guidelines, skills, and MCP connection automatically. For Codex, this includes `.agents/skills` and `.codex/config.toml`. You do not need to create those folders manually.

Review `git diff` afterward. Setup may record your assistant selection in `boost.json`; keep personal setup choices out of shared commits and report unexpected guideline or skill changes.

For Codex, check `codex mcp list` locally and confirm the connection targets this clone. Open it as a trusted project, restart Codex, then ask it to call Boost's `application_info` and report the Laravel version. A successful response is the connection check. See [Boost installation](https://laravel.com/framework/docs/12.x/boost#installation) and [Codex MCP setup](https://developers.openai.com/codex/mcp).

<details>
<summary>Skill files and later updates</summary>

The repository shares Boost's [custom skill sources](https://laravel.com/framework/docs/12.x/boost#custom-skills) in `.ai/skills`. Boost generates `.agents/skills` for Codex, `.github/skills` for Copilot, `.claude/skills` for Claude Code, and `.cursor/skills` for Cursor. Machine-specific MCP settings remain local.

For an existing setup that only needs its connection repaired, `php artisan boost:install --mcp` preserves guidelines and skills. It does not install missing skills.

Maintain skills through Boost: use `boost:add-skill` for approved imports or its documented custom-source mechanism, then `php artisan boost:update` to regenerate selected assistants. Review source and generated changes together. Do not independently edit or manually synchronize the generated skill folders.

</details>

### C. Install and connect Serena

Install [uv](https://docs.astral.sh/uv/getting-started/installation/#winget), which manages Serena's Python environment:

```powershell
winget install --id astral-sh.uv --exact
```

Reopen PowerShell, then follow [Serena's installation guide](https://oraios.github.io/serena/02-usage/010_installation.html):

```powershell
uv tool install -p 3.13 serena-agent
serena --help
serena init
```

In `%USERPROFILE%\.serena\serena_config.yml`, update these keys without replacing the rest of the file:

```yaml
web_dashboard: true
web_dashboard_open_on_launch: false
gui_log_window: false
```

These [settings](https://oraios.github.io/serena/02-usage/060_dashboard.html#dashboard-opening-behaviour) keep the dashboard available without opening it automatically.

Register Serena using its [Codex context](https://oraios.github.io/serena/02-usage/030_clients.html#codex-cli-and-app), then index this clone. Skip the first command if its connection already works:

```powershell
codex mcp add serena -- serena start-mcp-server --context codex --open-web-dashboard false
serena project index
```

Keep an existing working launcher instead of installing a duplicate. Indexing may download language-server dependencies; follow any reported requirements and retry. Restart Codex, then ask it to read Serena's instructions, activate this clone by its absolute path, and find a code symbol. Verify the correct project and a successful lookup; see the [project workflow](https://oraios.github.io/serena/02-usage/040_workflow.html).

This shares the team's tool settings, not personal memories or chat history.

### D. Confirm readiness before coding

- App starts; build and tests pass; both database targets and migration status were checked.
- GitHub identity/access are correct; Boost responds; Serena activates and finds a symbol when needed.
- For UI acceptance, one working route is available: Codex Browser, Playwright, Chrome DevTools, or attributable manual verification.

Send the coordinator a short ready/missing report. Remove secrets from errors; never paste raw MCP configuration or unredacted server-list output. If unassigned, wait for an Issue and accepted plan.

## 3. Add integration credentials only when needed

Ask the owner for restricted development credentials through a private sharing channel. Keep mock integrations until the assigned task requires provider access.

| Supplied item | Put it here |
| --- | --- |
| PayMongo test keys and matching webhook secret | `.env`: `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SIG`; keep `PAYMONGO_LIVEMODE=false` |
| Solver service-account JSON | `storage/app/private/credentials/`; set `.env`'s `TALA_SCHEDULING_SOLVER_CREDENTIALS` to its absolute path |
| Google OCR JSON, when the task uses that client | Same directory; set `GOOGLE_APPLICATION_CREDENTIALS` to its absolute path |

Create the private directory if missing. Quote paths containing spaces, for example `TALA_SCHEDULING_SOLVER_CREDENTIALS="C:/Projects/SIA-TALA/storage/app/private/credentials/solver-dev.json"`; use your own path. Never commit keys/JSON, paste them into Issues, or place them under `public/`.

Credentials alone do not prove connectivity. The assigned task must verify permissions, driver, endpoint/audience or webhook settings; one JSON file may not suit both Google clients. Shared provider or webhook changes need separate authorization.

## 4. Start your assigned Issue

The coordinator assigns you through the Issue's **Assignees** field. Alerts follow your [GitHub notification settings](https://docs.github.com/en/subscriptions-and-notifications/concepts/about-notifications); they do not start Codex or authorize coding.

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

After readiness passes, follow the [cheat sheet](00_Project_Documents/TALA-Orchestration-Cheat-Sheet.md): `Plan #NN` plans read-only; authorized `Complete #NN` implements, verifies, and commits locally; `Publish #NN` publishes separately. Setup alone authorizes none of these actions.

## 5. Troubleshooting

<details>
<summary>Open if a setup or readiness check fails</summary>

| Finding | Action |
| --- | --- |
| A tool command is not found | Follow its installer's PATH instructions, then restart PowerShell and Codex |
| Boost is unavailable | Check PHP, this clone's Artisan path, project trust, and the connection in section 2B. User-level Codex connections live in `%USERPROFILE%\.codex\config.toml`; avoid duplicate or wrong-clone entries. Verify a tool response before Laravel changes |
| GitHub access is unavailable | Authenticate one approved GitHub route; do not copy another person's token |
| Browser tooling is unavailable for a UI Issue | Configure one accepted browser route or leave the affected criterion `Unverified` |
| Serena is unavailable or has no active project | Follow section 2C; activate this clone and check a symbol lookup. Report the gap; it blocks implementation only when the Issue or accepted plan requires Serena |
| Serena opens its dashboard unexpectedly | Check section 2C's user settings and any `--open-web-dashboard` launcher override, then restart the connection |
| A database has pending migrations or missing columns | Follow the README's maintenance steps for the verified target. Rebuild only the disposable `test_tala_db` after explicit approval; never use its rebuild command on `tala_db` |
| Required accounts, roles, or academic data are missing | Check README seeding, then request the Issue's missing fixtures/account setup. There are no default administrator credentials; do not copy a teammate's database |
| Wrong branch, behind `main`, or unexplained dirty files | Stop before implementation and reconcile Git state without discarding work |
| Prototype comparison evidence is needed | Use the tracked [Human-Centered Operations evidence pack](00_Project_Documents/design-evidence/human-centered-operations/README.md); it does not define production behavior |

If project-scoped Boost configuration still cannot load, Laravel also supports [manual registration](https://laravel.com/framework/docs/12.x/boost#manually-registering-the-mcp-server). Use one connection, correcting any existing entry first:

```powershell
codex mcp add laravel-boost -- php (Join-Path (Get-Location).Path 'artisan') boost:mcp
```

</details>

### Before parallel implementation

The [TALA CI workflow](.github/workflows/ci.yml) installs dependencies, builds assets, migrates a disposable MySQL database, and runs PHPUnit on GitHub-hosted Linux. Check [GitHub Actions](https://github.com/yosoykyle/SIA-TALA/actions/workflows/ci.yml); passing CI does not replace acceptance or browser evidence.

Solo work may continue through the approved direct-`main` path. Before a second implementation Issue becomes active concurrently, the repository must have verified pull-request CI and protection of `main` as required by the TALA Orchestrator Protocol. Planning and onboarding may occur before that gate; concurrent implementation may not.
