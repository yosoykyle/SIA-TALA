## 4. Academic Setup

---

### 4.1. Academic Calendar and Term Rules

Supported terms:

1. First Semester
2. Second Semester
3. Summer / Special Term, when offered.

Summer rules:

1. Staff may create a Summer / Special Term in Draft and estimate demand before Second Semester grades are finalized.
2. Completion/catch-up eligibility that depends on failed or deficient subjects must use finalized Second Semester grades.
3. Final Special Offering approval, Master Schedule publication, and Summer enrollment open only after the required academic results and institutional approvals are available.

Default scheduling grid:

1. Monday to Saturday.
2. 7:00 AM to 8:00 PM.
3. 30-minute base blocks.
4. Sunday blocked by default.
5. Holidays and no-class days block scheduling.
6. Authorized staff may configure operating days, hours, institutional break blocks, and time-block granularity before scheduling.

Curriculum and Course Catalog preparation may occur independently of a Term. Term setup must exist before Term Offerings, scheduling, enrollment, assessment due dates, or grade windows are activated.

Institution-wide calendar rules:

1. Calendar periods, terms, and date rules are strictly institution-wide.
2. V1 supports one College Academic Calendar. Program-specific calendar overrides and separate graduate-school calendars are deferred unless Servitech formally adopts materially different academic calendars.
3. Program length does not create a separate Academic Calendar. A three-year, four-year, or other-length program uses the same term calendar unless it uses a materially different term system.
4. Calendar windows may have a simple scope, such as all students, continuing students, first-year students, graduating-review students, or a named term process.

Required calendar windows:

1. Term Planning.
2. Regular Offering Preparation.
3. Special Offering Request.
4. Special Offering Approval.
5. Scheduling.
6. Schedule Review and Publication.
7. Enrollment.
8. Add / Drop / Adjustment.
9. Classes.
10. Examination Periods.
11. Grade Encoding.
12. Late Grade Encoding Authorization.
13. Grade Finalization.
14. INC Completion / Removal.

Daily scheduling blocks:

1. Institutional break blocks, such as a lunch break or daily common break, are configured on the Academic Calendar or Scheduling Availability setup.
2. A break block removes the affected time blocks from regular class scheduling for the configured days, dates, or term scope.
3. Break blocks may apply institution-wide or to a simple scope, such as all regular classes, selected rooms, selected faculty, or a specific term process.
4. Authorized staff record make-up class blocks when an approved institutional action uses a break period or normally blocked period for a specific academic need.

Calendar effects:

1. Scheduling uses the Academic Calendar to generate valid time blocks and exclude holidays, no-class dates, and examination periods when institutional policy suspends regular classes.
2. Enrollment uses the term calendar to determine enrollment windows, late-enrollment handling, capacity deadlines, payment due dates, and official enrollment cutoffs.
3. Assessment uses the active term and enrollment timing to apply the exact Program-and-Term downpayment rule, late enrollment fees, installment schedules, and term-specific fee configuration.
4. Grade encoding uses the academic calendar to open and close faculty grade-entry windows.
5. Student Hub visibility uses the active term to decide current schedule, current COR, current SOA, released grades, and active holds.
6. Reports and exports use academic year and term as primary filters for operational records.
7. Add / Drop / Adjustment dates control when staff may change subject enrollments before or during the early term.
8. INC Completion / Removal windows control when an incomplete grade outcome may be resolved according to institutional policy.
9. Scoped Calendar Windows control date access. Graduation, irregular standing, completion, and deficiency status come from student academic source records.
10. Institutional break blocks become unavailable scheduling time blocks for the configured scope.

Academic Calendar date exceptions:

1. Holiday
2. No-class day
3. Make-up class date or block
4. Institutional break block

Scheduling Availability records:

1. Room closure or unavailability.
2. Faculty unavailable period.
3. Resource-specific blocked time.
4. Daily or term-specific break block.

