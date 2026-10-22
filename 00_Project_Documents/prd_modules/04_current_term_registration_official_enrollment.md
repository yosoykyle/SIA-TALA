# PRD 04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop
## Authority

This PRD is the complete authority for one Registration Case per learner and Term, proposal versions, confirmation, placement, finance readiness, atomic Official Enrollment, Student activation, cancellation, adjustment, Course Drop, and COR. It is understandable without legacy enrollment/COR PRDs or current implementation.
## 1. Purpose and boundary

Clinic 4 owns the complete journey:

`Eligible enrollment case opens → unambiguous standard proposal or Registrar advising → temporary reservation → assessment → learner confirmation and payment/coverage → Registrar finalization of ready cases → official enrollment and COR → controlled adjustment or Course Drop`

It consumes Clinic 2's `ReadyApplicantProjection`, Clinic 3's active curriculum and published `ClassOffering` facts, Clinic 5's released academic-result and lifecycle facts, and Clinic 6's enrollment-payment requirement. It consumes the current attributable Registrar enrollment clearance without copying an individual paper-document checklist or post-enrollment follow-up workflow. It does not recreate admissions, curriculum approval, timetable generation, grading, credit-evaluation approval, readmission approval, shifting approval, refund decisions, or payment operations.

Curriculum evaluation remains the academic authority. Clinic 4 stores versioned proposed course registrations within the current `RegistrationCase`.

One enrollment engine supports ordinary and exceptional degree students. TALA records externally approved institutional decisions when another office owns them; it does not recreate those offices' approval workflows.

## 2. Terminology, ownership, and supported circumstances

### 2.1 Selection basis, not learner type

TALA does not store `Regular` or `Irregular` as policy-driving Student statuses. Every case uses one `EnrollmentSelectionBasis`:

- `StandardCurriculum` — TALA derives the expected curriculum courses and normal cohort classes.
- `IndividuallyAdvised` — Registrar prepares a bounded proposal from the assigned curriculum, released results, approved credits/equivalencies, and actual published classes.

Selection basis is independent from academic progress, academic enrollment effect, Applicant/Student identity, and funding or payment arrangement. Transferee, returning, shifted, graduating, failed/back-course, and old-curriculum circumstances are facts affecting evaluation; they are not separate enrollment engines.

Verified identity and existing Applicant/Student, Program, curriculum and Term context are reused rather than re-entered. Collect a new value only for a concrete registration purpose that an existing source cannot reasonably satisfy. This applies the purpose and proportionality principles in [NPC Data Privacy Act IRR §§18–19](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/); it establishes no blanket retention period or permission to erase academic or financial records. Student-facing language is **Proposed subjects and schedule**. Registrar prepares the academically eligible selection; the learner confirms that exact proposal. Clinic 3's actual scheduled term class is `ClassOffering`.

### 2.2 Supported circumstances

The same journey supports:

- New first-year and transferee Applicants.
- Standard continuing Students.
- Failed or back-course Students and cases with pending or incomplete results.
- Cases whose current `AcademicEnrollmentEffect` is `Allowed`, `AdvisingRequired`, `Blocked`, or `PendingDecision`.
- Individually Advised continuing-Student cases whose failed, incomplete, or unreleased prerequisites exclude only the dependent courses while unrelated eligible courses remain available.
- Returning/reactivated and shifted Students after Clinic 5 records the authorized outcome.
- Old-curriculum, bridging, approved-equivalency, and transfer-credit cases.
- Graduating and approved Special Term cases, including an exact authorized individual assessment when a fixed Program-and-Term Fee Plan cannot represent the current reserved proposal or authorized confirmed change.
- Full-payment, installment, and externally approved scholarship, sponsorship, government-subsidy, or other authorized-funding coverage cases. Coverage processing remains external; Clinic 6 records only its approved Term Account effect.

Incoming/outgoing cross-enrollees, foreign-student processing, second-degree, non-degree/special, refresher students, course shopping, ranked waitlists, petitions, advising appointments, internal chat, and academic approval chains are outside MVP. TALA may consume an externally approved credit or equivalency outcome affecting a Servitech Student.

## 3. Entry, windows, outcomes, and checkpoints

### 3.1 Entry and deadlines

- A Ready Applicant clicks **Start enrollment**. Readiness alone creates no registration, Student profile, Student number, assessment, or seat.
- Registrar starts an applicant's enrollment from a searchable, paginated Ready applicants page containing only cleared applicants without an existing case in that exact term. Existing cases remain in Enrollment records. The selected applicant, program and admission term are shown read-only. Routine applicant/continuing starts require no intake reference entry; actor, time and source are recorded automatically. A late start still requires its specific institutional approval. Readiness, source ownership and the enrollment window are rechecked at submission.
- Clinic 2 clearance changes before finalization flag the current case for source revalidation. A superseded/invalid clearance cannot finalize enrollment; it never silently deletes a case, copies admissions data or reverses an already official enrollment.
- A continuing Student clicks **Start registration**. Existing Students cannot reapply through Admissions. Registrar starts ordinary continuing enrollment by selecting the Student and exact Term; TALA derives the assisted method and enforces the published window and learner group. There is no timing choice or routine reference input. **Approved late enrollment** is a distinct Registrar action requiring the existing institutional approval reference. An ordinary refusal never switches to a late exception automatically.
- Returning and shifted Students enter only after Clinic 5 records the applicable authorized lifecycle result.
- Clinic 3's neutral `Enrollment` operational window owns the approved opening and closing dates. Registrar selects who may start during those dates: Ready Applicants, Standard continuing Students, Individually Advised continuing Students, or all otherwise eligible learners. The selected Term's existing setting is shown before an intentional change. Actor, time and before/after values are recorded automatically; no separately typed approval reference is required for this routine setting. Earlier supplied references remain in activity history. This fixed vocabulary changes neither published dates nor genuine late-enrollment approval requirements.
- Each deadline uses an inclusive Asia/Manila date and an exact cutoff only when an approved source supplies one.
- After the applicable ordinary window, self-start is blocked. Late enrollment requires recorded institutional authority; TALA invents no late fee or automatic exception.
- After the final authorized cutoff, an unfinished case becomes `NotEnrolled`. No subjects, seats, assessment, or eligibility determination automatically carries into a later term. Reopening the same-Term case after that cutoff requires exact late-enrollment authority.

### 3.2 Stored outcomes and derived stage

`RegistrationCase` stores only these outcomes:

- `Active`
- `OfficiallyEnrolled`
- `CancelledByLearner`
- `CancelledByRegistrar`
- `NotEnrolled`

Readiness summarizes the following real checks; these are not typed hold gates, waiver records or a configurable engine:

1. Student eligibility.
2. Confirmed proposed subjects.
3. Valid class placement.
4. Accounting clearance through verified payment, Approved Coverage, a mixed basis, or an institutionally authorized `NoPaymentRequired` basis.
5. Registrar finalization.

`Action Needed`, responsible owner, reason, evidence, and next action are projections. TALA has no configurable enrollment state machine, universal override record, or generic gate engine.

These are finalization checks, not a chronological screen sequence. Prepare the valid proposal, reserve its exact classes and resolve its assessment before asking the learner to confirm; payment/coverage clearance and Registrar finalization follow. A missing source names its owner and recovery rather than asking the learner to confirm incomplete information.

### 3.3 Consolidated State and Action Matrix

