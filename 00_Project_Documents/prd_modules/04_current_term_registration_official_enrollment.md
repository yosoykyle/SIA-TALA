# PRD 04 — Current-Term Registration, Official Enrollment, Student Activation, Adjustment, and Course Drop

## Authority and Review Status

**Clinic 4 authority:** Approved on 2026-08-06; complete-authority review passed

This is the canonical approved unified Clinic 4 journey authority. It is product and UI authority, not an implementation task plan.

## 1. Purpose and boundary

Clinic 4 owns the complete journey:

`Eligible learner → learner starts term registration → proposed subjects and classes → learner confirmation → seat reservation → assessment and Accounting clearance → Registrar finalization → official enrollment and COR → controlled adjustment or Course Drop`

It consumes Clinic 2's `ReadyApplicantProjection`, Clinic 3's active curriculum and published `ClassOffering` facts, Clinic 5's released academic-result and lifecycle facts, and Clinic 6's enrollment-payment requirement. It preserves any Clinic 2 post-enrollment credential follow-up reference without deciding or reclassifying it. It does not recreate admissions, curriculum approval, timetable generation, grading, credit-evaluation approval, readmission approval, shifting approval, refund decisions, or payment operations.

The standalone **Study Plan module is removed**. Curriculum evaluation remains the academic authority. Clinic 4 stores only versioned proposed course registrations within the current `RegistrationCase`.

One enrollment engine supports ordinary and exceptional degree students. TALA records externally approved institutional decisions when another office owns them; it does not recreate those offices' approval workflows.

## 2. Terminology, ownership, and supported circumstances

### 2.1 Selection basis, not learner type

TALA does not store `Regular` or `Irregular` as policy-driving Student statuses. Every case uses one `EnrollmentSelectionBasis`:

- `StandardCurriculum` — TALA derives the expected curriculum courses and normal cohort classes.
- `IndividuallyAdvised` — Registrar prepares a bounded proposal from the assigned curriculum, released results, approved credits/equivalencies, and actual published classes.

Selection basis is independent from academic progress, academic enrollment effect, Applicant/Student identity, and funding or payment arrangement. Transferee, returning, shifted, graduating, failed/back-course, and old-curriculum circumstances are facts affecting evaluation; they are not separate enrollment engines.

Student-facing language is **Proposed subjects and schedule**. Learners do not freely assemble arbitrary subjects or compete in an open class marketplace. `Term Offering` is removed from product language; Clinic 3's actual scheduled term class is `ClassOffering`.

### 2.2 Supported circumstances

The same journey supports:

- New first-year and transferee Applicants.
- Standard continuing Students.
- Failed or back-course Students and cases with pending or incomplete results.
- Probation-eligible, academically blocked, or pending-decision cases.
- Returning/reactivated and shifted Students after Clinic 5 records the authorized outcome.
- Old-curriculum, bridging, approved-equivalency, and transfer-credit cases.
- Graduating and approved Special Term cases.
- Full-payment, installment, scholarship, sponsorship, and authorized accommodation cases.

Incoming/outgoing cross-enrollees, foreign-student processing, second-degree, non-degree/special, refresher students, course shopping, ranked waitlists, petitions, advising appointments, internal chat, and academic approval chains are outside MVP. TALA may consume an externally approved credit or equivalency outcome affecting a Servitech Student.

## 3. Entry, windows, outcomes, and checkpoints

### 3.1 Entry and deadlines

- A Ready Applicant clicks **Start enrollment**. Readiness alone creates no registration, Student profile, Student number, assessment, or seat.
- A continuing Student clicks **Start registration**. Existing Students cannot reapply through Admissions.
- Returning and shifted Students enter only after Clinic 5 records the applicable authorized lifecycle result.
- Clinic 3's neutral `Enrollment` operational window owns the approved opening and closing dates. Clinic 4 selects only one bounded applicability: Ready Applicants, Standard continuing Students, Individually Advised or exception cases, or all otherwise eligible learners. This is fixed vocabulary, not a programmable audience engine.
- Each deadline uses an inclusive Asia/Manila date and an exact cutoff only when an approved source supplies one.
- After the applicable ordinary window, self-start is blocked. Late enrollment requires recorded institutional authority; TALA invents no late fee or automatic exception.
- After the final authorized cutoff, an unfinished case becomes `NotEnrolled`. No subjects, seats, assessment, or eligibility determination automatically carries into a later term.

