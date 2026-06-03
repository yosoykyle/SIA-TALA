# TALA Pre-UAT Developer/Internal QA Checklist

**Date Prepared:** 2026-06-01
**Iteration:** TAL-12 - Hardening, UAT, and Go-Live Readiness
**Audience:** Developer / internal tester
**Scope:** Backend + Filament Admin only, including shared student-domain backend logic required by admin workflows
**Out of Scope:** Student Portal UI, student self-service contracts (`TAL-13`), production cutover approval, staff/client UAT sign-off
**Status:** Required before staff/client UAT handoff

---

## 1. Purpose

This checklist is the internal developer QA gate before staff/client UAT. The developer/tester uses this document to verify that the implemented backend and Filament Admin workflows match the Functional Specification and Technical Specification before the system is handed to Registrar, Accounting/Cashier, Faculty, Academic Head, System Super Admin, or project-owner reviewers.

This document is not the staff UAT sign-off sheet and is not production approval. It is the internal proof that the developer/tester already exercised the system and recorded failures before external handoff.

---

## 2. Required Sequence

1. Execute this Pre-UAT Developer/Internal QA checklist.
2. Record every failed row in the QA issue log.
3. Fix small/medium bugs under TAL-12 and retest failed rows.
4. Create separate Linear issues for large missing-module gaps before staff UAT.
5. Move Student Portal UI or student self-service contract findings to TAL-13.
6. Refresh the UAT checklist and go-live runbook if developer QA changes actual behavior.
7. Handoff to staff/client UAT only after critical developer QA rows pass or have accepted written risk.

---

## 3. Product Quality Component Options (ISO 25010)

Use one or more of the following values in the `Product Quality Component` column:

- Functional Suitability
- Performance Efficiency
- Compatibility
- Usability
- Reliability
- Security
- Maintainability
- Portability
- Safety

---

## 4. Execution Rules

- Use local/UAT data only. Do not use real production student data during this developer QA pass.
- Mark only one of `Pass` or `Fail` per executable row.
- If a scenario is blocked, mark `Fail` and explain the blocker in `Comments/Suggestion`.
- If a row fails because of a bug, create or link a TAL-12 bug/fix task and retest the row after the fix.
- If a row reveals a missing major module, create a separate Linear issue before staff UAT.
- If the issue is Student Portal UI or student self-service contract behavior, move it to `TAL-13`.
- Do not use `/student/*` placeholder routes/pages as TAL-12 readiness evidence. TAL-12 readiness is based on backend/admin services, Filament workflows, and shared student-domain logic required by staff workflows.
- Do not update staff UAT as ready-for-handoff until all critical developer QA rows pass or have accepted written risk.

---

## 5. Developer QA Test Case Scenario Table