**Identity Distinctions:** `Ready Applicant != Registration Case != Official Enrollment != Student Identity != Student Number`. A Ready Applicant has a current admitted decision, resolved identity warnings and matching Registrar enrollment clearance after external school checks; they do not hold a student number or student profile until official enrollment.

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| No case / entry available | Start enrollment or registration | Ready Applicant, continuing Student, or Registrar | Own eligible identity; Registrar-assisted start trigger ("Start Registration") with recorded authority | Applicable Clinic 3 window open or valid late authority; Clinic 2/5 eligibility source current | One `Active` Registration Case for learner/term | Retry is idempotent; a closed case does not carry into another term | Registrar workbench (`REG-E01`/`REG-E02`) can trigger case creation; no Student identity is created by start |
| Proposal in preparation/revision | Prepare Standard Curriculum or Individually Advised proposal | TALA derives an unambiguous standard proposal; Registrar handles deviations/revisions | Authorized proposal derivation/Registrar action; external authorities recorded when genuinely required | Assigned curriculum, released Clinic 5 results, actual Clinic 3 classes, no unresolved academic block | New immutable proposed-registration version | Later version supersedes the active proposal without erasing prior versions | Learner sees only an issued version and **Why these subjects**; Accounting sees no course authority |
| Placement/reservation valid or shortage | Place or change class; reserve or release seat | Registrar/TALA transaction | Placement authority and current proposal | Published class, requisite and conflict validity, protected capacity, current lock/version | Seat reservation or `EnrollmentShortageItem` with owner/deadline | Expiry/release changes current projection but preserves evidence; no ranked waitlist | Clinic 3 receives unmet-demand projection; Faculty still sees no roster until official enrollment |
| Finance unavailable/pending/cleared | Record or verify the exact assessment, payment requirement, verified payment, or Approved Coverage | Accounting through Clinic 6 finance authority | Accounting assessment/clearance authority | Current `PublishedFeePlan` or eligible `AuthorizedIndividualAssessment`, exact proposal/change source, and valid finance evidence | `EnrollmentPaymentRequirementProjection` becomes `Unavailable`, `ActionNeeded`, or `Cleared` | Later finance event updates projection; it does not select courses or finalize enrollment | Learner/Registrar see assessment basis, payment/coverage amounts, satisfaction basis, and clearance status; Accounting sees bounded enrollment context |
| Waiting for learner / confirmed | Issue proposal; confirm online or record assisted confirmation | TALA/Registrar then learner/Registrar | Own confirmation or recorded assisted-confirmation authority | Current issued, reserved and assessed version; stale version rejected; no arbitrary learner course shopping | Learner confirmation tied to exact proposal version | A material revision requires a new confirmation; history remains | Registrar sees confirmation; learner sees exact confirmed subjects/schedule |
| Ready to finalize / `OfficiallyEnrolled` | Finalize official enrollment | Registrar | Registrar finalization authority | All current readiness checks pass in one current transaction | Official term/course registrations, Student activation if first enrollment, immutable COR version | Finalization is not reversed by mail failure; post-finalization changes use adjustment/drop records | Faculty roster, Student schedule, Accounting reference, source-linked admissions continuity, and COR update together |
| `CancelledByLearner`, `CancelledByRegistrar`, or `NotEnrolled` | Cancel before finalization, expire at final cutoff, or reopen the same-Term case | Learner cancels own case; Registrar cancels or reopens | Self-cancel within boundary; recorded Registrar authority for cancellation/reopen | Case not officially enrolled; deadline/outcome current; reopen before final cutoff or with exact late-enrollment authority | Seats released and terminal outcome recorded, or same case returns to `Active` with a reopen event | Terminal history remains; reopen restores no proposal, confirmation, seat, Assessment validity, clearance, or eligibility fact automatically; monetary history remains under PRD 06 | All roles see safe current/history projection; no official roster/COR is created by reopen |
| Academic-result impact review | Receive an initial release, INC resolution, or correction affecting an active case | TALA routes; Registrar owns review | Released Clinic 5 event and any current prerequisite/late-adjustment authority | Exact result/case versions current; affected proposal/registration identified | Before finalization, proposal/placement becomes stale and returns to preparation when no longer valid; after finalization, review records the owner and possible authorized outcome | No course changes automatically; duplicate delivery returns the same review; later result/decision supersedes the projection | Learner Enrollment/Academics and Registrar show the source, affected course, owner, and next action; Accounting/Faculty change only after an applied official change |
| Official adjustment | Record authorized post-enrollment change | Registrar | Open Adjustment window or specific recorded late-adjustment authority, plus exact change evidence | Current official version; valid replacement/placement; learner confirmation where material; an add/replacement has `Cleared` additional requirement or authoritative no-additional-amount result | Updated official registrations, placement, roster, schedule, finance projection, new COR version | Prior COR and assessment remain immutable; change is append-only | Student, Faculty, Accounting, and Registrar projections update together |
| Course removal or Course Drop | Record approved removal/drop outcome | Registrar | Owning institutional authority recorded | Official registration exists; academic effect valid; financial effect may remain under Accounting review | Removal/drop record, roster/schedule update, `AccountingReviewPending`, new COR version | Does not invent refund, credit, fee, forfeiture, or grade; prior official record remains | Clinic 5 receives owning outcome; Accounting receives the exact change source for review |
| Timetable-impact review | Clinic 3 proposes published change affecting placements | Registrar | Clinic 3 revision plus Clinic 4 enrollment-impact authority | Every affected reservation/official placement has a valid outcome | Replacement, authorized course cancellation, or recorded approved outcome | No silent learner move; Clinic 3 revision publishes only after impacts resolve | One Clinic 3-owned revision event uses Clinic 4 recipients/context |
| Official Student profile correction | Record an authorized correction to a source identity/program/curriculum/entry/contact fact | Registrar | Student-record correction authority with reason and evidence | Existing Student profile; current source/version; no conflicting active correction | Append-only correction and current Student profile projection | Issued COR/TOR snapshots are never rewritten; a changed official output uses a successor version | Future Clinic 4/5/6 views use the corrected source while history retains the prior fact |

### 3.4 Terminal case reopening

- There is exactly one `RegistrationCase` per learner and Term. Reopening never creates a successor or duplicate case.
- Registrar may reopen `CancelledByLearner`, `CancelledByRegistrar`, or `NotEnrolled` to `Active` before the final authorized enrollment cutoff. After that cutoff, the same action requires exact late-enrollment authority for that learner and Term.
- Reopening within the published final enrollment cutoff requires an authorized Registrar, a reason, current identity/Term/eligibility evidence, and an impact preview. TALA records the actor and time automatically; no separately typed approval reference is required. After the cutoff, reopening consumes an unused recorded late-enrollment approval for this exact case and Term and reuses its stored external reference. Recording that reference does not reopen the case; it continues to the same guarded review with that exact source fixed. The review still requires its own reopening reason. A source from an earlier terminal cycle or a consumed source cannot be reused.
- Reopening restores no prior proposal validity, learner confirmation, reservation or seat, Assessment validity, Accounting clearance, or eligibility determination automatically. Recalculate each source and checkpoint. Historical Assessments, verified payments, coverage and obligation applications remain retained in the same Term Account. Accounting validates the current Assessment/obligations under PRD 06; a still-valid source can satisfy the new checkpoint, while a changed source requires an attributable successor. Reopening never charges the same obligation twice, erases payment, transfers money or infers a refund.
- Without the required authority, the terminal case remains read-only. A learner may use the existing help/request path but cannot reopen a terminal case directly.

### 3.5 Action readiness matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Student eligibility | Clinic 2 Ready Applicant or Clinic 5 released result/lifecycle/progress effect | Registrar consumes; Clinics 2/5 own sources | Applicant is ready or Student has `Allowed`/authorized `AdvisingRequired`; no unresolved block/decision | Case creation is blocked when entry eligibility fails; placement is blocked only for courses whose eligibility fails; finalization is blocked when the current effect becomes `Blocked`/`PendingDecision` | Start case; prepare proposal; finalize | Correct the owning source or revise the proposal; never create a local/general override |
| Confirmed proposed subjects | Current proposal version and learner confirmation | Registrar and learner | Proposal matches authoritative curriculum/results/classes and exact current version is confirmed | Finalization blocked; valid provisional reservation and assessment may precede confirmation | Finalize enrollment | Revise/issue proposal and obtain new online or assisted confirmation |
| Valid class placement | Clinic 3 published classes plus reservations, requisites, conflicts, and capacity | Registrar | Every proposed recurring course has a valid protected placement or approved no-meeting treatment; every prerequisite is satisfied by a released result or exact approved-credit mapping | Only the affected course is excluded; shortage owner/deadline shown | Finalize official enrollment | Resolve shortage, wait for and release the authoritative result, revise the proposal, or revise timetable through Clinic 3; no provisional requisite bypass |
| Accounting clearance | Clinic 6 assessment and enrollment-payment requirement plus verified payment and Approved Coverage evidence | Accounting | Assessment basis is `PublishedFeePlan` or eligible `AuthorizedIndividualAssessment`; source is current; required amount is satisfied by verified payment, Applied coverage, a mixed basis, or `NoPaymentRequired` | `Unavailable` or `ActionNeeded`; finalization blocked without a fallback | Finalize official enrollment | Publish the ordinary Fee Plan, record an eligible exact individual assessment, verify payment, or record current Approved Coverage in Finance |
| Registrar finalization | Current case, four prior checkpoints, identity/term/version guards | Registrar | Current facts pass atomically and Registrar records finalization | Learner remains not officially enrolled; no roster/COR/Student activation | Create official enrollment and COR | Refresh stale facts, resolve named blocker, then retry idempotently |

## 4. Academic authority and proposed registrations

### 4.1 Released academic-result handoff

Clinic 4 uses only Clinic 5's released official results. Draft or submitted-but-unreleased grades never determine eligibility. Under the accepted Clinic 5 final-grade policy, `1.00–4.00` is `Satisfied`, `5.00` is `Unsatisfied`, and `INC` is `Incomplete`; Course Drop, full withdrawal, and approved credit retain their owning non-Faculty outcomes.

`OfficialCourseResultProjection` provides `Satisfied`, `Unsatisfied`, `Incomplete`, `WithdrawnOrDropped`, or `ApprovedCredit`. `Pending` exists only as an unreleased internal projection and cannot satisfy a requisite or drive placement.

`AcademicEnrollmentEffect` provides:

- `Allowed` — ordinary processing may continue.
- `AdvisingRequired` — Registrar prepares an Individually Advised proposal.
- `Blocked` — placement and finalization cannot proceed.
- `PendingDecision` — Clinic 4 waits for Clinic 5's authoritative outcome.

Failure alone does not automatically dismiss a Student, impose probation, reduce load, or create an institutional eligibility label. Clinic 5 owns factual curriculum evaluation and any actual authorized consequential decision. Ordinary eligible placement yields `Allowed`; failures, deficiencies, shifts, bridging, retakes, and other nonstandard placement yield `AdvisingRequired`; only a recorded authority or incompatible lifecycle state yields `Blocked`, and only an opened review or unresolved authoritative source yields `PendingDecision`.

Every initial result release, INC resolution, and authorized correction recalculates Clinic 5's result, GWA, curriculum evaluation, and progress recommendation. If it affects an active Registration Case, Clinic 4 routes that exact case to Registrar review and never silently adds, removes, replaces, or treats an officially registered course as safe to retain by assumption.

### 4.2 Standard proposal

For `StandardCurriculum`, TALA:

1. derives expected courses from the assigned Curriculum Version and applicable curriculum term;
2. maps them to published cohort Class Offerings;
3. proposes the block when exactly one complete valid block exists;
4. requires Registrar choice when multiple complete valid blocks exist; and
5. routes academic, credit, capacity, conflict, or availability deviations to individual advising.

### 4.3 Individually Advised proposal

- Failed required courses remain unsatisfied and may be included only when offered.
- A failed prerequisite blocks only its dependent chain. Unrelated eligible curriculum courses remain available.
- Pending and incomplete results do not satisfy prerequisites. Their dependent courses are excluded while unrelated eligible courses remain available.
- An unavailable subject is never replaced by an arbitrary unrelated subject.
- Approved credits and equivalencies satisfy only their mapped curriculum requirement.
- A transferee cannot be finalized while required credit evaluation is missing; the UI says **Academic evaluation required**.
- Registrar records the already-approved transferee academic evaluation in the existing enrollment case before selecting subjects. The record binds the current admitted submission and an active curriculum in the admitted Program. It records mapped credited curriculum subjects, or an explicit approved no-credit result, plus the actual approval reference/date; learner identity, actor and recording time come from existing sources. No grades, duplicate identity fields, uploads, essays or approval chain are added. Only mapped requirements may be satisfied, and only where the prerequisite accepts transfer credit. A credited subject is not selected again for enrollment.
- Corrections append a replacement evaluation; they never erase the earlier decision. A proposal binds its exact evaluation. A changed submission, curriculum or evaluation requires a fresh proposal and confirmation before finalization. Concurrent/stale forms cannot replace a newer evaluation. After official activation, the same recorded credits remain available to the Student curriculum evaluation and later registration; recording the evaluation alone creates no Student identity, enrollment, seat or financial effect.
- A shift applies only from its recorded effective term. Historical enrollment remains under the previous program.
- Returning Students reuse the continuing journey after authorized reactivation; reactivation reserves no seat.
- Old-curriculum and bridging cases require an assigned Curriculum Version and approved deficiency/equivalency mapping, never free-text subjects.
- Institution-selected electives are assigned to the cohort before scheduling; there is no learner elective marketplace.
- The normal ceiling is the approved curriculum term total. There is no universal irregular maximum.
- Reduced enrollment is allowed when some required courses are unavailable; missing requirements remain visible.
- Overload requires a specific externally approved graduating exception. TALA hard-codes no universal overload quantity. Registrar records the existing school approval reference and actual approval date against the current proposed subjects and exact curriculum/proposed load. No copied evidence reference or paraphrased approval basis is required. TALA records the actor, time and reviewed scope automatically, using a factual record description rather than inventing the school’s rationale. Earlier distinct supporting evidence and explanations remain intact; recording the same current approval again reuses it. This records an external decision and cannot grant an overload approval.
- A dependent course requires its released satisfied prerequisite or exact approved credit/equivalency. A later satisfying result uses the ordinary Adjustment path in Section 4.4. A declared corequisite is satisfied by that prior evidence or valid concurrent enrollment in the same Term. Validate every required concurrent course, its prerequisites, placement, capacity and confirmation in the complete proposal/finalization transaction; a missing companion blocks the affected group. Adjustment/removal rechecks that group and routes an incompatible official change to the owning Registrar outcome.

Student Academics and Registrar Student Record in Clinic 5 own the full curriculum evaluation. Clinic 4 shows a compact **Why these subjects** explanation and links to that evaluation.

### 4.4 Unreleased prerequisite handling

TALA does not create a provisional prerequisite permission, substitute grade, or special pending-result enrollment state.

- An unreleased, failed, incomplete, withdrawn, or dropped prerequisite does not satisfy the dependent course.
- Registrar issues the current proposal with the dependent course excluded and keeps unrelated eligible courses available, so the learner may complete ordinary enrollment instead of waiting for every prior-term grade.
- When a released `Satisfied` result or exact `ApprovedCredit` mapping later makes the dependent course eligible, Registrar may add it through the ordinary Adjustment path while the window is open. The add still rechecks learner confirmation, capacity, timetable, load, and any additional Clinic 6 clearance.
- After the Adjustment window closes, the same exact late-adjustment authority required for any other late add is required. TALA creates no separate prerequisite-override workflow.
- If a later authorized grade correction makes a prerequisite unsatisfied after Official Enrollment, the current enrollment and COR remain intact while one idempotent academic-result impact review opens. Only an authorized ordinary Adjustment or Course Drop may change the official record.

This conservative default follows a mature-SIS pattern in which institutions may either include or exclude in-progress coursework from requisite evaluation; TALA chooses the completed-and-released rule because no Servitech policy authorizes provisional satisfaction. The dated PUP ACE workflow is comparison evidence that missing subjects can be added through a controlled adjustment process rather than a separate learner type or provisional grade.

### 4.5 Learner confirmation

- The learner reviews the complete proposed subjects and classes, current temporary reservation and authoritative assessment before confirming the exact proposal once. Initial placement and assessment preparation do not require prior learner confirmation; finalization still requires current confirmation and all other checks.
- Registrar may record confirmation the learner has already given outside TALA. TALA binds the exact reviewed proposal and learner and records the assisted method, Registrar and time automatically; the routine form asks for no evidence or approval reference. Previously recorded supporting references remain in history. Exact late, overload, after-cutoff reopening and other genuine exceptional school authority/evidence remain required.
- A material course, Class Offering, meeting schedule, eligibility, curriculum, or credit change creates a new proposal version and requires confirmation again.
- Non-material display or evidence corrections do not require reconfirmation.
- If the proposal appears wrong, the page says **Do not confirm—contact Registrar** and shows official contact details. TALA creates no ticket, chat, appeal, or request-help workflow.

## 5. Placement, capacity, and shortage handling

- Clinic 3 creates Draft Class Offerings from active curricula, confirmed standard-curriculum cohorts, forecasts, and bounded `UnmetClassDemandProjection` evidence. Registrar confirms, splits, shares, adds, or cancels them before scheduling.
- Separate Class Offerings per cohort are the default. Sharing requires an explicit Registrar decision for the same canonical course or approved equivalency, sufficient capacity, a valid timetable, and appropriate authority. CP-SAT never merges cohorts automatically.
- Continuing-cohort capacity remains protected until the applicable deadline. Admission does not consume capacity.
- Opening an eligible case derives an unambiguous standard proposal and transactionally reserves its exact proposed classes before assessment and learner confirmation. Individually Advised cases use the same sequence after Registrar prepares the valid proposal. Reservation succeeds only when all required placements pass current capacity and source-version checks atomically; a full/stale class retains the proposal and exposes the affected shortage and Registrar recovery action without a successful reservation or assessment. The learner sees the temporary reservation, expiry and exact assessed proposal before confirming/payment. Opening an Enrollment window creates no cases in bulk and does not enroll a learner or redirect immediately to payment.
- Reservations expire at the applicable institutional enrollment/payment deadline, not an individual countdown. Expiry releases seats, preserves the case and payment evidence, records the reason, notifies the learner, and requires replanning.
- A full or stale class creates a Registrar shortage item. No assessment is finalized from failed placement.
- The shortage queue shows affected course and learners, current/protected capacity, valid alternatives, academic impact, and aggregate unmet demand.
- Resolution may use another valid class, a safe capacity amendment, or an externally approved Additional Offering. No ranked waitlist or first-confirmed future entitlement is introduced.
- A capacity-only amendment needs no CP-SAT rerun only when it stays within the assigned room's physical capacity and changes no Faculty, room, time, mode, or meeting. Every other change follows Clinic 3's timetable-revision process.

TALA targets the earliest valid completion path. It preserves the original cohort completion date only when feasible and presents a bounded outlook: `On target`, `At risk`, or `Future opportunity not yet confirmed`. It never invents future classes or graduation dates.

## 6. Finance clearance and official finalization