### 3.2 Stored outcomes and derived stage

`RegistrationCase` stores only these outcomes:

- `Active`
- `OfficiallyEnrolled`
- `CancelledByLearner`
- `CancelledByRegistrar`
- `NotEnrolled`

The current stage is derived from five accountable checkpoints:

1. Student eligibility.
2. Confirmed proposed subjects.
3. Valid class placement.
4. Accounting clearance or authorized coverage/accommodation.
5. Registrar finalization.

`Action Needed`, responsible owner, reason, evidence, and next action are projections. TALA has no configurable enrollment state machine, universal override record, or generic gate engine.

### 3.3 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| No case / entry available | Start enrollment or registration | Ready Applicant or continuing Student | Own eligible identity; Registrar-assisted start only with recorded authority | Applicable Clinic 3 window open or valid late authority; Clinic 2/5 eligibility source current | One `Active` Registration Case for learner/term | Retry is idempotent; a closed case does not carry into another term | Registrar queue sees the same case; no Student identity is created by start |
| Proposal in preparation/revision | Prepare Standard Curriculum or Individually Advised proposal | TALA derives then Registrar confirms/revises | Registrar proposal authority; external authorities recorded when required | Assigned curriculum, released Clinic 5 results, actual Clinic 3 classes, no unresolved academic block | New immutable proposed-registration version | Later version supersedes the active proposal without erasing prior versions | Learner sees only an issued version and **Why these subjects**; Accounting sees no course authority |
| Waiting for learner / confirmed | Issue proposal; confirm online or record assisted confirmation | Registrar then learner/Registrar | Own confirmation or recorded assisted-confirmation authority | Current issued version; stale version rejected; no arbitrary learner course shopping | Learner confirmation tied to exact proposal version | A material revision requires a new confirmation; history remains | Registrar sees confirmation; learner sees exact confirmed subjects/schedule |
| Placement/reservation valid or shortage | Place or change class; reserve or release seat | Registrar/TALA transaction | Placement authority and current proposal | Published class, requisite and conflict validity, protected capacity, current lock/version | Seat reservation or `EnrollmentShortageItem` with owner/deadline | Expiry/release changes current projection but preserves evidence; no ranked waitlist | Clinic 3 receives unmet-demand projection; Faculty still sees no roster until official enrollment |
| Finance pending/cleared | Verify payment requirement, coverage, or accommodation | Accounting through Clinic 6 finance authority | Accounting clearance authority | Current assessment/payment requirement and valid finance evidence | `EnrollmentPaymentRequirementProjection` becomes cleared or action needed | Later finance event updates projection; it does not select courses or finalize enrollment | Learner/Registrar see clearance status; Accounting sees bounded enrollment context |
| Ready to finalize / `OfficiallyEnrolled` | Finalize official enrollment | Registrar | Registrar finalization authority | All five checkpoints pass in one current transaction | Official term/course registrations, Student activation if first enrollment, immutable COR version | Finalization is not reversed by mail failure; post-finalization changes use adjustment/drop records | Faculty roster, Student schedule, Accounting reference, Clinic 2 follow-up reference, and COR update together |
| `CancelledByLearner`, `CancelledByRegistrar`, or `NotEnrolled` | Cancel before finalization or expire at final cutoff | Learner or Registrar | Self-cancel within boundary; recorded Registrar authority | Case not officially enrolled; deadline/outcome current | Seats released and terminal outcome recorded | Terminal case remains historical; no automatic next-term carryover | All roles see safe terminal projection; no official roster/COR is created |
| Official adjustment | Record authorized post-enrollment change | Registrar | Adjustment authority and recorded evidence | Valid replacement/placement/finance impact; current official version | Updated official registrations, placement, roster, schedule, finance projection, new COR version | Prior COR remains immutable; change is append-only | Student, Faculty, Accounting, and Registrar projections update together |
| Course Drop | Record approved Course Drop outcome | Registrar | Owning institutional authority recorded | Official registration exists; bounded academic/finance effects known | Course Drop record, roster/schedule update, new COR version | Does not invent refund/fee/grade; prior official record remains | Clinic 5 receives owning outcome; Accounting receives only valid finance effect |
| Timetable-impact review | Clinic 3 proposes published change affecting placements | Registrar | Clinic 3 revision plus Clinic 4 enrollment-impact authority | Every affected reservation/official placement has a valid outcome | Replacement, authorized course cancellation, or recorded approved outcome | No silent learner move; Clinic 3 revision publishes only after impacts resolve | One Clinic 3-owned revision event uses Clinic 4 recipients/context |
| Official Student profile correction | Record an authorized correction to a source identity/program/curriculum/entry/contact fact | Registrar | Student-record correction authority with reason and evidence | Existing Student profile; current source/version; no conflicting active correction | Append-only correction and current Student profile projection | Issued COR/TOR snapshots are never rewritten; a changed official output uses a successor version | Future Clinic 4/5/6 views use the corrected source while history retains the prior fact |