Institutional class suspensions or temporary closures are decided outside TALA. Authorized staff record the resulting no-class or make-up date only when it changes the official Academic Calendar.

Exam and deadline rules:

1. Examination periods block regular class scheduling only when the configured institutional policy suspends regular classes during that period.
2. Payment due dates, adjustment periods, grade encoding windows, late grade authorization windows, and INC completion/removal windows are configured as explicit, absolute dates on the Term calendar setup.
3. Before Master Schedule publication, TALA must validate that planned meetings can satisfy the instructional/contact hours required by the applicable CHED policies, program standards, and institutional rules.
4. Break blocks are considered during contact-hour validation so scheduled meetings still satisfy required hours using allowed class time.

---

### 4.2. Delivery Modality

Supported delivery modality values:

1. Online
2. Face-to-Face
3. Modular

Modality field:

`delivery_modality`

Allowed enum values:

1. `ONLINE`
2. `FACE_TO_FACE`
3. `MODULAR`

Rules:

1. Modality must be modeled separately from payment status.
2. Payment status uses finance values. Delivery modality remains a separate academic scheduling value.
3. Online classes do not require physical room assignment.
4. Face-to-Face classes require physical room assignment.
5. Modular classes may require staff handling. Modular packet distribution is handled through classroom or office procedures.
6. Modality may affect fee computation only if Accounting configures modality-based fee rules.

---

### 4.3. Course Catalog and Course Specification Revisions

Course Catalog identifies each course. A Course Specification Revision owns the effective academic and default delivery definition used by curricula, offerings, enrollment, scheduling, COR, grades, and history.

Course identity fields:

1. Subject Code.
2. Active / Inactive Status.

Course Specification Revision fields:

1. Revision Identifier.
2. Subject Title.
3. Description.
4. Credit Units.
5. Course Components.
6. Derived Total Contact Hours.
7. Grading Profile.
8. Prerequisites.
9. Corequisites.
10. Equivalent Subjects, when approved.
11. Allowed Delivery Modalities.
12. Effective Term or Effective Curriculum Version.
13. Revision Notes.
14. Status.

Course Component fields:

1. Component Type: Lecture or Laboratory for v1.
2. Weekly Contact Hours.
3. Default Required Room Type.
4. Default Required Room Features.
5. Allowed Delivery Modalities, when narrower than the course default.
6. Consecutive Block Required, when applicable.
7. Notes.

Course Component rules:

1. One Course Specification Revision may have only a Lecture component, only a Laboratory component, or both.
2. Lecture and Laboratory components are scheduleable parts of the same course under one subject code, Curriculum Entry, enrollment, and released grade.
3. Separate course identities are used only when the institution assigns separate subject codes or requires separate released grades.
4. Derived Total Contact Hours is computed from the Course Components.
5. The course-level Credit Units, prerequisite/corequisite rules, grading profile, and equivalency rules remain owned by the Course Specification Revision.
6. V1 limits component types to Lecture and Laboratory. Discussion, seminar, recitation, tutorial, studio, or similar component types are deferred unless Servitech formally requires them as distinct scheduled components.
7. Different qualified faculty may teach different components of the same course by default.
8. The Course Specification Revision may require the same faculty member across linked Lecture and Laboratory components only when institutional policy or course design requires it.

Prerequisites are stored as a structured Prerequisite Rule Set, not as free text used directly during enrollment:

1. A rule set contains one or more requirement groups.
2. Requirement groups are combined with `AND`.
3. Approved alternatives inside one group are combined with `OR`.
4. Each alternative references a course identity, its approved equivalents, the required completion result, an optional institutional minimum grade, and accepted credit sources.
5. Corequisites are separate rules satisfied by prior completion or concurrent enrollment in the required course or approved equivalent.
6. Example: `(IT101 OR CS101) AND MATH101` is stored as two groups, not as one text expression.

Revision states:

1. Draft.
2. Active.
3. Retired.

Rules:

