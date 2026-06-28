# TAL-50 Backend Salvage Ledger

## Scope and authority

This is the audit artifact for **TAL-50 — Current Worktree Intake and Backend Salvage Ledger**. It is planning evidence only. It does not authorize migration execution, deletion, schema reset, or application-code changes.

Authority was applied in this order:

1. `AGENTS.md`
2. `00_Project_Documents/prd_modules/README.md`
3. PRD modules `01` through `13`
4. `00_Project_Documents/ui_surface_blueprint.md`
5. `00_Project_Documents/architecture_specification.md`
6. Live worktree, routes, code, tests, migrations, and database schema as salvage inventory

Audit date: **2026-06-28**. Live application evidence: PHP 8.2, Laravel 12.62.0, Filament 5.6.7, Livewire 4.3.1, MySQL, database queue, database cache, SMTP mail, Cloud Run scheduling driver, and mock payment driver. The configured integration seams are in `config/tala_integrations.php`; their bindings are in `app/Providers/AppServiceProvider.php`.

## Disposition vocabulary

| Disposition | Meaning |
| --- | --- |
| **Retain** | Fits the current PRD boundary and can remain as a foundation, subject to regression coverage. |
| **Adapt** | Useful structure or behavior exists, but names, fields, relationships, authorization, or workflow must change. |
| **Replace** | The current data or workflow model conflicts materially with the PRD. Preserve useful logic as reference, but design a clean replacement. |
| **Defer** | Not needed for the MVP boundary. Keep out of the clean schema and active UI unless later approved. |
| **Remove** | Obsolete or contradictory scope. Exclude it from the clean MVP schema and eventually remove it through an approved implementation issue. |

## Worktree intake and evidence layers

| Layer | Live evidence | TAL-50 treatment |
| --- | --- | --- |
| Accepted baseline | `00_Project_Documents/TALA-Local-Linear-Sync-Tracker.md` records TAL-43, TAL-44, TAL-45, TAL-48, and TAL-49 as Done. Focused baseline tests include `tests/Feature/PublicLandingAndFilamentAuthTest.php`, `tests/Feature/ApplicantWorkspaceTest.php`, `tests/Feature/ApplicantIntakeSubmissionTest.php`, and `tests/Feature/Auth/*`. | **Retain** the public entry, three panel shells, applicant intake draft/submission, panel access, email verification, password reset, and role-aware landing. |
| Pre-existing tracked dirty work | All 13 PRD modules, `README.md`, `CONTEXT.md`, `app/Actions/Enrollment/AdmissionFinanceReadinessGateService.php`, `app/Actions/Enrollment/StudentEnrollmentService.php`, `app/Filament/Resources/ApplicantIntakes/ApplicantIntakeResource.php`, `app/Models/ChecklistItem.php`, `app/Models/StudentProfile.php`, and `app/Providers/Filament/StudentPanelProvider.php` were already modified before TAL-50. | Preserve untouched. PRDs remain the live authority for this audit; dirty PHP is unaccepted salvage inventory. |
| Pre-existing untracked feature work | Student profile resource/relation managers, personal-data correction workflow, duplicate-profile workflow, Student Hub profile page, four feature-test files, and three migrations are untracked. `progress.md` describes these as locally completed, but the sync tracker does not accept them. | **Adapt** or **Replace** only after a bounded review issue. Do not treat them as baseline. |
| Pending migrations | `database/migrations/2026_06_28_000000_create_personal_data_correction_requests_table.php`, `2026_06_28_013200_add_merged_into_to_student_profiles_table.php`, and `2026_06_28_013300_create_duplicate_profile_resolutions_table.php` are Pending in `php artisan migrate:status`. | Do not run. Fold approved concepts into the clean schema contract rather than stacking them blindly on the legacy schema. |
| Reusable infrastructure | Laravel/Fortify auth, Spatie roles/permissions and activity log, Filament panels/resources, database queue/cache, private file storage patterns, Cloud Run client contract, PayMongo gateway/webhook seam, and Laravel Excel dependency. | **Retain** the boundaries; adapt domain payloads and authorization as the clean schema lands. |
| Obsolete scope | Live MySQL contains `document_ocr_results`, `document_extracted_fields`, `document_requests`, and `service_requests`, but no current migration, model, action, Filament surface, or test references them. Public COR verification remains in `cor_verifications` and `app/Actions/Registrar/CorVerificationLifecycleService.php` despite PRD deferral. | **Remove** from the clean MVP boundary. Preserve data only if a later data-retention decision requires an archive/export before reset. |

