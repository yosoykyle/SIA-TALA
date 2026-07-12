# TALA Rescue Next Steps

## Purpose

This is the active planning surface: it controls issue order and scope, not product behavior. It is read after `AGENTS.md` and `TALA-Orchestrator-Protocol.md` in the intake chain. For each issue it shows only the status, goal, sub-slice map (when split), dependency lock, and next boundary. The protocol owns the full cycle — planning, gates, delegation, verification, tracker movement, commits, and sync — so do not restate those here.

- **Issue numbering:** the next issue continues from the last ID in `TALA-Local-Linear-Sync-Tracker.md` (or Linear).
- **Sub-slice maps:** when a parent issue is split, the primary records the map here on plan acceptance (ID, one-line purpose, status, and next boundary per sub-slice) and keeps the parent here until every sub-slice is complete. A finished sub-slice is trimmed to a one-line status stub — its delivered detail lives in the git commit message, not here — and the parent is removed once every sub-slice is complete.
- **Resume:** after compaction, interruption, rejected worker output, or stale state, run the resume checkpoint from the protocol before continuing.

## Active and Upcoming Issues

Dependency lock:

1. Identity, roles, panels, and base administration come first.
2. Academic setup and calendar come before admissions handover because handover assigns Program and Curriculum.
3. Holds and lifecycle foundation come before enrollment because gates and COR visibility depend on student state.
4. Term offerings, resources, and a published Master Schedule come before enrollment binding.
5. Finance core comes before official enrollment because assessment, ledger, downpayment, accommodation, and Finance Gate affect enrollment.
6. Student Hub comes after source records exist; it is a projection, not a source module.
7. A pre-integration regression gate (TAL-93) must pass before integration hardening begins, proving the SIS foundation is clean.
8. CP-SAT and PayMongo end-to-end hardening (TAL-94/95) start only after the pre-integration gate passes; they are human-gated and require credentials/deployment steps.
9. A post-integration regression gate (TAL-96) runs after both integrations are wired in, catching regressions introduced by external service handlers before demo preparation.
10. Demo and rehearsal support (TAL-97) builds only on a fully verified and integration-tested system.

