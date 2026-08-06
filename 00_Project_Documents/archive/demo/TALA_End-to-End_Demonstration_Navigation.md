# End-to-End TALA Demonstration Navigation

> **Archived demonstration material — not current product, credential, fixture, or execution authority.** Do not use these steps until a later canonical vertical slice explicitly revalidates them.

This guide has two distinct uses:

1. **Prepared presentation:** use seeded records to show completed and blocked states without rebuilding the institution during a timed presentation.
2. **Manual creation:** use unique records to learn which source records, actions, and approvals are required to create a new journey.

Do not mix the two paths. Never overwrite a seeded persona, published run, payment reference, or grade roster to demonstrate data entry.

All accounts use:

- Password: `password`
- Staff: `http://127.0.0.1:8000/admin/login`
- Applicant: `http://127.0.0.1:8000/applicant/login`
- Student: `http://127.0.0.1:8000/student/login`

## 1. Prepare three browser sessions

Use separate browser profiles so sessions do not replace each other:

1. Applicant session
2. Student session
3. Staff session

Only one staff role can use the Staff session at a time. Sign out before switching Registrar, Accounting, Faculty, Academic Head, or System Super Admin.

## 2. Integral accounts

| Presentation purpose | Account |
|---|---|
| Editable application | `applicant.demo@example.test` |
| Submitted application | `applicant.review.demo@example.test` |
| Application needing correction | `applicant.action-required.demo@example.test` |
| Application ready for decision | `applicant.evaluation.demo@example.test` |
| Approved application | `applicant.approved.demo@example.test` |
| Admissions, scheduling, enrollment | `registrar.demo@example.test` |
| Academic and schedule oversight | `academic-head.demo@example.test` |
| Official enrolled student with COR and schedule | `student.dit-1a.005@example.test` |
| Student with amount due and failed checkout | `student.dit-1a.001@example.test` |
| Student with partial payment | `student.dit-1a.002@example.test` |
| Finance-cleared but academically blocked student | `student.dit-2a.001@example.test` |
| Assessment, payment, and ledger | `accounting.demo@example.test` |
| Faculty schedule and grade entry | `faculty.demo@example.test` |
| General Student Hub and released grades | `student.demo@example.test` |
| Irregular enrollment example | `student.dbm-2a.001@example.test` |
| Cancelled enrollment example | `student.dthm-1a.001@example.test` |
| Completion review | `student.completion.demo@example.test` |
| Graduation review | `student.graduation.demo@example.test` |
| Integration health, users, audit | `system-admin.demo@example.test` |

## Before the demo: preparation checklist

Complete this checklist before opening the first demonstration account. The goal is to confirm that the prepared evidence is available and that the presentation will not accidentally create or replace institutional records.

### Start the local application

Open separate terminals in the project directory and start:

```bash
php artisan serve
```

```bash
npm run dev
```

Start the queue listener only when the demonstration needs scheduling or integration processing:

```bash
php artisan queue:listen --queue=scheduling,default --timeout=360
```

On Windows, do not rely on `composer run dev` if Pail fails because `pcntl` is unavailable. Start the essential processes separately.

### Verify the workspace entry points

Open each URL in a private or separate browser profile:

| Workspace | URL | Initial check |
|---|---|---|
| Staff | `http://127.0.0.1:8000/admin/login` | Staff login page loads |
| Applicant | `http://127.0.0.1:8000/applicant/login` | Applicant login page loads |
| Student | `http://127.0.0.1:8000/student/login` | Student login page loads |

Use the exact lowercase email and password `password`. Do not use an Applicant account on Staff login or a Student account on Applicant login.

### Verify the prepared checkpoint

Before the timetable portion, sign in as `registrar.demo@example.test` and verify:

1. **Class Planning** opens.
2. **Source records** → **Generated timetables** contains the prepared published run.
3. Run #10, or the newest prepared run after a deliberate restore, shows **Published**.
4. The run has 54 candidate assignments and zero hard-constraint violations.
5. **Source records** → **Published timetable** shows 54 official meetings.

Do not click **Generate Timetable**, **Retry timetable generation**, **Publish Timetable**, or **Revise published timetable** during this check.

### Verify the official Student checkpoint

Sign in as `student.dit-1a.005@example.test` and confirm:

- Enrollment is **Officially Enrolled**;
- eight active subjects exist;
- eight active schedule bindings exist;
- **Class Schedule** shows the published meetings;
- **Current COR** is available; and
- Finance shows the prepared assessment and payment state.

If any of these are missing, stop and repair or restore the prepared environment before the presentation. Do not create a replacement enrollment during the demo.

### Verify role accounts before presenting

Perform a short login check for the accounts you will actually use:

- `registrar.demo@example.test`
- `academic-head.demo@example.test`
- `accounting.demo@example.test`
- `faculty.demo@example.test`
- `system-admin.demo@example.test`
- the selected Applicant account
- the selected Student account

Sign out before changing staff roles. Navigation is role-dependent; a missing menu usually means the wrong staff role is active.

### Prepare the browser and presenter state

1. Use separate browser profiles for Applicant, Student, and Staff.
2. Keep only one Staff role signed in at a time.
3. Clear table searches and filters before each stage.
4. Select **Second Semester AY 2025–2026** when the Term filter is available.
5. Keep the account table and this guide available as presenter notes, not as a screen to expose credentials unnecessarily.
6. Decide which seeded record demonstrates each blocked state before starting.