### 3.4 Five-Checkpoint Readiness Matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Student eligibility | Clinic 2 Ready Applicant or Clinic 5 released result/lifecycle/progress effect | Registrar consumes; Clinics 2/5 own source | Applicant is ready or Student has `Allowed`/authorized `AdvisingRequired`; no unresolved block/decision | Case start, placement, or finalization blocked as applicable | Start case; prepare proposal; finalize | Correct owning source or record external authorized outcome; never override locally |
| Confirmed proposed subjects | Current proposal version and learner confirmation | Registrar and learner | Proposal matches authoritative curriculum/results/classes and exact current version is confirmed | Placement/finalization blocked | Place classes | Revise/issue proposal and obtain new online or assisted confirmation |
| Valid class placement | Clinic 3 published classes plus reservations, requisites, conflicts, and capacity | Registrar | Every proposed recurring course has a valid protected placement or approved no-meeting treatment | Finalization blocked; shortage owner/deadline shown | Finalize official enrollment | Resolve shortage, validly change class, amend safe capacity, or revise timetable through Clinic 3 |
| Accounting clearance | Clinic 6 enrollment-payment requirement and verified finance evidence | Accounting | Required current amount is satisfied or valid coverage/accommodation is recorded | Finalization blocked | Finalize official enrollment | Complete payment, verify coverage, or record authorized accommodation in Finance |
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

Failure alone does not automatically dismiss a Student, impose probation, or reduce load. Clinic 5 owns the progress assessment and the authorized consequential decision. `Good` may yield `Allowed` automatically; Warning, Probation, load reduction, and Ineligible effects require Clinic 5's recorded decision before Clinic 4 applies them.

An authorized released-grade correction recalculates Clinic 5's result, GWA, curriculum evaluation, and progress recommendation. If it affects an active Registration Case, Clinic 4 routes that case to Registrar review and never silently adds, removes, or replaces a proposed or officially registered course.

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
- Pending and incomplete results do not satisfy prerequisites.
- An unavailable subject is never replaced by an arbitrary unrelated subject.
- Approved credits and equivalencies satisfy only their mapped curriculum requirement.
- A transferee cannot be finalized while required credit evaluation is missing; the UI says **Academic evaluation required**.
- A shift applies only from its recorded effective term. Historical enrollment remains under the previous program.
- Returning Students reuse the continuing journey after authorized reactivation; reactivation reserves no seat.
- Old-curriculum and bridging cases require an assigned Curriculum Version and approved deficiency/equivalency mapping, never free-text subjects.
- Institution-selected electives are assigned to the cohort before scheduling; there is no learner elective marketplace.
- The normal ceiling is the approved curriculum term total. There is no universal irregular maximum.
- Reduced enrollment is allowed when some required courses are unavailable; missing requirements remain visible.
- Overload requires a specific externally approved graduating exception. TALA hard-codes no universal overload quantity.
- Concurrent prerequisite enrollment requires recorded approval and still obeys capacity, schedule, load, and finance rules.

Student Academics and Registrar Student Record in Clinic 5 own the full curriculum evaluation. Clinic 4 shows a compact **Why these subjects** explanation and links to that evaluation.

### 4.4 Learner confirmation