1. Subject Code is unique at the course-identity level; one course may have multiple effective Course Specification revisions.
2. A material change to units, Course Components, prerequisites, corequisites, grading profile, or default delivery requirements creates a new revision. Active revisions are not edited in place.
3. Prerequisites and corequisites reference existing course identities or approved equivalency rules.
4. Free-text prerequisite source values are retained only as import evidence; enrollment evaluates the confirmed structured rule set.
5. A released passing Grade Outcome, approved internal program-shift credit, mapped external transfer credit, or approved equivalent may satisfy a prerequisite according to policy.
6. Failed, incomplete, pending, withdrawn, dropped, blank, or merely current enrollment does not satisfy a prerequisite; concurrent enrollment satisfies only a corequisite.
7. Circular prerequisites are blocked.
8. Units and Course Component contact hours must satisfy applicable CHED program standards and institutional rules.
9. Contact hours and required delivery components must be complete before a referenced Curriculum Version becomes scheduling-ready.
10. Active revisions referenced by approved curricula, historical schedules, enrollments, CORs, or grades are immutable.
11. Retiring a revision prevents new curriculum use but preserves every historical reference.

---

### 4.4. Course Equivalency & Batch Credit Interaction

Transfer and program-shift crediting use a low-friction **Batch Credit interaction**. The Registrar opens the student's target curriculum and rapidly checks off credited subjects in one pass based on the approved paper evaluation. Approved credited subjects satisfy prerequisite checks without requiring a global external-course mapping engine.

**Differentiated Credit Model:**
Credited subjects are stored under two distinct rules in the student's academic history:

1. **External Transfer Credits (Transferees):** Mapped with a grade of `TC` (Transfer Credit). These satisfy prerequisites and degree checklist requirements, but **are strictly excluded** from the student's cumulative General Weighted Average (GWA) calculations.
2. **Internal Program Shift Credits (Internal Shifters):** Mapped with their original numeric grades (1.00 to 5.00 scale) earned at the institution. These satisfy degree requirements and **are included** in the GWA calculations.

The Batch Credit interaction must provide a controlled selection for the Registrar to designate each credited subject as either External (TC) or Internal (Numeric Grade), preventing GWA inflation during graduation clearance and honors audits.

---

### 4.5. Curriculum Creation and Management

The client-provided curriculum is a completed table by program, year level, and term. It gives the academic subject plan, not operational scheduling details.

Client curriculum source columns:

1. Academic grouping or term block, such as First Year / First Semester.
2. Subject Code.
3. Subject Title.
4. Total Units, shown by the client as the subtotal for an academic grouping or term block.
5. Units, representing the individual course's credit units.
6. Prerequisite.

TALA adapts this curriculum through one curriculum encoding table. The table resolves each source row into a Course Specification Revision and a Curriculum Entry underneath one workflow.

TALA curriculum encoding table:

| Column Group | Columns |
| --- | --- |
| Client curriculum fields | Academic grouping / term block, Subject Code, Subject Title, Total Units subtotal, Course Units, Prerequisite |
| Course Specification completion | Course Components, Corequisites if applicable, Grading Profile, Allowed Modalities |
| Curriculum placement | Year Level, Term, Sequence, Required / Elective Grouping |

Rules:

