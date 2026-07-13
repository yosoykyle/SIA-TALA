## 13. System Administration, Reports, & Audit

---

### 13.1. System Configuration

System Configuration controls institution-specific rules and reference data.

For MVP implementation, configuration is grouped by operational area instead of scattered feature screens:

1. Academic setup.
2. Enrollment policy.
3. Finance policy and fee matrix.
4. Scheduling policy.
5. Grade policy.
6. Integrations.
7. Reports, audit, and retention.

#### 13.1.1 Configuration Capability Dispositions

TALA must support configuration for the following institutional data and rules, but "configuration" does not imply that every item has a runtime-editable administration UI. Each item uses the capability-depth taxonomy in Module 1:

1. **Runtime-configurable policy/reference data:** Academic years.
2. **Runtime-configurable policy/reference data:** Terms.
3. **Runtime-configurable policy/reference data:** Programs.
4. **Core system-managed workflow:** Course Catalog identities and Course Specification revisions.
5. **Core system-managed workflow:** Curriculum versions.
6. **Runtime-configurable policy/reference data:** Admission categories.
7. **Runtime-configurable policy/reference data:** Credential bases.
8. **Runtime-configurable policy/reference data:** Document types (e.g. Birth Certificate, Form 137).
9. **Runtime-configurable policy/reference data:** Hold types and blocking levels.
10. **Runtime-configurable policy/reference data:** Fee rules in one Accounting-owned matrix with Program and Term scope, fixed amounts, per-unit PHP rates, exact Program-and-Term downpayment rules, discount/scholarship rules, and Accounting-posted penalty rules.
11. **Fixed/seeded V1 policy:** Financial Accommodation basis, allowed effects, evidence-reference requirements, and status rules.
12. **Fixed/seeded V1 policy:** Delivery modality rules.
13. **Runtime-configurable policy/reference data:** Room types and room features.
14. **Runtime-configurable policy/reference data:** Faculty qualification groups.
15. **Fixed/seeded V1 policy:** Scheduling constraints and the default soft-priority preset remain controlled; **Runtime-configurable policy/reference data:** authorized institutional break-block source records are managed through Calendar Events.
16. **Fixed/seeded V1 policy:** Role permissions are seeded and read-only in V1.
17. **Deferred/post-MVP:** Email notification templates are deferred to TAL-100.
18. **Fixed/seeded V1 policy:** Retention categories.
19. **Supporting/frozen capability:** Integration credentials and webhook settings are environment-managed; administrators have restricted read-only status, not runtime secret editing.
20. **Runtime-configurable policy/reference data:** Student Lifecycle Change windows are managed through academic-calendar window records; **Fixed/seeded V1 policy:** decision authorities, late-exception rules, class-standing requirements, and fee/refund policies remain controlled; **Recorded office result:** approved office decisions are recorded as lifecycle results.
21. **Core system-managed workflow:** Academic Calendar windows and institutional break blocks are managed source records; **Runtime-configurable policy/reference data:** authorized staff maintain those records; **Fixed/seeded V1 policy:** scheduling-grid defaults, Special Offering reasons, tutorial thresholds, and offering approval authorities remain controlled unless runtime variation is proven.
22. **Fixed/seeded V1 policy:** Grade Outcome categories, allowed marks, Servitech v1 final formula, conversion scale, passing threshold, prerequisite effects, GWA effects, `P` and `INC` authority rules, INC completion/removal deadline, lapsed-INC result, and late grade authorization authorities.
23. **Fixed/seeded V1 policy:** Prerequisite completion results, optional minimum-grade policy, accepted credit sources, corequisite treatment, and Academic Exception authorities.
24. **Fixed/seeded V1 policy:** Course Component types, limited to Lecture and Laboratory in V1.
25. **Fixed/seeded V1 policy:** Same-faculty requirement defaults and authorized override rules for linked course components.
26. **Fixed/seeded V1 policy:** Scheduling policy authorities, including which policy constraints require authority, reason, and audit evidence.
27. **Core system-managed workflow; generated read-only projection:** Graduation Review Batch filters, visibility rules, snapshot blocker labels, and review authorities.
28. **Fixed/seeded V1 policy; recorded office result:** Student Unit Load Exception authorities, normal-load rules, excess-unit caps, and allowed scopes; approved exceptions are recorded as office results.