- The learner confirms the complete proposed subjects and classes once.
- Registrar may record assisted confirmation performed outside TALA, including actor, method, evidence reference, and time.
- A material course, Class Offering, meeting schedule, eligibility, curriculum, or credit change creates a new proposal version and requires confirmation again.
- Non-material display or evidence corrections do not require reconfirmation.
- If the proposal appears wrong, the page says **Do not confirm—contact Registrar** and shows official contact details. TALA creates no ticket, chat, appeal, or request-help workflow.

## 5. Placement, capacity, and shortage handling

- Clinic 3 creates Draft Class Offerings from active curricula, confirmed standard-curriculum cohorts, forecasts, and bounded `UnmetClassDemandProjection` evidence. Registrar confirms, splits, shares, adds, or cancels them before scheduling.
- Separate Class Offerings per cohort are the default. Sharing requires an explicit Registrar decision for the same canonical course or approved equivalency, sufficient capacity, a valid timetable, and appropriate authority. CP-SAT never merges cohorts automatically.
- Continuing-cohort capacity remains protected until the applicable deadline. Admission does not consume capacity.
- Learner confirmation triggers transactional capacity validation and temporary reservation.
- Reservations expire at the applicable institutional enrollment/payment deadline, not an individual countdown. Expiry releases seats, preserves the case and payment evidence, records the reason, notifies the learner, and requires replanning.
- A full or stale class creates a Registrar shortage item. No assessment is finalized from failed placement.
- The shortage queue shows affected course and learners, current/protected capacity, valid alternatives, academic impact, and aggregate unmet demand.
- Resolution may use another valid class, a safe capacity amendment, or an externally approved Additional Offering. No ranked waitlist or first-confirmed future entitlement is introduced.
- A capacity-only amendment needs no CP-SAT rerun only when it stays within the assigned room's physical capacity and changes no Faculty, room, time, mode, or meeting. Every other change follows Clinic 3's timetable-revision process.

TALA targets the earliest valid completion path. It preserves the original cohort completion date only when feasible and presents a bounded outlook: `On target`, `At risk`, or `Future opportunity not yet confirmed`. It never invents future classes or graduation dates.

## 6. Finance clearance and official finalization

Clinic 4 consumes `EnrollmentPaymentRequirementProjection` containing total assessment; payment, scholarship, sponsorship, or accommodation arrangement; amount required now; verified amount applied; remaining amount required now; clearance state and basis; source/as-of time; later-obligation indicator; and Student Account link.

Official enrollment requires Accounting clearance for the amount currently required, not a universal zero balance. Failed checkout or unverified evidence never posts payment. A later missed installment cannot erase an already official enrollment. Examination access, reenrollment, record release, and login use service-specific effects; no global financial hold may block all services.

For every term, **Finalize official enrollment** atomically:

1. locks and revalidates all five checkpoints;
2. rejects stale, expired, conflicting, or out-of-order facts;
3. converts reservations into official course registrations;
4. activates Student schedule and Faculty roster projections;
5. records the official term enrollment;
6. creates immutable COR version 1 for that term;
7. publishes account and academic effects; and
8. queues one official-enrollment/COR email after commit.

Only on the person's first official enrollment does the same transaction also create the minimal Student profile, generate permanent `SIA-YYYY-NNNN` using the first-enrollment calendar year and an atomic unique sequence, grant Student access to the existing account, and retain the completed application as admissions evidence. The number encodes no program, year level, cohort, or section. The single official-enrollment/COR message also explains that Student access is active; no separate activation email is sent.

The minimal profile reuses verified Clinic 2 identity/contact facts; adds student number, entry term, program, curriculum assignment, and initial lifecycle state; and requires one identity/contact confirmation. Sex, detailed permanent address, emergency contact, civil status, religion, income, ethnicity, medical data, scholarship data, and broad family data are not Clinic 4 requirements. Any future additional official identity or reporting field requires applicable institutional/legal authority and an approved revision to its source and consuming-output authorities; Clinic 6 cannot add it implicitly.

Continuing Students reuse their account, profile, and number. Finalization is idempotent and cannot create duplicates. Mail failure never reverses the transaction.

### 6.1 Official Student profile continuity and correction