Worker-created output is limited to this file.

## Live database diagnosis

Laravel Boost reported **74 live MySQL tables**. The database is development-sized: 9 users, 7 roles, 45 permissions, 5 programs, 2 subjects, 1 curriculum, 0 applicant intakes, 1 student profile, 1 enrollment, 2 ledger entries, 1 payment, 0 grades, 1 schedule run, 0 section meetings, 2 activity rows, and 0 webhook calls. These counts make a clean development rebaseline practical, but TAL-50 does not perform it.

The live database and migration source are not a reproducible clean baseline:

1. Live tables `document_ocr_results`, `document_extracted_fields`, `document_requests`, and `service_requests` have no current source references under `database/migrations`, `app`, or `tests`.
2. `database/migrations/2026_05_12_055403_create_scheduling_foundation_tables.php` creates `candidate_schedule_rows`, and `app/Models/CandidateScheduleRow.php` relies on Laravel's default `candidate_schedule_rows` table name. Live MySQL has only `schedule_draft_rows`, not `candidate_schedule_rows`.
3. The current schema has flat `subjects`, `curriculums`, `curriculum_subjects`, and `prerequisites` tables, but no Course Specification revision/component or structured prerequisite-rule tables required by Module 04.
4. The current schema has no canonical `term_offerings`, `scheduling_demands`, enrollment gate results, gate overrides, academic exceptions, Student Unit Load Exceptions, enrollment seat reservations, assessment headers/lines, Financial Accommodations, COR print logs, INC resolutions, late-grade authorizations, lifecycle changes, graduation review batches/snapshots, report export logs, or notification-delivery history.
5. `student_profiles` lacks `curriculum_version_id` and canonical self-service contact/address/emergency-contact fields. The dirty Student Hub profile page writes post-handover contact data back to `applicant_intakes`, so it does not establish a clean student master-record boundary.
6. `ledger_entries.running_balance` and `student_profiles.current_balance` duplicate a balance that Module 08 requires to be reproducible from posted entries. They may remain cached projections only if the next schema contract defines reconciliation rules; they must not be independent truth.

Conclusion: **do not preserve the current 74-table shape as the MVP ERD**. Preserve selected PHP and UI seams, then build a clean PRD-derived schema contract and migration strategy.

## Module-level salvage ledger