Clinic 4 consumes `EnrollmentPaymentRequirementProjection` containing total assessment; `AssessmentBasis = PublishedFeePlan | AuthorizedIndividualAssessment`; the exact proposal or change reference; applicable Accounting authority; amount required now; verified payment applied; Approved Coverage applied; remaining amount required now; state; `SatisfactionBasis = VerifiedPayment | ApprovedCoverage | Mixed | NoPaymentRequired | None`; payment/coverage references; source/as-of time; later-obligation indicator; and Student Account link.

Ordinary registration requires a current published fixed Program-and-Term Fee Plan. Approved Special Term, reduced, or Individually Advised cases may instead use an exact externally calculated `AuthorizedIndividualAssessment` when the fixed plan cannot represent the current reserved proposal or authorized confirmed change. Clinic 4 never calculates fees from units, reduces an assessment because fewer subjects were proposed, or substitutes zero when neither source is ready. `Unavailable` blocks finalization and names Accounting as the source owner.

Official enrollment requires satisfaction of the current authoritative payment requirement, including applicable verified coverage or zero-due basis. Registrar Enrollment work within Students & Enrollment and Accounting Student Accounts are focused views of the same records; they create no duplicate records or rebuild. Action-scoped restrictions identify the responsible office, reason, exact blocked action, source and history. Their removal follows their owning source rather than a typed five-gate hold/waiver engine. Real eligibility, identity, payment, capacity and conflict checks remain required. Failed checkout or unverified evidence never posts payment. Scholarship, sponsorship, subsidy, and other funding eligibility remain external; TALA consumes only Clinic 6's applied, source-keyed coverage effect. A later missed installment or post-enrollment coverage reversal cannot erase an already official enrollment. Examination access, reenrollment, record release, and login use service-specific effects; blanket, wildcard, or global financial holds that block all services or login are prohibited. Other consequential restrictions follow baseline Section 3.7 and the exact owning source/action contract; a schema flag cannot create an extra checkpoint. Registrar records externally authorized academic/administrative effects, while Academic Head remains read-only in TALA. Clinic 6's `EnrollmentPaymentRequirementProjection` remains a separate source-specific financial calculation.

Applicant, Registrar and Accounting views resolve the same person/application, exact Term, current Registration Case and linked account/assessment versions. Show the person's name and the appropriate application, case or Student reference without inventing a Student number before official enrollment. Missing or conflicting sources produce an unavailable state with the source owner and remedy, rather than blank labelled facts or a substitute Term. Registrar's admissions clearance permits entry to registration; Accounting's current finance result gates official finalization, not the admissions decision or paper-clearance action. The learner reviews the proposed schedule before confirmation/payment and receives the official Student schedule only after successful enrollment.

The coordinated `REG-2026-ST-001` Special Term case uses Clinic 3's `TERM-2026-ST`, `CLS-ITE3-ST-A`, and `CLS-IT201-ST-R`. A prior failed `IT201` excludes dependent `IT301` only; the retake and unrelated planned Special Term course remain eligible in one `IndividuallyAdvised` proposal. Clinic 4 publishes no fee amount until Clinic 6 supplies the exact `AuthorizedIndividualAssessment`. PHP 2,000 Applied coverage plus PHP 1,000 verified payment satisfies the PHP 3,000 enrollment obligation as `Mixed`; Registrar then finalizes the same case and produces its immutable COR snapshot.

For every term, **Finalize official enrollment** atomically:

1. locks and revalidates all current readiness checks;
2. rejects stale, expired, conflicting, or out-of-order facts;
3. converts reservations into official course registrations;
4. activates Student schedule and Faculty roster projections;
5. records the official term enrollment;
6. creates immutable COR version 1 for that term;
7. publishes account and academic effects; and
8. queues one official-enrollment/COR email after commit.

Only on the person's first official enrollment does the same transaction also create the minimal Student profile, generate permanent `SIA-YYYY-NNNN` using the first-enrollment calendar year and an atomic unique sequence, grant Student access to the existing account, and retain the completed application as admissions evidence. The number encodes no program, year level, cohort, or section. The single official-enrollment/COR message also explains that Student access is active; no separate activation email is sent.

The minimal profile reuses the current verified Clinic 2 identity/contact facts and adds student number, entry term, program, curriculum assignment and initial lifecycle state. Finalization revalidates those source facts and their matching current admissions clearance without another learner identity/contact confirmation. Missing or stale identity evidence uses the existing source-owner correction path; it never creates an unverified profile. Sex, detailed permanent address, emergency contact, civil status, religion, income, ethnicity, medical data, scholarship data, and broad family data are not Clinic 4 requirements. Any future additional official identity or reporting field requires applicable institutional/legal authority and an approved revision to its source and consuming-output authorities; Clinic 6 cannot add it implicitly.

Continuing Students reuse their account, profile, and number. Finalization is idempotent and cannot create duplicates. Mail failure never reverses the transaction.

Registrar may select ready cases for batch finalization. Every case rechecks current eligibility, identity, curriculum/results/proposal/confirmation, reservation expiry, capacity, conflicts, payment/coverage or zero-due basis in its own atomic transaction. A failed or stale case reports its own reason and creates no partial enrollment; other valid cases may complete. Official Student number/identity and COR exist only after successful finalization.

### 6.1 Official Student profile continuity and correction

Clinic 4 owns the minimal official Student profile after first enrollment. The Student sees a read-only Profile containing legal identity, Student number, program, assigned Curriculum Version, entry/admission reference, current lifecycle projection, and approved contact facts, with plain guidance for requesting correction. Credential email, password, MFA, and Account Security remain Clinic 1 responsibilities.

Students do not directly edit official identity, program, curriculum, entry, lifecycle, enrollment, grade, or finance facts. Registrar records an authorized correction with reason, authority/evidence reference, actor, effective time, prior value, and successor value. Current source projections refresh, but an issued COR or TOR snapshot is never rewritten. When corrected content belongs in a later official output, its owning clinic creates a successor version and preserves supersession history. TALA does not collect broader demographics merely because a legacy form or schema contains them.

## 7. Cancellation, adjustment, Course Drop, and timetable impact

### 7.1 Before official enrollment

- Before learner confirmation, the learner may cancel an active case directly after reviewing its effects. No reason or reference entry is required; TALA records the learner's cancellation choice, actor and time. An authorized Registrar records any later reopening reason in that separate task; after-cutoff reopening additionally consumes its recorded late authority.
- Registrar may record cancellation of an in-progress case with a reason; after learner confirmation, only Registrar may cancel it. Actor, time, exact case and current proposal are recorded automatically. No separately typed approval reference is required. Cancellation releases temporary seats; it cannot cancel an official enrollment. Earlier supplied references remain in history.
- Accounting reviews payment evidence separately. Cancellation never deletes evidence or automatically refunds, credits, or reallocates money.

### 7.2 After official enrollment

Adjustment and Course Drop are distinct rule sets presented contextually in the same Enrollment workbench:

- **Adjustment** is an authorized add, remove, replacement, or class change during the adjustment window.
- **Course Drop** is an authorized removal after adjustment but within the applicable drop window.
- Requests and institutional approvals occur outside TALA; Registrar records the approved decision, authority, evidence, and system effects.
- Ordinary-window adjustments and Course Drops derive the case, current proposal/course/COR, actor and time. They require no separately typed Registrar approval reference. Existing supplied references remain in history. Late changes fix one exact currently eligible approval and show its recorded reference, approving office and date; multiple matches retain a choice. No match keeps submit blocked and offers recording approval from the selected exact task. A changed subject clears the prior source and binds it again only when one exact match remains; server validation rejects changed or consumed sources without substituting another. Genuine Accounting authority and the Course Drop reason remain part of their owning decisions.
- During the exact Term's Adjustment window, an approved change uses the ordinary guarded adjustment action.
- After that window closes, Registrar records the existing school approval from the selected subject-change or Course Drop task. The case, Term and exact before/after subjects are fixed by that task; the operator supplies the actual approving office, approval reference and approval date. A recorded confirmation for the current change supplies student acknowledgement automatically. Otherwise, identify the actual student request or recorded acknowledgement for this change. Additional notes and a separate academic decision are optional when genuinely relevant; they do not replace academic eligibility checks. The form records school approvals already valid for the selected change. No separate effective-time entry is collected; the guarded adjustment/drop transaction records when the change actually takes effect. A future-effective permission must not be recorded as immediately usable. Any retained approval with an explicit future effective time remains unusable until that time. Recording approval applies no change, and cannot bypass confirmation, academic, timetable, capacity or Accounting requirements. Reopening the same approval preserves its original evidence and attribution; a reused reference with conflicting scope, office/date or consumed status is refused.
- Without late-adjustment authority, current Official Enrollment, roster, schedule, account projection, and COR remain unchanged. Student and Registrar see **Adjustment period closed**, the responsible office, what remains usable, and the next permissible institutional path. TALA invents no manual backdoor or future entitlement.
- An add or replacement with a cost increase waits for a successor Assessment version and clearance of the newly required amount. If the financial source is unavailable, the change remains unapplied.
- A no-additional-cost add, replacement, or class change requires Accounting's unused no-additional-cost confirmation for the exact Registration Case, current course registration and replacement class. An Assessment alone does not authorize this path; unchanged class-only placement is not treated as a fee calculation.
- Accounting opens that review from the exact learner-confirmed pending change. The form derives its current subject and proposed class and shows them read-only; Accounting records the genuine external financial approval rather than selecting the same change again. A changed proposal, confirmation, current registration or class source requires reopening the review before recording a result.
- An authorized removal or Course Drop takes academic and roster effect when recorded and creates `AccountingReviewPending`. It does not wait for or imply a refund decision.
- A Course Drop references an officially enrolled course without a released final result.
- Dropping every active course is full withdrawal and belongs to Clinic 5.
- Late drops require specific authority.
- Each applied change updates placement, roster, Student schedule, the source-keyed account-review projection, and immutable COR version together. A later Accounting result appends an assessment adjustment or successor version without rewriting the change or COR.
- TALA invents no adjustment fee, drop fee, penalty, refund, or forfeiture.