Clinic 4 owns the minimal official Student profile after first enrollment. The Student sees a read-only Profile containing legal identity, Student number, program, assigned Curriculum Version, entry/admission reference, current lifecycle projection, and approved contact facts, with plain guidance for requesting correction. Credential email, password, MFA, and Account Security remain Clinic 1 responsibilities.

Students do not directly edit official identity, program, curriculum, entry, lifecycle, enrollment, grade, or finance facts. Registrar records an authorized correction with reason, authority/evidence reference, actor, effective time, prior value, and successor value. Current source projections refresh, but an issued COR or TOR snapshot is never rewritten. When corrected content belongs in a later official output, its owning clinic creates a successor version and preserves supersession history. TALA does not collect broader demographics merely because a legacy form or schema contains them.

## 7. Cancellation, adjustment, Course Drop, and timetable impact

### 7.1 Before official enrollment

- Before learner confirmation, the learner may cancel an active case directly.
- After confirmation or reservation, Registrar records cancellation and releases seats.
- Accounting reviews payment evidence separately. Cancellation never deletes evidence or automatically refunds, credits, or reallocates money.

### 7.2 After official enrollment

Adjustment and Course Drop are distinct rule sets presented contextually in the same Enrollment workbench:

- **Adjustment** is an authorized add, remove, replacement, or class change during the adjustment window.
- **Course Drop** is an authorized removal after adjustment but within the applicable drop window.
- Requests and institutional approvals occur outside TALA; Registrar records the approved decision, authority, evidence, and system effects.
- Cost-increasing changes wait for Accounting clearance. No-cost changes may proceed after academic validation.
- Removals take academic and roster effect when recorded and create Accounting review; no refund is assumed.
- A Course Drop references an officially enrolled course without a released final result.
- Dropping every active course is full withdrawal and belongs to Clinic 5.
- Late drops require specific authority.
- Each applied change updates placement, roster, Student schedule, account-review projection, and immutable COR version together.
- TALA invents no adjustment fee, drop fee, penalty, refund, or forfeiture.

### 7.3 Published timetable changes

Clinic 3 cannot finalize cancellation of a published Class Offering while unresolved Clinic 4 placements depend on it. Unofficial reservations are released or validly replaced. Officially enrolled Students enter a Registrar impact queue. Registrar records a valid replacement, authorized course cancellation, or another approved outcome; TALA never silently moves a Student. Placement, roster, schedule, COR, and Accounting effects remain synchronized. Clinic 3's published revision triggers one shared notice; Clinic 4 supplies the affected enrolled-Student recipients and their updated schedule/COR context.

## 8. Exact UI authority

The complete low-fidelity wireframes, responsive variants, and shared Student Home/Profile coverage live in the Clinic 4 and shared-shell sections of the [UI Surface Blueprint](../ui_surface_blueprint.md). This PRD owns their product content, actions, and authorization boundaries.

### 8.1 Learner Enrollment page

Use one **Guided status page**, not a Wizard or card dashboard. Information order is:

1. Term, applicable deadline, stage, owner, next action, and one primary button.
2. Five-checkpoint summary with successful checks collapsed and failed checks explained.
3. Proposed or official subjects and schedule.
4. **Why these subjects** and link to full curriculum evaluation.
5. Academic blockers, unavailable requirements, shortage status, and bounded completion outlook.
6. Placement and reservation evidence.
7. Enrollment-payment requirement and Finance link.
8. COR and registration/change history.

Primary actions are limited to **Start enrollment/registration**, **Confirm proposed subjects and schedule**, **Continue to Finance**, and **View COR**. Only the action appropriate to the current stage appears.

### 8.2 Registrar Students & Enrollment workbench

One selected-term header shows term/windows, readiness and deadlines, official-enrollment count, shortage count, and one state-appropriate primary action.

Tabs are **Ready to prepare**, **Waiting for learner**, **Placement and shortages**, **Finance pending**, **Ready to finalize**, **Adjustments and Drops**, and **Official and history**.

Search supports legal name, verified email, application reference, and student number. Native filters cover term/program, Applicant/continuing context, selection basis, academic enrollment effect, checkpoint/stage, shortage/capacity condition, finance state, deadline/overdue state, and started/finalized/last-activity date ranges.

The record reads in this order:

1. Stage, owner, deadline, failed reason, and primary action.
2. Identity, program, curriculum, and term.
3. Academic basis and compact curriculum evaluation.
4. Proposal version and learner confirmation.
5. Eligibility, classes, capacity, reservations, and shortages.
6. Finance requirement.
7. Finalization evidence.
8. Adjustments, drops, timetable impacts, and COR versions.
9. Collapsed audit and email evidence.

Actions are **Prepare/revise proposal**, **Issue for confirmation**, **Record assisted confirmation**, **Place/change class**, **Finalize official enrollment**, **Record cancellation**, **Record adjustment**, **Record Course Drop**, and **Print current/historical COR**, shown only when valid.

Native Filament Tables own work queues and filters; Infolists and Sections own read-only detail; Forms own actual input; secondary actions use Action Groups. There is no generic gate screen, separate Study Plan resource, or column-header filter dropdown.

### 8.3 Accounting and role projections

Accounting receives one **Enrollment Clearance** queue showing learner, reference, term, assessed total, amount required now, verified amount, remaining amount, coverage/accommodation, clearance state, deadline, and next action. Accounting cannot select courses, place classes, create Student identity, or finalize enrollment.

Faculty sees only official rosters; reservations are not enrolled Students. Academic Head receives read-only oversight and authority evidence. System Administrator sees only locally evidenced System Health for integrations, queues, and email. Applicant and Public see no internal capacity analytics or other learners' information.

The Student **Profile** surface is a read-only official summary and correction-guidance page. Registrar reaches the same profile from **Students & Enrollment** and receives a focused **Record authorized correction** action only when current authority and evidence are present. Empty, stale, inaccessible, correction-conflict, and failed-output states reveal no protected identity detail and preserve entered correction evidence when safe.

### 8.4 COR

Each immutable, versioned Registration Form/COR contains institution/document identity; student number and complete legal name; program, curriculum, term, and selection basis; curriculum levels represented; official courses, units/contact hours, class references, schedules, modes, rooms, Faculty, and total units; the assessment-at-finalization snapshot; and actual recorded institutional authorities where required.

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

Mobile uses labelled stacked course and queue rows, preserves reading order, keeps the primary action reachable, and puts secondary actions in Action Groups. Loading, empty, stale, expired, 403, 404, 419, 429, validation, concurrency, and integration-failure states identify the responsible owner and safe recovery action. Keyboard access, visible focus, screen-reader status text, and non-color status meaning are mandatory.

## 9. Conceptual contracts

No public HTTP API is introduced. Minimum interfaces are:

- `RegistrationCase`
- `EnrollmentSelectionBasis`
- `ProposedRegistrationVersion`
- `ProposedCourseRegistration`
- `LearnerRegistrationConfirmation`
- `EnrollmentCheckpointProjection`
- `CourseEligibilityProjection`
- `OfficialCourseResultProjection`
- `AcademicEnrollmentEffect`
- `EnrollmentSeatReservation`
- `EnrollmentShortageItem`
- `EnrollmentPaymentRequirementProjection`
- `OfficialTermEnrollment`
- `OfficialCourseRegistration`
- `StudentProfileActivation`
- `StudentProfileCorrection`
- `EnrollmentAdjustment`
- `CourseDropOutcome`
- `CertificateOfRegistrationVersion`
- Clinic 3 `UnmetClassDemandProjection`

These are conceptual responsibilities, not physical table or class instructions.

## 10. Reconciliation disposition

| Disposition | Clinic 4 treatment |
|---|---|
| `Retain when conforming` | Transactional placement/finalization, row locking, idempotency, schedule/conflict checks, finance projection integration, authorization foundations, COR rendering/logging, and native Filament foundations. |
| `Simplify` | Nine gates become five checkpoints; enrollment state becomes terminal outcomes plus derived stage; course planning becomes proposal rows within the Registration Case; capacity becomes protection, reservation, and shortage evidence. |
| `Replace` | Standalone Study Plan, stored Regular/Irregular policy status, student-controlled arbitrary selection, generic overrides, global holds, and manually re-entered Term Offerings. |
| `Remove after dependency migration` | Unsupported numeric overload/default fees, zero-balance assumptions, ranked waitlists, duplicate Applicant/readmission paths, live installments in COR, and generic policy/state-machine machinery. |
| `Quarantine` | Existing physical fields and services remain untouched until every consumer is mapped after complete-authority approval. |