### Decide whether to run PayMongo live

Run **Pay Current Due** only when the authorized PayMongo test configuration, active ngrok endpoint, signed webhook, and queue listener are ready. Otherwise show the prepared Payment Attempt, Payment Exception, Operational Event, and Integration Status records.

Never describe a successful checkout return as proof of payment. The signed webhook is the provider evidence.

### Freeze the presentation boundary

During the presentation:

- do not reseed or rebuild the database;
- do not create a replacement Run #10;
- do not retry a failed historical run;
- do not republish an already published timetable;
- do not overwrite seeded applicant, student, payment, or grade records; and
- do not start the manual-creation path unless the presentation explicitly switches to a separate unique test record.

If a prepared state is missing, stop the affected stage, show the recovery explanation, and continue with another prepared state. Do not improvise a destructive repair in front of the audience.

# Manual Creation Path — Reference Only

Use this path when you want to create a new journey instead of showing the seeded one. Use unique names, emails, codes, and references. Do not delete or edit the prepared personas.

## Manual capability boundary

| Manual transition | Current UI result | Entry point or limitation |
|---|---|---|
| Applicant account and draft | Available | Applicant login → **Apply Online** → **Create Applicant Account**; Applicant → **Application** → **Save Draft** |
| Applicant submission and correction | Available | **Submit Application**; Applicant Home → **Review Requirements** → **Submit Corrected Evidence** |
| Registrar review, evaluation, approval, handover | Available | Staff → **Admissions** → **Open Review** |
| Academic year, term, program, courses, curriculum, rooms, qualifications | Available | Registrar → **Academic Readiness** and **Class Planning** → **Source records** |
| Regular offerings, sections, delivery groups | Available | Class Planning → **Source records** → **Term offerings** → **Build Regular Offerings** |
| Schedule requirements, candidate timetable, publication | Available | Class Planning → **Source records** → **Schedule requirements** / **Generated timetables** |
| Continuing Enrollment and placement | Available | Registrar → **Students & Enrollment** → Enrollments → **Start Continuing Enrollment** / **Confirm Placement** |
| First draft Assessment for a new Enrollment | Not available as a visible action | **Student Accounts** has no first-time **Generate Draft Assessment** action. Use a prepared Assessment after the manual placement portion. |
| Assessment activation and manual payment | Available for an existing Assessment | Accounting → **Student Accounts** → open account → **Activate Assessment** / **Record Manual Payment** |
| PayMongo checkout | Available when the integration is authorized | Student → **Finance** → **Pay Current Due**; the signed webhook, not the return page, proves payment |
| Official enrollment | Available | Registrar → open Enrollment → **Record Official Enrollment** |
| First Grade Roster for a new section | Not available as a visible action | The roster generation service exists, but no Registrar or Faculty button currently creates the first roster. Use a prepared roster for grade demonstration. |
| Grade entry and release for an existing roster | Available | Faculty → **Grade Rosters** → **Select Roster**; Registrar → **Grades & Completion** → **Grade Rosters** |

The demonstration baseline already contains Admission Requirement Policies, assessments, and rosters. A completely blank database also has a first-time navigation gap: **Admission Requirements** is registered as a contextual Registrar resource, not a primary setup entry. Do not create policies through direct database edits. If no policy exists and no non-Draft Applicant can be opened to expose **Requirement Policies**, stop and record that UI gap.

## Manual preflight

1. Start the application and confirm the correct workspace URLs at the top of this document.
2. Use a new applicant email such as `applicant.manual.20260731@example.test`.
3. Confirm the Admissions window is open. If **Apply Online** redirects away, the Registrar must create an effective window first.
4. Sign in as Registrar and open **Academic Readiness**.
5. Select the target Term wherever the page provides a term selector. The normal prepared scope is Second Semester AY 2025–2026.
6. Confirm that an active Program and active Curriculum Version exist before creating offerings.
7. Confirm at least one active suitable Room and qualified Faculty member for every face-to-face component.
8. If you intend to show payment or grades, plan to switch to the prepared Assessment and Grade Roster at the documented UI boundaries.

## Manual Stage 1 — Create the academic period

**Role:** Registrar

### Create an Academic Year

1. Open **Academic Readiness** → **Source records** → **Academic years**.
2. Select the visible Create action.
3. Enter **Academic Year**, **School Year Start**, **School Year End**, and **State**.
4. Save.

The dates define the parent boundary for every Term. A Term outside this range is rejected.

### Create a Term

1. Open **Academic Readiness** → **Source records** → **Terms**.
2. Select the visible Create action.
3. Enter **Term Name**, **Academic Year**, **Term Type**, and **State**.
4. Enter start and end dates inside the Academic Year.
5. Set **Scheduling Slot Minutes**, **Scheduling Days**, **Scheduling Day Starts At**, **Scheduling Day Ends At**, and optional **Default Faculty Max Units**.
6. Save.

Recovery: if a later table is empty, select this Term in the page filter before assuming records were not created.

## Manual Stage 2 — Create the academic structure

**Role:** Registrar