### 7.3 Published timetable changes

Clinic 3 cannot finalize cancellation of a published Class Offering while unresolved Clinic 4 placements depend on it. Unofficial reservations are released or validly replaced. Officially enrolled Students enter a Registrar impact queue. Registrar records a valid replacement, authorized course cancellation, or another approved outcome; TALA never silently moves a Student. Retaining placement requires attributable learner acknowledgement of the exact changed meeting facts. A verified registration change or terminal case cancellation uses its exact current proposal, certificate or case event as system evidence; no routine evidence reference is retyped. Placement, roster, schedule, COR, and Accounting effects remain synchronized. Clinic 3's published revision triggers one shared notice; Clinic 4 supplies the affected enrolled-Student recipients and their updated schedule/COR context.

Student Home presents useful current dates, next actions, balance and announcements from their owning sources. It omits empty hold panels and engine widgets. Registrar-advised subjects explain why each course is included or excluded; no free course shopping or permanent Regular label is introduced.

## 8. Exact UI authority

Capability coverage, hierarchy, states, responsive/accessibility outcomes and shared Student Home/Profile contracts live in the Clinic 4 and shared-shell [UI Surface Blueprint](../ui_surface_blueprint.md). This PRD owns product content, actions and authorization. DESIGN.md and the matching surface brief govern Impeccable-led composition and framework research.

Missing or changed admissions, academic and account records must identify the outstanding work and its owning office, including older cases without a change-review event. Registrar follows the existing source correction or approved-evaluation task; the enrollment page creates no override or duplicate clearance. Recording a transferee evaluation precedes subject selection, and replacing it requires reviewing that selection again. Simplified screens preserve current-source revalidation and retained history without requiring staff to interpret internal checkpoint fields.

### 8.1 Learner Enrollment page

The learner follows one status-led enrollment journey. Keep the current stage and permitted next action readily identifiable, with these facts available in context:

1. Term, applicable deadline, stage, owner, next action, and one primary button.
2. Current enrollment status, exact blocked action and next step; successful checks are compact supporting detail rather than a five-gate widget.
3. Proposed or official subjects and schedule.
4. **Why these subjects** and link to full curriculum evaluation.
5. Academic blockers, unavailable requirements, shortage status, and bounded completion outlook.
6. Placement and reservation evidence.
7. Enrollment-payment requirement and Finance link.
8. COR and registration/change history.

Primary actions are limited to **Start enrollment/registration**, **Confirm proposed subjects and schedule**, **Continue to Finance**, and **View COR**. Only the action appropriate to the current stage appears.

The Student COR page prioritizes the selected issued copy, its actual current/historical status, learner and Program/Term, subject count, units, issue date and recorded subjects/meetings. Earlier owned copies and other Terms remain available after the subjects; printing always uses the selected version. Frozen fees and issue details remain secondary. Missing output explains the actual source problem and returns to Enrollment.

Learner waiting and closed views lead with the actual outcome, responsible office and safe next step. A confirmed, cleared case awaiting Registrar finalization makes no new payment demand or official-enrollment claim. Expired reservations are distinguished from released or missing placement. Retained payment, coverage and subject records stay reachable through named disclosures without suggesting an automatic refund, restored seat or reopening approval.

### 8.2 Registrar Students & Enrollment workbench

One selected-term header shows term/windows, readiness and deadlines, official-enrollment count, shortage count, and one state-appropriate primary action.

The workbench distinguishes **Ready to prepare**, **Waiting for learner**, **Placement and shortages**, **Finance pending**, **Ready to finalize**, **Adjustments and Drops**, and **Official and history** work. The UI Blueprint governs their discoverable composition.

Search supports legal name, verified email, application reference, and student number. Term, program and current-work filters support finding the right case. Add further filters only when they serve a concrete queue task; the workbench does not require a filter for every recorded fact.

Each record leads with the learner, program and Term, followed by the current outcome or task, its responsible person or office, and the next available action. Show a deadline, blocker or recovery instruction when it affects that task. Proposed or official subjects, their schedule and the relevant enrollment-payment requirement support the current decision. Missing assessment or payment authority stays explicitly unavailable.

Closed cases lead with the actual outcome, reason, recorded actor/time and a valid recovery path. Official cases lead with current subjects and the issued COR; an unapplied change remains a separate task. Prior proposals, source references, authority, adjustments, drops, COR versions and attributable audit/email history remain reachable through named secondary views or disclosures. These records do not require a repeated stack of completed or irrelevant checkpoints.

Actions are **Prepare/revise proposal**, **Issue for confirmation**, **Record assisted confirmation**, **Place/change class**, **Finalize official enrollment**, **Record cancellation**, **Record adjustment**, **Record Course Drop**, and **Print current/historical COR**, presented in their authorized context. A blocked task names its missing requirement, source owner and valid recovery. Show a disabled action when understanding its availability helps the current task; omit unrelated, completed and premature actions. Server-side guards recheck readiness when invoked.

Keep work queues searchable and filterable, current evidence readable, actual inputs distinct and the permitted next action clear. DESIGN.md and the matching surface brief govern component selection and supported customization. Readiness stays with the affected registration action and its owning source.

### 8.3 Accounting and role projections

Accounting receives a focused **Student Accounts** view of the same underlying enrollment and account records showing learner, Registration Case and proposal/change reference, term, assessment basis, authority, assessed total, amount required now, verified payment applied, Approved Coverage applied, remaining amount, satisfaction basis, clearance/review state, deadline, and next action. `Assessment required` remains a state in this queue rather than a new navigation destination. Accounting cannot select courses, place classes, create Student identity, or finalize enrollment.

Faculty sees only official rosters; reservations are not enrolled Students. Academic Head receives read-only oversight and authority evidence. System Administrator sees only locally evidenced System Health for integrations, queues, and email. Applicant and Public see no internal capacity analytics or other learners' information.

For one selected official `ClassOffering`, assigned Faculty and an authorized Registrar read the same current Class Roster projection. Clinic 4 owns membership: only current official course registrations appear, and an applied adjustment, class change, Course Drop, or other authorized removal updates the projection atomically with the Student schedule, account-review source, and successor COR. Clinic 5 owns the contextual print and Class Roster CSV actions. The selected-class export uses a readable preview and fixed useful columns, preserving current membership, role access, privacy and output history. Clinic 5 may not copy roster membership into a separate reporting record. The projection exposes no reservation, Applicant evidence, grade, finance, address, phone, or email data.

The Student **Profile** surface leads with the student's identity and current studies, followed by approved contact facts and correction guidance. Official school records remain read-only. Students may replace their own private profile photo with a validated JPEG, PNG or WebP of at most 2 MB; the photo is separate from official identity and does not alter school records. Registrar reaches the same official profile from **Students & Enrollment** and receives a focused **Record authorized correction** action only when current authority and evidence are present. Empty, stale, inaccessible, correction-conflict, and failed-output states reveal no protected identity detail and preserve entered correction evidence when safe.

### 8.4 COR

Each immutable, versioned Registration Form/COR contains institution/document identity; student number and complete legal name; program, curriculum, term, and selection basis; curriculum levels represented; official courses, units/contact hours, class references, schedules, modes, rooms, Faculty, and total units; the assessment-at-enrollment-finalization snapshot with its basis/reference; and actual recorded institutional authorities where required. A later adjustment/drop COR may state that Accounting review is pending, but it does not recalculate or replace that original financial snapshot.

The frozen assessment basis, source/reference, authority and amounts remain one coherent snapshot. An adjustment/drop successor may identify its changed-assessment or Accounting-review source separately; it never presents a successor assessment reference as the source of unchanged original financial amounts.

It excludes LRN, live ledger activity, future installments, payment attempts, receipt history, continuously changing balances, and fictitious signatures. Later financial activity appears in Student Account/SOA. Rendering is restrained, high-contrast, grayscale-safe, and supports authenticated browser print/save-as-PDF.

