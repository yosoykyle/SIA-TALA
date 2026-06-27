# TALA Rebaseline Rescue Plan - 2026-06-27

Working status: active rescue controller until superseded.

This document converts the new PRD module baseline into a controlled implementation recovery plan. It is not a full restart. Existing code is treated as candidate implementation: keep what matches the new PRD, reshape what is close, and remove only what clearly conflicts.

## Authority

1. `00_Project_Documents/prd_modules/`
2. `00_Project_Documents/architecture_specification.md`, after stale-architecture audit
3. Current Laravel code reality
4. Focused tests and manual UAT evidence

Old SDD maps, local checklists, deleted `docs/prd_modules/*`, and historical rescue documents are not active sprint instructions unless copied into this file or a later approved issue.

## Rescue Goal

Build the smallest coherent College SIS foundation that proves:

1. Identity, account lifecycle, and role-scoped workspaces.
2. Applicant intake to student handover.
3. Academic setup and curriculum readiness.
4. Enrollment gates, finance evidence, scheduling, COR, grades, Student Hub, reports, and audit in SIS lifecycle order.

The capstone feature focus remains timetable-integrated academic lifecycle administration with CP-SAT scheduling connected to curriculum, resources, enrollment, COR, and Student Hub visibility.

## Current Code Reality Snapshot

Observed on 2026-06-27:

- Active PRD modules exist under `00_Project_Documents/prd_modules/`.
- Old `docs/prd_modules/*` and old `.github/skills/*` files are deleted in the worktree.
- Laravel code still contains substantial implementation candidates: Filament providers, applicant intake, checklist items, document upload review, academic setup, scheduling, finance, PayMongo, COR, grades, holds, StudentDashboardService, and tests.
- `app/Providers/Filament/AdminPanelProvider.php`, `ApplicantPanelProvider.php`, and `StudentPanelProvider.php` exist.
- `app/Filament/Applicant/*` and `app/Filament/Student/*` currently have no resources/pages beyond provider discovery targets.
- `routes/web.php` currently exposes only `/`.
- `RoleAwareLoginResponse` redirects staff to `/admin`, students to `/student`, and applicants to `/applicant`.
- `User::canAccessPanel()` requires `status = active` and verified email for all panels, including applicant and student.
- Flat checklist implementation exists through `checklist_items`, `ChecklistItem`, `ApplicantIntake::checklistItems()`, and `StudentProfile::checklistItems()`.
- Legacy applicant-document tracking tables are dropped by migration, but old migrations and some compatibility services still exist as history or transitional code.

## PRD-To-Code Rebaseline Matrix: Modules 01-04

| Module | New PRD Baseline | Current Code Evidence | Alignment | Rescue Decision | Next Work |
| --- | --- | --- | --- | --- | --- |
| 01 Product Intent & Architecture | TALA is a College-focused SIS with CP-SAT scheduling, PayMongo, email, source-derived outputs, and audit. Office-handled workflows remain outside TALA but their result records may be recorded. | Code has CP-SAT dispatch classes, PayMongo webhook/payment commands, mail command, COR/finance/grades/scheduling services. Architecture spec still appears to mention Redis/Horizon and older infrastructure language. | Partial. Product intent and many integrations exist; architecture document may be stale against current cleanup. | Keep monolithic Laravel architecture. Audit architecture spec before relying on it. Do not rebuild from old SDD. | S0A Architecture Sanity Pass: align architecture doc with actual dependencies, queues, panels, and active integrations. |
| 02 Identity, Access, and Workspaces | Canonical roles: applicant, student, registrar, accounting, faculty, academic-head, system-super-admin. Fortify auth, Spatie permissions, role-scoped applicant/student/staff workspaces. | `User` implements `FilamentUser`; three Filament panels exist: `admin`, `applicant`, `student`. Admin panel discovers all `app/Filament/Resources`. Applicant/student panel folders are empty. `canAccessPanel()` blocks non-active users for all panels. | Partial. Panel skeleton matches PRD direction, but applicant access status semantics are unclear and panel resources are not implemented. | Keep multi-panel direction unless later simplified. First fix access semantics and navigation boundaries before adding UI. | S1 Workspace Gate: verify Fortify routes, applicant registration path, panel access, login redirects, seeded roles, and direct URL denial. |
| 03 Admissions & Student Handover | Simplified flat checklist: one upfront identity upload, checklist items by owner, blocking levels, physical/digital/metadata evidence, handover creates/reuses official student profile. | `ApplicantIntake` has `identity_document_url`; `ChecklistItem` polymorphic model and migration exist; legacy `applicant_document_requirements` and `retention_document_undertakings` are dropped. Applicant intake service initializes checklist items from requirement resolution. Document review service still bridges document uploads to checklist items. | Good direction, but transitional complexity remains. Handover and duplicate resolution need verification against simplified PRD. | Keep flat checklist. Remove active dependency on legacy tracking models. Treat document uploads as evidence attached to checklist items, not as the checklist itself. | S2 Admissions/Handover Trace: test applicant account, identity evidence upload, checklist generation, staff review, handover blocking, and student profile creation/reuse. |
| 04 Academic Setup | Institution-wide terms, configurable calendar dates, modality separated from payment, course catalog, curriculum upload/encoding, same-screen scheduling fields, recorded-approved/active curriculum lifecycle. | `Term`, `AcademicYear`, `Subject`, `Curriculum`, `CurriculumSubject`, delivery patterns, sections, rooms, readiness scopes, and scheduling fields exist. `Curriculum` currently has `is_active` and `activated_at`, but not an explicit state enum for `Draft`, `Recorded Approved`, `Active`, `Superseded`, `Archived`. Subject model has limited fields versus PRD course catalog. | Partial. Scheduling readiness fields exist; curriculum lifecycle and course catalog may be under-modeled. | Keep academic setup as next foundational backend slice. Avoid UI polish until data model matches PRD essentials. | S3 Academic Setup Hardening: audit curriculum states, subject fields, term date fields, modality enum naming, and readiness checks. |