1. Open **Academic Readiness** → **Source records** → **Programs** and create the Program code, name, length, and active state.
2. Open **Course catalog** and create each required course code and title.
3. Return to **Academic Readiness** and select **Create curriculum draft**.
4. Open the new draft's review page and select **Add curriculum row** for each subject.
5. For each row, set **Course Specification**, **Year Level**, **Term Type**, **Term Label**, **Display Sequence**, and **Requirement Group**.
6. If the row is incomplete, select **Complete specification** and enter subject title, units, grading profile, allowed modalities, and each component's contact hours, room type, required features, modality, consecutive-block rule, and same-Faculty rule.
7. Use **Edit placement** when the year, term, sequence, or requirement group is wrong.
8. Select **Record external approval**, enter the approval reference, then select **Activate complete curriculum** and confirm activation.

Expected result: the Program has one active, complete Curriculum Version. Until that is true, the offering builder should remain blocked.

## Manual Stage 3 — Configure admissions readiness

**Role:** Registrar

1. Open **Academic Readiness** → **Source records** → **Academic calendar windows**.
2. Create the window with **Term**, **Process**, **Opens At**, **Closes At**, **State**, and **Authority / Reference**.
3. Confirm that the baseline contains Admission Requirement Policies. A Registrar reaches policy maintenance by opening a non-Draft Applicant in **Admissions** and selecting **Requirement Policies**.
4. If that contextual link is available, create or maintain each policy with admission category, credential basis, requirement type, evidence method, blocking level, state, effective dates, and authority.
5. Confirm that requirements are configured as digital upload, physical copy, or staff-tracked evidence.

Expected result: a new Applicant can register, select a valid Term and Program, and receive a checklist after submission.

## Manual Stage 4 — Register and submit an Applicant

**Role:** Applicant

1. Open the Applicant login and select **Apply Online**.
2. On **Create Applicant Account**, enter a unique email and account fields, then finish registration.
3. Open **Application** and complete admission Term, preferred Program, admission category, credential basis, legal name, birth details, contact information, address, guardian details, and prior school.
4. Upload the required digital evidence in **Admission Requirements**.
5. Select **Save Draft** whenever you need to leave an incomplete application.
6. At **Review and Submit**, confirm the accuracy statement.
7. Select **Submit Application**.

Expected result: the private draft becomes visible to the Registrar in the Admissions Work Queue. A draft alone is not reviewable and does not create a Student Profile.

Recovery:

- If submission is disabled, complete the required fields and accuracy confirmation.
- If no requirements load, correct the Admission Requirement Policy scope.
- If the email already exists, use another unique manual-test email.

## Manual Stage 5 — Review evidence and hand over the Applicant

**Role:** Registrar

1. Select **Admissions**. This opens the Admissions Work Queue.
2. Filter by the Applicant's email or workflow state and select **Open Review**.
3. Read **Current Stage**, **Responsible Party**, **Next Action**, **Requirement Readiness**, and **Official Student Record Check**.
4. In **Review Actions**, use **Record Physical Receipt** for a physical requirement, then use the matching Verify action for acceptable evidence.
5. To demonstrate correction, use the matching Reject action and enter **Correction Instruction**.
6. Applicant returns to Home → **Review Requirements** → **Submit Corrected Evidence**, selects **Requirement to Correct**, uploads the **Corrected File**, and submits it.
7. Registrar reopens the review and verifies the corrected evidence.
8. Select **Mark for Evaluation**, confirm, then select **Approve Application** and confirm.
9. Select **Hand Over to Student**.
10. Read the handover preview and possible duplicate profile matches. For a returning Applicant, choose the confirmed new or existing profile decision.
11. Select **Confirm Hand Over**.

Expected result: one official Student Profile is created or reused. Handover does not create Enrollment, payment, timetable, or grades.

## Manual Stage 6 — Prepare Faculty, rooms, and availability

**Roles:** System Super Admin for account/role setup; Registrar and Faculty for academic sources

### Faculty

1. If needed, System Super Admin opens **Users & Access**, creates the User, and grants the Faculty role.
2. Registrar opens **Class Planning** → **Source records** → **Faculty qualifications**.
3. Select Create, choose Faculty and Course, mark **Active Qualification**, and save the recording details.
4. Review **Faculty term loads** and add an approved override only when policy requires one.
5. Faculty opens **My Unavailable Times** and records recurring term-scoped unavailable blocks.

### Rooms

1. Registrar opens **Class Planning** → **Source records** → **Rooms**.
2. Select Create and enter code, name, building, room type, capacity, **Active Room**, and notes.
3. Open the Room and add required features when a component needs them.

Expected result: each physical demand has a suitable Room and each teaching demand has qualified Faculty evidence.

## Manual Stage 7 — Build offerings, sections, and delivery groups

**Role:** Registrar

1. Open **Class Planning** → **Source records** → **Term offerings**.
2. Select **Build Regular Offerings**.
3. Choose **Target Term**, Program, **Active Curriculum Version**, and Year Level.
4. Review **Eligible Curriculum Entries**.
5. For each included row, set modality, expected count, and any approved room-type or same-Faculty override.
6. Enter **Confirmed Section Code**, section capacity, and **Planned Delivery Groups** with group name, expected count, and modality.
7. Use the same logical-cohort name, such as `DIT-1A`, for every subject section attended by that cohort.
8. Submit the builder.
9. Return to **Class Planning** → **Source records** → **Sections and delivery groups**.
10. Open each Section, edit each Delivery Group, and change its state from **Planned** to **Ready** only after the source values are complete.

Expected result: each subject has its own Term Offering and Section, while the shared logical cohort supplies the cross-subject conflict identity.

## Manual Stage 8 — Generate schedule requirements

**Role:** Registrar