| Test Case Scenario ID | Name of the Module Function | Test Case Scenario | Action | Actual Input | Pass | Fail | Product Quality Component | Comments/Suggestion |
|----------------------|----------------------------|-------------------|--------|--------------|------|------|--------------------------|---------------------|
| *[Unique ID, e.g., DEV-REG-001]* | *[Module/feature name]* | *[Developer QA scenario]* | *Step 1: Open the target screen or command.<br>Step 2: Execute the listed scenario.<br>Step 3: Record the actual input and mark Pass or Fail.* | *[Input data/values]* | *[PASSED]* | *[FAILED]* | *[Select one or more quality components]* | *[Observations/bugs/improvements]* |
| **DEVELOPER SETUP AND BASELINE CHECKS** | | | | | | | | |
| DEV-BASE-001 | Spec-First Gate | Developer confirms QA is based on current Functional and Technical specs. | Step 1: Open `TALA-Functional-Specification.md`.<br>Step 2: Open `TALA-Technical-Specification.md`.<br>Step 3: Confirm the tested workflow exists in the current specs before marking any row Pass. | `TALA-Functional-Specification.md`; `TALA-Technical-Specification.md`. |  |  | Functional Suitability, Maintainability |  |
| DEV-BASE-002 | Migration Baseline | Local/UAT schema is fully migrated before manual QA starts. | Step 1: Open a terminal in the project root.<br>Step 2: Run `php artisan migrate:status --no-interaction`.<br>Step 3: Confirm `2026_06_02_120612_add_name_parts_to_users_table.php` is marked `Ran` before testing user/staff/student identity flows.<br>Step 4: Confirm there are no unexpected pending migrations before manual QA starts. | Current local/UAT database. |  |  | Reliability, Maintainability |  |
| DEV-BASE-003 | Automated Regression Baseline | Existing backend/admin automated tests pass before manual QA. | Step 1: Open the TAL-12 regression evidence.<br>Step 2: Run the focused TAL-12/TAL-12A PHPUnit command recorded for the workflow.<br>Step 3: Confirm the command passes before manual QA starts. | Existing PHPUnit suites. |  |  | Reliability, Maintainability |  |
| DEV-BASE-004 | Filament Access Baseline | Staff admin panel is reachable for seeded/approved staff accounts. | Step 1: Start the Laravel app.<br>Step 2: Open the Admin Nexus/Filament panel.<br>Step 3: Login once as each seeded staff role and confirm access succeeds only for approved staff accounts. | Registrar, Accounting, Faculty, Academic Head, System Super Admin accounts. |  |  | Functional Suitability, Security, Usability |  |
| **REGISTRAR - ENROLLMENT, DOCUMENTS, SCHEDULING, COR** | | | | | | | | |
| DEV-REG-001 | Enrollment Review Queue | Registrar can review submitted applicant/enrollment records using allowed actions only. | Step 1: Login as Registrar.<br>Step 2: Open the enrollment review queue.<br>Step 3: Select a submitted applicant/enrollment record.<br>Step 4: Perform one allowed review action and verify no disallowed actions are available. | Test submitted applicant/enrollment record. |  |  | Functional Suitability, Security, Usability |  |
| DEV-REG-002 | OCR/Document Review | Registrar can review uploaded document evidence and OCR/manual-review status without auto-promoting unverified OCR values. | Step 1: Login as Registrar.<br>Step 2: Open the document review queue.<br>Step 3: Inspect the source upload, OCR result, confidence/status, and review controls.<br>Step 4: Confirm OCR values stay provisional until staff approval. | Test document upload with OCR result. |  |  | Functional Suitability, Reliability, Safety |  |
| DEV-REG-003 | Walk-In Intake | Walk-in document handling bypasses unnecessary OCR and still applies prerequisite/payment/capacity rules. | Step 1: Login as Registrar.<br>Step 2: Create or open a walk-in enrollment test record.<br>Step 3: Enter First Name, optional Middle Name, Last Name, and optional Suffix/Extended Name.<br>Step 4: Confirm physical document submission.<br>Step 5: Verify OCR is bypassed and prerequisite/payment/capacity rules still control the next state.<br>Step 6: Verify the saved account displays the composed full name while academic fields remain on the student profile. | Walk-in test enrollment with split-name values and hard-copy document flag. |  |  | Functional Suitability, Performance Efficiency |  |
| DEV-REG-004 | Scheduling/Import Audit Controls | Registrar can use current scheduling controls and the Import Batch Audit surface without mutating Accounting-only fees. | Step 1: Login as Registrar.<br>Step 2: Open scheduling resources and verify allowed scheduling actions only.<br>Step 3: Open Import Batch Audit.<br>Step 4: Confirm Import Batch Audit has no generic Create/Edit route or action.<br>Step 5: If a pending import batch exists, verify only approved commit/cancel controls are available.<br>Step 6: Confirm dedicated upload/parse/preview pages are not claimed as implemented unless a separate issue delivers them.<br>Step 7: Confirm Accounting-only fee mutation controls are unavailable. | Active term, schedule/import-batch test data, optional pending `import_batches` row. |  |  | Functional Suitability, Security, Maintainability |  |
| DEV-REG-005 | COR Controls | COR is available only for finance-cleared enrollments. | Step 1: Login as Registrar.<br>Step 2: Open a finance-cleared enrollment and confirm the COR action is available.<br>Step 3: Open a pending-payment enrollment.<br>Step 4: Confirm COR generation is restricted for the pending-payment case. | One `Pre-Enrolled` or `OfficiallyEnrolled` record; one `PendingPayment` record. |  |  | Functional Suitability, Reliability, Security |  |
| DEV-REG-006 | Document Request Catalog and Fulfillment | Registrar can process only approved document request types and fulfill requests with delivery details after shipment. | Step 1: Login as Registrar.<br>Step 2: Open the document request resource or request detail.<br>Step 3: Confirm the selectable/requested document type is one of: Certificate of Registration, Certificate of Enrollment, Certificate of Good Moral Character, Transcript of Records, Form 137, Form 138, Diploma, or Other.<br>Step 4: Confirm arbitrary undocumented document types are not available as selectable values.<br>Step 5: Open a document request in Registrar processing state and move it through processing.<br>Step 6: For delivery, record courier, tracking or N/A, receipt, and actual shipping fee. | Test document request in Registrar processing state using one approved document type. |  |  | Functional Suitability, Reliability, Usability |  |
| DEV-REG-007 | Registrar Finance Boundary | Registrar cannot confirm payments, approve promissory notes, or mutate ledger rows. | Step 1: Login as Registrar.<br>Step 2: Attempt to access payment-confirmation controls if visible.<br>Step 3: Attempt promissory approval if visible.<br>Step 4: Attempt ledger mutation if visible.<br>Step 5: Confirm each financial mutation is blocked. | Test payment/promissory/ledger data. |  |  | Security, Functional Suitability |  |
| **ACCOUNTING / CASHIER - ASSESSMENT, PAYMENTS, INSTALLMENTS** | | | | | | | | |
| DEV-ACC-001 | Assessment and Ledger Review | Accounting can review assessed fees, discounts, payments, credits, and balances. | Step 1: Login as Accounting/Cashier.<br>Step 2: Open the assessment/ledger view.<br>Step 3: Inspect assessed fees, discounts, payments, credits, and balance details.<br>Step 4: Confirm the displayed totals match the ledger records. | Assessed enrollment with ledger entries. |  |  | Functional Suitability, Usability |  |
| DEV-ACC-002 | Manual Payment Confirmation | Accounting can confirm pending OTC/manual payment and produce payment/ledger evidence. | Step 1: Login as Accounting/Cashier.<br>Step 2: Open the payment queue.<br>Step 3: Select a pending manual/OTC payment.<br>Step 4: Confirm the payment.<br>Step 5: Verify payment status and ledger evidence were created. | Pending manual/OTC `payment_attempts` row. |  |  | Functional Suitability, Reliability, Security |  |
| DEV-ACC-003 | PayMongo Webhook Visibility | PayMongo/mock/sandbox events are visible, idempotent, and do not double-post. | Step 1: Open PayMongo mock/sandbox webhook evidence.<br>Step 2: Send or inspect a duplicate payment event.<br>Step 3: Verify the event is stored/visible.<br>Step 4: Confirm only one payment and ledger posting exists for the provider reference. | PayMongo mock/sandbox event ID and provider reference. |  |  | Reliability, Security |  |
| DEV-ACC-004 | Promissory Non-Clearing | Promissory approval records promise tracking only and does not make enrollment finance-cleared. | Step 1: Login as Accounting/Cashier.<br>Step 2: Create or review a promissory case.<br>Step 3: Approve or record the promissory note if the test requires it.<br>Step 4: Confirm the enrollment remains not finance-cleared without actual required payment. | Active promissory note; underpaid enrollment. |  |  | Functional Suitability, Security, Reliability |  |
| DEV-ACC-005 | Installment Overdue Rules | Installment due dates, 3-day grace, and 5% monthly missed-payment penalty are visible and consistent. | Step 1: Login as Accounting/Cashier.<br>Step 2: Open an installment case with overdue sample data.<br>Step 3: Inspect due date, grace period, overdue status, and penalty state.<br>Step 4: Run or review overdue job evidence if needed.<br>Step 5: Confirm the 3-day grace and recurring 5% monthly penalty behavior is visible. | Installment policy/milestone with overdue sample. |  |  | Functional Suitability, Reliability |  |
| DEV-ACC-006 | Shipping Payment Confirmation | Accounting can confirm shipping payment after Registrar records shipment. | Step 1: Login as Accounting/Cashier.<br>Step 2: Open a document request in `pending_shipping_payment` state.<br>Step 3: Confirm the shipping fee payment.<br>Step 4: Verify the document request moves to completion according to the flow. | Delivery document request with actual shipping fee. |  |  | Functional Suitability, Reliability |  |
| DEV-ACC-007 | Accounting Academic Boundary | Accounting cannot edit grades, schedules, or Registrar-owned academic records. | Step 1: Login as Accounting/Cashier.<br>Step 2: Attempt to edit grades if visible.<br>Step 3: Attempt to edit schedules if visible.<br>Step 4: Attempt to mutate Registrar-owned academic records if visible.<br>Step 5: Confirm academic mutations are blocked. | Grade/schedule/enrollment academic test records. |  |  | Security, Functional Suitability |  |
| **FACULTY - CLASS LISTS, FINANCE PRIVACY, GRADES** | | | | | | | | |
| DEV-FAC-001 | Faculty Class List | Faculty sees only assigned class lists and eligible students. | Step 1: Login as Faculty.<br>Step 2: Open the class list screen.<br>Step 3: Verify assigned section/subject records are visible.<br>Step 4: Confirm unassigned class records are not visible. | Faculty account assigned to one section. |  |  | Functional Suitability, Security, Usability |  |
| DEV-FAC-002 | Finance Privacy Boundary | Faculty sees only allowed finance status badge, never balances, receipts, ledger, or promissory documents. | Step 1: Login as Faculty.<br>Step 2: Open the class list/payment indicator.<br>Step 3: Inspect finance information for paid and with-balance students.<br>Step 4: Confirm only the allowed finance status badge is visible and no balances, receipts, ledger, or promissory documents appear. | Students with paid and with-balance cases. |  |  | Security, Functional Suitability |  |
| DEV-FAC-003 | Grade Encoding | Faculty can encode valid grades only for assigned section/subject. | Step 1: Login as Faculty.<br>Step 2: Open the grade encoding screen for an assigned class.<br>Step 3: Enter valid grade values.<br>Step 4: Save the draft.<br>Step 5: Confirm the draft persists only for the assigned section/subject. | Assigned class with valid grade values. |  |  | Functional Suitability, Usability, Reliability |  |
| DEV-FAC-004 | Grade Finalization | Faculty can submit/finalize grade sheet and cannot edit after finalization without approved flow. | Step 1: Login as Faculty.<br>Step 2: Complete the assigned grade sheet.<br>Step 3: Submit/finalize the grade sheet.<br>Step 4: Attempt a post-finalization edit.<br>Step 5: Confirm edits are blocked unless the approved correction flow is used. | Complete assigned grade set. |  |  | Functional Suitability, Security, Reliability |  |
| DEV-FAC-005 | Grade Correction Participation | Faculty can provide allowed clarification but cannot bypass Registrar/Academic Head correction policy. | Step 1: Login as Faculty.<br>Step 2: Open a grade correction-related workflow.<br>Step 3: Provide allowed clarification if the current screen supports it, or record that faculty clarification remains an internal Registrar note.<br>Step 4: Verify the official grade change still follows Registrar review and prior Academic Head approval recording.<br>Step 5: Confirm Faculty cannot directly approve or apply the official grade change. | Grade correction request needing faculty clarification. |  |  | Functional Suitability, Maintainability, Security |  |
| DEV-FAC-006 | Faculty Assignment Boundary | Faculty cannot access or edit another faculty member's class or grade sheet. | Step 1: Login as Faculty.<br>Step 2: Attempt to access an unassigned class or grade record.<br>Step 3: Attempt to edit the unassigned record if reachable.<br>Step 4: Confirm access or mutation is blocked. | Unassigned class or grade record. |  |  | Security, Functional Suitability |  |
| **ACADEMIC HEAD - OVERSIGHT AND OVERRIDE ACTIONS** | | | | | | | | |
| DEV-AHD-001 | Read-Only Oversight | Academic Head can inspect oversight data without normal write authority. | Step 1: Login as Academic Head.<br>Step 2: Open oversight screens for grades, schedules, and enrollment.<br>Step 3: Inspect available controls.<br>Step 4: Confirm default access is read-only except approved override actions. | Grade/schedule/enrollment oversight data. |  |  | Functional Suitability, Security, Usability |  |
| DEV-AHD-002 | Grade Override Approval | Academic Head override requires non-empty reason and creates audit evidence. | Step 1: Login as Academic Head.<br>Step 2: Open an eligible finalized grade/correction requiring override.<br>Step 3: Execute the available override action with a non-empty reason where the current screen provides one.<br>Step 4: For Registrar-resolved grade corrections, verify the Registrar action records the already-approved Academic Head and reason instead of bypassing approval.<br>Step 5: Verify audit/context evidence is created. | Finalized grade/correction requiring override. |  |  | Functional Suitability, Reliability, Security |  |
| DEV-AHD-003 | Reopen/Force-Finalize Boundary | Academic Head can reopen or force-finalize only through approved override actions. | Step 1: Login as Academic Head.<br>Step 2: Attempt an approved reopen or force-finalize override action.<br>Step 3: Attempt a direct edit path if visible.<br>Step 4: Confirm only approved override actions work and direct edits are blocked. | Eligible grade sheet and non-eligible grade sheet. |  |  | Security, Functional Suitability |  |
| DEV-AHD-004 | Academic Head Finance Boundary | Academic Head finance access is read-only and limited to approved summary/status surfaces only. | Step 1: Login as Academic Head.<br>Step 2: Confirm the Academic Head can view only read-only finance status, fee template/downpayment rules, installment policy summary, and promissory status/tag.<br>Step 3: Confirm Accounting payment queues, confirmed-payment ledgers, full ledger-entry review, and installment milestone maintenance screens are not available.<br>Step 4: Attempt payment confirmation, promissory approval, assessment creation, discount application, installment policy edit, or ledger mutation if any path is visible.<br>Step 5: Confirm all finance mutation actions are blocked. | Enrollment finance status, fee template, installment policy, promissory, payment, and ledger test records. |  |  | Security, Functional Suitability |  |
| **SYSTEM SUPER ADMIN - USERS, ROLES, AUDIT, FAQ** | | | | | | | | |
| DEV-SSA-001 | Staff User Management | System Super Admin can manage approved staff account/status fields. | Step 1: Login as System Super Admin.<br>Step 2: Open staff user management.<br>Step 3: Create or update a test staff account using First Name, optional Middle Name, Last Name, optional Suffix, username, email, password/status, and one approved staff role.<br>Step 4: Verify the old single full-name creation field and system-managed archive/email-verification timestamp fields are not editable creation inputs.<br>Step 5: Verify the saved table/detail display uses the composed full name.<br>Step 6: Verify active/inactive behavior matches the saved status. | Test staff user split-name data. |  |  | Functional Suitability, Security, Maintainability |  |
| DEV-SSA-002 | Role/Audit/Internal Settings Boundary | System Super Admin can manage roles and view audit logs while generic runtime settings remain hidden/internal and academic/financial ownership is not bypassed. | Step 1: Login as System Super Admin.<br>Step 2: Open the roles screen and verify role management remains available.<br>Step 3: Open the audit log screen and verify read-only audit review remains available.<br>Step 4: Confirm generic System Settings is not visible in Filament navigation.<br>Step 5: Attempt direct generic System Settings URL access if known.<br>Step 6: Confirm direct access is blocked and no raw JSON/cutover/default setting edit path is available.<br>Step 7: Login as Registrar, Accounting, Faculty, and Academic Head, then confirm Roles and Audit Logs are not visible in their navigation.<br>Step 8: Attempt academic or finance mutation from System Super Admin if visible.<br>Step 9: Confirm domain ownership boundaries remain enforced. | Seeded staff accounts, seeded roles, seeded `system_settings` rows, known System Settings resource URL if available. |  |  | Functional Suitability, Security, Maintainability |  |
| DEV-SSA-003 | Audit Log Review | System Super Admin can view audit logs but cannot destroy evidence. | Step 1: Login as System Super Admin.<br>Step 2: Open the audit log resource.<br>Step 3: View an existing audit log detail.<br>Step 4: Confirm destructive actions are unavailable. | Existing activity log rows. |  |  | Security, Reliability, Maintainability |  |
| DEV-SSA-004 | FAQ Management | System Super Admin can create/edit/order/publish FAQ entries using only the approved fixed categories. | Step 1: Login as System Super Admin.<br>Step 2: Open the FAQ resource.<br>Step 3: Create or edit a test FAQ entry.<br>Step 4: Confirm the category field is a fixed selection list with only: General, Admission / Enrollment, Payments / Fees, Documents / Requests, Grades / Academics, Account / Login, and Technical Support.<br>Step 5: Set order/category/published state and save.<br>Step 6: Verify the saved visibility/published state and category label. | Test FAQ question, answer, approved category, order, and published state. |  |  | Functional Suitability, Usability, Maintainability |  |
| DEV-SSA-005 | System Super Admin Academic/Finance Boundary | System Super Admin cannot directly edit grades or confirm payments. | Step 1: Login as System Super Admin.<br>Step 2: Attempt to directly edit grades if visible.<br>Step 3: Attempt to confirm payments if visible.<br>Step 4: Confirm academic and financial direct mutations are blocked where required. | Grade and payment test records. |  |  | Security, Functional Suitability |  |
| **CROSS-MODULE, INTEGRATIONS, OPERATIONS** | | | | | | | | |
| DEV-XMOD-001 | Enrollment to Assessment Handoff | Registrar approval unlocks payment phase and Accounting owns financial confirmation. | Step 1: Login as Registrar.<br>Step 2: Approve a test enrollment.<br>Step 3: Login as Accounting/Cashier.<br>Step 4: Verify the payment/assessment queue receives the handoff and Accounting owns financial confirmation. | Test applicant/enrollment record. |  |  | Functional Suitability, Reliability |  |
| DEV-XMOD-002 | Payment Clearance State | Required payment or full payment triggers `Pre-Enrolled`; promissory-only case does not. | Step 1: Confirm a qualifying payment for one enrollment.<br>Step 2: Inspect the enrollment state and confirm it becomes `Pre-Enrolled` when requirements are met.<br>Step 3: Compare with a promissory-only enrollment.<br>Step 4: Confirm promissory-only does not trigger finance clearance. | One qualifying payment; one promissory-only enrollment. |  |  | Functional Suitability, Reliability, Security |  |
| DEV-XMOD-003 | Freshmen Discount | New Grade 11 or 1st Year receives 50% discount on tuition fee only. | Step 1: Create or open an eligible new Grade 11 or 1st Year assessment.<br>Step 2: Run/inspect assessment calculation.<br>Step 3: Verify the 50% discount is applied to tuition fee only.<br>Step 4: Confirm lab, miscellaneous, and other fees are not discounted. | New Grade 11 or 1st Year test student with fee lines. |  |  | Functional Suitability, Reliability |  |
| DEV-XMOD-004 | OCR Failure Fallback | OCR failure/low confidence creates manual-review path and does not block staff validation. | Step 1: Process a failed or low-confidence OCR upload.<br>Step 2: Inspect the OCR result/status record.<br>Step 3: Open the staff manual-review controls.<br>Step 4: Confirm the failure routes to manual review and does not block staff validation. | Test upload with OCR failure/low confidence. |  |  | Reliability, Functional Suitability, Safety |  |
| DEV-XMOD-005 | Calendar and Phase Gates | Actions outside configured calendar windows are blocked according to policy. | Step 1: Confirm the active term has gate settings.<br>Step 2: Attempt enrollment, scheduling, or grade action inside the configured gate.<br>Step 3: Attempt the same action outside the configured gate.<br>Step 4: Confirm policy blocks outside-window actions. | Active term with gate settings. |  |  | Functional Suitability, Security, Reliability |  |
| DEV-XMOD-006 | Queue Failure Visibility | Queue tables and failed-job visibility are available for operational review. | Step 1: Open a terminal in the project root.<br>Step 2: Run `php artisan queue:failed --no-interaction`.<br>Step 3: Inspect queue status evidence if needed.<br>Step 4: Confirm failed-job visibility is available for operational review. | Local/UAT queue tables. |  |  | Reliability, Maintainability |  |
| DEV-XMOD-007 | Scheduler Visibility | Required scheduled jobs are registered. | Step 1: Open a terminal in the project root.<br>Step 2: Run `php artisan schedule:list --no-interaction`.<br>Step 3: Verify installment overdue and shipping-fee schedules are registered.<br>Step 4: Record any missing scheduled job as a Fail. | Laravel scheduler configuration. |  |  | Reliability, Maintainability |  |
| DEV-XMOD-008 | Health Route | Health route is reachable before UAT handoff. | Step 1: Resolve the local/UAT application URL.<br>Step 2: Open `/up` or inspect the route list.<br>Step 3: Verify the expected health response.<br>Step 4: Record any routing or response failure. | Local/UAT application URL. |  |  | Reliability, Usability |  |
| DEV-XMOD-009 | Staff Role Smoke Test | Every staff role can perform one allowed workflow and is blocked from one restricted workflow. | Step 1: Login as Registrar and execute one allowed plus one restricted workflow.<br>Step 2: Repeat for Accounting/Cashier.<br>Step 3: Repeat for Faculty.<br>Step 4: Repeat for Academic Head.<br>Step 5: Repeat for System Super Admin and record all blocked/allowed results.<br>Step 6: Confirm only System Super Admin sees Roles, Audit Logs, Users, and FAQ administration.<br>Step 7: Confirm no role sees generic System Settings navigation. | Seeded staff accounts. |  |  | Security, Functional Suitability |  |
| DEV-XMOD-010 | Artifact Refresh Decision | Developer determines whether UAT/runbook artifacts need revision after QA. | Step 1: Review all completed developer QA rows.<br>Step 2: Identify failed rows and behavior changes.<br>Step 3: Decide if the UAT checklist must be refreshed.<br>Step 4: Decide if the go-live runbook must be refreshed.<br>Step 5: Record the decision and linked updates. | Completed developer QA results. |  |  | Maintainability, Functional Suitability |  |