## Clarification Queue

Use a grilling pass before implementation if these cannot be resolved from PRD/code:

1. Applicant account status: should applicant users with `pending`, `action_required`, `for_evaluation`, or `approved` status access the Applicant panel, or should user `status` remain `active` while `ApplicantIntake.status` carries applicant lifecycle?
2. Panel strategy: keep three panels (`/applicant`, `/student`, `/admin`) or collapse applicant/student into one `/portal` panel for rescue speed?
3. Curriculum lifecycle: does v1 need explicit `Draft`, `Recorded Approved`, `Active`, `Superseded`, `Archived` states in schema, or can `is_active` plus audit evidence pass for now?
4. Course catalog depth: which PRD course fields are required for UAT versus safe to defer if CP-SAT readiness is still protected?
5. Student Hub first slice: should Student Hub start as Filament student panel with read-only dashboard/COR/schedule/grades/finance, or should the public landing remain separate and student records wait until after handover is proven?
6. Queue/cache deployment baseline: should v1 stay on Laravel's current database queue/cache baseline through rescue, or should Redis/Horizon be approved as a production dependency after workload evidence justifies it?

## New Rescue Sprint Order

### S0A Architecture Sanity Pass

Goal: prevent stale architecture from steering code.

Tasks:

1. Compare `architecture_specification.md` against `composer.json`, `package.json`, `config`, providers, routes, jobs, and current PRD modules.
2. Mark stale claims such as removed dependencies, old queue assumptions, old panel assumptions, or old Student Hub routing.
3. Patch architecture only where it conflicts with current baseline.
4. Run `git diff --check`.

Exit criteria:

- Architecture no longer contradicts active PRD modules or installed dependencies.
- Any unresolved architecture decision is listed in this plan.

### S1 Workspace Gate

Goal: make role-scoped access coherent before building module UI.

Tasks:

1. Decide applicant user-status semantics.
2. Verify panel provider registration and expected route names.
3. Fix `RoleAwareLoginResponse` only after panel access semantics are clear.
4. Add or update focused tests for admin, applicant, student, wrong-role, inactive, and unverified access.
5. Keep resources minimal; do not build dashboards yet.

Exit criteria:

- Staff can reach only staff workspace.
- Applicants can reach only applicant workspace during applicant lifecycle.
- Students can reach only Student Hub after handover/account activation.
- Wrong role and inactive users fail safely.

### S2 Admissions/Handover Trace

Goal: prove the simplified flat checklist path.

Tasks:

1. Verify applicant self-registration or Registrar-created applicant entry path.
2. Verify one required identity evidence field.
3. Verify checklist item generation and blocking levels.
4. Verify physical/digital/metadata evidence status updates.
5. Verify handover creates/reuses one student profile and student number.
6. Verify unresolved `blocks_handover` checklist items block handover.

Exit criteria:

- One happy-path applicant can become a student.
- One missing-blocking-item case fails safely.
- Student profile and applicant evidence remain linked.

### S3 Academic Setup Hardening

Goal: make curriculum and term data reliable enough for scheduling/enrollment.

Tasks:

1. Audit term fields against PRD calendar requirements.
2. Audit subject/course fields against PRD course catalog.
3. Audit curriculum version state against recorded-approved/active/superseded lifecycle.
4. Verify curriculum subject scheduling fields and readiness checks.
5. Add missing tests for activation and readiness blockers before UI expansion.

Exit criteria:

- A program has an active curriculum version.
- Curriculum subjects include enough scheduling fields for CP-SAT demand.
- Term setup blocks scheduling/enrollment when required dates are missing.

## Immediate Recommendation

Proceed with S0A, then S1. Do not continue finance, scheduling, COR, or Student Hub UI until the workspace gate and applicant-to-student identity path are coherent. Those later modules depend on a stable student profile, role boundary, active term, and curriculum assignment.

## Tracking Rule

When this plan changes:

1. Update this file first.
2. Mirror active sprint work to GitHub Issues in `yosoykyle/SIA-TALA`.
3. Keep old checklists summarized or archived, not active.
4. Do not let implementation tasks reference deleted `docs/prd_modules/*`.

## GitHub Issue Tracking Model

GitHub Issues replace Linear for rescue execution tracking.

Recommended structure:

1. Create one parent/meta issue: `TALA Rebaseline Rescue Controller`.
2. Create one issue per rescue sprint:
   - `S0A Architecture Sanity Pass`
   - `S1 Workspace Gate`
   - `S2 Admissions/Handover Trace`
   - `S3 Academic Setup Hardening`
3. After modules 05-13 are audited, create later sprint issues only from approved matrix rows.
4. Each issue must include:
   - PRD module references.
   - Code evidence paths.
   - Acceptance checks.
   - Tests to run.
   - Explicit out-of-scope notes.
5. Use GitHub labels:
   - `rebaseline`
   - `rescue-sprint`
   - `docs`
   - `backend`
   - `frontend`
   - `test`
   - `blocked-clarification`

Do not create issues for every PRD bullet. Create issues only for vertical slices that can be implemented, tested, and reviewed without reviving the old SDD backlog.