1. Open **Class Planning** → **Source records** → **Schedule requirements**.
2. Select the target Term.
3. Select **Generate Schedule Requirements**.
4. Resolve every row marked **Action required**.
5. Confirm the intended rows are **Ready for review**.

Check duration, consecutive-block rules, expected count, modality, Faculty qualification/load, Room suitability, grid hours, fixed assignments, and recurring unavailability before calling the solver.

Expected result: one ready Scheduling Demand exists per required component and delivery group. This step does not call CP-SAT or publish meetings.

## Manual Stage 9 — Generate, review, and publish the timetable

**Role:** Registrar

1. Open **Class Planning** → **Source records** → **Generated timetables**.
2. Select **Generate Timetable** and choose the target Term.
3. Wait for the queued solver request to finish.
4. Open the result with **Review timetable**.
5. Inspect Current Validation, Solution Quality, Hard Constraint Checklist, Soft Objective Evidence, Validation Findings, and Candidate Assignments.
6. Use **Review evidence** for the detailed solver and Laravel checks.
7. Use **Correct assignment** only for an institutionally justified correction; the complete candidate set is revalidated.
8. Select **Publish Timetable**, review **Accept lower soft-quality result** when shown, enter **Publication note**, and confirm.
9. Open **Class Planning** → **Source records** → **Published timetable**.

Expected result: candidate assignments become official meetings, affected offerings become Scheduled, and eligible planned Sections become Open. Do not place students into a candidate or unpublished schedule.

Recovery:

- `Unknown` is inconclusive; it is not mathematical infeasibility.
- `Infeasible` applies only to the exact tested input.
- HTTP, queue, authentication, timeout, and memory failures are operational failures.
- Use **Retry timetable generation** only after the failure cause is understood.
- Use **Enter complete timetable** only for an authorized complete manual replacement, never a partial schedule.

## Manual Stage 10 — Start and confirm Enrollment

**Role:** Registrar

1. Open **Students & Enrollment**. This opens Enrollments.
2. Select **Start Continuing Enrollment**.
3. Choose Student, **Enrollment term**, and **Enrollment type**.
4. Open the new Enrollment.
5. For a regular student, select **Confirm Placement**, choose the **Published logical cohort**, and confirm.
6. For an irregular student, the Student may use Enrollment → **Replace complete proposal**; the Registrar then uses **Confirm Placement**.
7. Review Course Enrollments, reservations, schedule bindings, Current Status, Next Step, and Responsible Office.

Expected result: successful confirmation atomically records eligible course placement, capacity holding, and published schedule bindings.

## Manual Stage 11 — Assessment and payment boundary

After confirming a brand-new Enrollment, the current UI has no visible **Generate Draft Assessment** action. Do not create an Assessment through direct database editing. For the demonstration, switch to a prepared Student Account and continue as follows:

1. Accounting opens **Student Accounts** and searches the student.
2. Open the account and inspect charges, current amount due, payment status, and Finance Gate.
3. Select **Activate Assessment**.
4. Select **Record Manual Payment**.
5. Enter **Amount Received**, **Payment Method**, **Payment / Evidence Reference**, required **OR Number**, Payment Allocation, and **Paid At**.
6. Select **Record payment**.
7. Confirm one Payment and its Ledger posting. Use **Payments and OR Reconciliation** → **Map OR** only to update existing verified evidence.

For PayMongo, Student → **Finance** → **Pay Current Due** is available only when the authorized test integration is running. The return page is informational; the signed webhook is the payment evidence. If external payment is not authorized, show Payment Attempts, Payment Exceptions, Operational Events, and Integration Status instead.

## Manual Stage 12 — Official enrollment and outputs

**Role:** Registrar

1. Open the Enrollment from **Students & Enrollment**.
2. Select **Refresh Gate Results**.
3. Read every failed gate and its responsible office.
4. When the status is Ready for Official Enrollment and capacity is held, select **Record Official Enrollment**.
5. Enter an optional Registrar remark and confirm.
6. Student opens **Enrollment** and uses **Class Schedule** or **Current COR**.
7. Student opens **Academics** for Released Grades, Academic Status, Holds & Blockers, and Completion Review.

Expected result: the Enrollment is Officially Enrolled and source-derived COR and schedule outputs are available when current-record and hold checks pass.

## Manual Stage 13 — Grade entry and release boundary

The current UI has no visible action to create the first Grade Roster for a new published Section. Use a prepared roster for this part of the presentation.

1. Faculty opens **Grade Rosters** and selects **Select Roster**.
2. Choose an assigned Draft or Returned roster.
3. Enter permitted grade values and use **Set controlled final mark** when required.
4. Select **Submit for Registrar Review**.
5. Registrar opens **Grades & Completion** → **Grade Rosters**.
6. Open the submitted roster and choose **Return** with a reason or **Post & Release**.
7. Student opens **Academics** → **Released Grades**.

Only Posted & Released results appear to the Student. Draft, Returned, Submitted, and Late / Not Submitted records remain staff workflow states.

## Manual Stage 14 — Lifecycle, completion, reports, and audit

1. Registrar opens the Student Profile and starts the applicable approved lifecycle review.
2. Read the impact preview before confirming; the preview must not change Enrollment, schedule, seat, finance, or lifecycle records before confirmation.
3. Registrar opens **Grades & Completion** → **Completion Eligibility Reviews** or the applicable graduation review.
4. Review grades, curriculum completion, holds, finance, and outstanding requirements, then record the authorized result and evidence.
5. Use role-owned Reports for Enrollment, sections, ledger, collections, Faculty load, schedule exceptions, grade review, progression, and completion.
6. System Super Admin uses **Governance & Audit** for activity, output access, exports, integration events, and webhook evidence.