| Module | Relevant live inventory and exact paths | Live schema evidence | Disposition and recommendation |
| --- | --- | --- | --- |
| **01 Product Intent & Architecture** | `app/Providers/Filament/AdminPanelProvider.php`, `ApplicantPanelProvider.php`, `StudentPanelProvider.php`; `routes/web.php`; `resources/views/welcome.blade.php`; `app/Providers/AppServiceProvider.php`; database queue/cache migrations. | `users`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `cache`, and `cache_locks` exist. Runtime resolves MySQL + database queue/cache. | **Retain** the monolithic Laravel application, public `/`, and `/admin`, `/applicant`, `/student` shells. **Adapt** domain modules behind those stable seams. Do not add microservices, Redis, or Horizon in the schema rebaseline. |
| **02 Identity, Access & Workspaces** | `app/Models/User.php`; `app/Providers/FortifyServiceProvider.php`; `app/Http/Responses/RoleAwareLoginResponse.php`; `app/Http/Responses/ApplicantRegistrationResponse.php`; `app/Actions/Fortify/`; `app/Policies/`; `app/Filament/Resources/Users/`; `app/Filament/Resources/Roles/`; auth boundary tests under `tests/Feature/Auth/`. | `users`, `roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `model_has_permissions`, `password_reset_tokens`, `sessions`, and `passkeys` exist. `database/seeders/DatabaseSeeder.php` creates the seven canonical roles. | **Retain** Fortify as backend auth, Filament auth surfaces, `User::canAuthenticate()`, `canAccessPanel()`, and Spatie RBAC. **Adapt** the permission catalog and every custom action/page authorization; current policies are uneven and panel providers do not enable strict authorization. Keep roles fixed and permission-driven. |
| **03 Admissions & Student Handover** | `app/Models/ApplicantIntake.php`, `ChecklistItem.php`, `DocumentUpload.php`, `StudentProfile.php`; `app/Actions/Applicants/*`; `app/Actions/Enrollment/StudentEnrollmentService.php`; Applicant pages/resources; dirty duplicate/correction/profile files; admissions tests. | `applicant_intakes`, `checklist_items`, `document_uploads`, `student_profiles`, `admission_offerings`, `admission_requirement_policies`, and `document_requirement_items` exist. The three dirty migrations are pending. | **Adapt** applicant intake, flat checklist, private identity upload, handover service, and staff Student Profile shell. **Adapt** duplicate resolution but change destructive `cascadeOnDelete()` history links to preservation-safe constraints and avoid a global scope that can hide audit records unexpectedly. **Replace/Remove** the dirty `personal_data_correction_requests` workflow: Module 03 requires locked legal identity changes through in-person Registrar verification, not a Student Hub request queue. Move post-handover contact data onto the student master profile. |
| **04 Academic Setup** | `app/Models/AcademicYear.php`, `Term.php`, `Program.php`, `Subject.php`, `Curriculum.php`, `CurriculumSubject.php`; `app/Actions/AcademicFoundation/*`, `Calendar/*`, `Imports/*`; corresponding Filament resources. | `academic_years` and `terms` are useful but incomplete. `subjects` owns mutable units/contact data; `curriculums` uses `is_active`; `curriculum_subjects` stores `weekly_contact_hours`; `prerequisites` is a flat pair table. | **Adapt** academic year, term, and program identities. **Replace** Subject/Curriculum internals with Course Catalog identities, immutable Course Specification revisions, Course Components, structured prerequisite/corequisite/equivalency rules, versioned curriculum entries, calendar windows/exceptions/break blocks, and recorded-approved/active/superseded states. **Adapt** import services only after fixed Course Specification and Curriculum template contracts exist. |
| **05 Term Offerings & Resources** | `app/Models/Room.php`, `FacultySubjectEligibility.php`, faculty availability models, `Section.php`, `SectionDeliveryGroup.php`, `DeliveryPattern.php`; scheduling/resource actions and Filament resources. | `rooms` has only code/name/building/capacity/active and no type/features/availability records. `faculty_subject_eligibilities` mixes qualification with optional term/max-hours concerns. `sections` and delivery groups stand in for offerings; no `term_offerings` or faculty term load overrides exist. `admission_capacity_*` links capacity to payments/ledger rather than published sections. | **Adapt** room, faculty qualification/availability, section, and delivery-group concepts. **Replace** `admission_capacity_plans`/`admission_capacity_reservations` with term-offering capacity plus Registrar-confirmed Enrollment Seat Reservations. Add term offerings, room features/unavailability, faculty term load overrides, and authoritative occupancy derived from reservations/enrollments rather than mutable counters alone. |
| **06 CP-SAT Scheduling** | `app/Actions/Integrations/SchedulingSolver/*`; `app/Jobs/ScheduleSolverDispatchJob.php`; `app/Actions/Scheduling/*`; `ScheduleGenerationRun`, `CandidateScheduleRow`, `SectionMeeting`, `ScheduleRevisionEvent`; schedule-run/candidate/meeting Filament resources. | `schedule_generation_runs`, `schedule_draft_rows`, `section_meetings`, and `schedule_revision_events` exist, but the model/migration expects `candidate_schedule_rows`. There is no `scheduling_demands`, component key, time-block/calendar source, room FK, constraint profile, or fixed-assignment schema. | **Retain** `SchedulingSolverClient`, Cloud Run IAM client, queue job pattern, immutable solver-input snapshot/hash, candidate review concept, publication seam, and revision-event snapshots. **Replace** the scheduling tables around canonical Scheduling Demands and stable IDs. **Adapt** the services and Filament tables to the new payload; do not preserve the `schedule_draft_rows`/`candidate_schedule_rows` mismatch. |
| **07 Enrollment Gate Model** | `app/Models/Enrollment.php`, `EnrollmentSubject.php`; `app/Actions/Enrollment/*`; enrollment Resources; `AdmissionReadinessDashboard`; `EnrollmentPolicy.php`. | `enrollments` stores one section and one delivery group; `enrollment_subjects` points directly to a `section_meeting`. No gate result, override, academic exception, unit-load exception, seat reservation, or schedule-binding tables exist. | **Replace** the schema with enrollment header, course enrollment lines, Student Schedule Bindings, Enrollment Seat Reservations, typed Gate Results, Gate Overrides, Academic Exceptions, and Student Unit Load Exceptions. **Adapt** transaction/locking logic from existing services, but recompute official status from gates rather than mutable toggles. |
| **08 Finance, Ledger & PayMongo** | `app/Actions/Finance/*`; `app/Actions/Integrations/Payments/*`; `PayMongoWebhookController.php`; `ProcessPayMongoWebhookCall.php`; payment/ledger/adjustment/promissory-note Resources and models. | `fee_templates`, `ledger_entries`, `payment_attempts`, `payments`, `accounting_adjustments`, `installment_policies`, `installment_policy_milestones`, `promissory_notes`, and `webhook_calls` exist. There is no assessment header/line, payment allocation, reversal, refund, or full Financial Accommodation record. | **Retain** `PaymentGateway`, mock/PayMongo driver binding, signature verification, webhook route, queued processing, idempotency identifiers, and accounting read queues. **Adapt** webhook processing to create payment evidence before policy-controlled ledger posting and validate currency/reference/risk states. **Replace** fee templates with fee items/matrix; add assessments/lines/schedules, payment allocations, immutable ledger directions, adjustment/reversal links, OR mapping, and Financial Accommodation with explicit effects. Treat `current_balance`/`running_balance` as projections only. |
| **09 COR** | `app/Filament/Student/Pages/CorView.php`; `app/Http/Controllers/CorVerificationController.php`; `app/Actions/Registrar/CorVerificationLifecycleService.php`; `app/Filament/Resources/CorVerifications/*`. | `cor_verifications` exists; `cor_print_logs` does not. COR source dependencies are incomplete because official enrollment, course revisions, schedule bindings, assessments, and ledger are incomplete. | **Retain/Adapt** the authenticated read-only COR shell and source-derived rendering approach. **Replace** its source query after Modules 04–08. Add `cor_print_logs` with actor/copy/action context. **Remove/Defer** public token/QR verification (`cor_verifications`) because Module 09 explicitly defers unauthenticated verification pending policy. |
| **10 Grades** | `app/Models/Grade.php`, `GradeSubmissionPackage.php`, `GradeSubmissionPackageItem.php`, `GradeCorrection.php`; `app/Actions/Grades/*`; Grades and GradeSubmissionPackages Resources; Student `GradesView.php`. | `grades` has numeric period columns plus `is_inc`; packages/items exist. There is no typed Grade Outcome, pending-grade replacement history, late authorization, or INC resolution table. Current `grade_corrections` includes Academic Head review fields and request-style data. | **Adapt** package/roster transaction logic, faculty class-list seam, period-equivalent calculation, Registrar queue, and Student read view. **Replace** grade persistence with one course-enrollment outcome, typed marks/categories, replacement history, late authorization, INC resolution, and Registrar-recorded posted correction. Remove in-system Academic Head correction approval from the MVP path. |
| **11 Student Lifecycle & Holds** | `app/Models/Hold.php`; `app/Actions/StudentLifecycle/HoldEvaluationService.php`; `app/Filament/Resources/StudentProfiles/RelationManagers/HoldsRelationManager.php`; `shifting_requests`, `shifting_fee_assessments`; no graduation/lifecycle action classes. | `holds` closely matches the central-table mandate. `student_profiles.operational_status` conflates lifecycle and standing; `enrollments.status` is separate but incomplete. `shifting_requests` models an in-system request, not a recorded approved result. No lifecycle-change, program-shift evaluation/credit, graduation batch, or snapshot tables exist. | **Retain/Adapt** central holds and evaluation. **Replace** profile status fields with separate primary lifecycle status and academic standing. **Replace** shifting request/fee tables with recorded Student Lifecycle Changes and Program Shift Credit Evaluations. Add graduation review batches/snapshots/items and account-reactivation effects. Dirty `HoldsRelationManager.php` must be rewritten for Filament v5 (`Filament\Actions`, not the obsolete `Filament\Tables\Actions`) and action authorization. |
| **12 Student Hub** | `app/Filament/Student/Pages/Dashboard.php`, `ScheduleView.php`, `CorView.php`, `SoaView.php`, `PaymentAcknowledgementView.php`, `GradesView.php`, `HoldsView.php`; widgets; `app/Actions/StudentHub/StudentDashboardService.php`; dirty `Profile.php`. | Current read pages query legacy enrollment/schedule/finance/grade tables. Student profile self-service data is not owned by `student_profiles`; dirty `Profile.php` writes contact/address data into `applicant_intakes`. | **Retain** the confirmed shell, navigation boundary, read-only page composition, empty states, and ownership scoping. **Adapt** every data query after the clean source tables land. **Replace** the dirty profile implementation with a limited Student Profile form for contact/address/emergency fields; remove its locked-field correction request action. Completion view remains a required future surface. |
| **13 System Admin, Reports & Audit** | `app/Models/SystemSetting.php`; Users/Roles/SystemSettings/ImportBatches/Activities/FAQ Resources; `app/Notifications/GeneralSystemNotification.php`; Spatie activity-log packages; `database/seeders/DatabaseSeeder.php`. | `system_settings` is untyped key/value; `import_batches.import_type` includes unsupported legacy imports; `activity_log` exists but only Grade/GradeCorrection models and a few services explicitly log. No report export logs, integration event abstraction, notification delivery history, retention category, or disposal log exists. | **Retain** Spatie activity log + authorized read-only Activity Resource, canonical-role seed concept, FAQ/public content, Laravel Excel seam, and queue infrastructure. **Adapt/Replace** settings with typed/effective-dated configuration, imports with only Course Specification/Curriculum template flows, and audit coverage for all high-risk actions. Add report export logs, notification delivery metadata, integration events, retention categories, and disposal review. **Remove** orphan legacy import/service/OCR scope. |

## Cross-cutting disposition ledger

| Area | Disposition | Evidence and boundary |
| --- | --- | --- |
| Authentication and panel access | **Retain** | `User::canAuthenticate()`, `User::canAccessPanel()`, `FortifyServiceProvider`, role-aware responses, three panel providers, and focused auth tests match the accepted TAL-49 boundary. Filament remains the auth UI; Fortify remains the backend contract. |
| Authorization | **Adapt** | Spatie tables and policy classes are reusable. Custom Filament pages/actions still need explicit ability checks. The dirty checklist/hold/profile actions demonstrate gaps, and `AdminPanelProvider.php` does not call `strictAuthorization()`. Define one PRD-derived permission matrix before expanding resources. |
| Audit | **Adapt** | `activity_log` and `ActivityResource` are reusable, but explicit logging is concentrated in grade services, user lifecycle, and dirty duplicate resolution. Add domain event/audit coverage for handover, gates, schedule publication/revision, ledger, outputs, lifecycle, exports, and configuration. |
| UI shells | **Retain/Adapt** | Public, Applicant, Student, and Staff shells are accepted. Existing Resources are reusable presentation inventory, not proof that their data/workflow is accepted. Preserve table/form composition where it maps cleanly; replace generic CRUD for controlled actions. |
| Scheduling integration | **Retain seam; replace payload schema** | `SchedulingSolverClient`, Cloud Run ID-token provider/client, service-provider binding, queue job, snapshot hash, and retry/timeout behavior are good boundaries. Rebuild payload generation around Course Components, Scheduling Demands, stable time blocks, rooms, faculty qualifications, and constraint profiles. |
| PayMongo/webhook integration | **Retain seam; adapt posting workflow** | `PaymentGateway`, PayMongo client, signature verifier, `/api/webhooks/paymongo`, `webhook_calls`, and queued processor are reusable. The processor currently posts a ledger entry immediately after a matching paid webhook; the PRD requires explicit payment-evidence and exception/review states before policy-approved posting. |
| Queue/cache/mail | **Retain** | Live configuration is database queue/cache and SMTP mail. Redis/Horizon is not installed and is outside TAL-50. |
| Imports | **Adapt/Replace** | Keep Laravel Excel and preview/service patterns. Restrict user-facing imports to versioned Course Specification and Curriculum CSV templates. Remove legacy `student_data`, `legacy_grades`, `legacy_financial`, and `enrollment_records` import types from the clean contract. |

## Clean MVP schema boundary

The next ERD/schema issue should define a new clean schema contract; it should not merely add missing columns to all current tables. Names below are conceptual and may be adjusted for existing Laravel conventions, but ownership boundaries should remain.

### Dependency order

1. **Platform foundation** — users, canonical roles/permissions and pivots, sessions/password reset/auth factors, jobs/cache, activity log, typed configuration versions, integration events, notification deliveries, retention categories.
2. **Institution and calendar reference** — programs, academic years, terms, calendar windows, calendar exceptions, scheduling time blocks, institutional break blocks, delivery modalities, controlled policy values.
3. **Course and curriculum authority** — course identities, Course Specification revisions, Course Components, prerequisite rule sets/groups/alternatives, corequisites, equivalencies, curriculum versions, curriculum entries, supported import batches/rows.
4. **Admissions and student master** — admission category/credential-basis mappings, applicant intakes, checklist policy items, applicant/student checklist items, private document evidence, student profiles, immutable curriculum assignments, duplicate-resolution records, profile-change audit.
5. **Resources and offerings** — faculty profiles/subject qualifications/availability/term-load overrides, rooms/features/unavailability, term offerings, sections, section delivery groups, expected demand and capacities.
6. **Scheduling** — Scheduling Demands, constraint profiles, fixed assignments, solver runs, candidate schedule rows, official section meetings, published schedule metadata, schedule revision events.
7. **Enrollment** — enrollment headers, course enrollment lines, Student Schedule Bindings, Enrollment Seat Reservations, gate results, gate overrides, Academic Exceptions, Student Unit Load Exceptions.
8. **Finance** — fee items/matrices, assessments/lines/installment schedules, payment attempts/evidence, payment allocations and OR mapping, ledger entries, adjustments/reversals/refunds, Financial Accommodations and schedules, PayMongo webhook evidence.
9. **Grades** — grade roster packages/rows, Period Equivalents, Grade Outcomes, release/replacement history, late-grade authorizations, INC resolutions, posted-grade corrections.
10. **Lifecycle and official outputs** — holds, Student Lifecycle Changes, Program Shift Credit Evaluations/credited entries, graduation review batches/snapshots/items, COR print logs, generated-output access logs, report export logs, disposal review logs.

### Required schema rules

1. Historical academic and finance records reference immutable/effective versions, not mutable current labels.
2. Source records own changes; Student Hub, COR, SOA, schedules, grades, dashboards, and reports are read projections.
3. Seat capacity is concurrency-safe and derived from active reservations plus official enrollments; payment never reserves a seat.
4. Ledger entries are immutable and balanced by explicit adjustment/reversal records; cached balances are projections with a reconciliation rule.
5. Candidate schedule rows are separate from official section meetings and use the same stable Scheduling Demand IDs sent to Cloud Run.
6. Holds, gate results, exceptions, and lifecycle changes are typed, scoped, effective-dated, actor-attributed, and auditable.
7. Official-output access and sensitive exports have dedicated logs even when Spatie activity log also records the action.
8. Foreign-key deletion behavior preserves official history. Academic, finance, grade, duplicate-resolution, and audit evidence must not cascade away with a user/profile deletion.

### Explicitly outside the clean MVP schema

1. OCR extraction/review (`document_ocr_results`, `document_extracted_fields`).
2. Registrar credential/document request, courier, tracking, and generic service-request workflows (`document_requests`, `service_requests`).
3. Public COR token/QR verification (`cor_verifications`) until an approved policy exists.
4. In-app notification center as a product requirement; retain framework tables only if used operationally, while product notifications remain email-first.
5. Redis/Horizon, microservices, full-calendar/drag-and-drop scheduling, generic constraint builders, and user-editable solver scoring weights.
6. In-system approval routing where the PRD specifies an externally approved, Registrar/Accounting-recorded result.

## Product ambiguities recorded with recommended defaults

| Ambiguity | Recommended default for the next issue |
| --- | --- |
| Module 13 says authentication is handled entirely by Fortify, while Module 02 and the UI blueprint place authentication UI in Filament panels. | Treat Filament as the user-facing auth surface and Fortify as the backend authentication/action contract, matching the accepted TAL-49 implementation. |
| Existing QR/token code and a QR package remain, while Module 09 defers public COR verification. | Exclude `cor_verifications` from the MVP ERD and keep authenticated COR view/print logging only. |
| Dirty work adds Student Hub requests for locked legal-field correction, while Module 03 says the student presents evidence in person and Registrar updates the record. | Do not create a correction-request table for MVP. Provide a Registrar-controlled profile update with audit; Student Hub edits only contact/address/emergency data. |
| The current schema stores post-handover contact data only on the applicant intake. | Copy approved handover data into canonical student-profile/contact records; keep applicant intake immutable as source history. |

None of these ambiguities blocks TAL-50 classification.

## Recommended next bounded issues (not started)

1. **Clean MVP ERD and schema contract** — define tables, keys, version/effectivity rules, state vocabularies, deletion behavior, indexes, and old-to-new table mapping for the ten dependency layers above. Include a decision on development reset versus data-preserving transition; do not write migrations in the same issue.
2. **Rebaseline migration strategy and reproducibility proof** — after ERD approval, decide which old migrations are historical, build the approved clean migration baseline, and prove a fresh database matches it. Include explicit treatment of the four orphan live tables and the candidate-table name mismatch.
3. **Academic/calendar/course/curriculum foundation slice** — implement dependency layers 2–3 with focused tests and fixed CSV template contracts.
4. **Admissions-to-student master slice** — adapt the accepted applicant intake/handover onto the clean student, checklist, document, curriculum-assignment, and duplicate-resolution schema.
5. **Offerings/scheduling contract slice** — land term offerings and Scheduling Demands, then adapt the retained Cloud Run seam and candidate/publication workflow.
6. **Enrollment/finance vertical slice** — prove placement → seat reservation → assessment → payment evidence → ledger posting/Financial Accommodation → official enrollment without coupling payment to capacity.

Later grade, lifecycle, Student Hub, output, report, and audit slices should follow only after their upstream source records are stable.

## TAL-50 completion checks

- Modules `01`–`13` are each classified above.
- Foundation, scheduling integration, PayMongo/webhooks, authorization, audit, and UI shells are classified separately.
- Accepted baseline, dirty work, pending migrations, obsolete scope, and reusable infrastructure are separated.
- Every disposition cites live repository paths, live schema/table evidence, or both.
- The clean MVP schema boundary and dependency order are defined without implementing TAL-51 or later work.
