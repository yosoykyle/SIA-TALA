# PRD 05 — Teaching, Final Grades, Academic Records, Lifecycle, and Completion

## Authority and Standalone Status

> **Authority status — Standalone and ready for vertical-slice planning.** This PRD is the complete authority for official roster results, release and correction, academic averages, curriculum evaluation, external competency evidence, enrollment effect, lifecycle, completion, conferral, academic records, and TOR. It is understandable without legacy Grades, Student Lifecycle, or Student Hub PRDs.

## 1. Purpose and successful outcome

Clinic 5 defines one complete academic-record journey:

`Official roster → final-grade submission → Registrar release → academic record/term and cumulative averages → curriculum evaluation and enrollment effect → lifecycle effects → completion/conferral → unofficial record and TOR`

The successful outcome is one coherent, append-only academic history whose released results, curriculum evaluation, lifecycle effects, completion evidence, and official outputs remain consistent across Faculty, Registrar, Academic Head, and Student projections.

TALA records final course results. It does not recreate Faculty gradebooks, period-grade formulas, attendance tracking, appeals, clearance-office workflows, document-request fulfillment, diploma production, or ceremony management.

## 2. Evidence and institutional boundary

This contract is grounded in:

- The supplied [Servitech final-grade workbook](../archive/raw-source-files/MS.-OLIMBERIO-Blended-Online.Final-Grade-1.xlsx), which evidences the final-grade vocabulary, including `4.00` as passing.
- [Batas Pambansa Blg. 232](https://lawphil.net/statutes/bataspam/bp1982/bp_232_1982.html), which protects access to school records and requires official records such as grades and transcripts within thirty days of request.
- [CHED eCAV requirements](https://ecav.ched.gov.ph/requirements), which require an official TOR used for CAV to be certified true and signed by the current HEI Registrar but do not supply a universal visual template.
- [CHED's statement on institutional grading systems](https://legacy.ched.gov.ph/424-scholars-may-lose-scholarship-due-to-pass-all-policy-of-17-heis/), which uses GPA/GWA in a specific scholarship context while affirming that HEIs determine their grading systems; it does not establish Servitech's one-term display label.
- The [UP academic-policy reference](https://osu.up.edu.ph/wp-content/uploads/2022/04/1309.FINALE.pdf) as a mature Philippine example of excluding PE and NSTP from GWA; Servitech's exclusion remains institution-specific and client-confirmed.
- [PeopleSoft grade-roster self-service](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/campus-self-service/entering-grades-through-self-service.html) as a mature-system benchmark for roster submission and controlled release, not Philippine policy.
- [TESDA assessment and certification](https://tesda.gov.ph/About/TESDA/25) and its [official assessment FAQ](https://tesda.gov.ph/About/Tesda/127), which establish TESDA/accredited-assessor ownership of competency judgments and NC/COC certification; they do not authorize TALA to operate those processes.

| Institutional responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Calculate period grades, raw scores, and final result | Designated Faculty through the approved external process | Record one controlled final result per official learner registration |
| Release official grades | Registrar | Validate and release the complete submitted roster or return specified rows |
| Decide late submission, correction, progress consequence, lifecycle change, credit/equivalency, honor, or conferral | Authorized institution outside TALA | Record the approved result, authority, evidence, effective date, and system effect |
| Maintain the academic record and derive academic averages/evaluation | Registrar-owned TALA records | Deterministic projections from released and approved facts |
| Assess or certify an external TESDA-linked competency | TESDA/accredited assessor | Registrar records only a verified append-only result against an active authority-backed curriculum requirement |
| Request, physically sign, seal, deliver, or submit a TOR for CAV | External Registrar/Accounting process | Record request/clearance evidence, generate the TALA Standard TOR, and record immutable issuance/void/replacement history |
| Produce diplomas, manage ceremonies, or calculate honors | External institutional process | No workflow; optionally record an approved honor with the conferral |

This is a **Decision then record** product. TALA does not turn institutional academic judgment into a generic approval engine.

### 2.1 Benchmark result

- **Domain checked:** final-result recording, complete-roster submission and release, academic-record projections, lifecycle/completion, and controlled transcript issuance.
- **Sources consulted:** the Servitech final-grade workbook, Batas Pambansa Blg. 232, CHED eCAV requirements, the cited UP academic reference, PeopleSoft grade-roster self-service, the legacy TALA PRDs, and bounded code/schema implementation evidence.
- **Confirmed:** a lean TALA journey can accept one final result per official registration, release a complete roster under Registrar control, preserve immutable academic events, derive term weighted average, cumulative GWA, and curriculum evaluation, and generate a controlled transcript snapshot without recreating a Faculty gradebook or document-request office.
- **Rejected or bounded:** another institution's rule is not Servitech evidence. TALA adopts only the explicitly bounded one-year nonautomatic INC completion default; no external automatic grade conversion, academic sanction, or load rule is copied. Period-grade formulas, attendance, generic progress engines, editable transcript templates, clearance routing, delivery, and diploma production remain outside TALA.
- **Alignment:** the PRD adopts controlled roster and read-mostly academic-record patterns, a deterministic nonautomatic INC completion lifecycle, and one fixed versioned Servitech-branded TOR. No benchmark, code constant, or demo fixture silently creates another policy.

## 3. Final-grade and roster authority

### 3.1 Controlled final results

Faculty calculates grades outside TALA using the institution's workbook or approved process. The designated submitting Faculty enters exactly one final result:

- `1.00`, `1.25`, `1.50`, `1.75`, `2.00`, `2.25`, `2.50`, `2.75`, `3.00`, `4.00`, `5.00`; or
- `INC`.

`1.00–4.00` is passing and satisfies the course. `5.00` is failed and does not satisfy the course. `INC` is temporary and unresolved.

Preliminary, Midterm, Final-period percentages, quizzes, requirements, examinations, attendance, raw scores, and formulas remain outside TALA. `P` is not an official mark. A blank row is unfinished workflow state and cannot be released. Course Drop, full withdrawal, and approved credit results come from their owning Registrar actions and cannot be entered by Faculty as grades.

The final-grade vocabulary is a fixed TALA product contract informed by the supplied Servitech workbook; it is not a configurable grading-scale or policy engine. `INC` is a valid temporary result. Its completion deadline and nonautomatic overdue behavior are fixed by Section 4.2.

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

## 4. Academic averages, INC, and corrections

### 4.1 Term weighted average and cumulative GWA

TALA calculates:

`Weighted average = Σ(released included numeric grade × official course units) ÷ Σ(included official course units)`

Rules:

- `TermWeightedAverageProjection` covers exactly one term. Its neutral default display label is **Term weighted average**.
- `CumulativeGwaProjection` covers every included attempt through the selected grade-complete term and displays as **Cumulative GWA**.
- Cumulative GWA is calculated from all included attempt grade points and units; it is never the arithmetic mean of displayed term averages.
- Every released numeric attempt counts. A later passing retake may satisfy the curriculum but never erases the earlier failed attempt.
- There is no independent maximum retake counter; continuation depends on the confirmed academic-enrollment effect.
- PE and NSTP courses—including CWTS, LTS, and ROTC equivalents—are excluded under the client-confirmed Servitech rule.
- Excluded courses remain curriculum and TOR requirements.
- `INC`, Course Drop/full-withdrawal results, and nonnumeric approved credit are excluded.
- TALA calculates at full precision and rounds once for display to two decimals using decimal half-up; no intermediate value is rounded.
- A correction replaces the superseded value in effective calculations while preserving event history.
- Academic averages appear in Student Academics and Registrar academic-record views, not in the standard TOR.

`CourseAcademicClassification` identifies which effective course revisions are PE, NSTP/CWTS/LTS/ROTC, or ordinary academic courses. The formula and confirmed exclusions above are fixed product rules, not a configurable GWA policy engine. An optional institution-approved term display label is bounded operational metadata with its authority reference/date and effective term. **Term GPA** or another institutional label appears only when Servitech records that metadata; PUP terminology never supplies it. Without it, the neutral **Term weighted average** label remains.

One shared `AcademicAverageReadiness` projection has exactly four states:

| State | Condition | Required presentation |
|---|---|---|
| `GradesNotComplete` | At least one official course registration in the selected term lacks a released terminal result or owning Registrar outcome | Show **Grades not complete**; publish neither a partial term value nor a new cumulative value |
| `IncompleteResultPending` | Every expected outcome is released, but at least one included course remains `INC` | Show **Current academic average pending — incomplete grade unresolved**; withhold the current term and cumulative final value |
| `Available` | Every official registration has a released resolved outcome and the term has at least one included numeric unit | Publish the term weighted average and cumulative GWA |
| `NotApplicable` | The term is grade-complete but has no included numeric units | Show **Not applicable — no included academic units**; never display zero |

Course Drop, full-withdrawal, approved-credit, and PE/NSTP-equivalent outcomes may satisfy grade completeness only when their owning authoritative outcome is released. They still contribute no grade points or units. While the selected term is `GradesNotComplete` or `IncompleteResultPending`, the last complete cumulative value remains visible as **Through [term]**; when none exists, cumulative GWA is unavailable. TALA never calculates from the released subset of a partially released term.

Resolution recalculates the original term and every later cumulative projection.

### 4.2 Incomplete result

`INC` requires a short Faculty completion note. It does not satisfy a prerequisite, is excluded from both academic-average calculations while unresolved, and never becomes failure solely because time passes.

Every released `INC` receives the bounded TALA completion deadline: one calendar year after the original Term's official end date. The same month and day in the next year is used; February 29 becomes February 28 when the next year has no February 29. The deadline is inclusive through 23:59:59 Asia/Manila.

The completion lifecycle is:

| State | Deterministic condition | Permitted action |
|---|---|---|
| `CompletionOpen` | The released `INC` remains unresolved and the current deadline has not elapsed | Faculty records the authorized completion result; Registrar may append an authorized deadline correction |
| `CompletionOverdue` | The current deadline has elapsed and no successor result exists | Faculty and Registrar may still complete the controlled result process; Registrar may append an authorized deadline correction |
| `Resolved` | Registrar has released the authorized completion or replacement result | Read current result and history; later change uses grade correction |

- The original INC event stores its completion note, original Term end, calculated deadline, release actor, release time, and source version.
- Registrar may correct the deadline before final resolution only by appending a deadline amendment with the prior and new deadline, authority reference/date, 10–1,000-character reason, actor, and time. The latest valid amendment controls the current state; every earlier value remains visible.
- An amendment may correct an already-overdue deadline. A later future deadline returns the derived state to `CompletionOpen`; a past or elapsed corrected date remains `CompletionOverdue`.
- Deadline passage is a derived state and audit-timeline fact, not a grade event. TALA never converts the result to `5.00` or another grade automatically.
- Faculty records the authorized completion result and Registrar releases the immutable superseding Official Grade Event. The original `INC`, deadlines, amendments, overdue interval, and final result remain historically visible.
- Completion release and deadline amendment transactionally revalidate the current unresolved INC. A stale or concurrent action records nothing and refreshes the controlling version.
- There is no arbitrary completion-attempt or amendment count while the result remains unresolved and the actor remains authorized.
- An unresolved INC keeps the current term `IncompleteResultPending`, contributes no grade points or units, leaves its course and dependent prerequisites unsatisfied, and keeps curriculum completion/conferral pending.
- Clinic 4 receives `AdvisingRequired` for nonstandard planning. The unresolved or overdue INC blocks only dependent courses; it never creates automatic probation, dismissal, a general enrollment block, or a financial hold.
- Student Academics shows the course, completion note, current deadline, `CompletionOpen` or **Completion overdue**, responsible Registrar office, and next safe action. Faculty sees only assigned-course completion action; Registrar sees the complete authority/history; Academic Head remains read-only.

### 4.3 Grade correction

A correction to a released result requires an externally authorized decision containing reason, authority, optional evidence reference, actor, and date.

- TALA has no hard technical correction cutoff.
- A correction outside the normal institutional period is visibly late but remains recordable when authorized.
- The correction appends a superseding result; it never mutates the original event.
- Term weighted average, cumulative GWA, curriculum evaluation, `AcademicEnrollmentEffect`, and completion readiness recalculate.
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
- externally verified competency results for requirements expressly present in the active Curriculum Version;
- current official enrollment; and
- approved shift, deficiency, bridging, and old-curriculum mappings.

It shows every required course, every official attempt and grade, credited mapping, current enrollment, prerequisite relationship, attempted units, earned units, remaining units, unresolved deficiency, and any authority-backed external-competency requirement with its safe current result.

- A later passing attempt may satisfy a requirement, but every attempt remains in the academic record and TOR.
- A credit or equivalency satisfies only its approved mapped requirement.
- Credits cannot be double-counted unless the approved curriculum explicitly permits it.
- A `TrackedOnly` external-competency requirement displays its verified result or **Not recorded** and never blocks enrollment, grades, completion, or conferral.
- A `CompletionRequired` external-competency requirement affects completion only when the active Curriculum Version's exact approved authority expressly assigns that treatment. Supplied evaluation sheets alone never create the block.
- TALA has no learner what-if audit, speculative graduation date, generic substitution builder, or automatic equivalency decision.

### 5.3 Externally verified competency result

TESDA or its accredited assessor owns the competency judgment and certification. Registrar may record one append-only `ExternalCompetencyResult` only against an authorized external-competency requirement in the Student's active Curriculum Version.

The record contains the Student and requirement references, assessment date, `Competent` or `NotYetCompetent` result, optional verified `NC` or `COC` reference and validity information, safe remarks, external evidence/source reference, Registrar recorder and time, and predecessor/supersession evidence.

- Reassessment creates a successor result; earlier attempts remain visible history.
- Missing, conflicting, stale, or unverified evidence posts nothing and names the external source or Registrar recovery action.
- The result never creates a course grade, academic units, average contribution, prerequisite satisfaction, or financial effect. A separate approved course/equivalency mapping remains authoritative for those effects.
- TALA Standard TOR — Servitech v1 excludes the external competency result. A later successor template may include it only with exact approved curriculum and output authority.
- No email or new official output is created.
- TALA does not manage TESDA applications, eligibility, training, schedules, assessors, venues, fees, assessment delivery, certificate issuance, renewal, or registry operations.

## 6. Academic progress and enrollment effect

TALA derives factual academic progress from the active Curriculum Version and released results. It determines course satisfaction, prerequisite availability, remaining requirements, retake need, unresolved deficiencies, and whether the ordinary curriculum sequence remains usable. It does not convert failed-unit percentages into institutional sanctions.

`AcademicEnrollmentEffect` is exactly:

| Effect | Exact condition | Enrollment consequence |
|---|---|---|
| `Allowed` | Ordinary curriculum placement remains eligible and no recorded lifecycle or academic authority blocks it | Clinic 4 may prepare the standard proposal |
| `AdvisingRequired` | Failure, deficiency, shift, bridging, retake, unavailable course, or another nonstandard placement requires an Individually Advised proposal | Registrar prepares an attributable proposal; the learner is not assigned a permanent type |
| `Blocked` | A recorded authorized institutional decision or incompatible lifecycle state expressly prevents the consuming action | Clinic 4 blocks placement/finalization and shows the authority, effective term, owner, and next safe action |
| `PendingDecision` | A real institutional review has been opened or an authoritative source needed for the effect remains unresolved | Clinic 4 waits for the named owner; absence of a policy alone does not fabricate a review |

Failed-unit percentages never automatically create `Warning`, `Probation`, load reduction, dismissal, program ineligibility, or institutional ineligibility. A consequential external decision is append-only and Registrar-recorded with its authority reference/date, reason, safe learner explanation, effective term, enrollment effect, actor, and time. A successor decision preserves the earlier decision and refreshes affected Clinic 4 projections. TALA has no generic progress-policy DSL or policy-driving Regular/Irregular status.

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

**Apply for graduation** appears when every course requirement is either already satisfied/credited or officially enrolled in the Student's current final term and every authority-backed `CompletionRequired` external-competency requirement is satisfied. `TrackedOnly` evidence never affects eligibility.

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
3. Term weighted average and cumulative GWA, or the explicit `Grades not complete`, incomplete-result, or not-applicable state.
4. Curriculum evaluation.
5. Confirmed academic progress and safe explanation.
6. Attempted, earned, and remaining units.
7. Completion readiness.
8. Correction, INC, and lifecycle history.

Students may print an **Unofficial — for student reference** record. They cannot issue or self-download an official TOR.

### 8.2 TALA Standard TOR — Servitech v1

TALA owns one fixed Servitech-branded transcript definition identified as **TALA Standard TOR — Servitech v1**. It is an immutable output definition, not a configurable template builder. Servitech branding, address/contact details, the current Registrar signatory name/title, and the seal image or seal-placement instruction are required operational inputs. A later adopted format becomes a successor template version; every prior issued version remains reproducible.

The standard TOR contains:

- Servitech logo, institutional name, address/contact data, and `TRANSCRIPT OF RECORDS`;
- transcript reference, template version, issue date/time, generation reference, and `Page x of y`;
- Student legal name, Student number, program, Curriculum Version, admission basis/date, and prior-school/prior-credit information when applicable;
- chronological Academic Year and Term groups;
- course code, historical title snapshot, units, released grade or mark, remarks, attempt history, and approved-credit/equivalency treatment;
- term units and cumulative earned units;
- completion and conferral information when applicable;
- grading/remarks legend, Registrar certification statement, current signatory name/title, and institutional seal area;
- repeated Student/transcript identity and table headings on continuation pages; and
- explicit `Issued`, `Voided`, `Replacement`, or `Superseded` notice, with `Generated through TALA` as restrained footer metadata.

The standard v1 TOR excludes term weighted average, cumulative GWA, LRN, period grades, raw scores, Faculty, schedules, financial balances, receipt details, service restrictions, and audit history.

### 8.3 Issuance boundary

1. Request intake, physical signing, sealing, claiming, delivery, courier, CAV submission, diploma, and ceremony remain external.
2. Registrar records the external request date/reference; TALA derives the 30-day statutory due date.
3. Clinic 6 supplies `OfficialOutputPaymentClearance` as `Cleared`, `NotRequired`, or `ActionNeeded`.
4. Official issuance is available for an academically completed learner when identity, released academic history, completion/conferral, request-specific clearance, current signatory data, template rendering, and issuance authorization are ready.
5. TALA generates an exact preview of the immutable snapshot. **Issue official TOR** confirms the learner, request, snapshot, template version, clearance, signatory data, resulting reference, and external physical steps.
6. Successful confirmation creates one immutable Transcript Snapshot and issuance event. Students cannot self-issue or anonymously download the official TOR.
7. Generation or validation failure creates neither an issuance event nor an official-looking partial artifact.
8. An issuance mistake appends a Void event and creates a new replacement snapshot/reference. No version is overwritten.
9. A later legitimate grade correction preserves but marks the historical snapshot `Superseded`; future issuance uses a new snapshot.
10. A generated TOR does not claim to be physically signed, sealed, a Certified True Copy, or CAV-ready unless those external facts are separately completed and recorded. Their external completion does not block TALA from generating and recording its official issuance.

### 8.4 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| `GradeRoster: Draft` | Save or submit complete roster | Designated Faculty | Current submitting-Faculty assignment | Official class/membership; open window or late authority; every required row valid | Submitted immutable roster version | Submission freezes the review version | Registrar sees one current submitted version; Students see no result |
| `GradeRoster: Submitted` | Release or return specified rows | Registrar | Grade-release authority | Membership, enrollment effects, submitter, completeness, and version revalidated | Immutable grade events or one consolidated return explanation | Release cannot be edited; return changes no official result | Students see only released events; Faculty sees returned rows |
| `GradeRoster: Returned` | Correct specified rows and resubmit | Designated Faculty | Current submitting-Faculty assignment | Return explanation visible; membership/version current | Next reviewable roster version | Prior return/submission history retained | Registrar sees successor version |
| `GradeRoster: Released` | View or begin separately authorized correction | Authorized role / Registrar | Record access or correction authority | Direct edit and partial release unavailable | Read-only released record or correction case | Released events immutable; correction appends successor | Student/Clinic 4 consume only released projection |
| Average `GradesNotComplete` | View a term whose official classes are only partly released | Authorized Student or Staff | Own-record or academic-record access | At least one official registration lacks a released terminal result/outcome | Read-only **Grades not complete** plus last complete cumulative **Through [term]** when available | No partial average is stored or displayed; later release recomputes readiness | Student sees own state; Registrar/Academic Head see authorized completeness evidence; Faculty sees only whether an assigned roster remains a missing source, never other classes or learner averages |
| Average `IncompleteResultPending` | View a grade-complete term with unresolved `INC` | Authorized Student or Staff | Own-record or academic-record access | All expected outcomes released; at least one included result remains `INC` | Read-only pending projection and last complete cumulative value | Registrar release of a successor result recalculates | Student and authorized Staff see the same source/deadline status |
| Average `Available` / `NotApplicable` | Publish deterministic projection | System-derived | Fixed PRD 05 formula, effective Course classifications/display-label metadata, and released authoritative results | Complete term; denominator positive for `Available`, zero for `NotApplicable` | Exact full-precision projection and two-decimal display, or explicit no-included-units text | Corrections supersede source results and recompute later projections | Student/Registrar views agree; TOR remains unchanged |
| Unresolved `INC` | Record completion and release result | Faculty then Registrar | Faculty completion plus Registrar release authority | Original result/note; current version; valid completion result | Superseding result and recalculated projections | Original `INC` remains history | Student, curriculum evaluation, term/cumulative averages, and Clinic 4 refresh |
| `INC: CompletionOpen` | View, complete, or amend deadline | Student views; Faculty completes; Registrar releases/amends | Own-record, assigned-roster, or academic-record authority | Current unresolved INC; inclusive deadline not elapsed; action uses current version | Deadline/action projection, deadline amendment, or immutable successor result | Amendment and result release append history; no automatic grade | Student/Faculty/Registrar/AH see role-safe current state; Clinic 4 receives `AdvisingRequired` |
| `INC: CompletionOverdue` | Deadline passes with no successor result | System-derived; Faculty/Registrar retain controlled completion actions | Same role authority as open completion | Current unresolved INC; latest deadline elapsed | **Completion overdue** guidance and audit timeline; grade remains `INC` | Later amendment may reopen the window; result release resolves it; neither overwrites history | Dependent courses and completion remain pending; unrelated enrollment is not globally blocked |
| External competency result absent/current/superseded | Record verified result or reassessment | Registrar | Academic-record access plus active requirement and external evidence | Exact requirement authority, Student curriculum version, assessment source, result, and current version revalidated | Append-only `ExternalCompetencyResult`; tracked-only absence displays **Not recorded** | Reassessment appends a successor and retains every attempt; failed/stale action posts nothing | Student Academics and Academic Oversight receive the safe current projection; no grade, average, prerequisite, finance, email, or TOR effect is inferred |
| Curriculum position / authorized academic decision | Derive factual effect or record external decision | TALA / Registrar | Released-result and curriculum sources; decision authority only when an actual decision exists | Current curriculum facts; for `Blocked` or authority-backed `PendingDecision`, exact authority/review, explanation, and effective term | `Allowed`, `AdvisingRequired`, `Blocked`, or `PendingDecision` projection | Factual advising is regenerated; consequential decisions are append-only successors | Clinic 4 receives the current source-labelled effect only |
| Lifecycle change | Record externally authorized result | Registrar | Lifecycle-result authority | Authority, effective date, current facts, current-term impact preview | Append-only lifecycle event | History never deleted | Student, Clinic 4, rosters, COR/account review receive bounded effects |
| Graduation application | Apply | Eligible Student | Own record and application eligibility | Requirements satisfied, credited, or officially enrolled in final term | Graduation intent | Does not confer degree or prove completion | Registrar receives completion work item |
| `ReadyForConferral` | Record conferral | Registrar | Conferral-recording authority | Complete curriculum, no unresolved result, source clearances, external authority/date | Conferral/evaluation snapshots and `Completed` event | Immutable; later correction uses successor evidence | Student sees conferral; downstream lifecycle projection updates |
| TOR preview | Generate exact preview | Registrar | Transcript-preview authority | Academically completed learner; releasable record; request/reference; current TALA Standard TOR and signatory data; Clinic 6 clearance not `ActionNeeded` | Preview of the exact immutable issuance snapshot | Preview alone creates no issuance | Registrar sees content/readiness/history; Student receives no self-issue action |
| TOR issuance | Issue, void, or replace | Registrar | TOR issuance authority | Current academic snapshot, verified identity, completion/conferral, current template/signatory data, Clinic 6 clearance, successful rendering | Issued snapshot or append-only void/replacement | Issuance freezes snapshot; later supersession never overwrites | Authorized history shows Issued/Voided/Replacement/Superseded exactly |

### 8.5 Readiness matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Roster submission | Clinic 3 class/assignment, Clinic 4 membership, Grade Entry window, current rows | Faculty submits; Clinics 3/4 own sources | Designated submitter and one valid result per required row within window/authority | Submission blocked with row-level action needed | Submit roster | Correct rows or owning assignment/window/membership source |
| Roster release | Current submitted version and official membership | Registrar | Version current, membership unchanged, all rows valid | Release blocked; specified rows may be returned | Release roster | Resolve stale enrollment evidence or return exact rows |
| Academic-average publication | Official term registrations, released terminal outcomes, fixed formula, Course Academic Classifications, optional authorized display-label metadata | Registrar owns source metadata; system derives | Every registration has a released resolved outcome; terminology/inclusion source is current; included-unit denominator is known | `GradesNotComplete`, `IncompleteResultPending`, or `NotApplicable`; no partial/zero fallback | Publish term weighted average and cumulative GWA | Release the missing owning result, resolve `INC`, or correct stale classification/display metadata |
| External competency result | Active Curriculum Version requirement, external assessment/certification evidence, current Student curriculum | TESDA/accredited assessor owns result; Registrar records verified evidence | Requirement is active and attributable; evidence supports the selected result; `CompletionRequired` treatment has exact authority | Recording blocked or result shown **Not recorded**; completion remains pending only for expressly required items | Record verified external result; evaluate completion when authorized | Correct requirement/evidence, verify current external source, or record a successor reassessment result |
| INC completion | Original `INC`, note, Faculty result, release authority | Faculty and Registrar | Valid completion result and current unresolved source | Superseding result unavailable | Release INC completion | Supply result or correct stale authority; preserve original event |
| INC deadline and completion | Original `INC`, official Term end, current deadline/amendments, current time, completion result | Registrar owns deadline corrections/releases; Faculty supplies completion | One-year deadline calculated; latest amendment current; unresolved source/version current | Stale action records nothing; overdue state remains actionable; grade never changes automatically | Display current state, amend deadline, or release successor result | Correct the exact stale source, append an authorized deadline amendment, or complete the controlled result process |
| Academic enrollment effect | Released results, active curriculum/lifecycle, and any actual external decision or opened review | TALA derives facts; Registrar records decision | Ordinary/nonstandard placement is attributable; `Blocked` has authority; `PendingDecision` has an opened review or unresolved named source | Consuming action receives `AdvisingRequired` or a named unavailable/pending state; no sanction is inferred | Prepare proposal or record authorized decision | Correct source or record/supersede the actual external decision and safe explanation |
| Lifecycle result | External authority and current-state/current-term impact | Registrar | Authority/effective date current and impact preview available | No lifecycle mutation | Record lifecycle result | Correct authority or refresh impact evidence |
| Conferral | Graduation application, evaluation, results, source clearances, external authority | Registrar and source owners | Curriculum satisfied, no unresolved result, every clearance ready | `AwaitingResultsOrClearance` | Record conferral | Resolve the named source; never override readiness locally |
| TOR preview | Completed academic snapshot, request reference/date, Clinic 6 clearance, TALA Standard TOR, signatory data | Registrar/Accounting | Identity, academic, completion/conferral, template, signatory, clearance, and rendering sources current | Preview unavailable with the named failed source | Generate exact preview | Correct the named source; preview creates no issue event |
| TOR issuance | Current preview/snapshot, TALA Standard TOR version, Clinic 6 clearance, issuance authority | Registrar and Accounting source | Every preview check passes and consequence confirmation succeeds | **Issue official TOR** unavailable; no partial artifact or event | Issue immutable TOR | Correct the named source; physical signing/sealing/CAV remain external after generation |

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

Authorized external-competency requirements remain inside the existing Academic Progress or Completion context. A focused **Record verified external result** action shows the Student, active requirement, treatment, assessment date, result, optional NC/COC reference and validity, safe remarks, external evidence/source, and append-only impact preview. It creates no TESDA, Certifications, or Assessment destination.

Search supports Student number, legal name, course/class reference, and TOR reference. Native filters cover term, program, course, Faculty, roster state, deadline, result, INC/correction state, progress, lifecycle, completion readiness, and relevant date ranges.

Each record leads with state, owner, next action, and one state-appropriate primary action, then presents the authoritative academic facts and collapsed evidence/history. Academic-average detail shows the neutral calculation name, effective inclusion source, authorized display label when one exists, grade-completeness evidence, included units, and **Through [term]** cumulative context. INC detail shows the original Term end, current completion deadline, `CompletionOpen` or `CompletionOverdue`, amendments, owner, and next action. TOR detail shows template/source versions, signatory inputs, readiness, issue history, and external physical steps. There is no policy Settings page, automatic lapse action, bulk release, bulk correction, bulk consequential decision, bulk conferral, or bulk TOR issuance.

### 9.3 Role projections and interaction rules

- Student sees only their released record, term weighted average/cumulative GWA or explicit readiness state, evaluation including safe external-competency results, confirmed progress, completion, unofficial output, the informational Examination Period, and the current INC deadline/state.
- Academic Head sees read-only academic oversight, including deadline/overdue evidence, external-competency requirement/result evidence, and recorded decision evidence.
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
| `INC` released | Student and designated Faculty | Course, Term, completion deadline, responsible Registrar office, authenticated link | INC event plus recipient identity | Deadline/state remain authoritative | One action email only; no grade value, attachment, countdown, or recurring reminder |
| INC deadline amended | Student and designated Faculty | Course, replacement deadline, owner, authenticated link | Deadline-amendment event plus recipient identity | Latest deadline remains authoritative | One replacement-deadline email; no prior deadline erased or recurring reminder |
| `INC` resolved | Student | Outcome available in Student Academics; secure link | Superseding result event plus Student identity | Result/recalculation remain committed | No grade value or attachment; deadline passage alone sends no email |
| Authorized correction released | Affected Student | Academic record updated; secure history link | Correction release event plus Student identity | Correction remains committed | No old/new grade value or attachment |
| Progress or lifecycle decision recorded | Affected Student | Safe outcome, owner, next action, secure link | Confirmed decision/event plus Student identity | Decision remains effective | No sensitive reason or unrelated record detail |
| Completion requires action | Student | Missing source, owner, next action, secure link | Completion-readiness version plus Student identity | Readiness remains source-derived | No automated reminder loop |
| Conferral recorded | Student | Conferral available in authenticated record | Conferral reference plus Student identity | Conferral remains committed | No diploma, TOR, grade value, or attachment |

Routine saves, calculations, queue movement, academic-average recalculation, page activity, and recurring reminders send no email. Mail failure never rolls back an academic transaction; the authenticated workspace remains authoritative and retains authorized resend evidence.

## 11. Conceptual contracts

These names define responsibilities before physical schema design. No public HTTP API or physical table design is approved here:

| Name or family | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| Grade vocabulary, average formula, exclusions, and INC deadline rule | Define fixed allowed results and calculations | PRD 05/TALA product authority | Documentation concept that does not require a separate implementation object | Roster validation, averages, INC UI | Fixed rules require no policy engine or editor |
| CourseAcademicClassification and optional term display-label authority | Supply effective course inclusion and authorized wording | Registrar records operational data | Required institutional operational data with effective history | Academic averages and UI | Owned with Course/effective metadata; not a standalone settings module |
| GradeRoster and GradeRosterEntry | Preserve complete Faculty submission/review versions | Faculty submits; Registrar releases/returns | Persisted authoritative record with owned rows and immutable versions | Registrar, Student result generation | Entries belong to the roster; no raw-score gradebook |
| OfficialGradeEvent | Preserve each released INC, completion, grade, or correction | Registrar | Immutable version or event | Student record, PRD 04, averages, completion, TOR | Late authority, completion resolution, and correction authority are event metadata, not separate workflow aggregates |
| INC deadline amendment | Correct one unresolved INC deadline without rewriting history | Registrar | Immutable version or event | Student, Faculty, Registrar, Academic Head | Remains distinct because it changes the controlling deadline but never the grade |
| AcademicAverageReadiness, TermWeightedAverageProjection, CumulativeGwaProjection | Explain completeness and calculate term/cumulative results | PRD 05 | Derived projection/calculation | Student, Registrar, Academic Head | No stored partial average or GWA engine |
| OfficialCourseResultProjection | Publish the released course result to authorized consumers | PRD 05 | Derived projection/calculation | PRD 04 and curriculum evaluation | No copied academic record |
| ExternalCompetencyResult | Record verified TESDA/accredited-assessor evidence | External assessor owns judgment; Registrar records it | External reference/result with immutable history | Curriculum evaluation and role projections | No TESDA operations module |
| CurriculumEvaluation and CurriculumRequirementResult | Map released attempts/credits to curriculum requirements | PRD 05 | Derived projection/calculation | Student, Registrar, PRD 04, completion | Requirement results are owned parts of the evaluation, not separate authoritative records |
| AuthorizedAcademicDecision | Record a real externally authorized consequential result | Registrar | Immutable version or event | AcademicEnrollmentEffect and PRD 04 | Used only when a real decision exists; no generic approval/override engine |
| AcademicEnrollmentEffect and CompletionReadiness | Publish current advising/enrollment and completion facts | PRD 05 | Derived projection/calculation | PRD 04, Student, Registrar | Never stored as sanctions or a configurable state machine |
| StudentLifecycleEvent | Preserve authorized leave, withdrawal, transfer, reactivation, shift, completion, and correction | Registrar | Immutable version or event | Enrollment, rosters, finance review, Student projections | One event family with explicit event types; no lifecycle engine |
| GraduationApplication and DegreeConferral | Record learner intent and the authoritative conferred result | Student/Registrar | Persisted authoritative record and immutable event | Completion, lifecycle, TOR | Honor calculation and award workflow remain external |
| TALA Standard TOR — Servitech template version | Reproduce the exact output layout and contract | PRD 05 product authority | Immutable version or event | Transcript preview/issuance | Fixed versioned definition; no template builder or generic document engine |
| TranscriptSnapshot and TranscriptIssuance | Freeze exact academic content and issue/void/replace/supersede history | Registrar | Immutable version or event and official output | Authorized Registrar history | Kept distinct because content reproducibility and issuance lifecycle protect different integrity concerns |
| OfficialOutputPaymentClearance | Supply request-specific Accounting result | PRD 06 | External reference/result consumed read-only | TOR readiness | PRD 05 never edits or recreates it |

There is no public API, gradebook engine, grading DSL, GWA editor, degree-audit rules engine, lifecycle state machine, generic override record, transcript-template editor, or global hold engine.

### 11.1 Authoritative data ownership

| Conceptual record family | Minimum authoritative facts | Owner and mutability |
| --- | --- | --- |
| Fixed academic rules and operational metadata | Accepted result vocabulary, average formula/exclusions, one-year nonautomatic INC rule, effective Course classifications, and optional authorized term display label | Product rules are fixed by PRD 05; Registrar records only bounded effective operational metadata |
| Roster and entry | Term, official Class Offering, official registrations, designated submitter, due date, row result/note, roster state and version | Faculty edits Draft/Returned rows; Registrar controls release; released versions are immutable |
| Official result, INC, and correction | Registration, result, release event/time/actor, original INC note, Term end, original/current deadline, amendments, overdue interval, superseding result, correction reason/authority/evidence | Registrar-owned append-only academic history; deadline passage never changes the grade automatically |
| Academic averages and curriculum evaluation | Included attempts/units, exclusions, term weighted/cumulative value or readiness reason, requirement-to-attempt/credit mapping, attempted/earned/remaining units, authority-backed external-competency requirements and current safe results | Generated read-only projections from released records, approved mappings, and verified external evidence |
| External competency result | Student, active requirement, assessment date, `Competent`/`NotYetCompetent`, optional verified NC/COC reference and validity, safe remarks, external source, recorder/time, predecessor/supersession | TESDA/accredited assessor owns the judgment; Registrar records append-only verified evidence; reassessment never overwrites history |
| Curriculum position and enrollment effect | Curriculum/result/lifecycle sources; ordinary or nonstandard placement basis; any actual decision authority/explanation/effective term; current Clinic 4 effect | Factual projection is generated; consequential external decision is Registrar-recorded and append-only |
| Lifecycle and completion | Event type, prior/derived state, authority, effective date, impact evidence, application, readiness source results, conferral facts and snapshot | Registrar-owned append-only events and generated readiness |
| Transcript | TALA Standard TOR version, source academic-record version, request reference/date, due date, Clinic 6 clearance, signatory inputs, preview/issuance state, issue/void/replacement/supersession evidence | Registrar-controlled; issued snapshots are immutable; physical signing/sealing/CAV remain external |
## 12. Lifecycle, Mutation, and Technical Boundaries

Submitted and released roster results, grade corrections, external competency results, lifecycle events, Completion Readiness versions, Conferral, and transcript snapshots are append-only and never deleted. Draft rows remain editable only before complete-roster submission; every later change uses return, completion, correction, withdrawal, void, replacement, or a successor version under the authority defined in this PRD.

This PRD does not prescribe tables, services, migrations, formula code, or implementation order. A future journey-complete slice must reconcile current roster, grade-event, curriculum-evaluation, lifecycle, transcript, authorization, UI, email, and test surfaces against this authority without restoring period-grade calculation, automatic sanctions, or external-institution policy.
## 13. Acceptance coverage

The future vertical implementation must prove:

- valid and invalid final-grade entry, including `4.00`, `5.00`, and `INC`;
- absence of period-grade calculation and released `P`;
- designated Faculty, view-only co-Faculty, replacement submitter, and externally arranged courses;
- grade window, overdue roster, and late authority;
- official enrollment changes invalidating a submitted roster;
- complete release, returned rows, and no partial release;
- INC release with the calculated one-year deadline, leap-date handling, amendment, `CompletionOpen`, `CompletionOverdue`, later controlled completion, stale/concurrent action, academic-average withholding, prerequisite/advising effects, and recalculation without automatic grade conversion;
- correction without a hard cutoff, append-only history, active-registration review, and issued-TOR supersession;
- PE/NSTP exclusion, neutral term weighted average, institution-authorized display terminology, all-attempt cumulative GWA, retake satisfaction, no attempt limit, and no arithmetic averaging of term values;
- partially released `GradesNotComplete`, unresolved-INC pending, available, and no-included-unit `NotApplicable` states with no partial or zero fallback;
- curriculum evaluation, credits/equivalencies, shifts, bridging, and no double counting;
- tracked-only external competency evidence, **Not recorded**, `NotYetCompetent` followed by superseding `Competent`, authority-gated completion effect, and no grade/average/unit/prerequisite/finance/email/TOR default;
- factual curriculum position; `Allowed`, `AdvisingRequired`, `Blocked`, and `PendingDecision` effects; nonstandard Individually Advised placement; and proof that failed-unit percentages never create sanctions;
- leave, withdrawal, transfer out, reactivation, and persistent portal history;
- final-term graduation application, failed/dropped final-term course, clearance, and conferral;
- completed-Student TALA Standard TOR, all attempts, no GWA, external request date, 30-day due date, exact preview, issue, void, replacement, supersession, continuation pages, and external physical-certification boundary;
- cross-role authorization, inaccessible-record behavior, email idempotency/failure, desktop/mobile, keyboard/screen-reader, stale-record, and concurrency behavior; and
- later DB-backed verification only against `test_tala_db`.

### 13.1 Realistic demonstration data

Use the coordinated 47-Student BM/IT/THM institution and its published classes, Registration Cases, and official roster membership for synthetic AY 2026–2027 records; never use real Student data:

- one official class with a designated Faculty submitter, one view-only co-Faculty member, and three officially enrolled Students;
- one passing result, one `5.00`, one `INC` resolved before its calculated deadline, one overdue `INC`, and one overdue deadline amended to a future date;
- one returned roster row and consolidated Registrar explanation;
- one later released INC completion and one separately authorized grade correction;
- one nonstandard curriculum position producing `AdvisingRequired` and one separate authority-backed decision producing a recorded effect;
- one final-term Student whose completion waits for a source-owned clearance;
- one completed former Student with an issued, later superseded TOR snapshot;
- official Special Term roster membership sourced from Clinic 4 `REG-2026-ST-001` under Clinic 3 `TERM-2026-ST`;
- coordinated `TERM-2026-ST` results for `CLS-ITE3-ST-A` (`1.75`) and `CLS-IT201-ST-R` (`2.50`), with the first class released while the second remains unreleased;
- prior cumulative evidence of 90 included units and 180 weighted grade points, producing Special Term `2.13` and cumulative `2.01` after both classes release;
- the earlier `IT201` `5.00` attempt retained in cumulative history while the passing retake satisfies the curriculum requirement; and
- `EXT-COMP-CSS-NCII`, one tracked-only synthetic external-competency requirement; `EXT-RES-CSS-001` `NotYetCompetent` followed by `EXT-RES-CSS-002` `Competent`; one missing tracked-only result that does not block completion; and hypothetical authority-backed `EXT-COMP-WEB-NCIII-REQ`, whose missing result keeps completion pending without establishing Servitech policy.

### 13.2 Browser acceptance walkthrough

1. As designated Faculty, open the official roster, enter the three controlled final results, and submit the complete roster; confirm that the view-only co-Faculty member cannot edit or submit.
2. As Registrar, return one specified row with one explanation; confirm that no official grade is released.
3. As Faculty, correct the returned row and resubmit; as Registrar, release the whole roster atomically.
4. As each Student, confirm that every released `INC` shows its calculated one-year inclusive deadline, responsible Registrar office, `CompletionOpen`, and an explicitly pending academic average.
5. Amend one deadline with authority and reason; confirm the replacement-deadline email, visible prior/current values, and that a stale concurrent amendment records nothing.
6. Record and release one INC completion before its deadline; confirm transactional revalidation, recalculated term/cumulative academic projections and curriculum evaluation, and history without overwriting the original `INC`.
7. Pass the inclusive deadline for another `INC`; confirm **Completion overdue**, no grade mutation or overdue email, dependent-course/completion blocking only, and continued controlled completion. Amend it to a future date and confirm it returns to `CompletionOpen` without erasing the overdue interval.
8. Record an authorized correction; confirm append-only history, recalculated projections, and Registrar review on any affected active Clinic 4 Registration Case.
9. Record one actual authority-backed academic/lifecycle decision and verify that Student, Registrar, Academic Head, and Clinic 4 receive only their authorized projections; a failed result without such authority produces advising rather than an automatic sanction.
10. Apply for graduation, resolve the named source-owned clearance, and record conferral; confirm the immutable degree and `Completed` history.
11. Generate an exact TALA Standard TOR preview for the completed former Student; satisfy Clinic 6 clearance and signatory inputs, then issue, void, replace, and supersede it through the authorized paths. Confirm continuation-page identity, monochrome output, immutable references, no Student self-issue, no partial artifact on failure, and no claim that unsigned output is CAV-ready.
12. Repeat the core Faculty, Registrar, Student, and TOR views at narrow width and by keyboard, including completion-open/overdue/amended, filtered-empty, inaccessible, stale, validation, concurrency, and mail-failure recovery states.
13. For `TERM-2026-ST`, release `CLS-ITE3-ST-A` while `CLS-IT201-ST-R` remains unreleased; verify **Grades not complete**, no partial term value, and the prior cumulative **Through [term]** value.
14. Release `CLS-IT201-ST-R`; verify the `2.13` term weighted average, `2.01` cumulative GWA from the full attempt/unit history, curriculum satisfaction by the retake, and retention of the earlier `5.00` attempt. Repeat the Student projection at 360/390 pixels and Staff detail at 1366 pixels using only neutral or Servitech-authorized terminology.
15. As Registrar, attempt to record an external competency result without an active authorized requirement or current evidence and confirm that nothing posts. Record `EXT-RES-CSS-001`, then the superseding `EXT-RES-CSS-002`; verify the safe result and history in Student Academics and Academic Oversight, that a missing tracked-only result says **Not recorded**, and that no grade, average, unit, prerequisite, finance, email, or standard-TOR effect appears. Open the hypothetical authority-backed `EXT-COMP-WEB-NCIII-REQ` state and confirm that its missing result alone keeps completion pending while making no claim that Servitech has adopted that requirement.

### 13.3 Authority-hardening control matrix

| Area or action | Actor and authorization | Validation/readiness | Confirmation, history, and limits | Failure/correction behavior |
|---|---|---|---|---|
| Grade-row Draft and complete-roster submission | Assigned designated Faculty; Registrar may return but not author the Faculty result | Every official roster row has one allowed result; numeric grades use the accepted increments; `INC` requires a completion note; no unassigned or duplicate learner; current roster/window/source; late submission requires recorded authority | Draft may be edited; submitted/released results cannot be deleted. **Submit complete roster** shows class, term, row count, unresolved warnings, and that Registrar review follows. No partial submission or arbitrary attempt cap | Invalid/stale/concurrent submission posts nothing and preserves safe row input. A returned roster reopens only named rows with one consolidated Registrar explanation |
| Roster release/return | Registrar with release authority | Complete submitted roster, current source, all row findings resolved, no stale successor | **Release roster** shows every row, effective result, Student/curriculum effects, email event, immutability, and audit. Return names rows and requires a 10–1,000-character reason. No partial release | Concurrent change blocks the whole action. Release is atomic; duplicate execution is idempotent. Later change uses grade correction |
| Released-grade correction | Registrar records an externally authorized decision | Original result/version, reason, authority/date, effective result, affected averages/curriculum/enrollment/output facts | **Record grade correction** creates one successor; no numeric lifetime cap, deletion, or edit-in-place | Conflict posts nothing. Affected active Registration Cases enter review; issued transcript snapshots remain immutable/superseded |
| `INC` completion | Faculty supplies authorized completion result; Registrar releases | Current unresolved `INC`, completion note/history, roster identity, current deadline/version, and applicable correction/release authority | **Release INC completion** shows result and recalculation effects; no arbitrary attempt cap | Transactional revalidation ensures only one successor. A stale deadline amendment or result action records nothing |
| INC deadline and amendment | System derives one year from Term end; Registrar may amend with authority | Original Term end, inclusive Asia/Manila calculation, current unresolved source/version; amendment requires prior/new date, authority/date, reason, actor | **Change INC deadline** shows the current/new deadline, resulting open/overdue state, email, and append-only consequence | Deadline passage derives `CompletionOverdue` but never a grade/email. A valid future amendment may reopen; conflict records nothing |
| External competency result | Registrar records verified external evidence | Active Curriculum Version requirement; result/date/source; predecessor for reassessment; optional NC/COC fields valid when present | **Record verified external result** states tracked-only or authority-backed completion effect; successor preserves attempts; no delete or email | Missing/stale/conflicting evidence posts nothing. `TrackedOnly` absence is **Not recorded**, not failed |
| Lifecycle event | Registrar records externally authorized leave, withdrawal, transfer, reactivation, shift, completion, or correction | Current lifecycle, authority/effective date, non-overlap, source records, affected registration/access projections | Consequential transition uses a named confirmation with resulting lifecycle and downstream effects; events are append-only | Conflicting or retroactive mutation is rejected. Correction creates a successor; no event creates a seat, refund, grade, or payment effect |
| Graduation Application and conferral | Student applies/withdraws own active application; Registrar records corrections/conferral | One active application per completion scope; final-term/result/clearance readiness; conferral authority | Application has no arbitrary resubmission cap while eligible. **Record conferral** shows degree, effective date, lifecycle, outputs, and irreversibility | Duplicate blocked; withdrawal preserves record. Missing sources produce `AwaitingResultsOrClearance`, never inferred completion |
| TOR preview, issuance, void, replacement | Registrar; Clinic 6 provides read-only clearance | Completed learner, verified identity, exact academic snapshot, request reference, current clearance, TALA Standard TOR version, signatory data, successful rendering | Preview creates no issue. Issue/void/replace each require consequence-specific confirmation. Reissue has no arbitrary cap but every version needs reason/authority | Output failure creates no partial artifact or event. Missing operational input names the owner; physical signature/seal/delivery/CAV remain external |

Shared validation primitives, stale/conflict behavior, critical-action audit fields, responsive states, and deletion rules come from baseline Section 11.4. All released academic, lifecycle, completion, and transcript records are append-only. The 30-day official-document duty remains sourced from BP 232; request intake, physical signatures, seals, claiming, delivery, and CAV remain institution-owned.
## 14. External Boundaries

The INC deadline and overdue behavior are complete TALA product rules. No automatic grade conversion or grading-policy engine exists. TALA Standard TOR — Servitech v1 is ready for Registrar preview and issuance when its operational data and source readiness pass. Physical signing, sealing, claiming, delivery, courier, CAV, diploma, and ceremony remain external and do not leave product behavior unresolved. Personal-data handling follows the product-wide no-automatic-disposal boundary.
## 15. Assumptions

- TALA targets a normally recognized and authorized Philippine college.
- The final-grade vocabulary comes from the supplied Servitech workbook.
- PE/NSTP/CWTS/LTS/ROTC exclusion from both academic-average projections is client-confirmed Servitech policy.
- The supplied evidence does not establish a Servitech INC deadline or automatic lapse rule. TALA therefore adopts the bounded one-year completion deadline and rejects automatic grade conversion.
- No other institution's academic-progress thresholds, sanctions, terminology, or load rules govern Servitech. TALA records factual curriculum effects and only an actual authorized institutional decision may block enrollment.
- External institutional decisions are recorded rather than recreated as multi-step approval systems.