These reports and outputs are source-derived and auditable. They do not become a second source of truth.

## Manual recovery rules

| Symptom | Check first | Correct response |
|---|---|---|
| Menu or action is missing | Signed-in role and record state | Use the owning role and correct state; do not bypass policy by changing roles |
| Applicant cannot register | Effective Admissions window | Configure the authorized window or use an existing Applicant account |
| Applicant cannot submit | Required fields, confirmation, and policy scope | Complete the source record; do not remove the requirement |
| Handover is blocked | Requirement, duplicate identity, or active Curriculum blocker | Resolve the named blocker through its owning action |
| Builder shows no entries | Program, active Curriculum, year level, and Term | Correct Academic Readiness first |
| Demand is Action required | Duration, group, Faculty, Room, grid, fixed value, or availability | Correct the source and regenerate requirements |
| Placement has no cohort options | Published Sections and logical-cohort mapping | Publish compatible Sections or use an approved special offering |
| New Student Account has no assessment | Missing Generate Draft Assessment UI action | Stop the manual path and use a prepared Assessment |
| New section has no roster | Missing Generate Grade Roster UI action | Stop the manual path and use a prepared roster |
| COR or schedule is empty | Official Enrollment, active bindings, Published timetable, and holds | Correct the owning source; do not manufacture the output |
| PayMongo return shows success but no Payment | Signed webhook and persisted provider event | Treat return as informational and use Accounting exception handling |

# Live Demo Path — Stages 1–18

Use this section during the actual presentation. Start at Stage 1 and follow the prepared accounts and records. Do not execute the Manual Creation Path during the timed demo unless you deliberately switch to a separate unique test record.

## Stage 1 — Public landing and workspace separation

Open:

`http://127.0.0.1:8000`

Show the Applicant, Student, and Staff workspace entrances.

Say:

> TALA separates public, applicant, student, and staff access. Each person enters the workspace appropriate to their role. Navigation visibility is supported by server-side authorization, so knowing another workspace URL does not grant access.

Do not spend too long here. The purpose is to establish the role boundary.

Expected result:

- Applicant access is separate from Student Hub.
- Student Hub is separate from Staff Workspace.
- Protected institutional records are not public.

---

## Stage 2 — Applicant starts an application

### Account

`applicant.demo@example.test`

### Navigation

1. Sign in through Applicant Workspace.
2. Open **Home**.
3. Open **Application**.
4. From Home, use **Review Requirements** when the checklist needs attention.

### What to show

Show:

- Admission category
- Program choice
- Personal information
- Contact and address information
- Application status
- Requirement checklist
- Save or update actions

Say:

> The applicant creates and maintains an application draft. Validation prevents incomplete or invalid information from moving forward. The applicant can see only their own application and requirements.

### Prepared state

This is an editable first-time applicant draft. You do not need to submit or modify it during the presentation.

### Why this must happen first

The Registrar cannot review a private draft. The applicant must complete and submit the application before it becomes part of the Admissions Work Queue.

### Recovery

If Application is empty:

1. Confirm the email is `applicant.demo@example.test`.
2. Confirm you used `/applicant/login`.
3. Clear page filters or reload.
4. Do not create a replacement application during the presentation.

---

## Stage 3 — Show submitted and correction states

Switch Applicant accounts when needed.

### Submitted for Registrar review

Use:

`applicant.review.demo@example.test`

Open:

1. Dashboard
2. Application
3. From Home, Review Requirements

Say:

> This applicant has submitted the application. The application is no longer simply a private draft; it is waiting for Registrar review.

### Action Required

Use:

`applicant.action-required.demo@example.test`

Open **Home** and select **Review Requirements**.

Show:

- Rejected or deficient requirement
- Registrar feedback
- Replacement-file path
- Exact next action

Say:

> Action Required does not mean the application is automatically rejected. It means the applicant must correct a specific requirement. TALA shows the reason and preserves the evidence history.

### For Evaluation

Use:

`applicant.evaluation.demo@example.test`

Say:

> This application has resolved its immediate requirement issues and is ready for the admission decision.

### Approved

Use:

`applicant.approved.demo@example.test`

Say:

> Approved means the Registrar completed the admission decision. Approval alone does not create an enrollment, timetable, payment, or grade. The controlled applicant-to-student handover must happen next.

Do not perform a handover during the demonstration. Show the prepared approved state and explain the next step.

---

## Stage 4 — Registrar admissions review

### Account

`registrar.demo@example.test`

### Navigation

1. Sign in to Staff Workspace.
2. Select **Admissions** in the Registrar navigation. This opens the Admissions Work Queue.
4. Use the workflow-state filter.
5. Locate one of the prepared applicants.
6. Open the applicant record.

Recommended records:

- `applicant.review.demo@example.test`
- `applicant.action-required.demo@example.test`
- `applicant.evaluation.demo@example.test`
- `applicant.approved.demo@example.test`

### What to show

Show:

- Current Workflow
- Applicant identity and requested program
- Admission category
- Requirement checklist
- Digital versus physical requirement method
- Evidence review state
- Responsible person
- Next action
- Available Registrar actions

Say:

