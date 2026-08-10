# PRD 02 — Application, Admission Decision, and Enrollment Readiness
## Authority and Standalone Status

**Status:** Standalone and ready for vertical-slice planning.

This PRD is the complete authority for Admission Cycles, Applicant applications, evidence, correction, decisions, official-credential outcomes, and the derived enrollment-readiness handoff. It is understandable without legacy admissions documents or current implementation. Shared terms and controls come from the [baseline](./00_system_definition_baseline.md); shared screen behavior comes from the [UI Surface Blueprint](../ui_surface_blueprint.md).
## 1. Purpose and Boundary

Clinic 2 owns the journey from a verified Applicant account to an enrollment-ready admissions record. It does not create a Student profile, student number, Student role, enrollment, payment obligation, study plan, or class placement.

The institutional boundary is deliberate:

| Responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Submit an application and preliminary review copies | Applicant | Source record and private evidence versions |
| Review preliminary evidence and decide admission | Registrar | Authorized decision record and applicant-safe projection |
| Present or transmit official credentials | Applicant, prior school, and Registrar outside TALA | Instructions plus receipt/review/verification result |
| Decide authenticity or an exceptional institutional case | Authorized institutional officer | Record the result, authority, reference, and dates; do not recreate the office process |
| Determine enrollment readiness | TALA from approved admissions facts | Derived read-only result |
| Register, place, assess, officially enroll, and create the Student identity | Clinic 4 | Consume the same ready-applicant projection without copying it |

The normal path is public Applicant self-service. Registrar-assisted entry is a bounded exception that uses the same application, requirement version, validation, decision, and history; it does not create a second workflow.

## 2. Evidence and Policy Basis

This contract is grounded in:

