# PRD 03 — Academic Setup, Offerings, and Published Timetable

## Authority and Review Status

**Clinic 3 authority:** Approved on 2026-08-06; complete-authority review passed

This is the canonical approved unified Clinic 3 journey authority and has passed the complete-authority review. It replaces the product authority formerly split across Academic Setup, Term Offerings and Resources, and CP-SAT Scheduling. Those inputs are preserved in `_legacy/` as read-only salvage evidence. Complete-set approval authorizes later implementation-task derivation only; this PRD does not authorize application changes, schema changes, migration work, or solver deployment.

## 1. Purpose and Successful Outcome

Clinic 3 defines one complete institutional journey:

`Approved academic authority → active curriculum → active term calendar → confirmed cohorts and classes → teaching-resource readiness → whole-term CP-SAT generation → human review → published timetable → controlled revisions`

The successful outcome is one immutable published timetable version that every authorized role reads through a purpose-specific projection. Candidate schedules are proposals. Only a Registrar-published version is official.

Clinic 3 does not own admissions, student-level study planning, enrollment, seat reservation, finance, COR, grades, internship operations, or Student activation.

## 2. Evidence and Institutional Boundary

This contract is governed by:

- [CHED CMO No. 40, s. 2008 — MORPHE](https://ched.gov.ph/wp-content/uploads/2017/10/CMO-No.40-s2008.pdf), applicable program Policies, Standards, and Guidelines, and the institution's approved academic decisions.
- [CHED Regional Office I collegiate-calendar guidance](https://region1.ched.gov.ph/wp-content/uploads/2024/05/CRMO-NO.-03-S.-2024-GUIDELINES-FOR-COLLEGIATE-AND-GRADUATE-SCHOOL-CALENDARS-AY-2024-2025.pdf), which requires particular schedules and the required class hours/days for summer, trimestral, or quarterly terms but does not prescribe a separate SIS workflow or Servitech calendar.
- [CHED CMO No. 62, s. 2017](https://ched.gov.ph/wp-content/uploads/2018/03/CMO-62-BS-Hospitality-Tourism-Management.pdf) for the distinction between supervised workplace practicum and recurring classroom timetable hours.
- [PeopleSoft Student Records](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/student-records-overview.html), [PeopleSoft combined sections](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/creating-combined-sections.html), and [UniTime course timetabling](https://help.unitime.org/course-timetabling) as mature-system benchmarks only.
- [OR-Tools CP-SAT statuses](https://developers.google.com/optimization/cp/cp_solver) and [OR-Tools infeasibility guidance](https://github.com/google/or-tools/blob/stable/ortools/sat/docs/troubleshooting.md) for solver-result meaning and bounded diagnostic evidence.

No approved Servitech institutional handbook has been supplied. Existing spreadsheets, business forms, database structures, services, and UI remain useful evidence, but none becomes policy merely because it already exists.

| Institutional responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Recognize a program or approve a curriculum | Regulator and authorized institution outside TALA | Record the authority, source, effective dates, and approved result |
| Approve the institutional academic calendar | Academic Head through the institution's process outside TALA | Registrar records, activates, and operates the approved package |
| Decide staffing eligibility, load, exceptional class authority, timetable sign-off, or graduating overload | Authorized institution outside TALA | Record only the approved result, authority, evidence reference, and effective dates needed by the owning clinic |
| Build cohorts, classes, and scheduling inputs | Registrar | Authoritative source records and readiness |
| Generate a timetable candidate | CP-SAT service | Integration output that remains untrusted until Laravel validation and human review |
| Review and publish the official timetable | Registrar | Controlled publication record and immutable version history |
| Manage internship placement, companies, supervisors, logbooks, or workplace attendance | External academic process | No Clinic 3 workflow |

This is a `Decision then record` product. It does not recreate regulator, committee, HR, workload-approval, practicum, or academic-sign-off workflows.

## 3. Authoritative Conceptual Records

These contracts define responsibilities before physical schema design. They are not approved table names or migrations:

- `ProgramAuthority`
- `Course`
- `CourseRevision`
- Shared Clinic 5 `CourseAcademicClassification`
- `CourseRequisite`
- `CourseEquivalency`
- `WeeklyMeetingRequirement`
- `CurriculumVersion`
- `CurriculumEntry`
- `TermCalendarPackage`
- `OperationalWindow`
- `WeeklyTeachingGrid`
- `DatedException`
- `TermCohort`
- `ClassOffering`
- `ClassOfferingCohort`
- `FacultyTeachingEligibility`
- `FacultyTermCapacity`
- `FacultyAvailabilityDeclaration`
- `Room`
- `ResourceUnavailability`
- `SchedulingCommitment`
- `ScheduleGenerationRun`
- `SolverSnapshot`
- `CandidateTimetable`
- `CandidateMeetingCorrection`
- `PublishedTimetableVersion`
- `PublishedMeeting`
- `TimetableRevision`
- Shared Clinic 4 `PublishedClassAvailabilityProjection`
- Clinic 4 `UnmetClassDemandProjection`

No public HTTP API, generic policy DSL, universal override record, generic state-machine builder, or configurable constraint engine is introduced.

## 4. End-to-End Narrative

1. Registrar records the regulator and institutional authority for each recognized program.
2. Registrar records or imports a Draft curriculum from an externally approved source, resolves every blocking finding, records the authority, and activates the immutable version.
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

### 5.3 Courses without recurring meetings

A Course Revision owns zero or more approved weekly meeting requirements.

- Ordinary lectures and laboratories use bounded recurring meeting blocks.
- An internship or externally arranged practicum stays in the curriculum, unit total, later enrollment, COR, grades, and completion records without invented classroom meetings.
- A course with no genuine recurring meeting is labeled **Externally arranged — no recurring master-timetable meeting** and is excluded from CP-SAT.
- A genuine recurring seminar may have its own meeting block without scheduling all workplace hours.

Clinic 3 does not manage placement sites, companies, supervisors, workplace attendance, or practicum logbooks.

## 6. Term Calendar Package

Academic Head approves the institutional calendar outside TALA. Registrar records the authority and evidence, activates the package, and operates its windows. Academic Head receives read-only oversight. TALA adds no duplicate approval action.

Every First, Second, or institutionally approved Special Term begins through an explicitly created `TermCalendarPackage`. TALA never automatically creates or clones the next term, and `Special Term` is a controlled term type rather than a separate Summer subsystem.

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

Application dates remain in Admission Cycles. Payment dates remain in accounts. Examination dates are informational; Clinic 3 includes no examination scheduler. The neutral `Enrollment` operational window owns approved opening and closing dates only. Clinic 4 assigns its bounded applicability to Ready Applicants, Standard continuing Students, Individually Advised or exception cases, or all otherwise eligible learners. The `Grade Entry` window owns the definite Faculty submission period and due date consumed by Clinic 5; Clinic 5 owns late-grade authority, INC deadlines, release, and correction behavior. No programmable audience rules are introduced. Activating a term does not open enrollment; Clinic 4 also requires a published timetable and its remaining readiness facts.

### 6.2 Readiness and date effects

Activation is blocked by missing authority, invalid term/class bounds, incomplete operational windows, an empty or contradictory teaching grid, invalid breaks, or conflicting dated exceptions. Readiness presents only failed checks; success collapses to **All required checks passed**.

A dated exception affects the applicable dated occurrences without rewriting the recurring published meeting pattern. Faculty and room unavailability remain teaching-resource records, not calendar events.

### 6.3 Consolidated Readiness Matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Program and curriculum authority | Program Authority, Course Revisions, active Curriculum Version | Registrar | Current authority and immutable applicable version are complete | Term classes/generation blocked | Confirm classes; generate; publish | Correct Draft authority/version and activate an approved replacement |
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

Clinic 3 exposes curriculum term totals, requisites, approved equivalencies, published Class Offerings, capacities, and official meeting times through one `PublishedClassAvailabilityProjection`.

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

The immutable Published Timetable and its role-filtered print/save-as-PDF view are Clinic 3's official output. Output generation must use one published version, show its authority and generation context, and fail without producing a partial or official-looking artifact when the source is stale or unavailable.

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

The curriculum sheet groups by curriculum year and term. It shows course code/title, units, prerequisites/corequisites, scheduling treatment, weekly meeting pattern, modes, room needs, source, and readiness. Draft rows may be edited; active records are read-only.

### 15.2 Term Planning workbench

One selected-term header shows term identity, state, current readiness, governing authority, current published version, and one state-appropriate primary action.

The workbench has five tabs:

1. **Overview** — official dates, operational windows, weekly grid, exceptions, authority evidence, and failed-first readiness.
2. **Cohorts & Classes** — forecast and confirmed cohorts, Class Offerings, sharing, capacity, source, pattern, mode, state, readiness, and contextual filters/actions.
3. **Teaching Resources** — Faculty declaration and capacity, eligibility, assigned demand, rooms, hard unavailability, blockers, and bounded commitments.
4. **Generate & Review** — result meaning, owner, next action, quality measures, filterable weekly view, accessible table alternative, warnings or failure diagnostics, and candidate actions.
5. **Published Timetable** — current immutable version, authority, publication time, filtered official timetable, print/save-as-PDF, revision impact, and superseded history.

Only the weekly timetable view is a justified custom component. Native Filament Tables, Sections, Infolists, Forms, Action Groups, filter panels, and active indicators own the rest. There are no custom column-header filter dropdowns, drag-and-drop timetable editor, generic Academic Settings surface, or peer navigation maze.

### 15.3 Role projections

- Registrar: complete editable planning and publication authority.
- Academic Head: read-only calendar, curriculum, readiness, candidate evidence, and published timetable oversight.
- Faculty: one availability declaration, assigned official schedule, and affected revision history.
- Student: confirmed schedule only after Clinic 4 placement and official enrollment.
- System Administrator: solver-related System Health and technical evidence, with no academic authority.
- Applicant, Accounting, and Public: no Clinic 3 timetable authority or master-schedule access.

On mobile, curriculum rows and class/resource tables use responsive stacked layouts and the weekly view becomes day-by-day/list presentation. Secondary actions remain in Action Groups. Status meaning never depends on color alone.

## 16. Salvage Disposition

| Verdict | Clinic 3 disposition |
| --- | --- |
| Retain when conforming | Immutable course/curriculum foundations, term records, Faculty/room sources, CP-SAT integration, immutable snapshots, status distinctions, candidate validation, candidate/published separation, revision evidence, queued mail, and native Filament foundations |
| Simplify | Calendar into Term Calendar Package; curricula into one grouped sheet/import; Faculty availability into one declaration; class planning into Term Cohort plus Class Offering |
| Replace | Term Offering → Section → Delivery Group layering; equal-weight objective; generic constraint profiles; technical run-first UI; unrestricted manual override; automatic handover/publication assumptions |
| Remove after dependency reconciliation | Configurable granularity, assumed day/hour values, preferred times, HyFlex, universal ceilings, separate special-offering workflows, generic approval/policy/override engines, duplicated states, exam scheduling, and automatic term cloning |
| Quarantine | Existing columns, models, services, routes, resources, and tests remain untouched until later authority-approved task derivation maps every consumer |

## 17. Acceptance and Defense Scenarios

The future vertical implementation must prove:

- Program authority and externally approved curriculum activation.
- Duplicate or inconsistent curriculum import findings.
- Simple prerequisites, equivalencies, and circular-reference prevention.
- Internship retained without an invented recurring timetable.
- Explicit First, Second, and approved Special Term creation.
- One Special Term that continues through published classes, Clinic 4 registration, Clinic 6 assessment/coverage, Clinic 4 official enrollment, and Clinic 5 released-result projections using the same references.
- Calendar-readiness failures and successful activation.
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

Realistic demonstration data must include at least one externally arranged practicum, one shared class, one Additional class, multiple meeting patterns, mixed On-campus/Online schedules, a Faculty late correction, a room conflict, every solver-result family, a bounded candidate correction, a first publication, and an affected-role revision. Demonstration data is not policy authority.

### 17.1 Synthetic Demonstration Data

| Reference | Synthetic record | Demonstrated evidence |
|---|---|---|
| `CUR-BSHM-2026` | Active BSHM Curriculum Version with lecture, laboratory, and `PRACT-401` externally arranged practicum | Immutable authority, grouped curriculum ordering, no invented practicum meeting |
| `TERM-2026-1` | Active First Term package with Mon–Sat grid, approved break, holiday, Enrollment and Grade Entry windows | Failed then passing activation readiness and dated-exception behavior |
| `TERM-2026-ST` | Approved Special Term package with particular schedule, attributable class-hour/class-day basis, Enrollment and Grade Entry windows | Missing authority blocks activation; no Summer-specific unit or timetable default is used |
| `COH-BSHM-1A/1B` | Separate confirmed cohorts with one approved shared general-education class | Shared class without cohort merge |
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
| Registrar; Draft `CUR-BSHM-2026` | Catalog & Curricula | Resolve import findings and activate | Grouped sheet, sources, blockers, authority, immutable active state | Clinic 4/5 can consume only the active version | Curriculum activation evidence | Circular requisite or missing authority blocks activation | No generic Settings or peer-resource maze is used |
| Registrar; Draft `TERM-2026-1` | Term Planning Overview | Attempt activation, correct window/grid conflict, activate | Failed-first source/owner/recovery then all-passed summary | Downstream clinics see owned window projections | Active Term Calendar Package | Contradictory exception blocks activation | Dates are explicit and no term is auto-cloned |
| Registrar and Faculty `FAC-ADA` | Cohorts & Classes / Teaching Resources / My Availability | Confirm classes, request declaration, submit late correction | Demand sources, separate cohorts, blockers, declaration history | Registrar readiness updates; Faculty sees only own facts | Complete scheduling inputs | Room or Faculty gap remains linked to owner/source | Every recurring class is attributable and ready |
| Registrar; `RUN-TECH` then `RUN-INF` | Generate & Review | Generate, inspect technical failure, then infeasible diagnostics | Distinct result meaning, safe reason, owner, source, next action | System Administrator sees technical health only | Retained failed runs, no candidate | Retry unavailable until service/source recovery | Failure is never mislabeled as a valid candidate |
| Registrar; `RUN-FEA` / `CAND-2026-01` | Generate & Review | Open valid candidate, make bounded correction, revalidate | Quality hierarchy, weekly and accessible table views, hard-rule result | Academic Head sees read-only evidence; Faculty/Students see nothing yet | Valid complete candidate | Invalid correction is rejected without waiver | Candidate remains non-official until publication |
| Registrar; external sign-off recorded | Published Timetable | Publish `PUB-2026-01`, filter/print official view | Authority, version, publication time, immutable meetings | Assigned Faculty sees official schedule; Clinic 4 receives availability | Official timetable print/save-as-PDF | Missing/stale sign-off blocks publication | Published data exactly matches validated candidate |
| Registrar with affected Clinic 4 placements | Published Timetable revision | Record source change, resolve impact, publish `PUB-2026-02` | Complete impact, validation, superseded history | Affected Faculty and enrolled Students receive one shared event | New official version and revision evidence | Unresolved placement or invalid timetable blocks publication | No meeting is edited in place and no duplicate email fires |
| Registrar; Draft `TERM-2026-ST` | Term Planning → Cohorts & Classes → Generate & Review | Fail missing calendar/Additional authority, record valid authority, confirm `CLS-ITE3-ST-A` and `CLS-IT201-ST-R`, then publish | Particular schedule, class-hour/class-day evidence, offering sources, resources, candidate, and official version | Clinic 4 receives the two published classes under the same Special Term reference | Published Special Term timetable | Missing authority, resource conflict, or invalid candidate blocks the next action | No Summer scheduler, tutorial workflow, universal unit cap, or learner classification appears |

## 18. Future Implementation Gate — Not a Task Plan

This PRD owns Clinic 3 behavior, UI, conceptual data, acceptance, exclusions, and salvage classification only. It is not a migration design, task breakdown, solver implementation contract, or permission to modify the application.

Clinics 1–6, canonical `00`–`06` consolidation, and the final cross-module review are complete. A journey-complete implementation task may now be derived only through a separately approved plan; this PRD does not authorize migration design, application change, tracker mutation, commit, or synchronization.

## 19. Assumptions and Closure Record

- TALA targets a normally recognized and authorized Philippine college.
- No approved Servitech institutional handbook has been supplied.
- Existing business documents remain valuable but unverified evidence.
- Institutional curriculum, calendar, staffing, workload, exceptional-class, timetable-sign-off, and overload decisions occur externally and are recorded by TALA.
- The existing application and schema remain intact until later authority-approved reconciliation.

Clinic 3 is approved and has passed the complete-authority review. Its complete-clinic checklist is satisfied through this PRD and its Clinic 3 UI authority, every automatic rule remains factually traceable, and the settled Clinic 3↔4 handoff is preserved. No material Clinic 3 product question remains open. Approval authorizes later implementation-task derivation only and does not authorize implementation.