> The Registrar reviews the source application and its requirement evidence. The Registrar may return incomplete evidence, record physical receipt, move an application to evaluation, approve it, and perform the controlled handover.

### Explain the handover

Say:

> Handover creates or connects the Student Profile and changes the person’s workspace boundary. It must not create a duplicate user or duplicate student profile. Enrollment remains a separate process.

### Dependency

The next academic and enrollment processes need:

- A Student Profile
- Program assignment
- Curriculum version
- Active student lifecycle status

---

## Stage 5 — Academic preparation

Remain signed in as Registrar.

### Navigation

Open **Academic Readiness**.

Then inspect these source records:

1. Academic Years
2. Terms
3. Programs
4. Course Catalog
5. Course Specifications
6. Curriculum Versions
7. Rooms
8. Faculty Qualifications

Say:

> Scheduling and enrollment cannot begin with only a student name. TALA first needs an academic year, term, program, curriculum, courses, faculty qualifications, and rooms. Academic Readiness presents those prerequisites in the order they are consumed.

### Current seeded academic scope

Explain:

- Three programs: DBM, DIT, and DTHM
- First-year and second-year cohorts
- 47 current students
- Six logical cohorts
- Nine faculty members
- Six rooms

Do not create another term or curriculum during the presentation.

---

## Stage 6 — Offerings, sections, and logical cohorts

### Navigation

Open Registrar resources:

1. **Source records** → **Term offerings**
2. **Source records** → **Sections and delivery groups**
3. **Source records** → **Schedule requirements**

### Explain the terminology

Say:

> A Term Offering is one curriculum subject made available during a particular term. A Section is the student grouping for that offered subject. A logical cohort such as DBM-1A connects the different subject sections taken by the same group of students.

Example:

- Section: DBM-1A-BME04
- Delivery group or logical cohort: DBM-1A
- Course: BME04

Continue with:

> There are 54 offerings and 54 subject sections because each offered subject requires its own section record. That does not mean there are 54 independent student cohorts. The client has six logical cohorts: DBM-1A, DBM-2A, DIT-1A, DIT-2A, DTHM-1A, and DTHM-2A.

### State explanation

- Planned: source section exists but is not yet enrollable.
- Open: section can accept placement when its timetable is published.

- Closed: section no longer accepts placement.
- Cancelled: section is no longer delivered.

The delivery groups were prepared before scheduling. Do not edit all 54 records during the presentation.

---

## Stage 7 — Timetable generation and publication

### Navigation

Open:

1. **Source records** → **Schedule requirements**
2. **Source records** → **Generated timetables**
3. Run #10
4. Candidate Assignments
5. **Source records** → **Published timetable**

### Current prepared state

Run #10 is now the published presentation timetable:

- 54 scheduling demands
- 54 candidate assignments
- 54 official meetings
- Zero hard conflicts in the accepted candidate
- Registrar publication completed

### What to say on Run #10

> The solver receives authoritative scheduling demands, faculty, rooms, availability, and time slots. Its output is only a candidate timetable. Laravel revalidates that candidate before the Registrar may publish it.

Then say:

> Publication is the institutional transition. Candidate assignments become official meetings, affected offerings become Scheduled, and their planned sections become Open for enrollment.

### Show the distinction

On **Generated timetables**:

- Show the run status.
- Open Candidate Assignments.
- Explain solver result, runtime, solution quality, and validation.

On **Published timetable**:

- Show official day and time.
- Show faculty.
- Show room or online modality.
- Show section and course.

### Why scheduling must precede placement

Say:

> Enrollment placement requires published meetings. TALA refuses to place a student into a candidate or unpublished timetable because that would expose an unofficial schedule.

### Do not do

- Do not click Generate Timetable.
- Do not retry Run #9.
- Do not create another run.
- Do not republish Run #10.

The correct result is already prepared.

---

## Stage 8 — Academic Head scheduling boundary

### Account

`academic-head.demo@example.test`

### Navigation

1. Academic Readiness
2. **Source records** → **Generated timetables**
3. Open Run #10

Say:

> The Academic Head can inspect academic readiness, faculty load, solution quality, and scheduling exceptions. Timetable generation and publication remain Registrar-owned actions.

Expected result:

- Run and evidence are visible.
- Generate, retry, correct, and publish actions are absent or unavailable.

After showing this, sign out.

---

## Stage 9 — Enrollment and regular cohort placement

### Registrar account

`registrar.demo@example.test`

### Navigation

1. Open **Students & Enrollment**. This opens Enrollments.
2. Search for the student number or account related to: `student.dit-1a.005@example.test`

3. Open the enrollment record.

### Current prepared state

This representative student is already:

- Regular
- Placed into DIT-1A
- Assigned eight active subjects
- Bound to eight published meetings
- Assessed
- Finance-cleared
- Officially enrolled

### What to show

Show:

- Current Status
- Next Step
- Responsible Office
- Student type
- Placement or cohort
- Course enrollments
- Enrollment gates
- Schedule bindings
- Official enrollment result

Say:

> For a regular student, the Registrar selects one compatible logical cohort. TALA places all eligible published subjects for that cohort together. The system checks lifecycle status, curriculum eligibility, prerequisites, capacity, schedule conflicts, unit load, finance, and required records.

### Explain what happened before the prepared state