- [CHED CMO No. 40, s. 2008 — MORPHE](https://ched.gov.ph/wp-content/uploads/2017/10/CMO-No.40-s2008.pdf), especially the admission credentials and official-enrollment conditions for first-year and transferee students.
- [DepEd Order No. 22, s. 2012](https://www.deped.gov.ph/2012/03/20/do-22-s-2012-adoption-of-the-unique-learner-reference-number/), which establishes LRN as a confidential, permanent basic-education identifier rather than a college login credential.
- [Data Privacy Act and NPC guidance](https://privacy.gov.ph/data-privacy-act/) for transparency, legitimate purpose, proportional collection, security, and limited retention.
- [PUP iApply](https://www.pup.edu.ph/iapply/procedure/caepup) and [PeopleSoft admissions](https://docs.oracle.com/en/applications/peoplesoft/campus-solutions/9.2.038/recruiting-and-admissions/adding-new-applications-manually.html) as benchmarks for separating application, checklist visibility, admissions review, and later Student creation. These patterns do not supply Servitech policy.

No approved institutional admissions handbook has been supplied. Institution-specific requirements, exceptions, dates, decision standards, and retention periods remain effective only when their authorized source is recorded.

## 3. Supported and Excluded Applicants

Clinic 2 supports:

- First-year applicants.
- Transferee applicants.
- SHS, ALS A&E, and PEPT or equivalent as qualifying credential bases, not separate applicant types.

The following are excluded until an approved institutional policy proves the need:

- Returning or readmission applicants. They retain their Student identity and use the lifecycle process.
- Foreign-student processing requiring Bureau of Immigration authority.
- Cross-enrollees, second-degree applicants, non-degree or special students, and refresher-course students.
- Entrance-exam, interview, appeal, scholarship, medical, accommodation, credit-evaluation, courier, appointment, and physical-document custody workflows.
- Applicant schedule, delivery-modality, preferred-time, or faculty-arrangement collection.

An applicant with foreign citizenship or foreign-issued credentials receives a clear Registrar contact path. TALA does not place that applicant into an unsupported automated flow.

## 4. End-to-End Narrative

1. Clinic 1 provides a verified Applicant account.
2. While a published Admission Cycle is open, the applicant starts one application for one accepting program.
3. The applicant completes a five-step form and may save a partial draft.
4. First submission assigns a stable application reference and freezes the submitted snapshot.
5. Registrar reviews the submitted facts and preliminary digital evidence.
6. A problem produces one scoped correction request naming only the affected fields or evidence, instructions, responsible party, and deadline.
7. Once required preliminary evidence is acceptable, Registrar records `Admitted` or `NotAdmitted`.
8. An admitted applicant receives instructions for presenting physical credentials or arranging official school-to-school records.
9. The physical transaction remains outside TALA. Registrar records only receipt, review, verification, or action-needed outcomes.
10. When every credential due before registration is satisfied, TALA derives `ReadyForEnrollment`.
11. The same application appears automatically in Clinic 4's registration queue. There is no handover button and no copied applicant record.
12. Clinic 4 later owns versioned proposed registrations within the current `RegistrationCase`, placement, finance clearance, registration, official enrollment, Student-profile creation, student-number generation, and Student access. It does not create a standalone Study Plan.

Admission, credential verification, and enrollment readiness are different facts. An admitted applicant is not yet enrollment-ready, and an enrollment-ready applicant is not yet an official Student.

## 5. Application State, Corrections, and Decisions

### 5.1 Stored and derived vocabulary

Stored application states are:

- `Draft`
- `Submitted`
- `ActionNeeded`
- `Admitted`
- `NotAdmitted`
- `Withdrawn`

Derived projections are:

- `AwaitingOfficialCredentials`
- `ReadyForEnrollment`
- `RegistrationStarted`, derived from Clinic 4's linked record

There is no stored `Pending`, `ForEvaluation`, `ApprovedForHandover`, or configurable admissions state machine. `Submitted` already means Registrar action is required, so **Mark for Evaluation** is removed.

### 5.2 Editing and corrections

- Draft fields remain editable.
- After submission, only fields or evidence named by the current Registrar correction request reopen.
- A correction request contains affected items, one consolidated applicant instruction, responsible party, a required due date/time within the Admission Cycle's authorized correction boundary, actor, and time.
- Corrected items return the application to `Submitted` for review.
- The prior submitted snapshot, evidence version, review result, correction request, and resubmission remain in history.
- Server-side guards prevent stale, unauthorized, or out-of-order edits even when a page remains open.

### 5.3 Discard, withdrawal, and reopening

- **Discard draft** is available only before first submission and removes the draft's temporary uploads.
- An applicant may self-withdraw a `Submitted`, `ActionNeeded`, or `Admitted` application until Clinic 4 starts registration. Confirmation is required; the reason is optional.
- Registrar-recorded offline withdrawal requires a reason and authority.
- Registrar may reopen a withdrawn submitted application before registration begins when the Admission Cycle permits. The same reference, snapshots, evidence, and history are preserved.
- A withdrawn application is historical, not deleted or silently replaced by another same-cycle record.

### 5.4 Decisions and reconsideration

- Registrar owns routine `Admitted` and `NotAdmitted` decisions.
- Appeals occur outside TALA.
- A reconsidered or erroneous decision is corrected through an append-only superseding decision containing the previous decision reference, reason, authority, safe applicant explanation, actor, and time.
- A superseding decision never edits or erases the earlier decision.
- Academic Head participates only when a verified institutional policy assigns a genuine academic exception; Academic Head is not a universal co-approver.

### 5.5 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| Admission Cycle `Draft` | Create or revise cycle | Registrar | Admissions-cycle management | Valid target term and bounded vocabulary | Draft cycle and readiness findings | No public effect until publication | Registrar sees failed-first readiness; Public sees nothing |
| Admission Cycle `Published` / derived open or closed | Publish, extend, close, or reopen | Registrar | Recorded publication/date-change authority | Every publication blocker passes; stale action rejected | Immutable publication/change evidence and current public-entry projection | Later authorized date change supersedes current dates without erasing history | Clinic 1/Public derives entry availability; existing review continues when closed |
| Admission Cycle `Cancelled` | Cancel cycle | Registrar | Recorded cancellation authority and safe explanation | Authorized action; affected records identified | New work stops; existing records and explanation remain | Cancellation is retained; any later replacement is a distinct authorized cycle/version | Applicants see safe support path; Registrar retains history |
| Application `Draft` | Start, save, or discard | Applicant or bounded Registrar-assisted entry | Own account or authorized assistance | Cycle open; one application per account/cycle | Partial application and temporary evidence, or removal on discard | First submission supersedes editability; discard exists only before submission | Applicant sees own progress; Registrar sees no review queue until submission |
| Application `Submitted` | First submission or corrected-item resubmission | Applicant | Own application | Cycle accepts first submissions; required fields/declarations valid; stale snapshot rejected | Stable reference and immutable submitted snapshot | Later resubmission adds a version; it never rewrites the prior snapshot | Registrar queue shows action needed; Applicant sees acknowledgment/history |
| Application `ActionNeeded` | Issue scoped correction | Registrar | Application-review authority | Named fields/evidence, consolidated instruction, owner, and due date within the authorized correction boundary | Correction request and bounded reopened items | Resubmission supersedes the active request while preserving it in history | Applicant sees only actionable scope; Registrar sees waiting/overdue state |
| `Withdrawn` | Self-withdraw, record offline withdrawal, or reopen | Applicant or Registrar | Self-service before registration; recorded authority for offline withdrawal/reopen | Submitted/ActionNeeded/Admitted; Clinic 4 registration not started; cycle permits reopen | Withdrawal or reopened submitted application using the same reference | History is immutable; reopening supersedes current state without deletion | Applicant and Registrar see history; Clinic 4 ready projection disappears |
| `Admitted` / `NotAdmitted` | Record decision | Registrar | Admissions-decision authority | Identity warnings resolved; applicable review complete | Append-only decision and safe applicant projection | Reconsideration creates a superseding decision; earlier decision remains | Applicant sees safe result; no Student identity or enrollment is created |
| Credential result | Record received, under review, verified, or action needed | Registrar | Credential-review authority | Admitted application; applicable requirement version; evidence/source recorded | Official-credential outcome and history | Later authorized result supersedes current outcome, never erases it | Applicant sees safe requirement status; Clinic 4 sees only readiness projection |
| `ReadyForEnrollment` | Recalculate from authoritative admissions facts | TALA | Derived only; no user override | Current admitted decision and all credentials due before registration satisfied | Read-only readiness projection | Any authoritative reversal removes readiness and records changed source; Clinic 4 never receives a copy | Clinic 4 queue sees the same ready application; Applicant is not yet a Student |

## 6. Identity and Duplicate Prevention

- One account may have only one application per Admission Cycle.
- LRN is collected only where the official basic-education record contains it.
- Applicant-entered LRN is unverified until Registrar confirms it against the credential.
- A verified LRN may not govern two different active person accounts. A collision blocks `Admitted` until Registrar resolves it.
- Without a verified LRN, exact normalized legal name plus birth date produces a private candidate warning.
- A candidate warning does not block submission, but it blocks `Admitted` until Registrar records `SamePerson`, `DifferentPerson`, or a corrected identifier with supporting evidence.
- TALA does not calculate a fuzzy match score, automatically merge people, disclose another person's record to an applicant, or create a Student-profile duplicate-resolution workflow in Clinic 2.
- LRN is masked outside authorized detail views and never used for authentication.

## 7. Admission Cycle and Readiness

Admission dates belong to `AdmissionCycle`, not to a generic calendar-event or Settings system. A shared calendar may project cycle dates read-only but cannot own or edit them.

An Admission Cycle contains:

- Stable code and applicant-facing label.
- Target academic term.
- Opening and closing date/time.
- Accepting programs.
- Enabled paths: first-year and/or transferee.
- Published requirement-set version for every enabled path.
- Applicant instructions and official support contact.
- Privacy-notice reference.
- Publication, revision, cancellation, and responsible-authority evidence.

Stored lifecycle is `Draft`, `Published`, or `Cancelled`. `Scheduled`, `Open`, and `Closed` are derived from publication and date/time.

### 7.1 Publication hard blockers

A cycle cannot be published unless it has:

- A valid target term and non-conflicting opening and closing date/time.
- At least one active accepting program.
- A published requirement set for every enabled path.
- Applicant instructions, official support contact, and privacy notice.
- Available private file storage.
- An authorized Registrar owner.

Queued-mail failure is a degraded readiness condition owned by System Administration. It does not reverse or corrupt an application, decision, credential result, withdrawal, or readiness result.

### 7.2 Closing, extending, and cancelling

- Closing stops new applications and first submissions.
- Drafts become read-only until an authorized extension or reopening.
- Existing review, scoped correction, decision, and credential-verification work continues.
- An authorized extension or reopening records reason, authority, previous and new dates, actor, and time.
- Cancellation stops new work and provides affected applicants a safe explanation and official support path; it does not erase records.

The readiness checklist is failed-first: passed items remain collapsed, while blockers show the source record, owner, reason, and next action.

### 7.3 Readiness Matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Target term and dates | Admission Cycle and approved academic-term source | Registrar | Target exists; opening precedes closing; dates do not conflict | Cycle publication blocked | Publish cycle; accept first submission | Correct the draft dates/source and rerun readiness |
| Accepting programs | Active program authority | Registrar | At least one active program is selected | Publication blocked; applicant cannot choose a valid program | Publish cycle; start application | Activate/correct program authority, then recheck |
| Path requirement versions | Published immutable requirement sets | Registrar | Every enabled first-year/transferee path has one applicable version | Publication blocked | Publish cycle; validate submission | Publish/correct replacement requirement version |
| Applicant guidance | Cycle instructions, support contact, privacy-notice reference | Registrar and institution | Each approved reference is present and reachable | Publication blocked | Public entry and Applicant submission | Supply approved text/reference and recheck |
| Private evidence storage | Operational storage readiness | System Administrator | Private upload, validation, retrieval, and authorized download are available | Publication blocked | Evidence upload/review | Restore service; do not weaken privacy or accept public storage |
| Registrar ownership | Authorized Registrar assignment | Institution/Clinic 1 access authority | An accountable authorized Registrar is assigned | Publication and decisions blocked | Publish, review, decide, verify credentials | Record valid assignment/authority |
| Identity resolution | Application identity facts and match results | Registrar | Verified-LRN collision or exact-match warning is resolved | `Admitted` blocked | Record admission decision | Record `SamePerson`, `DifferentPerson`, or corrected identifier with evidence |
| Preliminary requirements | Submitted snapshot, requirement version, and review results | Registrar | Every pre-decision requirement has an acceptable current result or authorized bounded exception | Decision blocked or correction required | Admit/not admit | Issue scoped correction or record valid result/exception |
| Official credentials due before registration | Official credential results for the retained requirement version | Registrar | Every due-before-registration credential is verified or has an authorized permitted result | `ReadyForEnrollment` false | Clinic 4 registration entry | Record receipt/review/verification or valid non-core exception |
| Mail delivery | Queue/mail operational evidence | System Administrator | Dispatch can be queued and delivery outcome recorded | Degraded only; authoritative transaction remains valid | Send/resend transactional message | Restore transport and use authorized idempotent resend |

## 8. Versioned Requirement Sets

Registrar owns bounded, versioned admission requirement sets. System Administrator does not edit admissions policy.

- A published set is immutable.
- A correction creates a replacement version with explicit effective timing.
- A submitted application retains the requirement-set version under which it was submitted.
- A replacement does not silently rewrite earlier submissions or results.

Each requirement defines:

- Code and applicant-facing label.
- Authority and purpose.
- Applicable path.
- Whether preliminary digital evidence is required.
- Official submission method: `InPerson`, `SchoolToSchool`, or `None`.
- Due stage: `PreliminaryReview`, `EnrollmentReadiness`, or `PostEnrollmentFollowUp`.
- Applicant and Registrar instructions.
- Whether an exception is permitted and the required approving authority.
- Display order.

Regulatory minimums used by this authority are:

- First-year: Form 138 or equivalent before official enrollment; after enrollment, the institution requests Form 137.
- Transferee: the prescribed Transfer Credential or Certificate of Transfer before official enrollment; later official records follow the institution-to-institution process.
- PSA birth certificate, good moral certification, photographs, and other supplemental items are institution-specific unless another applicable authority proves them mandatory.

An official-enrollment credential cannot receive an arbitrary waiver. Only a requirement explicitly classified as non-core and permitted by approved policy may receive a documented, time-bounded exception with the required authority.

## 9. Evidence and Credential Results

### 9.1 Preliminary digital evidence

Allowed results are:

- `NotSubmitted`
- `UnderReview`
- `AcceptedAsPreliminaryEvidence`
- `ActionNeeded`

### 9.2 Official credential

Allowed results are:

- `NotYetDue`
- `NotReceived`
- `ReceivedUnderReview`
- `Verified`
- `ActionNeeded`
- `AuthorizedException`

The label **Accepted** is never shown alone because a review copy must not be mistaken for an officially verified credential.

Digital evidence remains private, authorization-protected, checksum-tracked, and versioned. Each requirement/evidence version accepts exactly one PDF, JPEG, or PNG up to 10 MiB with actual MIME/signature validation. A multipage document is one PDF. Physical implementation must also use private storage, a generated storage name, and access auditing.

Clinic 2 records metadata and outcomes for physical credentials. It does not track shelves, envelopes, courier movement, appointments, or physical custody.

## 10. Applicant Data Contract

### 10.1 Application scope

- Admission Cycle and target term.
- Selected program.
- First-year or transferee path.

### 10.2 Identity and contact

- Legal first name, optional middle name, last name, and optional extension name.
- Birth date.
- Citizenship.
- Verified account email, read-only.
- Mobile number.
- Current city or municipality and province.
- Guardian name, relationship, and mobile number only when the applicant is under 18.

### 10.3 Prior education

- Official prior-school name and country.
- Credential basis.
- Completion or graduation year.
- LRN when applicable.
- Prior-college identifier when available for a transferee.

### 10.4 Submission declarations

- Privacy-notice acknowledgment.
- Accuracy declaration.
- Preliminary evidence required by the published requirement set.

### 10.5 Field and cross-record validation

| Field group | Required rule |
|---|---|
| Application scope | Current published Admission Cycle; selected Program is active and accepting the selected first-year/transferee path; one Application per credential account/Cycle |
| Legal name | First/last required and middle/suffix optional using the shared name primitives; the submitted snapshot is immutable outside a scoped correction |
| Birth date | Required valid date not in the future; age is derived at submission time. If under 18, guardian name, 1–60-character relationship, and valid telephone become required |
| Citizenship | Required controlled country value; display label 1–100 characters |
| Email and mobile | Verified credential email is read-only; mobile uses the shared telephone primitive |
| Current locality | City/municipality and province each required plain-text labels of 1–120 characters; no complete street/barangay address is collected |
| Prior school | Official school name 1–160 characters and required controlled country value |
| Credential basis | Must be one value published for the selected path/requirement-set version |
| Completion/graduation year | Required four-digit year no later than the current Asia/Manila calendar year |
| LRN | Optional shared 12-digit primitive; duplicate verified identity blocks decision, not initial submission |
| Prior-college identifier | Transferee-only when available; trimmed 1–64 characters using the shared code primitive |
| Declarations | Both current privacy-notice acknowledgement and accuracy declaration must be affirmatively accepted at submission; a changed notice version requires acknowledgement before a later submission |
| Evidence | Current published requirement/version; exactly one allowed private file per evidence version; required evidence must finish validation before submission |

Fields not listed in Section 10 are not collected speculatively. A cross-field or source failure links to the owning Wizard step, preserves safe input, records no submitted snapshot, and names the Registrar or source-owned recovery.

Civil status, sex, birthplace, complete street or barangay address, emergency contact, religion, ethnicity, disability, household income, parental occupation, and complete Student-reporting demographics are not collected during admission. Clinic 4 may collect verified official-Student data only when an applicable authority proves the need.

## 11. Conceptual Domain Contracts

No public HTTP API is introduced. These are logical responsibilities, not approved physical table names:

| Name | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| AdmissionCycle | Own dates, paths, publication, requirements, and current availability | Registrar | Persisted authoritative record with immutable publication/date-change events | Public Gateway, Applicant, Registrar, PRD 04 | Remains the one entry and deadline authority for its cycle |
| AdmissionRequirementSet and AdmissionRequirement | Version the exact requirements for a cycle/path | Registrar | Immutable version plus owned entries | Application, credential review, readiness | Entries belong to the version and need not be separate top-level resources |
| AdmissionApplication | Own one account-and-cycle application, identity/contact/prior-school facts, declarations, and snapshots | Applicant submits; Registrar reviews | Persisted authoritative record plus immutable submitted versions | Applicant, Registrar, PRD 04 readiness | `ApplicantProfile` is a documentation grouping inside Account continuity and Application facts, not a required master record |
| PreliminaryEvidenceVersion | Preserve each private submission/replacement | Applicant submits; Registrar reviews | Immutable version or event | Requirement review and access audit | Remains distinct from the external official-credential outcome |
| ApplicationCorrectionRequest | Reopen named fields/evidence with one instruction and deadline | Registrar | Immutable version or event | Applicant and Registrar | Exactly one active request; later resubmission closes it without deletion |
| AdmissionDecision | Record admitted/not-admitted result and any authorized successor | Registrar | Immutable version or event | Applicant and `ReadyApplicantProjection` | Never edited in place |
| OfficialCredentialResult | Record the verified external credential outcome | Registrar records the external result | External reference/result with immutable history | Applicant, Registrar, readiness | `ApplicantRequirementResult` is the derived current requirement status, not another authoritative record |
| IdentityMatchReview | Resolve a possible identity conflict without disclosure | Registrar | Persisted authoritative record with restricted visibility | Account/Application uniqueness checks | Separate because it protects identity integrity and privacy |
| ReadyApplicantProjection | Publish the single Clinic 2→4 readiness handoff | PRD 02 | Derived projection/calculation | PRD 04 | Replaces the duplicate `EnrollmentReadiness` name; no copied handover record |

`ReadyApplicantProjection` carries the same application reference, applicant identity, admitted program, path, current decision, verified identifiers, requirement version, credential results, readiness date, and unresolved post-enrollment follow-ups. It creates no copied admissions or person record.

Registrar and Clinic 2 retain responsibility for any requirement classified as `PostEnrollmentFollowUp`. Clinic 4 preserves and may surface the reference but does not reinterpret it as an enrollment blocker or decide its credential result. Requirements classified as due at `EnrollmentReadiness` remain Clinic 2 blockers before the projection becomes ready.

## 12. Applicant UI Authority

### 12.1 Home

Information order:

1. Application reference, plain-language state, responsible party, and nearest deadline.
2. One next action and one primary button.
3. Cycle, program, path, and submission summary.
4. Preliminary-evidence and official-credential readiness summaries.
5. A short **What happens next** explanation.
6. Application history.

Home is a status-first task page, not a card dashboard or full process timeline.

### 12.2 Application

Use one native five-step Filament Wizard:

1. Application choice.
2. Identity and contact.
3. Prior education.
4. Preliminary evidence.
5. Review and submit.

The Wizard provides visible **Save draft**, step-level validation, a server-side closing-time recheck, accessible error summaries with field-level links, and a single-column mobile layout. Submitted fields are read-only unless reopened by a scoped correction request.

### 12.3 Requirements

Show two groups:

1. Preliminary digital review.
2. Official credential verification.

Each row shows requirement, purpose, due stage, submission method, applicant-safe result, last update, Registrar instruction, deadline, and only the permitted action. Physical resubmission is not uploaded unless the published requirement separately permits a preliminary digital replacement.

### 12.4 History and printable acknowledgment

- Earlier submitted, withdrawn, or decided applications remain read-only.
- A printable Application Acknowledgment is generated from one immutable submitted Application version and the exact published Requirement Set version that governed that submission. Later requirement changes do not rewrite the historical acknowledgment.
- The A4 portrait output contains the approved institution identity; **APPLICATION ACKNOWLEDGMENT**; Applicant display name and stable Application reference; Admission Cycle, Program, and path; submitted time; the submitted Application summary; the versioned requirement list and each applicable submission method/state as of submission; applicable physical-submission instructions; output reference and generation time; and a restrained **Generated through TALA** footer.
- The screen and every printed copy identify the source Application and Requirement Set versions. A later submitted successor or changed decision leaves the earlier acknowledgment historical and visibly labelled with its source/version; it never silently presents current requirements as if they governed the earlier submission.
- The acknowledgment is not an admission certificate, proof of official enrollment, COR, or Student record.
- The output is authenticated, monochrome-safe, semantic, and keyboard reachable; navigation and interactive controls do not print. Multi-page copies repeat the Applicant/Application identity and table headings. Stale or unavailable source prevents generation, and failure creates no partial or official-looking artifact while retaining the ordinary Application page and safe retry/support path.

## 13. Registrar Admissions UI Authority

### 13.1 Admissions workbench

Use one native Filament table with operational-count tabs:

- Needs review.
- Waiting for applicant.
- Official credentials.
- Ready for enrollment.
- History.

Columns:

- Applicant and application reference.
- Program and cycle.
- Plain-language state.
- Responsible party and next action.
- Preliminary-evidence readiness.
- Official-credential readiness.
- Nearest deadline.
- Last activity.

Search supports application reference, legal name, verified email, and exact authorized LRN search without displaying LRN in the list.

Native filters are cycle, program, path, application state, submitted date/time range, last-activity date/time range, and deadline or overdue state. Filament's filter panel and active indicators replace custom column-header dropdowns. Small tab counts satisfy the admissions-analytics need; no chart dashboard, applicant score, forecast, or ranking is created.

### 13.2 Applicant Record

Use one vertical reading order:

1. State, owner, next action, and one primary action.
2. Private identity or LRN match warning.
3. Application scope and minimum applicant facts.
4. Preliminary evidence review.
5. Current and historical admission decisions.
6. Official credentials after admission.
7. Collapsed activity, notification, and technical evidence.

Only one state-appropriate primary action appears. Secondary actions use an Action Group. There are no bulk Admit, bulk credential-verification, or bulk withdrawal actions.

### 13.3 Cycle and requirement setup

Contextual Registrar pages provide:

- Admission Cycle list and derived readiness.
- Draft cycle form.
- Published requirement-set review.
- Publish, extend, close, cancel, and publish-replacement actions with reason, authority, and audit evidence.

These pages are reached from Admissions. They are not a generic Settings area.

### 13.4 Low-fidelity wireframes and layouts

```text
Applicant Home
┌ Reference · state · owner · deadline ┐
│ What you need to do next      [Action]│
├ Cycle / program / path                ┤
├ Preliminary evidence summary          ┤
├ Official credentials summary          ┤
├ What happens next                     ┤
└ Application history                   ┘
```

```text
Registrar Admissions
┌ Admissions                    [New cycle] ┐
│ Needs review | Waiting | Credentials | Ready | History │
│ Search                         [Filters]   │
├ Applicant/reference | Program/cycle       ┤
│ State | Owner/next action | Readiness      │
│ Deadline | Last activity       [View]      │
└ Filter indicators / empty or error state  ┘
```

```text
Applicant Record
┌ State · owner · next action     [Primary] ┐
├ Identity-match warning, when present       ┤
├ Application scope and minimum identity     ┤
├ Preliminary evidence review                ┤
├ Decision and superseding history           ┤
├ Official credentials, when admitted        ┤
└ Activity / email / technical evidence      ┘
```

On mobile, tables collapse secondary columns into labelled row detail, the Wizard remains single-column, filters use the native panel, and secondary actions remain in an Action Group. Empty, loading, error, inaccessible, and stale-action states must name what happened and the safe next action. Keyboard order, visible focus, labels, status text, and error association must remain usable without color or pointer input alone.

## 14. Cross-Role Visibility and Communication

### 14.1 Role projections

- Applicant sees only their own application, safe feedback, readiness, and next actions.
- Registrar owns detailed review, decisions, cycle setup, and credential results.
- Academic Head receives aggregate admissions counts only when authorized and has no personal-application access by default.
- Accounting, Faculty, and System Administrator receive no admissions-decision authority.
- After official enrollment, Applicant disappears from the normal workspace chooser. The completed application remains Registrar evidence and may appear as a safe Student-profile summary.

### 14.2 Email matrix

| Trigger | Recipient | Safe contents | Source / idempotency key | Failure behavior | Excluded notifications |
|---|---|---|---|---|---|
| First submission or accepted resubmission | Applicant | Application reference, received time, next-step link | Submitted snapshot/version | Submission remains authoritative; authorized resend available | No draft-save or routine upload mail |
| Consolidated Action Needed request | Applicant | Affected item labels, safe instruction, required correction due date/time, secure link | Correction-request reference | Request remains active and can become overdue in workspace; delivery outcome recorded | No message for each field/status update |
| `Admitted` | Applicant | Safe result and official-credential instructions | Admission-decision reference | Decision remains effective; authorized resend available | No private reviewer notes or evidence |
| `NotAdmitted` | Applicant | Safe result, official support path, secure history link | Admission-decision reference | Decision remains effective; authorized resend available | No sensitive rationale beyond approved applicant explanation |
| `ReadyForEnrollment` first becomes true | Applicant | Readiness result, secure **Start enrollment** link, no promise of official enrollment | Application plus readiness derivation generation | Projection remains authoritative; Clinic 4 visibility is unaffected | No separate copied-handover message |
| Withdrawal recorded | Applicant | Confirmation, application reference, safe consequence/support | Withdrawal record | Withdrawal remains effective; authorized resend available | No recurring reminder |

No email is sent for draft saves, routine file receipt or verification, page activity, every status-field update, or recurring reminders.

Mail failure never rolls back submission, decision, credential verification, withdrawal, or readiness. TALA records delivery outcome, keeps the workspace authoritative, and provides an authorized resend path. Email contains the safe result and a link to TALA; private evidence and sensitive review detail remain in the authorized workspace.
## 15. Lifecycle, Mutation, and Technical Boundaries

Draft applications and unreferenced Draft Admission Cycles may be discarded only under the rules in Sections 5 and 7. Submitted applications, evidence versions, decisions, credential results, withdrawals, and readiness history are never deleted; correction, reopening, cancellation, extension, or supersession preserves the same reference chain.

This PRD defines product records and behavior, not physical tables, routes, classes, migrations, or task order. A later journey-complete slice must reconcile current Applicant pages, private storage, queues, policies, email, matching, schema, and tests against this authority without restoring a generic admissions policy engine, copied handoff, or early Student creation.
## 16. Acceptance Contract

The later implementation must prove:

- Cycle publication failure and successful opening.
- Closing-time race during first submission.
- Draft discard, read-only closure behavior, and authorized reopening or extension.
- Minimum adult and under-18 applications.
- First-year SHS, ALS A&E or PEPT, and transferee credentials.
- One application per account and Admission Cycle.
- Verified-LRN collision, corrected LRN, and no-LRN exact-name and birth-date warning.
- Private upload, invalid type or size, replacement, and unauthorized download.
- Scoped field and evidence correction.
- `Admitted` and `NotAdmitted` decisions and an audited superseding decision.
- Preliminary acceptance never appearing as official verification.
- Physical receipt, under-review, verified, and action-needed results.
- Institution-to-institution record follow-up.
- Permitted non-core exception and prohibited core-credential waiver.
- Derived `ReadyForEnrollment` and automatic Clinic 4 visibility without Student creation.
- Applicant withdrawal, Registrar-recorded withdrawal, and authorized reopening.
- Mail success, failure, idempotency, and resend.
- Printable acknowledgment bound to the submitted Application and Requirement Set versions, with exact A4 content, historical labelling, monochrome behavior, and no false admission or official-enrollment language.
- Cross-role authorization and inaccessible-record behavior.
- Native date/time filters, active indicators, empty, loading, and error states.
- Keyboard, screen-reader, desktop, mobile, and print journeys.
- Server-side prevention of stale or out-of-order actions.

Realistic demonstration data must cover at least one adult first-year application, one under-18 first-year application, one ALS A&E or PEPT credential basis, one transferee, one scoped correction, one identity warning, one admitted applicant awaiting credentials, one ready applicant, one not-admitted application, and one withdrawal. Demonstration data is not policy authority.

### 16.1 Synthetic Demonstration Data

Applicant data is a bounded journey set linked to the coordinated BM, IT, and THM Programs; it is not an annual-volume forecast. Ready applicants hand off to the same six-cohort/47-Student institutional scenario without fabricating a Student before Clinic 4 finalization.

All identities use `example.test`; dates, references, credentials, and authorities are synthetic and stable.

| Reference | Applicant/case | Starting condition | Demonstrated path |
|---|---|---|---|
| `APP-2026-0001` | Alma Adult, `alma.adult@example.test` | Adult first-year SHS applicant in an open cycle | Five-step draft, first submission, acknowledgment, admission, verified credentials, `ReadyForEnrollment` |
| `APP-2026-0002` | Ulysses Minor, `ulysses.minor@example.test` | Under-18 first-year applicant | Minimum guardian contact, scoped evidence correction, resubmission |
| `APP-2026-0003` | Alyssa Equivalency, `alyssa.als@example.test` | ALS A&E credential basis | Versioned requirement applicability and official credential outcome |
| `APP-2026-0004` | Tomas Transfer, `tomas.transfer@example.test` | Transferee with school-to-school record follow-up | Preliminary copy, official record pending, then verified |
| `APP-2026-0005` | Inez Identity, `inez.identity@example.test` | Exact-name/birth-date candidate warning | Private `DifferentPerson` resolution before admission |
| `APP-2026-0006` | Adrian Awaiting, `adrian.awaiting@example.test` | Admitted with one due credential outstanding | `AwaitingOfficialCredentials`; not visible as ready in Clinic 4 |
| `APP-2026-0007` | Nadia Not Admitted, `nadia.result@example.test` | Complete review | `NotAdmitted`, then append-only superseding `Admitted` decision with authority |
| `APP-2026-0008` | Wendy Withdrawn, `wendy.withdrawn@example.test` | Submitted application | Self-withdrawal and authorized reopen using the same reference |
| `CYCLE-2026-A` | First-year/transferee cycle | Initially missing transferee requirement version, then publishable | Failed-first readiness, publication, close, authorized extension, cancellation evidence |

### 16.2 Browser Acceptance Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Public/Applicant; `CYCLE-2026-A` closed then open | Public gateway | Inspect closed entry, then sign in and start after publication | Open/close dates, supported paths, guidance, privacy and support | Clinic 1 derives entry availability | One application for the cycle | Close-time race blocks first submission without losing safe draft facts | Closed entry never blocks existing Applicant sign-in |
| `APP-2026-0001` | Applicant Home | Complete the five Wizard steps, save draft, submit | Step status, field errors, evidence versions, declarations, stable reference | Registrar queue receives `Submitted` | A4 Application Acknowledgment bound to the submitted Application and Requirement Set versions | Invalid upload or stale submission preserves safe recovery; output failure creates no artifact | No Student, enrollment, or Study Plan record is created, and the acknowledgment claims neither admission nor enrollment |
| Registrar and `APP-2026-0002` | Admissions queue/Applicant Record | Review and issue one scoped correction; Applicant resubmits | Action-needed scope, owner, deadline, version/history | Queue moves from waiting back to needs review | Consolidated correction email | Delivery failure leaves workspace authoritative | Only named fields/evidence reopen |
| Registrar and `APP-2026-0005` | Applicant Record | Resolve identity warning and record decision | Masked identity evidence, resolution, authorized decision | Applicant sees safe decision only | Append-only decision evidence | Unresolved warning blocks `Admitted` | No merge or other-person disclosure occurs |
| Registrar and `APP-2026-0007` | Applicant Record | Record `NotAdmitted`, then authorized superseding `Admitted` | Previous and current decisions, reason, authority, safe explanations | Applicant history updates; no Student identity exists | Decision messages keyed to each decision | Stale action is rejected | Earlier decision remains immutable |
| Applicant/Registrar and `APP-2026-0004` | Requirements | Record preliminary acceptance, physical receipt, review, and verification | Separate preliminary and official result states | Readiness recalculates from official facts | Credential history | Service/mail failure cannot promote a result | Preliminary acceptance never appears as official verification |
| `APP-2026-0001` and Registrar | Applicant Home / Clinic 4 queue | Satisfy final due credential | `ReadyForEnrollment`, secure next action, no enrollment promise | Same application appears automatically in Clinic 4 | Readiness email and printable history | Reversed authoritative credential removes readiness and flags consumers | No handover button or copied admissions record exists |
| `CYCLE-2026-A` owner | Admission Cycle setup | Fail readiness, correct sources, publish, extend, close/cancel | Failed-first source/owner/recovery details and immutable authority history | Public entry changes; existing review continues | Cycle publication evidence | Storage unavailable blocks publication | Only complete, authorized cycles publish |

### 16.3 Authority-hardening control matrix

| Action or record | Authorization and validation | Confirmation/audit | Limits, deadlines, deletion, and correction |
|---|---|---|---|
| Admission Cycle Draft/publish/extend/close/cancel | Registrar; unique scoped code, valid Term/programs/paths/requirement versions, opening before closing, support/privacy/storage readiness | **Publish**, **Extend**, **Close**, or **Cancel admission cycle** shows dates, affected paths/applicants, public-entry result, reversibility, and reason/authority | Draft hard-delete only before publication and before any Application reference. Published cycles are never deleted; changes append authority. Closing stops new/first submissions but not authorized review/correction |
| Start/save/discard Application | Applicant or authorized assisted entry; exactly one Application per credential account and Cycle; identity/contact/education/program/path fields use baseline primitives and cross-field date/program checks | Draft save needs no confirmation; **Discard draft** states that the unsubmitted record and temporary evidence are removed | Only unsubmitted Draft may be discarded. Submitted Application, snapshots, and reviewed evidence are never deleted |
| Submit/withdraw/reopen | Applicant submits/withdraws own record; Registrar records offline withdrawal/reopening under authority | **Submit application** shows declarations, immutable snapshot, requirements, and Registrar review; **Withdraw/Reopen application** shows readiness effect and preserved history | Stale/invalid submit posts nothing and preserves safe input. Withdrawal before Clinic 4 registration; reopening uses same reference. No arbitrary submission/reopen count while the governing state/window permits |
| Correction request/resubmission | Registrar names exact fields/evidence, responsible party, consolidated instruction, and due date within the Cycle's authorized correction boundary; Applicant edits only that scope | **Request correction** shows reopened items, due date, Applicant message/email, and no decision effect | Exactly one active correction request. Missing its deadline marks overdue/action-needed, never rejected/withdrawn. No numeric correction/evidence-replacement cap while authorized state remains open; each resubmission preserves versions |
| Evidence version | Applicant/Registrar within the relevant requirement and state | File uses the common private-evidence primitive; multipage evidence is one PDF; requirement/source/version must match | Replacement creates a new version. Unsubmitted temporary evidence may be removed with Draft discard; submitted/reviewed evidence never deletes |
| Identity warning resolution | Registrar with bounded identity-review authority | LRN is optional 12 digits; verified duplicate against another credential blocks decision; exact normalized name+birth date is a private warning only | **Resolve identity warning** never exposes the other record to Applicant. No merge is automatic; stale evidence changes nothing |
| Admissions decision/supersession | Registrar; current submitted review, resolved identity warning, valid program/path, recorded reason/authority | **Record admission decision** shows Applicant result, credential/readiness effects, email, and no Student creation. **Supersede decision** shows both versions | One current decision; correction always appends an authorized successor. Earlier decisions and messages remain immutable; no appeal engine |
| Official credential outcome | Registrar; current requirement/version, physical/external source, result, and safe explanation | Consequential verify/reverse action names readiness effect | Late/unavailable outcome remains Registrar-owned. Preliminary evidence never becomes official automatically; reversal refreshes the same `ReadyApplicantProjection` |

The Application data contract requires requiredness, format, range, uniqueness, immutability, and cross-record validation for legal identity, birth date, contact, prior school, program/path, LRN when present, declarations, and evidence. Duplicate, stale, concurrent, inaccessible, and partial failures create no admissions mutation. `ReadyForEnrollment` remains derived and idempotent; it cannot create a Student, placement, assessment, or copied handoff record.
## 17. Technical, Operational, and External Assumptions

- Applicant demand is represented by bounded acceptance cases because supplied evidence establishes no annual forecast.
- Institution-specific requirements, Cycle dates, decision authority, official-credential verification, and exceptional cases are operational inputs recorded by Registrar; no additional hidden workflow is inferred.
- No PRD 02 product decision remains unresolved. Automatic retention disposal is outside the MVP under the product-wide boundary.
- TALA is designed for a normally recognized and authorized Philippine college.
- Registrar is the accountable admissions-decision owner.
- First-year and transferee paths are sufficient for the capstone baseline.
- The supplied TESDA handbook is contextual evidence only and does not establish Servitech college-admissions policy; exact admission requirements and dates remain Registrar-recorded operational inputs.
- The written browser walkthrough is complete authority. Live browser execution and screenshots remain later implementation-acceptance evidence and were not performed during documentation closure.