Rules:

1. Only authorized roles can configure system rules.
2. Configuration changes require actor, timestamp, affected record, previous value, new value, and reason where applicable.
3. Effective-dated configuration must preserve historical behavior.
4. Active enrollment, finance, scheduling, grades, and source-derived outputs preserve the configuration version used when the official record was created.
5. Configuration used by official records must remain traceable.
6. Dependent workflows open after their required configuration is complete.
7. Document requirements must be configurable as a flat mapping list by Admission Category and Credential Basis.
8. Each configured document requirement must designate whether it blocks Handover (blocks Applicant -> Student transition) or blocks official Enrollment.
9. The system protects fixed hard scheduling constraints through validation. Physical capacity, double-booking, required contact hours, missing qualification, and blocked-calendar or break-block violations are corrected through authoritative source records.
10. Finance-related holds state the exact blocking effect. Pending OR mapping is treated as reconciliation status unless authorized administration configures a separate enrollment-blocking hold.
11. Faculty grade-entry marks use the active Grade Outcome policy. The Servitech v1 policy uses 30% Preliminary + 30% Midterm + 40% Final, the approved numeric conversion scale, `3.00` / 75% as the passing threshold, and controlled non-numeric outcomes including `P`, `INC`, and lifecycle-derived dropped or withdrawn labels. If the institution prints `W`, it is configured as a controlled display label mapped to a lifecycle-derived withdrawn outcome.
12. Graduation Eligibility Snapshot visibility defaults to staff-only. Student-facing visibility requires an explicit Registrar action.
13. Student Unit Load Exception policy defaults to Academic Head approval and Registrar recording unless the institution configures a different authorized workflow.

#### 13.1.2 Configuration Rules

1. Only authorized roles can configure system rules.
2. Configuration changes require actor, timestamp, affected record, previous value, new value, and reason where applicable.
3. Effective-dated configuration must preserve historical behavior.
4. Active enrollment, finance, scheduling, grades, and source-derived outputs preserve the configuration version used when the official record was created.
5. Configuration used by official records must remain traceable.
6. Dependent workflows open after their required configuration is complete.

Example:

Assessment becomes active after Accounting configures an active exact Program-and-Term downpayment rule for the enrollment.

---

### 13.2. Notifications (Email Only)

The system exclusively uses email through the configured Laravel mail transport for all critical system alerts (e.g., Application Approved, Payment Received, Schedule Released).

Notification delivery is email-first. The product tracks delivery metadata needed for operations without requiring an in-app notification center for v1.

Notification scope rules:

1. Notifications are sent only to directly affected users.
2. Schedule revision notifications are sent only to affected students and affected faculty.
3. Payment-posted notifications are sent only to the affected student.
4. Application-status notifications are sent only to the affected applicant.
5. Grade-release notifications are sent only to the affected student.
6. V1 notification delivery uses direct email to affected users.