1. Registrar started the continuing enrollment.
2. Student type was Regular.
3. DIT-1A was selected through Confirm Placement.
4. Eight course enrollments were created.
5. Eight schedule bindings were created.
6. Enrollment moved to Pending Payment.
7. Accounting generated and activated the assessment.
8. Required payment was confirmed.
9. Finance clearance passed.
10. Registrar recorded official enrollment.

Do not repeat these actions. Show the resulting records.

---

## Stage 10 — Irregular and cancelled enrollment comparisons

### Irregular student

Use or search:

`student.dbm-2a.001@example.test`

Say:

> An irregular student is not forced into a complete cohort. The Registrar selects eligible subjects individually because back subjects, prerequisites, or schedule conflicts may make the regular cohort unsuitable.

### Cancelled example

Use:

`student.dthm-1a.001@example.test`

Say:

> A cancelled enrollment cannot simply be restarted in place. Its reason and history remain visible, and a new valid enrollment process is required when policy allows it.

These cases prove that TALA does not treat every student identically.

---

## Stage 11 — Student sees official enrollment, schedule, and COR

### Account

`student.dit-1a.005@example.test`

### Navigation

Open Student Hub:

1. Dashboard
2. Enrollment
3. Class Schedule
4. COR
5. Finance
6. Academics

### Expected results

Enrollment:

- Officially Enrolled
- Eight active subjects

Class Schedule:

- Eight official published meetings
- Course and section
- Day and time
- Faculty
- Room or modality

COR:

- Current term
- Student identity
- Official subjects
- Sections
- Units
- Delivery mode
- Official enrollment state

Finance:

- Active assessment
- ₱2,000 confirmed payment
- Finance-clearance result
- Remaining account information when applicable

### What to say

> The Student Hub does not maintain a second copy of institutional information. It projects the same approved records used by the Registrar, Accounting, and the published timetable.

Then:

> The COR and Class Schedule become available only after official enrollment and active published schedule bindings exist.

Do not edit anything in Student Hub. It is an authorized read-only student projection.

---

## Stage 12 — Accounting assessment, payment, and ledger

### Account

`accounting.demo@example.test`

### Navigation

Open Accounting:

1. Student Accounts
2. Payments and OR Reconciliation
3. Account Activity
4. Payment Attempts
5. Payment Exceptions
6. Reports when applicable

### Representative official account

Search for the student linked to:

`student.dit-1a.005@example.test`

Show:

- Active assessment
- Assessment lines
- Required downpayment: ₱2,000
- Confirmed bank-transfer payment
- Payment reference
- Payment ledger entry
- Finance-cleared result

Say:

> Accounting activates the assessment, records verified payment evidence, posts the ledger entry, and evaluates finance clearance. Payment does not automatically bypass academic, capacity, or Registrar gates.

### Compare prepared finance cases

#### Amount due and failed checkout

`student.dit-1a.001@example.test`

Show:

- Current amount due
- Failed Payment Attempt
- Next action

#### Partial payment

`student.dit-1a.002@example.test`

Show:

- Confirmed partial payment
- Remaining balance
- Pending Payment Attempt

#### Finance-cleared but academic blocker remains

`student.dit-2a.001@example.test`

Say:

> Finance clearance means the financial requirement passed. It does not remove an academic prerequisite blocker.

---

## Stage 13 — PayMongo integration

This is the external-payment part of the core flow.

### Student account

`student.dit-1a.001@example.test`

### Navigation

Student Hub → Finance.

The action is:

Pay Current Due

### Explain the real flow

1. Student selects Pay Current Due.
2. TALA creates a local Payment Attempt.
3. TALA requests a PayMongo test Checkout Session.
4. The student is redirected to PayMongo’s hosted checkout.
5. The student completes or cancels the test checkout.
6. Returning to TALA is informational only.
7. PayMongo sends a signed webhook.
8. TALA verifies the signature.
9. TALA stores the provider event.
10. TALA processes the event idempotently.
11. Valid payment evidence becomes available to Accounting.
12. Accounting reconciles exceptions when required.
13. Payment and ledger records are posted only after verified evidence.

14. Finance clearance is recomputed.

### Required webhook

`https://edgar-nonhabitable-inconsiderately.ngrok-free.dev/api/webhooks/paymongo`

Enabled events:

- payment.paid
- checkout_session.payment.paid
- payment.failed

### What to say

> The checkout return does not prove payment. The signed webhook is the authoritative provider evidence. Duplicate webhook delivery must not create a second payment or ledger entry.

### If external payment is not currently authorized

Do not select Pay Current Due.

Instead show:

- Seeded failed Payment Attempt
- Seeded pending Payment Attempt
- Payment Exceptions
- Operational Events
- Integration Status

Clearly say:

> These records demonstrate the local exception and reconciliation workflow. A new external checkout requires the active ngrok URL and PayMongo test configuration.

---

## Stage 14 — Faculty schedule and grade entry

### Account

`faculty.demo@example.test`

### Navigation

1. **My Schedule**
2. **Grade Rosters**

### My Schedule

Say:

> Faculty sees only meetings assigned to their account from the published timetable.

Show:

- Course
- Section
- Day and time
- Room
- Delivery mode

### Grade Rosters

Show an editable or prepared roster state.

Explain the grade states:

- Draft: Faculty may encode grades.
- Submitted: waiting for staff review.
- Returned: Faculty must correct it.
- Released: official and student-visible.

Say:

> Faculty owns grade encoding and submission. Faculty cannot directly release a grade as an official student result.

Do not alter the prepared roster unless your presentation specifically requires a live grade-entry action.