### 8.5 Email matrix, mobile, and failures

| Trigger | Recipient | Safe contents | Source / idempotency key | Failure behavior | Excluded notifications |
|---|---|---|---|---|---|
| Continuing-Student enrollment window opens | Eligible continuing Student | Term, deadline, secure start link; no academic detail | Term window plus Student identity | Window remains authoritative; authorized resend available | No recurring reminder or Ready Applicant duplicate |
| Proposal issued or materially revised | Learner | Proposal-ready fact, deadline, secure confirmation link | Proposal version plus learner identity | Proposal remains available in workspace | No mail for staff draft saves or non-material edits |
| Payment or coverage action required | Learner | Amount/action due now, deadline, secure Finance link; no private ledger detail | Current enrollment-payment requirement version | Clearance remains blocked/visible; Finance is authoritative | No mail for every ledger event |
| Official enrollment and COR ready | Learner | Official enrollment fact, secure COR link; on first enrollment, Student access is active | Official enrollment plus COR version | Enrollment/activation never rolls back; authorized resend available | No duplicate Student-activation email |
| Reservation released or case expires | Learner | Safe consequence, responsible office, secure case link | Reservation release or terminal case record | Authoritative state remains; support/recovery shown | No ranked-waitlist or recurring reminder mail |
| Official adjustment or Course Drop | Learner | Official change fact, secure current COR/schedule link | Adjustment/drop record plus COR version | Change remains effective; authorized resend available | No invented refund/fee statement |
| Clinic 3 timetable revision | Clinic 3-derived Faculty and Clinic 4-supplied affected enrolled Students | Own affected schedule/COR context | Clinic 3 published revision plus recipient identity | Revision remains authoritative; shared authorized resend | No second Clinic 4 timetable-revision email |

Routine saves, validation/capacity checks, staff navigation, and recurring reminders send no email. Delivery failure never rolls back academic or financial state.

Mobile uses labelled stacked course and queue rows, preserves reading order, keeps the primary action reachable, and keeps secondary actions discoverable. Loading, empty, stale, expired, 403, 404, 419, 429, validation, concurrency, and integration-failure states identify the responsible owner and safe recovery action. Keyboard access, visible focus, screen-reader status text, and non-color status meaning are mandatory.

## 9. Authoritative Records and Conceptual Contracts

No public HTTP API is introduced. These are conceptual responsibilities, not physical table or class instructions:

| Name or family | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| RegistrationCase | Own one learner-and-Term journey, current proposal, readiness, finalization, cancellation, and change history | Registrar with bounded learner actions | Persisted authoritative record | Learner, Registrar, PRDs 01/03/05/06 | Remains the stable cross-clinic container |
| EnrollmentSelectionBasis | Identify Standard Curriculum or Individually Advised selection | PRD 04 | Documentation concept that does not require a separate implementation object | Proposal validation and UI | Implement as a controlled value, not a learner type or policy table |
| ProposedRegistrationVersion and ProposedCourseRegistration | Freeze one proposed selection and its course/Class Offering rows | Registrar prepares; learner confirms | Immutable version with owned rows | Placement, finance, finalization, COR | Rows belong to the proposal version and need no separate module |
| LearnerRegistrationConfirmation | Record informed learner or assisted confirmation of one proposal version | Learner or Registrar under assisted authority | Immutable version or event | Finalization readiness and audit | Remains distinct to protect consent/version history |
| EnrollmentSeatReservation | Protect provisional capacity until expiry/finalization | PRD 04 | Persisted authoritative record with atomic release | Placement and Class Offering capacity | Remains distinct because concurrency and one-time release require authoritative state |
| OfficialTermEnrollment and OfficialCourseRegistration | Record atomic official enrollment and exact registered classes | Registrar | Persisted authoritative record with owned rows | PRDs 01/03/05/06 and COR | Course rows are owned by the enrollment; no duplicate roster/enrollment record |
| Student identity activation and correction history | Create the minimal Student identity once and preserve authorized corrections | Registrar through finalization/correction | Immutable version or event on the Student identity | PRD 01 access and all Student projections | Creation and correction are events on the same Student identity |
| EnrollmentAdjustment and CourseDropOutcome | Record authorized post-enrollment changes and effects | Registrar | Immutable version or event | Timetable, roster, finance review, COR | Remain distinct event types because their guards and consequences differ |
| CertificateOfRegistrationVersion | Reproduce the official enrollment snapshot | Registrar/PRD 04 | Official output plus immutable version | Learner and authorized Staff | Not a live finance or schedule record |
| EnrollmentCheckpointProjection, CourseEligibilityProjection, EnrollmentShortageItem | Explain current readiness, course eligibility, and unresolved capacity | PRD 04 derives from producer-owned sources | Derived projection/calculation or UI-only state | Learner and Registrar | No generic checkpoint engine or stored shadow result |
| OfficialCourseResultProjection, AcademicEnrollmentEffect, EnrollmentPaymentRequirementProjection, UnmetClassDemandProjection | Consume or publish bounded cross-PRD facts | Owning producer PRD | Derived projection/calculation | Registration and source owners | PRD 04 never edits or copies the producer-owned fact |
## 10. Lifecycle, Mutation, and Technical Boundaries

A Registration Case, placement/reservation history, Official Enrollment, Student-activation result, Enrollment Adjustment, Course Drop, and COR version are never deleted. Before learner confirmation, the learner may cancel the current case; after confirmation, only Registrar records a cancellation. Material proposal change invalidates prior confirmation. Reservation expiry releases capacity once and preserves the case and finance evidence.

This PRD defines conceptual product behavior, not physical tables, routes, services, migrations, or task order. A future journey-complete slice must reconcile existing enrollment, placement, COR, identity, grades, finance, policies, tests, and all consumers against the producer-owned handoffs in this document.
## 11. Acceptance coverage

Prove one complete first-enrollment journey from Public Apply, verified account and submitted application through a scoped correction with retained uploads, Registrar review/admission/readiness, exact-Term published classes, proposal/reservations/assessment, learner confirmation, authoritative payment/coverage clearance and official enrollment. Check the same person, Term and current source versions across Applicant, Registrar and Accounting, then the resulting Student schedule/COR and Faculty roster. Missing/stale input, failed payment, concurrent action or retry must produce the named safe recovery without lost evidence, duplicates or partial enrollment. This connected check belongs to enrollment completion, alongside the retained continuing-Student and failure scenarios below.

The later vertical slice must verify:

- New Applicant and continuing Student entry; existing Students blocked from Admissions.
- Standard and Individually Advised proposals, including assisted and online confirmation.
- Failed prerequisite with unrelated eligible courses; pending/incomplete results and later correction.
- Pending prerequisite excluded while unrelated courses proceed; later satisfying release added only through ordinary Adjustment, and no released `P` or automatic enrollment mutation.
- All four academic enrollment effects.
- Transfer credit, shift effective term, returning authority, old curriculum, reduced enrollment, overload authority, and rejection of a concurrent-prerequisite bypass.
- Separate cohort classes, approved sharing, one/multiple valid blocks, protected capacity, shortage resolution, expiry, and concurrency races.
- Safe capacity amendment versus timetable-revision-required changes.
- Fixed Fee Plan and authorized individual assessment for ordinary, reduced, Special Term, and Individually Advised cases; unavailable source with no fallback; full payment, installment, externally approved scholarship/sponsorship/subsidy coverage, mixed satisfaction, failed checkout, and pending payment.
- Generic every-term finalization and conditional first Student activation.
- Cancellation boundaries, final-cutoff `NotEnrolled`, same-case terminal reopen before cutoff and with exact late authority after cutoff, cost-increasing/no-additional-cost adjustment, open Adjustment, closed Adjustment with and without exact late authority, removal/Course Drop with Accounting review pending, later authorized assessment adjustment, full-withdrawal boundary, and class-cancellation impact.
- COR versioning and assessment snapshot.
- Current per-Class-Offering roster view, print/save-as-PDF and contextual fixed CSV; retained print after official enrollment, adjustment, class change, and Course Drop; unauthorized, empty, stale, and generation-failure paths disclose no learner data.
- The affected Faculty membership view names the selected course, class and exact Term, shows current official learners with readable legal names, and retains the existing grade-entry permissions and dates. Its list and outputs use the same current-membership comparison. A mismatch names the Registrar recovery and prevents treating stale rows as current or saving them as a grade draft. Historical memberships remain retained and do not inflate current learner counts. Academic Head may inspect permitted membership without gaining roster CSV/print authority.
- Academic Head class finding leads with the full course title, class, exact Term and designated Faculty, with native search and explicit Term/state filters. Its roster-member count excludes retained historical rows. Opening a class checks membership against current official registrations before showing legal names and per-learner programs; a mismatch shows Registrar recovery instead of stale learners. Assignment approval, roster version and membership reference remain reachable as source details. This membership view exposes no draft results or grade mutation actions and preserves existing released-result inspection and Registrar ownership.
- Academic Oversight may show the selected Term's registration-case distribution by canonical outcome. Label the total as cases, distinguish official, in-progress and terminal outcomes, and identify unclassified data explicitly. This is a read-only aggregate without learner identities, payment amounts or finalization controls. A contextual enrollment drill-in uses an explicit Term filter; an unselected Term is unavailable rather than an inferred zero or another Term's count.
- The Academic Head enrollment list uses native search and explicit Term, Program and outcome filters, readable learner identity, canonical outcome and descriptive progress. Opening a case preserves the selected list criteria and Oversight return context. The read-only detail leads with current active official registrations and their current published class schedule when enrollment is official; an unapplied proposal never replaces those subjects or figures. Unfinished enrollments explicitly show proposed subjects or the absence of a proposal, the responsible party and recorded source/deadline. Identity, academic selection basis, issued confirmation, current clearance, institutional authority and change history remain inspectable through secondary source details. The Head receives no enrollment mutation, email-recovery or COR-output controls through these views; existing source and output authorization remains unchanged.
- Cross-role authorization, idempotent email success/failure, desktop/mobile, keyboard/screen-reader use, stale-record protection, and safe error recovery.

