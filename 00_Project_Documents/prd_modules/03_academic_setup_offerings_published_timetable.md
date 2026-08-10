# PRD 03 — Academic Setup, Offerings, and Published Timetable
## Authority and Standalone Status

**Status:** Standalone and ready for vertical-slice planning.

This PRD is the complete authority for academic setup, curricula, Terms, cohorts, Class Offerings, teaching-resource readiness, whole-term CP-SAT generation, candidate review, publication, and revision. It is understandable without legacy scheduling PRDs, solver source, tests, or the formulation document. The current implementation remains bounded evidence until a later PRD 03 vertical slice proves conformance.
## 1. Purpose and Successful Outcome

Clinic 3 defines one complete institutional journey:

`Approved academic authority → active curriculum → active term calendar → confirmed cohorts and classes → teaching-resource readiness → whole-term CP-SAT generation → human review → published timetable → controlled revisions`

The successful outcome is one immutable published timetable version that every authorized role reads through a purpose-specific projection. Candidate schedules are proposals. Only a Registrar-published version is official.

Clinic 3 does not own admissions, student-level study planning, enrollment, seat reservation, finance, COR, grades, internship operations, or Student activation.

## 2. Evidence and Institutional Boundary

This contract is governed by:

- [CHED CMO No. 40, s. 2008 — MORPHE](https://ched.gov.ph/wp-content/uploads/2017/10/CMO-No.40-s2008.pdf), applicable program Policies, Standards, and Guidelines, and the institution's approved academic decisions.
- [CHED Regional Office I collegiate-calendar guidance](https://region1.ched.gov.ph/wp-content/uploads/2024/05/CRMO-NO.-03-S.-2024-GUIDELINES-FOR-COLLEGIATE-AND-GRADUATE-SCHOOL-CALENDARS-AY-2024-2025.pdf), which requires particular schedules and the required class hours/days for summer, trimestral, or quarterly terms but does not prescribe a separate SIS workflow or Servitech calendar.
- [TESDA assessment and certification](https://tesda.gov.ph/About/TESDA/25) and its [official assessment FAQ](https://tesda.gov.ph/About/Tesda/127), which keep competency assessment, accredited assessors, and NC/COC certification under TESDA's external authority.
- [CHED CMO No. 62, s. 2017](https://ched.gov.ph/wp-content/uploads/2018/03/CMO-62-BS-Hospitality-Tourism-Management.pdf) for the distinction between supervised workplace practicum and recurring classroom timetable hours.
- [PeopleSoft Student Records](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/student-records-overview.html), [PeopleSoft combined sections](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/creating-combined-sections.html), [PeopleSoft examination scheduling](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/scheduling-examinations.html), and [UniTime course timetabling](https://help.unitime.org/course-timetabling) as mature-system benchmarks only. The enterprise examination feature is evidence that class/facility scheduling is a distinct optional subsystem, not authority to add it to TALA.
- [OR-Tools CP-SAT statuses](https://developers.google.com/optimization/cp/cp_solver) and [OR-Tools infeasibility guidance](https://github.com/google/or-tools/blob/stable/ortools/sat/docs/troubleshooting.md) for solver-result meaning and bounded diagnostic evidence.

The supplied 2019 handbook concerns Servitech TESDA operations. It is contextual evidence for terminology and observed procedure only; it is not authority for the college grading, enrollment, scheduling, completion, or disciplinary policies in this PRD. Existing spreadsheets, forms, database structures, services, UI, and outside-institution practices likewise become enforceable only when the stated authority hierarchy supports them.

| Institutional responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Recognize a program or approve a curriculum | Regulator and authorized institution outside TALA | Record the authority, source, effective dates, and approved result |
| Approve the institutional academic calendar | Academic Head through the institution's process outside TALA | Registrar records, activates, and operates the approved package |
| Schedule and communicate exact class examination arrangements | Faculty through the approved teaching process | Project only the institution-approved Examination Period; no class-level examination schedule |
| Assess and certify an external TESDA-linked competency | TESDA/accredited assessor | Record only an authority-backed curriculum requirement; Clinic 5 records the verified Student result |
| Decide staffing eligibility, load, exceptional class authority, timetable sign-off, or graduating overload | Authorized institution outside TALA | Record only the approved result, authority, evidence reference, and effective dates needed by the owning clinic |
| Build cohorts, classes, and scheduling inputs | Registrar | Authoritative source records and readiness |
| Generate a timetable candidate | CP-SAT service | Integration output that remains untrusted until Laravel validation and human review |
| Review and publish the official timetable | Registrar | Controlled publication record and immutable version history |
| Manage internship placement, companies, supervisors, logbooks, or workplace attendance | External academic process | No Clinic 3 workflow |

This is a `Decision then record` product. It does not recreate regulator, committee, HR, workload-approval, practicum, or academic-sign-off workflows.

## 3. Authoritative Conceptual Records

These contracts define responsibilities before physical schema design. They are not approved table names or migrations:

| Name or family | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| ProgramAuthority, Course, CourseRevision, CourseRequisite, CourseEquivalency | Define effective academic catalog facts and relationships | Registrar records approved academic authority | Persisted authoritative records and immutable revisions | Curriculum, scheduling, PRDs 04–05 | Remain distinct where effective history or graph validation requires it; no generic rule engine |
| CourseAcademicClassification | Identify ordinary, PE, or NSTP-equivalent average treatment | Registrar records approved classification | Required institutional operational data with effective history | PRD 05 averages | Owned with the Course revision; not a standalone policy module |
| CurriculumVersion | Freeze one program curriculum and authority | Registrar | Immutable version | Class demand, registration, curriculum evaluation | Remains distinct for historical reproducibility |
| CurriculumEntry, WeeklyMeetingRequirement, ExternalCompetencyRequirement | Define owned curriculum/course-position details | CurriculumVersion or CourseRevision | Documentation concept that does not require a separate implementation object | Scheduling, registration, records | May be implemented as owned rows/value objects; no independent lifecycle or navigation destination |
| TermCalendarPackage | Define one Term's dates, windows, teaching grid, exceptions, and Examination Period | Registrar | Immutable activated version | Scheduling, registration, grades, role projections | OperationalWindow, WeeklyTeachingGrid, and DatedException remain owned parts, not top-level modules |
| TermCohort and ClassOffering | Define term demand and the actual class to schedule/enroll | Registrar | Persisted authoritative records | Solver, PRDs 04–05 | ClassOfferingCohort is an owned association rather than an independent aggregate |
| FacultyTeachingEligibility, FacultyTermCapacity, FacultyAvailabilityDeclaration | Supply current bounded teaching-resource facts | Registrar records eligibility/capacity; Faculty declares availability | Persisted authoritative records or immutable declarations | Scheduling readiness and solver input | Remain separate facts because their owners and correction paths differ; no HR/workload system |
| Room, ResourceUnavailability, SchedulingCommitment | Supply room/resource facts and exact authorized commitments | Registrar | Persisted authoritative records or immutable events | Scheduling readiness and solver input | No generic resource or override engine |
| ScheduleGenerationRun | Own one immutable source snapshot, request, result, evidence, and run status | TALA/Registrar | Persisted authoritative record plus immutable result | Candidate review and audit | `SolverSnapshot` is the run's immutable input, not another aggregate |
| CandidateTimetable | Preserve a complete candidate and quality evidence | TALA result; Registrar reviews | Immutable version | Candidate review and publication | CandidateMeetingCorrection is successor history on the candidate, not a standalone workflow |
| PublishedTimetableVersion | Freeze the official meeting set and publication/revision evidence | Registrar | Immutable version and official output source | Faculty, Student, PRDs 04–05 | PublishedMeeting is owned content; TimetableRevision is successor metadata on a new published version |
| PublishedClassAvailabilityProjection, UnmetClassDemandProjection, Examination Period and readiness results | Communicate source-owned availability, demand, dates, and blockers | Owning PRD derives from authoritative facts | Derived projection/calculation | PRD 04 and role UIs | No stored copy, generic readiness engine, or event calendar |

No public HTTP API, generic policy DSL, universal override record, generic state-machine builder, or configurable constraint engine is introduced.

## 4. End-to-End Narrative

1. Registrar records the regulator and institutional authority for each recognized program.
2. Registrar records or imports a Draft curriculum from an externally approved source, including any explicitly authorized external-competency requirements, resolves every blocking finding, records the authority, and activates the immutable version.
3. Registrar explicitly creates a Draft Term Calendar Package, records its approved dates and teaching grid, resolves failed readiness checks, and activates it.
4. TALA projects continuing-student demand, Clinic 2 Ready-for-Enrollment counts, and bounded Clinic 4 unmet-demand evidence. Registrar confirms or splits standard-curriculum cohorts. TALA may generate Draft Class Offerings from those authoritative inputs; Registrar confirms, shares, adds, or cancels them.
5. Registrar records Faculty eligibility and term capacity decisions, rooms, genuine hard unavailability, and any authorized exact commitments.
6. Readiness derives whether every confirmed Class Offering that needs recurring meetings has complete, consistent scheduling inputs.
7. One immutable whole-term snapshot is submitted to CP-SAT. Only one run may be active for the term.
8. Laravel independently revalidates every returned assignment and records the result with factual quality and diagnostic evidence.
9. Registrar reviews the complete candidate and may make bounded, fully revalidated corrections. Hard rules cannot be waived.
10. Any institutional sign-off occurs outside TALA. Registrar records its authority/reference and publishes the first immutable timetable version.
11. Faculty sees assigned official meetings. Students see only the meetings for their Clinic 4 placements after official enrollment.
12. A post-publication change creates a Draft revision, revalidates the entire timetable, publishes a new immutable version, and preserves the superseded version and exact impact.

### 4.1 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| Draft course/curriculum authority | Record or import approved academic source | Registrar | Recorded regulator/institutional authority | Bounded source, complete revisions, no inconsistent/circular requisites | Draft records and blocking findings | Activation creates an immutable version; later correction requires a new revision/version | Academic Head sees readiness; other clinics consume only active authority |
| Active Curriculum Version | Activate | Registrar | Recorded external approval | Every entry/revision valid; findings resolved | Immutable active version becomes academic source | Never edited in place; later approved version supersedes prospectively | Clinic 4/5 consume assigned version and evaluation source |
| External competency requirement | Record inside a Draft Curriculum Version | Registrar | Exact approved curriculum authority identifies the qualification and treatment | Qualification/level, curriculum position, authority/effective version, and `TrackedOnly` or expressly authorized `CompletionRequired` treatment are complete | Bounded requirement becomes part of the immutable active Curriculum Version | Supplied evaluation sheets cannot create a completion block; later change requires a successor Curriculum Version | Clinic 5 may record and evaluate only externally verified results against the active requirement |
| Term Calendar Package `Draft` / `Active` / `Closed` | Create, activate, or close | Registrar | Recorded external calendar authority | Valid dates/windows/grid/exceptions; stale action rejected | Current term package and operational-window projection | Activation preserves source; later dated exception/change retains evidence | Clinics 2, 4, and 5 consume their owned window facts without editing them |
| Forecast/confirmed cohort and Draft Class Offering | Project demand; confirm, split, share, add, or cancel | TALA then Registrar | Registrar class-planning authority; special cases record external authority | Active curriculum/term; source and capacity facts; no cohort merge by CP-SAT | Confirmed cohorts/classes and source evidence | Later confirmed change supersedes draft; published-impact guards apply after publication | Clinic 4 supplies unmet demand and consumes published availability; no Student classification is created |
| Teaching resources ready/not ready | Record eligibility, capacity, rooms, unavailability, declaration, commitments | Registrar or Faculty for own declaration | Recorded institutional result; Faculty owns only declaration | Term/source validity; bounded hard facts; no invented preferences | Resource records and failed-first blocker projection | Later authorized source supersedes current fact; history remains | Faculty sees own declaration/schedule; Academic Head sees oversight |
| Generation run active | Generate whole-term candidate | Registrar | Scheduling permission | All readiness checks pass; only one active run; immutable snapshot | `ScheduleGenerationRun` and `SolverSnapshot` | Snapshot never mutates; later run is distinct | System Administrator sees technical health; academic users see business-safe progress |
| `Optimal` or `Feasible` candidate | Validate solver result | TALA, then Registrar reviews | Server-side validation plus Registrar review authority | Every hard rule independently passes | Candidate timetable and quality/diagnostic evidence | Candidate correction creates bounded, revalidated evidence; candidate is never official | Registrar/Academic Head see candidate; Faculty/Students do not |
| `Infeasible`, `Unknown`, `ModelInvalid`, `TechnicalFailure`, or no candidate | Record result and diagnostic owner/action | TALA/integration | Result vocabulary and operational evidence | Returned status and validation evidence are attributable | Failure outcome; no candidate publication path | A later run supersedes current operational focus but retains history | Registrar sees safe reason/source/next action; System Administrator sees technical detail as authorized |
| Published timetable version | Record external sign-off and publish | Registrar | Timetable-publication authority plus recorded external sign-off | Valid complete candidate; current sources; no unresolved hard failure | Immutable `PublishedTimetableVersion` and meetings | Never edited in place; later revision version supersedes it | Faculty sees assignments; Clinic 4 receives availability; Students see only official placed schedule |
| Draft/published timetable revision | Record source change, revalidate, publish revision | Registrar | Publication/revision authority | Complete impact known; Clinic 4 placement impacts resolved; whole timetable valid | New immutable version, superseded link, exact affected roles/classes | Published revision supersedes current version without erasing it | One shared revision event reaches affected Faculty and Clinic 4-supplied Students |

## 5. Catalog and Curriculum Authority

### 5.1 Programs, Courses, and revisions

`ProgramAuthority` records the recognized program, regulator, authority type and reference, effective dates, current status, and approved curriculum source. TALA does not reproduce regulator approval or compliance processing.

One stable `Course` owns course identity and code. An immutable `CourseRevision` owns the approved title, units, prerequisites, corequisites, equivalencies, contact requirements, allowed delivery, room requirements, and effective use.

- Prerequisites and corequisites use simple required-course lists.
- An approved equivalent may satisfy a requisite.
- No nested Boolean rule builder or generic policy language exists.
- A material change creates a new Draft revision.
- An active or historically used revision is read-only.
- Matching text or similar codes never establish course identity or equivalency.

### 5.2 Curriculum Versions

`CurriculumVersion` groups Course Revisions by curriculum year and term. It records the external approving authority, source/reference, approval date, and effective student intake.

Registrar records and activates an externally approved curriculum. TALA has no Academic Head or committee approval queue. The grouped curriculum sheet is the primary work surface:

- Draft rows may be edited.
- Active and historically used rows are read-only.
- One optional TALA CSV preview/import may create Draft Courses, Course Revisions, and Curriculum Entries.
- An import never activates records or overwrites an authoritative active record.
- Duplicate course codes, malformed or circular requisites, inconsistent titles, differing units, missing references, and invalid year/term placement remain blocking findings until resolved.
- Grades, remarks, and student outcomes in evaluation spreadsheets remain student-record evidence and never become catalog fields.

#### TALA curriculum CSV v1 contract

The optional import uses the downloadable template `tala-curriculum-import-template-v1.csv`. It is UTF-8 with an optional byte-order mark, comma-delimited, RFC 4180 quoted, generated with CRLF line endings, and limited to one file of 5 MiB and 5,000 nonblank data rows. The header is row 1 and uses this exact order:

1. `template_version`
2. `program_code`
3. `curriculum_version`
4. `curriculum_name`
5. `curriculum_year`
6. `term_placement`
7. `course_code`
8. `course_title`
9. `units`
10. `prerequisite_course_codes`
11. `corequisite_course_codes`
12. `equivalent_course_codes`
13. `scheduling_treatment`
14. `source_reference`

The three requisite/equivalency cells may be blank; every other cell is required. `template_version` is the literal `1`. Program, curriculum, Course, and source references use the shared code/reference validation; curriculum and Course names use the shared title validation; `curriculum_year` is a positive whole number; and `units` uses the shared positive two-decimal unit validation. Multiple course codes inside one requisite/equivalency cell use `|` and must resolve to a Course in the same preview or current academic authority. `term_placement` accepts only `First`, `Second`, or `Special`; `scheduling_treatment` accepts only `Recurring` or `ExternallyArranged`. Meeting components, allowed delivery modes, and room requirements are completed on the resulting Draft through the ordinary workbench. CSV v1 does not encode them through a nested syntax or mini-language.

The mapping preview shows the fixed one-to-one header contract read-only. Missing, duplicate, reordered, or unknown headers and an unsupported template version block preview; TALA provides no generic mapping designer. Invalid encoding, delimiter, file size, or row count produces one file-level explanation and recovery action and creates no academic record.

Preview creates no Course, Course Revision, Curriculum Entry, or active authority. It shows each source row, normalized interpretation, comparison with current academic authority, proposed Draft effect, errors, and warnings. Whole-file and row checks include authorization and source version; duplicate course codes; circular, unresolved, or malformed requisites; inconsistent course title or units; missing or invalid authority reference; invalid year/term/treatment; a non-empty source cell beginning with `=`, `+`, `-`, `@`, tab, or carriage return; and attempted overwrite of active or historically used authority. A blocking finding links to the exact source row. Warnings require acknowledgement but never conceal a blocker.

**Create Draft records** is available only with zero blockers and acknowledged warnings. It revalidates the Registrar, selected Program/Curriculum context, source versions, checksum, and every affected Draft scope, then creates all Draft records in one transaction. Any failure creates none. A checksum plus selected context reopens one unfinished preview; a successfully committed file/context cannot create duplicate Drafts on retry. Activation remains the separate ordinary readiness decision.

The formula-safe findings download contains source-row identity, original plain-text values, severity, affected column, finding, and recovery. Generated text cells beginning with `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with one apostrophe; browser preview escapes and renders contents as plain text and never evaluates markup. The findings file is not accepted as an import source: Registrar corrects and re-uploads the original TALA template. Upload, preview, findings download, and commit each require current Catalog & Curricula authorization without exposing another Program's data.

An approved Curriculum Version may also contain a bounded external-competency requirement when its exact authority identifies the qualification. The requirement owns only the qualification label and level, related course or curriculum position when applicable, authority reference/date, effective Curriculum Version, and treatment:

- `TrackedOnly` records an externally verified result for curriculum-evaluation visibility and never blocks enrollment, grades, completion, or conferral.
- `CompletionRequired` is permitted only when an exact approved Servitech curriculum authority expressly makes the external result a completion requirement.

The supplied Servitech evaluation forms establish that assessment dates and remarks are tracked; they do not by themselves authorize `CompletionRequired`. TALA does not infer a requirement from similar course titles, TESDA labels in schedule samples, or another institution's practice. The requirement is not a generic credential, certification, or curriculum-rule builder. TESDA assessment and certification remain external; Clinic 5 owns only the verified Student result and its approved curriculum projection.

### 5.3 Courses without recurring meetings

A Course Revision owns zero or more approved weekly meeting requirements.

- Ordinary lectures and laboratories use bounded recurring meeting blocks.
- An internship or externally arranged practicum stays in the curriculum, unit total, later enrollment, COR, grades, and completion records without invented classroom meetings.
- A course with no genuine recurring meeting is labeled **Externally arranged — no recurring master-timetable meeting** and is excluded from CP-SAT.
- A genuine recurring seminar may have its own meeting block without scheduling all workplace hours.

Clinic 3 does not manage placement sites, companies, supervisors, workplace attendance, or practicum logbooks.

## 6. Term Calendar Package

Academic Head approves the institutional calendar outside TALA. Registrar records the authority and evidence, activates the package, and operates its windows. Academic Head receives read-only oversight. TALA adds no duplicate approval action.

Every First, Second, or institutionally approved Special Term begins through an explicitly created `TermCalendarPackage`. More than one exact Term may be `Active` concurrently, including prior-term teaching or grade work while next-term registration or adjustment is open. The uniqueness boundary is one active package version per exact Term, not one active Term for the institution. Every window, class, timetable, registration, roster, result, account, COR, and output carries its exact Term; no action may infer one global current term. TALA never automatically creates or clones the next term, and `Special Term` is a controlled term type rather than a separate Summer subsystem.

### 6.1 State and owned records

Stored state is only:

- `Draft`
- `Active`
- `Closed`

The package owns:

- Academic year, controlled term type, institution-approved display label, administrative dates, and class dates.
- External approval reference and date plus Registrar recording evidence.
- For a Special Term, the approved particular schedule and attributable class-hour/class-day basis required by the recorded calendar authority.
- Typed operational windows with inclusive Asia/Manila close dates and an optional approved cutoff time.
- One weekly teaching-grid row for every allowed teaching day.
- Recurring institutional breaks.
- Dated holidays, no-class periods, make-up dates, and other approved exceptions.

No weekday, operating-hour, break, preferred-time, Special Term unit limit, or compressed-schedule value is assumed. Scheduling uses a fixed code-owned 30-minute grid inside the approved operating hours; the granularity is not configurable.

Application dates remain in Admission Cycles. Payment dates remain in accounts. The approved `Examination Period` is informational: its inclusive dates, Term display label, calendar authority, effective package version, owner, and as-of time are projected read-only to Registrar, Academic Head, Faculty, and officially enrolled Students. Exact class arrangements remain Faculty-owned and use the approved teaching channel. Missing or stale evidence shows **Examination period unavailable — contact Registrar or Faculty** and never derives a date from class meetings. Clinic 3 includes no class-level examination record or examination scheduler. The neutral `Enrollment` operational window owns approved opening and closing dates only. Clinic 4 assigns its bounded applicability to Ready Applicants, Standard continuing Students, Individually Advised or exception cases, or all otherwise eligible learners. The `Grade Entry` window owns the definite Faculty submission period and due date consumed by Clinic 5; Clinic 5 owns late-grade authority, INC deadlines, release, and correction behavior. No programmable audience rules are introduced. Activating a term does not open enrollment; Clinic 4 also requires a published timetable and its remaining readiness facts.

### 6.2 Readiness and date effects

Activation is blocked by missing authority, invalid term/class bounds, incomplete operational windows, an empty or contradictory teaching grid, invalid breaks, or conflicting dated exceptions. Readiness presents only failed checks; success collapses to **All required checks passed**.

A dated exception affects the applicable dated occurrences without rewriting the recurring published meeting pattern. Faculty and room unavailability remain teaching-resource records, not calendar events.

### 6.3 Consolidated Readiness Matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Program and curriculum authority | Program Authority, Course Revisions, active Curriculum Version | Registrar | Current authority and immutable applicable version are complete | Term classes/generation blocked | Confirm classes; generate; publish | Correct Draft authority/version and activate an approved replacement |
| External competency requirement authority | Exact approved curriculum source for each declared external qualification | Registrar records; external authority owns requirement | Every declared requirement has qualification/level, curriculum position, treatment, authority, and effective version; `CompletionRequired` is explicit | Requirement cannot activate or be consumed by Clinic 5; no completion effect is inferred | Activate Curriculum Version; evaluate external result | Correct the Draft requirement/source or omit it; never infer from an evaluation sheet or course label |
| Term Calendar Package | Approved package, operational windows, grid, exceptions | Registrar | Active package has valid bounds, windows, teaching days, breaks, and exceptions | Term planning/generation blocked | Confirm class occurrences; generate | Correct Draft package/source and activate |
| Special Term schedule | Approved particular schedule, class-hour/class-day basis, and applicable Curriculum Version | Registrar records; institution owns approval | Special Term authority, dates, meeting evidence, and curriculum placement are complete and mutually consistent | Activation, class confirmation, and generation blocked; no Summer default applies | Activate Special Term; confirm classes; generate | Correct the external authority or Draft package; never infer a unit cap, duration, or compressed pattern |
| Cohorts and demand | Continuing demand, Clinic 2 ready counts, Clinic 4 unmet-demand projection, Registrar confirmation | Registrar | Cohorts needing standard classes are confirmed with attributable demand | Class confirmation/generation blocked or flagged | Confirm/split cohorts and classes | Reconcile source and record confirmation; never let CP-SAT merge cohorts |
| Class Offerings | Confirmed Class Offerings and cohort links | Registrar | Every in-scope course has source, capacity, meeting treatment, mode, and state | Generation blocked for incomplete recurring classes | Generate/publish | Correct, share, add, or validly cancel the Draft class |
| Faculty | Eligibility, term capacity, availability declaration, assigned demand | Registrar and Faculty | Eligible Faculty and bounded capacity/availability exist for every required meeting | Generation blocked | Generate candidate | Correct institutional result or request/record declaration |
| Rooms | Room source and hard unavailability | Registrar | Required capacity/type/features and usable intervals exist | Generation blocked or infeasible | Generate/validate candidate | Correct source or record valid resource change |
| Meeting patterns and modes | Course Revision weekly requirements and Class Offering mode | Registrar | Every recurring requirement has bounded duration/frequency/mode/room need | Generation blocked/model invalid | Build snapshot | Correct Draft course/class source; never invent hours |
| Bounded commitments/integrations | Authorized commitments and locally evidenced solver/System Health facts | Registrar and System Administrator | Commitments are attributable and the integration can submit/record one run | Generation blocked or technical failure | Generate/retry | Correct authority/input or restore service; do not weaken hard rules |
| Candidate validity | Solver result plus independent Laravel validation | TALA and Registrar | Complete candidate satisfies every hard rule and has review evidence | Publication blocked | Record sign-off and publish | Correct source/candidate within bounds or run a new snapshot |
| Publication prerequisites | Valid candidate, recorded external sign-off, Clinic 4 impact resolution for revisions | Registrar | Authority/reference exists and affected placements have valid outcomes | First publication/revision blocked | Publish timetable version | Record valid authority or resolve impacts, then revalidate |

## 7. Cohorts and Class Offerings

### 7.1 Term Cohorts

`TermCohort` represents a Registrar-confirmed standard-curriculum cohort, not a policy-driving Regular/Irregular Student classification. Forecast demand combines continuing-student data with Clinic 2 Ready-for-Enrollment counts. Registrar confirms the actual count and creates or splits cohorts. TALA does not automatically split cohorts using a universal target.

### 7.2 Class Offerings

`ClassOffering` is the actual class or section for the term. It replaces the legacy Term Offering → Section → Delivery Group layering.

Stored state is only `Draft`, `Confirmed`, or `Cancelled`. Blocked, ready for scheduling, candidate scheduled, and published are derived projections.

Each Class Offering records the Course Revision, stable class reference, `Regular` or `Additional` source, linked cohorts and expected count, authoritative institutional class capacity, weekly meeting pattern and per-block mode, room needs, applicable exact commitments, and current readiness. `Regular` describes a curriculum-planned offering source only; it is not a Student or enrollment status.

TALA may generate Draft Class Offerings from the active curriculum, confirmed cohorts, forecasts, and bounded `UnmetClassDemandProjection` evidence returned by Clinic 4. Registrar alone confirms, splits, shares, adds, or cancels those drafts. CP-SAT schedules confirmed offerings and never creates or merges them.

One `Additional` Class Offering covers an externally approved retake, catch-up, elective, or other exceptional class. TALA does not create separate petition, tutorial, summer, or special-offering workflow engines.

Compatible cohorts may share one Class Offering only for the same canonical Course or an approved equivalency. Registrar confirms the shared class after reviewing total demand, capacity, delivery, Faculty, room, and timetable feasibility.

Capacity does not control admission and has no universal institutional ceiling. An on-campus capacity increase cannot exceed the assigned room. An Online class still has an institutionally approved class capacity.

Before publication, cancellation requires a reason and authority. After publication it requires a timetable revision. Once Clinic 4 placements exist, Clinic 4 must resolve the affected enrollments before final cancellation.

## 8. Clinic 4 and Clinic 5 Handoffs

Clinic 3 exposes curriculum term totals, requisites, approved equivalencies, published Class Offerings, capacities, and official meeting times through one exact-Term `PublishedClassAvailabilityProjection`. Concurrent packages remain independent: a source version, window, publication, closure, or failure for one Term never supplies or changes another Term's facts.

Clinic 4 contains no standalone Study Plan. Its `RegistrationCase` owns versioned proposed registrations under `EnrollmentSelectionBasis` (`StandardCurriculum` or `IndividuallyAdvised`) plus current-term eligibility, confirmation, placement and reservations, finance clearance, official enrollment, Student activation, and COR. Clinic 5 owns full curriculum evaluation and official academic-history outcomes; Clinic 4 consumes those released facts.

The approved curriculum term total is the normal unit ceiling for both selection bases. There is no separate irregular-student maximum. An Individually Advised proposal may carry fewer eligible units; that basis does not create unrelated substitutions or overload rights.

A failed prerequisite blocks only its dependent chain. Other eligible curriculum courses remain available. Cross-program placement requires the same canonical Course or an approved equivalency, a published class, capacity, no conflict, appropriate program permission, and Registrar confirmation. A graduating overload is an externally approved Clinic 4 exception; Clinic 3 encodes no universal overload amount.

For the coordinated Special Term acceptance journey, Clinic 3 owns `TERM-2026-ST`, curriculum-planned `CLS-ITE3-ST-A`, externally approved Additional retake/catch-up class `CLS-IT201-ST-R`, their complete scheduling inputs, and the published timetable version. Clinic 4 alone decides the learner's `REG-2026-ST-001` proposal and placement; Clinic 6 owns assessment and coverage; Clinic 5 owns released results and academic averages. The word `Additional` records the offering source only and does not classify the learner or create a tutorial workflow.

The Ethics-to-Rizal example remains hypothetical because the supplied curriculum evidence does not establish that prerequisite.

Clinic 5 consumes the official `ClassOffering`, designated submitting-Faculty assignment, official course-unit value, Term Calendar Grade Entry window and term-end date, effective Course Revision, and `CourseAcademicClassification`. One Clinic 5 roster is created per official Class Offering, including an internship or externally arranged course with no recurring timetable meeting. Clinic 3 never stores grades or decides GWA inclusion; it supplies only the authoritative class, course, Faculty, calendar, and classification facts needed by Clinic 5.

## 9. Teaching Resources and Commitments

### 9.1 Faculty

A Faculty member may teach multiple approved courses. TALA records externally determined course eligibility, term unit limit, applicable preparation limit, authority/evidence, and effective dates. It does not reproduce HR, appointment, or academic workload-approval workflows.

Each Faculty member makes one declaration for the selected term: genuine hard-unavailability periods or **No additional restrictions**. The declaration has a scheduling-input deadline and one action-required email. It has no approval queue and no preferred-time field. Late corrections record actor and reason and trigger candidate revalidation or the published revision path; they never silently move a meeting.

### 9.2 Rooms and commitments

A Room records code/name, capacity, type, flat features, active state, and term-specific hard unavailability. Clinic 3 has no building travel matrix, maintenance workflow, room-booking marketplace, or Online room.

Authorized exact Faculty, room, or meeting-time commitments may be recorded with reason and authority. Unspecified values remain solver decisions. No soft lock or generic override exists.

## 10. Meeting and Modality Contract

A Class Offering chooses a bounded approved meeting pattern such as `1×180`, `2×90`, or `3×60`. Each required block becomes one solver demand with only necessary same-offering linkage rules.

- Every block is `On-campus` or `Online`.
- Mixed student schedules and blended courses are derived descriptions, not stored modes.
- Simultaneous Hybrid or HyFlex delivery and student-selected modality are excluded.
- On-campus blocks require a suitable physical room. Online blocks require no room.
- A cohort receives at least 30 minutes when transitioning between Online and On-campus meetings.
- Minimizing mode switches is a soft priority. No hard modality-only day is imposed.

## 11. Whole-Term CP-SAT Contract

### 11.1 Scope and hard validity

One generation run covers every confirmed and scheduling-ready Class Offering in the selected term. Program-by-program or arbitrary-subset publication is prohibited because Faculty, rooms, cohorts, and shared classes cross program boundaries.

Hard constraints include complete assignment of every included meeting block; Faculty, room, and cohort non-overlap; approved calendar grid, breaks, dated effects, and hard unavailability; room capacity, type, and required features; Faculty eligibility, term load, and applicable preparation limits; required meeting pattern and linkage; cohort modality-transition buffer; authorized hard commitments; whole-term completeness; and independent Laravel revalidation.

The accepted runtime default is the private `tala-scheduler-solver` Cloud Run service in `asia-southeast1`: 8 vCPU, 16 GiB, eight solver workers, concurrency one, a 300-second solver limit, a 360-second HTTP timeout, minimum zero instances, maximum two instances, and deterministic seed `20260718`. These values preserve a previously qualified operating profile; they do not prove that the current formula or internal contract implements this PRD.

The current `tal94-demand-v2` and `balanced_v1` implementation names remain bounded technical evidence. This PRD neither adopts their behavior nor names a replacement contract. The future PRD 03 vertical slice must reconcile the refined source model, constraints, quality order, typed outcomes, Laravel validation, formulation, tests, fixtures, and deployed compatibility before claiming conformance. Expensive capacity testing is repeated only if the reconciled formulation, workload, compatibility evidence, or runtime telemetry invalidates the preserved profile.

### 11.2 Fixed lexicographic quality hierarchy

After hard feasibility, the code-owned non-configurable hierarchy is:

1. Cohort mode switches.
2. Cohort idle time.
3. Faculty load imbalance.
4. Faculty idle time.
5. Room-seat waste.
6. Stable earlier day/time placement as a final tie-breaker.

A lower priority may not worsen a higher priority. Staff never see or edit weights. TALA reports the individual quality measures and never presents solver “accuracy.”

### 11.3 Result vocabulary

| Result | Meaning and publication effect |
| --- | --- |
| `Optimal` | Complete, independently hard-valid, and proven best under the applied hierarchy; eligible for human review and publication. |
| `Feasible` | Complete and hard-valid, but best quality was not proven within the limit; Registrar may publish after review and recording a reason. |
| `Infeasible` | Impossibility was proven; no candidate exists and nothing may be published. |
| `Unknown` | Search ended without a candidate or proof of impossibility; nothing may be published. |
| `ModelInvalid` | The solver contract or model is defective; nothing may be published. |
| `TechnicalFailure` | Service, transport, infrastructure, or integration failed; nothing may be published. |

Failure explanations are deterministic and non-AI:

`Failure → affected source records → factual basis → responsible owner → corrective action/link`

TALA uses typed reason codes, candidate-enumeration evidence, source identifiers, and sufficient OR-Tools conflict assumptions when applicable. It never promises that a reported conflict set is mathematically minimal. Only failed checks expand. Successful readiness shows **All required checks passed**.

## 12. Candidate Review, Corrections, and Reruns

The solver produces the first complete candidate. Registrar may use **Adjust candidate meeting** with constrained day, time, Faculty, and room choices. Every correction revalidates the entire candidate. No hard constraint can be waived. A correction that lowers soft quality requires a publication reason. “Manual override” and generic constraint-bypass records are removed.

Only one run may be active per term. Every run, snapshot, and candidate remains immutable.

| Prior result | When another equivalent run is allowed |
| --- | --- |
| `Optimal` or `Feasible` | After a source change or recorded candidate rejection |
| `Infeasible` | After a conflicting source fact changes |
| `Unknown` | Same-snapshot retry is allowed |
| `ModelInvalid` | After the model defect is corrected |
| `TechnicalFailure` | After technical recovery |

There is no arbitrary lifetime run limit and no unlimited identical-rerun action.

## 13. Publication and Revision

The immutable Published Timetable and its role-filtered print/save-as-PDF view are Clinic 3's official output. Output generation uses one Published Timetable Version and labels it `Published` or `Superseded`; it never combines meetings from multiple versions. The authenticated A4 landscape view contains approved institution identity and **PUBLISHED TIMETABLE**; Academic Year, exact First/Second/Special Term label and reference; authority reference; publication and generation times; version and role/filter context; and ordered day/time, Course, Class Offering, Faculty where authorized, delivery mode, room or Online location, and revision marker. It repeats the Term/version and table headings on continuation pages, is monochrome-safe, omits navigation/controls, and uses a restrained **Generated through TALA** footer. A superseded version remains printable only as visibly historical. Stale or unavailable source prevents generation and failure produces no partial or official-looking artifact while preserving the ordinary timetable page and safe retry/support path.

Any required academic sign-off follows `Decision then record` outside TALA. Registrar records its authority/reference and publishes.

- Candidate and published timetable records remain separate.
- First publication creates an immutable `PublishedTimetableVersion` and its `PublishedMeeting` records.
- No published meeting is edited in place.
- A targeted change creates a Draft `TimetableRevision`, records the source change and affected roles/classes, and revalidates the complete timetable.
- Publishing the revision creates a new immutable version and preserves the superseded version and exact impact.
- A widespread source change may justify a new whole-term generation run.

## 14. Communication Contract

| Trigger | Recipient | Safe contents | Source / idempotency key | Failure behavior | Excluded notifications |
|---|---|---|---|---|---|
| Faculty availability action requested | Affected Faculty | Term, required action, due date, secure link; no other Faculty data | Term plus declaration-request generation | Readiness remains blocked/visible; authorized resend available | No email for routine declaration save |
| First official timetable publication | Assigned Faculty | Publication fact, own assigned schedule link, version | Published timetable version plus Faculty identity | Publication remains authoritative; delivery outcome recorded | No candidate or generation-status mail |
| Shared timetable revision published | Affected Faculty and Clinic 4-supplied officially enrolled Students | Revision fact, own affected schedule/COR context, secure link | Published revision version plus recipient identity | Revision remains authoritative; authorized resend is shared, not duplicated | No separate Clinic 4 timetable-revision email |

No email is sent for term creation or activation, routine saves, readiness checks, generation, failure, candidate correction, or page activity. Delivery failure never rolls back an academic state change. The workspace remains authoritative and records authorized resend evidence.

## 15. UI Authority

The exact page hierarchy and low-fidelity layouts live in the Clinic 3 section of the [UI Surface Blueprint](../ui_surface_blueprint.md). This PRD owns the information, action, and role contract.

### 15.1 Catalog & Curricula workbench

One connected Registrar workbench contains Programs and authority, the Course catalog and current revisions, a grouped Curriculum Version sheet, Draft import preview and blocking findings, and activation readiness and evidence.

The curriculum sheet groups by curriculum year and term. It shows course code/title, units, prerequisites/corequisites, scheduling treatment, weekly meeting pattern, modes, room needs, source, and readiness. Authorized external-competency requirements appear in a bounded section with qualification/level, mapped curriculum position, treatment, authority, and effective version. Draft rows may be edited; active records are read-only.

### 15.2 Term Planning workbench

One selected-term header shows term identity, state, current readiness, governing authority, current published version, and one state-appropriate primary action.

The workbench has five tabs:

1. **Overview** — official dates, operational windows including the informational Examination Period, weekly grid, exceptions, authority evidence, and failed-first readiness.
2. **Cohorts & Classes** — forecast and confirmed cohorts, Class Offerings, sharing, capacity, source, pattern, mode, state, readiness, and contextual filters/actions.
3. **Teaching Resources** — Faculty declaration and capacity, eligibility, assigned demand, rooms, hard unavailability, blockers, and bounded commitments.
4. **Generate & Review** — result meaning, owner, next action, quality measures, filterable weekly view, accessible table alternative, warnings or failure diagnostics, and candidate actions.
5. **Published Timetable** — current immutable version, authority, publication time, filtered official timetable, print/save-as-PDF, revision impact, and superseded history.

Only the weekly timetable view is a justified custom component. Native Filament Tables, Sections, Infolists, Forms, Action Groups, filter panels, and active indicators own the rest. There are no custom column-header filter dropdowns, drag-and-drop timetable editor, generic Academic Settings surface, or peer navigation maze.

### 15.3 Role projections

- Registrar: complete editable planning and publication authority.
- Academic Head: read-only calendar, Examination Period, curriculum including authorized external requirements, readiness, candidate evidence, and published timetable oversight.
- Faculty: one availability declaration, assigned official schedule, affected revision history, and the current Examination Period with source/as-of evidence.
- Student: confirmed schedule only after Clinic 4 placement and official enrollment, plus the current Examination Period with source/as-of evidence; exact class exam arrangements remain outside TALA.
- System Administrator: solver-related System Health and technical evidence, with no academic authority.
- Applicant, Accounting, and Public: no Clinic 3 timetable authority or master-schedule access.

On mobile, curriculum rows and class/resource tables use responsive stacked layouts and the weekly view becomes day-by-day/list presentation. Secondary actions remain in Action Groups. Status meaning never depends on color alone.
## 16. Lifecycle, Mutation, and Implementation-Evidence Boundary

Draft Programs, Courses, Course Revisions, Curriculum Versions, Calendar Packages, resources, cohorts, and Class Offerings may be deleted only before activation, confirmation, publication, or any reference. Historically used authority is retired, cancelled, or superseded through effective-dated successors. Accepted candidates and Published Timetable Versions are immutable.

This PRD defines the desired scheduling problem and institutional workflow. It does not approve a replacement formula, internal API, wire schema, table, class, migration, or deployment change. Current `tal94-demand-v2`, `balanced_v1`, Laravel integration, formulation, schema, tests, and fixtures remain implementation evidence to be reconciled together in a future PRD 03 journey-complete vertical slice.
## 17. Acceptance and Defense Scenarios

The future vertical implementation must prove:

- Program authority and externally approved curriculum activation.
- Authority-gated external-competency requirement activation without inferred completion effect.
- Duplicate or inconsistent curriculum import findings.
- Simple prerequisites, equivalencies, and circular-reference prevention.
- Internship retained without an invented recurring timetable.
- Explicit First, Second, and approved Special Term creation.
- Concurrent exact First-, Second-, and Special-Term operation, including prior-term grade entry while next-term registration and adjustment are open, with no implicit current-term action or cross-term source leakage.
- One Special Term that continues through published classes, Clinic 4 registration, Clinic 6 assessment/coverage, Clinic 4 official enrollment, and Clinic 5 released-result projections using the same references.
- Calendar-readiness failures and successful activation.
- Consistent Registrar, Academic Head, Faculty, and Student Examination Period projections, including unavailable/stale evidence and no class-level schedule.
- Forecast and confirmed cohorts.
- Regular, shared, and Additional Class Offerings.
- Multiple cohorts sharing one class without being mistaken for room sharing.
- Multiple meeting patterns and On-campus/Online blocks.
- Cohort modality-transition buffer.
- Faculty multiple-course eligibility, load/preparation limits, availability confirmation, and late correction.
- Room capacity, type, feature, and availability conflicts.
- Bounded hard commitments.
- Whole-term `Optimal`, `Feasible`, `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure` outcomes.
- Deterministic failure reason, owner, source link, and next action.
- Solver-first candidate correction and hard-rule rejection.
- Outcome-specific rerun behavior.
- External sign-off recording, first publication, targeted revision, and immutable history.
- Impact-checked Class Offering cancellation.
- Cross-role authorization, filtered print outputs, queued email success/failure/resend, responsive layouts, and accessible interaction.
- Policy traceability for every automatic rule.
- Later DB-backed checks only against `test_tala_db`.

Realistic demonstration data must include at least one externally arranged practicum, one authority-backed tracked-only external-competency requirement, one shared class, one Additional class, multiple meeting patterns, mixed On-campus/Online schedules, a Faculty late correction, a room conflict, every solver-result family, a bounded candidate correction, a first publication, an affected-role revision, one Examination Period projection plus unavailable-source state, and two simultaneously active exact Terms whose operational windows overlap. The scoped [PUP 2026–2027 schedule](https://www.pup.edu.ph/announcements/?go=Cjoh4ZVj%2FLE%3D&v=Schedule-of-AY-2026-2027-First-Semester-Online-Enrollment-and-Encoding-of-Grades-20260727134235133) supports the overlap scenario only; it supplies no Servitech dates or policy. Demonstration data is not policy authority.

### 17.1 Synthetic Demonstration Data

The coordinated institution contains BM, IT, and THM; 47 current Students across six first/second-year cohorts; nine Faculty; and ten rooms with explicit capacity, type, features, and availability. Third-year curricula may be represented, but no current third-year enrollment is fabricated. The observed 34 face-to-face/13 online distribution is contextual population evidence and never assigns a Class Offering's mode.

| Reference | Synthetic record | Demonstrated evidence |
|---|---|---|
| `CUR-THM-2026` | Active THM Curriculum Version with lecture, laboratory, and `PRACT-401` externally arranged practicum | Immutable authority, grouped curriculum ordering, no invented practicum meeting |
| `EXT-COMP-CSS-NCII` | Tracked-only external competency requirement with exact synthetic curriculum authority | Clinic 5 may record verified results; absence cannot block completion |
| `EXT-COMP-WEB-NCIII-REQ` | Hypothetical `CompletionRequired` external competency requirement with explicit synthetic curriculum authority | Demonstrates that only exact authority can create a pending completion effect; it is not adopted Servitech policy |
| `TERM-2026-1` | Active First Term package with Mon–Sat grid, approved break, holiday, Enrollment, Examination Period, and Grade Entry windows | Failed then passing activation readiness, role-consistent examination-period projection, and dated-exception behavior |
| `TERM-2026-ST` | Approved Special Term package with particular schedule, attributable class-hour/class-day basis, Enrollment and Grade Entry windows | Missing authority blocks activation; no Summer-specific unit or timetable default is used |
| `COH-THM-1A/1B` | Separate confirmed cohorts with one approved shared general-education class | Shared class without cohort merge |
| `CLS-HM101-A/B` | Regular Class Offerings plus `CLS-HM205-X` Additional class with authority | Demand source, capacity, mode, and exception evidence |
| `CLS-ITE3-ST-A` / `CLS-IT201-ST-R` | Curriculum-planned Special Term class and externally approved Additional retake/catch-up class | One scheduler and publication journey; no tutorial resource or learner status |
| `FAC-ADA` | Ada Faculty with multiple-course eligibility, bounded capacity, and late corrected availability | Declaration request, blocker, authorized correction, own schedule |
| `ROOM-LAB1` | Laboratory room with one hard unavailable interval | Capacity/type conflict and factual recovery source |
| `RUN-OPT`, `RUN-FEA`, `RUN-INF`, `RUN-UNK`, `RUN-MOD`, `RUN-TECH` | Six immutable generation snapshots | `Optimal`, `Feasible`, `Infeasible`, `Unknown`, `ModelInvalid`, and `TechnicalFailure` meanings |
| `CAND-2026-01` | Valid candidate with one bounded meeting correction | Independent validation and no hard-rule override |
| `PUB-2026-01/02` | First publication and a later affected-role revision | Immutable versions, supersession, Faculty and Clinic 4 Student impact |

### 17.2 Timetable-Publication Browser Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Registrar; Draft `CUR-THM-2026` | Catalog & Curricula | Download the v1 template, preview one invalid file, correct it, create Draft records, resolve remaining findings, and activate | Fixed headers/mapping, source comparison, errors/warnings, checksum, grouped sheet, authority, immutable active state | Clinic 4/5 can consume only the active version | Formula-safe findings CSV and curriculum activation evidence | Invalid encoding/header/row, circular requisite, missing authority, stale source, or duplicate commit creates no partial/duplicate Drafts | No generic mapper, Settings surface, or peer-resource maze is used |
| Registrar; Draft `TERM-2026-1` | Term Planning Overview | Attempt activation, correct window/grid conflict, activate | Failed-first source/owner/recovery then all-passed summary | Downstream clinics see owned window projections | Active Term Calendar Package | Contradictory exception blocks activation | Dates are explicit and no term is auto-cloned |
| Registrar, Academic Head, Faculty, and Student; active `TERM-2026-1` | Term Planning Overview / Academic Oversight / My Schedule / Student Home or Academics | Open the Examination Period projection | Same approved dates, authority, package version, owner, and as-of time; exact arrangements identified as Faculty-owned | Every role sees the same informational period without gaining a scheduling action | No new output | Missing/stale calendar evidence shows the named unavailable state and no inferred date | No class-level exam schedule, email, generic event, or financial hold appears |
| Registrar and Faculty `FAC-ADA` | Cohorts & Classes / Teaching Resources / My Availability | Confirm classes, request declaration, submit late correction | Demand sources, separate cohorts, blockers, declaration history | Registrar readiness updates; Faculty sees only own facts | Complete scheduling inputs | Room or Faculty gap remains linked to owner/source | Every recurring class is attributable and ready |
| Registrar; `RUN-TECH` then `RUN-INF` | Generate & Review | Generate, inspect technical failure, then infeasible diagnostics | Distinct result meaning, safe reason, owner, source, next action | System Administrator sees technical health only | Retained failed runs, no candidate | Retry unavailable until service/source recovery | Failure is never mislabeled as a valid candidate |
| Registrar; `RUN-FEA` / `CAND-2026-01` | Generate & Review | Open valid candidate, make bounded correction, revalidate | Quality hierarchy, weekly and accessible table views, hard-rule result | Academic Head sees read-only evidence; Faculty/Students see nothing yet | Valid complete candidate | Invalid correction is rejected without waiver | Candidate remains non-official until publication |
| Registrar; external sign-off recorded | Published Timetable | Publish `PUB-2026-01`, filter/print the A4 landscape official view | Authority, exact Term, version/status, publication/generation time, role/filter context, immutable meetings | Assigned Faculty sees official schedule; Clinic 4 receives availability | Monochrome-safe Published Timetable with repeated Term/version and headings | Missing/stale sign-off blocks publication; generation failure creates no artifact | Published data exactly matches the validated candidate and a superseded version is visibly historical |
| Registrar with affected Clinic 4 placements | Published Timetable revision | Record source change, resolve impact, publish `PUB-2026-02` | Complete impact, validation, superseded history | Affected Faculty and enrolled Students receive one shared event | New official version and revision evidence | Unresolved placement or invalid timetable blocks publication | No meeting is edited in place and no duplicate email fires |
| Registrar; Draft `TERM-2026-ST` | Term Planning → Cohorts & Classes → Generate & Review | Fail missing calendar/Additional authority, record valid authority, confirm `CLS-ITE3-ST-A` and `CLS-IT201-ST-R`, then publish | Particular schedule, class-hour/class-day evidence, offering sources, resources, candidate, and official version | Clinic 4 receives the two published classes under the same Special Term reference | Published Special Term timetable | Missing authority, resource conflict, or invalid candidate blocks the next action | No Summer scheduler, tutorial workflow, universal unit cap, or learner classification appears |

### 17.3 Authority-hardening control matrix

| Action or record | Authorization and validation | Confirmation and audit | Lifecycle, retry, and failure behavior |
|---|---|---|---|
| Program, Course Revision, Curriculum Version | Registrar records approved external authority | Unique scoped references; effective dates do not overlap incompatibly; units/contact hours reconcile; prerequisite/corequisite graph has no cycle; required external competency is expressly authorized | **Activate [record]** shows effective version and downstream Programs/Terms. Never-used Draft may delete; active/historically used record retires or receives successor, never hard-deletes |
| Calendar Package activation/closure | Registrar | Authority, Term/type, inclusive dates, operational windows, Examination Period, teaching grid, dated exceptions, Special Term class-hour/class-day evidence, no conflict | **Activate/Close calendar** shows every downstream window and consuming clinic. Activated package is immutable; successor/dated exception preserves history; stale input blocks |
| Term Cohort/Class Offering confirmation/cancellation | Registrar | Unique Term/program/cohort/course/reference; attributable demand; active curriculum; course/offering uniqueness; positive units; valid capacity; Additional authority when applicable | **Confirm/Cancel Class Offering** shows demand, capacity, scheduling, Clinic 4 effects, and reason. Confirmed/historical offering never deletes; cancellation preserves projections/history |
| Faculty/resource readiness | Faculty records own availability; Registrar records eligibility/capacity/room authority | Faculty eligibility, availability, load/preparation facts; unique room/resource code; room capacity and modality; no contradictory commitment | Late availability correction records source and impact. Candidate becomes stale; after publication use controlled timetable revision |
| Generate schedule | Registrar after failed-first readiness passes | One immutable source snapshot; all classes/resources/grid/integrations current; no active run | **Generate timetable candidate** shows snapshot, expected result classes, and no publication effect. Exactly one run per snapshot scope at a time; no arbitrary lifetime count |
| Solver retry/correction | Registrar | Same-snapshot retry only for `Unknown`; `Optimal`/`Feasible` need source change or recorded rejection; `Infeasible` needs conflicting fact change; `ModelInvalid` defect correction; `TechnicalFailure` recovery | Constrained candidate correction revalidates all hard rules and records quality impact. Invalid/stale correction changes nothing; failures retain source/owner/next action |
| Candidate accept/reject | Registrar; current valid complete candidate and source snapshot | Named action shows that acceptance remains non-official; rejection requires reason and enables attributable later run | Candidate immutable. Concurrency/stale source blocks; no hidden waiver or manual override |
| First/revision publication | Registrar after external sign-off and full revalidation; current accepted candidate/revision, complete impact, valid Clinic 4 placements, and source authority | **Publish timetable/revision** shows version, affected classes/roles, one owned email event, outputs, supersession, and irreversibility | Atomic/idempotent. Published meetings never edit/delete; successor version and exact impact preserve history; output failure creates no partial official artifact |

References/codes follow the shared 1–64-character primitive and are unique within their owning scope. Unit/contact-hour, capacity, room-capacity, meeting-grid, date-window, and effective-authority checks are cross-record validations rather than generic policy rules. Examination Period and external-competency boundaries remain unchanged.
## 18. Technical and Operational Boundaries

TALA invokes a separately deployed private OR-Tools CP-SAT service. Laravel owns authorization, source-snapshot creation, request identity, persistence, independent candidate validation, and publication. The solver owns bounded search over the supplied immutable snapshot and returns a typed outcome plus attributable diagnostics and quality measures. Neither side may treat a browser timeout or transport response as an official timetable.

The accepted runtime default is operational evidence, not formula conformance. Later implementation planning must reconcile code, the internal contract, formulation, tests, fixtures, and deployment compatibility before changing any of them.
## 19. Assumptions and External Responsibilities

- TALA targets a normally recognized and authorized Philippine college.
- The supplied TESDA handbook is contextual evidence only and does not establish college policy.
- Existing business documents remain valuable but unverified evidence.
- Institutional curriculum, calendar, staffing, workload, exceptional-class, timetable-sign-off, and overload decisions occur externally and are recorded by TALA.
- The existing application and schema remain intact until later authority-approved reconciliation.

Clinic 3 is approved and has passed the complete-authority, essential-SIS negative-space, and authority-hardening reviews. Its complete-clinic checklist is satisfied through this PRD and its Clinic 3 UI authority, every automatic rule remains factually traceable, and the settled Clinic 3↔4 handoff is preserved. No material Clinic 3 product question remains open. Approval permits separately authorized journey-complete planning; it does not authorize implementation.
