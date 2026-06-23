# T.A.L.A. System - Technical Specification

**Total Academic Lifecycle Automation System**

**Servitech Institute Asia (SIA)**

---

## Document Control

Versioning rule: major version increments once per update date; same-day updates are consolidated.

| Version | Date | Description |
| --- | --- | --- |
| 1.0 | 2026-04-02 | TS baseline consolidated. |
| 2.0 | 2026-04-30 | Hybrid ingestion; private storage; staff verification. |
| 3.0 | 2026-05-01 | Queue, scheduler, and worker strategy. |
| 4.0 | 2026-05-02 | Student Hub/PWA architecture; baseline tables; period grading. |
| 5.0 | 2026-05-03 | Prerequisite validator; modality enum; PUP transmutation; PayMongo/GCV. |
| 6.0 | 2026-05-04 | PHPUnit alignment; Term Close job; returnee states; document transitions. |
| 7.0 | 2026-05-05 | Advising enum/service; roster fields; TallStackUI; role middleware. |
| 8.0 | 2026-05-12 | Curriculum intake; faculty self-service scheduling; role segregation; migration refs. |
| 9.0 | 2026-05-13 | Applicant fields; evidence confirmation; payment endpoints; terms calendar gates. |
| 10.0 | 2026-05-14 | Toast templates; import batch framework. |
| 11.0 | 2026-05-18 | Student records queries; settings; document review; COR QR; grade correction. |
| 12.0 | 2026-05-20 | Walk-in APIs; installment schema; enrollment/payment/import rule contracts. |
| 13.0 | 2026-05-21 | Complexity audit: payment delivery, GCV, calendar, fees, ledger, RateLimiter. |
| 14.0 | 2026-05-22 | Import/notification alignment; cost addendum; Fortify; curriculum/grading fixes. |
| 15.0 | 2026-05-23 | Subsidy workflow replaced by freshmen discount. |
| 16.0 | 2026-05-24 | Calendar/installment locks; migration inventory; Fortify/runtime settings. |
| 17.0 | 2026-06-02 | Filament role contracts; canonical split-name contract. |
| 18.0 | 2026-06-03 | Settings debloat; Student Hub guidance; admin CRUD/service-boundary contracts. |
| 19.0 | 2026-06-04 | Faculty class list and enrollment-subject Filament contract. |
| 20.0 | 2026-06-05 | Filament lifecycle contracts: documents, enrollment, installments, settings, schedules, COR, FAQ. |
| 21.0 | 2026-06-06 | Detail/display hardening; relationship labels; private uploads; Student Hub/FAQ access. |
| 22.0 | 2026-06-07 | Rescue architecture: GCP OR-Tools; faculty assignment, availability, coverage, validity targets. |
| 23.0 | 2026-06-08 | Scheduling constraints; teacher/adviser requirement; max-seat cap. |
| 24.0 | 2026-06-09 | Scheduling pipeline; solver deployment; promissory and returnee boundaries. |
| 25.0 | 2026-06-10 | Guided tour removed; Phase 1 boundary; foundation admin architecture. |
| 26.0 | 2026-06-11 | Controlled import architecture; Academic Head approval; PayMongo smoke command. |
| 27.0 | 2026-06-12 | PayMongo evidence; FAQ governance cleanup; TS cleanup. |
| 28.0 | 2026-06-14 | Backend services active; Student Hub UI pending; scheduling readiness hardened. |
| 29.0 | 2026-06-17 | Scheduling closure; delivery groups; curriculum readiness; publish lifecycle; workload overrides; PayMongo handover parity. |
| 30.0 | 2026-06-18 | Student services; assessment/payment/promissory/adjustment contracts; document lifecycle requirements. |
| 31.0 | 2026-06-19 | Workflow reconciliation; admission/retention/capacity requirements; UI test baseline. |
| 32.0 | 2026-06-21 | Submission baseline; benchmark/legal hardening; College-only correction; document-request removal. |
| 33.0 | 2026-06-22 | Scope pruning: non-client workflows, Student Hub requests, student lifecycle, administration, imports, automated text extraction, security, and operations. |
| 34.0 | 2026-06-23 | Scheduling lifecycle benchmark; Registrar publication; hard solver inputs. |

---

**Document Scope Boundary:** This document defines technical architecture, contracts, and implementation constraints only. Project execution status, QA progress, and implementation ownership live outside the TS in project management artifacts.

**Code Example Boundary:** Code blocks in this TS are contract examples or pseudocode unless they point to a concrete source file. The implementation source of truth is the checked-in Laravel code, migrations, policies, tests, and lockfiles. When an example conflicts with the codebase, update the example or replace it with a contract/interface description instead of copying it blindly.

**Technical Specification Scope Boundary:** This TS is the benchmark-grounded technical contract for the final-form TALA system. It is written so a developer can implement the approved FS baseline using Laravel services, policies, migrations, Filament staff resources, Livewire/TallStackUI student surfaces, queues/jobs, private files, audits, and focused tests. The TS must describe stable contracts and boundaries; implementation progress belongs in tracking artifacts.

**College-Only Technical Boundary:** The active implementation target is College-only. Prior-school credentials such as Grade 12, Form 138, and Form 137 may exist only as College admission evidence.

**System-Owned Technical Boundary:** TALA implements only system-owned SIS workflows: applicant intake, admission evidence review, enrollment, scheduling, student accounting evidence, grades, Student Hub visibility, roster exports, generated artifacts, privacy, and audit. Removed outside-office processes must not appear as routes, permissions, model lifecycles, seed data, factories, jobs, or tests.

**Payment Evidence Technical Boundary:** TALA is a student subsidiary ledger and internal evidence system only. It may store payment/reference evidence and issue SOA/payment-acknowledgement artifacts, but official tax documents remain outside the product baseline.

### Submission Baseline Technical Contract Map

| Technical area | Baseline contract | Primary implementation pattern | Verification expectation |
| --- | --- | --- | --- |
| Identity and access | Fortify/authenticated sessions, active-account middleware, one-role operational access, policy-gated staff/student routes, logout/session expiry, and audit for account lifecycle actions. | Laravel auth, middleware, policies, Spatie Permission, Filament navigation policy checks. | Feature tests for login/logout, protected-route denial, role boundary, and lifecycle actions. |
| Admissions and documents | Published offerings, versioned requirement policies, materialized applicant checklist snapshots, per-item admission/retention state, private evidence storage, and Registrar verification. | Laravel services/actions, private filesystem, and Filament actions for staff review. | Service/feature tests for intake, checklist resolution, review state changes, retention undertaking, upload validation, and manual review. |
| Enrollment and student records | Atomic handover from approved applicant to canonical student profile/enrollment, secured capacity, section/delivery placement, enrolled roster, and lifecycle audit. | Transactional services with row locking, model states/enums, capacity services, roster queries/export actions. | Tests for happy path, missing gate, full capacity, duplicate/idempotent handover, and roster authorization/export. |
| Scheduling | Curriculum readiness, projected-demand section delivery groups, faculty eligibility/availability/configured workload, Registrar-confirmed subject/faculty assignment, immutable snapshots, OR-Tools CP-SAT solve, validated ingest, draft review, transactional Registrar publication, and published-version immutability. | Laravel scheduling services plus authenticated Cloud Run/OR-Tools solver. | Unit/feature tests for hard constraints, solver statuses, result ingest, Registrar publication, conflict rejection, superseding versions, and unauthorized publication denial. |
| Finance and payments | Assessment, approved discount policy, immutable ledger, PayMongo/manual channel parity, provider webhooks, idempotency, SOA/payment evidence, externally issued receipt/reference evidence recording, and computed clearance. Installments, promissory tracking, exam-access accommodation, and refund/disposition automation remain review candidates. | Accounting services, webhook storage/signature verification, queue jobs, ledger-entry invariants, internal artifact issuance. | Tests for assessment, payment confirmation, webhook retry/idempotency, overpayment, zero-balance edge, and finance privacy. |
| Grades and academic records | Faculty class-list scope, grade encoding/submission, Registrar verification, finalization, correction/override, finalized grade history, and Student Hub grade viewing. Formal transcript/report-card PDF generation is outside active scope. | Service-owned grading workflows, policy-gated Filament actions, immutable finalization/correction audit. | Tests for assigned-faculty access, invalid role denial, grade finalization, correction approval, and grade-history integrity. |
| Student Hub and public UI | Livewire/TallStackUI pages read from backend services; Student Hub is protected by active student status; offline/PWA behavior is read-only. Approved PayMongo payment entry must route through the finance service path; document-request, credential-request, courier, and generic service-request UIs are outside active scope. | Livewire components, TallStackUI controls, service-returned view models, PWA cache for approved read-only data. | Browser/feature tests for access, dashboard data, validation/error states, read-only offline boundaries, and no cross-student leakage. |
| COR, SOA/payment evidence, and QR verification | COR is derived from canonical enrolled state. SOA/payment acknowledgements are derived from Accounting-owned ledger/payment state. Formal TOR, Form 137, diploma, report-card PDF, and full credential issuance/fulfillment are outside active TALA scope; Form 137/Form 138 remain prior-school admission evidence only. Student grade history and grade viewing remain active under the Grades and Student Hub contracts. | DomPDF/Blade templates for COR/internal finance evidence, private storage, QR token/signed URL verification, issuance services, lifecycle states. | Tests for source-state eligibility, private artifact access, QR verification, revocation/supersede, and no private data in QR payloads. |
| Imports, exports, and reporting | Controlled templates, private upload, validation preview, zero-error commit where required, audit, and generic roster/report export. | Laravel Excel/PhpSpreadsheet services, import batch records, queueable processing, authorized export actions. | Tests for template headers, invalid rows, no partial unsafe commit, export authorization, and audit records. |
| Security, privacy, and audit (RA 10173 & NPC 2023-06) | Privacy-by-design access controls, private-by-default files, signed/temporary access, role-scoped evidence visibility, webhook signature checks, purpose-limited retention, breach protocol readiness, activity logs, and sensitive-support data minimization. | Laravel filesystem, signed URLs, policies, activitylog, validation/FormRequests, webhook verifier services. | Security tests for unauthorized access, private path leakage, webhook rejection, upload validation, and audit creation. |

### Submission Readiness Rule

This TS is submission-ready only when every final-form feature described in the FS has an implementable technical contract covering: source models/tables, service/action owner, policy/RBAC boundary, UI surface, integration/file/job behavior, validation and failure modes, audit/evidence output, and focused test expectation. A feature may be marked Supporting, Deferred, Phase 2, or External Boundary in execution artifacts, but its final-form technical boundary must still be clear enough that future implementation does not require guessing.

## Table of Contents