### 11.1 Synthetic Demonstration Data

Registration scenarios reuse the coordinated 47 Students, six cohorts, BM/IT/THM curricula, published Class Offerings, and the exact Clinic 6 accounts. PRD 04 owns Registration Cases and their outcomes; it does not copy admissions, timetable, grade, or finance sources.

| Reference | Synthetic case | Demonstrated evidence |
|---|---|---|
| `REG-2026-0001` | First enrollment for `alma.adult@example.test`, Standard Curriculum | Clinic 2 readiness, first Student activation, official enrollment, COR v1, single combined email |
| `REG-2026-0002` | Continuing Student, Standard Curriculum | Generic every-term registration without Applicant flow or new Student identity |
| `REG-2026-0003` | Failed prerequisite with unrelated eligible courses | Bounded exclusion, remaining valid proposal, Clinic 5 released-result source |
| `REG-2026-0004` | Individually Advised old-curriculum/transferee case with selection-specific charges | Approved credits/equivalencies, proposal evidence, assisted confirmation, `AuthorizedIndividualAssessment` |
| `REG-2026-0005` | Authorized reduced enrollment | Clinic 5 effect/authority and exact Accounting assessment; no automatic fee reduction, penalty, or learner classification |
| `REG-2026-0006` | Two-course shortage with one reservation expiry | Protected capacity, Clinic 3 unmet demand, release, valid replacement |
| `REG-2026-0007` | Installment arrangement with required amount outstanding then cleared | Clinic 6 payment requirement, Accounting action, finalization blocker/recovery |
| `REG-2026-0008` | Externally approved scholarship coverage | Clinic 6 Applied coverage evidence without scholarship processing, course authority, or Registrar finance mutation |
| `REG-2026-0009` | Official adjustment followed by Course Drop | Immutable COR v1/v2/v3, synchronized roster/schedule/finance projections |
| `REG-2026-0010` | Published timetable change affecting an enrolled Student | No silent move, Registrar impact resolution, one Clinic 3 revision event |
| `REG-2026-0011` | Individually Advised continuing Student with one unreleased prerequisite | Dependent course excluded while unrelated courses proceed; later satisfying release uses the ordinary guarded Adjustment path and creates no `P` or automatic add |
| `REG-2026-0012` | Authorized grade correction makes a prerequisite unsatisfied after Official Enrollment | One idempotent academic-result review; open-Adjustment guarded outcome plus closed-Adjustment branches with and without exact late authority; no automatic registration/COR mutation |
| `REG-2026-ST-001` | Continuing Student in `TERM-2026-ST` with planned `CLS-ITE3-ST-A`, Additional retake `CLS-IT201-ST-R`, and dependent `IT301` excluded | Individually Advised proposal, exact authorized individual assessment, PHP 2,000 Applied subsidy plus PHP 1,000 verified payment, `Mixed` clearance, official enrollment, and immutable COR snapshot |

### 11.2 Enrollment Browser Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Ready Applicant `REG-2026-0001` | Applicant Home → Enrollment | Start enrollment | Term/deadline, current readiness facts, owner/next action, no Student identity yet | Registrar sees an automatically prepared standard proposal/reservation or named advising/shortage action | One Active Registration Case | Closed window blocks start unless valid late authority exists | Retry does not duplicate case |
| Continuing Student `REG-2026-0002` | Student Enrollment | Start registration | Existing Student identity and current-term status | Admissions remains unavailable | Current-term case | Attempt to reapply through Admissions is blocked | Same engine handles first and continuing enrollment |
| Registrar; terminal same-Term case | Students & Enrollment | Reopen before final cutoff, then test after-cutoff branches with and without exact late authority | Same reference, terminal and reopen events, source revalidation, and no restored proposal/seat/clearance | Learner sees the reactivated journey only after authorized commit | Reopened `Active` case, not a second case | Missing authority keeps the terminal case read-only and changes nothing | Reopen requires current work to be prepared again and never duplicates or silently restores prior facts |
| Registrar; `REG-2026-0001` and `0004` | Students & Enrollment | Prepare Standard and Individually Advised proposals, reserve valid classes and resolve the assessment before issuing for confirmation | Curriculum/result sources, versions, **Why these subjects**, exact classes, reservation and assessment | Learner receives only the current complete issued version | Proposal-ready email | Stale/ineligible course, failed placement or unavailable assessment names the owning recovery | No standalone Study Plan or arbitrary learner shopping |
| Learner/Registrar | Enrollment / assisted confirmation | Review reserved classes and assessment, then confirm the exact proposal online or record assisted confirmation | Version, schedule, temporary reservation, assessed charges and confirmation evidence | Learner sees the current payment/coverage requirement and next action | Learner confirmation | Material revision invalidates old confirmation | Only the current complete version is confirmed; confirmation creates no official enrollment |
| Registrar; `REG-2026-0003/0006` | Placement and shortages | Place valid courses, expose failed prerequisite, expire reservation, resolve shortage | Requisite reason, capacity evidence, owner/deadline, replacements | Clinic 3 receives unmet demand; Faculty sees no unofficial roster | Valid placement or explicit shortage | Concurrency loss refreshes rather than oversubscribes | Protected capacity and unrelated eligible courses are preserved |
| Accounting; `REG-2026-0004/0005/0007/0008` and `REG-2026-ST-001` | Student Accounts | Resolve fixed or authorized-individual assessment, then verify payment and/or record Applied coverage | Exact proposal, basis/authority, assessed amount, separate payment/coverage amounts, satisfaction basis, remaining amount, deadline, next action | Registrar checkpoint updates; Accounting cannot finalize | Assessment and clearance evidence | Missing/stale/excess coverage or assessment authority is `Unavailable`; no zero, silent cap, or unit-rate fallback | Finance policy remains Clinic 6-owned |
| Continuing Student and Registrar; `REG-2026-ST-001` | Enrollment → Students & Enrollment | Review the bounded proposal, confirm it, and finalize after `Mixed` clearance | Special Term source, two eligible classes, dependent `IT301` exclusion, exact assessment/coverage/payment sources, current readiness checks | Clinic 3 receives placements; Clinic 5 receives official roster membership; Clinic 6 retains the same Term Account | Official Special Term enrollment and COR | Missing class authority, stale proposal, unavailable assessment, invalid coverage, or remaining required amount blocks only the next consuming action | No Summer engine, tutorial feature, irregular status, or scholarship workflow appears |
| Registrar; all checks current | Ready to finalize | Finalize official enrollment | Atomic current readiness pass, identity/term/version evidence | Faculty roster, Student schedule/access, Accounting reference, source-linked admissions continuity | Official enrollment and COR v1 | Stale source or mail failure cannot create partial/duplicate result | One transaction creates synchronized official projections |
| Student/Registrar; `REG-2026-0009` | Current COR / Adjustments and Drops | Record a cleared cost-increasing adjustment, a confirmed no-additional-cost change, then Course Drop; inspect versions | Current and historical COR, original assessment snapshot, changed roster/schedule, Accounting review state | Faculty/Accounting/Clinic 5 receive bounded owning projections | COR v2/v3 and printable history | Missing increase clearance blocks add/replacement; removal/drop proceeds with review pending and no invented refund | Prior COR and assessment versions remain immutable |
| Registrar; `REG-2026-0010` | Timetable impacts | Resolve proposed Clinic 3 revision | Affected placement, valid replacement/cancellation/outcome, no silent move | Clinic 3 can publish; affected user gets one shared event | Updated schedule/COR context | Unresolved impact blocks revision publication | Clinic 3 owns the single revision trigger/email |
| Registrar/Student; `REG-2026-0011` | Ready to prepare → Enrollment | Issue/confirm a proposal that excludes the dependent course, finalize unaffected courses, then release a satisfying prerequisite result and use ordinary Adjustment | Released-result source, excluded/newly eligible course, current window, capacity/schedule/load/Finance checks | Clinic 5 result makes the course eligible but does not add it; only a committed Adjustment updates projections | Confirmed proposal, Official Enrollment, and successor COR after guarded add | Unreleased/adverse result, closed window without late authority, stale Finance, or capacity conflict leaves the course excluded | No `P`, provisional permission, learner type, or automatic add appears |
| Registrar/Student; `REG-2026-0012` | Adjustments and Drops / Academics → Enrollment | Process a grade correction that makes a prerequisite unsatisfied after Official Enrollment, then exercise open and closed Adjustment branches | One result-impact review, current COR, owner, next action, capacity/schedule/Finance state | Applied authorized change synchronizes Faculty/Accounting/Student; no-authority branch changes nothing | Successor COR only after an applied guarded change | Duplicate result, mail failure, capacity conflict, stale Finance, or closed window without exact late authority creates no duplicate/partial mutation | Current enrollment remains official until one authorized transaction commits |