Legacy finance, COR, and lifecycle PRDs remain inputs for Clinics 5 and 6, but their Clinic 4-overlapping rules cannot override this authority.

## 11. Acceptance coverage

The later vertical slice must verify:

- New Applicant and continuing Student entry; existing Students blocked from Admissions.
- Standard and Individually Advised proposals, including assisted and online confirmation.
- Failed prerequisite with unrelated eligible courses; pending/incomplete results and later correction.
- All four academic enrollment effects.
- Transfer credit, shift effective term, returning authority, old curriculum, reduced enrollment, overload authority, and concurrent-prerequisite approval.
- Separate cohort classes, approved sharing, one/multiple valid blocks, protected capacity, shortage resolution, expiry, and concurrency races.
- Safe capacity amendment versus timetable-revision-required changes.
- Full payment, installment, scholarship, sponsorship, accommodation, failed checkout, and pending payment.
- Generic every-term finalization and conditional first Student activation.
- Cancellation boundaries, final-cutoff `NotEnrolled`, adjustment, Course Drop, full-withdrawal boundary, and class-cancellation impact.
- COR versioning and assessment snapshot.
- Cross-role authorization, idempotent email success/failure, desktop/mobile, keyboard/screen-reader use, stale-record protection, and safe error recovery.

### 11.1 Synthetic Demonstration Data

| Reference | Synthetic case | Demonstrated evidence |
|---|---|---|
| `REG-2026-0001` | First enrollment for `alma.adult@example.test`, Standard Curriculum | Clinic 2 readiness, first Student activation, official enrollment, COR v1, single combined email |
| `REG-2026-0002` | Continuing Student, Standard Curriculum | Generic every-term registration without Applicant flow or new Student identity |
| `REG-2026-0003` | Failed prerequisite with unrelated eligible courses | Bounded exclusion, remaining valid proposal, Clinic 5 released-result source |
| `REG-2026-0004` | Individually Advised old-curriculum/transferee case | Approved credits/equivalencies, proposal evidence, assisted confirmation |
| `REG-2026-0005` | Authorized reduced enrollment | Clinic 5 effect/authority, no automatic penalty or learner classification |
| `REG-2026-0006` | Two-course shortage with one reservation expiry | Protected capacity, Clinic 3 unmet demand, release, valid replacement |
| `REG-2026-0007` | Installment arrangement with required amount outstanding then cleared | Clinic 6 payment requirement, Accounting action, finalization blocker/recovery |
| `REG-2026-0008` | Scholarship/authorized accommodation | Coverage evidence without course or Registrar authority |
| `REG-2026-0009` | Official adjustment followed by Course Drop | Immutable COR v1/v2/v3, synchronized roster/schedule/finance projections |
| `REG-2026-0010` | Published timetable change affecting an enrolled Student | No silent move, Registrar impact resolution, one Clinic 3 revision event |

