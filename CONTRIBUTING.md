# Contributing to TALA

Start with a local clone and let Codex help you finish setup. You can do this before TALA runs or you receive an Issue. Run commands in PowerShell from your clone's root; skip installation steps for tools already working.

This is setup guidance. [`AGENTS.md`](AGENTS.md), the [TALA Orchestrator Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md), canonical product documents, and the owning Issue govern the work.

## Start here: no Issue required

1. Accept the GitHub invitation, install [Git](https://git-scm.com/install/windows), and clone [TALA](https://github.com/yosoykyle/SIA-TALA).
2. Install and sign in to the [Codex Windows app](https://developers.openai.com/codex/app/windows), then open the cloned folder as a local project.
3. Send this ordinary message; it is not a new protocol command:

```text
Onboard me to TALA. I have no assigned Issue yet.

Read AGENTS.md, README.md, and CONTRIBUTING.md. Check my setup and guide
me through anything missing, one step at a time. Explain what is required
now and what can wait. Do not assume the app, databases, or MCPs work yet.

Start read-only and ask before making changes. After setup, introduce the
codebase and development workflow. Do not start implementation.
```

Codex should check software versions and PATH first, then the clone/Git state, local environment and both databases, coding tools, and any needed integrations. Use the steps below as the checklist; report each relevant check as ready, missing, or not yet checked, with a concrete next step. A missing prerequisite defers dependent checks; an unavailable MCP does not prevent read-only setup guidance.

Ask before installing software, changing configuration or databases, or making provider requests. You complete sign-ins and supply authorized credentials privately; keep secret values out of the chat. After approved setup, verify startup, build, and tests against the confirmed local targets. Finish with a short codebase/workflow tour and a ready/missing report; wait for assignment before creating a work branch or implementing a feature.

## 1. Install and verify the application

Follow the [README setup](README.md#local-setup), then its [developer verification](README.md#developer-verification):

- Create your own `.env` and use your own MySQL login.
- Use `tala_db` for browsing the app and `test_tala_db` for disposable automated tests. Check both schemas using the README; passing tests does not update `tala_db`.
- Confirm `composer dev` starts the app, the frontend builds, and `php artisan test --compact` passes.

Use the example environment's mock integrations initially. Keep credentials, machine-specific MCP settings, Serena indexes, and personal memories local; do not copy a teammate's whole `.env` or tool configuration.

## 2. Connect your coding tools

These tools help the coding assistant; TALA itself runs without them. Boost is required for AI-assisted Laravel/package work; connect it during onboarding once its prerequisites are installed. The initial read-only setup conversation can proceed while it is unavailable. Prepare Serena for code navigation; it is required only when the Issue or accepted plan calls for it. Use the applicable project skills, and add other plugins only when the task needs them.

### A. Install and sign in to the developer tools

Keep your working Codex app. The terminal-based MCP steps below use [Codex CLI](https://developers.openai.com/codex/cli); install it when reaching those steps, then check `codex --version`. Use [GitHub CLI](https://cli.github.com/) for repository access unless an approved GitHub integration already works:

```powershell
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

Basic onboarding uses mock payments, the local solver stub, and log email. Ask the owner for restricted development credentials through a private sharing channel only when the assigned task requires provider access.

<details>
<summary>Configure email, PayMongo, or the hosted solver</summary>

| Supplied item | Put it here |
| --- | --- |
| SMTP account and sender settings | `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; use the provider's TLS/scheme and port settings |
| PayMongo test keys and matching webhook secret | `.env`: `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SIG`; keep `PAYMONGO_LIVEMODE=false` |
| Solver URL, audience, and service-account JSON | Store JSON in `storage/app/private/credentials/`. Set `.env`'s `TALA_SCHEDULING_SOLVER_DRIVER=cloud_run`, `TALA_SCHEDULING_SOLVER_URL`, `TALA_SCHEDULING_SOLVER_AUDIENCE`, and `TALA_SCHEDULING_SOLVER_CREDENTIALS` to the supplied values and absolute JSON path |

Create the private directory if missing. Quote paths containing spaces, for example `TALA_SCHEDULING_SOLVER_CREDENTIALS="C:/Projects/SIA-TALA/storage/app/private/credentials/solver-dev.json"`; use your own path. Never commit keys/JSON, paste them into Issues, or place them under `public/`.

For **email**, keep `MAIL_MAILER=log` until real delivery is needed; messages appear in the application log instead of an inbox. Use an approved recipient for an explicitly authorized send. See [Laravel mail configuration](https://laravel.com/framework/docs/12.x/mail#configuration).

For **PayMongo**, also set `TALA_PAYMENT_GATEWAY_DRIVER=paymongo`. The owner must approve a reachable HTTPS test webhook ending in `/api/webhooks/paymongo`, its subscribed events (including `checkout_session.payment.paid` for hosted checkout), and its matching signing secret. A local endpoint needs an approved tunnel or hosted endpoint; never redirect another developer's shared webhook. Keep `QUEUE_CONNECTION=database` and the `composer dev` queue listener running for confirmation processing. See [PayMongo webhook setup](https://docs.paymongo.com/docs/creating-a-webhook-endpoint).

For the **hosted solver**, the supplied identity needs permission to invoke that service, and the audience must match its approved configuration. The PHP client obtains its token from the JSON file; calling the existing hosted service needs neither Python nor Google Cloud CLI. See [Cloud Run service authentication](https://docs.cloud.google.com/run/docs/authenticating/service-to-service). Local Python solver work needs its own environment and pinned dependencies from the [solver guide](cloud/scheduler-solver/README.md); Cloud administration/deployment uses separately authorized tooling and access, such as [Google Cloud CLI](https://docs.cloud.google.com/sdk/docs/install-sdk).

After approved `.env` changes, clear stale local configuration with `php artisan config:clear --no-interaction` and restart local processes. Credentials alone do not prove connectivity: perform only the task's authorized checks and report anything unverified. Provider requests, shared webhook changes, and Cloud changes need separate authorization.

</details>

Legacy OCR keys remain in `.env.example`, but the current application has no active OCR client consuming them. Leave them alone during onboarding; request OCR credentials only after an authorized task identifies a working client and its requirements.

## 4. Start your assigned Issue

The coordinator assigns you through the Issue's **Assignees** field. Alerts follow your [GitHub notification settings](https://docs.github.com/en/subscriptions-and-notifications/concepts/about-notifications); they do not start Codex or authorize coding.

Enable [GitHub Actions notifications](https://docs.github.com/en/subscriptions-and-notifications/how-tos/managing-github-actions-notifications) on your own GitHub account, preferably with **Only notify for failed workflows**. This personal setting cannot be configured repository-wide; required CI still blocks a failing pull request from merging when notifications are disabled.

A fresh Codex task is recommended when the implementation owner or Issue changes, but it is not mandatory. Use this once in the new or existing Issue-scoped task after the repository, dependencies, and assigned Issue are available:

```text
Re-anchor this TALA clone for assigned Issue #NN as its implementation owner.

Read AGENTS.md, CONTRIBUTING.md, the TALA Orchestrator Protocol, the owning
Issue body and relevant durable comments, any existing accepted plan or execution
handoff, and the minimum relevant canonical documents and implementation
surfaces. Verify the current branch, HEAD relationship to origin/main, clean or
attributable working state, assignment, dependencies, workspace and database
isolation, actual development and test database targets, migration status and
any observed schema mismatch for each, GitHub access, Laravel Boost, applicable
project skills, the browser-verification route required by the Issue, and any
material task-specific environment or tool prerequisite named by the handoff.
Treat Serena and other plugins as conditional unless the Issue or accepted plan
requires them.

Report what is ready, what is missing, the exact safe remedy, and whether
implementation may begin. Read-only: do not edit files, mutate GitHub, create a
branch, commit, publish, merge, deploy, or change credentials yet.
```

Follow the [cheat sheet](00_Project_Documents/TALA-Orchestration-Cheat-Sheet.md): use `Plan #NN` to plan read-only when no accepted plan exists. Coding needs readiness, an accepted plan/handoff, and authorized `Complete #NN`; for concurrent work, that command creates or switches to the Issue-specific branch, then implements, verifies, and commits locally. `Publish #NN` publishes separately. Setup alone authorizes none of these actions.

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