---

## 6. Coverage Decision

This Pre-UAT Developer/Internal QA checklist is intended to cover **all critical backend + Filament Admin readiness scenarios** for the current TAL-12 scope. It contains **43 executable scenarios** across the required staff roles, cross-module handoffs, integrations, queues/scheduler checks, health checks, and artifact-refresh decisions.

This does **not** mean every possible micro-case is manually listed. Field-level validation permutations, unusual data combinations, and bug-specific retests remain covered by automated tests and by new issue-log rows created when failures are found. Student Portal UI and student self-service contract testing remain out of scope for this artifact and belong to `TAL-13`.

| Coverage Area | Scenario Range | Covered for Pre-UAT? | Notes |
| --- | --- | --- | --- |
| Developer setup and baseline | DEV-BASE-001 to DEV-BASE-004 | Yes | Confirms spec-first basis, migration baseline, regression baseline, and staff panel access. |
| Registrar workflows | DEV-REG-001 to DEV-REG-007 | Yes | Covers enrollment review, OCR/manual review, walk-in intake, scheduling/import-batch audit, COR control, fixed document request catalog/fulfillment, and finance boundary. Dedicated import upload/preview pages are not counted as implemented until a separate issue delivers them. |
| Accounting/Cashier workflows | DEV-ACC-001 to DEV-ACC-007 | Yes | Covers assessment, ledger review, manual payments, PayMongo visibility, promissory non-clearing, installment penalties, shipping payment, and academic boundary. |
| Faculty workflows | DEV-FAC-001 to DEV-FAC-006 | Yes | Covers class lists, finance privacy, grade encoding, finalization, correction participation, and assignment boundary. Full faculty availability self-service is not counted as implemented until a separate issue delivers submission/review/change-request screens. |
| Academic Head workflows | DEV-AHD-001 to DEV-AHD-004 | Yes | Covers read-only oversight, approved grade override, reopen/force-finalize boundary, offline-approved grade-correction recording, and the narrowed read-only finance scope for status/rules/summaries/tags only. |
| System Super Admin workflows | DEV-SSA-001 to DEV-SSA-005 | Yes | Covers staff users, Role/Audit access, hidden/internal runtime settings boundary, fixed-category FAQ management, and read-only academic/finance boundary. COR template editor and separate System Health page are not counted as implemented unless separate issues deliver them. |
| Cross-module, integrations, and operations | DEV-XMOD-001 to DEV-XMOD-010 | Yes | Covers enrollment-to-assessment handoff, payment clearance, tuition-only freshmen discount, OCR failure fallback, phase gates, queue/scheduler visibility, health route, role smoke test, and artifact refresh decision. |
| Staff/client UAT signatures | UAT artifact only | No | Executed later after developer QA passes or accepted risk is documented. |
| Production cutover approval | Go-live runbook only | No | Executed later after developer QA, staff/client UAT, and operational owner approval. |
| Shared student-domain backend logic required by admin workflows | TAL-12/TAL-12A | Yes | Covered through enrollment, assessment, payment, document, OCR, grade, class-list, and gate scenarios. |
| Student Portal UI and self-service contracts | TAL-13 | No | `/student/*` pages are not TAL-12 readiness evidence until protected, data-backed, and tested under TAL-13. |
| Larger unimplemented admin surfaces | Separate implementation issues | No | Dedicated import upload/preview pages, faculty availability self-service, COR template editor, document-catalog admin UI, rich dashboard metric correctness, and separate System Health admin page must be accepted risk or moved into Linear before staff/client UAT if stakeholders require them. |