> **V1 implementation note (recorded 2026-07-08, reconciled during TAL-93J3b):** V1 notification content is defined in code (Laravel Mailable classes + Blade views), not database-configurable templates; DB-editable notification templates (§13.1.1 disposition #17) are routed to post-MVP TAL-100 and are not an MVP dependency. Notification *delivery metadata* (send/failure status, channel, recipient snapshot, timestamps) is captured by the `operational_events` monitoring surface delivered under TAL-92D. (The student-facing Student Hub priority notices delivered under TAL-91 — backed by the Laravel `notifications` table — are a separate projection surface, distinct from this §13.2 email-alert channel.) Production Payment Received and Schedule Released triggers remain routed to TAL-95 and TAL-94 respectively, each subject to its integration Ground-Truth Gate.

---

### 13.3. Reports

Reports use pre-built operational data grids (e.g. Applicant List, Enrollment Master List, Ledger Report) and "Export to CSV" buttons.

Report boundaries:

1. Reports are basic filtered tables with CSV export.
2. V1 reports use filtered operational tables and CSV export.
3. Printable official outputs such as COR, SOA, billing slip, and payment acknowledgement use authenticated HTML/CSS print views for MVP. Server-generated retained files are not planned; a future approved retention requirement must receive a new Next Steps issue.
4. Report analysis beyond filtering and CSV export happens outside TALA.

Faculty sees only reports for assigned classes.

#### 13.3.1 Registrar Reports

1. Enrollment Master List.
2. Capacity Pending List showing students awaiting Registrar section placement.
3. Section Capacity Summary showing capacity, active reservations, official enrollments, and remaining seats.
4. Student Lifecycle Change Register showing approved change type, effective date, decision authority, recorder, and affected term without exposing private evidence by default.
5. Graduation Review Batch List.
6. Graduation Eligibility Snapshot Export.

V1 capacity reporting uses the Section Capacity Summary.

#### 13.3.2 Academic Head Reports

1. Curriculum Version Report
2. Faculty Load Report
3. Scheduling Exception Report
4. Faculty Term Load Override Report
5. Academic Progression Exception Report
6. Grade Correction Audit Log
7. Graduation Eligibility Snapshot
8. Pending Grade List
9. INC Completion / Removal List
10. Late Grade Encoding Authorization List
11. Student Unit Load Exception List

#### 13.3.3 Accounting Reports (Daily Turnover & Reconciliation)

The system provides standardized CSV exports for the legacy three-way parity audit (Excel Ledger = Receipts = Cash):

1. **Daily Cash Collection Report (Daily Turnover):** CSV export by date to verify the daily cash box and GCash payouts. Columns:
   - `Transaction Date/Time`
   - `OR Number` (manual paper receipt reference)
   - `PayMongo Reference ID` (blank for cash payments)
   - `Student Number`
   - `Student Name`
   - `Payment Method` (e.g., Cash, PayMongo-GCash, PayMongo-Card)
   - `Allocated Category` (e.g., Downpayment, Midterm, Old Balance)
   - `Amount Paid`
   - `Accounting Recorder ID` (tracks the encoder)
2. **Reconciliation Exception Report:** CSV list of PayMongo payments with `Pending OR Mapping` status. Enforces the policy that every digital transaction must have a corresponding paper receipt.
3. **Student Ledger Statement:** Exportable transaction rows for a specific student to audit balance discrepancies.
4. **Term Fee Summary:** Totals collected per program and fee item for term-level audits.
5. **Financial Accommodation List:** Accounting-only operational list showing student, term, covered amount, due date, status, and explicitly approved effects without exposing certification evidence in exports by default.

#### 13.3.4 Admin / Audit Reports

> **Routing note (approved 2026-07-08, reconciled during TAL-93J3b):** the fixed operational catalog was delivered under TAL-92A, and the Sensitive Access Audit / Document Access Audit / Login-Session Audit breakdowns were delivered under TAL-92B. Remaining work is routed by Next Steps: Solver Run History → TAL-94; PayMongo Webhook Event Log → TAL-95; Privacy Request Log → post-MVP TAL-99 (Data-Subject Privacy Request Handling & Log, RA 10173 §16). Assessed during TAL-92F: no privacy-request source record exists; data-subject-request handling is a DPO-owned manual/hybrid process (RA 10173 §16 rights — access, rectification, erasure/blocking, object, data portability, complaint); RA 10173 and its IRR mandate no specific in-system request-log UI; no MVP slice depends on it; access-request evidence is already partly served by `activity_log` + `output_access_logs`, and the erasure/blocking right routes through the TAL-92E hold-aware disposal-review ledger and the TAL-98 archival scope.

1. User and Role Report
2. Sensitive Access Audit
3. Document Access Audit
4. Generated Output Access Audit
5. Integration Event Log
6. Solver Run History
7. PayMongo Webhook Event Log
8. Report Export Audit
9. Login / Session Audit
10. Privacy Request Log

#### 13.3.5 Report Rules

1. Report actor must be recorded.
2. Export actor must be recorded.
3. Sensitive exports require purpose capture.
4. Export scope must be role-limited.
5. Hidden fields must not be exported by default.
6. Report downloads must be auditable.

#### 13.3.6 Report Export Audit Contract

> **Implementation note (reconciled 2026-07-08):** this contract is persisted in the existing unified `output_access_logs` table (`output_type = 'REPORT'`, `action = 'EXPORT'`) rather than a separate `report_export_log` table. "Export Format" is implicitly `CSV` in v1, since no other export format exists. "Hidden Fields Excluded" is enforced structurally by the fixed per-report column allow-list in `OperationalReportService::columns()` rather than a stored boolean field.

Every CSV export creates one `report_export_log` record.

Required fields:

1. Report Key / Name
2. Actor ID
3. Actor Role
4. Exported At
5. Export Format, default CSV
6. Filter Summary
7. Row Count
8. Purpose, required for sensitive exports
9. Sensitivity Level
10. File Retention Reference or Generated File ID, only when the export file is stored
11. Requester IP, if available
12. User Agent, if available
13. Hidden Fields Excluded
14. Status

Sensitivity levels:

1. Normal
2. Student Data
3. Finance Data
4. Sensitive

Export log statuses:

1. Generated
2. Failed
3. Expired
4. Deleted

Rules:

1. Hidden fields are excluded by default.
2. Sensitive exports require purpose capture.
3. Faculty exports are scoped to assigned classes only.
4. Exports use data-grid CSV output.
5. CSV report exports stream the file to the requester and store only the export log by default.
6. V1 stores generated files only for official source-derived outputs when required by policy; otherwise the output is streamed or printed and only the log is stored.

---

### 13.4. Imports and Exports

#### 13.4.1 Supported Imports and Template Flow

TALA supports these user-facing CSV imports through the authorized TALA workspaces:
1. Course Specification import.
2. Curriculum version upload.

A Curriculum Version upload may match existing course identities and propose Draft Course Specification revisions for materially different or incomplete source rows. Active revisions remain historically preserved.

Each supported import provides its own downloadable, current TALA CSV Import Template. The template has fixed required headers, documented optional headers, and a required `template_version` column whose supported value is retained on every populated row.

Import flow:

Download current template → complete or copy source rows → upload → validate template and rows → preview errors and warnings → correct and reupload when needed → confirm valid batch → create Draft records → complete domain review and activation separately.

All other initial setup data (including rooms, faculty profiles, faculty qualifications, fee matrices, historical student master records, historical grades, and ledger history) are loaded once during system setup via developer-run database migrations/seeders. Staff create, update, and manage these records day-to-day using standard UI forms in their workspaces.

#### 13.4.2 Import Rules

1. Source, uploader, and timestamp must be recorded.
2. Import type and TALA template version must be recorded.
3. File type, encoding, required headers, and template version are validated before domain rows.
4. Preview is required before Draft creation.
5. High-risk imports require review.
6. Errors and warnings must be downloadable with source row numbers.
7. Import uses the same required validation, authorization, and audit logging as manual entry.
8. V1 uses fixed TALA templates for supported imports.

Import batch states:

1. Uploaded
2. Validating
3. Validation Failed
4. Preview Ready
5. Draft Created
6. Cancelled

Rules:

1. Import preview is required before Draft creation.
2. Validation errors block the entire batch from creating Draft records.
3. Warnings require acknowledgement.
4. A Preview Ready batch may be cancelled before Draft creation.
5. After Draft creation, correction occurs on the Draft records through the normal Course Specification or Curriculum workflow.
6. Import batch must preserve uploader, timestamp, source file, row count, error count, and affected records.
7. A confirmed Course Specification or Curriculum import creates Draft records only; activation remains a separate authorized action.

> **Implementation note (reconciled 2026-07-08):** the implementation uses a synchronous 3-state persisted model (`PENDING_REVIEW` = "Preview Ready", `POSTED` = "Draft Created", `CANCELLED` = "Cancelled") rather than persisting all 6 PRD-named states, because validation is synchronous for the fixed ≤5MB CSV templates in V1 — `Uploaded`/`Validating` are transient in-request states that are never persisted, and `Validation Failed` is represented as a Preview Ready batch with `error_count > 0` that cannot be posted (rather than a distinct terminal state). The "Posted" batch state corresponds to the PRD's "Draft Created" state: posting an import batch creates Draft records only, and activation remains a separate authorized action, already true in the implementation and already correctly labeled "Create Draft records" in the UI.

#### 13.4.3 Export Rules

1. Export actor must be recorded.
2. Sensitive exports must capture purpose.
3. Export scope must be role-limited.
4. Student-level exports must be filtered by authorized term, program, or section where possible.
5. Export logs must be retained for audit.
6. Export defaults include only visible, authorized fields.
7. Exported files use controlled download links.

---

### 13.5. Integration Settings and Operational Monitoring

TALA must expose administrative configuration and monitoring for integrations.

---

#### 13.5.1 Email Integration Settings

Settings:

1. Mail transport and credential reference.
2. Verified sender email address.
3. Active / inactive status.
4. Daily/monthly email send usage counter.
5. Exception event logging (failed delivery attempts).

Rules:

1. Email templates must be rendered with minimal necessary personal data.
2. Email delivery failures must be logged in TALA’s integration logs.
3. Successful email delivery metadata (message ID, recipient, timestamp) must be recorded in notification history.

> **Implementation note (recorded 2026-07-08, reconciled during TAL-93J3b):** integration/email settings (mail transport, PayMongo keys, and scheduler credentials) are environment-managed — driver selection and secrets live in `.env`/`config/`, never in the database or rendered in the UI. TALA exposes a restricted read-only status view (`App\Filament\Pages\IntegrationStatus`) showing current driver/mode, configured-or-not (derived from whether a required secret/key is non-empty, never its value), and non-secret reference fields, plus a safe "Send test email" action that always targets only the acting admin's own address. The Operational Events monitor (§13.8 "Integration events and failures" row) reviews integration outcomes read-only in V1 via `OperationalEventResource`. Safe/idempotent retry execution remains routed to TAL-95 and TAL-94 and may be added only after each integration Ground-Truth Gate proves the corresponding live handler. The daily/monthly email send-usage counter and successful-delivery notification-history metadata are not planned MVP work; a future approved requirement must receive its own Next Steps issue.

---

### 13.6. Privacy, Security, and Audit

The system relies on the application framework (Laravel) for security and auditing.

1. **Authentication:** Handled through Filament panel authentication surfaces backed by Laravel Fortify where the backend authentication contract is integrated.
2. **Audit Logging:** The system leverages the **Spatie Activitylog** package. High-risk inserts, updates, deletes, and official-output access events are tracked at the application level.
3. **Audit Visibility:** The System Super Admin views audit logs inside the Filament-powered Audit Log UI (e.g., using `ActivityResource`).

MVP audit scope:

1. Login and session security events.
2. Enrollment status, gate results, and gate overrides.
3. Assessment, payment evidence, ledger posting, reversals, adjustments, and OR mapping.
4. COR, SOA, payment acknowledgement, and sensitive generated-output access.
5. Billing-slip access and PayMongo checkout-attempt creation.
6. Grade posting, release, pending-grade replacement, INC completion/removal, late encoding authorization, and correction.
7. Graduation Review Batch creation, membership changes, snapshot refreshes, and visibility changes.
8. Schedule publication, live schedule revision, Manual Schedule Override, and solver run records.
9. Holds and Student Lifecycle Change records.
10. Sensitive report exports.
11. Room or faculty scheduling-block creation, update, activation, and retirement.

V1 audit focuses on official-record changes, sensitive output access, and high-risk exports.

> **Implementation note (recorded 2026-07-08):** V1 audit immutability is enforced at the application layer only — the read-only `ActivityPolicy` (no `update`/`delete`/`restore`/`forceDelete`) and `ActivityResource::canCreate() = false` prevent staff-surface tampering with audit records. There is no database-level append-only or tamper-evident storage (e.g., write-once constraints, hash chaining) for the `activity_log` table in V1. DB-level tamper-evidence hardening (e.g., write-once constraints or hash chaining) was assessed in TAL-93A and accepted as out of MVP scope: application-layer immutability is the proportionate baseline for V1 (the money trail in `ledger_entries` is separately protected by the reversal-based, no-hard-delete design from TAL-86C), and DB-level hardening is routed to post-MVP issue TAL-101.

---

### 13.7. Retention and Disposal

TALA must support retention categories and disposal controls.

#### 13.7.1 Permanent or Long-Term Records

1. Student profile.
2. Student number.
3. Enrollment records.
4. COR print logs and source enrollment/schedule/ledger records.
5. Final grades.
6. Grade correction history.
7. Academic history.
8. Curriculum assignment.
9. Graduation / completion eligibility snapshots.
10. Ledger summary and official finance history.
11. Student status transitions.
12. Student Lifecycle Change results and their affected-record references.

#### 13.7.2 Archive After Active Use Plus Institutional Review Period

1. Applicant records.
2. Admission evidence.
3. Retention document tracking.
4. Holds.
5. Payment evidence.
6. Financial Accommodation records and required private document references.
7. SOA/payment acknowledgement logs and source finance records.
8. Irregular scheduling notes.

#### 13.7.3 Shorter Operational Retention

1. Login / session logs.
2. Raw webhook payloads.
3. Temporary uploads.
4. Failed import files.
5. Draft curriculum uploads.
6. Rejected duplicate files.
7. Solver temporary payloads.

#### 13.7.4 Retention Rules

1. Preserve official academic records.
2. Archive official student records through authorized retention handling.
3. Destroy expired temporary records securely.
4. Keep disposal audit logs.
5. Retention must follow purpose limitation and minimum necessary retention.
6. Exact retention periods are institution-configured policy values.
7. Disposal actions must be permission-controlled.
8. Disposal is held when a record is under institutional, legal, audit, or active workflow hold.
9. V1 tracks retention categories and supports manual disposal review.
10. Automated disposal jobs are routed to post-MVP TAL-98 and are built only if the institution explicitly requires them.

#### 13.7.5 Record Accessibility and Archival Tiers

1. Retention is driven by record category and retention period, not by batch or age alone. A record being old or from a previous batch is not by itself a reason to make it inaccessible or to dispose of it.
2. Permanent and long-term records (13.7.1) remain directly accessible in the system indefinitely, including for alumni and historical requests. They are never moved to offline storage that the operational system cannot reach.
3. Archive-after-review records (13.7.2) may be moved to a quieter archived state after their active-use and institutional review period, but must remain retrievable while under any institutional, legal, audit, or active-workflow hold.
4. Short-operational records (13.7.3) are disposed after their retention period through the manual disposal review (13.7.4).
5. V1 archival is a soft, in-database state: archived records are hidden from normal and student-facing views but remain queryable by authorized staff for audit and history. Physically relocating records out of the operational database is not required in V1.
6. Backup (disaster recovery) and archival (long-term retention) are distinct. Backup handling is defined in the architecture specification and does not change how the application accesses live records.
7. Physical or offline archival storage, an archive-management interface, and automated cold-storage export are routed to post-MVP TAL-98 and are not V1 scope.

---

### 13.8. Administration, Import, Report, and Audit Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Simple configuration record | Record Form with typed values, effective dates, status, and audit metadata |
| Repeated configuration such as fees, holds, requirements, policies, or authorities | Editable Table opening a Record Form per row; active historical values are preserved |
| Date-based policy windows | Calendar / Date-Range Input linked to the relevant Term or policy scope |
| Scheduling policy and Institutional Break Blocks | Generated Read-Only View for the versioned code-defined constraint profile plus Calendar / Date-Range Input for break blocks and blocked periods |
| Grade Outcome policy | Editable Table defining allowed mark, category, finality, prerequisite effect, GWA effect, student-facing label, active status, formula/version reference, numeric conversion range, passing threshold, submission authority, INC deadline rule, and lapsed-INC behavior |
| Late grade authorization authority | Editable Table or Record Form defining authorized approvers, scope, deadline rule, and audit requirement |
| Student Unit Load Exception policy | Editable Table defining normal max units, excess-unit cap, allowed standing/scope, authority, and active status |
| Graduation Review Batch | Operational Review Table where Registrar selects academic year, term, optional filters, and included students; snapshot results are generated read-only |
| Graduation Eligibility Snapshot visibility | Focused action toggling student-facing visibility for a generated snapshot with actor, timestamp, and reason |
| Course Specification or Curriculum import | Current template download, template-conforming CSV File Upload, row preview, validation/error table, and explicit Draft-creation confirmation |
| Import correction | Correct rows in the preview before posting; after posting use the domain's amendment, supersession, adjustment, or reversal workflow |
| Standard report | Filter Form using controlled date/term/program/status selectors, followed by a Generated Read-Only table and CSV export |
| Audit log | Read-only searchable table with actor, action, record type/ID, timestamp, before/after reference, and source context |
| Integration settings | Restricted Record Form; secrets are write-only or stored by secure reference |
| Integration events and failures | Operational Queue / Review Table with retry only where safe and authorized |
| Retention/disposal review | Read-only candidate table plus explicit, permission-controlled confirmation that records are not under hold |

V1 user-facing CSV import covers Course Specification and Curriculum Version templates. Rooms, faculty, qualifications, fees, historical student records, historical grades, and ledger history use setup seeders for initial migration and normal workspace forms for ongoing maintenance.

---
