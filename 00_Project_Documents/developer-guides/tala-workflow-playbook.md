# TALA Developer Workflow Playbook: Scenario Walkthroughs & Visual Guide

This playbook provides concrete, visual, scenario-based walkthroughs for everyday development on TALA. Whether you are using an AI coding assistant (Antigravity, OpenAI Codex, Claude Code, Cursor) or executing Git commands directly in PowerShell, this guide demystifies the workflow from your first assigned issue to a merged pull request.

---

## Table of Contents

1. [Section 1: The Big Picture & Mental Model](#section-1-the-big-picture--mental-model)
2. [Section 2: Primary Focus — Getting an Issue (From Todo to Done)](#section-2-primary-focus--getting-an-issue-from-todo-to-done)
3. [Section 3: Secondary Focus — Creating an Issue (The Reporter)](#section-3-secondary-focus--creating-an-issue-the-reporter)
4. [Section 4: Team Concurrency & Branch Isolation](#section-4-team-concurrency--branch-isolation)
5. [Section 5: The Automated CI Judge & Error Recovery](#section-5-the-automated-ci-judge--error-recovery)
6. [Section 6: Master Protocol Reference](#section-6-master-protocol-reference)

---

## Section 1: The Big Picture & Mental Model

### The Full Lifecycle in 30 Seconds

Every task in TALA follows a strict, consequential pipeline. No code ever lands on the default branch (`main`) without passing through isolated development, local verification, cloud CI checks, and a human review gate:

```text
 ┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
 │   GitHub Task   │       │ Isolated Branch │       │   AI Planning   │
 │   in [ Todo ]   │ ────> │ feat/issue-NN   │ ────> │  (READ_ONLY)    │
 └─────────────────┘       └─────────────────┘       └────────┬────────┘
                                                              │
 ┌─────────────────┐       ┌─────────────────┐                │
 │ 1 Local Commit  │ <──── │ Pint & Tests    │ <──────────────┘
 │ on your branch  │       │ (LOCAL_EXEC)    │
 └────────┬────────┘       └─────────────────┘
          │
          ▼
 ┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
 │ Publish PR with │       │ GitHub Actions  │       │ Human Lead Gate │
 │  "Closes #NN"   │ ────> │ Automated CI    │ ────> │ Review & Merge  │
 └─────────────────┘       └────────┬────────┘       └────────┬────────┘
                                    │                         │
                                    ▼                         ▼
                           ┌─────────────────┐       ┌─────────────────┐
                           │   Green Pass    │       │ Issue Closed to │
                           │  (Red = Fix)    │       │    [ Done ]     │
                           └─────────────────┘       └─────────────────┘
```

### The Three Work Types

| Work Type | When to Use | Branch Strategy | Submission & Merge |
|---|---|---|---|
| **Work Type 1: Solo Work** | Single active implementation issue tracked; zero concurrent branches or PRs. | Implemented directly on `main` in your primary checkout. | Direct push to `origin/main` after fresh local preflight; auto-closed. |
| **Work Type 2: Parallel Work (Team Default)** | Multiple developers active or working on parallel features/fixes. | Isolated branch (`feat/issue-NN` or `fix/issue-NN`). | PR with `Closes #NN`; merged **strictly by the Project Lead** after CI passes. |
| **Work Type 3: Untracked Work** | Ad-hoc internal docs tweaks, prompt tuning, or read-only investigation. | Handled directly on `main` or in session. | 1 clean commit; no GitHub Project board overhead. |

### The Three Permission Boundaries

Every interaction with your AI assistant operates within one of three explicit permission boundaries:

1. **`READ_ONLY`** (`Plan #NN`, `diagnose`, `reanchor`): The AI can read files, inspect Git history, and run read-only database queries. It **cannot** modify code, create files, or make Git commits.
2. **`LOCAL_EXECUTION`** (`implement`, `fix`, `change`, `proceed`): The AI can make bounded edits to in-scope files, run migrations on disposable `test_tala_db`, execute tests, and format with Pint. It **cannot** create git commits, push, open PRs, or touch `origin`.
3. **`COMPLETION_AND_PUBLISH`** (`Complete #NN`, `Publish #NN`, `commit`): The AI creates exactly ONE bounded local commit once verification is complete and all criteria are Verified (`Complete #NN`); and in Publish mode (`Publish #NN`), pushes the branch to `origin` and creates the Pull Request linked to the issue.

---

## Section 2: Primary Focus — Getting an Issue (From Todo to Done)

This is the primary day-to-day journey for every contributing developer. Follow these 7 consecutive steps from the moment you are assigned a card to the moment it is merged and closed.

### Visual Flowchart: The 7 Consequential Steps

```text
 [STEP 1: DISCOVER]
 ┌───────────────────────────────────────────────────────────────┐
 │ Project Board: See assigned card in "Todo".                   │
 │ Action: Read the Issue Outcome, Scope, and Criteria.          │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 2: ISOLATE]               ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Terminal: Checkout fresh main and create feature branch.      │
 │ Command:  git checkout main && git pull                       │
 │           git checkout -b feat/issue-48                       │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 3: PLAN]                  ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ AI Chat: Prompt "Plan #48" (READ_ONLY).                       │
 │ AI Action: Reads specs, drafts plan, touches ZERO files.      │
 │ Dev Action: Review and accept the plan.                       │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 4: COMPLETE]              ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ AI Chat: Prompt "implement" (LOCAL_EXEC) -> edits/Pint/tests. │
 │ AI Chat: Prompt "Complete #48" (COMPLETION_AND_PUBLISH)       │
 │ Action:  All criteria Verified -> creates 1 local commit.     │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 5: PUBLISH]               ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ AI Chat or Terminal: Prompt "Publish #48".                    │
 │ Action: Push branch & create PR with "Closes #48".            │
 │ Note:   AI session STOPS immediately (no token burning).      │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 6: CI & TWO PATHS]        ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Cloud CI (GitHub Actions) runs automatically on the PR:       │
 │                                                               │
 │   • GREEN PATH: All checks pass!                              │
 │                 Ping Lead (@yosoykyle) for review & merge.    │
 │                                                               │
 │   • RED PATH:   Checks failed! (DO NOT OPEN A NEW ISSUE!)     │
 │                 Stay on same branch -> AI "diagnose #48"      │
 │                 -> push fix to same branch -> CI re-runs.     │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
 [STEP 7: MERGE & CLEANUP]       ▼ (Green Path)
 ┌───────────────────────────────────────────────────────────────┐
 │ Lead reviews diff and clicks "Merge Pull Request".            │
 │ GitHub sees "Closes #48" -> Auto-closes issue to [ Done ].    │
 │ Dev cleans up local branch:                                   │
 │   git checkout main && git pull origin main                   │
 │   git branch -d feat/issue-48                                 │
 └───────────────────────────────────────────────────────────────┘
```

---

### Step-by-Step Case Study: Developer "Bob" Working on Issue #48

#### Step 1: Discover Card in `Todo` & Read Contract
Bob opens the [TALA Development Project Board](https://github.com/users/yosoykyle/projects) and sees card `#48` assigned to him in `Todo`:
* **Title:** `fix(curriculum): enforce prerequisite check before subject enrollment`
* **Outcome:** Prevent students from enrolling in subjects whose prerequisites have not been passed.
* **Acceptance Criteria:** Bounded logic in enrollment service, dedicated test in `Feature/EnrollmentTest`, Pint formatting.

#### Step 2: Branch Off Fresh `main`
Bob opens PowerShell at the repository root and creates an isolated branch:
```powershell
git checkout main
git pull origin main
git checkout -b feat/issue-48
```
> **Rule:** Never write code on `main` when working in a team. Branch protection will reject any attempt to push directly to `origin/main`.

#### Step 3: Phase 1 AI Pairing — `Plan #48` (`READ_ONLY`)
Bob pastes this prompt into his AI coding assistant:
```text
Plan #48. Read the issue contract, relevant curriculum services, and tests.
Show me an implementation plan before touching any code.
```

**What the AI does behind the scenes:**
1. Calls read-only tools to examine `app/Services/EnrollmentService.php` and `tests/Feature/EnrollmentTest.php`.
2. Produces a concise implementation plan outlining the exact files to modify.
3. Leaves all source files completely untouched.

Bob reads the plan. It looks accurate and bounded.

#### Step 4: Phase 2 AI Pairing — `Complete #48` (`LOCAL_EXECUTION`)
Bob instructs the AI to execute:
```text
Complete #48 on branch feat/issue-48. Make bounded edits, verify with tests
against test_tala_db, format with Pint, and create exactly 1 local commit.
```

**What the AI does behind the scenes:**
1. Modifies `app/Services/EnrollmentService.php` to add the prerequisite validation check.
2. Adds a regression test to `tests/Feature/EnrollmentTest.php`.
3. Runs the test suite: `php artisan test --filter=EnrollmentTest`.
4. Formats code with Pint: `vendor/bin/pint --dirty --format agent`.
5. Creates a single, clean Git commit on `feat/issue-48`:
   ```text
   fix(curriculum): enforce prerequisite check before subject enrollment (#48)
   ```

#### Step 5: Phase 3 AI Pairing — `Publish #48` (`COMPLETION_AND_PUBLISH`)
Bob tells the AI to publish (or runs it manually in terminal):
```text
Publish #48. Push branch feat/issue-48 and open a Pull Request.
```
*(Manual terminal equivalent)*:
```powershell
git push -u origin feat/issue-48
gh pr create --title "fix(curriculum): enforce prerequisite check before subject enrollment" --body "Closes #48"
```

> [!IMPORTANT]
> **What the AI Agent Does Next: IT STOPS!**  
> The moment the PR URL is generated, the AI's job is complete. It outputs the link to the PR and terminates its turn. It does **not** stay awake in the background, spin poll loops, or burn API tokens waiting for GitHub Actions in the cloud.

#### Step 6: The PR Endgame — CI & The Two Paths (Green vs. Red)
Once the PR is opened on GitHub, the automated cloud CI runner (`.github/workflows/ci.yml`) immediately begins executing tests on a fresh Linux container.

Bob clicks the PR link and observes GitHub Actions running. There are two possible outcomes:

##### Path A: The Green Path (All checks pass)
1. All GitHub Action checks display green checkmarks (`Application verification / Passed`).
2. Bob pings Kyle (`@yosoykyle`) on team chat:
   > *"Hey Kyle, PR for Issue #48 is up and CI is green! Ready for review: [PR Link]"*
3. Kyle reviews the code diff, confirms verification, and clicks **Merge Pull Request**.
4. Proceed directly to **Step 7**.

##### Path B: The Red Path (Checks fail)
1. GitHub Actions fails with a red `X` (for example, a test assertion failed or a database migration timed out).
2. **THE GOLDEN RULE: DO NOT CREATE A NEW GITHUB ISSUE!**
   A failed PR is not a new task; it is an in-progress verification fix on your current task.
3. Bob stays on the **SAME branch** (`feat/issue-48`).
4. Bob asks his AI to diagnose:
   ```text
   Diagnose #48. GitHub Actions CI failed with error: 'Call to undefined method Prerequisite::check()'.
   Find the cause and fix it on branch feat/issue-48.
   ```
5. The AI fixes the method call, runs `php artisan test`, and creates a fix commit.
6. Bob pushes the fix to the **same branch**:
   ```powershell
   git push origin feat/issue-48
   ```
7. GitHub automatically detects the new commit on the PR and re-triggers CI! Once CI turns Green, proceed to Step 7.

#### Step 7: The Human Lead Merge Gate & Automatic Closure
1. **The Merge:** Only the Project Lead (`@yosoykyle`) merges PRs into `main`. Collaborators never merge their own PRs.
2. **The Auto-Closure:** Because Bob's PR contained `Closes #48`, the exact millisecond Kyle merges the PR into `main`, GitHub natively:
   - Closes Issue `#48`.
   - Transitions the card on the TALA Project Board from `In Progress` to `Done`.
3. **Local Cleanup:** Bob switches back to `main`, pulls the newly merged code, and deletes his local feature branch:
   ```powershell
   git checkout main
   git pull origin main
   git branch -d feat/issue-48
   ```
Bob's task is 100% complete!

---

## Section 3: Secondary Focus — Creating an Issue (The Reporter)

When you discover a bug, an unhandled edge case, or a missing requirement, you need to record it as a tracked GitHub Issue.

### Visual Flowchart: Issue Reporting Decision Tree

```text
                      ┌────────────────────────────┐
                      │    You spot an issue or    │
                      │    missing functionality   │
                      └─────────────┬──────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
            Route A (Recommended)           Route B (Manual)
          AI-Assisted Formulation         GitHub task.md Template
                    │                               │
                    ▼                               ▼
     ┌────────────────────────────┐  ┌────────────────────────────┐
     │ Ask AI: "Diagnose this     │  │ Open GitHub -> New Issue   │
     │ error and draft a complete │  │ -> Choose "Task & Feature" │
     │ Issue contract."           │  │ Fill in Outcome, Scope, &  │
     │                            │  │ Acceptance Criteria.       │
     └──────────────┬─────────────┘  └──────────────┬─────────────┘
                    │                               │
                    └───────────────┬───────────────┘
                                    │
                                    ▼
     ┌────────────────────────────────────────────────────────────┐
     │ Is this owned by an active Academic Coordination Epic?     │
     │                                                            │
     │  • YES: Coordination-Derived (Linked to active Epic)       │
     │  • NO:  Standalone Work (Parentless, add Why Standalone)   │
     └──────────────────────────────┬─────────────────────────────┘
                                    │
                                    ▼
     ┌────────────────────────────────────────────────────────────┐
     │ Issue is created with label "implementation".              │
     │ GitHub Project automation moves it directly to [ Todo ].   │
     └────────────────────────────────────────────────────────────┘
```

---

### Route A: AI-Assisted Issue Formulation (Recommended)
You don't need to manually format markdown tables or remember XML tags. Let your AI draft the issue for you:

**Prompt to AI:**
```text
I noticed that when a student views their schedule, overlapping classes are
not flagged with a visual warning badge.
Diagnose this gap and draft a complete GitHub Issue contract for it.
Include Outcome, Scope, Acceptance Criteria, and Verification approach.
Do not modify any files.
```

**Result from AI:** The AI will produce a clean, ready-to-use issue draft matching `.github/ISSUE_TEMPLATE/task.md`. You review it, verify the scope is realistic, and ask the AI (or use `gh issue create`) to submit it to GitHub.

---

### Route B: Manual Creation via GitHub Template
If you prefer creating the issue manually in the browser:
1. Go to `https://github.com/yosoykyle/SIA-TALA/issues/new/choose`.
2. Click **Get started** on **Task & Feature Request** (`task.md`).
3. Fill in the template fields:
   * **Outcome:** What will exist when this is done? (1-2 sentences).
   * **Scope:** What files or areas will be touched?
   * **Acceptance Criteria:** Checkboxes (`- [ ]`) defining what must be proven.
   * **Verification:** Which test command or browser scenario proves it works?

---

### Demystifying "Standalone Work": When is an Issue Standalone?

Developers are often confused about whether an issue should be "Standalone" or "Coordination-Derived":

* **Coordination-Derived:** Work that delivers core student, registrar, or faculty academic journeys owned by an active umbrella Epic (e.g. Slices 1–7 of the Human-Centered Academic Roadmap). These issues have an active Coordination Issue as their parent.
* **Standalone:** Work that is **parentless**. This is used for:
  1. Internal developer tooling, DX, CI workflows, and documentation (e.g. Issues #42, #46, #47).
  2. Targeted bug fixes or post-milestone refinements that do not belong to an active coordinated epic (e.g. Issue #43).

**The Golden Rule:** If no active coordination epic owns the task, simply add a two-sentence `## Why standalone` section explaining why, and create it parentless.

---

## Section 4: Team Concurrency & Branch Isolation

When multiple developers are coding on TALA simultaneously, strict branch isolation prevents collisions and protects repository integrity.

### Visual Diagram: Parallel Development without Collisions

```text
 origin/main ───────●──────────────────●──────────────●─────────> (Always Stable)
                     \                /              /
 feat/issue-48        ●──────●───────● (PR #49)     /
 (Developer Bob)      Fix prerequisite logic        /
                                                   /
 feat/issue-50 ──────────────────────────●────────● (PR #51)
 (Developer Alice)                       Add PDF transcript export
```

### Why No One Codes on `main` in Team Mode
The repository enforces strict **Branch Protection** on `origin/main`:
* Direct pushes to `origin/main` by collaborators are rejected by GitHub.
* Pull Requests require passing CI before they can be merged.
* Branch currency is enforced (`required_status_checks.strict: true`). If `main` moves ahead while your PR is open, your PR must pull or merge `main` and re-verify before it can be merged.

### Standard Branch Naming Conventions
Always name your feature branch using the issue number:
* `feat/issue-NN` (New features or enhancements, e.g. `feat/issue-48`)
* `fix/issue-NN` (Bug fixes, e.g. `fix/issue-49`)
* `docs/issue-NN` (Documentation or guide updates, e.g. `docs/issue-47`)

### Git Worktrees: An Optional Power Tool for Concurrency
If you need to switch to an urgent bugfix without stashing or discarding your current in-progress work, use Git worktrees:
```powershell
# Create an isolated directory for Issue 49 alongside your project
git worktree add ../tala-issue-49 -b fix/issue-49
```
This lets you have two branches open in separate VS Code / IDE windows simultaneously, each with their own isolated Git working directory!

---

## Section 5: The Automated CI Judge & Error Recovery

### What is Continuous Integration (CI)?
CI is not an abstract concept; it is an automated GitHub Actions workflow defined in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). 

Every time a Pull Request is opened or updated, GitHub automatically spins up a clean Ubuntu virtual machine and runs:
1. `composer validate --strict`: Ensures `composer.json` and `composer.lock` are valid.
2. `composer install`: Installs exact locked PHP dependencies.
3. `npm ci && npm run build`: Builds Vite frontend assets from source.
4. `php artisan migrate --force`: Runs all database migrations against a fresh MySQL 8 container (`test_tala_db`).
5. `php artisan test --compact`: Executes the entire PHPUnit automated test suite.

```text
 ┌───────────────────────────────────────────────────────────────┐
 │                   GitHub Actions CI Pipeline                  │
 ├──────────────┬──────────────┬──────────────┬──────────────────┤
 │ Dependencies │ Build Assets │  Migrate DB  │    Run Tests     │
 │  (Composer)  │   (Vite)     │  (MySQL 8.0) │ (php artisan test│
 │      ●       │      ●       │      ●       │        ●         │
 └──────┬───────┴──────┬───────┴──────┬───────┴────────┬─────────┘
        ▼              ▼              ▼                ▼
   [ Passed ]     [ Passed ]     [ Passed ]       [ Passed ] ===> ALL GREEN!
```

### Why "Works on My Machine" is Not Enough
Your local machine might have cached node modules, uncommitted files, or a dirty database. The CI workflow starts from a 100% blank slate. If CI fails, it has detected a real discrepancy that must be resolved before code touches `main`.

### How to Recover from a Red CI (Step-by-Step)

```text
 ┌───────────────────────────────────────────────────────────────┐
 │ CI FAILS (Red X on PR)                                        │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 1: Click "Details" next to the failed check on GitHub.   │
 │         Identify the failing step (e.g., PHPUnit or Migrate). │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 2: In terminal or AI chat, run the "diagnose" prompt:    │
 │         "Diagnose #NN: CI failed on test X with error Y."     │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 3: AI pinpoints the root cause and applies a fix.        │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 4: Run tests locally to verify the fix:                  │
 │         php artisan test --compact --filter=FailingTestName   │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 5: Commit and push to the SAME branch:                   │
 │         git add -u                                            │
 │         git commit -m "fix(test): resolve CI failure (#NN)"   │
 │         git push origin feat/issue-NN                         │
 └───────────────────────────────┬───────────────────────────────┘
                                 │
                                 ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Step 6: GitHub automatically re-runs CI on the PR.            │
 │         Once Green, ping the Lead for merge!                  │
 └───────────────────────────────────────────────────────────────┘
```

---

## Section 6: Master Protocol Reference

The table below maps the 11 formal commands from the [TALA Orchestrator Protocol](../TALA-Orchestrator-Protocol.md) to the practical actions you perform daily:

| Formal Protocol Command | Boundary | What You Say to Your AI / Do in Terminal | What Happens Behind the Scenes |
|---|---|---|---|
| `derive` | `READ_ONLY` | `"Derive next issue from active coordination"` | AI inspects roadmap, drafts next dependency-ready slice. |
| `derive_standalone` | `READ_ONLY` | `"Draft standalone issue for [tooling/docs]"` | AI drafts parentless task contract with standalone rationale. |
| `create_issue` | `LOCAL_EXEC` | `"Create approved issue on GitHub"` | AI/dev creates issue; GitHub automation moves card to `Todo`. |
| `plan` (`Plan #NN`) | `READ_ONLY` | `"Plan #NN. Show plan before writing code."` | AI inspects code, specs, and tests; produces read-only plan. |
| `review` | `READ_ONLY` | `"Review proposed plan for #NN"` | Optional second-pass audit of architecture or migrations. |
| `complete` (`Complete #NN`) | `COMPLETION_AND_PUBLISH` | `"Complete #NN on branch feat/issue-NN"` | Verifies all criteria Verified, creates 1 local commit. |
| `publish` (`Publish #NN`) | `PUBLISH` | `"Publish #NN"` or `git push && gh pr create` | Branch pushed to origin; PR opened with `Closes #NN`; AI stops. |
| `diagnose` | `READ_ONLY` | `"Diagnose #NN: CI failed with error X"` | AI inspects CI logs and pinpoints root cause without edits. |
| `reanchor` | `CURRENT` | `"Re-anchor session for Issue #NN"` | Restores context after chat compaction or resumption. |
| `derive_batch` | `READ_ONLY` | `"Derive parallel batch for 2 developers"` | Partitions independent feature slices for concurrent work. |
| `audit` | `READ_ONLY` | `"Audit coordination cycle #NN"` | Final end-to-end qualification check before closing an Epic. |

---

## Summary Checklist for Every Developer

When working on any TALA task, remember the **Golden Rules**:
1. 🛑 **Never code on `main`** — Always create `feat/issue-NN`.
2. 📝 **Always plan first** — `Plan #NN` is read-only and catches mistakes before they happen.
3. 🧪 **Always verify before committing** — For code changes, run affected tests against `test_tala_db` and required formatting. For documentation-only changes, check authority consistency, the intended diff, and formatting. Required GitHub CI still applies after publication.
4. 🔗 **Always include `Closes #NN`** — Links your PR to your issue for automatic closure upon merge.
5. 🛑 **The AI stops at Publish** — It does not sit waiting for cloud CI.
6. 🚨 **Never open a new issue for a broken PR** — Fix it right on the same branch and re-push.
7. 👑 **The Lead merges into `main`** — Once merged, GitHub automatically marks your card `Done`!
