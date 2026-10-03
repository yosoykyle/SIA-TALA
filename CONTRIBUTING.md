# Contributing to TALA

Start with a local clone and let your preferred coding assistant (such as Codex, Antigravity, Claude Code, or Cursor) help you finish setup. You can do this before TALA runs or you receive an Issue. Run commands in PowerShell from your clone's root; skip installation steps for tools already working.

This is setup guidance. [`AGENTS.md`](AGENTS.md), the [TALA Orchestrator Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md), canonical product documents, and the owning Issue govern the work.

## Start here: no Issue required

1. Accept the GitHub invitation, install [Git](https://git-scm.com/install/windows), and clone [TALA](https://github.com/yosoykyle/SIA-TALA).
2. Open the cloned repository in your preferred environment or AI coding assistant (e.g. the [Codex Windows app](https://developers.openai.com/codex/app/windows), Antigravity, VS Code, Cursor).
3. Send this initial onboarding prompt (or follow the checklist below manually):

```text
Onboard me to TALA. I have no assigned Issue yet.

Read AGENTS.md, README.md, and CONTRIBUTING.md. Check my setup and guide
me through anything missing, one step at a time. Explain what is required
now and what can wait. Do not assume the app, databases, or MCPs work yet.

Start read-only and ask before making changes. After setup, introduce the
codebase and development workflow. Do not start implementation.
```

Your assistant should check software versions and PATH first, then the clone/Git state, local environment and both databases, coding tools, and any needed integrations. Use the steps below as the checklist; report each relevant check as ready, missing, or not yet checked, with a concrete next step. A missing prerequisite defers dependent checks; an unavailable MCP does not prevent read-only setup guidance.

Ask before installing software, changing configuration or databases, or making provider requests. You complete sign-ins and supply authorized credentials privately; keep secret values out of the chat. After approved setup, verify startup, build, and tests against the confirmed local targets. Finish with a short codebase/workflow tour and a ready/missing report; wait for assignment before creating a work branch or implementing a feature.

## 1. Install and verify the application

Follow the [README setup](README.md#local-setup), then its [developer verification](README.md#developer-verification):

- Create your own `.env` and use your own MySQL login.
- Use `tala_db` for browsing the app and `test_tala_db` for disposable automated tests. Check both schemas using the README; passing tests does not update `tala_db`.
- Confirm `composer run dev` starts the app, the frontend builds, and `php artisan test --compact` passes.
- For browser qualification testing, start the dedicated qualification test server in a separate terminal:
  ```powershell
  php -S 127.0.0.1:8008 -t public
  ```

Use the example environment's mock integrations initially. Keep credentials, machine-specific MCP settings, Serena indexes, and personal memories local; do not copy a teammate's whole `.env` or tool configuration. For shared credential requests, contact `kylefbaluyot@iskolarngbayan.pup.edu.ph`.

### Developer Integration Runbooks

When configuring external integrations, consult the dedicated developer guides in [`00_Project_Documents/developer-guides/`](00_Project_Documents/developer-guides/):

* [**TALA Developer Workflow Playbook**](00_Project_Documents/developer-guides/tala-workflow-playbook.md): Task execution, workspace selection, publication follow-through, and recovery examples.
* [**ngrok Local Tunneling Guide**](00_Project_Documents/developer-guides/ngrok-setup.md): Exposing localhost for testing incoming webhooks and remote redirects.
* [**Gmail SMTP Setup & Testing**](00_Project_Documents/developer-guides/smtp-setup.md): App Password setup, `.env` mail variables, and terminal testing via Tinker.
* [**CP-SAT Cloud Run Solver Integration**](00_Project_Documents/developer-guides/cpsat-cloudrun-setup.md): Service Account key setup, Google Cloud CLI installation, and terminal `/health` verification.
* [**PayMongo Checkout & Webhook Integration**](00_Project_Documents/developer-guides/paymongo-setup.md): Hosted checkout setup, webhook event selection, signing secret retrieval, and ngrok tunneling.

## 2. Connect your coding tools

These tools help the coding assistant; TALA itself runs without them. Boost is required for AI-assisted Laravel/package work; connect it during onboarding once its prerequisites are installed. The initial read-only setup conversation can proceed while it is unavailable. Prepare Serena for code navigation; it is required only when the Issue or accepted plan calls for it. Use the applicable project skills, and add other plugins only when the task needs them.

### A. Install and sign in to the developer tools

The terminal-based MCP steps below use assistant-specific CLI or configuration tools (e.g. [Codex CLI](https://developers.openai.com/codex/cli), Claude Code CLI, or your editor's MCP manager). Use [GitHub CLI](https://cli.github.com/) for repository access unless an approved GitHub integration already works:

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

Boost reads the shared `.ai/skills` sources and installed packages, then generates your assistant's guidelines, skills, and MCP connection automatically. For Codex, this includes `.agents/skills` and `.codex/config.toml`; for Claude Code, `.claude/skills`; for Cursor, `.cursor/skills`. You do not need to create those folders manually.

Review `git diff` afterward. Setup may record your assistant selection in `boost.json`; keep personal setup choices out of shared commits and report unexpected guideline or skill changes.

Boost owns the generated `<laravel-boost-guidelines>` blocks in `AGENTS.md`, `CLAUDE.md`, and other selected agent files. TALA workflow governance belongs in `<TALA_ORCHESTRATOR_ROUTER>` outside that block and its linked protocol. Routine SDLC edits change only that router in `AGENTS.md`; leave generated blocks, skills, and personal tool configuration unchanged. A future approved package-guideline customization uses Boost's documented source/override mechanism and reviewed regeneration, not hand-edits to generated output.

Check your assistant's active MCP list and confirm the connection targets this clone. Open it as a trusted project, restart your assistant, then ask it to call Boost's `application_info` and report the Laravel version. A successful response is the connection check. See [Boost installation](https://laravel.com/framework/docs/12.x/boost#installation) and [Codex MCP setup](https://developers.openai.com/codex/mcp).

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

Register Serena with your assistant:
- **Codex**: `codex mcp add serena -- serena start-mcp-server --context codex --open-web-dashboard false`
- **Other assistants (Antigravity, Claude Code, Cursor)**: Register `serena start-mcp-server --open-web-dashboard false` in your client's MCP configuration.

Index this clone:

```powershell
serena project index
```

Keep an existing working launcher instead of installing a duplicate. Indexing may download language-server dependencies; follow any reported requirements and retry. Restart your assistant, then ask it to read Serena's instructions, activate this clone by its absolute path, and find a code symbol. Verify the correct project and a successful lookup; see the [project workflow](https://oraios.github.io/serena/02-usage/040_workflow.html).

This shares the team's tool settings, not personal memories or chat history.

### D. Confirm readiness before coding

Check the prerequisites needed by the assignment and reuse still-valid setup evidence:

- All work: accepted scope, relevant authority, correct workspace, attributable Git state, and preservation of unrelated edits.
- Documentation-only work: document access and consistency/diff checks; no app, database, migration, or browser setup.
- Code work: applicable runtime and package tools, affected tests, and verified database targets/isolation when those checks use a database.
- UI work: a ready serving app and one working browser or attributable manual route for the required rendered checks. Use the dedicated qualification server when applicable.
- External integrations: only the assigned task's approved access and sandbox/provider checks.

Report a blocking prerequisite with its safe remedy. Otherwise continue within the existing execution authorization; a readiness report is not another approval gate. Remove secrets from errors and tool output. Without an assigned task or clear direct execution request, remain read-only.

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

For **PayMongo**, also set `TALA_PAYMENT_GATEWAY_DRIVER=paymongo`. The owner must approve a reachable HTTPS test webhook ending in `/api/webhooks/paymongo`, its subscribed events (including `checkout_session.payment.paid` for hosted checkout), and its matching signing secret. A local endpoint needs an approved tunnel or hosted endpoint; never redirect another developer's shared webhook. Keep `QUEUE_CONNECTION=database` and the `composer run dev` queue listener running for confirmation processing. See [PayMongo webhook setup](https://docs.paymongo.com/docs/creating-a-webhook-endpoint).

For the **hosted solver**, the supplied identity needs permission to invoke that service, and the audience must match its approved configuration. The PHP client obtains its token from the JSON file; calling the existing hosted service needs neither Python nor Google Cloud CLI. See [Cloud Run service authentication](https://docs.cloud.google.com/run/docs/authenticating/service-to-service). Local Python solver work needs its own environment and pinned dependencies from the [solver guide](cloud/scheduler-solver/README.md); Cloud administration/deployment uses separately authorized tooling and access, such as [Google Cloud CLI](https://docs.cloud.google.com/sdk/docs/install-sdk).

After approved `.env` changes, clear stale local configuration with `php artisan config:clear --no-interaction` and restart local processes. Credentials alone do not prove connectivity: perform only the task's authorized checks and report anything unverified. Provider requests, shared webhook changes, and Cloud changes need separate authorization.

</details>

Legacy OCR keys remain in `.env.example`, but the current application has no active OCR client consuming them. Leave them alone during onboarding; request OCR credentials only after an authorized task identifies a working client and its requirements.

## 4. Starting Your Assigned Issue: The 5-Step Developer Quickstart

These are phases of one bounded assignment, not five required owner conversations. The [Orchestrator Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md) owns permissions; the [cheat sheet](00_Project_Documents/TALA-Orchestration-Cheat-Sheet.md) provides short requests and the [developer playbook](00_Project_Documents/developer-guides/tala-workflow-playbook.md) explains continuation, publication, and recovery.

### 1. Read the accepted contract; plan only what is missing

Read the owning Issue and relevant durable decisions or handoff. A sufficient accepted Issue already serves as the plan. An explicit owner request to execute that contract adopts it within the stated scope; unresolved material decisions still require resolution before affected implementation.

If the contract is missing or materially changed, use:

```text
Plan #NN. Resolve the missing scope, authority, material decisions, and verification
for this Issue. Reuse existing accepted decisions. Read-only; do not write code.
```

Assignment notifications alone grant no execution permission. For clear direct work without an Issue, the owner's explicit request supplies the bounded contract.

### 2. Check the workspace and task-specific prerequisites

Inspect the current workspace before setup:

```powershell
git status --short
git branch --show-current
git worktree list
```

Solo work uses the existing primary `main` checkout. Preserve attributable unrelated edits; do not reset, clean, or create a branch simply because an Issue exists.

For concurrent work, verify the protocol's prerequisites and explicitly authorize the required isolated checkout/branch and database arrangement. In an agreed separate checkout, an authorized branch setup can look like this:

```powershell
git fetch origin
git switch -c codex/issue-NN origin/main
```

`codex/issue-NN` is the Codex example; other environments use their agreed branch name. A branch alone does not isolate a shared checkout. Use the protocol's separate workspace for parallel execution.

Use section 2D's conditional readiness checks: documentation needs no database or app server; code checks need their actual runtime/test targets; rendered UI checks need a ready HTTP/browser route. On resumption, refresh volatile or invalidated premises rather than repeating onboarding.

### 3. Execute to the explicitly authorized endpoint

For local edits only:

```text
Implement #NN under the accepted contract. Make bounded edits, run applicable
verification, repair in-scope failures, and review the result. Preserve unrelated
work. No commit, push, PR, or unauthorized isolation setup.
```

`Complete #NN` can finish remaining bounded implementation and verification, then create one commit after every criterion is Verified. A separate Implement turn is unnecessary. If the owner wants completion and publication together, one explicit request may cover them:

```text
Complete and Publish #NN under the accepted contract. Finish remaining bounded
work, verify and review every criterion, create one bounded commit, and publish
by the protocol's current solo/concurrent path. For concurrent work, I authorize
necessary Issue-branch/workspace setup and the linked PR. Follow required CI on
the exact published revision; repair only in-scope CI failures and create/push
bounded corrective commits after re-verification. I authorize this Issue's
required status/evidence updates and solo closure after Verified acceptance and
successful CI. Preserve unrelated work. No merge, deployment, new dependencies,
or scope expansion.
```

Only use that broader request when those effects are intended. A read-only request stays read-only. Plain language is sufficient; optional XML formatting provides no compliance guarantee.

### 4. Verify and review the outcome

Maintain one criterion-level acceptance ledger. Add or update tests when existing coverage is insufficient. For code changes, use the affected tests; format PHP edits with Pint:

```powershell
php artisan test --compact --filter=YourTestName
vendor/bin/pint --dirty --format agent
```

These commands are examples for applicable code work, not a second mandatory test round. Reuse valid evidence; repeat checks invalidated by edits, environment changes, or findings. Documentation-only work uses authority consistency, links, intended-diff, and formatting checks. UI work also needs representative rendered checks.

The reviewer checks actual conformance and applicable failure/recovery paths, not just the executor's success report. A genuine capability gap stays Partial or Unverified and blocks completion.

### 5. Publish through the applicable route and verify CI

Complete selectively stages the exact accepted manifest and creates one bounded local commit. After publication is explicitly authorized and the preflight passes, manual CLI examples are:

**Solo publication from primary `main`:**

```powershell
git push origin main
```

**Concurrent publication from the isolated Issue branch:**

```powershell
git push -u origin codex/issue-NN
gh pr create --base main --title "Issue #NN: bounded outcome" --body "Closes #NN. Include acceptance and verification evidence here."
```

Push only the accepted commit range; inspect it first so unrelated pending or ahead work is excluded. Verify required CI on the exact published revision. A push or PR URL alone is not the end of publication verification.

`Closes #NN` links a concurrent PR to its Issue and closes it when merged into the default branch. Required CI and resolved review precede a separately authorized merge; agents never infer merge permission from Publish. Solo tracked closure requires successful published CI and current acceptance evidence. Deployment has its own gate.

An authorized CI correction stays with the same task, is reverified, and receives its own bounded corrective commit. If that effect was not authorized, request only the missing permission.

### Workflow Decision Flowchart

```text
Requested boundary?
  READ_ONLY --------------------------> Findings or draft only; no writes
  Authorized edits/completion
    |
    +-- Issue #NN? -------------------> Read its accepted contract
    |   Clear direct task? -----------> Use the owner's bounded request
    |   Missing material decision? ---> Resolve before affected implementation
    |
    +-- Concurrent implementation? ---> Authorized isolated workspace + Issue branch
    |                                   Publish: linked PR + required CI
    |                                   Merge: separate explicit authorization
    |
    +-- Solo? ------------------------> Existing primary main checkout
                                        Publish: accepted range + required CI

Execute only to the authorized endpoint; task type never grants extra permission.
```

### The Three Work Types in Plain English

| Work type | Workspace and delivery |
| --- | --- |
| Solo tracked work | Existing primary `main` checkout; authorized bounded completion and direct publication, followed by required CI and Verified acceptance before closure |
| Concurrent tracked work | Authorized isolated Issue branch/workspace; linked PR, required CI and review, then separately authorized merge |
| Clear Direct Work (Untracked) | Direct request supplies scope; existing primary checkout for solo work; no Issue or Project item; edits, commit, and publication follow the explicitly named effects |

A read-only investigation remains read-only in every work type. GitHub Project status is a view of tracked work, not an extra task record.

### Re-anchoring for an Assigned Issue

The coordinator assigns the accountable owner through the Issue's Assignees field. Alerts follow your [GitHub notification settings](https://docs.github.com/en/subscriptions-and-notifications/concepts/about-notifications); they do not authorize coding. Contributors may enable [GitHub Actions failure notifications](https://docs.github.com/en/subscriptions-and-notifications/how-tos/managing-github-actions-notifications) on their own account.

If readiness needs a separate diagnosis, use this read-only prompt. During an already authorized execution assignment, perform the same applicable checks without another owner approval round:

```text
Re-anchor this TALA workspace for Issue #NN. Read the router, applicable protocol
sections, owning Issue/accepted handoff, relevant authority, and existing evidence.
Check branch/base, assignment, dependencies, unrelated edits, and required isolation.
Prove actual database targets/migrations when code tests need them, and HTTP/browser
readiness when rendered verification needs it. Treat plugins as conditional unless
the task requires them. Report blockers and safe remedies. Read-only: no edits,
Git/GitHub writes, commit, push, merge, or deployment.
```

Use `Plan #NN` only for a missing or materially changed contract. Coding requires task-applicable readiness and explicit execution authorization; commit and publication need their corresponding effects. Setup alone authorizes none of them.

## 5. Troubleshooting & Branch Protection

<details>
<summary>Open if a setup or readiness check fails</summary>

| Finding | Action |
| --- | --- |
| A tool command is not found | Follow its installer's PATH instructions, then restart PowerShell and your assistant |
| Boost is unavailable | Check PHP, this clone's Artisan path, project trust, and the connection in section 2B. User-level connections (e.g. `%USERPROFILE%\.codex\config.toml`) should point to this clone. Verify a tool response before Laravel changes |
| GitHub access is unavailable | Authenticate one approved GitHub route; do not copy another person's token |
| Browser tooling is unavailable for a UI Issue | Ensure `php -S 127.0.0.1:8008 -t public` is running, configure one accepted browser route, or leave the affected criterion `Unverified` |
| Serena is unavailable or has no active project | Follow section 2C; activate this clone and check a symbol lookup. Report the gap; it blocks implementation only when the Issue or accepted plan requires Serena |
| Serena opens its dashboard unexpectedly | Check section 2C's user settings and any `--open-web-dashboard` launcher override, then restart the connection |
| A database has pending migrations or missing columns | Follow the README's maintenance steps for the verified target. Rebuild only the disposable `test_tala_db` after explicit approval; never use its rebuild command on `tala_db` |
| Required accounts, roles, or academic data are missing | Check README seeding, then request the Issue's missing fixtures/account setup. There are no default administrator credentials; do not copy a teammate's database |
| Wrong assigned workspace/base or overlapping edits of unclear ownership | Reconcile the affected state without discarding work. Unrelated attributable edits alone do not block a bounded task |
| Prototype comparison evidence is needed | Use the tracked [Human-Centered Operations evidence pack](00_Project_Documents/design-evidence/human-centered-operations/README.md); it does not define production behavior |

If project-scoped Boost configuration still cannot load, Laravel also supports [manual registration](https://laravel.com/framework/docs/12.x/boost#manually-registering-the-mcp-server). Use one connection, correcting any existing entry first:

```powershell
codex mcp add laravel-boost -- php (Join-Path (Get-Location).Path 'artisan') boost:mcp
```

</details>

### Branch Protection & CI Requirements

The [TALA CI workflow](.github/workflows/ci.yml) installs dependencies, builds assets, migrates a disposable MySQL database, and runs PHPUnit on GitHub-hosted Linux. Check [GitHub Actions](https://github.com/yosoykyle/SIA-TALA/actions/workflows/ci.yml); passing CI does not replace acceptance or browser evidence.

Verify live branch protection and required checks before concurrent work; this guide does not establish that remote settings are currently configured.

- **Parallel Work**: Required CI, resolved review, integrated dependencies, and sufficient currency with `origin/main` precede merge. Refresh the branch and invalidated evidence when its base changes.
- **Solo Work**: Retain the approved direct-`main` path after task-applicable local verification; required CI applies to the published commit.
- **Human Merge Gate**: Pull Requests require the Human Project Owner's explicit merge authorization.
