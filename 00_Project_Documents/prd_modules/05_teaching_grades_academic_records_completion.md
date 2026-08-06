# PRD 05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion

> **Authority status — Clinic 5 approved; complete-authority review passed.** This is the canonical unified Clinic 5 journey authority. It replaces the product authority formerly split across Grades, Student Lifecycle, and the academic-record/completion portions of Student Hub. Those inputs are preserved in `_legacy/` as non-authoritative salvage evidence. Complete-set approval authorizes later implementation-task derivation only; it does not authorize application changes, schema changes, migration work, or deployment.

## 1. Purpose and successful outcome

Clinic 5 defines one complete academic-record journey:

`Official roster → final-grade submission → Registrar release → academic record/GWA → curriculum evaluation → progress decision → lifecycle effects → completion/conferral → unofficial record and TOR`

The successful outcome is one coherent, append-only academic history whose released results, curriculum evaluation, lifecycle effects, completion evidence, and official outputs remain consistent across Faculty, Registrar, Academic Head, and Student projections.

TALA records final course results. It does not recreate Faculty gradebooks, period-grade formulas, attendance tracking, appeals, clearance-office workflows, document-request fulfillment, diploma production, or ceremony management.

## 2. Evidence and institutional boundary

This contract is grounded in:

- The supplied [Servitech final-grade workbook](../archive/raw-source-files/MS.-OLIMBERIO-Blended-Online.Final-Grade-1.xlsx), which evidences the final-grade vocabulary, including `4.00` as passing.
- [Batas Pambansa Blg. 232](https://lawphil.net/statutes/bataspam/bp1982/bp_232_1982.html), which protects access to school records and requires official records such as grades and transcripts within thirty days of request.
- [CHED eCAV requirements](https://ecav.ched.gov.ph/requirements), which require an official TOR used for CAV to be certified true and signed by the current HEI Registrar but do not supply a universal visual template.
- The [PUP Student Handbook](https://www.pup.edu.ph/studentservices/files/ThePUPStudentHandbook2014.pdf) as a mature Philippine reference for INC completion and the transparent academic-progress profile.
- The [UP academic-policy reference](https://osu.up.edu.ph/wp-content/uploads/2022/04/1309.FINALE.pdf) as a mature Philippine example of excluding PE and NSTP from GWA; Servitech's exclusion remains institution-specific and client-confirmed.
- [PeopleSoft grade-roster self-service](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/campus-self-service/entering-grades-through-self-service.html) as a mature-system benchmark for roster submission and controlled release, not Philippine policy.

| Institutional responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Calculate period grades, raw scores, and final result | Designated Faculty through the approved external process | Record one controlled final result per official learner registration |
| Release official grades | Registrar | Validate and release the complete submitted roster or return specified rows |
| Decide late submission, correction, progress consequence, lifecycle change, credit/equivalency, honor, or conferral | Authorized institution outside TALA | Record the approved result, authority, evidence, effective date, and system effect |
| Maintain the academic record and derive GWA/evaluation | Registrar-owned TALA records | Deterministic projections from released and approved facts |
| Request, pay for, certify, sign, seal, deliver, or authenticate a TOR | External Registrar/Accounting process | Record request evidence, generate an immutable snapshot, and record issuance |
| Produce diplomas, manage ceremonies, or calculate honors | External institutional process | No workflow; optionally record an approved honor with the conferral |

This is a **Decision then record** product. TALA does not turn institutional academic judgment into a generic approval engine.

### 2.1 Benchmark result

- **Domain checked:** final-result recording, complete-roster submission and release, academic-record projections, lifecycle/completion, and controlled transcript issuance.
- **Sources consulted:** the Servitech final-grade workbook, Batas Pambansa Blg. 232, CHED eCAV requirements, the cited PUP and UP academic references, PeopleSoft grade-roster self-service, the legacy TALA PRDs, and bounded code/schema salvage evidence.
- **Confirmed:** a lean TALA journey can accept one final result per official registration, release a complete roster under Registrar control, preserve immutable academic events, derive GWA and curriculum evaluation, and generate a controlled transcript snapshot without recreating a Faculty gradebook or document-request office.
- **Rejected or bounded:** another institution's policy is not Servitech authority; period-grade formulas, attendance, generic progress engines, editable transcript templates, clearance routing, delivery, and diploma production remain outside TALA.
- **Alignment:** the PRD adopts the controlled roster and read-mostly academic-record patterns while keeping institution-specific decisions externally authorized and recorded. No benchmark overrides the accepted TALA authority.

## 3. Final-grade and roster authority

### 3.1 Controlled final results

Faculty calculates grades outside TALA using the institution's workbook or approved process. The designated submitting Faculty enters exactly one final result:

- `1.00`, `1.25`, `1.50`, `1.75`, `2.00`, `2.25`, `2.50`, `2.75`, `3.00`, `4.00`, `5.00`; or
- `INC`.

`1.00–4.00` is passing and satisfies the course. `5.00` is failed and does not satisfy the course. `INC` is temporary and unresolved.

Preliminary, Midterm, Final-period percentages, quizzes, requirements, examinations, attendance, raw scores, and formulas remain outside TALA. `P` is not an official mark. A blank row is unfinished workflow state and cannot be released. Course Drop, full withdrawal, and approved credit results come from their owning Registrar actions and cannot be entered by Faculty as grades.

The final-grade vocabulary is code-owned through an effective `FinalGradePolicyVersion`; it is not a runtime formula or grading-scale builder.

### 3.2 Official roster

One `GradeRoster` exists per official `ClassOffering`, including internships or externally arranged courses with no recurring timetable meeting.

- Only officially enrolled learners appear.
- One designated Faculty owns submission; other assigned Faculty may view.
- Replacing the submitter requires an authorized teaching-assignment change.
- No collaborative gradebook is created.
- Roster state is `Draft`, `Submitted`, `Returned`, or `Released`.
- A roster is released completely or not at all.
- Registrar may return specified rows with one consolidated explanation, but cannot casually edit Faculty-entered results.
- Clinic 4 additions, removals, adjustments, or Course Drops synchronize the roster before release. A submitted roster affected by a material enrollment change becomes action-needed and must be resubmitted.
- Every released result is an immutable `OfficialGradeEvent`.

### 3.3 Submission and release window

The Term Calendar Package supplies a definite Grade Entry window and due date. Faculty may save Draft entries during the window and submit only when every required row has a valid final result.

After the deadline, submission requires `LateGradeAuthorization` with the class, responsible Faculty, reason, authority, and authorized deadline. Late submission is separate from correction of an already released result.

Registrar release rechecks roster membership, submitter authority, completeness, current enrollment effects, and stale versions. It publishes the official results atomically and queues the grade-release notice only after commit.

## 4. GWA, INC, and corrections

### 4.1 GWA

TALA calculates:

`GWA = Σ(final numeric grade × official course units) ÷ Σ(included official course units)`

Rules:

- Term GWA covers one term. Cumulative GWA covers all included attempts through the selected complete term.
- Every released numeric attempt counts. A later passing retake may satisfy the curriculum but never erases the earlier failed attempt.
- There is no independent maximum retake counter; continuation depends on the confirmed academic-enrollment effect.
- PE and NSTP courses—including CWTS, LTS, and ROTC equivalents—are excluded under the client-confirmed Servitech rule.
- Excluded courses remain curriculum and TOR requirements.
- `INC`, Course Drop/full-withdrawal results, and nonnumeric approved credit are excluded.
- TALA calculates at full precision and displays two decimals without intermediate rounding.
- A correction replaces the superseded value in effective calculations while preserving event history.
- GWA appears in Student Academics and Registrar academic-record views, not in the standard TOR.

`CourseAcademicClassification` identifies which effective course revisions are PE, NSTP/CWTS/LTS/ROTC, or ordinary academic courses. `GwaPolicyVersion` records the institution-specific inclusion rule without providing an arbitrary GWA editor.

When any GWA-eligible course has unresolved `INC`:

- current-term GWA is withheld;
- current cumulative GWA is not presented as final;
- the last complete cumulative GWA remains visible as **Through [term]**; and
- TALA shows **Current GWA pending — incomplete grade unresolved**.

Resolution or lapse recalculates the original term and every later cumulative projection.

### 4.2 Incomplete result

`INC` requires a short Faculty completion note. Its deadline is one academic year from the original term's official end.

- It does not satisfy a prerequisite and is not treated as failure while unresolved.
- Faculty records the completion grade; Registrar releases the superseding result.
- An unresolved `INC` automatically becomes `5.00` after the accepted deadline.
- The original `INC`, completion note, deadline, and resolution/lapse history remain visible to authorized roles.
- The Student sees the safe status, deadline, responsible office, and required next action, not private evidence.

### 4.3 Grade correction

A correction to a released result requires an externally authorized decision containing reason, authority, optional evidence reference, actor, and date.

- TALA has no hard technical correction cutoff.
- A correction outside the normal institutional period is visibly late but remains recordable when authorized.
- The correction appends a superseding result; it never mutates the original event.
- GWA, curriculum evaluation, progress recommendations, and completion readiness recalculate.
- Earlier consequential decisions remain historical. If the recommendation changes, a new decision is required.
- Any affected active Clinic 4 Registration Case enters Registrar review. TALA never silently adds or removes a course.
- An already issued transcript snapshot remains immutable and is marked superseded; future issuance uses a new snapshot.

## 5. Official-result and curriculum-evaluation contract

### 5.1 Cross-clinic result projection

Clinic 5 exposes `OfficialCourseResultProjection` as:

- `Satisfied`
- `Unsatisfied`
- `Incomplete`
- `WithdrawnOrDropped`
- `ApprovedCredit`
- `Pending`, only as an unreleased internal projection

Clinic 4 consumes the released facts and the confirmed `AcademicEnrollmentEffect`:

- `Allowed` — ordinary registration may continue.
- `AdvisingRequired` — Registrar must prepare an Individually Advised proposal.
- `Blocked` — placement and finalization cannot proceed.
- `PendingDecision` — Clinic 4 waits for Clinic 5's authoritative outcome.

Draft and submitted-but-unreleased grades never affect registration. A failed result alone does not automatically impose probation, dismissal, or a reduced load.

### 5.2 Curriculum evaluation

The authoritative `CurriculumEvaluation` derives from:

- the effective-dated Program and Curriculum Version;
- every released course attempt;
- approved transfer-credit and equivalency mappings;
- current official enrollment; and
- approved shift, deficiency, bridging, and old-curriculum mappings.

It shows every required course, every official attempt and grade, credited mapping, current enrollment, prerequisite relationship, attempted units, earned units, remaining units, and unresolved deficiency.

- A later passing attempt may satisfy a requirement, but every attempt remains in the academic record and TOR.
- A credit or equivalency satisfies only its approved mapped requirement.
- Credits cannot be double-counted unless the approved curriculum explicitly permits it.
- TALA has no learner what-if audit, speculative graduation date, generic substitution builder, or automatic equivalency decision.

## 6. Academic progress and enrollment effect

`AcademicProgressPolicyVersion` records the transparent capstone reference profile. It produces a recommendation only from fully resolved, released academic results:

| Failed academic units in the evaluated period | Recommendation |
| --- | --- |
| None | `Good` |
| Up to 15% | `Warning` |
| 16–30% | `Warning` with recommended three-unit reduction |
| 31–50% | `Probation` with recommended six-unit reduction |
| 51–75% | `Ineligible` recommendation for the current program |
| Above 75% | `Ineligible` recommendation for institutional continuation |

Successive-warning and probation rules follow the cited PUP reference profile. PE, NSTP, and valid nonnumeric outcomes are excluded from this failed-academic-unit calculation. An unresolved `INC` leaves the consequential assessment pending rather than treating the learner as failed.

`Good` requires no human confirmation. `Warning`, `Probation`, load reduction, and either `Ineligible` outcome require an authorized institutional decision recorded by Registrar with authority, safe explanation, effective term, and `AcademicEnrollmentEffect`.

This profile is not represented as CHED-wide policy. It is a transparent capstone reference that must be institutionally adopted or replaced before production deployment. TALA has no generic progress-policy DSL.

## 7. Lifecycle, graduation, and conferral

### 7.1 Lifecycle events

Append-only `StudentLifecycleEvent` records derive:

- `Active`
- `OnLeave`
- `Withdrawn`
- `TransferredOut`
- `Completed`

Clinic 5 records externally authorized leave, full withdrawal, return/reactivation, transfer out, program shift, and degree conferral. Course Drop remains Clinic 4.

- Lifecycle changes never delete enrollment or grade history.
- Released grades survive later withdrawal or transfer.
- Unreleased active courses receive only the externally authorized withdrawal effect.
- Seats, schedule bindings, rosters, COR, and Accounting-review projections update atomically where the event affects the current term.
- TALA infers no refund, penalty, or financial forfeiture.
- A shift is effective-dated and never rewrites earlier program history.
- Reactivation returns the Student to `Active` but creates no registration, class placement, or seat.
- Lifecycle state never disables historical Student-portal access; account disablement remains Clinic 1.
- Appeals, grievances, disciplinary proceedings, and attendance investigations remain outside TALA.

### 7.2 Graduation application and readiness

**Apply for graduation** appears when every curriculum requirement is either already satisfied/credited or officially enrolled in the Student's current final term.

Missing, failed, dropped, unregistered, or unresolved requirements from an earlier term block application. The application records intent only and is not proof of completion or graduation.

`CompletionReadiness` derives:

- `NotEligible`
- `EligibleToApply`
- `AwaitingResultsOrClearance`
- `ReadyForConferral`
- `Conferred`

### 7.3 Conferral

Conferral requires:

- complete curriculum satisfaction;
- no unresolved `INC` or pending official result;
- a Graduation Application;
- required source-owned clearance results; and
- recorded external conferral authority and date.

Registrar conferral creates an immutable Degree Conferral, final curriculum-evaluation snapshot, and `Completed` lifecycle event. An externally approved honor may be recorded, but TALA does not calculate honors or manage ceremonies, diplomas, seals, or Special Order processing.

## 8. Academic record and transcript authority

### 8.1 Student Academics

Student Academics presents one vertical reading order:

1. Current academic-record status, responsible owner, and next action.
2. Released grades grouped by term.
3. Term/cumulative GWA or the explicit pending-GWA state.
4. Curriculum evaluation.
5. Confirmed academic progress and safe explanation.
6. Attempted, earned, and remaining units.
7. Completion readiness.
8. Correction, INC, and lifecycle history.

Students may print an **Unofficial — for student reference** record. They cannot issue or self-download an official TOR.

### 8.2 Proposed and institution-approved TOR format

The supplied Servitech TOR format is unavailable for reuse because it is covered by the client's NDA. TALA therefore uses an original code-owned demonstration layout labelled **Proposed institutional format — Not for official issuance**. It supports current, withdrawn, transferred-out, and completed Students. Previewing the proposed layout does not make it an approved institutional form; official issuance requires a separately recorded institution-approved `TranscriptTemplateVersion`.

The TOR contains:

- institution and document identity;
- reference, issue date, and page numbering;
- complete legal name and Student number;
- program/major and applicable department;
- birth date and entry/admission date;
- conferral date when applicable;
- chronological academic-year and term groups;
- course code, historical title snapshot, lecture/laboratory hours, units, official grade or mark, and remarks;
- every official attempt, including failures and retakes;
- approved-credit/equivalency annotations;
- term total units, total earned units, grading legend, certification statement, Registrar area, and seal area.

It excludes GWA, LRN, period grades, raw scores, Faculty, schedules, financial balances, receipt details, service restrictions, and audit history.

### 8.3 Issuance boundary

1. Request, payment, delivery, claiming, CAV, signature, seal, and certification processes remain external.
2. Registrar records the external request date/reference; TALA derives the 30-day statutory due date.
3. Clinic 6 supplies `OfficialOutputPaymentClearance` as `Cleared`, `NotRequired`, or `ActionNeeded`.
4. TALA may generate an immutable proposed preview and `TranscriptSnapshot` from the effective record, clearly labelled as not for official issuance.
5. Registrar may record issuance only when the institution has approved the exact code-owned template version and institutional certification has occurred externally.
6. An issuance mistake creates a voided version and replacement.
7. A later legitimate grade correction preserves but marks the historical snapshot superseded; future issuance uses a new snapshot.

### 8.4 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| `GradeRoster: Draft` | Save or submit complete roster | Designated Faculty | Current submitting-Faculty assignment | Official class/membership; open window or late authority; every required row valid | Submitted immutable roster version | Submission freezes the review version | Registrar sees one current submitted version; Students see no result |
| `GradeRoster: Submitted` | Release or return specified rows | Registrar | Grade-release authority | Membership, enrollment effects, submitter, completeness, and version revalidated | Immutable grade events or one consolidated return explanation | Release cannot be edited; return changes no official result | Students see only released events; Faculty sees returned rows |
| `GradeRoster: Returned` | Correct specified rows and resubmit | Designated Faculty | Current submitting-Faculty assignment | Return explanation visible; membership/version current | Next reviewable roster version | Prior return/submission history retained | Registrar sees successor version |
| `GradeRoster: Released` | View or begin separately authorized correction | Authorized role / Registrar | Record access or correction authority | Direct edit and partial release unavailable | Read-only released record or correction case | Released events immutable; correction appends successor | Student/Clinic 4 consume only released projection |
| Unresolved `INC` | Record completion, release result, or apply lapse | Faculty, Registrar, or system at accepted deadline | Faculty completion plus Registrar release, or code-owned lapse rule | Original note/deadline; valid completion result or elapsed deadline | Superseding result and recalculated projections | Original `INC` remains history | Student, curriculum evaluation, GWA, and Clinic 4 refresh |
| Progress recommendation | Record authorized consequence | Registrar | Academic-progress decision authority | Resolved released results, effective policy, authority, explanation, term | Confirmed `AcademicEnrollmentEffect` | Recommendation and decision remain distinct history | Clinic 4 receives confirmed effect only |
| Lifecycle change | Record externally authorized result | Registrar | Lifecycle-result authority | Authority, effective date, current facts, current-term impact preview | Append-only lifecycle event | History never deleted | Student, Clinic 4, rosters, COR/account review receive bounded effects |
| Graduation application | Apply | Eligible Student | Own record and application eligibility | Requirements satisfied, credited, or officially enrolled in final term | Graduation intent | Does not confer degree or prove completion | Registrar receives completion work item |
| `ReadyForConferral` | Record conferral | Registrar | Conferral-recording authority | Complete curriculum, no unresolved result, source clearances, external authority/date | Conferral/evaluation snapshots and `Completed` event | Immutable; later correction uses successor evidence | Student sees conferral; downstream lifecycle projection updates |
| TOR proposed preview | Generate preview | Registrar | Transcript-preview authority | Releasable record, request reference/date, Clinic 6 clearance not `ActionNeeded`, proposed layout available | Immutable preview labelled not for official issuance | Cannot be represented as issued | Registrar sees preview/history; Student receives no official-download action |
| TOR issuance | Record issuance or later void/replace | Registrar | TOR issuance authority | Current snapshot, institution-approved template version, external certification, Clinic 6 clearance | Issued snapshot or append-only void/replacement | Issuance freezes snapshot; later supersession never overwrites | Authorized history shows Issued/Voided/Superseded exactly |

### 8.5 Readiness matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Roster submission | Clinic 3 class/assignment, Clinic 4 membership, Grade Entry window, current rows | Faculty submits; Clinics 3/4 own sources | Designated submitter and one valid result per required row within window/authority | Submission blocked with row-level action needed | Submit roster | Correct rows or owning assignment/window/membership source |
| Roster release | Current submitted version and official membership | Registrar | Version current, membership unchanged, all rows valid | Release blocked; specified rows may be returned | Release roster | Resolve stale enrollment evidence or return exact rows |
| INC completion | Original `INC`, note/deadline, Faculty result, release authority | Faculty and Registrar | Valid completion result or accepted lapse condition | Superseding result unavailable | Release INC completion | Supply result or correct stale authority; preserve original event |
| Progress consequence | Released results, resolved INC, effective policy, authority | Registrar | Recommendation complete and authorized decision recorded | `PendingDecision`; no Clinic 4 effect | Record progress effect | Record the external authorized decision and safe explanation |
| Lifecycle result | External authority and current-state/current-term impact | Registrar | Authority/effective date current and impact preview available | No lifecycle mutation | Record lifecycle result | Correct authority or refresh impact evidence |
| Conferral | Graduation application, evaluation, results, source clearances, external authority | Registrar and source owners | Curriculum satisfied, no unresolved result, every clearance ready | `AwaitingResultsOrClearance` | Record conferral | Resolve the named source; never override readiness locally |
| TOR proposed preview | Academic snapshot, request reference/date, Clinic 6 clearance, proposed layout | Registrar/Accounting | Record releasable and clearance not `ActionNeeded` | Preview unavailable with named source | Generate proposed preview | Correct source or Clinic 6 clearance |
| TOR issuance | Proposed/current snapshot, institution-approved template version, Clinic 6 clearance, external certification | Registrar, institution, Accounting | Exact template approved and certification/clearance complete | **Record issuance** unavailable | Record issuance | Approve the code-owned template externally or complete named source; do not relabel a proposed preview |

## 9. Exact UI authority

The complete low-fidelity wireframes, responsive variants, and shared Student Home/Academic Oversight coverage live in the Clinic 5 and shared-shell sections of the [UI Surface Blueprint](../ui_surface_blueprint.md). This PRD owns their product content, actions, and authorization boundaries.

### 9.1 Faculty — Grade Rosters

The queue shows:

- course and class reference;
- program/cohort;
- official learner count;
- completed-result count;
- submission deadline;
- state, owner, and next action.

The roster shows:

- Student number;
- legal name;
- official enrollment state;
- controlled final-grade/INC selector;
- derived academic result; and
- validation or lifecycle explanation.

The primary actions are **Save draft** and **Submit complete roster**. Returned-row correction, history, and evidence remain secondary actions. Native Filament tables and forms are used; spreadsheet import and a custom gradebook are excluded from MVP.

### 9.2 Registrar — Grades & Completion

One workbench contains:

- Grade Review
- INC & Corrections
- Academic Progress
- Lifecycle
- Completion & TOR
- History

Search supports Student number, legal name, course/class reference, and TOR reference. Native filters cover term, program, course, Faculty, roster state, deadline, result, INC/correction state, progress, lifecycle, completion readiness, and relevant date ranges.

Each record leads with state, owner, next action, and one state-appropriate primary action, then presents the authoritative academic facts and collapsed evidence/history. There is no bulk release, bulk correction, bulk consequential decision, bulk conferral, or bulk TOR issuance.

### 9.3 Role projections and interaction rules

- Student sees only their released record, GWA, evaluation, confirmed progress, completion, and unofficial output.
- Academic Head sees read-only academic oversight and recorded decision evidence.
- Accounting sees only Clinic 6 payment/clearance responsibility.
- System Administrator sees queue, email, and System Health evidence without academic authority.
- Applicant and Public receive no Clinic 5 access.

Mobile layouts use labelled stacked rows, preserve reading order, keep the primary action reachable, and move secondary actions into Action Groups. Loading, empty, stale-record, inaccessible, expired-session, validation, concurrency, and technical-failure states identify the responsible owner and safe recovery action. Meaning never depends on color alone.

## 10. Communication contract

Queued, idempotent emails are limited to:

| Trigger | Recipient | Safe contents | Source / idempotency key | Failure behavior | Excluded notifications |
|---|---|---|---|---|---|
| Grade roster requires submission | Designated Faculty | Class reference, due date, action, authenticated link | Roster assignment/window plus Faculty identity | Workspace remains authoritative; authorized resend recorded | No draft-save or recurring reminder mail |
| Registrar returns specified rows | Designated Faculty | Class reference and instruction to review authenticated explanation | Returned roster version plus Faculty identity | Return remains valid; mail failure does not reopen/release | No row values or private explanation in email |
| Registrar releases roster | Affected Students | Official results available; secure link | Released roster version plus Student identity | Results remain official in Student Academics | No grade value or attachment |
| `INC` requires action or approaches accepted deadline | Student and designated Faculty when needed | Safe state, deadline, owner, authenticated link | INC event/deadline plus recipient identity | Deadline/state remain authoritative | No recurring reminder loop or grade value |
| `INC` resolved or lapsed | Student | Outcome available in Student Academics; secure link | Superseding result event plus Student identity | Result/recalculation remain committed | No grade value or attachment |
| Authorized correction released | Affected Student | Academic record updated; secure history link | Correction release event plus Student identity | Correction remains committed | No old/new grade value or attachment |
| Progress or lifecycle decision recorded | Affected Student | Safe outcome, owner, next action, secure link | Confirmed decision/event plus Student identity | Decision remains effective | No sensitive reason or unrelated record detail |
| Completion requires action | Student | Missing source, owner, next action, secure link | Completion-readiness version plus Student identity | Readiness remains source-derived | No automated reminder loop |
| Conferral recorded | Student | Conferral available in authenticated record | Conferral reference plus Student identity | Conferral remains committed | No diploma, TOR, grade value, or attachment |

Routine saves, calculations, queue movement, GWA recalculation, page activity, and recurring reminders send no email. Mail failure never rolls back an academic transaction; the authenticated workspace remains authoritative and retains authorized resend evidence.

## 11. Conceptual contracts

These names define responsibilities before physical schema design. No public HTTP API or physical table design is approved here:

- `FinalGradePolicyVersion`
- `GwaPolicyVersion`
- `CourseAcademicClassification`
- `GradeRoster`
- `GradeRosterEntry`
- `OfficialGradeEvent`
- `LateGradeAuthorization`
- `IncompleteResolution`
- `GradeCorrectionDecision`
- `StudentGwaProjection`
- `OfficialCourseResultProjection`
- `CurriculumEvaluation`
- `CurriculumRequirementResult`
- `AcademicProgressPolicyVersion`
- `AcademicProgressAssessment`
- `AcademicProgressDecision`
- `AcademicEnrollmentEffect`
- `StudentLifecycleEvent`
- `GraduationApplication`
- `CompletionReadiness`
- `DegreeConferral`
- `AcademicHonorRecord`
- `TranscriptTemplateVersion`
- `TranscriptSnapshot`
- `TranscriptIssuance`
- Clinic 6 `OfficialOutputPaymentClearance`

There is no public API, gradebook engine, grading DSL, GWA editor, degree-audit rules engine, lifecycle state machine, generic override record, transcript-template editor, or global hold engine.

### 11.1 Authoritative data ownership

| Conceptual record family | Minimum authoritative facts | Owner and mutability |
| --- | --- | --- |
| Policy versions | Version, effective scope/dates, accepted vocabulary or calculation rule, authority reference | Registrar-governed; immutable after use |
| Roster and entry | Term, official Class Offering, official registrations, designated submitter, due date, row result/note, roster state and version | Faculty edits Draft/Returned rows; Registrar controls release; released versions are immutable |
| Official result, INC, and correction | Registration, result, release event/time/actor, original INC note/deadline, superseding result, correction reason/authority/evidence | Registrar-owned append-only academic history |
| GWA and curriculum evaluation | Included attempts/units, exclusions, term/cumulative value or pending reason, requirement-to-attempt/credit mapping, attempted/earned/remaining units | Generated read-only projections from released records and approved mappings |
| Progress and enrollment effect | Evaluated period, failed-unit basis, recommendation, decision authority/explanation/effective term, confirmed Clinic 4 effect | Recommendation is generated; consequential decision is Registrar-recorded and append-only |
| Lifecycle and completion | Event type, prior/derived state, authority, effective date, impact evidence, application, readiness source results, conferral facts and snapshot | Registrar-owned append-only events and generated readiness |
| Transcript | Template version, source academic-record version, request reference/date, due date, Clinic 6 clearance, preview/issuance state, issue/void/replacement/supersession evidence | Registrar-controlled; issued snapshots are immutable |

## 12. Reconciliation disposition

### Retain when conforming

- Roster and immutable result-event foundations.
- Transaction locking and late-authority evidence.
- Lifecycle history and completion snapshots.
- Authorization and native Filament foundations.

### Simplify

- Faculty entry to one final result.
- Curriculum evaluation to one deterministic projection.
- Academic progress to recommendation plus authorized decision.
- Completion to application, readiness, and conferral.

### Replace

- Period-grade calculation and stored Preliminary/Midterm/Final values.
- Hard-coded formula/scale engine.
- Released `P` and mutable released grades.
- Legacy Term Offering dependencies.
- Manual graduation batches and global-hold completion logic.

### Remove after dependency migration

- Raw gradebook logic and daily attendance.
- Generic grading DSL and arbitrary GWA editor.
- Learner what-if audit.
- Transcript-template editor.
- Internal appeals, chat, and official-TOR Student self-download.

### Quarantine

Current columns, services, pages, and tests remain untouched until a separately approved implementation task maps every consumer. Nothing is dropped by this authority review.

## 13. Acceptance coverage

The future vertical implementation must prove:

- valid and invalid final-grade entry, including `4.00`, `5.00`, and `INC`;
- absence of period-grade calculation and released `P`;
- designated Faculty, view-only co-Faculty, replacement submitter, and externally arranged courses;
- grade window, overdue roster, and late authority;
- official enrollment changes invalidating a submitted roster;
- complete release, returned rows, and no partial release;
- INC creation, GWA withholding, resolution, lapse to `5.00`, and recalculation;
- correction without a hard cutoff, append-only history, active-registration review, and issued-TOR supersession;
- PE/NSTP exclusion, all-attempt GWA, retake satisfaction, and no attempt limit;
- curriculum evaluation, credits/equivalencies, shifts, bridging, and no double counting;
- `Good`, `Warning`, `Probation`, and `Ineligible` recommendations with required human confirmation;
- leave, withdrawal, transfer out, reactivation, and persistent portal history;
- final-term graduation application, failed/dropped final-term course, clearance, and conferral;
- current-Student and completed-Student TORs, all attempts, no GWA in TOR, external request date, 30-day due date, reissue, and supersession;
- cross-role authorization, inaccessible-record behavior, email idempotency/failure, desktop/mobile, keyboard/screen-reader, stale-record, and concurrency behavior; and
- later DB-backed verification only against `test_tala_db`.

### 13.1 Realistic demonstration data

Use synthetic AY 2026–2027 records, never real Student data:

- one official class with a designated Faculty submitter, one view-only co-Faculty member, and three officially enrolled Students;
- one passing result, one `5.00`, and one `INC` with its completion note and deadline;
- one returned roster row and consolidated Registrar explanation;
- one later released INC completion and one separately authorized grade correction;
- one progress recommendation requiring an authorized decision;
- one final-term Student whose completion waits for a source-owned clearance; and
- one completed former Student with an issued, later superseded TOR snapshot.

### 13.2 Browser acceptance walkthrough

1. As designated Faculty, open the official roster, enter the three controlled final results, and submit the complete roster; confirm that the view-only co-Faculty member cannot edit or submit.
2. As Registrar, return one specified row with one explanation; confirm that no official grade is released.
3. As Faculty, correct the returned row and resubmit; as Registrar, release the whole roster atomically.
4. As each Student, confirm that only released results appear and that the unresolved `INC` shows its safe deadline/action while current GWA is explicitly pending.
5. Record and release the INC completion; confirm recalculated GWA, curriculum evaluation, and history without overwriting the original `INC`.
6. Record an authorized correction; confirm append-only history, recalculated projections, and Registrar review on any affected active Clinic 4 Registration Case.
7. Record the required progress/lifecycle decision and verify that Student, Registrar, Academic Head, and Clinic 4 receive only their authorized projections.
8. Apply for graduation, resolve the named source-owned clearance, and record conferral; confirm the immutable degree and `Completed` history.
9. Generate a TOR proposed preview for the completed former Student and confirm its **Not for official issuance** label; satisfy Clinic 6 clearance, record institution approval of the exact template version and external certification, then record issuance and supersede it through the authorized correction path. Confirm that every proposed and issued snapshot remains correctly labelled and historical.
10. Repeat the core Faculty, Registrar, Student, and TOR views at narrow width and by keyboard, including filtered-empty, inaccessible, stale, validation, concurrency, and mail-failure recovery states.

## 14. Future implementation gate — not a task plan

This PRD owns Clinic 5 behavior, UI, conceptual records, acceptance, exclusions, and salvage classification only. It is not a migration design, task breakdown, implementation sequence, or permission to modify the application.

Clinic 5 is closed and has passed the complete-authority review. Clinics 1–6 satisfy the same complete-clinic checklist, canonical `00`–`06` consolidation is complete, and implementation tasks may now be derived only through a separately approved journey-complete plan.

## 15. Assumptions

- TALA targets a normally recognized and authorized Philippine college.
- The final-grade vocabulary comes from the supplied Servitech workbook.
- PE/NSTP/CWTS/LTS/ROTC GWA exclusion is client-confirmed Servitech policy.
- The PUP academic-progress reference profile is a transparent capstone reference requiring institutional adoption or replacement before production deployment.
- External institutional decisions are recorded rather than recreated as multi-step approval systems.