### 11.2 Enrollment Browser Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Ready Applicant `REG-2026-0001` | Applicant Home → Enrollment | Start enrollment | Term/deadline, five checkpoints, owner/next action, no Student identity yet | Registrar sees Ready to prepare | One Active Registration Case | Closed window blocks start unless valid late authority exists | Retry does not duplicate case |
| Continuing Student `REG-2026-0002` | Student Enrollment | Start registration | Existing Student identity and current-term status | Admissions remains unavailable | Current-term case | Attempt to reapply through Admissions is blocked | Same engine handles first and continuing enrollment |
| Registrar; `REG-2026-0001` and `0004` | Students & Enrollment | Prepare Standard and Individually Advised proposals, issue for confirmation | Curriculum/result sources, versions, **Why these subjects**, exact classes | Learner receives only current issued version | Proposal-ready email | Stale/ineligible course is rejected | No standalone Study Plan or arbitrary learner shopping |
| Learner/Registrar | Enrollment / assisted confirmation | Confirm exact proposal online or record assisted confirmation | Version and confirmation evidence | Placement queue becomes actionable | Learner confirmation | Material revision invalidates old confirmation | Only current exact version is confirmed |
| Registrar; `REG-2026-0003/0006` | Placement and shortages | Place valid courses, expose failed prerequisite, expire reservation, resolve shortage | Requisite reason, capacity evidence, owner/deadline, replacements | Clinic 3 receives unmet demand; Faculty sees no unofficial roster | Valid placement or explicit shortage | Concurrency loss refreshes rather than oversubscribes | Protected capacity and unrelated eligible courses are preserved |
| Accounting; `REG-2026-0007/0008` | Enrollment Clearance | Inspect required-now amount, verify payment/coverage/accommodation | Learner/reference/term, assessed/verified/remaining amount, deadline, next action | Registrar checkpoint updates; Accounting cannot finalize | Clearance evidence | Failed/pending checkout leaves action needed | Finance policy remains Clinic 6-owned |
| Registrar; all checks current | Ready to finalize | Finalize official enrollment | Atomic five-checkpoint pass, identity/term/version evidence | Faculty roster, Student schedule/access, Accounting reference, Clinic 2 follow-up projection update | Official enrollment and COR v1 | Stale source or mail failure cannot create partial/duplicate result | One transaction creates synchronized official projections |
| Student/Registrar; `REG-2026-0009` | Current COR / Adjustments and Drops | Record adjustment, then Course Drop; inspect versions | Current and historical COR, authority, changed roster/schedule | Faculty/Accounting/Clinic 5 receive bounded owning projections | COR v2/v3 and printable history | Invalid change preserves current official version | Prior COR versions remain immutable |
| Registrar; `REG-2026-0010` | Timetable impacts | Resolve proposed Clinic 3 revision | Affected placement, valid replacement/cancellation/outcome, no silent move | Clinic 3 can publish; affected user gets one shared event | Updated schedule/COR context | Unresolved impact blocks revision publication | Clinic 3 owns the single revision trigger/email |

## 12. Future implementation gate — not a task plan

Clinic 4 defines behavior, UI, conceptual data, acceptance, exclusions, and salvage disposition only. Clinics 1–6, canonical consolidation, the final cross-module review, and complete-authority approval are complete. A journey-complete implementation task may now be derived only through a separately approved plan; no migration design, application change, tracker change, commit, or external synchronization is authorized by this PRD.

The complete authority set has passed the final cross-module review. Clinic 5's accepted authority governs the academic handoff, and later implementation-task derivation must preserve this PRD's shared identifiers, Student-profile correction history, and cross-clinic projections without treating this document as implementation permission.

## 13. Evidence basis

- [CHED Manual of Regulations for Private Higher Education](https://legacy.ched.gov.ph/manual-regulations-private-higher-education-morphe/) governs applicable private-college enrollment and academic-record duties; institution-specific deadlines, overloads, grading, refunds, and late charges still require approved institutional authority.
- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) requires examination access for qualified disadvantaged students to remain distinct from reenrollment, record-release, and account actions; this supports service-specific rather than global financial restrictions.
- [PeopleSoft class enrollment processing](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/student-records/class-enrollment-processing.html) is a mature-system benchmark for validating requests against requisites, deadlines, permissions, conflicts, and class limits. It is not Philippine policy and does not justify importing PeopleSoft complexity.
- [PLMun's published enrollment schedule](https://www.plmun.edu.ph/event.php?id=212) is local comparison evidence that continuing and irregular learners may receive bounded enrollment periods without becoming new Applicants. It does not establish Servitech's dates or terminology.

Business evidence, curriculum sheets, PUP/PUPSIS observations, and existing code remain clarification or salvage inputs. They become enforceable only when supported by applicable authority or an approved institutional decision.

## 14. Assumptions

- TALA targets a normally recognized and authorized Philippine college.
- No approved institutional enrollment, overload, grading, refund, late-fee, or readmission handbook has been supplied.
- External institutional decisions are recorded rather than recreated.
- Existing code and data remain intact until post-authority implementation reconciliation.

Clinic 4 is approved and has passed the complete-authority review. Its checklist is satisfied through this PRD and its Clinic 4 UI authority; the settled Clinic 2→4, Clinic 3↔4, Clinic 4↔5, and Clinic 6→4 handoffs remain unchanged. Approval authorizes later implementation-task derivation only and does not authorize implementation.