1. Staff work in one curriculum encoding table even though TALA stores Course Specification revisions and Curriculum Entries as separate authoritative records.
2. TALA preserves the source row and identifies which values came from the client document versus staff completion.
3. The TALA Curriculum CSV Import Template preserves the client source columns as the required curriculum input. Course Components, room requirements, modality, and other enrichment columns absent from the client source remain optional and may be completed in the review table.
4. An imported row first matches Subject Code to a course identity. If units, prerequisites, or another material specification differ from the active revision, TALA proposes a Draft Course Specification Revision and never overwrites the active revision.
5. Staff may complete missing Course Specification fields in the same curriculum table during import cleanup or review.
6. A draft curriculum may be saved with incomplete specification fields, but it cannot become scheduling-ready until every automatically scheduled entry references a complete Course Specification Revision.
7. Course Units are stored on the Course Specification Revision. Total Units is computed by summing the Curriculum Entries in the academic grouping or term block and is not stored as another per-course value.
8. When an import supplies a Total Units subtotal, TALA compares it with the computed subtotal and reports a mismatch for staff correction without duplicating the value across course rows.
9. Prerequisite import text is parsed only into a proposed structured rule for staff confirmation: a single code proposes one requirement, comma-separated codes propose `AND`, and the word `or` proposes alternatives within one group.
10. Ambiguous separators such as `/`, unknown course codes, circular rules, or unclear minimum-grade text block curriculum approval until staff resolves them; TALA must not guess their meaning.
11. Existing curriculum documents are copied into the current TALA template or encoded through the manual table.

Course Specification revisions are the source of truth for what a course is. Curriculum Versions are the source of truth for which Course Specification revision belongs to a program, year level, term, sequence, and graduation path.

Curriculum Version structure rules:

1. A Curriculum Version may contain any number of year levels and terms required by the approved program.
2. TALA uses the configured Curriculum Version length for each college program.
3. Graduation review status comes from Curriculum Version completion and source records, not year-level labels.
4. Graduation and completion checks use the student's assigned Curriculum Version and source records, not a hardcoded year-level rule.
5. Year Level is a curriculum placement label and reporting filter. It is not the source of graduation eligibility.

Curriculum information is shared across modules as follows:

1. Term Offerings are generated from Curriculum Entries in the active approved Curriculum Version for a program, year level, and term.
2. Scheduling Demand combines the referenced Course Specification Revision and Course Component with section delivery groups, actual Term Offering modality and approved overrides, faculty eligibility, rooms, calendar, and term setup.
3. Enrollment gates use the student's Curriculum Version and referenced Course Specification prerequisite rules to determine eligible subjects, progression, irregular selection, and graduation path.
4. Assessment uses enrolled Course Specification units and laboratory component indicators together with the actual Term Offering modality and term fee rules.
5. COR and SOA derive from official enrollment, referenced Course Specification revisions, published schedule, assessment, and ledger records; they do not invent or copy mutable course values.
6. Grades and graduation eligibility use the student's assigned Curriculum Version and its referenced Course Specification revisions to determine completed, failed, deficient, credited, and remaining requirements.

Rules:

1. Curriculum encoding starts from the headers that exist in the client-provided curriculum table.
2. Weekly contact hours are derived from authoritative Course Components; they are not independently owned by a Curriculum Entry.
3. Default room needs belong to the Course Specification Revision's Course Components. Actual modality, room, faculty, and approved delivery overrides belong to the Term Offering or Scheduling Demand.
4. Missing Course Specification completion fields may block scheduling readiness while still allowing staff to save a draft curriculum.
5. TALA preserves the original curriculum-table meaning while avoiding duplicate ownership of units, prerequisites, contact hours, and room defaults.

**Flattened Workflow:**
Academic Head curriculum approval happens through institutional review outside TALA. After approval, the Registrar or Admin records the approved curriculum in TALA and uses a direct "Set Active Curriculum" function to activate it.

Curriculum version states:

1. `Draft`: being encoded or imported.
2. `Recorded Approved`: approved outside TALA and recorded in TALA.
3. `Active`: default curriculum for new handovers in that program.
4. `Superseded`: replaced by a newer active curriculum.
5. `Archived`: retained for history and not selectable for new handovers.

Rules:

1. V1 does not route Academic Head curriculum approval inside TALA.
2. TALA records the approved curriculum result and activation action.
3. Only one curriculum version per program may be `Active` for new handovers at a time.
4. Activating a new curriculum version supersedes the previous active version for future handovers only.

**Curriculum Locking & Cohort Rules:**