---

## Stage 15 — Registrar grade release and student result

Return to:

`registrar.demo@example.test`

### Navigation

1. **Grades & Completion**
2. **Grade Rosters**
3. Open a Submitted or Released roster

Say:

> The Registrar reviews the submitted roster and may return it for correction or release it according to authority. Only Released results appear in Student Hub.

Then sign in to Student Hub as:

`student.demo@example.test`

Open Grades.

Expected result:

- Released grade is visible.
- Draft, Returned, or Submitted-only results are absent.

---

## Stage 16 — Academic status, lifecycle, and completion

### General student

`student.demo@example.test`

Open:

1. Academics
2. Academic Status
3. Holds & Blockers
4. Grades

Say:

> Academic standing is an official recorded status. Computed progression guidance may support review, but it does not silently replace Registrar authority.

### Completion case

Use:

`student.completion.demo@example.test`

Open Completion Review.

Say:

> This inactive historical case demonstrates completion eligibility and outstanding conditions without pretending there is an active third-year cohort.

### Graduation case

Use:

`student.graduation.demo@example.test`

Say:

> Graduation review uses recorded academic evidence and blockers. TALA supports the review, while the institution retains the final graduation authority.

---

## Stage 17 — System administration, integrations, and audit

### Account

`system-admin.demo@example.test`

### Navigation

Open:

1. **Users & Access**
2. **System Health**
3. **Governance & Audit**

### Integration Status

Show three integrations:

- Email
- Payments (PayMongo)
- Scheduler (CP-SAT solver)

Say:

> Local configuration and operational evidence are intentionally separate. A configured integration is not automatically described as successful. TALA reports successful evidence only after observing a processed event.

Explain ownership:

- Email configuration: System Administrator
- PayMongo configuration: System Administrator
- Payment exception decision: Accounting
- Solver connectivity: System Administrator
- Timetable run and publication: Registrar

Say:

> Secrets are stored in environment configuration and are never displayed on this page.

### Operational Events

Show:

- Solver events
- PayMongo events
- Email events when present
- Processed, failed, or review-required state
- Timestamp
- Safe diagnostics

### Authorization boundary

Say:

> System Super Admin may inspect health, users, settings, audit, and integration evidence. System administration does not replace the Registrar’s academic decisions or Accounting’s payment decisions.

---

## Stage 18 — Reports and official outputs

While using the appropriate role, show:

### Registrar

- Enrollment Master List
- Section Capacity Summary
- Lifecycle records
- Completion reviews

### Accounting

- Student Ledger Statement
- Daily collections
- Reconciliation exceptions
- Term fee summary

### Academic Head

- Faculty load
- Scheduling exceptions
- Grade review reports
- Progression exceptions

### System Super Admin

- User and Role Report
- Activity Log
- Generated Output Access
- Report Export Audit
- Integration Event Log
- PayMongo Webhook Event Log

Say:

> Reports are filtered operational tables with controlled CSV exports. Official student outputs such as COR, SOA, billing slip, payment acknowledgement, and schedules are source-derived views. Access and export actions are auditable.

# Closing statement

Say:

> The complete TALA flow begins with Applicant Intake and requirement review. The Registrar performs the controlled Student Profile handover. Academic records, offerings, faculty, rooms, and scheduling demands produce a validated and published timetable. The Registrar places the student into published sections. Accounting generates the assessment, verifies payment evidence, posts the ledger, and clears the finance gate. The Registrar then records official enrollment. The student receives a source-derived COR and Class Schedule. Faculty records grades, authorized staff release official results, and the system continues through academic status, lifecycle, completion, reports, audit, and integration monitoring.

# Presentation recovery guide

## Login says “These credentials do not match our records”

1. Check the correct workspace URL.
2. Use the exact lowercase email.
3. Use password `password`.
4. Sign out of the existing account.
5. Close that browser profile and reopen it.
6. Do not use an Applicant account on Staff login.
7. Do not use a Student account on Applicant login.

## A menu is missing

This usually means the wrong role is signed in.

Examples:

- Accounting does not own Admissions Work Queue.
- Faculty does not own Enrollment placement.
- Academic Head does not publish timetables.
- System Super Admin does not perform routine academic decisions.

## A table looks empty

1. Clear all filters.
2. Clear search.
3. Select the Second Semester AY 2025–2026 term when available.
4. Reload the page.
5. Confirm the correct account.

## Run #10 is not Published

Stop the scheduling part. Do not generate a replacement timetable during the presentation. The expected prepared state is:

- Run #10 Published
- 54 candidate assignments
- 54 published meetings

## COR or Class Schedule is empty

Use:

`student.dit-1a.005@example.test`

Confirm:

- Enrollment is Officially Enrolled.
- Eight course enrollments exist.
- Eight active schedule bindings exist.
- Run #10 remains Published.

## PayMongo does not return a payment

Do not manually claim success.

Show:

1. Payment Attempts
2. Payment Exceptions
3. Operational Events
4. Integration Status

Explain that a redirect is not proof and the signed webhook is required.

## composer dev fails because of Pail

On Windows, Pail requires the unavailable `pcntl` extension. Start the essential processes in separate terminals:

```bash
php artisan serve
```

```bash
npm run dev
```

Run the queue listener only when the integration demonstration requires it:

```bash
php artisan queue:listen --queue=scheduling,default --timeout=360
```

Do not start multiple servers on different ports during the presentation.