| Issue | Status | Goal |
| --- | --- | --- |
| TAL-91 | Done locally; pending explicit Linear sync | Student Hub Projection Acceptance: validate student-safe views for enrollment, schedule, finance, COR/output, grades, holds, lifecycle, completion, and notices. Split into TAL-91A-D, all complete; see Local Linear Sync Tracker. |
| TAL-92 | Done locally; pending explicit Linear sync | Reports, Audit, Imports, Retention, and Remaining Admin Acceptance: validate fixed reports/exports, audit evidence, guarded imports, retention categories, integration settings, operational monitoring, and remaining system configuration. Owning contract PRD `13_system_admin_reports_audit.md`. Split into TAL-92A–F, all six complete; see Local Linear Sync Tracker. |
| TAL-93 | In progress - only TAL-93J3 remains (TAL-93A-I, J1, J2, and all fix boundaries J2a-h done locally) | Cross-Role Regression, Security, and UAT Readiness (Pre-Integration Gate). Only the final gate TAL-93J3 is outstanding; it must pass before integration hardening (TAL-94/95) begins. See the TAL-93 Sub-Slice Map below. |
| TAL-94 | Planned | Build-state UNVERIFIED — do NOT assume "hardening" means it works: this slice MUST start with (1) a Ground-Truth Gate on the integration (tables? real solver endpoint vs stub? passing tests? deployed/credentialed?) classifying each surface aligned/gap/phantom, and (2) the MANDATORY Benchmark Gate (mature CP-SAT/scheduling practice + Academico reference + CHED loading/scheduling policy) to validate the PRD design is realistic; if the PRD design is wrong, correct it via the Authority Document Correction rule and scope the fix to patch/simplify/bounded-rebuild-of-that-surface only (never a system restart), deferring disproportionate richer behavior to a post-MVP issue. CP-SAT End-to-End Scheduling Hardening: prove validated foundation records through solver dispatch, candidate review, publication, schedule visibility, and safe failure/infeasibility handling. Human-gated; requires credentials, solver deployment, and manual verification steps. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for solver run records (PRD §13.6 scope 8's solver-run half) once solver dispatch is wired in; from TAL-92A — Solver Run History report (PRD §13.3.4 admin/audit report #6); from TAL-92F: wire the Schedule Released production notification trigger once schedule publication is live. |
| TAL-95 | Planned | Build-state UNVERIFIED — do NOT assume "hardening" means it works: this slice MUST start with (1) a Ground-Truth Gate on the integration (live payment code EXISTS — PaymentConfirmationService/PayMongoWebhookProcessor — but real gateway wiring / webhook verification / idempotent posting are unverified: real gateway vs stub? passing tests? credentialed?) classifying each surface aligned/gap/phantom, and (2) the MANDATORY Benchmark Gate (mature e-payment/gateway+ledger practice + Academico reference + RA 10173 + BSP/e-payment regs) to validate the PRD design is realistic; if the PRD design is wrong, correct it via the Authority Document Correction rule and scope the fix to patch/simplify/bounded-rebuild-of-that-surface only (never a system restart), deferring disproportionate richer behavior to a post-MVP issue. Payment Gateway End-to-End Hardening: prove payment attempt, gateway evidence, webhook verification, idempotent ledger posting, Finance Gate, and Accounting/Student visibility. Human-gated; requires API keys, webhook endpoints, and test-mode payment verification. Deferred items routed here: from TAL-92B — add audit logging (`activity_log`) for PayMongo checkout-attempt creation (PRD §13.6 scope 5's PayMongo half) once the live gateway is wired in; from TAL-92A — PayMongo Webhook Event Log report (PRD §13.3.4 admin/audit report #7); from TAL-92F: wire the Payment Received production notification trigger once the live gateway is wired in. |
| TAL-96 | Planned | Cross-Role Regression and Integration Coherence (Post-Integration Gate): verify the full system remains correct after CP-SAT and PayMongo integrations are wired in; catch regressions introduced by external service handlers, solver publication effects, and payment posting side-effects. |
| TAL-97 | Planned | Demo and Rehearsal Support from Verified MVP: rebuild only the realistic demonstration support needed for accepted flows, on top of a fully verified and integration-tested system. |
| TAL-98 | Planned (future enhancement, post-MVP) | Archival & Offline-Storage Management: optional archive-management interface (browse/restore archived and exported records), automated offline/cold-storage export, and on-premise HDD/SSD backup/replication target. Deferred from the TAL-92E retention clarification (PRD §13.7.5 point 7): V1 keeps all records in the operational database via soft-archive (hidden-but-queryable), so this is not required for the capstone MVP. Also the natural home for automated disposal jobs (PRD §13.7.4 rule 10) if the institution later explicitly requires them. Not a dependency for any MVP slice. |
| TAL-99 | Planned (future enhancement, post-MVP) | Data-Subject Privacy Request Handling & Log (RA 10173 §16): intake, DPO triage/fulfilment, and logging of data-subject requests (access, rectification, erasure/blocking, object, data portability, complaint). Deferred from TAL-92F: PRD §13.3.4 admin/audit report #10 (Privacy Request Log) has no source record, and RA 10173 with its IRR mandate no specific in-system request-log UI — data-subject-request handling is a DPO-owned manual/hybrid process. No MVP slice depends on it; access-request evidence is already partly served by `activity_log` + `output_access_logs`, and the erasure/blocking right routes through the TAL-92E hold-aware disposal-review ledger + the TAL-98 archival scope. Not a dependency for any MVP slice. |
| TAL-100 | Planned (future enhancement, post-MVP) | Configurable Notification Templates: admin-editable, database-backed email/notification templates (subject + body per notification type), replacing code-defined content. Deferred from TAL-92F: PRD §13.1.1 configurable record #17. V1 defines notification content in code (Laravel Mailable classes + Blade views); DB-editable templates are a post-MVP administration convenience, not an MVP dependency — no MVP slice requires them. |
| TAL-101 | Planned (future enhancement, post-MVP) | Database-Level Audit Tamper-Evidence Hardening: append-only / write-once protection for the `activity_log` table (e.g., MySQL triggers blocking UPDATE/DELETE, or hash-chaining for cryptographic verifiability). Deferred from TAL-93A (PRD 13.6 note). V1 enforces audit immutability at the application layer only (read-only `ActivityPolicy`, `canCreate()=false`), which the TAL-93A benchmark found proportionate for the capstone MVP; DB-level tamper-evidence is a post-MVP hardening enhancement. Not a dependency for any MVP slice. |

### TAL-93 Sub-Slice Map

Parent TAL-93 (pre-integration gate) split into TAL-93A-I + TAL-93J1-J3. TAL-93A-I, J1, and J2 are DONE locally (test-isolation and authorization repair, the Larastan baseline, phantom pre-rescue retires, the rebuilt Admission Requirement Policy surface, canonical permission seeding, gate-environment prep, and the read-only Ground-Truth audit). Only the final gate TAL-93J3 remains. Completed rows are one-line stubs; full delivered detail is in the git commit messages and the Local Linear Sync Tracker.

| Sub-slice | Nature | Purpose | Status |
| --- | --- | --- | --- |
| TAL-93A | Housekeeping | Foundation housekeeping: audit-immutability accepted app-layer (DB-level -> TAL-101); removed unused `pxlrbt/filament-activity-log`, `maatwebsite/excel`. | Done locally; pending Linear sync |
| TAL-93B | Tool-fix | Test-isolation repair: idempotent role seeding across 4 test files. | Done locally; pending Linear sync |
| TAL-93C | Feature fix | Retire legacy PersonalDataCorrectionRequest; align identity edits to PRD §3.5 + audit logging. | Done locally; pending Linear sync |
| TAL-93D | Feature fix | Verified-user 403 regression fixed as stale student fixtures (StudentProfile gating); no production auth change. | Done locally; pending Linear sync |
| TAL-93E | Tool-fix | Static-analysis (Larastan) baseline established; new errors surface only. | Done locally; pending Linear sync |
| TAL-93F | RETIRE (F1-F3) | Retire superseded admissions-offering/IAS remnant (offering/capacity/doc-requirement); live ChecklistItem + state-based policy + EnrollmentSeatReservation replace it. | Done locally; pending Linear sync |
| TAL-93G | Security (foundational) | Canonical permission seeding + role-access enforcement per PRD §2.3; per-role access-matrix tests. | Done locally; pending Linear sync |
| TAL-93H | RETIRE (H1-H3) | Retire phantom scheduling/curriculum surfaces (dead section-meeting manual path, Curriculum/CurriculumSubject, DeliveryPattern); live CurriculumVersion/CurriculumEntry replace them. | Done locally; pending Linear sync |
| TAL-93I | Core config build | Admission Requirement Policy Configuration Filament surface for the live state-based model, gated on `manage-admission-setup`. | Done locally; pending Linear sync |
| TAL-93J1 | Tooling/security | Gate environment + dependency prep: forced `test_tala_db` target, bootstrap guard, idempotent baseline seed, tool security patches. | Done locally; pending Linear sync |
| TAL-93J2 | Read-only audit | Whole-repository Ground-Truth + static-analysis audit; findings routed to fix boundaries TAL-93J2a-h. | Done locally; pending Linear sync |
| TAL-93J3 | Scope alignment + gate (J3a-J3c) | Final TAL-93 preparation: J3a reconciles the assigned-but-unused `manage-curricula` permission; J3b applies the full protocol to a docs-only capstone scope-clarity and policy-alignment authority correction; J3c then runs the pure pass/fail cross-role/security/UAT-readiness gate against the clarified authority. | J3a done locally; J3b next, then J3c |

### TAL-93J2 Fix-Boundary Map

TAL-93J2 (read-only audit) findings were routed here as separately-approved fix boundaries and executed lowest-risk first, each following the proven TAL-93F/H phantom-retire process. All of TAL-93J2a-h are DONE locally; rows are one-line stubs with full detail in the git commit messages and the tracker.

| Sub-slice | Nature | Purpose (live replacement / PRD) | Status |
| --- | --- | --- | --- |
| TAL-93J2a | RETIRE | Faculty-availability phantom island + FacultySubjectEligibility + standalone SectionDeliveryGroupResource -> CalendarEvent / FacultyQualification / DeliveryGroups relation manager (PRD 05 §5.2). | Done locally; pending Linear sync |
| TAL-93J2b | RETIRE | Dead grades-table design -> live `grade_rosters` workflow (PRD 10). | Done locally; pending Linear sync |
| TAL-93J2c | RETIRE | Catalog/enrollment/docs phantom cluster (Subject/EnrollmentSubject/DocumentUpload) -> Course+CourseSpecification / CourseEnrollment / DocumentEvidence+ChecklistItem; pruned vestigial `view-class-list`. `manage-curricula` carry-in -> TAL-93J3. | Done locally; pending Linear sync |
| TAL-93J2d | RETIRE | Finance phantom cluster (FeeTemplate/InstallmentPolicy/Milestone + service/trait) -> live FeeRule / PaymentScheduleRow / FinancialAccommodation (PRD 08). | Done locally; pending Linear sync |
| TAL-93J2e | BUILD/PATCH | Dynamic admin-managed landing FAQ (FaqEntry table + resource registration + escaped public render); PRD 02/12 doc updates. | Done locally; pending Linear sync |
| TAL-93J2f | RETIRE | Standalone tableless PromissoryNote model/service/policy/unregistered resource + dead payment/webhook no-op edges; pruned `approve-promissory-notes`; live FinancialAccommodation recorded-result workflow preserved. | Done locally; pending Linear sync |
| TAL-93J2g | RETIRE | Tableless CorVerification token/lifecycle/resource island + orphaned `manage-cor-verifications`; authenticated source-derived COR (BuildCorOutput/CorPrintController/CorView/`output_access_logs`) preserved. | Done locally; pending Linear sync |
| TAL-93J2h | PATCH/COMPLETE | FinancialAccommodation controlled lifecycle transitions, Accounting authorization, audit evidence, and view action. | Done locally; pending Linear sync |

### TAL-93J3 Boundary Map

The revised split is approved before implementation planning. Each sub-slice requires its own accepted plan and orchestration command. J3b changes authority documents only after benchmark-backed, user-approved findings; it preserves aligned implementation and routes any proven code mismatch into a separately approved bounded fix. J3c remains a pure gate and must route, not repair, any defect it finds.

| Sub-slice | Nature | Purpose | Status |
| --- | --- | --- | --- |
| TAL-93J3a | Permission reconciliation | Pruned the vestigial `manage-curricula` slug (0 live checkers after J2c retired `SubjectPolicy`); curriculum capability preserved by role-gating in the Course/CourseSpecification/CurriculumVersion policies. 13->12 permissions, 14->12 assignments; no PRD change. | Done locally; pending Linear sync |
| TAL-93J3b | Docs-only authority correction | Apply the Ground-Truth, Slice Clarity, Benchmark, Qualified-Reference, and Purposeful Simplification gates to classify capabilities as core, supporting/frozen, recorded-result, read-only, fixed V1 policy, configurable, deferred, or conflicting; make only approved PRD/blueprint/architecture corrections. Preserve aligned implementation and existing tests; do not edit the historical baseline research-paper files. | Revised split approved; J3a complete - awaiting plan |
| TAL-93J3c | Final gate | After J3b and every separately approved routed fix are complete, verify all five staff roles against every registered staff surface; run the complete automated regression, static-analysis, dependency-security, build, and thin rendered-smoke stack; produce only the visual, institutional-workflow, real-email, and later-integration checklist that still requires people. Pure pass/fail; no product fixes. | Revised split approved; blocked on J3b and routed fixes, then awaiting plan |

### Next Boundary

Next primary boundary: **Plan TAL-93J3b** (docs-only authority-correction slice); route any proven implementation mismatch separately. Run TAL-93J3c only after J3b and every approved routed fix are complete. (TAL-93J3a permission reconciliation is done locally.) Once J3a-J3c are complete, remove the entire TAL-93 parent block from this file.