1. **Entrance Lock:** When an applicant undergoes official handover, their student master profile is permanently bound to the `curriculum_version_id` active at their time of entry (e.g., BSIT v2024).
2. **Supersession Scope:** Setting a new curriculum version to "Active" only changes the default version mapped to newly onboarding applicant intakes.
3. **Immutability:** Continuing students remain bound to their entry curriculum version throughout their degree track. The system prevents dynamic global curriculum updates from altering a continuing student's mapped version.
4. **Amendment Gate:** A student's curriculum assignment changes only from a recorded approved **Program Shift Credit Evaluation** result effective in a future term. This preserves historical course-checklist, prerequisite, and graduation audits without requiring TALA to implement the institution's approval routing.

The system enforces these curriculum rules during enrollment and scheduling. TALA records the approved curriculum result without routing the paper approval itself.

---

### 4.6. Academic-Setup Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Academic year and Term identity | Record Form with controlled term type, status, and absolute start/end dates |
| Academic Calendar windows | Calendar / Date-Range Input plus an Editable Table for named windows, optional scope, and exact open/close dates |
| Holidays, no-class dates, make-up dates, and Institutional Break Blocks | Calendar / Date-Range Input; room and faculty unavailability remain separate source records |
| Course identity | Record Form for Subject Code and active status |
| Course Specification Revision | Record Form for one revision, with numeric fields and Selection Lists for grading profile and modalities |
| Course Components | Inline Editable Table inside the Course Specification Revision form; one row per Lecture or Laboratory component with contact hours, room defaults, modality allowance, consecutive-block rule, and optional same-faculty requirement |
| Prerequisite Rule Set | Nested Editable Table: requirement-group rows, with course alternatives selected from existing course identities; AND/OR is explicit and not typed as runtime free text |
| Corequisites and equivalents | Separate Editable Tables using course Selection Lists |
| Curriculum Version | Record Form for version identity, program, effectivity, state, and recorded approval reference |
| Curriculum course list | One Editable Table grouped by the curriculum's year-level and term labels; each row references a Course Specification Revision and stores Curriculum Entry placement only |
| Standalone Course Specification source file | Downloadable versioned Course Specification CSV template, template-conforming upload, row preview, validation results, and explicit Draft creation; manual Record Form entry remains available |
| Curriculum source file | Downloadable versioned Curriculum CSV template, template-conforming upload, row preview, validation results, and explicit Draft creation; manual table entry remains available |
| Client Total Units | Read-only computed subtotal per term grouping; an imported subtotal appears only as a comparison value and mismatch warning |
| Batch credit evaluation | Curriculum-checklist table with one row per target Curriculum Entry, credit/not-credit selection, source type, and numeric grade only for internal credit |
| Activate or supersede curriculum | Read-only impact preview followed by explicit confirmation; it is not a direct editable status field |

Prerequisite import cleanup shows the original source text beside the proposed structured rule until staff confirms it. Ambiguous rows remain visibly blocked from `Recorded Approved` and `Active` states.

CSV template flow:

1. Staff downloads the current TALA CSV Import Template for Course Specifications or Curriculum Versions.
2. Staff enters or copies source rows without changing the required headers. Each populated row retains the template's required `template_version` value.
3. TALA validates file type, encoding, template type/version, exact required headers, and duplicate headers before reading domain rows.
4. TALA validates required values, data types, controlled values, referenced programs/terms/courses, duplicate rows, units and subtotals, prerequisite structure and cycles, and conflicts with Active revisions.
5. TALA displays the full batch preview with row-numbered errors and warnings; errors block the whole batch and warnings require acknowledgement.
6. Staff corrects the CSV in the current TALA template and reuploads it, or cancels the batch.
7. Posting a valid preview creates or updates Draft records only. It never overwrites an Active Course Specification Revision and never activates a Curriculum Version.
8. Staff completes optional enrichment in the existing review table, records the institutional approval result, and activates the Curriculum Version through the separate impact-confirmed action.

---