1.  [Technical Architecture](#1-technical-architecture)
2.  [Database Implementation References](#2-database-implementation-references)
3.  [Module Implementation Details](#3-module-implementation-details)
4.  [Security Implementation](#4-security-implementation)
5.  [Frontend Implementation](#5-frontend-implementation)
6.  [Third-Party Integrations](#6-third-party-integrations)
7.  [Implementation & Verification Strategy](#7-implementation--verification-strategy)
8.  [Deployment & Operations](#8-deployment--operations)

---

## 1. Technical Architecture

### 1.1 Architecture Philosophy

**“Dev-Quick” Hybrid Architecture** designed for Speed, Familiarity, and Unified Data.

### 1.1.1 Core Technical Boundary

System acceptance depends on the following technical requirements. These are acceptance boundaries, not an execution tracker:

- Academic foundation behavior must be staff-operable through typed Filament resources/pages and/or controlled imports for Programs, Subjects, Curricula/Curriculum Subjects, Terms, Sections, and the minimum safe room input required by scheduling. Local seeders remain QA fixtures only.
- Official/finalized grade corrections use an authenticated Academic Head approval action/queue before Registrar resolution applies corrected values. Registrar-only prior-approval recording is no longer an accepted workflow.
- PayMongo must pass a live sandbox/configured-environment smoke check. Its mock driver remains available for automated tests and local fallback only; dated execution results belong in acceptance readiness artifacts.
- Import must implement strict template download, upload, validation preview/error report, commit, and audit evidence. The Phase 1 implemented path covers curriculum/foundation import; student, grade, financial, and enrollment legacy imports require separate controlled services if they become required for acceptance.
- Backend contracts are active backend readiness dependencies before acceptance: applicant intake orchestration, student enrollment orchestration, prerequisite-aware subject suggestion, and student dashboard aggregation. Student Hub UI screens and PWA presentation remain deferred until those contracts are stable.

### 1.2 Technology Stack

| Layer | Technology | Package | Version | Role in System |
| --- | --- | --- | --- | --- |
| **Backend Core** | **Laravel 12 (PHP)** | `laravel/framework` | 12.58.0 | **The Brain.** Monolithic backend handling Business Logic for **Enrollment, Grading, and Financial Record Keeping**. It serves as the single source of truth for all modules. |
| **Staff Operations UI** | **FilamentPHP v5** | `filament/filament` | 5.6.2 | **The Command Center.** Powering the **Registrar, Accounting/Cashier, Faculty, Academic Head, and System Super Admin** dashboards. It leverages “TALL Stack” (Tailwind, Alpine, Livewire, Laravel) to auto-generate 80% of the UI (Tables, Forms, Notifications, Modals), drastically reducing development time. |
| **Student Hub UI** | **Laravel + Livewire + TallStackUI + Tailwind CSS + Alpine.js** | `livewire/livewire`, `tallstackui/tallstackui`, `tailwindcss`, `alpinejs` | 4.3.0, 3.0.0, 4.1.18, 3.15.10 | **The Public Face.** Uses **Server-Side Rendering (Blade)** with **TallStackUI Components** for premium aesthetics and **Alpine.js** for client-side interactivity. It leverages **Multi-Route SPA routing with wire:navigate** for instantaneous transitions, PWA offline caching, and SEO. Includes **PWA Service Workers**. |
| **Database** | **MySQL** | \- | 8.0+ | **The Memory.** Relational source of truth for academic, financial, audit, document metadata, and staff-verified fields. Raw uploaded files are not stored as database BLOBs. |
| **File/Object Storage** | **Laravel Filesystem (Private Disk)** | Built-in / Flysystem | \- | **The Evidence Vault.** Stores uploaded documents, payment evidence, and generated artifacts outside the database with private visibility and authorized temporary access. The deployment selects the storage provider. |
| **Infrastructure** | **Supported PHP/MySQL Web Runtime** | Deployment-managed | \- | Runs HTTPS web traffic, the Laravel scheduler, persistent database-queue workers, protected storage, and backup/restore controls without prescribing a hosting vendor or fixed server size. |

---

**Panel Terminology Boundary**: "Admin Nexus," "Admin Panel," "Filament admin panel," and similar labels refer only to the shared staff operations UI. They do not define a generic `admin` role. Implementation must use the approved roles and permission slugs: `registrar`, `accounting`, `faculty`, `academic-head`, and `system-super-admin`.

### 1.2.0 Implementation Readiness Snapshot

The lockfiles and local package manager output are authoritative for exact installed versions during implementation planning. This document records major-version contracts only: PHP 8.2, Laravel 12, MySQL, Filament 5, Livewire 4, Laravel Boost 2, Tailwind CSS 4, Alpine.js 3, and PHPUnit 11. Re-check `composer.lock`, `package-lock.json`, or `composer show` / `npm list` before using version-sensitive APIs.

The approved core implementation tools include `spatie/laravel-permission`, `spatie/laravel-model-states`, `spatie/laravel-activitylog`, `spatie/laravel-webhook-client`, `luigel/laravel-paymongo`, `tallstackui/tallstackui`, `erag/laravel-pwa`, `maatwebsite/excel`, `barryvdh/laravel-dompdf`, and `chillerlan/php-qrcode`. Exact installed packages remain lockfile-controlled.

The database contract is defined by the migration files, model/service boundaries in this TS, and `TALA-Foundation-Migration-Control-Log.md`. Live migration execution state is environment-specific and must be verified with `php artisan migrate:status --no-interaction`; this specification must not carry exact applied/pending migration counts.

The following runtime boundaries apply across environments:

| Runtime Area | Boundary | Implementation Impact |
|--------------|----------|-----------------------|
| Queue Workers | The approved queue connection is the Laravel database driver. | Local and deployed environments run persistent `queue:work` processes with retry/backoff and failed-job storage; the deployment process monitor restarts failed workers. |
| API Routes | `routes/api.php` exists for integration endpoints such as PayMongo webhooks. | New API endpoints must be registered deliberately, signed or authenticated where appropriate, and covered by focused feature tests. |
| Webhooks | PayMongo webhooks use the local `webhook_calls` storage table plus application signature verification and processing services. | Provider callbacks must be stored, signature-verified, idempotent, and covered by smoke evidence before acceptance. |
| File Storage | The `local` disk already roots to `storage/app/private`. | Uploaded student documents and payment evidence must remain private by default. Public disks are only for intentionally public assets. |
| Domain Schema | Stable foundation migrations are implemented; business-policy-heavy domains remain deferred. | Use the migration files and `TALA-Foundation-Migration-Control-Log.md` as implementation references. Add future domain schema through new migrations paired with services and tests. |

### 1.2.1 Frontend Stack Details (TALL Stack + TallStackUI)

The Student Hub UI uses an enhanced **TALL Stack** with the following components:

| **Component** | **Technology** | **Purpose** |
| --- | --- | --- |
| **Tailwind CSS** | v4.0+ | Utility-first CSS framework for rapid UI development, configured to support TallStackUI components |
| **Alpine.js** | v3.x | Lightweight JavaScript framework for client-side interactivity (dropdowns, modals, toggles) without the complexity of larger frameworks |
| **Livewire** | v4.x | Full-stack framework for dynamic Laravel applications using server-side rendering |
| **TallStackUI** | v3.0 | Free, open-source Livewire component library for polished, production-ready UI elements (modals, badges, buttons, dropdowns, layouts, sidebars, tables, date pickers, and 50+ components) |
| **Heroicons** | v2.x | Hand-crafted SVG icons integrated via Blade components |

**Why Alpine.js?**  
Alpine.js provides lightweight JavaScript reactivity for UI patterns that don’t require Livewire’s server round-trip:

-   Dropdown menus and popovers
-   Modal open/close animations
-   Tab switching
-   Form validation feedback
-   Toggle switches and checkboxes
-   Client-side state management for instant UI updates

Example usage in Blade templates:



```blade
<!-- Dropdown with Alpine.js --><div x-data="{ open: false }" class="relative">    <button @click="open = !open" class="flex items-center gap-2">        Menu <span x-text="open ? '▲' : '▼'"></span>    </button>    <div x-show="open"          @click.outside="open = false"         x-transition         class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg">        <!-- Menu items -->    </div></div>
```

---

### 1.3 Additional Technical Components

| Component | Technology / Approach | Package | Purpose |
| --- | --- | --- | --- |
| **Document Storage** | Hybrid file + relational metadata model | Laravel Filesystem + MySQL | Original files remain in private storage; review state, reviewer, timestamps, reasons, and approved verified fields are stored in MySQL. |
| **Background Jobs** | Laravel Database Queue + Laravel Scheduler | Built-in | Runs webhook processing, solver dispatch, notifications, controlled imports, and approved scheduled operations. Persistent workers are deployment-managed and failed jobs remain inspectable. |
| **Webhooks** | Spatie Webhook Client | `spatie/laravel-webhook-client` | Safely receives, verifies signatures, and prevents double processing (idempotency) of GCash webhooks. |
| **Audit Trails** | Spatie Activity Log | `spatie/laravel-activitylog` | Tracks all model changes and user actions for strict accountability on financial and grading overrides. |
| **Audit UI** | Filament Activity Log | `pxlrbt/filament-activity-log` | Provides a Filament staff-panel GUI to view the `spatie/laravel-activitylog` records. |
| **State Machine** | Spatie Model States | `spatie/laravel-model-states` | Enforces approved lifecycle transitions where a domain requires explicit state control. |
| **Email** | Laravel Mail (SMTP) | Built-in | Account-related notifications |
| **Excel I/O** | Laravel Excel / PhpSpreadsheet | `maatwebsite/excel` ^3.1 | Final schedule exports, curriculum template import/export, report exports, and optional strict staff fallback imports. Faculty availability is collected in-app, not primarily by Excel. |
| **PDF Gen** | DomPDF | `barryvdh/laravel-dompdf` ^3.1 | COR and Report Card generation (Module 2) |
| **QR Code Gen** | chillerlan/php-qrcode | `chillerlan/php-qrcode` ^5.0 | COR QR image generation for an online verification URL. The payload is a signed/opaque-token route, not raw student/term/hash data. |
| **RBAC** | Spatie Laravel Permission | `spatie/laravel-permission` ^6.24 | Role-based access control for all user types (applicant, student, registrar, accounting, faculty, academic-head, system-super-admin) |
| **PWA** | Laravel PWA | `erag/laravel-pwa` ^2.1 | Progressive Web App support for offline COR access and mobile installation. Provides `@PwaHead` and `@RegisterServiceWorkerScript` Blade directives, Livewire-compatible, supports Laravel 8–13. |
| **Icons** | Blade Heroicons | Built-in (via `filament/filament`) | Filament v5 bundles `blade-ui-kit/blade-heroicons` as a transitive dependency. No separate install needed. |
| **Staff Onboarding** | External operations guidance | No runtime package | The Admin Nexus does not load a guided-tour plugin. Staff onboarding is handled through maintained operations guidance and acceptance scripts unless a future approved item reintroduces a tested tour surface. |
| **Student Onboarding** | Student Hub help content and operations guidance | No runtime package | Student guidance is delivered through maintained page content, published FAQ/help, and acceptance scripts. No guided-tour runtime is part of the active baseline. |

---

### 1.3.1 Document Ingestion & Storage Strategy

**Architecture Decision**: T.A.L.A. uses a **hybrid document storage model**.

> **Architecture Decision:** The generic College pipeline, Regular College Freshman and College Transfer public-offering contracts, Old Curriculum College pathway, inactive-by-default foreign compliance profile, purpose-limited IP/SEN support attributes, readiness-gated payment/enrollment handover, stacked College capacity-plan enforcement, returning/legacy boundary, composable admission dimensions, and deterministic fail-closed resolution are approved. Cross-enrollee intake is removed from the active product scope rather than retained as an inactive branch. Versioned requirements, per-item evidence, immutable history, capacity locking, and typed services are retained controls.

**Admissions and Document Review Technical Baseline**:
This section adopts the benchmark matrix rule for admissions, applicant intake, requirement policies, and manual evidence review. The implementation must behave like a service-owned lifecycle, not a set of unrelated forms: a published admission offering opens intake; a deterministic resolver snapshots requirement policy into checklist rows; evidence enters through self-service, Registrar-assisted, official-transmission, or physical-custody channels; Registrar review decides satisfaction; and only the enrollment/handover services may promote the applicant into official student/enrollment records.

| Contract area | Required technical behavior | Focused verification expectation |
| --- | --- | --- |
| Published offering resolution | `AdmissionRequirementResolver` resolves one active offering/policy for the selected term/scope and fails closed for no match, duplicate priority, unpublished offering, expired window, or unsupported dimension. | Tests cover published happy path plus no-match/ambiguous/unpublished denial without fallback to a hardcoded document list. |
| Applicant staging | Applicant intake creates a pending applicant user plus applicant-intake staging data only. It does not create `student_profiles`, official enrollments, ledger entries, CORs, or Student Hub access. | Tests assert applicant-only access and absence of official student records before approved handover. |
| Checklist snapshot | `applicant_document_requirements` stores the source offering, policy/rule/item versions, labels, gate type, evidence methods, due-date strategy, and current digital/physical/review states. | Tests prove later policy edits do not mutate existing applicant checklist rows. |
| Unified evidence lifecycle | Applicant uploads, Registrar scans, official transmissions, and physical custody events all satisfy the same materialized requirement model while preserving channel, actor, timestamp, checksum or custody evidence, and replacement history. | Tests cover self-service upload, Registrar-assisted physical receipt, replacement evidence, and official-transmission satisfaction. |
| Manual review boundary | Submitted evidence remains preliminary until an authorized Registrar reviews the private source file and records an explicit decision. Automated text extraction, confidence scoring, and field prefilling are outside active scope. | Tests cover upload validation, authorized preview, approve/correction/reject transitions, and no automatic promotion to official values. |
| Review transitions | `DocumentUploadReviewService` or equivalent lifecycle service owns approve, needs-correction, reject, and reprocess transitions with row locking, active-state validation, typed reasons, reviewer metadata, notification context, and activity-log evidence. | Tests cover terminal-state protection, required rejection/correction reason, approved payload copy, and unauthorized-role denial. |
| Gate computation | `DocumentComplianceService` derives admission-gate completeness, retention obligations, missing labels, and hold reasons from checklist rows, not from `student_profiles.hard_copy_received` or free-text notes. | Tests cover gate-blocked payment/handover, retention-nonblocking handover, and checklist-driven hold labels. |
| Retention undertaking | `RetentionDocumentUndertakingService` creates itemized undertakings only for pending retention checklist rows, attaches student/enrollment context on handover, and scheduled jobs mark overdue items without cancelling enrollment. | Tests cover undertaking creation, resolution from later document approval, overdue marking, and no silent section/COR/class removal. |

**Versioned Admission Requirement Contract**:

- `admission_offerings` owns the term-scoped applicant-facing route. It records entry route, prior-credential pathway, citizenship/compliance profile, optional College program and year-level scope, publication window/state, capacity-plan reference, and active requirement-policy version. Unpublished offerings cannot receive public or Registrar-assisted intake.
- Initial seed data creates draft templates for approved College workflow profiles but publishes only Regular College Freshman and College Transfer for acceptance. Cross-enrollee intake must not be seeded, displayed, or retained as a dormant offering. Old curriculum is a controlled College prior-credential pathway; ALS/equivalency is inactive until institution-approved for College admission; foreign is a compliance profile; IP and disability/SEN are purpose-limited support attributes. None creates a separate service or state machine.
- Draft requirement templates reproduce the client-declared admission/retention rows in FS 4.1.2 with source/version provenance. Seeding is not publication: activation validates non-empty coverage, regulator-compatible evidence methods/deadlines, deterministic resolution, and authorized Registrar approval. A binding regulator rule may change the active method or deadline without deleting the traceable client baseline.
- `admission_requirement_policies` owns immutable Registrar-authored policy versions. `admission_requirement_rules` contains structured match criteria over approved dimensions plus explicit priority; publication validates that every offering resolves deterministically and rejects equal-priority conflicts, unknown dimensions, or missing coverage.
- `document_requirement_items` owns the ordered normalized document-type keys produced by a policy rule plus `gate_type` (`admission` or `retention`), permitted evidence methods, storage class, sensitivity class, verified-field mapping, deadline strategy, retention policy, and nullable policy parameters. Items are configuration records, not applicant submissions. Admission and retention classification is scope/version specific; no deployment-wide all-physical constant is permitted.
- `applicant_document_requirements` materializes the selected requirement-item snapshot for one applicant intake. It owns separate digital-review and physical-receipt states independently from whether a digital file currently exists, allowing a Registrar to record a walk-in physical inspection before an optional scan is attached.
- A requirement policy moves through typed draft/active/retired states. Activation rejects overlapping or incomplete rules that would make an offering's resolution ambiguous or impossible.
- `AdmissionRequirementResolver` resolves the published offering and composes its active base and conditional rules when an applicant intake starts. It stores the offering and source policy/rule versions plus an immutable requirement snapshot on the intake so later configuration changes do not rewrite historical applicant obligations.
- Missing or ambiguous resolution blocks intake for that scope and produces a staff-facing setup error. The service must not fall back silently to a hardcoded list.
- Registrar maintains requirement sets through a typed Filament workflow with version/activation actions and audit evidence. Arbitrary per-applicant requirement names and generic delete of activated history are forbidden.
- The existing hardcoded `ApplicantIntakeService::requiredDocumentsFor()` matrix must be replaced by the resolver In the final implementation.

**Unified Submission Channel Contract**:

- `self_service` and `registrar_assisted` are submission-channel values on one document lifecycle. They do not create separate applicant, requirement, or review models.
- Self-service requires an authenticated applicant-owned upload action. Registrar-assisted intake requires the authorized Registrar actor and may record physical inspection with no attachment or attach a scan to private storage.
- Every submitted file links to the applicable `applicant_document_requirements` row and records channel, submitting actor, and submission time. Replacement evidence remains historically linked instead of overwriting the prior file.
- Registrar-assisted physical inspection may be recorded without fabricating a digital attachment. Any optional scan remains private evidence for manual review.
- Both channels use the same completeness, review, correction, rejection, and approval services. Channel-specific controller or Filament code must not duplicate state-transition rules.

**Per-Item Compliance Contract**:

- Each materialized requirement snapshots `gate_type`, permitted evidence methods, review state, due-date policy, and labels. Required items cannot be waived ad hoc.
- Evidence state distinguishes `not_required`, `pending`, `submitted`, `approved`, `needs_correction`, `rejected`, `overdue`, and `satisfied`, while the satisfaction record identifies the accepted method (for example physical original, certified copy, or regulator-permitted official transmission), actor, timestamp, and evidence reference.
- Applicant uploads and Registrar scans remain preliminary unless the snapshot explicitly permits that evidence method and an authorized review records satisfaction. File possession alone never establishes authenticity.
- Admission-gate completeness is an activation prerequisite. Retention items may remain pending after enrollment only through an itemized undertaking with issue date, due date, responsible party, reminders, extensions, receipt history, and resolution.
- `RetentionDocumentDeadlineResolver` derives the due date within the active deployment policy and term calendar. The current institution's ordinary target window is 30-to-60 days, selected per requirement/undertaking; no universal seven-day default is permitted.
- `document_requirement_extensions` preserves prior/new deadlines, reason, actor, and timestamp. Scheduled monitoring marks overdue items, sends deduplicated notifications, and applies only configured documentary/next-cycle holds. It never silently cancels an active enrollment or removes section/COR/class access.
- Runtime implementation currently materializes retention undertakings in `retention_document_undertakings` as one row per pending retention checklist item. Approval for payment creates active undertakings, handover attaches student/enrollment context, Registrar document approval resolves the undertaking, and `ProcessRetentionDocumentUndertakingsJob` marks due active undertakings overdue with `retention_document_overdue` hold evidence. Notification delivery and explicit extension workflow remain admin-surface follow-up work.
- `DocumentComplianceService` derives admission completeness, retention obligations, missing labels, and hold reasons from the checklist. `student_profiles.hard_copy_received` is transitional compatibility data and must not remain an independent source of truth.
- Prior-school evidence uses Registrar-assisted entry of sender/school, channel, receipt timestamp, provenance, verification, and optional private artifact. Undertakings, deadlines, reminders, and holds are TALA-owned records. Alternative identity evidence uses the ordinary private upload/verification lifecycle.
- For Regular College Freshman, admission outcomes are verified identity, verified Grade 12/prior-education completion or eligibility, and accepted Good Moral evidence for the active deployment. Diploma/Certificate of Graduation and one normalized ID-photo obligation default to retention/follow-up. Policy publication rejects duplicate photo items with the same purpose.
- College entrance-exam completion/result is stored as a structured admission assessment linked to the intake. No score threshold affects eligibility until a versioned admission-assessment policy defines the exam, scoring/version, passing or placement effect, effective scope, and approving authority. An uploaded screenshot is never the authoritative exam result.
- `official_transmission` records are Registrar-created receipt/provenance records, not external-system integrations. Electronic artifacts are retained privately and checksummed; physical-only receipt creates a custody/inspection event without fabricating a file. Both methods may satisfy only requirement items whose active policy permits that evidence method.
- For College Transfer, admission outcomes are verified identity, sufficient preliminary academic evidence for credit/eligibility evaluation, verified transfer-release eligibility, and accepted Good Moral evidence for the active deployment. Final TOR, remaining non-duplicate official transfer evidence, and one normalized ID-photo item default to retention/follow-up.
- `document_types` must distinguish `honorable_dismissal` as transfer-release evidence from `final_tor` as the authoritative academic record. A generic `official_transfer_credentials` label may group staff display but must not materialize a duplicate obligation when its purpose is already satisfied by a specific item.
- `old_curriculum` is a College prior-credential pathway, not an applicant type or separate state machine. It composes with Regular College Freshman and substitutes verified old-high-school eligibility evidence for Grade 12/prior-education completion evidence. Requirement materialization deduplicates Form 137 when it already served as the accepted admission evidence and does not add a generic Certificate of Completion unless an active rule identifies the credential and purpose.
- Earlier non-College offering rows are historical source evidence only. They are not seeded as publishable offerings, have no public route, no resolver fallback, and no hardcoded requirement list.
- ALS/equivalency for College admission is inactive until the institution approves a College pathway and evidence rule. If approved later, publication validation must require one authoritative eligibility outcome and deduplicate Certificate of Rating, Certificate of Completion, and equivalent evidence when they prove the same purpose.
- `foreign` is a citizenship/compliance profile, not an applicant type or separate state machine. Publication validation fails unless the base offering is published, institution acceptance of foreign applicants is recorded for the term/scope, and the active policy requires legal stay/study authorization evidence appropriate to the offering. College and other higher-than-high-school offerings must distinguish Student Visa 9(f) evidence; basic-education, short-term, exchange, and non-degree cases must use the configured legal-stay or Special Study Permit evidence method where applicable. Passport, visa, permit, immigration, medical, and English-proficiency files use `restricted_support_file` controls by default. TALA stores evidence, verification state, deadlines, and holds only; outside-agency processing remains outside the product.
- IP and disability/SEN are optional support attributes, not applicant types, routes, or denial-producing dimensions. Requirement resolution must not add an admission gate solely because one of these attributes is present. Support evidence may be materialized only when a configured support purpose exists, such as scholarship/support program eligibility, culture-responsive coordination, accessibility accommodation, safety planning, or legally required inclusive-education service. IP/community, medical, psychological, functional, accessibility, and accommodation files use `restricted_support_file` controls and require a purpose tag, role-scoped authorization, access audit, retention policy, and minimal verified-field promotion. These attributes must not be used for automated rejection, ranking, section assignment, billing, discipline, or public reporting unless a later approved rule explicitly permits the specific use.
- `admission_capacity_plans` owns effective-dated approved College intake limits by term and scope. Capacity scopes may be campus-wide, program-specific, year-level-specific, or delivery-setup-specific. The current campus value may be 100 active enrolled students, but scope and value are configuration, not platform constants.
- Capacity resolution returns every active matching plan as a stack. Payment may secure capacity only when every applicable plan has remaining room. Child caps may be stricter than the parent; the system must reject overlapping equal-scope plans that would make capacity resolution ambiguous.
- `admission_capacity_placements` distinguishes `tentative` pre-payment planning, `secured` payment-backed admission capacity, and `placed` final section/delivery assignment. Tentative placement grants no account/COR/class access, consumes no protected capacity, and expires at the earlier of the configured payment deadline, admission-window close, or manual Registrar cancellation with reason.
- Payment confirmation locks the resolved capacity-plan stack and atomically secures capacity with the immutable payment/ledger post. Idempotent retries cannot consume capacity twice. Section/group planning must cover the approved plan before payment opens, and final placement must respect section and delivery-group capacity separately from admission capacity.
- Runtime implementation currently enforces configured approved plans through `admission_capacity_plans` and `admission_capacity_reservations`. `AdmissionCapacityReservationService` resolves every matching approved plan, locks the stack during finance clearance, creates one `secured` reservation per enrollment/plan, increments `reserved_count` idempotently, and throws before handover when any configured plan is full. For legacy/local data with no approved plan rows, reservation is a no-op until the remaining readiness gate/admin setup makes capacity plans mandatory before payment opens.
- `EnrollmentReadinessService` must block payment clearance, payment-backed capacity security, and enrollment handover until the term has an active calendar/enrollment window, published admission offering and requirement policy, approved capacity plan, ready curriculum/subject-offering scope, planned sections and delivery groups, Registrar-confirmed subject/faculty assignment inputs, faculty availability or approved override, and a committed/published official schedule or documented institution-controlled scheduling exception.
- If an applicant pays and capacity is secured but no compatible final section/delivery placement remains because of institution-caused planning delay or scheduling failure, the system records `PendingInstitutionalPlacement` for Registrar resolution instead of reverting payment, rejecting the applicant, or marking the applicant noncompliant.
- `EnrollmentHandoverService` may activate only when admission, finance, secured-capacity, and compatible-placement gates are clear. It finalizes section/group assignment, writes canonical `enrolled`, updates counts once, activates the account, and enables COR/class-list eligibility in one transaction.
- If the institution cannot provide compatible placement after capacity was secured, the enrollment enters `pending_institutional_placement`; this is not applicant noncompliance and financial outcome is resolved by the effective disposition policy.
- Cancellation and withdrawal preserve applicant, checklist, upload, payment, and ledger history. They use typed causes and the financial disposition resolver; no service assumes blanket payment retention or refund.

Raw uploaded documents are preserved as the canonical evidence, while review decisions and approved verified fields are stored in structured database records. Uploaded content never becomes an official student value until an authorized staff review records the decision.

| Storage Layer | Stores | Purpose |
| --- | --- | --- |
| **Private File/Object Storage** | Original images/PDFs, payment screenshots, generated previews | Canonical evidence for review, audit, reprocessing, and dispute handling |
| **MySQL Metadata Tables** | Owner, document type, storage disk/path, MIME type, checksum, file size, upload status, review status | Fast workflow queries, RBAC filtering, audit joins, and lifecycle tracking |
| **MySQL Review Tables** | Review state, reviewer, timestamps, typed reasons, approved verified-field payload, and lifecycle history | Registrar review queues, audit, correction/rejection handling, and approved field promotion |
| **Domain Tables** | Verified profile fields, credited subjects, discount entries, payment references | Operational source of truth after staff verification |

**Normative Document-Class Matrix**:

| Storage class | Included evidence | Canonical record | Permitted derivatives | Required controls |
| --- | --- | --- | --- | --- |
| `credential_file` | PSA/birth certificate, Form 138, Form 137, TOR/grades, Good Moral, diploma/completion, Honorable Dismissal, ALS records, passport/visa | Versioned private source file plus metadata/checksum; accepted facts are copied only into verified domain fields | Preview/thumbnail only | Replacement history, source/provenance, staff review, temporary authorized preview |
| `official_transmission` | School-to-school Form 137/TOR/transfer credential or other regulator-permitted official transmission | Received artifact plus sender, channel, received time, provenance/signature evidence, and verification state | Optional | Requirement satisfaction references the transmission; applicant upload is not fabricated or required when this method is accepted |
| `identity_photo` | 2x2/recent ID photo | Private source image and versioned approved derivative | Disabled | Image access remains private; public use requires a separately generated purpose-bound artifact |
| `restricted_support_file` | Medical/psychological, disability/SEN, IP/community, immigration, and medical-clearance evidence | Versioned private source file | Disabled by default; explicit purpose approval required | Separate restricted permission, purpose limitation, access audit, minimal field promotion, retention/disposal schedule |
| `transaction_evidence` | Payment proof, externally issued receipt/reference image, and promissory attachment only if that review feature is later promoted | Private source file linked to an immutable transaction or approval record | Preview/thumbnail only | A file cannot confirm payment or lifecycle state; Accounting service remains authoritative |
| `generated_tala_artifact` | COR, assessment, SOA/payment acknowledgement | Immutable issuance snapshot and/or PDF with template version, issuer, issued time, checksum, subject/term/request, token, and lifecycle state | Preview/thumbnail only | Supersede/revoke instead of overwrite; verification and re-render reproducibility where applicable. Formal TOR, Form 137, report-card PDF, diploma, and full credential issuance/fulfillment are outside active TALA scope; finalized grade history remains an active structured record. |
| `structured_record` | Applicant, requirement status, enrollment, schedule, grades, ledger, holds | Authorized normalized database row plus audit evidence | Not applicable | A screenshot/PDF/export is never the operational source of truth |
| `import_source` | Curriculum, roster, fee, grade, enrollment, or legacy CSV/XLSX | Private source file plus checksum, uploader, template/parser version, validation report, and batch state | Parser output is provisional until commit | Zero-error/approved commit rules; normalized accepted rows become operational records |
| `processing_derivative` | Thumbnail or preview | Exact source-version-linked derivative | Regenerable | No independent authority; track source version and purge independently when policy permits |
| `physical_custody_record` | Original/certified evidence received or inspected without a scan | Structured custody/inspection event | None unless a separate scan is attached | Record method, actor, time, custody location/status, and return/transfer event; scan possession and physical possession are distinct |

`document_types` (or the equivalent normalized catalog) owns the default storage class, sensitivity, allowed MIME/extensions, maximum size, and verified-field schema. A versioned admission requirement may narrow accepted evidence methods, but it cannot weaken the class's security controls. `document_uploads` must reference the applicable document type, owner/context, source channel, source version, checksum, storage disk/key, detected MIME type, size, lifecycle state, and superseded upload when present. Restricted support files require a distinct permission boundary from ordinary document review.

Institutional forms and clearances use the class determined by their source: externally issued evidence is `credential_file`, a decision captured inside TALA is `structured_record`, and an official PDF/signature package issued by TALA is `generated_official_artifact`. One business concept may therefore have a structured decision plus an immutable issued artifact without duplicating authority.

**Processing Flow**:

1.  Validate the file type and size before accepting the upload.
2.  Store the original file on a private Laravel filesystem disk.
3.  Create a `document_uploads` row with file metadata, checksum, owner, selected document type, and initial status.
4.  Place the evidence in the authorized Registrar review queue.
5.  Show the original file, declared document type, source metadata, and applicant-entered data in Filament.
6.  Record the typed review decision and promote only staff-approved values into operational domain tables.

**Implementation Rules**:

-   Do not store full document binaries in MySQL.
-   Do not expose document files through public URLs.
-   Use private visibility and temporary signed URLs for previews/downloads.
-   Validate an allowlisted extension plus detected MIME/file signature; never trust the client `Content-Type` alone. Generate storage names, enforce size limits, and scan files for malware where the deployed infrastructure supports it.
-   Replacement uploads must preserve the original evidence and review history.
-   Critical academic, financial, and identity fields require human verification before they affect enrollment, billing, grades, or credentials.
-   Retention and disposal are driven by document class, active institutional/regulatory policy, unresolved holds, and audit requirements. Disposal removes both the object and non-required derivatives while preserving the minimum non-sensitive tombstone/audit evidence needed to explain the lifecycle.

**Cost and Complexity Trade-Offs**:

-   **Storage Cost**: Higher than text-only storage because original files are retained, but object storage is cheaper and easier to scale than database BLOB storage.
-   **Review Cost**: Human review requires staff time; use typed queues, clear evidence metadata, and bounded review actions to keep it operationally manageable.
-   **Maintenance Complexity**: The system must keep database rows and file storage synchronized. Add orphan-file reports and checksum checks instead of blind deletion.
-   **Retrieval Latency**: Raw files may load slower than DB rows. Keep workflow screens fast by querying metadata first and loading file previews through temporary URLs only when needed.

---

### 1.4 Architecture Rationale

#### 1.4.1 Unified Data (Monolith Strength)

By using a Monolithic Laravel core, the **Registrar** and **Accounting** modules share the *exact same* Database Models (`Student`, `Enrollment`, `Payment`). This makes the **“Unified Pipeline”** possible without complex API syncing.

#### 1.4.2 Decoupled Logic (EDA Strength)

“Internal Events” allow us to build an **Event-Driven System** without the complexity of external message brokers (Kafka/RabbitMQ). This satisfies the “Technical Complexity” constraint while keeping the code clean and scalable.

#### 1.4.3 Role-Based Views

`FilamentPHP` allows us to create one shared staff panel while strictly controlling visibility. A `Cashier` user simply sees a restricted menu compared to a `Registrar`, but they are in the same system.

---

### 1.5 Module-to-Tech Mapping

| Module | Primary Tech | Key Libraries | Key Features |
| --- | --- | --- | --- |
| Module 1 (Student) | **Laravel Livewire + TallStackUI + Tailwind + Alpine.js** | `tallstackui/tallstackui`, `alpinejs`, `erag/laravel-pwa` | PWA, Multi-Step Wizard, College modality selection (On-Site/Blended/Online), external-reporting-ready data collection, and automated College freshmen discount eligibility capture. **(Custom)** |
| Module 2 (Registrar) | FilamentPHP (Admin) | `filament/filament` | Student Record Management (Filament Data Table), Scheduling (Curriculum Import, Modality-Aware Conflict Detection), Manual Credit Evaluation, Account Archiver, manual Document Review, and filterable Enrolled Student Roster/export |
| Module 3 (Accounting) | FilamentPHP + Excel Export | `filament/filament`, `maatwebsite/excel`, `chillerlan/php-qrcode` | Payment Queues (E-Wallets, OTC, Screenshots), internal SOA/payment evidence, COR with QR Code, Export Reports |
| Module 4 (Faculty) | FilamentPHP | `filament/filament`, `maatwebsite/excel` | Grading Ecosystem, Manual INC Management, Grade Export, Modality-Aware Class Lists |
| Module 5 (Administration & Integration) | FilamentPHP + Laravel Mail + Audit Logs | `filament/filament`, `spatie/laravel-permission` | RBAC, User Mgmt, Dashboard, Email, Audit Trail, FAQ/Inquiry Management |

---

### 1.4 Benchmark-Hardened Technical Contracts for Feature Groups 3-11

These contracts summarize mandatory implementation boundaries across the remaining benchmark queue. Existing detailed contracts later in this TS remain authoritative where they are stricter. A documented contract is not proof of runtime completion; implementation requires migrations/models, service-owned behavior, policies, UI integration, and focused PHPUnit/Livewire/Filament tests.

| Feature group | Required technical contract | Minimum focused verification |
| --- | --- | --- |
| Enrollment, sectioning, finance clearance, inventory, and COR | One handover application service owns duplicate-safe person/student matching, prerequisite evaluation, row-locked capacity reservation, enrollment creation, section/delivery assignment, account activation, and post-commit events. The transaction is idempotent by applicant/term and fails closed on missing readiness. Roster queries are policy-scoped and generic exports are audited. COR generation reads canonical enrolled state and creates a versioned issuance snapshot. | Happy handover, each blocked prerequisite, duplicate retry, concurrent last-seat attempt, rollback after failure, role denial, roster filter/export field boundary, and COR unavailable before enrollment. |
| Scheduling and CP-SAT generation | A scheduling input builder creates an immutable versioned snapshot of term, projected-demand sections/delivery groups, subjects, meeting requirements, rooms, Registrar-confirmed eligible subject/faculty assignments, submitted availability, configured workload limits, and policies. An after-commit queued adapter invokes OR-Tools CP-SAT with hard constraints, limited approved objectives, a time limit, and deterministic metadata. Raw CP-SAT outcomes are normalized to `optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`; timeout remains separate diagnostic evidence. User-visible lifecycle is generated draft, Registrar review, and one transactional Registrar publication action that creates official meetings. A later correction creates and publishes a superseding version. | Constraint-unit tests; infeasible/unknown/model-invalid/timeout/no-publish tests; unassigned-demand no-publish proof; snapshot reproducibility; after-commit dispatch/retry proof; concurrent publication protection; hard-conflict denial for manual changes; superseding-version proof; published visibility only. |
| Finance, payments, ledger, SOA, and payment evidence | Assessment services resolve effective-dated fee, approved discount, scholarship, and downpayment policy into immutable charge projections. All confirmed payment channels call one ledger-posting service with unique provider/reference idempotency, database transaction, row locking, and auditable actor/channel evidence. Webhooks are signature-verified, stored, replay-safe, and processed asynchronously after commit. Balance/clearance are projections over immutable entries. SOA/payment acknowledgement PDFs use issuance snapshots and void/supersede state. Installments, promissory tracking, exam-access accommodation, and refund/disposition automation remain review candidates. | Assessment derivation, manual and webhook parity, invalid signature, duplicate callback/reference, pending/failed outcome, overpayment credit, reversal/adjustment, concurrent post, clearance recomputation, private authorization, PDF snapshot/void. |
| Faculty classes and grades | Published schedule/enrollment assignments scope faculty queries. A grade lifecycle service validates period/profile/range/completeness, locks submission/finalization, stores immutable grade history, and emits post-commit notifications. Correction requests snapshot old/new values and evidence; Academic Head decision and Registrar application are separately authorized transitions. | Assigned/unassigned access, unpublished exclusion, valid/invalid grade entry, incomplete submission, duplicate/concurrent finalization, finalized mutation denial, correction approve/reject/apply, old/new audit preservation. |
| COR and finance artifact verification | COR and SOA/payment acknowledgement issuance resolves eligibility/source state, renders from authoritative read models, stores immutable issuance metadata and checksum, and owns issued/void/revoked/superseded transitions. Files remain private unless intentionally public. QR payloads contain only opaque tokens or signed verification URLs; verification responses disclose minimal status metadata and are rate limited/audited where appropriate. Formal TOR, Form 137, report-card PDF, diploma, and full credential issuance/fulfillment are outside active scope. | Eligibility and source-state denial, deterministic source snapshot, private file access, token tamper/expiry/revocation, minimal disclosure, supersede history, unauthorized issue/release, and source-record change behavior. |
| Student Hub/PWA | Student-facing read models aggregate authoritative services under student ownership policies; Livewire components do not reproduce domain calculations. PWA caches only an approved read-only subset with version/freshness metadata, removes protected caches on logout/account denial, and disables mutations offline. Loading/error responses use safe messages and prevent duplicate submission. | Cross-student denial, applicant/inactive denial, unpublished data exclusion, balance/grade/COR service parity, offline read-only behavior, cache clearing, stale indicator, loading/duplicate-action protection, responsive accessibility smoke. |
| Student status and completion | Registrar-owned typed transition services validate allowed source/target states, effective dates, reasons, evidence references, notices, access effects, and role authority while preserving history. Readmission performs duplicate/provenance review. Graduation evaluation stores a reproducible snapshot of curriculum, finalized grades, deficiencies, and clearance results; completion and credential eligibility are separate transitions. Generic request records/routes and direct status editing are forbidden. | Allowed/invalid transitions, rollback/reactivation rules, duplicate legacy match, unauthorized action, missing reason/evidence, incomplete curriculum/hold denial, reproducible evaluation, concurrent status update, external-submission boundary. |
| Imports, exports, and reports | Import batches store private source, template/schema version, checksum, uploader, scope, parsed rows, validation results, preview state, commit state, and audit. Commit services use normalized identifiers, row-level validation, transaction/idempotency rules, and queued chunks only where atomicity semantics remain explicit. Export/report queries apply policies, field allowlists, filters, and audit; generated files are temporary or lifecycle-managed artifacts. | Wrong template/version, invalid headers/types/references, duplicate upload/commit, zero-error atomic rollback, authorized partial policy where explicitly allowed, large chunk behavior, export field leakage denial, external-format absence. |
| Attendance, behavior, discipline, and guidance | No enrollment/clearance gate may consume these domains until typed schemas, case/evidence ownership, privacy policy, resolution/appeal transitions, effective-dated rules, and authorized services exist. Sensitive records require least-privilege policies, private attachments, purpose-limited retention, and audit. | Until promoted, tests assert no hidden dependency blocks enrollment/progression. After promotion: role/privacy denial, evidence lifecycle, notice/response/appeal, policy versioning, resolution effects, retention/deletion, and no free-text-only automated sanction. |

Cross-cutting rule: lifecycle mutations must use service/action boundaries with authorization at entry, validation before mutation, transactions and row locks where shared capacity or money is affected, idempotency for retries/integrations, post-commit jobs/events, safe user errors, and immutable audit evidence. The detailed SDD slice owns concrete class/schema names when implementation begins.

## 2. Database Implementation References

### 2.1 Data Modeling Philosophy

**“Lean Relational” approach**. Use foreign keys for integrity, but keep high-traffic tables such as enrollments and transactions flat enough for speed.

The specifications remain the source of truth for business rules, role ownership, deadlines, locks, approvals, official-record behavior, and workflow boundaries. Migration files are the implementation source for table names, columns, indexes, foreign keys, and rollback behavior. Future schema changes must be added through new migrations and summarized in the implementation control log instead of duplicating table-by-table schema contracts here.

---

### 2.2 Implemented Foundation Schema References

| Area | Implementation File |
| --- | --- |
| Account metadata | `database/migrations/2026_05_12_055403_add_tala_account_fields_to_users_table.php` |
| Academic foundation | `database/migrations/2026_05_12_055403_create_academic_foundation_tables.php` |
| Faculty availability and assisted scheduling foundation | `database/migrations/2026_05_12_055403_create_scheduling_foundation_tables.php` |
| Activity log base table | `database/migrations/2026_05_12_055413_create_activity_log_table.php` |
| Activity log event column | `database/migrations/2026_05_12_055414_add_event_column_to_activity_log_table.php` |
| Activity log batch UUID column | `database/migrations/2026_05_12_055415_add_batch_uuid_column_to_activity_log_table.php` |
| Spatie permission package tables | `database/migrations/2026_01_27_015712_create_permission_tables.php` |
| Role and permission seed mapping | `database/seeders/DatabaseSeeder.php` |
| Migration decision and deferred scope | `00_Project_Documents/TALA-Foundation-Migration-Control-Log.md` |

Laravel baseline authentication, cache, session, queue, and job batch tables remain in the default `0001_01_01_*` migrations.

---

### 2.3 Migration Execution Gates

The following low-policy support tables now have migration files and are executable in the next migration wave:

- Laravel `notifications` using Laravel's standard database notifications table.
- `import_batches` for legacy import preview/commit auditability.
- `webhook_calls` for PayMongo webhook payload/header storage.

The following domains are covered by schema and service contracts in this TS. Whether a given local database has applied every migration is verified through `migrate:status`, not by hardcoded documentation counts:

- Enrollment/profile flow (`student_profiles`, `enrollments`, `enrollment_subjects`)
- Applicant intake staging (`applicant_intakes` plus applicant-linked `document_uploads` before handover)
- Financial flow (`fee_templates`, `ledger_entries`, `payment_attempts`, `payments`; promissory and installment tables remain review scope)
- Admission document review flow (`document_uploads`, requirement checklist state, and verified-field review records)
- Grade/correction flow (`grades`, `grade_corrections`)
- Student-status/COR flow (`student_status_transitions`, `program_shift_cases`, `shifting_credit_evaluations`, `cor_verifications`, `faq_entries`)

**Migration Status Boundary**:
- This TS defines the required schema contracts and relationships, not a live migration-status ledger.
- Current migration execution must be checked with `php artisan migrate:status --no-interaction` in the target environment.

---

### 2.4 Schema Boundary Rules

- Do not add table-by-table schema summaries back into this technical specification.
- Do not treat migration paths as business approval by themselves; business behavior remains governed by the Functional Specification and workflow sections of this Technical Specification.
- Use `decimal(12,2)` for money in future ledger/storage migrations and minor-unit integers only at payment gateway boundaries.
- Do not store online meeting URLs in scheduling tables unless the client later approves link tracking.
- `users.status` is account lifecycle/auth state only. Future student lifecycle state belongs on `student_profiles.operational_status`; `student_profiles.status_reason` is required only when `operational_status = 'Inactive'`.
- Use new migrations for future schema changes unless the project is intentionally reset locally before production.
- `users.first_name`, `users.middle_name`, `users.last_name`, and `users.suffix` are canonical person-name fields for staff, applicant, and student accounts. `users.name` remains a generated/composed display and search value for Filament tables, audit labels, exports, and legacy auth compatibility.

---

### 2.5 Detailed Business Data Dictionary

#### 2.5.1 Applicant Data Fields (Student Record and External Reporting Aligned)

| Field Group | Data Point | Type | Purpose / Validation |
| --- | --- | --- | --- |
| **Identity** | **LRN** | `string(12)` | External learner identifier where applicable. Exactly 12 digits, unique check |
|  | **Last Name** | `string(50)` | Alphabetic + hyphen + apostrophe only |
|  | **First Name** | `string(50)` | Alphabetic + hyphen + apostrophe only |
|  | **Middle Name** | `string(50)` | Alphabetic + hyphen + apostrophe only |
|  | **Extended Name** | `string(10)` | Suffixes (Jr., Sr., II, III, IV) — optional |
|  | **Birthdate** | `date` | Valid past date; age rules are governed by active College admission policy |
|  | **Place of Birth** | `string(100)` | City/Municipality, Province — student record/external reporting field |
|  | **Gender** | `enum` | Male / Female — student record/external reporting field |
|  | **Civil Status** | `enum` | Single / Married / Widowed / Separated / Annulled — default: Single |
|  | **Mother’s Maiden Name** | `string(100)` | Student identity/external reporting field |
| **Personal Contact** | **Home Address — Street** | `string(100)` | House/Unit No., Building, Street Name — student record/external reporting field |
|  | **Home Address — Barangay** | `string(50)` | Barangay name — student record/external reporting field |
|  | **Home Address — City/Municipality** | `string(50)` | City or Municipality — student record/external reporting field |
|  | **Home Address — Province** | `string(50)` | Province — student record/external reporting field |
|  | **Home Address — Region** | `string(50)` | Region (e.g., NCR, Region IV-A) — student record/external reporting field |
|  | **Home Address — Zip Code** | `string(4)` | 4-digit Philippine zip code |
|  | **Contact Number** | `string(13)` | Philippine mobile format (09XXXXXXXXX). Regex: `/^09\d{9}$/` |
|  | **Father’s Name** | `string(100)` | Full name — student record/external reporting field |
|  | **Father’s Occupation** | `string(50)` | Optional student record field |
|  | **Mother’s Occupation** | `string(50)` | Optional student record field |
|  | **Guardian’s Name** | `string(100)` | Required if applicant is a minor |
|  | **Guardian’s Contact Number** | `string(13)` | Same format as Contact Number |
|  | **Guardian’s Address** | `text` | Can be same as home address (checkbox to copy) |
| **Enrollment Context** | **School Year / Term** | `string` | Derived from system active configuration. Read-only for applicants; editable only by Registrar for backdated applications. |
| **Academic (College)** | **Course / Program** | `string(50)` | IT, BM, THM, etc. |
|  | **Year Level** | `enum` | 1st Year - 4th Year |
|  | **Admission Category** | `enum` | Freshman / Transferee / Returnee / Second Degree |
|  | **Credited Subjects** | `json` | Relevant only for transferees/irregular placement. Populated post-evaluation. |
| **Last School Attended** | **School Name** | `string(150)` | Required for Transferees — basis for F137 request |
|  | **School Address** | `text` | School location — transferee record/external reporting field |
|  | **Year Graduated / Last Year Attended** | `year` | e.g., 2024, 2023 — dropdown or text |
| **Modality** | **Learning Mode** | `enum` | **College**: `on_site`, `blended`, `online`. `blended` remains active in Phase 1 as a room-required schedule modality that uses on-site-style room, delivery-group, and faculty conflict checks; online meeting/link tracking and alternating online/on-site pattern modeling are out of scope. Modality is captured as a declared enrollment preference and finalized through Registrar assignment to a section delivery group. |
| **Account Name Storage** | **Canonical Name Parts** | `users.first_name`, `users.middle_name`, `users.last_name`, `users.suffix`; composed `users.name` | Intake forms store legal/display name parts separately. The model/service layer composes `users.name` for search/display compatibility. |
| **Discount Eligibility** | **Automated Freshmen Discount Flag** | `boolean` | Derived from intake data and validated by rules (`student_type = New` and College `year_level = 1st Year`) to trigger the 50% Tuition Fee discount. |

---

#### 2.5.2 Future `student_profiles` Schema Contract

Future student profile migrations must keep academic and financial context off the `users` table. `student_profiles` owns the student lifecycle and academic identity contract:

**Creation Timing**: A `student_profiles` row is created atomically during the Official Handover transaction (§3.3) when the applicant account transitions to an enrolled student. Applicant data lives in `users` for authentication/name fields and in `applicant_intakes` for student-profile/external-reporting data, duplicate-check evidence, applicant status, required-document lists, and Registrar review metadata until handover. The profile is never created for applicants who do not complete enrollment.

| Field Group | Required Contract |
| --- | --- |
| Identity | `user_id`, immutable `student_id`, unique `lrn` when applicable, legal name linkage through `users.first_name`, `users.middle_name`, `users.last_name`, `users.suffix`, and composed `users.name` |
| Academic context | `program_id`, College year level, curriculum/version context, current term context when needed |
| Lifecycle status | `operational_status` enum-like string with `Active`, `LeaveOfAbsence`, `Withdrawn`, `TransferredOut`, `Graduated`, `Inactive`, and `Archived`; Readmission and Reactivation are audited transitions back to `Active`, not permanent statuses |
| Status reason | `status_reason` required for every transition away from `Active` and every archive/reactivation correction |
| Financial summary | `current_balance decimal(12,2)` as a denormalized read model fed by ledger services, not by direct UI edits |
| Document flags | Physical receipt and staff review markers needed for admission, retention, grade, and COR gating |
| Audit timestamps | `created_at`, `updated_at`, plus workflow-specific timestamps such as `graduated_at`, `archived_at`, or `last_status_changed_at` when introduced |

Do not add academic status, balances, hard-copy flags, LRN, student ID, program, year level, or operational lifecycle fields to `users`. That table remains responsible for login identity, credentials, account availability, and authentication lifecycle.

#### 2.5.3 Financial Transaction Types (Enum)

| Type | Description |
| --- | --- |
| `assessment` | Initial Fee/Debit (Tuition, Lab Fees, Miscellaneous) |
| `payment` | OTC Payment, E-wallet (GCash Webhook validation) |
| `promissory_note` | Review candidate only. If promoted, records Accounting-reviewed promise/expiry/settlement evidence only; does not clear balance, enrollment, COR, class-list, or exam access by itself |
| `discount` | Automated or authorized Discount/Credit ledger entry (including the Freshmen Tuition discount) |
| `drop_fee` | Review-only typed assessment; unavailable until an effective-dated institution-approved and regulator-bounded withdrawal policy is promoted |
| `adjustment` | Manual Correction |

**Display Logic**: Portal groups transactions by type to show “Tuition vs. Misc vs. Discounts”

**Promissory Review Boundary**: Promissory-note automation is not active core sprint scope. If promoted later, it must remain Accounting-owned promise tracking only, must not clear balance/enrollment/COR/class-list/exam access, and must use typed lifecycle services rather than generic status editing.

**Exam Access Accommodation Review Boundary**: Exam-access accommodation automation is not active core sprint scope. TALA must not create an undocumented debt-based exam block, and promissory-note approval is not exam-access approval. If promoted later, decision responses must never expose evidence paths, certification references, balances, payment channels, or promissory amounts.

---

#### 2.5.4 Document Flags

| Flag | Type | Purpose |
| --- | --- | --- |
| `hard_copy_received` | Derived compatibility Boolean | Mirrors whether every required physical applicant-document item is received; per-item compliance rows are authoritative. |
| `review_status` | Enum | `uploaded`, `pending_registrar_review`, `registrar_approved`, `needs_correction`, `rejected` |
| `document_verified_fields` | Related rows or approved payload | Stores Registrar-approved values with reviewer and timestamp; applicant-declared values remain distinguishable from verified values. |
| `student_confirmed_at` | Timestamp | When the applicant confirmed the provisional extraction. |
| `student_confirmed_payload` | JSON | Snapshot of data at the time of student confirmation. |
| `registrar_reviewed_by` | Foreign Key (Users) | The staff member who performed the review. |
| `registrar_reviewed_at` | Timestamp | When the staff review occurred. |
| `registrar_approved_payload` | JSON | Snapshot of data explicitly approved during document review. |
| `document_type` | Catalog key | Normalized requirement-item type such as PSA, prior-school report card/Form 138, Diploma, F137, Good Moral, or another approved College admission evidence type. |
| `checksum` | String | Detects duplicate uploads and preserves source integrity |

---

### 2.6 Key Computed Logic

#### 2.6.1 Examination Access Boundary

Outstanding balance and promissory status do not deny an enrolled student's scheduled examination access. Digital exam-permit and accommodation automation remain review scope, not active core implementation. Finance collection, next-cycle enrollment clearance, and lawful record-release holds remain separate services. Faculty and class-facing UI must not expose delinquent lists or balance amounts.

#### 2.6.2 COR QR Code Verification Contract

**Official Mode**: Online verification is required for official authenticity checks. Offline/PWA COR copies are read-only convenience copies only; they cannot prove current validity because revocation, replacement, or supersession must be checked against the database.

**QR Payload Format**: The QR code encodes one absolute HTTPS verification URL generated from a named route, e.g. `cor.verify`. The URL contains an opaque random token as a path parameter and may also include Laravel's signed-route query parameters. It must not expose raw `Student_ID`, `Active_Term`, balances, ledger IDs, or a custom concatenated hash payload. There is no custom separator format.

**Route Contract**:

| Item | Contract |
| --- | --- |
| Route | `GET /verify/cor/{token}` |
| Route name | `cor.verify` |
| Auth | Public/guest route; protected by throttling and signed-route validation when a signed URL is used |
| Payload token | Opaque, random, database-backed COR verification token bound to one generated COR/version |
| Signing | Laravel signed URL validation using the application key; do not implement a separate exposed `Student_ID + Term + Hash` signature |
| Invalid signature | HTTP `403` with a user-facing invalid/expired verification page |
| Missing token/document | HTTP `404` with `status = not_found` |
| Valid lookup | HTTP `200` with one of `valid`, `superseded`, or `revoked` |

**Issuance Source Contract**: A COR token must be created only by the COR issuance service after the enrollment is canonical `enrolled` and the source snapshot is built from authoritative enrollment, student, term, section/delivery-group, schedule, and finance-clearance data. Token creation may happen in the same transaction as artifact issuance or in an after-commit job that writes one issuance record and one token idempotently.

**Artifact Metadata Contract**: The goal-state issuance record stores `document_type`, `student_profile_id`, `term_id`, `enrollment_id`, optional `document_request_id`, `template_version`, `source_snapshot_json`, `source_snapshot_checksum`, `file_disk`, `file_path`, `mime_type`, `reference_number`, `serial_number`, `issued_by`, `issued_at`, `state`, `revoked_at`, `revoked_by`, `revocation_reason`, and `supersedes_id`/`superseded_by_id` where applicable. Files use private storage by default; downloads stream through policy-checked controllers and must never expose raw storage paths.

**QR Generation Contract**: The QR renderer uses `chillerlan/php-qrcode` to render the verification URL as SVG or PNG for the PDF/template. The encoded value is the verification URL only. Do not encode JSON payloads containing student, grade, balance, ledger, or checksum values.

**Filament Resource Mapping**: `CorVerificationResource` is a COR Controls evidence surface. It registers list/view pages only and exposes controlled lifecycle actions for superseding or revoking tokens. It must not register generic create/edit page routes, create/edit header actions, delete actions, or forms for direct `student_profile_id`, `token`, `status`, `issued_at`, `expires_at`, or `revoked_at` editing. COR tokens are generated by COR issuance services. The final implementation replaces the unrelated legacy `manage-lis` check with a dedicated `manage-cor-verifications` permission. Supersede/revoke transitions are delegated to `CorVerificationLifecycleService`, which allows only valid lifecycle transitions, requires a typed non-empty revoke reason before setting `revocation_reason` / `revoked_at`, and records lifecycle activity. `CorVerification` owns approved status labels and badge colors so Filament filters and columns do not duplicate raw status literals.

**Response Body**: The HTML page is canonical for scanners. When `Accept: application/json` is sent, return:

```json
{
  "status": "valid",
  "document_type": "cor",
  "student_number": "SIA-2026-0001",
  "student_name": "Student Display Name",
  "term": "AY 2026-2027 / 1st Semester",
  "issued_at": "2026-05-18T09:00:00+08:00",
  "verified_at": "2026-05-18T09:05:00+08:00"
}
```

The response must not expose balances, payment channels, transactions, promissory details, internal ledger references, birthdate, LRN, or private document paths.



---

## 3. Module Implementation Details

### 3.1 Grading Calculation Engines (Logic Isolation)

To ensure consistency, grading calculations are isolated in dedicated **Service Classes**:

#### 3.1.1 College-Only Grading Boundary

Only College grading profile resolution is active in the current deployment. The grading implementation must not expose non-College grading branches through active Filament forms, validators, factories, seeders, tests, or Student Hub projections.

#### 3.1.2 College Engine

**Algorithm**: Average raw percentage scores first → round to nearest integer → transmute once at the end. The system **MUST NOT** average transmuted equivalents (see Deprecation Notice below).

> **Architecture Decision:** The code block below documents the raw-evidence grading profile used as the default contract example. The consolidated workflow describes a conflicting lecture/laboratory calculation and transmutation scale. Replace hardcoded policy with an effective-dated `grading_profiles` contract scoped by education level and optionally program/subject/term before changing calculations. Every grade sheet/import must snapshot its profile. No runtime or historical-grade migration is approved until the client selects the active College profile.

```php
class CollegeGradingService
{
    /**
     * SIA Standard Transmutation Table.
     * Maps the minimum raw percentage to the equivalent grade (1.00-5.00).
     * Keys MUST be ordered descending for correct range matching.
     * Scores below 74 fall through the loop and return 5.00 (Failure).
     *
     * @var array<int, float>
     */
    protected array $transmutationTable = [
        98 => 1.00,
        93 => 1.25,
        90 => 1.50,
        87 => 1.75,
        84 => 2.00,
        82 => 2.25,
        80 => 2.50,
        78 => 2.75,
        75 => 3.00,
        74 => 4.00,
    ];

    /**
     * Step 3 (Terminal Transmutation): Convert a single rounded raw percentage
     * to the 1.00-5.00 equivalent scale via the SIA mapping table.
     */
    public function transmute(int $roundedRaw): float
    {
        foreach ($this->transmutationTable as $minScore => $equivalentGrade) {
            if ($roundedRaw >= $minScore) {
                return $equivalentGrade;
            }
        }

        return 5.00;
    }

    /**
     * Full lifecycle: Compute the final subject grade from period raw scores.
     *
     * Step 1: Validate raw percentage scores as numeric values from 0 to 100.
     * Step 2: Calculate weighted mean (Prelim 30%, Midterm 30%, Final 40%), round half-up.
     * Step 3: Pass rounded integer through the transmutation table.
     *
     * @param  array<string, int|float>  $periodScores  e.g. ['prelim' => 85.5, 'midterm' => 88.0, 'final' => 90.3]
     * @return array{final_raw_average: int, equivalent_grade: float, remarks: string}
     *
     * @throws \App\Exceptions\InvalidGradeException if any score is null, non-numeric, below 0, or above 100
     * @throws \App\Exceptions\MissingGradePeriodException if prelim, midterm, or final is missing
     */
    public function calculateFinalGrade(array $periodScores): array
    {
        $requiredPeriods = ['prelim', 'midterm', 'final'];
        foreach ($requiredPeriods as $period) {
            if (!isset($periodScores[$period])) {
                throw new \App\Exceptions\MissingGradePeriodException("Missing required grade period: {$period}");
            }
        }

        foreach ($periodScores as $period => $score) {
            if (! is_int($score) && ! is_float($score)) {
                throw new \App\Exceptions\InvalidGradeException(
                    "Invalid raw score for period {$period}. Must be numeric 0-100."
                );
            }

            if ($score < 0 || $score > 100) {
                throw new \App\Exceptions\InvalidGradeException(
                    "Invalid raw score {$score} for period {$period}. Must be 0-100."
                );
            }
        }

        // SIA Weighted Formula: 30% Prelim, 30% Midterm, 40% Final
        $rawAverage = ($periodScores['prelim'] * 0.30) + 
                      ($periodScores['midterm'] * 0.30) + 
                      ($periodScores['final'] * 0.40);
                      
        $roundedRaw = (int) round($rawAverage, 0, PHP_ROUND_HALF_UP);
        $equivalentGrade = $this->transmute($roundedRaw);

        return [
            'final_raw_average' => $roundedRaw,
            'equivalent_grade' => $equivalentGrade,
            'remarks' => $equivalentGrade <= 3.00 ? 'passed' : 'failed',
        ];
    }
}
```

**College Period Payload Contract**: For the active College deployment, grade calculation accepts only the published grading-profile periods for the resolved class and term. The request/FormRequest layer must normalize labels to canonical College period keys before calling the service and reject missing periods, blank values, `null`, duplicate submissions, or unsupported period keys. The service must not average incomplete grade payloads.

**Deprecation Notice**: The system **MUST NOT** calculate the arithmetic mean of transmuted equivalents. Example: `(1.25 + 1.50) / 2 = 1.375` — this value cannot be resolved against the transmutation table without arbitrary secondary rounding, leading to data drift and registrar disputes. This legacy approach is **fully deprecated**.

#### 3.1.3 Grade Locking

```php
class GradeFinalizationService
{
    public function finalize(Grade $grade, User $actor): GradeFinalizationResult
    {
        if ($grade->is_finalized) {
            return GradeFinalizationResult::alreadyFinalized();
        }

        if (! $actor->isAssignedFacultyFor($grade)) {
            throw AuthorizationException::forUser($actor);
        }

        $grade->forceFill([
            'is_finalized' => true,
            'finalized_at' => now(),
            'finalized_by' => $actor->id,
        ])->save();

        return GradeFinalizationResult::finalized();
    }

    public function reopen(Grade $grade, User $actor, string $reason): void
    {
        if (! $actor->hasRole('academic-head')) {
            throw AuthorizationException::forUser($actor);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to reopen a finalized grade.',
            ]);
        }

        $grade->forceFill([
            'is_finalized' => false,
            'reopened_at' => now(),
            'reopened_by' => $actor->id,
        ])->save();
    }
}
```

**Finalization Authority**:

| Actor | Normal Finalize | Force Finalize / Reopen | Notes |
| --- | --- | --- | --- |
| Assigned Faculty | Yes | No | May finalize only their assigned section/subject grade sheet. |
| Academic Head | Yes, as audited override only | Yes | Requires non-empty reason and activity-log entry. |
| Registrar | No | No | Requirement: the institution workflow requires Registrar verification/return and official-record finalization. |
| System Super Admin | No | No | Technical/system role; no academic write authority. |

Already-finalized grades return a user-facing `Already finalized` notice, HTTP/API status `409` for API calls or an info notification in Filament/Livewire, and no database state change. The package lifecycle below is the target finalization architecture.

**Finalization Endpoint/Action Contract**:

| Action | Required Input | Success Response | Failure Response |
| --- | --- | --- | --- |
| Finalize grade sheet | `grade_sheet_id` or section/subject assignment ID; authenticated assigned faculty | `200`, `status = finalized` | `403` unauthorized, `409 already_finalized`, `422` validation incomplete |
| Academic Head force-finalize | Target grade sheet and non-empty reason | `200`, `status = finalized_by_override` | `403` unauthorized, `422` missing reason |
| Academic Head reopen | Target finalized grade sheet and non-empty reason | `200`, `status = reopened` | `403` unauthorized, `422` missing reason |

**Filament Resource Mapping**: `GradeResource` is the Academic Head Grade Oversight surface and must be list/view plus typed override actions only. It must not register generic create/edit page routes, delete actions, or raw forms for `prelim_grade`, `midterm_grade`, `final_grade`, `grade`, `is_finalized`, `finalized_by`, `finalized_at`, `reopened_by`, or `reopened_at`. Faculty grade encoding/finalization belongs to assigned class-list or enrollment-subject actions backed by `GradeEncodingService` and `GradeFinalizationService`. Academic Head force-finalize/reopen remains available only through action modals that require a non-empty reason and policy authorization.

**Faculty Class List Filament Mapping**: `EnrollmentSubjectResource` is the Faculty Class List / Academic Head submission-progress surface. It must register list/view pages only and must not expose generic create/edit page routes, delete actions, or raw forms for `enrollment_id`, `subject_id`, `section_meeting_id`, `units`, `lec_hours`, `status`, `is_dropped`, or `dropped_at`. Faculty mutation is limited to typed record actions backed by `GradeEncodingService` and `GradeFinalizationService`: `Encode Grade`, `Mark INC`, and `Finalize`. The encode modal must be College grading-profile aware and collect only the required College period fields for the resolved class and term, such as `prelim`, `midterm`, and `final` when that profile is active. Academic Head access to this resource remains read-only submission-progress oversight through `view-grade-submission-progress` or `view-global-records`; Academic Head grade mutation belongs to `GradeResource` override actions, not class-list row editing.

#### 3.1.4 Goal-State Grade Profile and Submission Contract

**Profile model**: `grading_profiles` stores a stable profile key/version, education level, effective dates/term scope, optional program/subject/delivery scope, period definitions, score ranges, weights, rounding, transmutation bands, remarks/pass rules, publication state, approver, and checksum. Resolution must produce exactly one published profile. New grade sheets fail closed on zero or multiple matches.

**Submission model**: `grade_submission_packages` identifies one term, section/delivery group, subject, faculty assignment, grading period/final submission type, roster snapshot checksum, grading-profile snapshot/checksum, state, submitter/time, Registrar reviewer/time, return reason, and finalization time. Package items snapshot each enrollment-subject, entered values, derived result, remarks, and source/evidence reference. Historical snapshots are immutable.

| State | Owner/action | Allowed transition | Record effect |
| --- | --- | --- | --- |
| `draft` | Assigned Faculty encodes against the published assignment/profile | `submitted` | Working values only; no official release. |
| `submitted` | Faculty submits a complete package | `returned` or `verified_finalized` | Faculty editing locked; immutable submission snapshot retained. |
| `returned` | Registrar records reason and affected rows | revised `draft`, then a new `submitted` snapshot | Prior rejected snapshot remains auditable. |
| `verified_finalized` | Registrar verifies the package | terminal except correction/supersession | Included grades become official and releasable atomically. |
| `superseded` | Authorized correction is applied | terminal | Original official values remain linked to replacement evidence. |

**Transactional boundary**: Submission locks the package and snapshots roster/profile/items in one transaction. Registrar verification locks the package and included grade rows, revalidates roster/profile/item completeness, records reviewer evidence, and finalizes all items atomically. Partial package finalization is prohibited. Idempotency keys prevent duplicate submit, verify, return, and correction application.

**Release boundary**: Student Hub, prerequisite/progression, and authorized internal academic views read only `verified_finalized` grade history. Draft, submitted, and returned values may support authorized early-advising views only when clearly labeled non-official. Formal TOR/Form 137/report-card PDF generation and full credential exports are outside active TALA scope; this boundary does not remove grade records or student grade viewing.

**Correction boundary**: A pre-final `returned` package is not a grade correction. After finalization, a correction records original and proposed period values, derived old/new result, reason, optional evidence, Academic Head approval/rejection, Registrar application, actor/time, source package, and supersession link. Direct update/delete of official history is forbidden.

**Migration Rule**: No historical-grade recalculation is allowed without an approved profile and migration rule.

#### 3.1.5 Template Generation

Uses `maatwebsite/excel` to generate pre-populated `.xlsx` files with `readonly` student columns.

#### 3.1.6 Grade Correction Implementation (Technical Mapping)

**DB Table**: `grade_corrections`

-   `id` (PK), `user_id` (student), `grade_id` (nullable FK), `subject_id`, `term_id`, `assessment_component`, `current_grade`, `requested_action`, `reason`, `attachment_paths` (nullable JSON array of private disk paths, max 3), `status` (enum: submitted, under\_review, resolved, rejected), `assigned_to` (staff user\_id), `creator_id`, `resolved_at`, `created_at`, `updated_at`

**Enums**:



```php
namespace App\Enums;

enum GradeCorrectionStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
}
```

**Model & Relations**:

-   `GradeCorrection`: belongsTo User (student), Grade, Subject, Term, assignedTo (User).

**Student Request API Contract**:

`POST /api/grade-corrections`

| Field | Type | Required | Contract |
| --- | --- | --- | --- |
| `grade_id` | integer | No | Required when an existing grade row is visible to the student; nullable only when the correction is against a missing grade. |
| `subject_id` | integer | Yes | Must belong to a subject/class visible to the authenticated student for the term. |
| `term_id` | integer | Yes | Must match a term where the student has enrollment or grade visibility. |
| `assessment_component` | string | No | Optional period/component label when the correction is narrower than the final subject grade. |
| `requested_action` | string, max 500 | Yes | Student's requested correction or action. |
| `reason` | string, max 250 | Yes | Student explanation. |
| `attachments[]` | files | No | Max 3 files; each max 5 MB; allowed MIME/extensions: `jpg`, `jpeg`, `png`, `pdf`; stored on a private disk. |

Attachments are always optional for concern/issue resolution. Validation must enforce type, size, and storage rules only when files are present; no workflow transition may require a file upload.

`current_grade`, `user_id`, `creator_id`, and initial `status = submitted` are server-derived. The client must not be trusted to submit the current grade value.

**Response Contract**:

| Endpoint | Success | Body |
| --- | --- | --- |
| `POST /api/grade-corrections` | HTTP `201` | `id`, `status`, `submitted_at`, `subject_id`, `term_id`, `timeline[]` |
| `GET /api/grade-corrections/{id}` | HTTP `200` | `id`, `status`, `subject`, `term`, `current_grade`, `requested_action`, `reason`, `attachments[]`, `timeline[]`, `resolved_at` |

**Error Contract**:

| Code | Meaning |
| --- | --- |
| `401` | Not authenticated. |
| `403` | Student/staff user cannot access the correction record or requested transition. |
| `404` | Correction, grade, subject, or term is not visible/found. |
| `409` | Invalid transition, terminal correction already resolved/rejected, or duplicate active correction for the same grade/subject/term. |
| `413` | Attachment payload exceeds file count or size limits. |
| `422` | Request validation failed. |

**Controllers / Filament Resource**:

-   Student: POST `/api/grade-corrections` (create), GET `/api/grade-corrections/{id}` (view status/timeline).
-   Staff (Filament): `GradeCorrectionResource` with filters (status, term, subject) and actions to transition status.

**Role and Transition Contract**:

| From | To | Actor | Guard |
| --- | --- | --- | --- |
| `submitted` | `under_review` | Registrar | Request is complete enough for review. |
| `submitted` / `under_review` | `rejected` | Registrar | Invalid, incomplete, duplicate, out of scope, or unsupported by records/review notes; rejection reason required. |
| `under_review` | `resolved` | Registrar | No grade change is needed, or Academic Head override approval has already authorized the official grade change. |

Faculty raw-computation verification is handled as an internal Registrar review note, attachment, or timeline entry while the correction remains `under_review`. It must not create a separate student-visible `for_faculty` status. Any correction that changes an official/finalized grade must be approved by the **Academic Head** through the override policy in §3.1.3 before the Registrar records the correction as `resolved`. The Academic Head approval is an audited action linked to the correction ticket; it does not require adding a separate public status.

**The system Hardening Mapping**: `GradeCorrectionResource` implements the in-system approval path and must not treat Registrar-recorded offline approval as valid. `grade_corrections` stores `academic_head_review_status`, `academic_head_reviewed_by`, `academic_head_reviewed_at`, and `academic_head_review_note`. Official/finalized grade changes require `GradeCorrectionService::approveOfficialGradeChange()` before `GradeCorrectionService::resolveWithGradeChange()` can apply corrected values. Academic Head rejection uses `GradeCorrectionService::rejectOfficialGradeChange()` and rejects the correction without mutating the grade. The Registrar resolution modal remains College grading-profile aware after approval and collects only the permitted College period inputs for the resolved profile, such as `college_prelim`, `college_midterm`, and `college_final` when that profile is active. `GradeCorrectionService` then derives `prelim_grade`, `midterm_grade`, `final_grade`, `grade`, `remarks`, `is_inc`, and `inc_expires_at` through the College grading service and rejects direct `grade`, `final_grade`, or `remarks` override payloads.

`GradeCorrectionResource` must be registered as a list/view lifecycle surface only. It must not register generic create/edit page routes, create/edit/delete header actions, or a raw form for direct `user_id`, `current_grade`, `attachment_paths`, `status`, `assigned_to`, Academic Head review metadata, `creator_id`, or `resolved_at` mutation. The student request API and `GradeCorrectionService::submit()` own ticket creation and server-derived fields; Registrar and Academic Head table actions own the allowed transitions.

**SLA Enforcement (Background Jobs)**:

-   `SLAWatcherJob`: Scheduled nightly.
    -   Finds `submitted` requests > 3 working days: Escalates to **Academic Head** (Notification).
    -   Finds `under_review` requests > 10 working days: Escalates to **Academic Head** (Notification).

**Audit Integration**:

-   Final grade edits still require **Academic Head Override** per §3.1.3. When the **Academic Head** authorizes an override, the `GradeChange` audit record links to the `grade_correction_id` to maintain a complete paper trail.

#### 3.1.6 Faculty Academic Advising Status API & Service

**Purpose**: Provide a performant, privacy-preserving API used by the Faculty class list modal (Functional Spec §7.1.4) for student advising. Computes an advisory status from current-term grades without exposing GPA.

**Enum**:



```php
namespace App\Enums;enum AcademicAdvisingStatus: string{    case NotAvailable = 'not_available';    case Good = 'good';    case Watch = 'watch';    case Priority = 'priority';}
```

**Service**: `AcademicAdvisingStatusService`



```php
namespace App\Services;

use App\Enums\AcademicAdvisingStatus;
use App\Models\Grade;
use App\Models\User;

class AcademicAdvisingStatusService
{
    /**
     * @return array{status: AcademicAdvisingStatus, reasons: string[]}
     */
    public function compute(User $student, int $termId): array
    {
        $grades = Grade::query()
            ->whereHas('enrollment', fn ($query) => $query
                ->where('user_id', $student->id)
                ->where('term_id', $termId))
            ->get();

        $hasActiveInc = $grades->contains('is_inc', true);
        $failedSubjects = [];
        $lowPassSubjects = [];

        foreach ($grades as $grade) {
            if ($grade->grade === null) {
                continue;
            }

            if ($grade->grade < 75) {
                $failedSubjects[] = $grade->subject->code;
            } elseif ($grade->grade <= 79) {
                $lowPassSubjects[] = [
                    'subject' => $grade->subject->code,
                    'grade' => $grade->grade,
                ];
            }
        }

        if ($hasActiveInc || count($failedSubjects) > 0 || count($lowPassSubjects) >= 2) {
            return ['status' => AcademicAdvisingStatus::Priority, 'reasons' => []];
        }

        if (count($lowPassSubjects) === 1) {
            return ['status' => AcademicAdvisingStatus::Watch, 'reasons' => []];
        }

        return $grades->whereNotNull('grade')->isNotEmpty()
            ? ['status' => AcademicAdvisingStatus::Good, 'reasons' => []]
            : ['status' => AcademicAdvisingStatus::NotAvailable, 'reasons' => []];
    }
}
```

**API**: `GET /api/faculty/students/{student_id}/advising-status`

**Request Contract**:

| Input | Type | Required | Notes |
| --- | --- | --- | --- |
| `student_id` | integer route parameter | Yes | Must resolve to a student visible to the requesting faculty. |
| `term_id` | integer query parameter | No | Passed when the modal opens from a specific class/section view. Must match a term taught by the requesting faculty. |

-   Returns: `advising_status`, `status_reasons`, `enrollment_status`, `current_term_subjects`, `prerequisite_status`, `year_grade_level`, `modality`, `enrollment_history`.
-   **Excluded**: GPA, LRN, birthdate, balances, transactions, discounts, promissory details.

**Term Selection Contract**:

1. If `term_id` is present from the viewed class/section context, compute against that viewed term.
2. Otherwise, compute against the configured active term for the student's education level.
3. If neither a viewed term nor an active term exists, return `advising_status = "not_available"` and do not silently fall back to the latest historical term.

**Response Contract**:

| Field | Type | Nullable | Notes |
| --- | --- | --- | --- |
| `advising_status` | string enum | No | `not_available`, `good`, `watch`, or `priority` |
| `status_reasons` | array<int, string> | No | Empty array when no risk reason exists |
| `enrollment_status` | string | Yes | Enrollment state class short name or legacy import status; `null` when no current enrollment exists |
| `current_term_subjects` | array<int, array{subject_code: string, subject_name: string, grade: int|float|null, is_inc: bool}> | No | Uses only subjects visible to the requesting faculty |
| `prerequisite_status` | string enum | No | `not_evaluated`, `complete`, `blocked`, or `missing_history` |
| `year_grade_level` | string | Yes | Display label from the student profile |
| `modality` | string enum | Yes | `modular`, `online`, or `on_site` |
| `enrollment_history` | array<int, array{term_id: int, term_name: string, status: string}> | No | Summary only; excludes GPA and financial data |
| `term_context` | array{term_id: int|null, source: string} | No | `source` is `viewed_class`, `active_term`, or `none` |

Successful responses return HTTP `200`. Unauthorized faculty receive `403`. Unknown students return `404`. A valid student without an advising term returns `advising_status = "not_available"` with empty arrays and `term_context.source = "none"`.

**Query Notes**:

-   Excludes sensitive fields at the query level (e.g., `current_balance`, `lrn`, `birthdate`).
-   Joins `student_profiles`, `enrollments`, and `grades` efficiently.

**Filament Implementation**:

-   `FacultyAdvisingStatus` Action (Infolist modal) calls the service.
-   **RBAC Policy**: `viewAdvisingStatus(User $faculty, User $student)` ensures the faculty member actively teaches the student this term.

---

### 3.2 Account Lifecycle & Security (Staff/HR)

#### 3.2.1 Status-Based Middleware



```php
class CheckUserStatus
{
    public function handle($request, Closure $next)
    {
        if (auth()->check() && auth()->user()->status !== 'active') {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/access-denied')
                ->with('error', 'Your account is not active. Please contact the school office.');
        }

        return $next($request);
    }
}
```

**Protected Access Contract**: All authenticated protected-area routes require `users.status = 'active'`. This blocks `pending`, `action_required`, `for_evaluation`, `approved`, `unclaimed`, `inactive`, `dropped`, `archived`, and any future non-active account state from staff dashboards, staff panels, Student Hub pages, and protected API routes. Public application, applicant progress/status, claim-account, password reset, email verification, public FAQ, admission requirements, and PayMongo webhook routes are excluded because they are applicant/public/recovery or integration flows and expose only their scoped workflow.

#### 3.2.2 Audit Trait



```php
trait Auditable{    public static function bootAuditable()    {        static::creating(function ($model) {            $model->creator_id = auth()->id();        });                // Prevent ON DELETE CASCADE on user-linked history        // Historical records must persist even if user is archived    }}
```

Models like `Grade` and `Transaction` use a `creator_id` foreign key. To prevent “orphaned” records, database-level `ON DELETE CASCADE` is **STRICTLY PROHIBITED** on user-linked history.

#### 3.2.3 Role Flushing

The `ArchiveUser` service class uses `$user->syncRoles([])` to ensure no lingering permissions exist in the cache.



```php
class ArchiveUser
{
    public function execute(User $user, string $reasonDocument): void
    {
        DB::transaction(function () use ($user, $reasonDocument) {
            DatabaseSessionHandler::deleteByUserId($user->id);

            $user->syncRoles([]);

            $user->status = 'archived';
            $user->archived_reason = $reasonDocument;
            $user->archived_at = now();
            $user->save();
        });
    }

    public function restore(User $user): void
    {
        $user->status = 'active';
        $user->archived_at = null;
        $user->archived_reason = null;
        $user->save();
    }
}
```

**Archive Input Contract**: `$reasonDocument` is a required non-empty audit summary string stored in `users.archived_reason`. If HR uploads supporting evidence, the file is stored on a private disk and linked through activity-log context until a dedicated HR evidence table is approved. `restore()` reactivates the account only; roles are not restored automatically and must be manually reassigned by a System Super Admin.

**Staff User Edit Contract**: `UserPolicy::update()` authorizes only `manage-users` actors editing a different, non-archived staff record. Archived accounts are restored only through the audited Restore Account action, not through direct edit routes. The current actor's own account/role/status is not editable through Staff User Management to avoid self-lockout and unreviewed privilege changes. Direct staff create/edit status choices come from `User::staffEditableStatusOptions()` and are validated to `active` or `inactive` only; `archived`, applicant, student, and future workflow statuses must not be direct form options. Staff role choices for direct assignment and restore come from `User::staffRoleOptions()` so the UI, restore action, and panel-access rule share the same approved staff-role set. `UsersTable` must keep Archive/Restore as typed modal actions only and delegate persistence to `UserAccountLifecycleService`; the service locks the target account, authorizes `archiveStaffAccount` or `restoreStaffAccount`, validates archive reason length and approved restore role, blocks duplicate/invalid lifecycle states, clears roles on archive, assigns exactly one role on restore, and records `staff_account_archived` or `staff_account_restored` activity.

**RBAC Matrix Filament Contract**: `RoleResource` is list-only for Phase 1. It may show role names, guard, and seeded permission badges, but it must not register create/edit routes, `CreateAction`, `EditAction`, stale create/edit page classes, or a `Select::make('permissions')` relationship editor. `RoleResource::canCreate()` must return `false` even if the policy also denies creation, so the UI contract is explicit. Role/permission changes are release-controlled through seeders/config/code plus regression tests.

---

### 3.3 Atomic Enrollment & Account Handover



```php
class GenerateStudentAccount
{
    public function handle(Applicant $applicant, Enrollment $enrollment): void
    {
        DB::transaction(function () use ($applicant, $enrollment) {
            $studentId = $this->generateStudentId();
            $tempPassword = Str::random(12);

            $applicant->forceFill([
                // Legal-name parts are already captured during intake; users.name is composed from them.
                'username' => $studentId,
                'email_verified_at' => null,
                'status' => 'active',
                'password' => Hash::make($tempPassword),
            ])->save();

            StudentProfile::create([
                'user_id' => $applicant->id,
                'student_id' => $studentId,
                'lrn' => $applicant->lrn,
                'program_id' => $enrollment->section->program_id,
                'year_level' => $enrollment->year_level,
                'modality' => $enrollment->modality,
                'operational_status' => 'Active',
            ]);

            DatabaseSessionHandler::deleteByUserId($applicant->id);

            Mail::to($applicant->email)->send(
                new WelcomeStudentEmail($applicant, $studentId, $tempPassword)
            );
        });
    }

    private function generateStudentId(): string
    {
        $year = date('Y');
        $sequence = DB::table('student_profiles')
            ->whereYear('created_at', $year)
            ->lockForUpdate()
            ->count() + 1;

        $studentId = sprintf('%s-%04d', $year, $sequence);

        while (DB::table('student_profiles')->where('student_id', $studentId)->exists()) {
            $sequence++;
            $studentId = sprintf('%s-%04d', $year, $sequence);
        }

        return $studentId;
    }
}
```

**Trigger**: Documents Verified (Physical) == true AND Payment == Confirmed

**Output**:

1.  Generates `Student_ID`
2.  Creates one `student_profiles` row atomically with at minimum `user_id`, immutable `student_id`, `lrn` when applicable, `program_id`, `year_level`, `modality`, and `operational_status = 'Active'` (see §2.5.2 creation timing contract)
3.  Sets `users.status = 'active'` for account access. Student lifecycle state is stored separately on `student_profiles.operational_status`.
4.  Emails credentials to user
5.  Invalidates old “Applicant” session

---

### 3.4 FCFS Sectioning (Race Condition Prevention)



```php
class EnrollmentService
{
    public function enrollInSection(Student $student, Section $section): void
    {
        DB::transaction(function () use ($student, $section) {
            $lockedSection = Section::where('id', $section->id)
                ->lockForUpdate()
                ->first();

            if ($lockedSection->enrolled_count >= $lockedSection->max_seats) {
                throw new SectionFullException("Section {$lockedSection->name} is full");
            }

            Enrollment::create([
                'user_id' => $student->id,
                'section_id' => $section->id,
                'term_id' => Term::active()->id,
                'modality' => $student->profile->modality,
                'status' => PendingPayment::class,
            ]);
        });
    }
}
```

**Status Contract**: `enrollments.status` uses the `EnrollmentState` model-state classes defined in §3.12.1. Literal strings such as `'enrolled'`, `'approved'`, or `'pending'` are not authoritative state values for new enrollment records.

**Section Capacity Contract**: `sections.max_seats` is editable by authorized Registrar/setup staff, but the approved hard maximum is **30 heads**. New sections default to 30. A section capacity edit must reject values above 30 and must reject decreases below `sections.enrolled_count`. The previous 10% overflow/PIN model is outside the approved capacity policy and must not be implemented unless a later exception policy is approved.

**Student Section Assignment Contract**: Student-to-section assignment is an admin-owned operation for Phase 1. `StudentEnrollmentService` or any future enrollment finalization service must assign only to an existing term-scoped section and must lock the selected section and delivery-group rows before checking actual assigned capacity. It must reject full sections instead of auto-overflowing, auto-balancing across alternate sections, or creating a new section. An admission-capacity reservation is a plan-level institutional commitment, not a section hold, and remains absent from COR, schedules, class lists, and enrollment assignment columns until actual placement. If compatible section capacity is missing for a valid reservation, the backend records `pending_institutional_placement`; it must not silently create a section or blame the applicant.

**Filament Configuration Target**: `EnrollmentResource` remains list/view with typed actions only. The profile-wide hard-copy receipt action must be replaced by per-requirement evidence/receipt actions backed by the applicant checklist and admission/retention services. Accounting payment confirmation secures capacity but must not activate the applicant while an admission gate or placement remains unresolved. Retention undertakings appear as itemized obligations and do not become a raw status-edit control. Generic create/edit/status/compliance mutation remains forbidden. A separate read-only `EnrolledStudentRosterResource` (or purpose-built list page) exposes required term plus optional College program, year level, section, modality, and student-type filters, with an audited Filament v5 `ExportAction` limited to CSV and XLSX; it has no create/edit/delete or external-status mutation action.

### 3.4.1 Prerequisite Validation (INC Block)



```php
class Grade extends Model
{
    /**
     * Derive department from the enrollment's section -> program chain.
     * Eager-load `enrollment.section.program` to avoid N+1 in loops.
     */
    public function getDepartmentAttribute(): string
    {
        return $this->enrollment->section->program->department;
    }

    public function isPassingFinalGrade(): bool
    {
        if ($this->is_inc) {
            return false;
        }

        return $this->department === 'college'
            ? $this->grade <= 3.0
            : $this->grade >= 75;
    }
}

class PrerequisiteValidator
{
    public function evaluate(Student $student, Subject $targetSubject): PrerequisiteValidationResult
    {
        foreach ($targetSubject->prerequisites as $prerequisite) {
            $grade = $student->latestFinalizedGradeForSubjectOrEquivalent($prerequisite);

            if (! $grade) {
                return PrerequisiteValidationResult::blocked('missing_history', $prerequisite);
            }

            if ($grade->is_inc) {
                return PrerequisiteValidationResult::blocked('active_inc', $prerequisite);
            }

            if (! $grade->isPassingFinalGrade()) {
                return PrerequisiteValidationResult::blocked('failed', $prerequisite);
            }
        }

        return PrerequisiteValidationResult::passed();
    }
}
```

**Prerequisite Edge-Case Contract**:

| Case | Required Behavior |
| --- | --- |
| Repeated subject | Use the latest finalized attempt for that subject. A later finalized passing attempt satisfies the prerequisite even if an older attempt failed. |
| Approved equivalent subject | Treat as satisfying the prerequisite only if the equivalency is configured by Registrar/Academic Head and the equivalent subject has a finalized passing grade. |
| Active INC | Blocks enrollment in downstream subjects until resolved through the authorized grading workflow or an effective institutional policy. |
| Missing historical grade | Blocks enrollment as `missing_history` unless the Registrar applies an audited prerequisite override. |
| Registrar override | Requires reason, target subject, prerequisite subject, actor ID, and activity-log entry; it does not change the historical grade itself. |

**Subject Suggestion Backend Contract**: `App\Actions\Enrollment\SubjectSuggestionService` is the current prerequisite-aware suggestion contract for irregular/transferee enrollment assistance. It accepts an `Enrollment`, resolves the applicable curriculum from the assigned section or active program curriculum, scopes current subjects by `year_level` and `curriculum_period`, and returns structured `suggested`, `back_subjects`, `blocked`, `already_passed`, `setup_blockers`, and `summary` arrays. It consumes finalized grade rows from the student's enrollment history plus active INC rows, uses the latest relevant attempt per subject, and emits blocker reasons `missing_history`, `failed`, or `active_inc`.

**Limitation**: Direct prerequisite relationships are enforced through the `prerequisites` table and `Subject::prerequisites()` relationship. Approved equivalent or credited-subject satisfaction must remain blocked as `missing_history` until controlled Registrar/Academic Head equivalency or credit-evaluation records exist. The service does not create `enrollment_subjects`, perform 30-unit load/summer splitting, or bypass Registrar approval; those remain enrollment finalization concerns.

`canEnroll()` callers should consume the structured `PrerequisiteValidationResult` instead of a bare boolean so the UI can show `missing_history`, `active_inc`, `failed`, or `override_required` without guessing.

---

### 3.5 Database Queue and Scheduled Work

Laravel's database queue is the approved runtime for the current deployment. Queue workers are persistent deployment processes, not staff-facing features.

| Work item | Trigger | Queue / behavior |
| --- | --- | --- |
| PayMongo webhook processing | Valid callback stored after signature verification | High priority; idempotent; retries rethrow failures and never duplicate ledger effects. |
| Schedule solver dispatch | Registrar creates a ready generation run | `scheduling`; dispatched after commit; immutable snapshot; bounded timeout and retry/backoff. |
| Critical lifecycle notifications | Approved domain transition | Default; owner-scoped payload; no sensitive values in queued data. |
| Controlled import processing | Authorized commit when batch size requires async work | Default/low; importer-specific idempotency and explicit atomicity rules. |
| Retention-document monitoring | Approved scheduler event | Marks due undertakings and applies only approved documentary/next-cycle holds. |
| Term close | Manual Registrar action | May queue for off-hours; idempotent per term; never resets student profiles/accounts. |

Document-review and staff operational queues are database queries over lifecycle state, not Laravel worker queues. Automatic pending-payment rejection, installment processing, promissory processing, and INC expiry jobs have no active schedule unless their underlying review feature is separately approved.

**Worker Contract**:

- Use `QUEUE_CONNECTION=database` in local, acceptance, and initial deployed environments.
- A deployment process monitor runs `php artisan queue:work database` and restarts workers after failure or deployment.
- Jobs define bounded attempts, backoff, timeout, failure visibility, and idempotency appropriate to their side effects.
- A job timeout remains lower than the queue connection's `retry_after` value.
- Jobs that depend on newly committed rows dispatch after commit.
- Failed jobs remain inspectable and retryable only after the underlying cause is understood.

**Scheduler Contract**:

The environment invokes Laravel's scheduler every minute. Only approved jobs are registered in `routes/console.php`; removed or review-scope workflows have no scheduled event.

---

### 3.6 Explicit Term Context and Capacity Integrity

Term-scoped workflows receive an explicit term from the route, authenticated workflow context, selected staff filter, or service command. The system must not apply a global Eloquent scope that silently limits every enrollment, payment, grade, or schedule query to one active term. Historical records remain authorized and queryable.

Reusable query scopes such as `forTerm(Term|int $term)`, policy-gated staff filters, and service-owned current-term resolution may reduce duplication without hiding history. Missing or ambiguous term context fails safely for mutations.

Section capacity is enforced inside the authoritative enrollment/placement transaction:

1. Lock the target section and applicable capacity records.
2. Re-read authoritative enrolled/secured placement state.
3. Reject when the approved maximum would be exceeded.
4. Apply placement/enrollment and any retained counter in the same transaction.
5. Preserve idempotency for retries and concurrent handover.

An Eloquent observer alone must not increment/decrement `enrolled_count`. A retained counter is a transactionally maintained optimization that can be reconciled against canonical enrollment/placement rows; otherwise use indexed count queries. Lists use eager loading, selected columns, pagination, and indexes on common term/status/relationship filters.

---

#### 3.6.1 Digital Faculty Availability and Assisted Scheduling

**Purpose**: Replace brittle faculty availability Excel intake with authenticated, term-scoped faculty self-service availability and Registrar-controlled subject/faculty assignment plus draft schedule generation.



**Faculty Availability Cadence Contract**: Availability is collected once per faculty per configured College scheduling term. The Academic Year is not the availability submission scope. A College faculty member normally submits per semester, plus summer if a summer term is opened. The backend must reject duplicate submitted/locked availability for the same `faculty_id` and `term_id`; approved late revisions must use versioned change-request records rather than mutating the original locked submission.



**Constraint Boundary**: The scheduling architecture extends solver snapshots, solver runtime, Laravel ingestion, publication validation, Filament review actions, and tests for section delivery groups, delivery patterns, delivery-group capacity, scoped curriculum readiness, Registrar-confirmed subject/faculty assignment, and weekly contact hours. The approved hard constraints are: faculty eligibility, submitted availability, configured maximum weekly workload, faculty time overlap, section/delivery-group time overlap, room conflict/capacity/type, missing Registrar-confirmed faculty assignment, invalid school calendar day/time, missing required curriculum scope, missing required delivery group, and invalid room requirement. Lunch-break blocking, max back-to-back load, preference weighting, and any default workload value remain configurable or later optimization inputs until exact values and executable tests are approved.


**Approved Rescue Scheduling Architecture**: Automatic schedule generation is implemented as deterministic cloud optimization through an IAM-private Google Cloud Run service running Google OR-Tools CP-SAT. Vertex AI is explicitly not the primary scheduler for Phase 1 because schedule generation requires hard-constraint satisfaction, not ML prediction. Laravel remains the system of record, validator, review surface, and final committer.

**Cloud Solver Security Contract**: The Cloud Run solver service must be deployed with authentication required. It must not allow unauthenticated public invocation. Laravel must invoke the solver with a Google-signed ID token whose target audience is the Cloud Run service URL. The invoking principal must be a dedicated scheduling service account with only the Cloud Run Invoker role on the solver service; unrelated integration credentials must not be reused.

**Section and Delivery-Group Planning Contract**:
- Automatic scheduling is section/delivery-group driven. `sections` and `section_delivery_groups` are created before solver dispatch and are treated as immutable input for that generation run.
- After term and curriculum readiness, projected-demand section/delivery-group planning and room setup may proceed in parallel with faculty availability collection. Registrar subject/faculty assignment waits for both branches; solver dispatch waits for all hard inputs.
- `sections` are academic parent records: term, program, curriculum, College year level, curriculum period, name, and total section capacity.
- `section_delivery_groups` are operational scheduling/enrollment records: parent section, delivery pattern/version, modality, delivery setup name, capacity, room requirement, optional fixed room, and status.
- The solver does not create, split, merge, or rebalance sections or delivery groups, and it does not choose teaching subjects for faculty. Registrar/setup staff confirm the subject/faculty assignment input for each planned section-delivery-group-subject demand before solving; CP-SAT places the confirmed demand into a room where required, day, and time.
- Each schedulable demand must have `section_id`, `section_delivery_group_id`, `term_id`, `program_id`, `curriculum_id`, `year_level`, `curriculum_period`, delivery pattern, modality, capacity data, and room when required.
- Laravel derives curriculum demand by matching `sections.curriculum_id`, `sections.year_level`, and `sections.curriculum_period` to curriculum scopes marked `ready_for_scheduling`. It must not infer demand from section names.
- If a year level or delivery setup needs more capacity, Registrar/setup staff create or adjust another section/delivery group first and rerun readiness. The solver only schedules records that already exist in the snapshot.

**New Scheduling Data Contracts**:

| Table / Concept | Required Contract |
| --- | --- |
| `delivery_patterns` | Versioned reusable rules for days, modality, subject routing, enforcement level, and default scheduling behavior. Used records are frozen; changes require cloning a new version. |
| `section_delivery_groups` | Belongs to a section; stores modality/delivery pattern, delivery setup label, capacity, assigned count, optional fixed room, status, and audit metadata. |
| `enrollments.section_delivery_group_id` | Nullable until sectioning; required once a student is assigned to a section/delivery group for the term. |
| `schedule_draft_rows.section_delivery_group_id` | Required for every draft row tied to a delivery group. |
| `section_meetings.section_delivery_group_id` | Required for every official schedule row tied to a delivery group. |
| `curriculum_readiness_scopes` | Explicit current-state table keyed by `curriculum_id + year_level + curriculum_period`; `program_id` is derived through `curriculums.program_id`. Statuses are `needs_review`, `ready_for_scheduling`, and service-derived `blocked`. Scheduling can use only `ready_for_scheduling` scopes. Transition history is written to `activity_log`. |
| `curriculum_subjects` scheduling fields | Stores scheduler-facing offering fields: `weekly_contact_hours`, `academic_subject_type`, `scheduling_group`, and nullable constrained `delivery_rule_override`. `subjects.lec_hours` may remain for backward compatibility/display, but solver demand must use `curriculum_subjects.weekly_contact_hours`. |

**Accuracy / Validity Contract**:
- Solver target: greater than 98% auto-assignment coverage for feasible inputs.
- Publication target: 100% hard-constraint validity.
- Rows with missing faculty assignment, faculty ineligibility, missing/outside availability, configured workload overflow, faculty conflict, room conflict, section/delivery-group conflict, invalid calendar day/time, missing required room, fixed-room mismatch, section capacity overflow, delivery-group capacity overflow, or missing curriculum readiness must be stored as draft conflicts and must not be published.
- Pre-dispatch readiness is enforced by `TermSchedulingReadinessService`: every section-delivery-group-subject demand must come from a `ready_for_scheduling` curriculum scope, must have a Registrar-confirmed subject/faculty assignment, and must have submitted or locked availability evidence for the assigned faculty before solver dispatch. A demand without confirmed assignment or assigned-faculty availability blocks generation rather than producing a known-impossible solver run. Missing availability for non-assigned faculty is not a generation blocker.
- Manual official-schedule assignment uses the same eligibility, submitted-availability, configured-workload, calendar, capacity, faculty, section/delivery-group, and room hard guards as generated schedules. Missing or outside availability has no reason-based bypass.

**Implementation Components**:
- `CurriculumScopeReadinessService`: reports and transitions curriculum coverage scopes by `curriculum_id + year_level + curriculum_period`, displaying the derived `program + curriculum version + College year level + curriculum period` review label. It computes blockers live and stores transition snapshots when state changes. It blocks `ready_for_scheduling` when the scope has zero valid subject rows, unresolved classification, missing/invalid weekly contact hours, invalid delivery override, unresolved import errors, or all rows excluded from automatic scheduling without an explicit reviewer reason. It derives `blocked` from hard blockers; staff may mark clear scopes ready or return scopes to `needs_review`, but may not manually select `blocked`.
- `DeliveryPatternService`: owns delivery-pattern creation, cloning/versioning, rule validation, and freeze-on-use behavior. It must reject direct mutation of a pattern version already used by enrollments or committed schedules.
- `SectionDeliveryGroupService`: owns create/update/close behavior for delivery groups, section/delivery-group capacity validation, inherited delivery-pattern defaults, and delivery-group assignment suggestions.
- `EnrollmentSectioningService`: records `section_id` and `section_delivery_group_id` together, lists compatible delivery groups, rejects over-capacity assignments transactionally, and keeps Registrar confirmation authoritative. Ranked recommendation and automatic balancing remain outside the active baseline until separately approved.
- `SectionPlanningReadinessService` or equivalent readiness branch inside `TermSchedulingReadinessService`: verifies target term sections exist for the intended program/year-level scope before generation, validates solver-scope columns, and reports missing section planning as a blocking readiness issue. This responsibility may live inside `TermSchedulingReadinessService` rather than a separate class when the behavior remains explicit and tested.
- `TermSchedulingReadinessService`: checks whether a selected term has `term_name`, `term_start_date`, `term_end_date`, and `scheduling_starts_at`; verifies each target section has explicit solver scope (`curriculum_id`, `year_level`, `curriculum_period`), a ready curriculum scope, at least one delivery group, section/delivery-group capacity, modality/delivery pattern, fixed-room input when required, weekly contact hours for every auto-schedulable subject, Registrar-confirmed eligible subject/faculty assignment for every section-delivery-group-subject demand, submitted/locked availability evidence for each assigned faculty member, and no configured workload overflow; returns `is_ready`, missing term fields, section issues, delivery-group issues, faculty-input issues, and room/catalog mode for the UI/snapshot layer. Section planning may create sections against a `needs_review` scope, but this service must block generation until the scope is ready.
- `FacultySubjectEligibilityService`: maintains approved faculty teaching/assignment records used by Registrar/setup staff before schedule generation; faculty cannot self-approve teaching subjects.
- `FacultyAvailabilityService`: opens/closes one submission period per term, validates available windows, submits records, rejects duplicate submitted/locked submissions for the same faculty/term, locks records, and creates exception revisions from approved change requests.
- `FacultyAvailabilitySubmissionResource`: provides the Faculty-facing availability submission UI. It exposes only list/create/view pages. The create form uses an `availability_period_id` select scoped to currently open periods and a `windows` repeater with `day_of_week`, `starts_at`, `ends_at`, and optional `notes`. It must not expose direct `faculty_id`, `term_id`, status, lock fields, or raw solver payload fields.
- `FacultyAvailabilityChangeRequestService`: validates late/post-lock requested windows, records faculty reasons, routes Registrar approve/reject decisions, supersedes prior locked availability only for future solver snapshots, and preserves old/new audit evidence.
- `ScheduleSolverSnapshotService`: captures the immutable solver input payload for a schedule generation run before dispatch. It stores the normalized JSON in `schedule_generation_runs.solver_input_snapshot`, stores a SHA-256 hash in `solver_input_hash`, records `solver_snapshot_captured_at`, and must return the existing stored snapshot on later calls instead of rebuilding from changed source data. The Laravel snapshot must include section delivery groups, `weekly_contact_hours`, and curriculum-scope readiness evidence including scope ID/status, reviewer/timestamp where present, blocker snapshot/hash, and exception reason where required. In the final implementation, the legacy `lec_hours` payload key may remain as a solver-compatibility alias, but it must be sourced from `curriculum_subjects.weekly_contact_hours`, not `subjects.lec_hours`. Adding audit-only readiness evidence or a compatibility alias to Laravel snapshots does not by itself require Cloud Run redeploy; Cloud Run redeploy is required when the solver runtime starts parsing or enforcing new fields such as `weekly_contact_hours` or `section_delivery_group_id`.
- `ScheduleGenerationService`: creates a schedule generation run, calls `ScheduleSolverSnapshotService` for term, ready curriculum scopes, section delivery-group demand, delivery patterns, Registrar-confirmed subject/faculty assignment inputs, locked availability, room/catalog data, existing commitments, and modality constraints, then dispatches `ScheduleSolverDispatchJob` after database commit.
- `ScheduleSolverDispatchJob`: runs on the `scheduling` queue after database commit, reuses the immutable input snapshot, invokes the configured `SchedulingSolverClient`, records success/failure metadata, and retries with backoff. When the `cloud_run` driver is enabled, the configured client obtains a Google ID token for the Cloud Run solver audience and triggers the IAM-private Cloud Run solver service.
- `ScheduleCloudResultIngestor`: receives or fetches solver result JSON, validates every proposed row against the immutable solver snapshot, and writes `schedule_draft_rows`.
- `ScheduleConflictValidator`: detects missing teacher assignment, teacher double-booking, room double-booking, section/delivery-group overlap, section capacity overflow, delivery-group capacity overflow, fixed-room mismatch, teacher outside locked availability, unapproved subject/faculty assignment, invalid calendar day/time, and modality/delivery-rule violations.
- `SchedulePublishService`: Registrar-owned transaction component that validates the reviewed run, creates official `section_meetings`, synchronizes `section_teacher`, records lifecycle activity, marks the run published, and supersedes the prior published version.
- `SchedulePublishService`: authorizes the Registrar, rejects stale/non-feasible/conflicted/incomplete runs, invokes the commit component, marks the run published, records actor/timestamp/note, and supersedes the prior published version inside one transaction. Academic Head and System Super Admin publication are denied.
- `ScheduleExportService`: exports published schedules through Laravel Excel. Exports may use headings and multiple sheets for department/program views; draft rows are not export-authoritative.

**Minimal Faculty Teaching Assignment Contract**:

`faculty_subject_eligibilities` is the approved pre-scheduling contract for Registrar-managed subject/faculty assignment. It is distinct from `section_teacher`, which is a post-commit assignment output.

| Column | Type | Contract |
| --- | --- | --- |
| `id` | bigint unsigned | Primary key |
| `faculty_id` | foreignId to `users` | Must reference a user with the `faculty` role |
| `subject_id` | foreignId to `subjects` | Curriculum subject the faculty member is approved to be assigned to |
| `term_id` | nullable foreignId to `terms` | Null means reusable/default eligibility; non-null means term-specific eligibility |
| `status` | string | `active` or `inactive` |
| `priority` | nullable unsigned smallint | Optional Registrar-side assignment preference; lower value may mean preferred |
| `max_weekly_hours` | nullable decimal(5,2) | Optional cap for this faculty/subject pairing |
| `approved_by` | nullable foreignId to `users` | Administrative approver |
| `approved_at` | nullable timestamp | Approval timestamp |
| timestamps | timestamps | Standard audit timestamps |

Recommended indexes:

- unique active guard for `faculty_id`, `subject_id`, and nullable `term_id` scope
- index `faculty_id, status`
- index `subject_id, status`
- index `term_id, status`

Ownership rule: System Super Admin may create the faculty account and assign the `faculty` role; Academic Head, Registrar, or another approved administrative owner maintains approved subject/faculty assignment records. Faculty may view their assigned/approved teaching subjects where exposed, but cannot create, update, replace, remove, or self-approve them.

**Cloud Solver Input Snapshot**:

The solver must receive normalized JSON produced by Laravel, not live database credentials or direct database access.

`schedule_generation_runs` stores the immutable dispatch input:

| Column | Type | Contract |
| --- | --- | --- |
| `solver_input_snapshot` | nullable JSON | Normalized payload sent to the solver; written once before dispatch |
| `solver_input_hash` | nullable char(64) | SHA-256 hash of the JSON snapshot for audit/debug comparison |
| `solver_snapshot_captured_at` | nullable timestamp | Time the immutable snapshot was first captured |

Required groups:
- run metadata: run ID, term ID, timezone, term dates, allowed scheduling grid, requested actor
- curriculum/subject demand: ready curriculum scopes, curriculum subjects, required section/program/year scope, units, weekly contact hours, academic subject type, scheduling group, delivery rule override, and meeting split rules when implemented
- delivery patterns: active pattern version, rules, hard/soft enforcement level, inherited defaults, and per-subject overrides
- section planning: pre-created term sections by program/year level; the snapshot must never ask the solver to create missing sections
- delivery-group planning: pre-created section delivery groups; the snapshot must never ask the solver to create missing delivery groups
- sections: section ID, program ID, `curriculum_id`, `year_level`, `curriculum_period`, section capacity, assigned count, and available seat count
- section delivery groups: delivery group ID, parent section ID, modality, delivery pattern version, capacity, assigned count, available seats, room requirement, and fixed room if any
- subject/faculty assignments: Registrar-confirmed faculty ID per section-delivery-group-subject demand, term scope, optional priority/max weekly hours
- faculty availability: locked/submitted availability windows
- rooms/catalog: room rows or fixed-room inputs for room-required delivery groups
- existing commitments: committed `section_meetings`
- policy constraints: modality rules, mandatory eligible faculty assignment for every publishable row, submitted availability, configured maximum weekly workload, section and delivery-group capacity guards, slot granularity, official campus day rules, and future policy hooks for lunch breaks, max back-to-back limits, preference weights, and meeting split rules. Future hooks must not be marked implemented until exact values and executable tests exist.

**Cloud Solver Output Contract**:

Each output row must map to one proposed `schedule_draft_rows` row:

- section_id
- section_delivery_group_id
- subject_id
- faculty_id required for every `ok` draft row and every committed `section_meetings` row; null is allowed only on unresolved/conflict draft rows that cannot be committed
- room nullable according to modality; for fixed-room rows, the proposed room must match the delivery group's fixed room in the immutable solver snapshot
- day_of_week
- starts_at
- ends_at
- modality
- solver_status
- hard_violation_codes
- soft_warning_codes
- diagnostic codes for rejected hard inputs and approved non-blocking optimization warnings
- score contribution or penalty metadata

The result summary must include solver status, solve time, objective score, assigned count, unassigned count, hard violation count, warning count, and timeout flag.

**Service Contract Shapes**:

| Service | Method | Input Contract | Output / Failure Contract |
| --- | --- | --- | --- |
| `CurriculumScopeReadinessService` | `markReady(CurriculumReadinessScope $scope, User $actor, ?string $reason = null): CurriculumReadinessScope` | Authorized Registrar/Academic Head, at least one valid subject row, confirmed classification, valid weekly contact hours, valid constrained delivery override, no unresolved import errors, and required exception reason when all rows are excluded from automatic scheduling or the scope was manually repaired from legacy data | Marks scope `ready_for_scheduling`, stores actor/timestamp/current blocker snapshot, and writes a transition entry to `activity_log`; otherwise returns field-level readiness blockers and leaves/derives status as `blocked` or `needs_review` |
| `CurriculumScopeReadinessService` | `markNeedsReview(CurriculumReadinessScope $scope, User $actor, string $reason): CurriculumReadinessScope` | Authorized Registrar/Academic Head or service-triggered scheduler-facing curriculum change | Returns a ready scope to `needs_review`, stores actor/timestamp/reason/blocker snapshot, and writes a transition entry to `activity_log` |
| `DeliveryPatternService` | `cloneVersion(DeliveryPattern $pattern, array $data, User $actor): DeliveryPattern` | Existing pattern and approved rule updates | Creates a new version; refuses mutation of in-use pattern versions |
| `SectionDeliveryGroupService` | `prepareForSave(Section $section, array $data, User $actor): array` | Parent section, delivery pattern, modality, capacity, room requirement, status | Rejects capacity below assigned count, invalid modality/room combination, or inactive pattern version |
| `EnrollmentSectioningService` | `assign(Enrollment $enrollment, Section $section, SectionDeliveryGroup $group, User $registrar): Enrollment` | Staff-confirmed section/group assignment | Stores `section_id` and `section_delivery_group_id` together; rejects subject-set mismatch or section/group capacity overflow |
| `SectionPlanningReadinessService` or `TermSchedulingReadinessService` section branch | `evaluateTerm(Term $term): array` | Persisted term and pre-created target sections | Blocks generation if no planned sections exist, if section solver-scope fields are missing, or if curriculum demand cannot be derived |
| `TermSchedulingReadinessService` | `evaluateTerm(Term $term): array` | Persisted `Term` model, target sections, delivery groups, ready curriculum demand, Registrar-confirmed subject/faculty assignment inputs, and submitted/locked availability with windows | `{is_ready: bool, missing_term_fields: string[], section_issues: array[], delivery_group_issues: array[], faculty_input_issues: array[], room_catalog_mode: string}`. Blocks generation when any section-delivery-group-subject demand lacks a confirmed faculty assignment or assigned-faculty availability. |
| `FacultySubjectEligibilityService` | `syncForFaculty(User $faculty, array $subjectEligibility, User $actor): void` | Authorized admin actor and subject IDs/term scope | Writes approved assignment records; rejects non-faculty users or invalid subjects |
| `FacultyAvailabilityService` | `submit(User $faculty, Term $term, array $windows): FacultyAvailabilitySubmission` | `windows[] = {day_of_week: 1-7, starts_at: HH:MM, ends_at: HH:MM, notes?: string}` where `1 = Monday` and `7 = Sunday` | Throws validation error when outside period, overlapping, or `starts_at >= ends_at` |
| `FacultyAvailabilityChangeRequestService` | `requestChange(User $faculty, FacultyAvailabilitySubmission $submission, array $data): FacultyAvailabilityChangeRequest` | Faculty-owned submitted/locked availability, `requested_windows[]`, and required reason | Creates a pending request; rejects non-owner access, direct edits to locked records, stale source versions, duplicate pending requests, overlapping windows, invalid time ranges, and missing reason |
| `FacultyAvailabilityChangeRequestService` | `approve(FacultyAvailabilityChangeRequest $request, User $registrar, ?string $reviewNote = null): FacultyAvailabilityChangeRequest` | Authorized Registrar and pending request | Creates a new locked `faculty_availability_submissions` revision with `version + 1`, copies requested windows, links `parent_submission_id`, records review evidence, and blocks stale/invalid transitions |
| `FacultyAvailabilityChangeRequestService` | `reject(FacultyAvailabilityChangeRequest $request, User $registrar, ?string $reviewNote = null): FacultyAvailabilityChangeRequest` | Authorized Registrar and pending request | Rejects without creating a revision, records review evidence, and blocks invalid state transitions |
| `ScheduleSolverSnapshotService` | `captureForRun(ScheduleGenerationRun $run): array` | Schedule run for a ready term | Writes `solver_input_snapshot`, `solver_input_hash`, and `solver_snapshot_captured_at` once; rejects unready terms; later calls return the stored immutable snapshot. The local scheduling snapshot contract is schema version 3 and includes readiness evidence, delivery groups, `section_delivery_group_id`, delivery pattern fields, `weekly_contact_hours`, delivery-group capacity, room requirement, and fixed-room data. Any legacy `lec_hours` alias must come from weekly contact hours. |
| `ScheduleGenerationService` | `generate(Term $term, User $registrar): ScheduleGenerationRun` | Ready term, authorized Registrar, confirmed subject/faculty assignment, availability, section, and delivery-group inputs | Creates one draft run, snapshots inputs, records queued solver metadata, and dispatches `ScheduleSolverDispatchJob` after database commit |
| `ScheduleSolverDispatchJob` | `handle(ScheduleSolverSnapshotService $snapshotService, SchedulingSolverClient $solverClient): void` | Persisted schedule generation run ID with immutable snapshot or ready term | Invokes the configured solver client on the `scheduling` queue; records completed/failed solver dispatch summary; retries with backoff |
| `ScheduleDraftAssignmentService` | `addOrRevise(ScheduleGenerationRun $run, array $data, User $registrar): ScheduleDraftRow` | Registrar-owned manual draft payload with typed section, delivery group, subject, faculty, room when required, day, start, end, modality, and review reason | Rejects published/terminal runs, ineligible subject/faculty assignment, missing/outside availability, configured workload overflow, invalid time ranges, faculty/room/section/delivery-group conflicts, missing required room, and capacity violations; never creates an official meeting directly. |
| `ScheduleConflictValidator` | `validate(ScheduleGenerationRun $run): ScheduleConflictReport` | Draft run | `{has_blocking_conflicts: bool, conflicts: array<int, ScheduleConflict>, warnings: array<int, ScheduleWarning>}` |
| `SchedulePublishService` | `publish(ScheduleGenerationRun $run, User $registrar, ?string $note = null): ScheduleGenerationRun` | Run in `generated` or `under_review` with reviewed draft rows and no blocking conflicts | Throws domain exception if already published, superseded, abandoned, incomplete, or still conflicting |
| `SchedulePublishService` | `publish(ScheduleGenerationRun $run, User $registrar, ?string $note = null): ScheduleGenerationRun` | Reviewed feasible run with no blocking conflicts and authorized Registrar | Transactionally creates official meetings, synchronizes `section_teacher`, marks the run published, records actor/timestamp/note, supersedes the prior published version, and blocks direct post-publish edits |
| `ScheduleExportService` | `exportPublished(Term $term, string $view): string` | `view` is `department`, `program`, or `faculty` | Private temporary export path; draft rows are never exported as official schedules |

**Workflow Contract**:
1. Registrar configures the term before availability or scheduling actions are enabled. Required term fields are `term_name`, `term_start_date`, `term_end_date`, and `scheduling_starts_at`.
2. If term readiness fails, Filament actions for availability period creation, faculty availability editing, draft generation, and schedule publication are disabled and display the missing fields.
3. Registrar/setup staff create planned term sections for the intended program/year-level scope. Each section must have `curriculum_id`, `year_level`, `curriculum_period`, and total capacity. Sections may be created while the curriculum scope is still `needs_review`, but the UI must surface the readiness state and generation remains blocked until the scope is `ready_for_scheduling`.
4. Registrar/setup staff create one or more section delivery groups with delivery pattern, modality, capacity, and room when required.
5. Laravel derives subject demand from the planned section's curriculum scope only when that scope is marked `ready_for_scheduling`. If no section exists, no delivery group exists, no ready curriculum demand can be derived, or the scope is `blocked`/`needs_review`, generation is blocked; the solver must not create sections or delivery groups.
6. Registrar creates one availability period per term with `opens_at` and `closes_at`; validation enforces `opens_at < closes_at <= scheduling_starts_at`.
7. Faculty can submit availability only while the period is open. Submitted windows become immutable scheduling input evidence after submission/lock.
8. Submitted/locked availability cannot be edited directly. Late or exceptional changes require a formal change-request workflow with reason and Registrar approval before they can replace solver input for a future generation/rerun.
9. Registrar/setup staff confirm the subject/faculty assignment for each section-delivery-group-subject demand before generation. Faculty submission never self-approves teaching subjects.
10. Laravel verifies each section-delivery-group-subject demand has a confirmed faculty assignment and assigned-faculty availability evidence. Missing assignment or missing assigned-faculty availability blocks generation and tells Registrar which demand lacks readiness.
11. Schedule generation is manually started by the Registrar. Faculty submission never auto-generates or publishes an official schedule.
12. Laravel creates a schedule generation run, captures an immutable input snapshot, and dispatches a queue job after database commit.
13. The queue job triggers the IAM-private Cloud Run solver service with a Google ID-token authenticated request.
14. OR-Tools CP-SAT solves within a strict timeout via the deployed GCP Cloud Run service. Local package-level solver tests are allowed as development verification and do not change deployed Cloud Run behavior until redeploy.
15. Laravel validates every row before inserting `schedule_draft_rows` as `ok`, `warning`, or `conflict`; an `ok` row must have a faculty assignment, `section_delivery_group_id`, valid capacity, and valid room/modality.
16. Every generation is saved as a draft run. User-visible lifecycle is `generated`, `under_review`, and `published`; `abandoned` and `superseded` remain terminal history states. Any internal `committed` state is transactional and not a separate user action.
17. Hard conflicts block publication. Registrar resolves conflicts in draft inputs by changing faculty assignment, time, room, delivery group, section plan, capacity, or other approved input; Faculty may be consulted but does not resolve conflicts in the system.
18. Registrar selects one `Publish Schedule` action for a reviewed feasible run. The publication transaction creates official `section_meetings`, synchronizes `section_teacher`, and releases the new published version to authorized stakeholders.
19. Published schedules are immutable. Changed availability or resources require a new draft and the same validation/publication path; successful replacement publication supersedes, but never mutates, the prior version.

**Cloud Run Redeploy Boundary**: `cloud/scheduler-solver` changes are not complete until the agent provides an explicit step-by-step Google Cloud Console or Cloud Shell redeployment checklist when the Scheduling slice is activated and the user asks for it. Do not assume local Python changes affect the deployed solver until a new image is built, deployed, and smoke-tested through `/health` and `/solve`.

**Filament Resource Mapping**: `ScheduleGenerationRunResource` is a Schedule Drafts evidence and lifecycle surface. It registers list/view pages only and may expose authorized generate, review, abandon, and Registrar Publish actions according to policy. It must not register generic create/edit page routes, create/edit header actions, delete actions, or forms for direct `term_id`, `requested_by`, `status`, `constraint_summary`, publish metadata, or timestamp editing. Draft run creation belongs to `ScheduleGenerationService`. `SchedulePublishService` validates `manage-schedules`, rejects stale/non-feasible/conflicted/incomplete runs, creates official `section_meetings`, synchronizes `section_teacher`, records scheduling activity, marks the run published, and supersedes the prior version inside one transaction. Academic Head and System Super Admin receive no publication action.

`SectionMeetingResource` registers list/view pages only and displays published official meetings. It has no generic create/edit/delete route. Registrar manual assignment is an add/revise draft-row action under `ScheduleGenerationRunResource`, using typed section, delivery group, subject, faculty, room, day, start, end, modality, and review-reason fields. `ScheduleDraftAssignmentService` owns normalization, invalid-time rejection, section/delivery-group overlap rejection, faculty-overlap rejection, physical-room overlap rejection, subject/faculty eligibility, submitted-availability validation, configured-workload validation, and capacity validation. It never writes official `section_meetings`; those are created only by Registrar publication. Corrections use a superseding draft/version, and approved availability revisions must be captured in that new snapshot.

`DeliveryPatternResource` and `SectionDeliveryGroupResource` (or a Section relation manager) must be typed Filament admin surfaces using v5 Resources, forms, tables, filters, relation managers, and lifecycle actions. Delivery patterns expose clone/version actions instead of raw mutation after use. Section delivery groups are edited under the parent section or as a scoped resource with descriptive labels, capacity badges, modality filters, and no unsafe bulk delete. Business rules stay in services, not in resource callbacks.

Manual draft assignment cannot override missing/outside faculty availability, faculty ineligibility, or configured workload overflow. The Registrar must correct the approved input or assignment before saving.

**Online Link Boundary**: The scheduling schema stores modality and schedule time only. It must not add fields for Zoom, Google Meet, LMS, or platform URLs. Online link coordination stays outside the active TALA baseline.

**Financials**: Simple Ledger logic (encoded amounts). No complex real-time formula calculations.

---


### 3.7 Faculty Class-List Privacy Boundary

Faculty class-list queries are derived from active canonical enrollment-subject rows plus the committed/published faculty assignment. They expose only the student identity and academic context required to teach, advise, and encode grades.

The query and Filament table must not join, compute, or render current balance, payment state, payment attempts, ledger entries, promissory notes, receipt/proof data, financial holds, or finance-derived attendance/exam/grade restrictions. Accounting services may use those records as upstream enrollment or next-cycle clearance gates, but Faculty receives only the resulting active academic roster.

**Migration Requirement**: `FacultyClassListService::facultyPaymentStatusFor()` and any Finance badge in `EnrollmentSubjectsTable` violate this goal-state boundary. The final implementation removes that field from the row DTO/query/table and updates privacy tests. Faculty class-list acceptance must be measured against the privacy boundary above, not against legacy finance-derived UI evidence.

---

### 3.8 Grade Submission Tracking Service (Administrative Widget)

**Purpose**: Track and display faculty grade submission progress across all sections for proactive **Academic Head** monitoring and bulk reminders.

#### 3.8.1 Service Class

**Implementation Note**: The atomic grading-access unit is a `section_teacher` row (a specific faculty × subject × section assignment), NOT a raw section. Published `section_meetings` are the official schedule source; `section_teacher` is synchronized during Registrar publication so the grading and class-list screens can continue to traverse the pivot. For correct tracking, eager-load `sections` with the pivot data.



```php
namespace App\Services;use App\Models\Section;use App\Models\Grade;use App\Models\User;use Illuminate\Support\Facades\DB;class GradeSubmissionTrackingService{    /**     * Get grade submission status for all faculty this term.     * Returns collection of faculty with their section assignments and grade progress.     */    public function getFacultySubmissionStatus(int $termId): \Illuminate\Support\Collection    {        $deadline = config('settings.grade_encoding_deadline');        return User::role('faculty')            ->with(['sections' => function ($q) use ($termId) {                $q->where('term_id', $termId)                  ->with(['subject', 'enrollments.grade']);            }])            ->get()            ->map(function (User $faculty) use ($deadline) {                $sectionsData = $faculty->sections->map(function (Section $section) use ($deadline) {                    $totalStudents = $section->enrollments->count();                    $finalizedCount = $section->enrollments->reduce(function ($carry, $enrollment) {                        return $carry + ($enrollment->grade?->is_finalized ? 1 : 0);                    }, 0);                    $percentage = $totalStudents > 0                        ? round(($finalizedCount / $totalStudents) * 100, 1)                        : 0;                    $status = $this->computeStatus($percentage, $finalizedCount, $deadline);                    return [                        'section_name'     => $section->name,                        'subject_code'     => $section->subject->code,                        'subject_name'     => $section->subject->description,                        'total_students'   => $totalStudents,                        'finalized_count'  => $finalizedCount,                        'completion_pct'   => $percentage,                        'status'           => $status,                    ];                });                // Aggregate faculty-level status                $totalSections = $sectionsData->count();                $submittedSections = $sectionsData->where('status', 'submitted')->count();                return [                    'faculty_id'         => $faculty->id,                    'faculty_name'       => $faculty->full_name,                    'total_sections'     => $totalSections,                    'submitted_sections' => $submittedSections,                    'overall_status'     => $submittedSections === $totalSections && $totalSections > 0                        ? 'submitted'                        : $sectionsData->pluck('status')->unique()->first(),                    'sections'           => $sectionsData,                ];            });    }    /**     * Compute submission status for a single section.     */    protected function computeStatus(float $percentage, int $finalizedCount, $deadline): string    {        $deadlinePassed = now()->gt($deadline);        if ($percentage >= 100) {            return 'submitted';  // Green        }        if ($deadlinePassed && $percentage < 100) {            return 'overdue';    // Red        }        if ($finalizedCount > 0) {            return 'in_progress'; // Amber        }        return 'not_started';    // Gray    }    /**     * Send grade submission reminder to selected faculty.     */    public function sendReminder(array $facultyIds, int $termId): void    {        $deadline = config('settings.grade_encoding_deadline');        User::whereIn('id', $facultyIds)->each(function (User $faculty) use ($deadline, $termId) {            $sections = $faculty->sections()                ->where('term_id', $termId)                ->with('subject')                ->get()                ->pluck('subject.code')                ->implode(', ');            \Illuminate\Support\Facades\Mail::to($faculty->email)->send(                new \App\Mail\GradeSubmissionReminder($faculty, $sections, $deadline)            );            // In-app notification            $faculty->notify(new \App\Notifications\GradeSubmissionReminder(                $sections, $deadline            ));        });    }}
```

**Return Contract**: `getFacultySubmissionStatus()` returns a collection of arrays with the faculty-level fields `faculty_id:int`, `faculty_name:string`, `total_sections:int`, `submitted_sections:int`, `overall_status:string`, and `sections:array`. Each section row contains `section_name:string`, `subject_code:string`, `subject_name:string`, `total_students:int`, `finalized_count:int`, `completion_pct:float`, and `status` as one of `submitted`, `in_progress`, `not_started`, or `overdue`. Rows are sorted by `overall_status` severity (`overdue`, `in_progress`, `not_started`, `submitted`) then by `faculty_name` and `section_name`. Supported filters are `status`, `faculty_id`, and `subject_code`; unsupported filters are ignored rather than changing the return shape.

#### 3.8.2 Filament v5 Widget



```php
namespace App\Filament\Widgets;use App\Services\GradeSubmissionTrackingService;use Filament\Tables;use Filament\Tables\Table;use Filament\Widgets\TableWidget;use Illuminate\Support\Facades\Config;class GradeSubmissionProgressWidget extends TableWidget{    protected static ?int $sort = 0; // Top of dashboard    protected int|string|array $columnSpan = 'full';    public function table(Table $table): Table    {        $service = app(GradeSubmissionTrackingService::class);        $termId = \App\Models\Term::active()->id;        $deadline = Config::get('settings.grade_encoding_deadline');        $data = $service->getFacultySubmissionStatus($termId);        // Flatten faculty→sections into rows        $rows = $data->flatMap(function ($faculty) {            return $faculty['sections']->map(function ($section) use ($faculty) {                return array_merge($section, [                    'faculty_id'   => $faculty['faculty_id'],                    'faculty_name' => $faculty['faculty_name'],                ]);            });        });        return $table            ->records(fn (): array => $rows->toArray())            ->columns([                Tables\Columns\TextColumn::make('faculty_name')                    ->label('Faculty')                    ->searchable()                    ->sortable(),                Tables\Columns\TextColumn::make('section_name')                    ->label('Section'),                Tables\Columns\TextColumn::make('subject_code')                    ->label('Subject')                    ->description(fn ($record) => $record['subject_name']),                Tables\Columns\TextColumn::make('total_students')                    ->label('Students')                    ->alignCenter(),                Tables\Columns\TextColumn::make('finalized_count')                    ->label('Grades Done')                    ->formatStateUsing(fn ($record) => "{$record['finalized_count']} / {$record['total_students']}")                    ->alignCenter(),                Tables\Columns\TextColumn::make('completion_pct')                    ->label('Progress')                    ->formatStateUsing(function ($state) {                        return "{$state}%";                    })                    ->color(fn (string $state): string => match (true) {                        $state >= 100 => 'success',                        $state >= 50  => 'warning',                        default       => 'gray',                    }),                Tables\Columns\TextColumn::make('status')                    ->label('Status')                    ->badge()                    ->color(fn (string $state): string => match ($state) {                        'submitted'     => 'success',                        'in_progress'   => 'warning',                        'not_started'   => 'gray',                        'overdue'       => 'danger',                    })                    ->formatStateUsing(fn (string $state): string => match ($state) {                        'submitted'   => 'Submitted',                        'in_progress' => 'In Progress',                        'not_started' => 'Not Started',                        'overdue'     => 'Overdue',                    })                    ->sortable(),            ])            ->actions([                Tables\Actions\Action::make('send_reminder')                    ->label('Send Reminder')                    ->icon('heroicon-o-bell')                    ->action(function ($record) use ($service, $termId) {                        $service->sendReminder([$record['faculty_id']], $termId);                    })                    ->requiresConfirmation()                    ->modalHeading('Send Grade Submission Reminder')                    ->modalDescription('This will send a reminder email and in-app notification to the selected faculty.'),            ])            ->bulkActions([                Tables\Actions\BulkAction::make('send_bulk_reminder')                    ->label('Send Reminders to Selected')                    ->icon('heroicon-o-bell-alert')                    ->action(function ($records) use ($service, $termId) {                        $facultyIds = $records->pluck('faculty_id')->unique()->toArray();                        $service->sendReminder($facultyIds, $termId);                    })                    ->requiresConfirmation(),            ])            ->filters([                Tables\Filters\SelectFilter::make('status')                    ->options([                        'submitted'   => 'Submitted',                        'in_progress' => 'In Progress',                        'not_started' => 'Not Started',                        'overdue'     => 'Overdue',                    ]),            ]);    }}
```

#### 3.8.3 Deadline Countdown Banner (Separate Widget)



```php
namespace App\Filament\Widgets;use Filament\Widgets\StatsOverviewWidget\Stat;use Filament\Widgets\StatsOverviewWidget as BaseWidget;use Illuminate\Support\Facades\Config;class GradeDeadlineWidget extends BaseWidget{    protected static ?int $sort = -1; // Above all other widgets    protected function getStats(): array    {        $deadline = Config::get('settings.grade_encoding_deadline');        $now = now();        if ($now->gt($deadline)) {            return [                Stat::make('⏰ Grade Encoding Deadline', 'EXPIRED')                    ->description("Deadline was {$deadline->format('M d, Y g:i A')}")                    ->color('danger'),            ];        }        $diff = $now->diff($deadline);        $days = $diff->days;        $hours = $diff->h;        $color = match (true) {            $days > 7  => 'success',            $days > 3  => 'warning',            default    => 'danger',        };        return [            Stat::make(                '⏰ Grade Encoding Deadline',                "{$days} days, {$hours} hours remaining"            )                ->description("Deadline: {$deadline->format('M d, Y g:i A')}")                ->color($color),        ];    }}
```

**Deadline Contract**: `settings.grade_encoding_deadline` must resolve to a nullable `CarbonImmutable` in `config('app.timezone')` (production default: `Asia/Manila`). A missing deadline means widgets show `not_configured`, no section is marked `overdue`, and reminder actions must display a blocking validation message until the Academic Head sets the deadline. Stored settings use ISO 8601 datetime strings with timezone offsets.

#### 3.8.4 `section_teacher` Pivot Schema Reference

The `section_teacher` pivot table is implemented in `database/migrations/2026_05_12_055403_create_academic_foundation_tables.php`. Do not duplicate the migration contract in this section; keep this section focused on service and relationship behavior.

#### 3.8.5 Model Relationships



```php
// User model (faculty)public function sections(){    return $this->belongsToMany(Section::class, 'section_teacher')        ->withPivot('subject_id')        ->withTimestamps();}// Section modelpublic function teachers(){    return $this->belongsToMany(User::class, 'section_teacher')        ->withPivot('subject_id')        ->withTimestamps();}public function subjects(){    return $this->belongsToMany(Subject::class, 'section_teacher')        ->withPivot('user_id')        ->withTimestamps();}
```

**Privacy Constraint**: This widget is restricted to the **Academic Head** and **System Super Admin** (read-only). Faculty cannot see other faculty's submission status. The widget uses the existing RBAC system (`spatie/laravel-permission`) to restrict access.

---


### 3.9 Unified General System Notification Service

**Purpose**: To handle all system-generated alerts (financial restrictions, profile updates, exam permits, etc.) using a single, unified `GeneralSystemNotification` architecture, eliminating class bloat.

#### 3.9.1 Notification Architecture

All system notifications are dispatched using a single class:

```php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

class GeneralSystemNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $message,
        public string $type,
        public ?string $actionUrl = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database']; // In-app notification only
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->actionUrl,
            'created_at' => now(),
        ]);
    }
}
```

#### 3.9.2 Usage Examples

Instead of specialized classes like `FinancialHoldNotification` or `StudentInfoUpdatedNotification`, services dynamically pass the payload to the unified class:

```php
// Example 1: Financial Hold
$student->notify(new GeneralSystemNotification(
    title: 'Account Restricted',
    message: 'Action Required: Minimum downpayment required.',
    type: 'financial_hold',
    actionUrl: route('student.finance')
));

// Example 2: Faculty Notification (Profile Update)
$faculty->notify(new GeneralSystemNotification(
    title: "Student Info Updated: {$student->full_name}",
    message: "This student updated their contact details.",
    type: 'student_info_update'
));
```

### 3.11 Public Admission Requirements Portal & Faculty Sharing Link

**Purpose**: Provides a public-facing Livewire page for admission requirements, accessible to students before login, solving F1. Also provides a quick-share widget in the Faculty Dashboard.

#### 3.11.1 Public Livewire Component



```php
namespace App\Livewire\Public;

use App\Services\AdmissionRequirementsService;
use Livewire\Component;

class AdmissionRequirements extends Component
{
    public array $requirements = [];

    public function mount(AdmissionRequirementsService $service): void
    {
        $this->requirements = $service->publishedRequirements();
    }
}
```

**Requirements Contract**: Admission requirements must not be hardcoded in Livewire components. The public requirements view reads a cached projection generated from published `admission_offerings` and their resolved typed policy versions. Registrar owns the dedicated offering and policy workflow; no independently editable JSON source exists.

```json
{
  "version": 2,
  "updated_at": "2026-05-18T00:00:00+08:00",
  "updated_by": 1,
  "offerings": [
    {
      "key": "college_freshman_bsit",
      "academic_term_id": 12,
      "entry_route": "regular",
      "prior_credential_pathway": "grade_12",
      "citizenship_profile": "domestic",
      "program_id": 3,
      "year_level": "1st Year",
      "title": "College Freshman - BSIT",
      "source_requirement_policy_version": 3,
      "items": ["PSA Birth Certificate", "Grade 12 Form 138", "Good Moral"],
      "is_published": true
    }
  ]
}
```

| Field | Contract |
| --- | --- |
| `version` | Positive integer incremented on every settings save. |
| `offerings[].key` | Stable unique string used for applicant selection, public anchors, and faculty share links. |
| `offerings[].academic_term_id` | Required term owning the admission window and offering. |
| `offerings[].entry_route` | Controlled route such as `regular`, `transfer`, `returning`, or `cross_enrollee`; this is not a demographic/support category. |
| `offerings[].prior_credential_pathway` | Prior-schooling basis such as `grade_12`, `old_curriculum`, `transfer_record`, or another approved College prior-credential catalog value. |
| `offerings[].citizenship_profile` | Compliance profile such as `domestic` or `foreign`; it is independently combinable with entry route and support attributes. |
| `offerings[].program_id`, `year_level` | Optional narrowing dimensions when requirements or eligibility differ by College program or year level. |
| `offerings[].source_requirement_policy_version` | Published immutable policy version from which this public projection was generated. |
| `offerings[].items` | Resolved, ordered public display labels only; compliance metadata remains in normalized policy and requirement tables. |
| `offerings[].is_published` | Boolean; unpublished offerings cannot receive public or Registrar-assisted intake. |

Seeders may provide draft offering/policy templates, but the public page and faculty quick-link widget must read through `AdmissionRequirementsService`. Publishing or retiring an offering/policy invalidates and rebuilds the projection cache. If no valid published offering resolves, the public page hides that intake route; if the projection itself fails, it displays a standard "Requirements temporarily unavailable" message and logs the failure for System Super Admin review.

---

### 3.12 Enrollment & Financial State Machine

**Purpose**: Manage the transition from applicant to institutionally enrolled student while enforcing document, finance, placement, account-access, and ledger invariants.

**Architecture Note**: The full student lifecycle uses **two separate state machines** on different models:

1.  **Application State** — tracked on `users.status` (simple enum). Governs the document review pipeline managed by the Registrar.
    
    -   `pending` → applicant submitted docs
    -   `action_required` → documents rejected, needs re-upload
    -   `for_evaluation` → transferee needs credit evaluation
    -   `approved` → documents verified, ready for payment
    -   `active` → fully enrolled student account with Student Hub access
    -   `inactive` / `archived` → authentication availability only; student lifecycle meaning remains on `student_profiles.operational_status`
2.  **Enrollment State** — tracked on `enrollments.status` via `spatie/laravel-model-states`. Governs document, finance, placement, active enrollment, ineligibility, and term-close transitions. Created when Application State reaches `approved`.

**Payment Evidence Boundary**: TALA acts as a subsidiary ledger for student accounts. Generated SOA and payment-acknowledgement documents are internal tracking artifacts, not official tax receipts.


#### 3.12.1 Enrollment States (`spatie/laravel-model-states`)

> **Enrollment State Architecture Decision:** The state table below is approved for implementation. Tentative placement expires at the earliest configured payment deadline, admission-window close, or authorized Registrar cancellation and consumes no secured seat. Confirmed qualifying payment secures every matching capacity-plan scope atomically. Account activation, canonical `Enrolled` state, COR/class-list eligibility, and roster inclusion occur only when admission gates, finance, secured capacity, and compatible final placement all pass in the same handover transaction. Otherwise the institution-owned case remains `PendingInstitutionalPlacement`; applicant noncompliance is not inferred.



| Enrollment State | Meaning | Permitted Next States |
| --- | --- | --- |
| `PendingPayment` | Assessment exists but minimum required payment is not confirmed. | `PendingAdmissionGate`, `PendingInstitutionalPlacement`, `Enrolled`, `EnrollmentCancelled` |
| `PendingAdmissionGate` | Payment may exist, but one or more configured admission-gate requirements remain unresolved. Tentative placement does not grant protected access. | `PendingPayment`, `PendingInstitutionalPlacement`, `Enrolled`, `EnrollmentCancelled` |
| `PendingInstitutionalPlacement` | Admission and finance gates are clear and capacity is secured, but the institution has not completed compatible section/delivery-group placement. The account remains inactive; this is institution responsibility, not applicant noncompliance. | `Enrolled`, controlled institution-caused resolution |
| `Enrolled` | Admission, finance, secured-capacity, and placement gates are clear; account handover completed; COR and class access available. Retention undertakings may remain active as separate holds. | `LeaveOfAbsence`, `Withdrawn`, `Completed` |
| `LeaveOfAbsence` | Registrar approved an interruption for the active term through a typed action. Active class-list rights stop; readmission creates or activates a later term enrollment through the readmission workflow. | None for the closed/interrupted term |
| `Withdrawn` | Registrar recorded full withdrawal with effective date, reason, evidence reference, and separately resolved financial disposition. Subject-only drops do not use this state. | None for the withdrawn term |
| `Ineligible` | Registrar resolved a disqualifying institutional enrollment conflict through an audited workflow. | Controlled repair only |
| `Completed` | Historical enrollment after term close. | None |
| `EnrollmentCancelled` | Enrollment stopped before institutional activation through an authorized typed cause and financial disposition. Retention-document delinquency alone does not silently cancel an active enrollment. | Controlled restart only |

**Canonical Enrollment Boundary**: Accepted admission-gate evidence plus finance clearance, secured capacity, and compatible placement triggers `Enrolled`. This is the only active institutional enrollment state: the student can download COR, attend classes, and appears in class lists and the Registrar Enrolled Student Roster. Retention undertakings remain separate itemized obligations. Drop Subject changes an enrollment-subject relation rather than this state. Registrar-approved LOA or full Withdrawal creates its corresponding terminal term outcome; term close transitions remaining `Enrolled` records to `Completed`.

**Bridge Between Phase 1 and Phase 2**: An approved scoped capacity plan may create a tentative placement before payment, but that placement consumes no secured seat and grants no protected access. Accepted admission-gate evidence unlocks the approved payment path. Confirmed minimum/full payment atomically secures capacity against the plan. When a compatible final placement is available, the system completes Account Handover to `Enrolled`; otherwise it records `PendingInstitutionalPlacement` without blaming the applicant. Non-critical retention documents use a separate undertaking/hold lifecycle. Promissory approval does not trigger finance clearance.

**Applicant Intake Backend Contract**: `ApplicantIntakeService` is the application-layer authority for public applicant creation, applicant status updates, duplicate checks, required-document derivation, applicant-owned `document_uploads`, and approval-for-payment prerequisites. It creates a pending applicant `users` row with the `applicant` role, creates one `applicant_intakes` staging record, and does not create `student_profiles`, `enrollments`, ledger entries, or official student credentials. Applicant document uploads may have `document_uploads.applicant_intake_id` with `student_profile_id = null` until Official Handover links official student records.

**Admission Offering and Requirement Resolution Contract**: Replace hardcoded document derivation and flat `applicant_type` branching with an `AdmissionOffering` plus versioned `AdmissionRequirementPolicy`. The normalized intake dimensions are College entry route, prior-credential pathway, citizenship profile, target program, target year level, and purpose-limited support attributes. IP and disability/SEN attributes must not be exposed as public applicant categories or used as eligibility denials; they may select lawful evidence alternatives, accessibility accommodations, or authorized support workflows only. `AdmissionRequirementResolver` validates that the selected offering is published for the term, composes its base and conditional rules deterministically, rejects unknown dimensions or conflicting matches, and materializes an immutable checklist snapshot containing source rule IDs/versions. Applicant approval decisions and later student creation continue through the shared state machine; no category-specific service may bypass verification, finance, capacity, or audit contracts.

**Returning/Legacy Intake Contract**: Returning students bypass public applicant-account creation and enter through an authenticated Registrar-assisted lookup. Matching order is immutable student ID when known, then LRN and verified identity attributes, then a guarded manual review; weak name-only matches never auto-merge. If no reliable pre-TALA record exists, `LegacyStudentOnboardingService` creates one provenance-tagged student/profile baseline from verified institutional evidence, records source references and unknown historical fields, and runs the same duplicate guard before readmission begins. This service does not approve readmission, fabricate grades/documents, clear balances/holds, or activate access. It may invoke `AdmissionRequirementResolver` for itemized missing legacy/readmission evidence. The readmission service remains responsible for eligibility, curriculum alignment, finance/document/discipline holds, capacity, lifecycle transition, and account reactivation.

**Student Enrollment Backend Contract**: `StudentEnrollmentService` is the application-layer authority for the approved-applicant-to-student bridge and regular enrollment backend contract before Student Hub UI is enabled. It creates or reuses exactly one `student_profiles` row for an approved applicant, creates or reuses one term-scoped sectionless `enrollments` row with `status = 'pending_payment'`, and links applicant-owned documents to the official profile. Applicant-origin section assignment is deferred until finance and required physical-document clearance are both satisfied, then uses the locked-capacity `EnrollmentSectioningService`. Regular/returning enrollment keeps its separate eligibility, balance, returnee, and compatible-section rules. The final implementation migrates transitional enrollment values to the canonical `enrolled` value and removes external-reporting-only columns after all code references are migrated.

**Legacy Enrollment Migration Contract**:

- Stop `EnrollmentAssessmentService` from setting `enrolled_at`; assessment creation is not enrollment. Only the successful atomic document/finance/placement handover writes `enrolled_at`.
- Before dropping legacy columns, map both `pre_enrolled` and `officially_enrolled` status values to `enrolled` and set `enrolled_at = COALESCE(pre_enrolled_at, officially_enrolled_at, enrolled_at)` for those rows. Other lifecycle states retain their existing timestamps.
- Migrate all services, policies, queries, Filament resources, dashboard projections, exports, factories, seeders, and tests to `enrolled` before dropping transitional enrollment timestamp/status columns.
- The local/system acceptance rollback recreates legacy nullable columns, maps `enrolled` back to `pre_enrolled`, and copies `enrolled_at` into `pre_enrolled_at`. Production with real data uses backup plus forward-fix migration rather than relying on this lossy rollback.

`EnrollmentFinanceClearanceService` is the shared application-layer rule for payment-driven finance clearance. Manual Accounting confirmation and PayMongo webhook-confirmed payments both evaluate the same minimum-downpayment/full-payment rule and atomically secure the approved capacity reservation while ignoring promissory notes as payment. It may delegate to account handover only when admission and placement gates are clear. Retention undertakings do not block that delegation. Regular/returning enrollment keeps its approved finance-clearance behavior. PayMongo attempts without an enrollment link remain payment/ledger evidence only until a controlled reconciliation path links them to an enrollment.

**Cross-State Invariant Contract**:

| Application / Account State (`users.status`) | Enrollment State (`enrollments.status`) | Allowed? | Notes |
| --- | --- | --- | --- |
| `pending`, `action_required`, `for_evaluation` | none | Yes | Applicant is still in document review; no enrollment record exists. |
| `approved` | none or `PendingPayment` | Yes | Payment portal is available; protected Student Hub remains blocked until account activation. |
| `approved` | `PendingAdmissionGate` | Yes | Payment evidence may exist, but an admission blocker remains; tentative placement grants no protected access. |
| `approved` | `PendingInstitutionalPlacement` | Yes | Applicant gates are clear, but the institution still owes a compatible placement; no protected student access exists. |
| `active` | `Enrolled` | Yes | Finance-, document-, and placement-cleared account; COR and class access are available. |
| `active` | `PendingPayment`, `PendingAdmissionGate`, or `PendingInstitutionalPlacement` | No | Account must not activate before finance, admission, secured-capacity, and institutional-placement clearance. |
| `approved` | `Enrolled` | No | Successful admission/finance/placement handover must activate `users.status` in the same transaction. |
| any non-active account status | `Enrolled` | No, except audited rollback | Active enrollment requires an account access effect consistent with the approved student lifecycle transition. |
| `inactive` | `EnrollmentCancelled` | Yes | A temporary applicant enrollment was closed before activation; no protected student access exists. |
| `active` | historical `Completed` | Yes | Between terms, an eligible student may retain read-only Student Hub access while no current-term enrollment exists. |
| `inactive` or `archived` | historical `LeaveOfAbsence`, `Withdrawn`, `Completed`, or `Ineligible` | Yes | Readmission/reactivation requires the Registrar-owned lifecycle flow before a new active enrollment. |

`Completed` is historical and set by term close. System code must enforce the invariant inside the same transaction that applies payment clearance and account handover. The final implementation removes external-reporting-only status/timestamp columns from the target schema and all Student Hub/Admin projections; it preserves ordinary `enrolled_at` and `completed_at` evidence.

**Roster Export Support**: `EnrollmentRosterQuery` is the read authority for the Registrar roster and export. It requires a term and includes only `status = enrolled`. Optional filters are College program, year level, section, delivery modality, and student type. `EnrollmentRosterExporter` consumes that query and emits the same generic authorized column schema as CSV or XLSX. Export authorization is separate from record mutation, and every export records actor, format, filters, timestamp, row count, and generated-file audit evidence. No export action writes enrollment status.

#### 3.12.2 Advance Payments (Negative Ledger Balance)

To handle overpayments flexibly, the system uses the ledger's natural math rather than a separate Wallet Engine.

**Implementation contract**:

-   Payments exceeding the current assessed debt simply drive the student's overall ledger balance below zero (a negative balance). The system does not maintain a separate "Student Wallet".
-   All ledger values are stored as `decimal(12,2)` and passed through services as decimal strings or value objects, never PHP `float`.
-   PayMongo payload amounts are accepted as integer centavos at the gateway boundary and converted once before ledger posting.
-   The ledger remains immutable: overpayments create standard `payment` transactions.
-   When a new fee is assessed, it simply adds to the ledger, automatically offsetting against any negative balance.

**Filament Resource Mapping**: `LedgerEntryResource` is the Accounting Ledger Review surface and must be list/view only. It must not register generic create/edit page routes, create/edit header actions, delete actions, or raw forms for `entry_type`, `reference_type`, `reference_id`, `amount`, `running_balance`, `posted_at`, or `posted_by`. Core ledger entries are written by domain services such as enrollment assessment, automated discounts, manual payment confirmation, PayMongo webhook processing, and the typed Accounting adjustment workflow. Installment overdue processing and refunds remain review scope.



#### 3.12.3 Financial Disposition Policy Contract

**Purpose**: Review institution-approved, effective-dated financial outcomes for typed payment and enrollment events. Automated cancellation/refund/withdrawal-fee disposition is not active core scope until the institution approves the policy, authority, audit, reconciliation, and tests.

**Rules**:

-   Future `financial_disposition_policies` may store versioned policy name, scope, typed event cause, disposition, effective dates, state, and approval evidence. Overlapping active scopes must be rejected.
-   Future dated percentage schedules must distinguish fee components, externally issued receipt/payment-reference date, official-enrollment effective time, payment channel, and prior disposition.
-   Missing or ambiguous policy must block automatic closure and route the case to authorized Accounting review; no payment code may guess a disposition.
-   Outside-system discrepancies, cancelled enrollment, duplicate successful payments, and overpayments must not trigger PayMongo provider refunds or cash refund workflows unless the resolved policy explicitly enables an implemented refund channel.
-   Duplicate webhook retries are suppressed by idempotency and do not create duplicate ledger entries.
-   A separate second successful payment remains a standard immutable `payment` credit and may drive the ledger balance below zero until its resolved disposition is applied; no separate wallet transaction is created.
-   Cancelled or ineligible enrollment keeps immutable payment history for audit; access/COR/class-list state is changed separately from financial refund behavior.
-   No document-related cancellation may assume automatic payment retention. The resolver applies the effective policy to each fee component and preserves the original payment evidence.
-   The physical-document closure rule is not a generic cancellation rule. Other cancellation causes require an explicitly approved financial disposition.
-   Institution-caused placement failure uses cause `institutional_placement_unavailable`; it must never be recorded as applicant document noncompliance. Its financial outcome is resolved through the active policy after placement resolution is exhausted.
-   A deployment may enable provider/cash refund only after an approved policy, role authorization, channel-specific idempotency, immutable audit trail, reconciliation behavior, and tests are implemented. Raw ledger mutation is never a refund mechanism.

#### 3.12.4 Downpayment Rule

**Purpose**: Define the active finance-clearance rule while full installment policy tables/services remain review scope.

**Data Contract**:
-   The `fee_templates` table includes a `minimum_downpayment_percentage` (e.g., 20.00).
-   `fee_templates.program_id` and `year_level` define the College assessment scope. `program_id = null` means all College programs. `year_level = null` means all College year levels.
-   The Filament form must expose `year_level` as a canonical select matching the exact values used by College enrollment records (`1st Year`, `2nd Year`, `3rd Year`, `4th Year`), not a loose numeric/free-text field.
-   `FeeTemplate` normalizes blank `program_id`/`year_level` values to `null` and rejects saving a second active template for the same College program/year scope. Inactive historical templates may share a scope.

**Service Boundary**:
-   Accounting configures the base fee templates and downpayment percentages.
-   Finance clearance is evaluated by checking if total payments for the term meet or exceed `(total_assessed * minimum_downpayment_percentage / 100)`. Applicant-origin enrollment reaches `Enrolled` only after admission gates, secured capacity, and sectioning also succeed. Retention undertakings remain separate holds.
-   Accounting-approved promissory notes do not grant finance clearance; only confirmed payment evidence can clear finance.
-   Policy calculations must use decimal strings/value objects, never PHP `float`.



**Installment Review Boundary**:

-   Installment plans, penalty schedules, grace periods, and per-student installment-state automation remain review scope.
-   Promissory notes remain non-payment records and must not satisfy installment or finance clearance if promoted later.
-   Per-student installment states such as `paid`, `in_grace`, `overdue`, penalties, balances, and clearance must not be implemented through inline mutable table columns. If promoted later, installment behavior requires explicit authorization, service calls, audit logging, and tests.

#### 3.12.5 Automated Freshmen Discounts

**Purpose**: Automatically apply the institution's 50% Tuition Fee discount policy for eligible incoming freshmen.

**Rules**:
- Triggered automatically during the enrollment assessment phase.
- **Eligibility**: `student_type == 'New'` AND `year_level == '1st Year'`. Transferees are excluded.
- **Computation**: The system calculates exactly 50% of the assessed `Tuition Fee`. Miscellaneous, Laboratory, and Other Fees are not discounted.
- **Ledger Behavior**: The calculated discount is injected directly into the student's ledger as a **Negative Ledger Entry** (Credit), reducing their `current_balance` accordingly without requiring manual Accounting review.

#### 3.12.6 Registrar-Owned Program Shift Contract

**Purpose**: Apply Registrar-recorded College program shifts without corrupting existing curriculum binding, grades, financial history, or enrollment records. Student Hub does not submit or manage program-shift cases.

**Eligibility Rules**:

-   College program shifting is allowed only up to the approved 2nd-year limit.
-   Shifting preserves all prior grades, enrollments, payments, uploaded documents, and curriculum history.
-   The approved shift takes effect only for the approved effective term and does not rewrite historical enrollment records.

**Data Contract**:

-   `program_shift_cases`: student, current program, target program, current year level, approved effective term, state, reason, evidence reference, recorder, reviewer, and audit metadata.
-   `shifting_credit_evaluations`: Registrar-reviewed subject equivalency and prerequisite notes for the requested program.
-   Financial assessment links exist only if a separately approved program-shift fee policy is promoted.

**Service Boundary**:

-   `ProgramShiftEligibilityService` evaluates the approved College 2nd-year eligibility limit before Registrar review.
-   `ProgramShiftService` owns typed transitions: `Recorded`, `UnderReview`, `Approved`, `Rejected`, and `Applied`.
-   Registrar owns academic review, curriculum fit, credited subjects, prerequisites, and effective-term recommendation.
-   Accounting participates only if a promoted fee policy defines the amount, due date, confirmation, waiver, and ledger behavior.
-   Academic Head may authorize exceptions only if a later approved policy explicitly permits them.

---

#### 3.12.7 Finance Assessment, Artifact, and Reconciliation Contract

**Fee structure and assessment ownership**:

- Evolve the transitional `fee_templates` design into approved versioned fee structures with effective dates, academic-year/term and College program/year-level scope, lifecycle state, and non-overlapping active resolution. Component lines are child records, not new fixed amount columns for every fee type.
- A per-enrollment assessment header and assessment-line snapshot records the resolved structure/version, scope, quantities or units where applicable, component amounts, discount/scholarship/downpayment policy versions, gross/net totals, actor, and assessment time. Ledger charge/discount entries reference this immutable assessment evidence.
- Reassessment after a legitimate enrollment/schedule/policy change creates a superseding assessment or typed adjustment entries. It never edits the original assessment or ledger rows.
- Assessment does not set `enrollments.enrolled_at`; official admission/finance/placement handover owns that timestamp.

**Unified posting boundary**:

- Manual cash/GCash/bank confirmation and provider-confirmed PayMongo outcomes call one transactional posting boundary that locks the student/enrollment projection, requires prior assessment, enforces unique normalized channel/provider references, writes payment plus ledger credit plus audit evidence, recomputes the materialized balance, evaluates clearance, and commits capacity/handover effects atomically where eligible.
- `student_profiles.current_balance` is a materialized projection for efficient reads. Ledger history is authoritative; a reconciliation/rebuild service must detect and repair projection drift through audited correction rather than editing ledger history.
- Pending, failed, unknown, invalid-signature, or redirect-only provider states create no payment credit or clearance. Duplicate webhook/provider/reference delivery returns the existing effect without posting twice.

**SOA and payment-evidence artifacts**:

- A confirmed collection may record payment/reference evidence with unique reference metadata, payment links, channel, payer, amount, recorded actor/time, source evidence, checksum, and correction state. TALA does not generate official tax document numbers, PDFs, or tax documents.
- An SOA issuance snapshots the authorized ledger cutoff, opening/closing balance, charge/payment/credit lines, due context, template version, issuer/time, checksum, and state. Regeneration after new transactions creates a new issuance rather than altering a previously issued statement.
- Generated PDFs are private derived artifacts. Authorization is owner/accounting scoped; raw storage paths are never exposed.

**Daily reconciliation and segregation**:

- A daily/shift close groups collections by office/cashier, business date, and channel. It stores expected ledger/payment totals, external receipt/reference totals where recorded, actual cash count or deposit/provider settlement totals, variance, evidence, preparer, verifier, and lifecycle (`open`, `submitted`, `approved`, `reopened`).
- A non-zero variance requires reason and evidence. Approval requires the reconciliation permission and, where maker-checker applies, a verifier different from the collector/recorder/preparer. Approved batches are immutable; later correction uses reopen/supersede history.
- Provider reconciliation compares TALA attempts/payments with PayMongo provider identifiers and settlement evidence through replay-safe retrieval. It must not invent a manual PayMongo payment channel.
- Private reminder/due-list generation uses owner-scoped notifications. Faculty and public surfaces never receive balances or delinquent lists.



### 3.13 Legacy Student Import and Registrar-Assisted Activation

**Purpose**: Onboard verified continuing students through a dedicated, auditable student import without exposing a public account-claim workflow.

- `ContinuingStudentsImport` uses the shared import-batch infrastructure in §3.19 but owns a fixed student template, validator, preview, and commit service.
- Accepted rows create or update the minimum verified `StudentProfile` and inactive student account baseline. The importer must not post balances, grades, payments, or enrollment history.
- Duplicate resolution uses immutable student ID when known, then LRN plus verified identity attributes. Weak name-only matches require Registrar review and never auto-merge.
- A Registrar activates access only after verifying institutional evidence, confirming the matched student profile, assigning the seeded `student` role, and initiating the approved password setup/reset path.
- There is no public self-service account claim, evidence-matching endpoint, automated identity extraction, or claim lockout state machine.
- Activation, rejection, duplicate resolution, and source provenance are audited.

---

### 3.14 Webhooks & Third-Party Integrations

#### 3.14.1 Payment Gateway Webhooks (PayMongo - GCash/E-Wallet)

**Purpose**: Automate payment confirmations to remove bottlenecks in the Accounting queue.

-   **Integration**: Generates checkout sessions via PayMongo Checkout APIs (using `luigel/laravel-paymongo` or Laravel’s native Http client).
-   **Route**: `POST /api/webhooks/paymongo`, registered only after API routing is deliberately enabled for this Laravel 12 app because `routes/api.php` is not currently present.
-   **Webhook Client**: `spatie/laravel-webhook-client` must be published/configured first because `config/webhook-client.php` is not currently present.
-   **Signature Contract**: Verify the `Paymongo-Signature` header against the raw request body and webhook secret before processing. Invalid signatures are rejected without ledger effects.
-   **Storage Before Processing**: After signature validation, store the webhook call in `webhook_calls` before dispatching domain processing, including the payload and relevant headers needed for audit/replay.
-   **Response Contract**: Return an HTTP `2xx` JSON response quickly after signature validation and storage. Attempt, payment, ledger, student-balance, and eligible enrollment transitions run asynchronously from the stored webhook call.
-   **Logic**: Receives payloads from PayMongo, normalizes PayMongo integer minor-unit amounts to ledger-safe decimal strings, creates immutable `payment` transactions from the queued processing job, and runs shared finance-clearance/account-handover evaluation when the payment attempt is linked to a real enrollment.
-   **Accepted Events**: Hosted Checkout success is driven by `checkout_session.payment.paid`. `payment.paid` is accepted only when its provider payment/payment-intent reference maps to an existing TALA payment attempt. Unknown events are stored for audit and return `2xx` without ledger effects.
-   **Redirect Boundary**: Return/success URLs from PayMongo are user-navigation hints only. The system must never mark a ledger payment as paid from the redirect URL alone.
-   **Idempotency**: The webhook processor locks the matched payment-attempt row inside the transaction and uses database uniqueness on the provider-derived payment reference and generated composite key `{event_id}:{provider_checkout_session_id|provider_payment_id}`. A paid attempt or existing provider payment reference is ignored without another ledger credit.
-   **Transactions and Retry**: Attempt status, payment evidence, linked ledger credit, student balance, and finance-clearance/account-handover effects occur inside `DB::transaction()`. Processing failures are recorded on `webhook_calls` and rethrown so the queued job's configured retry/backoff policy remains effective.
-   **Missed Event Reconciliation**: If webhook delivery is missed or the endpoint was unavailable, reconcile by retrieving the PayMongo payment intent, checkout session, or payment object by provider ID and applying the same idempotent processing path.
-   **Diagnostic Command**: `php artisan integrations:paymongo-sandbox-webhook-smoke --attempt-id=<id>` or `--checkout-session-id=<cs_test_id>` verifies the stored PayMongo webhook evidence after a sandbox checkout is completed. The command fails unless the selected attempt is `paid`, a provider event/reference is recorded, a PayMongo `webhook_calls` row is present, exactly one confirmed PayMongo `payments` row exists, and the linked `ledger_entries` row is a negative `payment` credit for the same amount. `--process-pending` may be used only for local smoke testing when the callback was stored but no queue worker processed it yet.

**Webhook Processing Contract**:

| Event | Process? | Required Mapping |
| --- | --- | --- |
| `checkout_session.payment.paid` | Yes | Checkout session ID or reference maps to a TALA payment attempt. |
| `payment.paid` | Conditional | Payment/payment-intent ID maps to a TALA provider reference. |
| Any other event | Store only | No ledger mutation; return quick `2xx` after verified storage. |

Manual reconciliation may mark a payment as paid only by retrieving the provider object through the PayMongo API and applying the same idempotent ledger-posting service used by webhooks.

**Filament Resource Mapping**: `PaymentAttemptResource` is the Accounting Payment Queue and `PaymentResource` is Confirmed Payments evidence. Both resources are list/view only. They must not register generic create/edit page routes, create/edit header actions, delete actions, or raw `meta`/provider-reference forms. Payment attempts are created by checkout/manual-upload/service workflows, and payment records are created by webhook processing, manual confirmation, or reconciliation services. Any manual confirmation UI must be an authorized Accounting action that validates amount, reference, date, and eligible state before posting ledger effects.

**Payment Confirmation Implementation Contract**: `PaymentConfirmationService` accepts only Cash, GCash Manual, and Bank Transfer, requires a normalized unique reference and non-future payment date, rejects unassessed enrollments, and posts payment, negative ledger credit, running balance, clearance/handover evaluation, and audit evidence atomically. `PayMongoWebhookProcessor` links each paid attempt to its resulting ledger entry and shares `EnrollmentFinanceClearanceService`; an enrollment without positive assessment evidence cannot finance-clear even if its balance is zero or negative. PayMongo reconciliation is not exposed as a free-form manual channel and must use a verified provider retrieval path. Payment, Payment Attempt, and Ledger Entry Filament resources remain list/view-only evidence surfaces.

#### 3.14.2 COR and Finance Artifact Issuance Service Contract

**Purpose**: Convert authorized enrollment and Accounting source-workflow events into controlled COR and SOA/payment evidence artifacts without making the artifact the source of truth or introducing a document-request lifecycle.

**Service ownership**:

- The artifact issuance service resolves document type, eligibility, source read model, template version, and issuance state for COR and Accounting evidence only.
- COR issuance is called from the enrollment handover/COR flow.
- SOA/payment acknowledgement artifacts are called only by their owning Accounting workflow after prerequisites pass.
- Formal TOR, Form 137, report-card PDF, diploma, certificate, and full credential issuance/fulfillment are outside active TALA scope. Finalized grade history remains active as structured academic record data.

**Eligibility sources**:

| Document family | Required authoritative source |
| --- | --- |
| COR | Canonical enrolled student, active/requested term, section/delivery assignment where needed |
| SOA / payment acknowledgement | Immutable ledger/payment/assessment data and Accounting issuance lifecycle |

**State machine**:

`draft` -> `issued` -> `released` is the normal path. `issued` or `released` may transition to `revoked` or `superseded` with reason, actor, timestamp, and source linkage. Reissuing after correction creates a new issuance linked to the prior one; it never updates the previous artifact in place.

**Release evidence**:

- Student-facing COR and Accounting evidence access remains role-scoped and private unless a dedicated verification URL intentionally exposes minimal validity data.
- External credential release, school-to-school transfer, TOR/Form 137 fulfillment, formal report-card PDF release, diploma release, and document-request fulfillment are outside active TALA scope.

**Testing expectation**: Focused tests must cover eligible COR/finance artifact issuance, missing eligibility/source-state denial, private artifact authorization, checksum/source snapshot immutability, QR token minimal disclosure, revoked/superseded verification response, and unauthorized issue/release denial.



---

#### 3.14.3 Private Document Review Reliability

**Purpose**: Keep admission and retention evidence review available without an external extraction service.

- Upload acceptance validates extension, detected MIME type, size, checksum, ownership, and requirement linkage before storing the source privately.
- `DocumentUploadResource` is a Registrar list/view review queue with approve, needs-correction, and reject lifecycle actions. It has no generic create/edit routes and never exposes private storage paths as editable values.
- `DocumentUploadReviewService` owns authorization, row locking, active-state validation, typed correction/rejection reasons, reviewer metadata, approved verified-field capture, notifications, and `document_review` activity logging.
- Approved and rejected records are terminal for this surface. Replacement evidence creates a linked upload and preserves prior review history.
- Temporary authorized previews remain available if notification or queue delivery is delayed; review does not depend on a third-party API.

---

### 3.15 Approved Background Tasks

Scheduled events are limited to approved system-owned workflows. The deployment invokes Laravel's scheduler every minute; only the following application tasks are active:

| Task | Trigger | Contract |
| --- | --- | --- |
| Retention-document monitoring | Scheduled off-hours | Lock eligible undertaking rows, mark newly due items, apply only configured documentary/next-cycle hold evidence, deduplicate notices, and never cancel active enrollment. |
| Term close | Explicit Registrar action; may dispatch an off-hours batch | Close one selected term once, transition only eligible term enrollments, preserve student/account history and unresolved INC records, and audit the Registrar action. |

PayMongo webhook processing and CP-SAT solver dispatch are event-driven queue jobs, not scheduled polling tasks. Automatic payment rejection, installment deadlines, promissory deadlines, and INC expiry/failing conversion are not registered because their policies remain unapproved.

Every background task defines authorization at its trigger, row locking or an idempotency key, bounded batches where needed, retry/backoff, safe failure visibility, and tests proving repeated execution does not duplicate effects.

---
### 3.16 FAQ Module Implementation

**Purpose**: Technical mapping for FS §8.7. Provides a curated FAQ accessible via the public landing page and the Student Hub.

#### 3.16.1 FAQ Schema Reference

FAQ table migration exists as `2026_05_23_173901_create_faq_entries_table.php` and creates `faq_entries` with question, answer, fixed category key, sort order, publish flag, creator/updater audit links, and category/publish/order indexes.

#### 3.16.2 Admin Mutation Boundary

-   **Resource**: `App\Filament\Resources\FaqEntries\FaqEntryResource`
-   **Features**: System Super Admin can create, edit, publish/unpublish, sort, categorize, and delete FAQ entries through the Admin Nexus. The resource delegates content fields to `FaqEntryForm` and table behavior to `FaqEntriesTable`.
-   **Access**: `/admin/faq-entries*` routes are registered and permission-gated by `FaqEntryPolicy` using `manage-faqs`.
-   **Form Boundary**: Admin users may edit only `question`, `answer`, `category`, `sort_order`, and `is_published`. `created_by`, `updated_by`, and timestamps are system-derived audit fields.
-   **Category Contract**: `category` is a fixed select list, not arbitrary text. Allowed values are:
    -   `general` = General
    -   `admission_enrollment` = Admission / Enrollment
    -   `payments_fees` = Payments / Fees
    -   `admission_requirements` = Admission Evidence / Requirements
    -   `grades_academics` = Grades / Academics
    -   `account_login` = Account / Login
    -   `technical_support` = Technical Support

#### 3.16.3 Public & Student Views

-   **Public Route**: `GET /faq` (public, no auth required)
-   **Public Component**: `pages::faq`
-   **Public Features**: FAQ list filtered to `is_published = true`, grouped by model-owned `category` labels, ordered by `category`, `sort_order`, and `question`. Search requires a separate approved enhancement.
-   **Student Hub Route**: `GET /student/help`
-   **Student Hub Component**: `pages::student-hub.help`
-   **Student Hub Access Boundary**: All `/student/*` routes use `auth` plus `student.active`; the middleware requires `users.status = 'active'` and the `student` role, and is registered as persistent Livewire middleware for future component actions.
-   **Student Hub Features**: The Help page reads only `is_published = true` FAQ entries, groups them by model-owned category labels, and does not expose FAQ mutation to students.

#### 3.16.4 FAQ Governance Flow (Operational Contract)

-   **Authoring Role**: System Super Admin receives FAQ authoring rights through `manage-faqs`. No separate FAQ manager role is required.
-   **Publishing Rule**: Only entries marked published are visible in public/student views.
-   **Read Scope**: All non-admin users (students/public/staff roles) are read-only for FAQ content.
-   **Escalation Path**: If FAQ does not answer a concern, user proceeds to the specific module workflow (enrollment, registrar request, accounting process).

#### 3.16.5 FAQ and Student Help Implementation Contract

`FaqEntry`, the `faq_entries` migration, `FaqEntryResource`, Filament pages, and `FaqEntryPolicy` keep FAQ content maintainable without hardcoding. Categories are model-owned fixed options; author/updater fields remain system-derived. The public `/faq` route uses `pages::faq` and has only `web` middleware, so guests can read published entries without gaining mutation capability. Student Hub routes are protected by `EnsureActiveStudentHubUser`, which accepts only authenticated active users with the `student` role, and `pages::student-hub.help` renders only published FAQ entries. Student dashboard, schedule, grades, financials, holds, notifications, and help links are covered by `StudentDashboardService`; Student Hub pages must wire to that service contract before acceptance.

---

### 3.17 Curriculum Intake & Versioning

**Purpose**: Defines the technical flow for importing, versioning, and modifying academic curricula to ensure data integrity across student enrollments. 

#### 3.17.1 Standardized Curriculum Import Workflow

To mitigate parsing errors from heterogeneous `.docx` and `.xlsx` evaluation forms, the system mandates a **Strict Standardized Template** approach.

- **Package**: `maatwebsite/excel` (Laravel Excel)
- **Template Generation Contract**: The import workflow generates a CSV template and accepts CSV/XLSX uploads through `CurriculumImportService`. A styled XLSX template with locked headers, dropdowns, and instructions may be added later using the same PhpSpreadsheet/Laravel Excel dependency.
- **Unified Template Headers**: `Education Level`, `Program Code`, `Program Name`, `Curriculum Version`, `Effective Year`, `Is Active`, `Year/Grade`, `Curriculum Period`, `Subject Code`, `Subject Title`, `Units`, `Weekly Contact Hours`, `Academic Subject Type`, `Scheduling Group`, `Delivery Rule Override`, `Category`, `Sort Order`.
- **Import Parser**: The import service validates exact headers, rejects unsupported file types, rejects formula-like unsafe cells in text identifiers, and creates a preview batch before commit. Files with zero valid subject rows may create non-committable preview/audit evidence, but they must not be committed.
- **Commit Behavior**: Commit requires `error_rows = 0` and `valid_rows > 0`. It creates or updates programs, subjects, curricula, curriculum subjects, and curriculum-subject scheduling fields, then creates or updates affected readiness scopes as `needs_review`. Commit does not automatically make the curriculum usable for scheduling.

**Scheduling Classification Contract:**

| Field | Storage Target | Contract |
| --- | --- | --- |
| `Weekly Contact Hours` | `curriculum_subjects.weekly_contact_hours` | Scheduler-facing load/duration field; do not use `subjects.lec_hours` or College `Units` as meeting duration. `0.00` is allowed only for modular/no-recurring-meeting rows with `Scheduling Group = modular`; synchronous demand requires a positive value. |
| `Academic Subject Type` | `curriculum_subjects.academic_subject_type` | Academic meaning such as `general_education`, `professional_tesda`, `core`, `applied`, `specialized`, or `tvl`. |
| `Scheduling Group` | `curriculum_subjects.scheduling_group` | Operational bucket such as `minor`, `major`, `modular`, or `online_only`. |
| `Delivery Rule Override` | `curriculum_subjects.delivery_rule_override` | Nullable constrained code: blank, `force_online`, `force_on_site`, `force_modular`, or `exclude_from_auto_schedule`. Free text is not allowed. |

**Readiness Behavior:**

- Curriculum imports may be partial by College program, curriculum version, and term. Scheduling readiness is scoped; a missing or incomplete College curriculum blocks only the affected College scheduling scope.
- A committed curriculum scope starts as `needs_review`.
- `CurriculumScopeReadinessService` marks a scope `ready_for_scheduling` only when the scope has at least one valid subject row, required weekly contact hours, confirmed classification, valid delivery override, no unresolved import errors, and any required exception reason.
- Existing legacy curriculum/subject rows stay in the database, but their scheduling readiness becomes `needs_review` until the new scheduling fields are confirmed.

#### 3.17.2 Versioning as the Source of Truth

Curricula are not mutable "living documents" for active batches. They are versioned to preserve historical integrity.

- **New Versions**: When the institution adopts a new curriculum, the Academic Head uploads a new template, which creates a new `Curriculum` record (e.g., "BSIT 2025-2026").
- **Student Binding**: Existing students remain bound to the `Curriculum` version active during their admission year. The system maps their progress strictly against their designated version.

#### 3.17.3 Mid-Year Edit Protocols

Major mid-year curriculum overhauls are restricted to prevent data corruption. However, clerical corrections are supported:

- **Clerical Edits**: The **Academic Head** can use the Filament UI (`CurriculumSubjectResource`) to directly edit a specific subject mapping (e.g., correcting a typo or prerequisite linkage) without re-uploading an entire Excel sheet.

### 3.18 Academic Calendar Setup Architecture

The academic calendar is separated into two hierarchical models for College operations: `academic_years` and `terms`. `academic_years` define the school-year umbrella, while `terms` define the actual operational windows used by enrollment, scheduling, faculty availability, billing, class lists, and grade workflows.

**Hierarchy**:
1. **Academic Year** (`academic_years` table): Defines the top-level boundary.
   - `academic_year` (string, e.g., "2025-2026")
   - `school_year_start_date` (date)
   - `school_year_end_date` (date)
   - `status` (enum: `draft`, `active`, `closed`, `archived`)
   - `reference_note` (string, nullable)
2. **Term** (`terms` table): Inherits from an Academic Year and uses the canonical calendar contract:
   - `academic_year_id` (foreign key)
   - `term_name`, `term_type` (semester / summer)
   - Date ranges: `term_start_date`, `term_end_date`, `class_start_date`, `class_end_date`
   - Operational Gates: `scheduling_starts_at`, `enrollment_starts_at`, `enrollment_ends_at`, `late_enrollment_ends_at`, `credential_submission_cutoff`, `payment_deadline`, `adjustment_ends_at`

**Admin Interface Contract**:
- Target staff workflow is two-level College calendar setup: create/select the Academic Year, then create Terms under it. Terms remain the operational records used by services.
- `AcademicYearResource` provides Registrar/Academic Head create/view/edit for parent Academic Year rows and blocks delete/bulk-delete. `TermResource` provides Registrar/Academic Head create/view/edit for child operational terms and requires Academic Year selection through the `academicYear` relationship.
- `AcademicYearPolicy` reuses `manage-terms` for create/update and `view-global-records` for read-only visibility. Delete, restore, and force-delete are always denied to preserve calendar history.
- The Academic Year interface must not expose offered-level branching in the active deployment; College is the only offered level.
- The Term interface must remain the place for operational dates and gates: class dates, enrollment dates, late-enrollment cutoff, credential-submission cutoff, payment deadline, adjustment cutoff, and scheduling start.

**Phase Locking Middleware / Service Rules**:
- **Enrollment Service**: Throws exceptions if current timestamp is outside `[enrollment_starts_at, enrollment_ends_at]` for the applicable College term, if no approved admission-capacity plan resolves, or if required document grace cannot fit the configured credential cutoff.
- **Scheduling Service**: Faculty availability and draft generation are blocked if `scheduling_starts_at` is null or if current time > `scheduling_starts_at`.
- **Adjustment Gate**: Enrollment-affecting edits (irregular placements, drop/adds, section transfers) are allowed only within configured term adjustment windows. Outside the window, the system hard-blocks edits unless an approved late-edit override path exists.
- **Late Enrollment Gate**: If enabled, `late_enrollment_ends_at` is the final cutoff for any late-enrollment path; after this timestamp, no enrollment-affecting action is allowed.
- **Credential Gate**: `credential_submission_cutoff` is the ordinary finalization boundary. A requirement-specific regulator strategy may resolve a different deadline, but a generic Registrar extension cannot silently exceed the applicable authorized boundary.
- **Payment Deadline Gate**: `payment_deadline` controls the initial required payment cutoff for term activation/clearance workflows.

**College Cutover Activation (Approved)**:
- College migration cutover activates at the next College semester boundary.
- Cutover must not be executed mid-term.

**Required Cutover Config Entries Before F1 De-Deferral**:
- `college_cutover_effective_term` + `college_cutover_effective_datetime`

#### 3.18.1 Academic Load & Summer Scheduling Contracts (Deferred)

**Status**: Deferred business-policy module. The foundation schema contains scheduling tables, but load splitting and summer scheduling services require enrollment/payment services and PHPUnit coverage.

**30-Unit Load Cap**:

-   `AcademicLoadValidator` must reject any proposed regular-term enrollment load above **30 academic units**.
-   The validator must run for regular promotion, College irregular selection, transferee subject selection, Registrar manual assignment, and adjustment-period changes.
-   Registrar overrides cannot bypass the 30-unit cap under the approved policy. Any overload exception requires an Academic Head override policy and its own audit contract.
-   Unit totals must be calculated from the authoritative curriculum/subject records, not from client-provided totals.

**Automatic Summer Split**:

-   `SummerLoadSplitService` receives an eligible subject list and separates subjects into regular-term and proposed summer buckets when the 30-unit cap, failed/back subjects, or schedule conflicts prevent all subjects from fitting in the regular term.
-   The split is advisory until the Registrar confirms it.
-   The service must not create official enrollments, class sections, ledger entries, or faculty assignments by itself.
-   Summer split output must preserve prerequisite ordering and identify subjects that remain unscheduled because no valid summer term/class exists.

**Manual Summer Scheduling Panel**:

-   Registrar owns summer class opening, section assignment, schedule publication, and conflict resolution.
-   Accounting owns tuition effects for summer loads and any shifting-related fees; installment milestone effects remain review scope.
-   Faculty see summer assignments only after Registrar commitment.
-   Student-facing output must distinguish `proposed_summer`, `confirmed_summer`, and `unscheduled` subjects.

### 3.19 Controlled Domain Import Services

**Purpose**: Reuse one auditable batch lifecycle while keeping every imported domain explicit and independently validated.

#### 3.19.1 Shared Import Infrastructure

`import_batches` stores the importer type, template version, private source path, checksum, uploader, status, row counts, validation report, committed timestamp, and audit references. The shared lifecycle is:

1. Download the importer-specific template.
2. Upload a private CSV/XLSX file.
3. Validate exact headers, row types, references, permissions, and duplicate rules.
4. Present a preview and downloadable error report.
5. Commit through the importer-specific service inside the required transaction strategy.
6. Preserve source, result counts, actor, and audit evidence.

Shared infrastructure may provide file handling, batch state, error reporting, and audit helpers. It must not expose arbitrary table/entity selection, column mapping, freeform transformations, or a universal row writer.

#### 3.19.2 Approved Importers

| Importer | Owner | Decision | Technical boundary |
| --- | --- | --- | --- |
| Curriculum/foundation | Registrar / Academic Head | Keep | Dedicated templates and validators for programs, subjects, curricula, curriculum subjects, terms, sections, and approved scheduling inputs. |
| Legacy student baseline | Registrar | Keep | Dedicated student identity/profile template. Creates only verified baseline profiles and inactive accounts; no balance, grade, payment, or enrollment posting. |
| Legacy grades | Registrar / Academic Head | Review | Implement only after actual source data, authority, duplicate rules, grading-profile mapping, and correction policy are approved. |
| Legacy finance | Accounting | Review | Implement only after actual source data, immutable opening-balance policy, reconciliation evidence, and maker-checker rules are approved. |
| Legacy enrollment | Registrar | Review | Implement only after actual source data, term/curriculum mapping, status semantics, and historical-record authority are approved. |

Each importer owns a named service, fixed template, typed row DTO/validator, authorization policy, commit rules, and focused tests. Approval of shared infrastructure does not approve every importer.

#### 3.19.3 Verification

Focused tests cover wrong headers, invalid references, duplicate rows, unauthorized access, preview without writes, atomic/controlled commit, retry/idempotency, private source access, and audit evidence. Review-scope importers have no active route, permission, seeder, or UI until separately approved.

---

### 3.20 Role-Scoped Operational Dashboards

**Purpose**: Give each staff role a concise work queue, not a broad business-intelligence product.

| Role | Approved dashboard content |
| --- | --- |
| Registrar | Pending applicant/document reviews, enrollment readiness exceptions, placement issues, schedule readiness, and enrolled-roster access. |
| Accounting | Pending manual payment evidence, provider exceptions, reconciliation work, and finance-clearance exceptions. |
| Faculty | Assigned classes, schedule, pending grade encoding/submission, and availability status. |
| Academic Head | Grade-change approvals, academic setup/scheduling exceptions, and policy-owned review actions. |
| System Super Admin | Staff account lifecycle, security-sensitive audit events, failed jobs/integration health, and configuration exceptions. |

Dashboard cards link to policy-gated canonical resources or service actions. Counts are derived from canonical tables with role and term scoping; widgets must not duplicate domain state. Broad BI trends, revenue analytics, pass-rate charts, cohort analysis, and configurable report builders are outside active scope.

The grade-submission widget remains a review candidate because the underlying faculty grade workflow is required but can be operated from the assigned-class/grade resource without a separate dashboard widget.

Focused tests cover role visibility, direct component authorization, term scoping, count accuracy, empty states, and absence of cross-role or student-private data.

---
## 4. Security Implementation

### 4.1 Identity and Access Baseline Contract

This section applies the benchmark matrix identity/access rule to the technical baseline. Laravel Fortify owns login, logout, password reset, email verification, password updates, and configured login throttling. Role and permission ownership uses Spatie Permission plus Laravel policies, while Filament and Livewire enforce the same authorization at navigation, route, resource, record, and action boundaries.

| Contract area | Technical baseline |
| --- | --- |
| Public access | Public routes are allowlisted: landing page, FAQ/help, admission requirements, applicant intake, applicant status/recovery, password reset, email verification, public verification pages, static assets, health checks, and signed/externally-called integration callbacks where explicitly defined. |
| Protected staff access | Admin Nexus routes require authentication, active account status, a supported staff role, and resource/action policy authorization. Navigation hiding is not sufficient; direct URL access must return a safe denial. |
| Protected Student Hub access | Student Hub routes require authentication, `users.status = active`, the `student` role, and an owned student profile context. Livewire persistent middleware must preserve this boundary across component actions. |
| Applicant boundary | Applicant accounts may access applicant intake/progress only. Official handover invalidates the applicant session and requires the active student login path before Student Hub access. |
| One-role staff model | Staff accounts receive exactly one approved operational staff role unless a later approved role model changes this. Composite access must be expressed as reviewed permissions, not by assigning conflicting operational roles ad hoc. |
| Login throttling | Repeated invalid login attempts are throttled by the configured Laravel/Fortify rate limiter keyed by identity and request context. The implementation must not promise a custom lockout duration in FS/system acceptance unless `config/fortify.php` and tests prove it. |
| Password recovery | Password-reset routes use the configured Laravel/Fortify broker and safe responses. Reset, verification, and recovery flows must not reveal protected account existence through different user-facing messages. |
| Logout and session expiry | Logout invalidates the authenticated session. Archived/inactive account transitions must invalidate existing sessions or make the next request fail the active-account middleware. |
| Account lifecycle | Staff archive/restore actions use service-backed transactions, clear roles on archive, require an official reason, block self-archive, and write activity-log evidence. Student/applicant lifecycle transitions are separate academic/admission workflows and must not reuse staff archive as academic status. |
| Audit and error handling | Staff account role assignment, account archive/restore, critical data access/export, generated verification state changes, payment/grade/document lifecycle actions, and failed authorization attempts where useful must create audit evidence. Role/permission definitions are release-controlled and have no runtime mutation path. Safe 403/419/429/503 responses must not expose stack traces, SQL errors, private paths, or raw provider secrets. |

### 4.2 RBAC Stack

**Package**: `spatie/laravel-permission`

### 4.3 Audit Trails and Accountability

**Package**: `spatie/laravel-activitylog`

Audit coverage is event-driven and purpose-limited. Required events include staff role assignment and archive/restore; protected export/access where policy requires; applicant/document decisions; enrollment/placement transitions; ledger/payment/adjustment posts; schedule generation commitment, override, approval, and publication; grade submission/finalization/correction; and generated-artifact issue/revoke/supersede.

Each entry records an event key, actor, subject identifier/type, occurred time, approved reason or decision reference, correlation/batch identifier where useful, and an allowlisted before/after summary. It must not store passwords, password hashes, reset/session/API tokens, provider credentials, full private documents, unrestricted request payloads, or unnecessary medical/support/financial detail.

`ActivityResource` is System Super Admin list/view only and uses explicit policy registration for the vendor activity model. Detail views render readable labeled evidence and never expose editing or destructive actions. Retention follows the effective institutional records/privacy policy and legal holds; indefinite retention is not assumed.

Critical integrity/security events may create a minimized System Super Admin alert. The alert links to authorized evidence rather than copying restricted data into notification content.

---
### 4.4 Role Definitions

Approved role definitions are implemented in `database/seeders/DatabaseSeeder.php`. The approved role set is: `applicant`, `student`, `registrar`, `accounting`, `faculty`, `academic-head`, and `system-super-admin`. Do not add a generic `admin` role or a separate scheduling officer role without an approved role-model change.

### 4.5 Permission Matrix

The permission seed mapping is implemented in `database/seeders/DatabaseSeeder.php`. This specification defines the ownership rules and authority boundaries; the seeder file is the implementation reference for concrete permission slugs and role assignments.

**Scheduling Ownership Rule**: Do not create a separate Scheduling Officer role. The existing `registrar` role owns term academic setup, availability period management, draft generation, conflict resolution, final schedule publication, and schedule export. Scheduling hard constraints have no role-based override. The `academic-head` role may retain separately defined non-scheduling academic approvals such as finalized-grade correction. The `system-super-admin` role remains limited to system/user/settings operations and does not receive academic schedule write access.

**Academic Head Finance Visibility Rule**: The `academic-head` role may view only read-only finance status surfaces and fee template/downpayment rules. It must not access Accounting payment queues, confirmed-payment ledgers, full ledger-entry review, installment milestone maintenance screens, or promissory queues if those review features are later promoted. Academic Head has no finance mutation actions: no payment processing, no assessment creation, no discount application, no promissory approval, and no installment policy edits.

### 4.6 Middleware Implementation



```php
// app/Http/Middleware/CheckRole.php
public function handle($request, Closure $next, ...$roles)
{
    if (!$request->user() || !$request->user()->hasAnyRole($roles)) {
        abort(403, 'Unauthorized action.');
    }

    return $next($request);
}
```

**Usage in Routes**:



```php
Route::middleware(['auth', 'role:registrar'])->group(function () {
    Route::post('/documents/{id}/approve', [DocumentController::class, 'approve']);
});
```

---

### 4.7 Audit Implementation Contract

Lifecycle services emit named activity events only after authorization and successful state validation. When the domain mutation is transactional, its audit record is written in the same transaction or through a reliable after-commit mechanism that preserves correlation.

The `LogsActivity` trait may be used only with an explicit allowlist, dirty-value filtering, and minimized properties. Complex approval and integration workflows use explicit `activity()` calls so the event name, reason, correlation, and authoritative actor are unambiguous.

Focused tests cover required-event creation, actor/subject linkage, allowlisted change summaries, unauthorized action denial, secret/sensitive-field exclusion, read-only audit UI, policy-driven retention metadata, and minimized critical alerts.

---
## 5. Frontend Implementation

### 5.1 Design Philosophy

-   **Core Aesthetic**: **“Filament-like” with Default TallStackUI Components**. The design should mirror the clean, data-first, and functional aesthetic of the Filament staff panel. To achieve this predictably and maintainably, the frontend relies heavily on the **native styling of TallStackUI components**.
-   **Visual Style**: **Minimalist, Modern, & Robust**.
    -   Use generous whitespace (padding/margin provided by standard TallStackUI containers)
    -   Use subtle borders (`border-zinc-200` / `dark:border-zinc-700`) and soft shadows (`shadow-sm`) as defined by the default TallStackUI theme
    -   **Solid & Unified Interfaces**: We are pivoting *away* from custom Glassmorphism. Instead of using complex translucent backgrounds or manual UI blurs (`backdrop-blur-md`), we rely entirely on TallStackUI’s standard, accessible `<x-card>`, `<x-modal>`, and structured headers. These provide baked-in, polished Tailwind styles out-of-the-box, ensuring consistency without custom utility clutter.
-   **Mobile-First**: Design defaults to mobile layouts (stacked columns) and enhances for larger screens using TallStackUI’s responsive patterns

---

### 5.1.1 Goal-State UI Acceptance Contract

The UI goal state must be testable without requiring pixel-perfect design assertions. Every screen or workflow named in the FS must define the actor, entry route, authorized component surface, validation/feedback behavior, business-state effect, and failure behavior.

| Surface | Approved Component Pattern | Acceptance-Test Expectations |
| --- | --- | --- |
| Public/applicant forms | Blade/Livewire pages using TallStackUI-compatible cards, inputs, selects, file-upload controls, alerts, and toasts | Required fields show inline validation errors; file inputs enforce type/size policy; duplicate or unavailable-offering cases fail closed with user-safe messages; successful submissions create auditable applicant/intake records. |
| Student Hub dashboard and tabs | Livewire components with TallStackUI cards, badges, tabs/navigation, tables or stacked mobile cards, notification list, and PWA read-only cache | Dashboard data comes from service contracts such as `StudentDashboardService`; approved PayMongo payment entry routes through finance services; no document-request, credential-request, courier, or generic service-request buttons are exposed; offline mode shows cached read-only COR/schedule/grades and disables online-only actions. |
| Student Hub PWA launch/loading layer | `erag/laravel-pwa` directives in the Student Hub layout, `public/manifest.json`, service worker/offline fallback files, generated icon/splash assets, TallStackUI `Button` loading/`Progress`/`Alert` components, Livewire `wire:loading` and `wire:offline`, and Tailwind `motion-safe`/`motion-reduce` variants | Installed app launch shows branded splash behavior where the platform supports it; route changes and slow service-backed sections show stable skeleton/progress feedback; approved online actions disable duplicate submits; progress indicators are labelled; offline states block mutation and show safe fallback messaging. |
| Staff Admin Nexus lists | Filament v5 `Resource`/`Page` table surfaces with filters, searchable relationship labels, badges, infolists, and widgets | Tables must show human-readable labels instead of raw IDs; filters map to approved business states; empty states are allowed; navigation visibility follows policies/permissions. |
| Staff lifecycle actions | Filament v5 actions with modal forms, typed `Select`, `Toggle`, `Repeater`, `FileUpload`, `Textarea`, confirmation prompts, and notifications | Lifecycle actions call service/model-owned transitions, require reasons/evidence where documented, block invalid transitions, and emit success/error notifications using the FS message catalog. |
| Staff dashboards/readiness views | Filament widgets/pages with scoped filters, cards/stats, tables, and drilldown links | Widgets/pages may show zero/empty states until data exists, but must not fabricate readiness; blocker lists and drilldowns must reflect service output and role visibility. |
| Security and access feedback | Laravel/Fortify auth, Filament auth redirects, policy denials, TallStackUI/Filament notifications, branded error pages | Unauthorized users are redirected or receive safe 403/validation messages; inactive or wrong-role accounts cannot enter protected areas; rate limiting follows the implemented Laravel/Fortify configuration rather than an undocumented hardcoded attempt count. |

The master test-case file must use the professor-required columns exactly. Because that format has no separate Expected Result or Execution State columns, expected behavior belongs in the scenario/action text, while execution state belongs in `Comments/Suggestion`.

---

### 5.2 Color Palette

| Token | Value | Usage |
| --- | --- | --- |
| **Primary** | `#235EC7` (Deep Blue) | Primary Buttons, Active Tabs, Links, Headers |
| **Accent** | `#FFEB3C` (Yellow) | Critical Warnings, “Pay Now” actions, Highlighted Status badges |
| **Neutral** | Zinc / Slate Scale | Background: `bg-zinc-50`. Text: `text-zinc-900` (Headings), `text-zinc-500` (Body) |

---

### 5.3 Typography

-   **Font Family**: `Inter`
-   **Source**: Local File Path: `public/fonts/filament/filament/inter`
-   **Usage**:
    -   **Headings**: Semibold / Bold
    -   **Body**: Regular / Medium
    -   **Numbers/Data**: Tabular nums (for financial tables)

**CSS Configuration**:

css


```css
@font-face {    font-family: 'Inter';    src: url('/fonts/filament/filament/inter/Inter-Regular.woff2') format('woff2');    font-weight: 400;}@font-face {    font-family: 'Inter';    src: url('/fonts/filament/filament/inter/Inter-SemiBold.woff2') format('woff2');    font-weight: 600;}
```

---

### 5.4 Iconography

-   **Library**: **Heroicons**
-   **Style**:
    -   **Outline**: Default for navigation and UI elements
    -   **Solid**: Active states (e.g., Selected Tab)

---

### 5.5 Motion & Behavior

-   **Transitions**: Keep state changes fast and purposeful. Avoid decorative animation; any movement must support orientation, feedback, or perceived progress.
-   **Loading States**:
    -   **PWA launch/splash**: Student Hub installability uses the existing `erag/laravel-pwa` package, `@PwaHead`, `@RegisterServiceWorkerScript`, `config/pwa.php`, `public/manifest.json`, service worker files, and icon/splash assets. No new PWA dependency is approved for this baseline. After changing `config/pwa.php`, run `php artisan erag:update-manifest`.
    -   **Platform behavior**: Android splash behavior is generated from manifest `name`, `background_color`, `theme_color`, and `icons`; iOS/iPadOS requires explicit `apple-touch-startup-image` links or package-generated equivalents for device-specific startup images. Acceptance tests must treat unsupported platform behavior as a documented compatibility note, not a Laravel failure.
    -   **Global navigation**: Page transitions use a top-bar progress loader or Livewire navigation loading state. The loader must not cover validation errors, field focus, or critical action buttons after the request completes.
    -   **Local actions**: Submit buttons use TallStackUI button `loading` behavior or Livewire `wire:loading.attr="disabled"` with a clear spinner/text state to prevent double submission.
    -   **Uploads**: Heavy file uploads and import previews show labelled progress using TallStackUI `Progress` or a native `<progress>` element with an accessible label. Upload success/failure then moves to the normal status/toast layer.
    -   **Offline states**: Online-only controls use `wire:offline.attr="disabled"` or equivalent Livewire/Alpine state. Offline warnings must be visible near the affected action and must not queue hidden payments, uploads, grade corrections, enrollment changes, document requests, or credential requests.
    -   **Accessibility and reduced motion**: Loading regions set `aria-busy` or status text where appropriate. Tailwind animation utilities must use `motion-safe:`/`motion-reduce:` variants for spinners, shimmer, and transitions.

---

### 5.6 Enrollment Portals (Dual-Stack Approach)

#### 5.6.1 The “Applicant Intake” Portal (Public)

-   **Tech Stack**: Laravel Livewire (Guest Layout)
-   **Auth Guard**: `applicant` (Separate session driver)
-   **Key Components**:
    -   **Orientation Modal**: Required “I Agree” acknowledgements
    -   **Student Profiling**: Includes **Modality Selection** (On-Campus vs Modular) and intake fields used for automated freshmen discount eligibility
    -   **File Upload**: Standard `input type="file"` with **manual document type selection** (dropdown: PSA, Report Card, etc.)
    -   **Status Board**: Read-only view of application status
-   **Lifecycle**: Account transitions to `student` role upon enrollment approval (no migration needed)

---

#### 5.6.2 The “Student Hub” PWA (Private)

-   **Tech Stack**: Laravel Livewire + TallStackUI (Auth Layout)
-   **Auth Guard**: `web` (Standard User table)
-   **Key Components**:
    -   **Dashboard**: Student profile, enrollment status, holds/notices, and notifications
    -   **Enrollment/COR**: Current enrollment state and COR view/download when eligible
    -   **Accounts**: Balance, payment status, latest confirmed payments, and approved PayMongo payment entry
    -   **Schedule**: Published class schedule reference
    -   **Grades**: Read-only finalized grade history
    -   **Help**: Published FAQ/help entries
    -   **Offline Read-Only Cache**: Approved COR, schedule, and grade data with freshness labels
-   **Access**: Only accessible via Official Student Credentials (emailed upon acceptance)
-   **Implementation Boundary**: Student Hub routes and SFC pages live under `resources/views/pages/student-hub/`, the Student layout includes PWA directives, and access control is covered by feature tests. `StudentDashboardService` is the backend aggregate source for owned profile, enrollment, schedule, financial, grade, hold, notification, and FAQ/help data. Dashboard, Enrollment/COR, Schedule, Grades, Financials, and Help must wire to the service contract, loading states, empty/error states, offline guards, and PWA acceptance before they can be marked passed. A Student Hub Documents tab, document-request workflow, generic service-request workflow, credential-request workflow, and courier tracking are removed from active scope. Student-side enrollment or grade-correction mutation UI remains review-only unless separately promoted by feature audit.

---

### 5.7 Support & IT Automation

To minimize Super Admin operational overhead and eliminate the security risks of manual IT requests, the system enforces automated self-service.

-   **Password Resets (Staff/Admin)**: Enabled via Filament’s native `->passwordReset()` panel feature. Sends automated reset links. Manual resets are strictly prohibited.
-   **Password Resets (Students)**: Handled via standard Laravel Fortify/Breeze integration on the Student Hub login.
-   **General Inquiries (FAQ)**: Handled by a public `/faq` route using TallStackUI’s `<x-accordion>` to preemptively address common IT/Enrollment questions. No internal “Ticketing System” will be built to prevent scope creep and module duplication.

---

### 5.8 Navigation & Access (Student Portal)

#### 5.8.1 Global Navigation

-   **Type**: **Sticky Top Navbar** (Desktop & Mobile). No Sidebar.
-   **Branding**: School Logo + “Student Portal” on left. Profile Dropdown on right.

#### 5.8.2 The 5 Main Tabs (Logic & Ordering)

| Tab | Content | Goal |
| --- | --- | --- |
| **1\. HOME (Dashboard)** | Student Profile Summary (Name, ID, Program) + **Notification Center** (Latest on top). Displays system-triggered alerts (e.g., requirement reviewed, payment confirmed, schedule published) | Immediate “State of Affairs” check |
| **2\. ENROLLMENT** | **State A (Enrollment OPEN)**: Shows “One-Click Enroll” card (Regulars) or “Subject Selection” (Irregulars). **State B (Enrollment CLOSED/Enrolled)**: Shows **Downloadable COR** (PDF) and “You are officially enrolled” status | Never an empty state |
| **3\. ACCOUNTS (Financials)** | Accordion/List grouped by **Semester** (Latest first). Card Content: `Term Label`, `Total Assessment`, `Total Paid`, `Remaining Balance`. **Pay Now** action appears only when an approved online-payment route is active and a payable balance exists. | Historical view of finance evidence |
| **4\. SCHEDULE** | Current Term’s Class Schedule (Subject, Time, Room, Instructor). List View (Mobile) / Table View (Desktop) | Class schedule reference |
| **5\. GRADES** | Latest Term to Oldest Term. Separate visual blocks (Divs) per Term. Content: Subject Code, Description, Grade, Remarks (Passed/Failed) | Academic history |

**Student Dashboard Backend Contract**: `App\Actions\StudentHub\StudentDashboardService` is the read-only data contract for the Student Hub dashboard and tab UI. It accepts a `StudentProfile` resolved by the authenticated active student boundary and returns profile context, current enrollment/history, current schedule, financial balance and term summaries, latest confirmed payments, finalized grade history, holds, latest notifications, and published FAQ/help links.

**Data Scope Rules**:
- Schedule rows come from the current enrollment's section/term and, when present, its section delivery group.
- Grades are limited to finalized grade records attached to the student's enrollments.
- Financial summaries are limited to the student's ledger/payment records and do not treat promissory notes as clearance.
- Hold and notice summaries are limited to the student's profile or student user account.
- FAQ/help output includes only published FAQ entries.
- The service does not submit enrollment, document, credential, service-request, courier, or grade-correction mutations. Approved PayMongo payment entry uses the finance service path and remains online-only.
- PWA caching of protected Student Hub data must be opt-in by data family. A generic offline fallback does not prove safe cached COR/schedule/grade pages, freshness labels, clear-on-logout behavior, or offline mutation denial.

---

### 5.9 Mobile Responsiveness (Critical)

| Component | Desktop | Mobile |
| --- | --- | --- |
| **Menu System** | Sticky Top Navbar with labeled tabs | Sticky Top Navbar with **Hamburger Menu** (Or Bottom Nav) |
| **Tables** | Standard Data Table | “Card View” (Stacked). Row data transforms into Card component |
| **Modals** | Centered Modal | Bottom Sheet (Slide-up Panel) |

---

### 5.10 Special Frontend Workflows

#### 5.9.1 Transferee “Waiting Room”

-   **Status View**: When `For_Evaluation`, the student dashboard is **LOCKED**
-   **UI Layout**: A full-screen “Application Under Review” card
-   **Elements**:
    -   Timeline/Tracker: “Docs Submitted (Check) → Evaluation (Current) → Selection (Locked)”
    -   “Check Status” Button: simple refresh

---

#### 5.9.2 Registrar Walk-In Entry

-   **Tech Stack**: Filament (Staff Panel Feature)
-   **Definition**: A dedicated “Walk-In” form accessible only to Registrar staff
-   **Workflow**: Resolves the same active requirement set used by self-service intake and materializes the same applicant checklist. Registrar may record a physical inspection without an attachment or use an optional Filament `FileUpload` targeting the private disk. Walk-in evidence and later scans remain subject to the same manual review and versioned requirement history.
-   **Identity Contract**: The walk-in form captures `first_name`, `middle_name`, `last_name`, and `suffix` into the `users` account row and lets the model/service layer compose `users.name`; academic identifiers such as `student_id`, `lrn`, `program_id`, and `year_level` remain on `student_profiles`.

---

### 5.11 UI States & Feedback

#### 5.10.1 Loading States



```php
// Livewire component example
public function submit()
{
    $this->dispatch('show-spinner');

    // Prevent double submission
    $this->dispatch('disable-button');

    // Process...
}
```



```blade
<!-- Blade component -->
<button
    wire:click="submit"
    wire:loading.attr="disabled"
    class="btn-primary"
>
    <span wire:loading.remove>Submit</span>
    <span wire:loading>
        <x-spinner /> Processing...
    </span>
</button>
```

#### 5.10.2 Empty States



```blade
@forelse($grades as $grade)
    <x-grade-card :grade="$grade" />
@empty
    <div class="empty-state">
        <x-icon name="academic-cap" class="w-16 h-16 text-zinc-300" />
        <p class="text-zinc-500">No grades available yet.</p>
    </div>
@endforelse
```

#### 5.10.3 Error Handling & Standard Toast Templates

This section defines the implementation patterns for the Standard User-Facing Message Templates catalog established in Functional Specification §8.2.3. The system uses two distinct notification mechanisms depending on the UI surface:

**Admin Nexus (Filament Panel) — Filament Notifications**:

```php
use Filament\Notifications\Notification;

// Success toast
Notification::make()
    ->title('Saved Successfully')
    ->success()
    ->body('Your changes have been saved.')
    ->send();

// Blocking toast (temporary outage)
Notification::make()
    ->title('Service Temporarily Unavailable')
    ->danger()
    ->body('The system is temporarily unavailable. Please try again later.')
    ->persistent()
    ->send();

// Error toast (server error fallback)
Notification::make()
    ->title('Something Went Wrong')
    ->danger()
    ->body('An unexpected error occurred. Please try again. If the issue persists, contact support.')
    ->duration(8000)
    ->send();
```

**Student Hub (TallStackUI) — Toast Component**:

Requires `<x-toast />` in the layout and the `HasInteractions` trait on Livewire components.

```php
use TallStackUi\Traits\Interactions;

class StudentDashboard extends Component
{
    use Interactions;

    public function submitPayment(): void
    {
        // ... payment logic ...

        // Success toast
        $this->toast()
            ->success('Payment Submitted', 'Your payment reference #' . $ref . ' has been submitted for confirmation.')
            ->send();
    }
}
```

**Toast Template Registry (Implementation Mapping)**:

The full message catalog is defined in Functional Specification §8.2.3. Developers must use those exact titles and message templates. Below is the mapping to implementation concerns:

| Toast Title | Filament Method | TallStackUI Method | Auto-Dismiss |
| --- | --- | --- | --- |
| Saved Successfully | `->success()->send()` | `->success()->send()` | 5s |
| Payment Submitted | `->success()->send()` | `->success()->send()` | 5s |
| Document Uploaded | `->success()->send()` | `->success()->send()` | 5s |
| Grades Submitted | `->success()->send()` | N/A (Faculty uses Filament) | 5s |
| Missing Required Fields | `->warning()->send()` | `->warning()->send()` | 6s |
| Invalid File Format | `->warning()->send()` | `->warning()->send()` | 6s |
| Action Not Permitted | `->danger()->send()` | `->error()->send()` | 8s |
| Financial Hold Active | `->danger()->send()` | `->error()->send()` | 8s |
| Processing Request | `->info()->send()` | `->info()->send()` | 5s |
| Schedule In Progress | `->info()->send()` | N/A (Registrar uses Filament) | 5s |
| Something Went Wrong | `->danger()->duration(8000)` | `->error()->send()` | 8s |
| Service Temporarily Unavailable | `->danger()->duration(8000)` | `->error()->send()` | 8s |
| Session Expired | `->danger()->send()` | `->error()->send()` | 8s |

**Validation Errors**: Field-level validation errors are rendered as red text below the input field by default in both Filament form fields and TallStackUI form components. These are not toasts.

**Global Exception Handler** (`bootstrap/app.php`):

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Symfony\Component\HttpKernel\Exception\HttpException;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (HttpException $e, $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => match ($e->getStatusCode()) {
                    403 => 'You do not have permission to perform this action.',
                    404 => 'The requested resource was not found.',
                    419 => 'Your session has expired. Please log in again.',
                    503 => 'The system is temporarily unavailable. Please try again later.',
                    default => 'An unexpected error occurred. Please try again. If the issue persists, contact support.',
                },
            ], $e->getStatusCode());
        }
    });
})
```

**Error Pages**: Safe branded `403`, `404`, `500`, and `503` responses may follow SIA institutional branding without exposing stack traces or private implementation details.

---

### 5.12 Student Portal Development Workflow (AI/Developer Guide)

To ensure consistency, performance, and structure when developing the student-facing interfaces, developers and AI agents must follow this multi-layered implementation sequence:

```mermaid
graph TD
    A[1. Layout & Design System <br> ui-ux-pro-max + Tailwind] --> B[2. Security & Auth Gates <br> Fortify + Routing Security]
    B --> C[3. Livewire & TallStackUI <br> Dynamic UI Interactivity]
    C --> D[4. PWA Manifest & Caching <br> Offline Support & Manifest]
    D --> E[5. Test-Driven Verification <br> Feature Tests + Pint]
```

1. **Layout & Design System (`ui-ux-pro-max` + `tailwindcss-development`)**:
   - Establish custom theme values (like `#235EC7` for Primary and zinc/slate palettes for Neutral colors).
   - Use `ui-ux-pro-max` styling rules for spacing, standard typography (Inter), and layout containers.
   - Restrict page structures to standard responsive grids and flex layouts, eliminating ad-hoc spacing utilities.

2. **Security & Authentication Gates (`fortify-development` + `laravel-security`)**:
   - Set up student-routing gates using the standard `web` guard.
   - Customize Laravel Fortify action redirects to target the Student Hub landing route dynamically.
   - Secure files and student uploads via temporary signed URLs targeting private disks.

3. **Livewire & TallStackUI Integration (`livewire-development` + `tallstackui`)**:
   - Query the `tallstackui` MCP tools for UI component specifications (modals, slide-overs, tables, accordion, date-picker).
   - Implement interactive modules as structured Livewire components, using server-side states for operations and Alpine.js client-side state for animations and instant micro-interactions.
   - Use dynamic button states (`wire:loading.attr="disabled"`) and standard toast notifications (`use Interactions`) for feedback.

4. **PWA & Offline Support (`laravel-pwa-setup`)**:
   - Register PWA configurations inside `config/pwa.php` and regenerate `public/manifest.json` with `php artisan erag:update-manifest`.
   - Keep `@PwaHead` in the Student Hub layout head and `@RegisterServiceWorkerScript` before `</body>`.
   - Configure service worker caching strategy to ensure the COR (Certificate of Registration), schedule page, and approved read-only dashboard data can be accessed offline.
   - Verify installability over HTTPS or localhost; service workers and install prompts are not reliable on ordinary insecure HTTP.

5. **Test-Driven Verification (`laravel-tdd` + `laravel-verification`)**:
   - Write standard Livewire feature tests asserting user states, forms validation, and component state changes.
   - Verify that all modified files pass the Laravel Pint code formatter check (`vendor/bin/pint --dirty --format agent`).

---

## 6. Third-Party Integrations

### 6.1 Manual Document Review Boundary

TALA does not use automated document text extraction, document classification, confidence scoring, or form-field prefilling.

- The applicant or Registrar selects the declared document type before upload or physical receipt recording.
- Uploaded files are validated, checksummed, stored privately, and linked to the materialized requirement item.
- The Registrar reviews the source evidence, applicant-entered data, provenance, and requirement policy before recording approve, needs-correction, or reject.
- Transferee status and credit evaluation use the selected admission route plus Registrar-entered verified prior-school subjects/grades. No uploaded file automatically changes applicant type, credits, identity, payment, enrollment, or grades.
- Review UI shows the private source preview and structured declared/verified values. It does not expose an editable private path or raw provider payload.
- Automated extraction SDKs, credentials, jobs, commands, provider-specific tables/columns, and related dependencies are prohibited in the active implementation.

---
### 6.2 Email Configuration

**Driver**: Laravel Mail (SMTP)

```php
// config/mail.php'mailers' => [    'smtp' => [        'transport' => 'smtp',        'host' => env('MAIL_HOST', 'smtp.gmail.com'),        'port' => env('MAIL_PORT', 587),        'encryption' => env('MAIL_ENCRYPTION', 'tls'),        'username' => env('MAIL_USERNAME'),        'password' => env('MAIL_PASSWORD'),    ],],
```

**Consolidated General System Notification**:

-   All fragmented notification classes have been removed to prevent system bloat.
-   `GeneralSystemNotification`: A single, unified notification class is used for approved system triggers such as Welcome Applicant, Document Rejection, Payment Confirmation, and Financial Holds.
-   This class accepts a `type` (enum/string), `subject`, and `body` payload to dynamically format both email and in-app notifications.

---

### 6.3 Payment Gateway Integration

**Primary**: PayMongo Checkout API for GCash/E-Wallet payments with webhook-based confirmation (see §3.14.1). Generates checkout session links that students complete via PayMongo’s hosted payment page.

**Fallback**: Manual screenshot upload with Cashier human-in-the-loop verification (Mode B in FS §6.2.1). Ensures payments can be processed even if the gateway is temporarily unavailable.

**Supported Payment Methods (MVP)**:

-   GCash/E-Wallet (via PayMongo Checkout — primary)
-   GCash/Bank Transfer (Screenshot upload — fallback)
-   Over-the-Counter (Manual encoding by Accounting)
-   Promissory Note remains a review candidate only; it is not a payment method and not a document upload.

---

## 7. Implementation & Verification Strategy

### 7.1 Implementation Methodology: Contract-First, Evidence-Backed

| Phase | Activity |
| --- | --- |
| **Foundation Schema** | Use the implemented migration files and foundation migration control log as the schema implementation reference, then verify with Laravel Boost `database_schema` and migration tests. |
| **Domain Services & States** | Implement model states, ledger services, enrollment transitions, document-review services, and authorization policies against the verified schema. |
| **Filament/Livewire UI** | Build Registrar, Accounting/Cashier, Faculty, Academic Head, System Super Admin, and Student Hub screens only after the underlying contracts and services exist. |
| **Integrations, Jobs, and Ops** | Add PayMongo webhooks, CP-SAT solver dispatch, queued notifications, PWA acceptance, database-worker deployment, failed-job visibility, health checks, and provider-neutral operational monitoring after core workflows pass tests. |

**Benefit**: Prevents UI-first implementation drift. CRUD screens are fast only after the schema, state transitions, and ownership rules are stable.

---

### 7.2 Testing Strategy

| Test Type | Scope | Tools |
| --- | --- | --- |
| **Unit Tests** | Service classes, Models | PHPUnit |
| **Feature Tests** | API endpoints, Form submissions, Livewire components | PHPUnit + Laravel HTTP Testing |
| **Integration Tests** | Module workflows | PHPUnit |
| **Browser Smoke / E2E Tests** | Student Hub routes, read-only PWA offline behavior, and critical enrollment/payment flows | Manual browser checks or the already available browser automation toolchain; no mandatory new browser-test dependency |
| **PWA / Loading Acceptance** | Manifest metadata, install prompt/splash behavior where platform-supported, service-worker offline fallback, Livewire loading/offline button states, upload progress labels, and reduced-motion handling | Manual browser/device smoke tests now; Playwright/Lighthouse or device-specific checks only after dependency approval |
| **Performance Checks** | Query counts, pagination, queue latency, solver timeout, representative critical-flow response time, and large-import behavior | Existing Laravel tests, database/query evidence, worker logs, and an approved external load tool only when a measured acceptance target requires it |

---

## 8. Deployment & Operations

### 8.1 Deployment Runtime Profile

The deployment environment must support the locked PHP/Laravel/MySQL versions, HTTPS, private persistent file storage, a persistent database-queue worker, the Laravel scheduler, outbound SMTP, PayMongo callbacks, and authenticated CP-SAT solver dispatch. Hosting vendor, operating-system image, instance size, storage vendor, and scaling topology are selected from measured deployment needs rather than fixed by this specification.

The built-in `/up` health route verifies that the application boots. Deeper readiness evidence is obtained from database connectivity, queue/failed-job state, recent scheduler outcomes, PayMongo webhook processing state, and schedule-generation run state without exposing protected diagnostics publicly.

### 8.2 Production Configuration Boundary

| Area | Required contract |
| --- | --- |
| Application | `APP_ENV=production`, `APP_DEBUG=false`, valid `APP_KEY`, canonical HTTPS `APP_URL` |
| Database | Least-privilege application credentials supplied through the deployment secret mechanism |
| Sessions | Database-backed session or another approved durable driver; secure, HTTP-only, same-site cookies in HTTPS production |
| Cache | Approved Laravel cache store; cache loss must not destroy authoritative SIS state |
| Queue | `QUEUE_CONNECTION=database`, persistent worker, failed-job storage, bounded retry/backoff |
| Files | Private persistent disk with authorized temporary delivery; public disk only for intentional public assets |
| Mail | Institution-approved SMTP credentials and sender identity |
| Payments | PayMongo mode, keys, and webhook secret stored outside source control |
| Scheduling | Solver URL/audience and dedicated invocation credentials stored outside source control |
| Logging | Production log level and channel configured to exclude secrets and unnecessary personal data |

Environment examples must use placeholders only. Real passwords, provider secrets, private keys, and credentials never appear in documentation, source control, logs, test fixtures, or public error output.

### 8.3 Deployment and CI Guardrails

The chosen deployment pipeline or runbook must:

1. Install PHP and Node dependencies from lockfiles.
2. Run focused PHPUnit/static/formatting checks and build production assets.
3. Put the release in the approved migration/deployment state and take the required pre-change backup for destructive/high-risk changes.
4. Run reviewed migrations once against the target environment.
5. Cache production configuration/routes/views only after environment values are present.
6. restart PHP and database-queue workers so long-lived processes load the new release.
7. Verify `/up`, critical routes, worker processing, scheduler registration, storage access, and configured integration smoke evidence.
8. Roll forward or restore through the approved recovery procedure if acceptance checks fail.

The implementation may use any reviewed CI/CD platform. A named provider or repository workflow is not part of the SIS feature contract.

### 8.4 Monitoring and Failure Visibility

TALA exposes the minimum operational evidence needed to detect and diagnose system-owned failures:

- Laravel application logs with personal data and secrets minimized.
- `jobs` and `failed_jobs` visibility for database queues.
- PayMongo callback storage, processed timestamp, exception summary, and idempotent retry evidence.
- Schedule-generation run status, solver diagnostics, timeout/failure state, and correlation identifiers.
- Scheduled-task registration plus latest success/failure evidence for approved tasks.
- Public `/up` health response without sensitive diagnostics.
- Role-scoped System Super Admin alerts for critical security/integrity failures.

Host metrics, uptime probes, centralized error collection, and log aggregation are deployment-selected tools. This specification does not require a named monitoring vendor.

### 8.5 Backup and Restore Contract

Backups protect the relational database, private uploaded/generated files, and the configuration/key references required to restore encrypted or signed data. The deployment must:

- define policy-approved frequency, retention, encryption, access, and off-host/redundant storage;
- preserve database/file consistency or document the recovery reconciliation process;
- protect backups from ordinary application-user access and destructive compromise;
- record backup success/failure;
- perform and document periodic restore verification in a non-production environment;
- retain or dispose audit data according to the same effective institutional policy and legal holds.

Exact schedules, retention days, storage provider, and command syntax belong to the approved deployment runbook, not this system specification.

### 8.6 Security Hardening

| Control | Required implementation |
| --- | --- |
| Transport | HTTPS; trusted-proxy configuration limited to the deployed network model; HSTS only after HTTPS coverage is confirmed |
| Sessions | Secure, HTTP-only, same-site cookies; session regeneration on authentication/privilege change; invalidation for archived/inactive accounts |
| Browser requests | CSRF protection for state-changing browser requests and restrictive CORS for any credentialed API |
| Authorization | Active-account middleware, fixed seeded roles, policies/gates, direct-URL denial, owner/record checks |
| Input and uploads | Form Request/component validation, explicit attribute mapping, extension plus detected MIME checks, size limits, generated storage names, private storage |
| Output | Blade escaping by default, sanitized trusted rich text only, safe errors without stack traces/private paths |
| Integrations | PayMongo signature/idempotency; IAM-private solver audience and dedicated credentials |
| Secrets | Environment/secret-store delivery, no committed credentials, rotation after exposure |
| Headers | Appropriate content-type, framing, referrer, content-security, and HTTPS security headers for the deployed UI/assets |
| Dependencies | Lockfile-controlled installation plus Composer/NPM security auditing and reviewed remediation |

### 8.7 Verification Boundary

Acceptance evidence includes focused PHPUnit tests, authorization and validation tests, migration checks, browser smoke checks for critical staff/student flows, queue retry/failure tests, scheduler registration tests, integration smoke evidence, private-file denial, dependency audits, and backup restore evidence where a deployment target exists. New testing products are introduced only when an approved measurable requirement cannot be verified with the available toolchain.

---
### 8.8 Admin UI Boundary Rules

These rules define stable staff-facing admin boundaries. They are not an execution ledger; QA status and implementation ownership belong in project management artifacts.

| Area | Technical Contract | Deferred / Non-Goal Boundary |
| --- | --- | --- |
| Phase 1 readiness evidence | FS/TS define the required technical boundaries for academic foundation behavior, controlled import, Academic Head grade-change approval, PayMongo sandbox evidence, manual document review, and hidden/internal direct URL denial. | QA execution status and implementation ownership are not maintained in this TS. |
| Academic foundation behavior | Programs, Subjects, Curricula/Curriculum Subjects, Terms, Sections, and Rooms use typed Filament resources/pages with policy guards. Section Planning uses active room catalog selection for physical modalities, and service validation rejects missing, unknown, or inactive rooms. | Bulk setup uses controlled import. Generic bulk delete and unsafe dependent-record deletion remain outside the admin contract. |
| Controlled curriculum/foundation import | `ImportBatchResource` supports curriculum template download, private CSV/XLSX upload, strict row validation, validation preview/error report, zero-error commit, and audit evidence. It exposes no generic create/edit routes and no freeform in-browser spreadsheet repair. | Student, grade, financial, and enrollment legacy imports require separate controlled services before being treated as implemented. |
| PayMongo payment evidence | Payment confirmation is provider/service-owned. `PaymentAttemptResource` and `PaymentResource` are list/view evidence surfaces; webhook processing must be signed, idempotent, and ledger-posting safe. | Generic payment CRUD, raw gateway payload editing, and redirect-only paid status are forbidden. |
| Manual document review | Document review stores private source evidence, typed review state, reviewer metadata, approved verified fields, and lifecycle history without automatic extraction or promotion. | Private path editing, raw payload editing, and automated identity/field promotion are forbidden. |
| Student Hub boundary | `/student/*` is protected by `auth` and `student.active`; published FAQ consumption exists for Help. `StudentDashboardService` provides the read-only dashboard aggregate for profile, enrollment, schedule, financials, finalized grades, holds, notifications, and help links. Approved PayMongo payment entry uses the finance service path. Document-request, credential-request, courier, and generic service-request UI are removed from active Student Hub scope; enrollment-mutation and grade-correction mutation UI remain review-only unless promoted by feature audit. | draft or sample Student Hub screens do not count as backend/admin readiness evidence. Student Hub UI remains deferred until the UI phase is explicitly activated. |
| FAQ content management | `FaqEntryResource` provides System Super Admin CRUD for question, answer, category, sort order, and publish state, guarded by `manage-faqs`. Public `/faq` and Student Hub Help consume only published entries. | Registrar, Accounting, Faculty, Academic Head, Student, and public users cannot mutate FAQ content. FAQ categories remain model-owned fixed options, not arbitrary text. |
| Typed domain configuration | Terms/calendars, curricula, fee templates, and admission policies use dedicated domain models, validated services, policy gates, audit, and cache invalidation where applicable. | Generic settings resources, raw key/value/JSON editors, and arbitrary configuration registries are outside staff functionality. |
| Accounting adjustments | `AccountingAdjustmentService` and `AccountingAdjustmentResource` provide typed debit, credit, and ledger-entry reversal posts with policy checks, immutable ledger posting, balance recalculation, duplicate-reversal blocking, and activity logging. | Generic ledger CRUD, refund shortcuts, raw balance editing, and silent account-handover reversal are forbidden. |
| Promissory notes | Review candidate only. If promoted, promissory notes are Accounting-owned promise tracking, not payment clearance or exam access. | Student Hub UI remains deferred; generic status editing and amount/private-detail exposure remain forbidden. |
| Automatic scheduling solver | Scheduling uses IAM-private GCP Cloud Run OR-Tools CP-SAT, Google ID-token dispatch, immutable snapshots, solver-result ingestion, Laravel validation, draft review, and transactional Registrar publication to `section_meetings` / `section_teacher`. Published rows require 100% hard-constraint validity. | The solver does not create academic sections or choose students, subjects, or faculty. Projected-demand section planning and curriculum readiness precede solving. |
| Faculty assignment and availability | Subject/faculty assignment is Registrar-owned; faculty submit availability only. Eligibility, submitted availability, and configured workload are hard solver/manual-assignment inputs. Registrar alone publishes. | Faculty cannot choose teaching subjects or edit locked availability. Academic Head and System Super Admin have no schedule-publication or hard-constraint bypass authority. Published corrections require a superseding version. |
| Student-status workflow detail labels | Staff-facing status, shift, withdrawal, readmission, and related lifecycle tables/detail views should use relationship-backed labels and model-owned status helpers rather than raw IDs as primary labels. | Raw FK/payload display is only acceptable as internal audit evidence when no staff decision depends on it. |

#### Verification Rules for Admin UI Changes

- Use Filament relationship dot notation for display fields instead of raw foreign-key IDs in staff detail views.
- Use typed Filament components for staff input: `Select`, `Toggle`, `Repeater`, `FileUpload`, and modal `Textarea` fields with explicit validation.
- Keep lifecycle transitions in services or model-owned helpers, not ad hoc table closures with duplicated enum literals.
- Use private `FileUpload` fields plus service-side path validation for staff-uploaded evidence; never expose private storage paths as editable text.
- Treat JSON payloads as internal snapshots or audit evidence unless a documented, typed, validated editing workflow exists.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits and focused PHPUnit tests for the touched resource/service.

*End of Technical Specification*