If a tester finds a valid critical scenario that is not represented above, add it to this checklist or the issue log before staff/client UAT handoff.

---
## 7. Developer QA Issue Log

| Issue ID | Test Case Scenario ID | Severity | Description | Owner | Fix Location / Linear Link | Status | Retest Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| TAL-15 | DEV-REG-004 / DEV-FAC-005 / DEV-SSA-002 / Coverage Decision | Medium | Larger admin surfaces are not current TAL-12 Pre-UAT evidence: dedicated import upload/preview pages, faculty availability self-service, COR template editor, document-catalog admin UI, rich dashboard metric correctness, and optional System Health admin page. | Developer / Stakeholder decision | Linear `TAL-15` | Backlog / Accepted out of current TAL-12 scope unless stakeholders pull forward | N/A until implemented |
|  |  | Critical / High / Medium / Low |  |  |  | Open / Fixed / Accepted Risk / Moved to TAL-13 |  |

---

## 8. Developer QA Sign-Off

| Area | Required Before Sign-Off | Result | Name / Signature | Date | Notes |
| --- | --- | --- | --- | --- | --- |
| Baseline setup | DEV-BASE-001 to DEV-BASE-004 pass or accepted risk documented |  |  |  |  |
| Registrar workflows | DEV-REG-001 to DEV-REG-007 pass or accepted risk documented |  |  |  |  |
| Accounting workflows | DEV-ACC-001 to DEV-ACC-007 pass or accepted risk documented |  |  |  |  |
| Faculty workflows | DEV-FAC-001 to DEV-FAC-006 pass or accepted risk documented |  |  |  |  |
| Academic Head workflows | DEV-AHD-001 to DEV-AHD-004 pass or accepted risk documented |  |  |  |  |
| System Super Admin workflows | DEV-SSA-001 to DEV-SSA-005 pass or accepted risk documented, including hidden/blocked generic System Settings access |  |  |  |  |
| Cross-module/ops workflows | DEV-XMOD-001 to DEV-XMOD-010 pass or accepted risk documented |  |  |  |  |
| UAT/runbook refresh decision | Existing UAT and go-live runbook reviewed after QA |  |  |  |  |

---

## 9. Exit Criteria

Pre-UAT Developer/Internal QA is complete only when:

- Every critical row is marked `Pass` or has an accepted written risk.
- Every `Fail` row is recorded in the issue log.
- Small/medium bugs are fixed and retested under TAL-12.
- Large missing-module gaps are moved into separate Linear issues before staff UAT.
- Student Portal UI and student self-service contract findings are moved to TAL-13.
- `TALA-UAT-Checklist-Signoff-2026-06-01.md` is refreshed if QA changes staff-facing behavior.
- `TALA-Go-Live-Cutover-Runbook-2026-06-01.md` is refreshed if QA changes launch, rollback, monitoring, payment, OCR, or smoke-test behavior.

---

## 10. Current Status

| Item | Status |
| --- | --- |
| Developer QA artifact | Prepared |
| Developer QA execution | Pending |
| Staff/client UAT handoff | Blocked until developer QA pass or accepted risk |
| Existing UAT checklist | Prepared artifact; refresh after developer QA if behavior changes |
| Existing go-live runbook | Prepared artifact; refresh after developer QA if behavior changes |
| 2026-06-03 role/resource reconciliation | Applied to code, specs, checklist, and Linear evidence; Pre-UAT execution still pending |
| Production approval | Not approved |