### 11.3 Authority-hardening control matrix

| Action or record | Actor and authorization | Validation and readiness | Confirmation and audit | Lifecycle, deadline, and failure behavior |
|---|---|---|---|---|
| Start Registration Case | Ready Applicant or eligible continuing Student; Registrar may assist | Exactly one Case per learner/Term; current identity, Term window, lifecycle, curriculum/result/readiness sources | Start needs no critical dialog; records entry source and selection basis | Duplicate returns existing Case. No Case is deleted; unavailable source blocks only the consuming step |
| Prepare/revise proposal | Registrar; standard proposal may be derived only from current curriculum authority | Exactly one current proposal version; unique course/Class Offering pair; same course not twice; positive units; requisites/equivalencies, schedule, capacity, curriculum, lifecycle, and deadline current | Routine Draft save needs no dialog. Material successor identifies exact added/removed/replaced rows and invalidates prior confirmation | Stale/concurrent edit posts nothing and preserves safe notes. No fixed revision count while the Case/window permits |
| Learner/assisted confirmation | Learner confirms own complete proposal; Registrar records externally assisted confirmation with method/evidence | Current proposal/version, exact meetings/units, eligibility, valid temporary reservation and current assessment source | **Confirm proposed enrollment** or **Record assisted confirmation** shows courses, units, meetings, current reserved placement, assessment and next payment/finalization steps, and no guarantee of final enrollment | No fixed attempt count. Material change invalidates confirmation; nonmaterial display correction does not. Learner may cancel before confirmation; after it only Registrar records cancellation |
| Placement/reservation | Registrar/TALA through atomic current-capacity validation | Current proposed classes and published Class Offerings before learner confirmation, no conflict, applicable capacity/protection/deadline | Reservation and expiry consequences appear with the assessed proposal before confirmation/finalization; shortage action names alternatives and authority | Expiration releases capacity exactly once, preserves Case/finance evidence, records reason, and requires replanning. It grants no future entitlement |
| Registrar cancellation | Registrar after learner confirmation | Current Case, authority/reason, placement/payment/output impacts | **Cancel registration case** shows released seats, readiness/account consequences, learner projection, and preserved history | No hard deletion. Stale/concurrent finalization conflict changes nothing; terminal case remains read-only unless separately reopened under the next rule |
| Reopen terminal Registration Case | Registrar only | Same learner/Term case; terminal state; reason/authority; current eligibility/window/Term; before final cutoff or exact late-enrollment authority | **Reopen registration case** previews preserved history and recalculated readiness; no proposal, confirmation, seat, Assessment validity, clearance or eligibility fact is restored automatically. Money history remains and Accounting reconciles under PRD 06 | Appends one reopen event and returns the same case to `Active`. Stale/missing authority changes nothing; learner may request help but cannot self-reopen; no duplicate case or automatic next-term carryover |
| Official finalization and first Student activation | Registrar | All current readiness checks pass: academic proposal, placement, learner confirmation, Clinic 6 clearance, institutional/identity readiness; current versions locked | **Finalize enrollment** identifies the learner, Term and reviewed subjects/sections/units and states the official-registration, COR and first-enrollment access effects. It repeats no routine note or approval-reference entry; actor, time and exact source versions are recorded automatically, and current readiness is rechecked at submission | Atomic/idempotent. Retry cannot duplicate Student identity/number/access, enrollment, placement, Term Account, roster membership, email, or COR. Failure rolls back the whole institutional mutation |
| Adjustment/add/replacement | Registrar under authorized window/authority; learner acknowledgement where required | Current Enrollment/COR; valid new proposal/change version; academic/capacity/timetable checks; cost increase cleared by successor Assessment; no-cost result authoritatively confirmed | **Apply enrollment adjustment** shows exact before/after rows, units, meetings, financial state, roster/schedule/COR effects | No edit-in-place or deletion. Successor Enrollment/COR history; stale finance or class facts block only application of the change |
| Unreleased prerequisite and later-result handling | Registrar consumes Clinic 5's released result and uses only the ordinary proposal/Adjustment path | Individually Advised case; dependent course excluded until a satisfying released result or exact approved credit exists; all ordinary checks current | Proposal explains the exclusion. A later add uses **Apply enrollment adjustment** with the exact before/after courses and ordinary consequences | Result release changes eligibility only. It never creates a provisional grade, permission record, automatic add, duplicate review, or silent enrollment change |
| Removal/Course Drop | Registrar records authorized action | Current registration, deadline/authority, course, academic consequence, current finance review state | **Record Course Drop** shows removed course, roster/schedule/COR successor, and that Accounting review may remain pending without inferred refund/penalty | Academic effect may post without refund decision when authorized; original enrollment/COR remains immutable. No automatic balance change or global hold |

Unit totals, requisites, equivalencies, conflicts, capacity, placement, assessment, and deadline facts are revalidated against their producer versions for every material action. Registration Cases, proposal history after confirmation, official enrollment, placement/reservation history, adjustments, Course Drops, and COR versions are never deleted or generically archived. Inaccessible responses reveal no learner, schedule, academic, or financial facts.
## 12. Technical and Operational Assumptions

- Clinic 4 consumes only current versioned projections from Clinics 2, 3, 5, and 6 and cannot edit their source facts.
- `Allowed`, `AdvisingRequired`, `Blocked`, and `PendingDecision` are factual enrollment effects, not Student classifications.
- Missing operational dates, authority, assessment or placement evidence blocks only the affected action and names its source owner and recovery path.
## 13. Evidence basis

- [CHED Manual of Regulations for Private Higher Education](https://legacy.ched.gov.ph/manual-regulations-private-higher-education-morphe/) governs applicable private-college enrollment and academic-record duties; institution-specific deadlines, overloads, grading, refunds, and late charges still require approved institutional authority.
- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) requires examination access for qualified disadvantaged students to remain distinct from reenrollment, record-release, and account actions; this supports service-specific rather than global financial restrictions.
- [PeopleSoft enrollment requirement groups](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/defining-enrollment-requirement-groups.html) is a mature-system benchmark showing that an institution may include or exclude in-progress coursework from prerequisite evaluation. TALA deliberately uses the conservative completed-and-released rule; the configurable PeopleSoft rule engine is not imported.
- The dated [PUP ACE procedure](https://www.pup.edu.ph/downloads/files/PUP_ACE_Procedures_Campus.pdf) is comparison evidence for adding missing subjects through a controlled adjustment process. Its offices, dates, forms, and system implementation are not copied as Servitech policy.
- [PeopleSoft grade-roster and grade-change behavior](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/campus-self-service/entering-grades-through-self-service.html) is a mature-system benchmark for post-release controlled change, not authority for a TALA grade or enterprise approval layer.
- [UST College of Science enrollment guidance](https://www.ust.edu.ph/wp-content/uploads/2023/01/2nd-Term-AY-2022-2023-Enrollment-Guidelines-1.pdf) is a dated, college-specific comparison showing that late course changes may require an exact endorsement and Registrar action. Its fees, offices, dates, study-plan terminology, and approval chain are not generalized.
- [PLMun's published enrollment schedule](https://www.plmun.edu.ph/event.php?id=212) is local comparison evidence that continuing and irregular learners may receive bounded enrollment periods without becoming new Applicants. It does not establish Servitech's dates or terminology.

Business evidence, curriculum sheets, PUP/PUPSIS observations, and existing code remain supporting or implementation evidence. They become enforceable only when supported by applicable authority or an approved institutional decision.

## 14. Assumptions

- TALA targets a normally recognized and authorized Philippine college.
- No approved institutional enrollment, overload, grading, refund, late-fee, or readmission handbook has been supplied.
- The supplied fee evidence does not establish one approved Servitech variable-fee formula. Clinic 4 consumes only Clinic 6's exact authorized assessment result.
- External institutional decisions are recorded rather than recreated.
- Existing code and data remain intact until post-authority implementation reconciliation.
