# PRD 02 — Application, Admission Decision, and Enrollment Readiness
## Authority

This PRD owns bounded application intake and one Registrar enrollment clearance. TALA stores selected preliminary uploads. The Registrar handles receipt, custody, authenticity review and follow-up of paper credentials externally. Each delivery slice verifies implementation against the current contract. The [#48 register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932) retains decision provenance and feedback.

This PRD is the complete authority for Admission Cycles, Applicant applications, selected preliminary evidence, correction, decisions, Registrar enrollment clearance, and the derived enrollment-readiness handoff. It is understandable without legacy admissions documents or current implementation. Shared terms and controls come from the [baseline](./00_system_definition_baseline.md); shared screen behavior comes from the [UI Surface Blueprint](../ui_surface_blueprint.md).
## 1. Purpose and Boundary

Clinic 2 owns the journey from a verified Applicant account to an enrollment-ready admissions record. It does not create a Student profile, student number, Student role, enrollment, payment obligation, study plan, or class placement.

The institutional boundary is deliberate:

| Responsibility | Owner | TALA responsibility |
| --- | --- | --- |
| Submit an application and preliminary review copies | Applicant | Source record and private evidence versions |
| Review preliminary evidence and decide admission | Registrar | Authorized decision record and applicant-safe projection |
| Present, receive, review, verify and follow up paper credentials | Applicant, prior school, and Registrar outside TALA | Show source-backed instructions and one attributable Registrar clearance; no per-document physical-processing record |
| Decide authenticity or an exceptional institutional case | Authorized institutional officer outside TALA | Registrar confirms the resulting enrollment clearance without recreating the office process or arbitrary waiver |
| Determine enrollment readiness | TALA from approved admissions facts | Derived read-only result |
| Register, place, assess, officially enroll, and create the Student identity | Clinic 4 | Consume the same ready-applicant projection without copying it |

The normal path is public Applicant self-service. Registrar-assisted entry is a bounded exception that uses the same application, requirement version, validation, decision, and history; it does not create a second workflow.

For ordinary assistance, require an eligible Applicant owner and a short reason before Registrar draft entry becomes available. Record the assisting Registrar, Applicant owner and time with the existing attributable draft-save evidence. A separate approval reference and an office-evidence reference are optional supporting details when an existing authorization or office-held record is relevant; routine help requires neither a separate approval nor a new approval process. Preserve any references already recorded in history. Office references identify existing records and must not contain private evidence content.

Registrar assistance can prepare, save or discard an unsubmitted Draft only. It cannot edit a submitted application or complete the Applicant's scoped correction. The Applicant reviews their declarations and performs first submission. Assistance context precedes the shared five-step form without creating another Applicant step or locking unrelated sections. Missing assistance context prevents assisted writes and shows a field-specific validation message; it must not claim that a file failed or an application was partly saved when neither happened.

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
4. First submission assigns a stable application reference and freezes the submitted snapshot. New references use `APP-{four-digit year}-{three groups of four random characters}`, for example `APP-2026-ABCD-EFGH-JK23`. The 12-character suffix excludes I, L, O, 0 and 1. Allocation checks up to ten candidates against existing references; a database unique constraint remains authoritative. Existing references remain unchanged, including submitted snapshots, acknowledgments and retained history. The reference identifies the record and grants no access by itself.
5. Registrar reviews the submitted facts and preliminary digital evidence.
6. A problem produces one scoped correction request naming only the affected fields or evidence, instructions, responsible party, and deadline.
7. Registrar records the review outcome: `Admitted` requires acceptable preliminary evidence and resolved identity warnings; `NotAdmitted` records its review basis and a safe explanation.
8. An admitted applicant receives the school's instructions for presenting physical credentials or arranging official school-to-school records.
9. The school checks and retains those detailed paper records outside TALA. Registrar records one current enrollment clearance against the admitted application.
10. A current `Cleared` result and current admitted decision, with identity warnings resolved, produce `ReadyForEnrollment`.
11. The same application appears automatically in Clinic 4's registration queue. There is no handover button and no copied applicant record.
12. Clinic 4 later owns versioned proposed registrations within the current `RegistrationCase`, placement, finance clearance, registration, official enrollment, Student-profile creation, student-number generation, and Student access. It does not create a standalone Study Plan.

Preliminary review acceptance, admission, Registrar enrollment clearance, and enrollment readiness are different facts. Clearance records the school's permission to proceed after its external checks; it does not claim TALA authenticated every paper credential. An enrollment-ready applicant is not yet an official Student.

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

- `AwaitingRegistrarClearance`
- `ReadyForEnrollment`
- `RegistrationStarted`, derived from Clinic 4's linked record

`Submitted` means the application is awaiting Registrar review. Use the stored states and derived projections above to present the current journey.

### 5.2 Editing and corrections

- Draft fields remain editable.
- After submission, only fields or evidence named by the current Registrar correction request reopen.
- A correction request contains affected items, one consolidated applicant instruction, responsible party, a required due date/time on or before the Admission Cycle's correction boundary, actor, and time.
- Registrar may issue a new scoped correction after public application closing and through the inclusive correction boundary. After that boundary, a new request requires an authorized correction-boundary extension.
- An already active correction remains actionable after public closing, its due time, or the correction boundary. Overdue remains `ActionNeeded`; TALA never auto-rejects or withdraws the application.
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

- Registrar owns routine `Admitted` and `NotAdmitted` decisions. For an ordinary first decision, the recorded Registrar, time, internal reason and safe applicant explanation supply attributable authority; a separately entered approval reference is optional.
- A replacement decision or explicitly identified exceptional approval requires a separate approval reference. Retain the exceptional-approval classification in the decision event. Recording that classification does not waive identity, preliminary-evidence, authorization or current-version guards, and creates no additional approval workflow.
- Appeals occur outside TALA.
- A reconsidered or erroneous decision is corrected through an append-only superseding decision containing the previous decision reference, reason, authority, safe applicant explanation, actor, and time.
- A superseding decision never edits or erases the earlier decision.
- Academic Head participates only when a verified institutional policy assigns a genuine academic exception; Academic Head is not a universal co-approver.

### 5.5 Consolidated State and Action Matrix

| State or projection | Trigger or action | Actor | Authorization | Guards | Resulting record or effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|---|
| Admission Cycle `Draft` | Create or revise cycle | Registrar | Admissions-cycle management | Valid target term and bounded vocabulary | Draft cycle and readiness findings | No public effect until publication | Registrar sees failed-first readiness; Public sees nothing |
| Admission Cycle `Published` / derived open or closed | Publish, extend, close, or reopen | Registrar | Recorded publication/date-change authority | Every publication blocker passes, including valid public and correction boundaries; stale action rejected | Immutable publication/change evidence and current public-entry/correction projection | Later authorized date change supersedes current dates without erasing history | Clinic 1/Public derives entry availability; existing review continues when closed |
| Admission Cycle `Cancelled` | Cancel cycle | Registrar | Recorded cancellation authority and safe explanation | Authorized action; affected records identified | New starts and first submissions stop; existing correction, review, decision and clearance work resolves under its recorded authority | Cancellation is retained; any later replacement is a distinct authorized cycle/version; no automatic adverse decision or clearance reversal | Applicants see safe explanation/support and applicable existing actions; Registrar retains history |
| Application `Draft` | Start, save, inspect, or discard | Applicant or bounded Registrar-assisted entry | Own account or authorized assistance; assisted selection rechecked before navigation and saving | Cycle open for assisted preparation/start/save; picker includes only an available unsubmitted Draft or unused open intake. Prior submitted/terminal history permits a separate unused intake; submitted cases and corrections are excluded from assisted edits. Inspect/discard remains available after close/cancellation; one application per account/cycle | Partial application and temporary evidence, or removal on discard | First submission supersedes editability; discard exists only before submission | Applicant sees own progress; Registrar sees no review queue until submission |
| Application `Submitted` | First submission or corrected-item resubmission | Applicant | Own application | First submission requires the public application window; corrected resubmission requires an active scoped request; required fields/declarations valid; stale snapshot rejected | Stable reference and immutable submitted snapshot | Later resubmission adds a version; it never rewrites the prior snapshot | Registrar queue shows action needed; Applicant sees acknowledgment/history |
| Application `ActionNeeded` | Issue scoped correction | Registrar | Application-review authority | Named fields/evidence, consolidated instruction, owner, and due date on or before the current correction boundary | Correction request and bounded reopened items | Resubmission supersedes the active request while preserving it in history; overdue remains actionable | Applicant sees only actionable scope; Registrar sees waiting/overdue state |
| `Withdrawn` | Self-withdraw, record offline withdrawal, or reopen | Applicant or Registrar | Self-service before registration; recorded authority for offline withdrawal/reopen | Submitted/ActionNeeded/Admitted; Clinic 4 registration not started; cycle permits reopen | Withdrawal or reopened submitted application using the same reference | History is immutable; reopening supersedes current state without deletion | Applicant and Registrar see history; Clinic 4 ready projection disappears |
| `Admitted` / `NotAdmitted` | Record decision | Registrar | Recorded Registrar authority for an ordinary first decision; separate approval reference for replacement or explicitly exceptional approval | Current application and review; `Admitted` requires resolved identity warnings and acceptable required preliminary evidence; `NotAdmitted` requires a recorded review basis and safe explanation | Append-only decision and safe applicant projection | Reconsideration creates a superseding decision; earlier decision remains | Applicant sees safe result; no Student identity or enrollment is created |
| Registrar enrollment clearance | Record cleared or action needed after external school checks | Registrar | Enrollment-clearance authority | Current admitted application and submitted version; identity warnings resolved; current external school result confirmed | One attributable current result with predecessor history | A changed result appends a successor; stale action changes nothing | Applicant sees safe clearance/instruction; Clinic 4 consumes only current readiness |
| `ReadyForEnrollment` | Recalculate from authoritative admissions facts | TALA | Derived only; no user override | Current admitted decision, resolved identity warnings and current matching `Cleared` result | Read-only readiness projection | Changed decision/clearance or stale source removes readiness and flags affected consumers; no copied application | Clinic 4 sees the same ready application; no Student identity is created |

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
- Opening, public closing, and correction-boundary date/time.
- Accepting programs.
- Enabled paths: first-year and/or transferee.
- Published requirement-set version for every enabled path.
- Applicant instructions and official support contact.
- Privacy-notice reference.
- Publication, revision, cancellation, and responsible-authority evidence.

Stored lifecycle is `Draft`, `Published`, or `Cancelled`. `Scheduled`, `Open`, and `Closed` are derived from publication and date/time.

A cycle Draft may be saved with its applicant-facing name, unique stable code and target term before owner, dates, program/path scope, guidance or privacy notice are complete. These missing publication prerequisites appear in readiness and block Publish, not preparation. Continue setup resumes the same Draft; Save and exit returns to its overview. Confirmed discard is available only for a never-used Draft with no Application reference, requirement set or retained event. Other domain Drafts retain their own minimum persisted identity and final transition.

Dates and times are explicit future school schedules in Asia/Manila, persisted as UTC instants. Location does not determine a deadline. Date pickers preserve existing seconds and precise values. The recorded instant is the selected date/time; no device-location, whole-day or end-of-day inference changes it or its boundary comparison. Current/proposed values and the distinct public-closing, new-correction and active-correction consequences remain visible.

### 7.1 Publication hard blockers

A cycle cannot be published unless it has:

- A valid target term, non-conflicting opening and public closing date/time, and a correction boundary at or after public closing.
- At least one active accepting program.
- A published requirement set for every enabled path.
- Applicant instructions, official support contact, and privacy notice.
- Available private file storage.
- An authorized Registrar owner.

Queued-mail failure is a degraded readiness condition owned by System Administration. It does not reverse or corrupt an application, decision, clearance result, withdrawal, or readiness result.

### 7.2 Closing, extending, and cancelling

- Closing stops new applications and first submissions.
- Draft entry and first submission become unavailable until an authorized extension or reopening. The Applicant can still inspect or discard an unsubmitted draft.
- Registrar may issue new scoped corrections after public closing only through the inclusive correction boundary, and every new request must be due on or before that boundary.
- Existing active correction and corrected resubmission remain available after public closing and after their due time; overdue stays `ActionNeeded`. The correction boundary does not stop an active correction, ongoing review, decisions, or clearance work.
- After the correction boundary, issuing a new correction request requires an authorized extension of that boundary. Ordinary review, decisions, and clearance work continue.
- An authorized public-window or correction-boundary extension or reopening records reason, authority, previous and new dates, actor, and time.
- Cancellation stops new starts and first submissions and provides affected applicants a safe explanation and official support path. Unsubmitted Drafts remain inspectable/discardable. Existing active correction, review, decision and clearance work continues under its recorded authority and boundaries. Any adverse decision, withdrawal or clearance change requires its own attributable action; cancellation creates none automatically. A replacement is a distinct authorized cycle/version.

The readiness checklist is failed-first: successful checks use a concise summary, while blockers show the source record, owner, reason, and next action.

### 7.3 Readiness Matrix

| Check | Authoritative source | Owner | Valid condition | Effect if missing | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Target term and dates | Admission Cycle and approved academic-term source | Registrar | Target exists; opening precedes public closing; correction boundary is at or after public closing; dates do not conflict | Cycle publication blocked | Publish cycle; accept first submission; issue new correction request | Correct the draft dates/source and rerun readiness |
| Accepting programs | Active program authority | Registrar | At least one active program is selected | Publication blocked; applicant cannot choose a valid program | Publish cycle; start application | Activate/correct program authority, then recheck |
| Path requirement versions | Published immutable requirement sets | Registrar | Every enabled first-year/transferee path has one applicable version | Publication blocked | Publish cycle; validate submission | Publish/correct replacement requirement version |
| Applicant guidance | Cycle instructions, support contact, privacy-notice reference | Registrar and institution | Each approved reference is present and reachable | Publication blocked | Public entry and Applicant submission | Supply approved text/reference and recheck |
| Private evidence storage | Operational storage readiness | System Administrator | Private upload, validation, retrieval, and authorized download are available | Publication blocked | Evidence upload/review | Restore service; do not weaken privacy or accept public storage |
| Registrar ownership | Authorized Registrar assignment | Institution/Clinic 1 access authority | An accountable authorized Registrar is assigned | Publication and decisions blocked | Publish, review, decide, record clearance | Record valid assignment/authority |
| Identity resolution | Application identity facts and match results | Registrar | Verified-LRN collision or exact-match warning is resolved | `Admitted` blocked | Record admission decision | Record `SamePerson`, `DifferentPerson`, or corrected identifier with evidence |
| Preliminary requirements | Submitted snapshot, requirement version, and review results | Registrar | Required preliminary copies have acceptable current review results | `Admitted` blocked; correction remains available. Registrar may record `NotAdmitted` with its review basis and safe explanation | Admit | Issue a scoped correction and review the replacement evidence |
| Registrar enrollment clearance | Current clearance linked to the admitted application/decision and submitted version | Registrar | External prerequisites are confirmed and current result is `Cleared`; identity warnings resolved | `ReadyForEnrollment` false | Clinic 4 registration entry | Complete the external check or show one safe action-needed instruction, then record a successor result |
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
- Due stage for the selected digital copy: `PreliminaryReview`.
- Plain applicant and Registrar instructions and any source-backed digital deadline.
- Display order.

The bounded baseline names **PSA birth certificate** and **2x2 ID photo** as selected preliminary uploads, supported by the client workflow. Both selected copies are required for the supported first-year/transferee baseline. Their published path version records that requiredness and file treatment; it does not become a register of every paper credential. One path/cycle instruction explains externally submitted school documents and the contact route. Physical methods, individual receipt/review statuses, custody, exception approval and post-enrollment follow-up remain in the school's external records.

Regulatory minimums used by this authority are:

- First-year: Form 138 or equivalent before official enrollment; after enrollment, the institution requests Form 137.
- Transferee: the prescribed Transfer Credential or Certificate of Transfer before official enrollment; later official records follow the institution-to-institution process.
- PSA birth certificate, good moral certification, photographs, and other supplemental items are institution-specific unless another applicable authority proves them mandatory.

The external school checks remain necessary. TALA does not waive a core credential or decide an exception: Registrar records `Cleared` only after the responsible office confirms its applicable prerequisites. A permissible external exception remains the school's decision and paper record; TALA retains only the clearance and necessary safe explanation/reference.

## 9. Preliminary Evidence and Registrar Enrollment Clearance

### 9.1 Preliminary digital evidence

Allowed results are:

- `NotSubmitted`
- `UnderReview`
- `AcceptedAsPreliminaryEvidence`
- `ActionNeeded`

### 9.2 One Registrar enrollment clearance

Registrar records one append-only clearance result for the admitted application: `Cleared` or `ActionNeeded`. While no current result exists, show **Awaiting Registrar clearance**. The result carries the application/submitted-version and admission-decision references, Registrar actor/time, a predecessor reference when superseded, and a safe instruction when action is needed. A correction or reversal requires a reason. Routine clearance records the confirmed outcome; retain an external authority/reference when a consequential exception or correction needs it.

- **Record enrollment clearance** confirms that the school's required external checks are complete and shows the resulting readiness effect. It never authenticates documents on the school's behalf.
- `Cleared` requires confirmed current external checks and resolved identity warnings for an admitted application. Pending or incomplete external checks may produce `ActionNeeded` with a safe instruction. Revalidate the current application, submitted version and decision atomically before recording either result; a repeated action returns the same effect.
- Clearance is tied to the current decision/submitted facts. Changed material identity, decision or clearance invalidates readiness until Registrar records the matching current result.
- Before registration, loss of clearance removes the ready projection. After registration starts, Clinic 4 receives an action-needed source change and rechecks its current case before finalization. After official enrollment, retain the enrollment and route the discrepancy to Registrar with the changed source/version, affected Student/Term and next action. Registrar resolves identity/admissions facts and invokes an existing authorized Clinic 4/5 correction or lifecycle action only when required. No automatic seat, grade, COR, financial or access reversal follows.
- Physical receipt, document-by-document verification, appointments, paper storage and later school-to-school follow-up remain outside TALA. No generic clearance/exception engine is introduced.

The label **Accepted** is never shown alone because an acceptable preliminary copy must not be mistaken for admission or clearance. **Cleared for enrollment** is permission to begin the enrollment journey, not proof of official enrollment.

Digital evidence follows the shared private-evidence primitive in PRD 00 Section 11.4: one PDF, JPEG, or PNG up to 10 MiB per requirement/evidence version, with server-detected MIME and matching file-format header. A multipage document is one PDF; the 2x2 photo requires an image. Each operation authorizes the application, requirement and evidence version and verifies the generated path belongs to that exact scope, including client-supplied existing-file references and temporary-file cleanup. Private storage alone grants no record access. Format checks and checksums prove neither document authenticity nor absence of malware; the school owns authenticity checks outside TALA.

Existing historical per-document results remain attributable, read-only legacy evidence. This contract does not require deleting them or fabricating aggregate clearance from them. The executor must reconcile their permitted display/migration within its bounded task; old file acceptance or a historical result cannot silently assert new clearance.

## 10. Applicant Data Contract

### 10.1 Application scope

Admission Cycle/target term, accepting Program and first-year/transferee path are required. Use the current published choices; do not ask for modality, preferred time or classes at admission.

Reader-facing Applicant and Registrar surfaces label the path **Student type**, with the values **Freshman** (the first-year path) and **Transferee**, matching the Registrar's terminology (owner decision D1, October 10, 2026, #48 F68). The stored path values, submitted snapshots and the regulatory first-year/transferee wording in this PRD remain unchanged.

### 10.2 Personal and contact information

Required: first/last name, birth date, citizenship, verified read-only Email address, Mobile number, City/municipality and Province. Middle name and Suffix are optional. Name values remain the person's official-record facts although labels omit internal "legal" wording.

Optional: Sex, Civil status, Barangay, House/unit and street, and Postal code. Sex/civil status retain the latest client's requested intake facts for Registrar comparison with supplied identity/credential records; they create no eligibility rule or speculative export. Explain that purpose beside the group and obtain separate specific consent for these optional sensitive facts when supplied. Declining leaves both fields empty and preserves the ordinary application path. Label the control **I agree to the use of my optional sex and civil-status details for identity-record comparison**; default it unchecked. Place this choice before the optional editable fields and obtain consent before either value is transmitted or saved, including Livewire updates and Draft saves. Retain notice version, purpose and consent time with any saved optional values and in the submitted snapshot. An unchecked group transmits neither value; clearing the choice before submission clears both values from the current Draft through the existing save action, while earlier submitted snapshots remain historical. Empty optional values remain unknown; no defaults are invented.

One Parent/guardian contact (name, relationship and telephone) is required below 18. For an adult, one parent/guardian/emergency contact is optional. Supplying any component requires the complete contact. The baseline needs no parents' civil status, occupation, income, duplicate addresses, two-parent assumption, parent account or family subsystem.

### 10.3 Educational Background

Previous school attended, Educational attainment and Graduation year are required. The attainment choices preserve the published credential-basis values for the selected path. School location/address and a transferee's prior-college identifier are optional when known. A trusted local school list may assist; Other remains usable without a live directory integration. Text validation cannot certify that a school is genuine.

LRN availability is Provided, NotIssued or NotAvailable. Provided requires the accurate 12-digit LRN, including leading zeroes. An unavailable identifier uses Registrar's identity-resolution path; no fictional value, automatic exclusion or universal LRN prerequisite is introduced. Registrar resolves identifier checks before recording **Admitted**. Source-country facts are retained only when known and necessary; the ordinary Philippine-credential form collects its necessary local address facts once. Foreign-issued credentials retain the existing Registrar contact route, without an assumed Philippines value.

### 10.4 Notice, evidence, review and submission

Show plain data-use guidance and obtain the current privacy-notice acknowledgement before first private evidence upload. Retain its version and acknowledgement fact. PRD 01 owns the single notice and lawful-processing distinction; notice acknowledgement, optional sensitive-field consent and final accuracy declaration are separate facts. Validate specific consent before accepting either optional value at every save, update or submit seam. If a crafted or stale request supplies values without consent, reject that group with a clear error and a clear-values recovery; all other independent steps remain usable. Upload only the selected preliminary copies in the published path's requirement set, with distinct PSA birth certificate and 2x2 ID photo labels. The published version preserves the baseline requiredness; physical school documents are explained by the path/cycle instruction and handled outside TALA.

Keep the final review and affirmative accuracy declaration before Submit application. Save and continue and Save and exit must actually persist a draft; Continue alone must not imply persistence. A changed notice requires acknowledgement before a later upload/submission. Historical missing declaration facts remain visibly unavailable, never fabricated.

### 10.5 Exact validation and exceptions

| Field/group | Contract |
|---|---|
| Application choice | Current published Cycle, accepting Program/path and one application per credential account/Cycle |
| Names | Required first/last, optional middle/suffix using the shared name primitives; submitted facts change only through named correction |
| Birth date | Required valid typeable non-future date; age derived at submission. No invented numeric minimum age; qualifying prior education governs the supported path |
| Citizenship | Required controlled country value; display label 1–100 characters |
| Email and mobile | Verified email read-only; required mobile uses shared telephone validation |
| Address | Required city/municipality and province each 1–120 characters; optional barangay 1–120, house/unit/street 1–160 and postal code exactly four digits stored as text. No fictitious number or unknown-address default |
| Sex and civil status | Optional source-record text, 1–40 characters when supplied, with the separate specific consent in Section 10; unchecked by default, no assumed values or eligibility effects, and declining preserves application eligibility |
| Contact | Name uses shared name rules; relationship 1–60 characters and valid telephone. Complete group required for minors; adult group optional but complete when supplied |
| Previous school | Required name 1–160 characters; optional location/address 1–160. Other allows truthful entry; Registrar checks supporting facts. No live lookup dependency |
| Educational attainment/year | Published supported credential basis; required exactly four-digit graduation year no later than the current Asia/Manila year. Malformed/future input receives a field-specific correction message |
| LRN | Provided requires exactly 12 digits as text; NotIssued/NotAvailable retains the explicit fact. Verified collisions and unresolved identity warnings block **Admitted**, without disclosing another person's record |
| Prior-college identifier | Transferee-only when available; trimmed 1–64 characters preserving the issuing school's reference format. Render safely and retain punctuation and leading zeroes. A First Year Draft clears this hidden value, including after a path change; submitted snapshots and named correction scope remain protected |
| Evidence | Applicable immutable requirement version; one validated private PDF/JPEG/PNG up to 10 MiB per version. A photo uses an image; a multipage document uses one PDF. Only designated preliminary copies are uploaded |
| Declarations | Notice acknowledgement before evidence upload; affirmative accuracy declaration at final submission; snapshots retain the applicable facts/version |

Visible labels show optional/required treatment before entry. Validation identifies the owning field/step, preserves safe input and creates no partial submitted snapshot. Registrar clearance never substitutes for identity/correction guards. Birthplace, religion, ethnicity, disability, household income, parental occupation, duplicate family addresses and speculative reporting demographics remain excluded. Official enrollment reuses necessary verified facts.

### 10.6 Evidence and scope rationale

The supplied client workflow Section 2.1 names PSA birth certificates and 2x2 photographs for the college paths. The COR sample proves output continuity, not a blank intake form. The 2019 handbook identifies TESDA training; its age/refund examples do not establish college policy. [UP's admission notice](https://upcat2026form2a.up.edu.ph/privacy) supports a scoped tertiary comparison for minors and LRN when available; its extensive socioeconomic data serves UP-specific selection purposes. [NPC guidance](https://privacy.gov.ph/data-privacy-act/) supports field purpose and proportional collection. These sources inform delegated TALA decisions rather than prescribing Servitech policy.

Sex/Civil status are optional intake facts; one conditional contact supports assistance. LRN collection distinguishes Provided, NotIssued and NotAvailable. Supported qualifying education determines the admission path. School assistance includes Other for truthful entry. Keep independent form sections usable, privacy acknowledgement before upload and final review before submission. Decision history and feedback mapping belong to the [#48 register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932).

Show actual published deadlines and source-backed in-person instructions, without an invented review SLA. PRDs 04/06 own registration, assessment/payment order and externally executed refunds; admission introduces no payment engine, extra charge or refund entitlement.

## 11. Conceptual Domain Contracts

No public HTTP API is introduced. These are logical responsibilities, not approved physical table names:

| Name | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| AdmissionCycle | Own dates, paths, publication, requirements, and current availability | Registrar | Persisted authoritative record with immutable publication/date-change events | Public Gateway, Applicant, Registrar, PRD 04 | Remains the one entry and deadline authority for its cycle |
| AdmissionRequirementSet and AdmissionRequirement | Version the exact requirements for a cycle/path | Registrar | Immutable version plus owned entries | Application, credential review, readiness | Entries belong to the version and need not be separate top-level resources |
| AdmissionApplication | Own one account-and-cycle application, identity/contact/prior-school facts, declarations, and snapshots | Applicant submits; Registrar reviews | Persisted authoritative record plus immutable submitted versions | Applicant, Registrar, PRD 04 readiness | `ApplicantProfile` is a documentation grouping inside Account continuity and Application facts, not a required master record |
| PreliminaryEvidenceVersion | Preserve each private submission/replacement | Applicant submits; Registrar reviews | Immutable version or event | Requirement review and access audit | Remains distinct from the Registrar enrollment clearance |
| ApplicationCorrectionRequest | Reopen named fields/evidence with one instruction and deadline | Registrar | Immutable version or event | Applicant and Registrar | Exactly one active request; later resubmission closes it without deletion |
| AdmissionDecision | Record admitted/not-admitted result and any authorized successor | Registrar | Immutable version or event | Applicant and `ReadyApplicantProjection` | Never edited in place |
| RegistrarEnrollmentClearance | Record one permission-to-proceed outcome after external school checks | Registrar | Attributable result with successor history | Applicant, Registrar, Clinic 4 readiness | No per-paper-document receipt/review record or generic clearance engine |
| IdentityMatchReview | Resolve a possible identity conflict without disclosure | Registrar | Persisted authoritative record with restricted visibility | Account/Application uniqueness checks | Separate because it protects identity integrity and privacy |
| ReadyApplicantProjection | Publish the single Clinic 2→4 readiness handoff | PRD 02 | Derived projection/calculation | PRD 04 | One source-linked derived readiness fact for the current application/version |

`ReadyApplicantProjection` carries the same application/submitted-version reference, identity, admitted program/path, current decision, confirmed identifiers, current clearance reference/result and readiness/as-of time. It creates no copied admissions/person record and no detailed paper-document dossier.

Registrar owns the external paper-document check and follow-up. Clinic 4 consumes the current clearance/readiness source without copying the paper checklist, reclassifying requirements or recording separate document results. A source change revalidates only the affected registration/finalization action.

## 12. Applicant UI Authority

### 12.1 Home

Application references remain complete, selectable and copyable through a shared labelled control with success/failure feedback. Native tables may use their native copy affordance. Resubmission, withdrawal, reopening and replacement decisions preserve the first issued reference; readable grouping creates no second identifier.

The public tracking view, reached only through PRD 01's expiring verified-owner email link, shows a fixed plain-language current status, next step or waiting condition, and the published cycle support contact. It excludes identity/contact facts, correction text, evidence, acknowledgments, private reasons and history. Protected work continues through ordinary authenticated Applicant access. The tracking view derives current facts and grants no mutation permission.

Information order:

1. Plain-language current situation, responsible office, relevant deadline and permitted next step or waiting condition.
2. One primary action when the applicant can act; otherwise the reason and condition that enable the next step.
3. Compact application reference, cycle, program, path and submission facts.
4. Distinct preliminary-review, admission and Registrar-clearance summaries.
5. One short **What happens next** explanation.
6. Reachable application history and version-bound acknowledgment.

Home is a status-first task page. A compact progress indicator explains the factual application, review, decision and Registrar-clearance stages without introducing stored workflow states. Its current stage, nearest deadline and one permitted next action lead; secondary readiness and historical details remain reachable through progressive disclosure.

Conditional coverage includes no application, open/closed intake, saved Draft inspection and discard, save/validation failure, scoped and overdue corrections, consent-gated optional details, LRN availability, transferee education, minor-contact requirements, evidence availability/replacement, admission outcomes, pending or ActionNeeded clearance, withdrawal, history and version-bound acknowledgment. Each applicable state requires evidence; the presence of a hidden control does not establish its usability or correct authorization. Registration/payment surfaces already reachable after readiness retain PRDs 04/06 ownership. Admissions creates no fee, payment obligation or official Student; the UI explains the next office and handoff.

### 12.2 Application

Use one native five-step Filament Wizard:

1. Application choice.
2. Identity and contact.
3. Prior education.
4. Preliminary evidence.
5. Review and submit.

The Wizard provides visible **Save and continue** progression and secondary **Save and exit**, step-level validation, a server-side closing-time recheck, accessible error summaries with field-level links, and a single-column mobile layout. Submitted fields are read-only unless reopened by a scoped correction request.

Use plain applicant-facing labels, including First name, Last name, Suffix, Email address and Educational Background, while retaining the underlying identity and validation semantics. Group related fields responsively and retain labels/requiredness when a placeholder disappears. Do not lock unrelated sections merely to reduce scrolling. A save/continue label must describe an actual save; failed saves retain input and show the owning field or source error.

### 12.3 Requirements

Show two groups:

1. Preliminary digital review.
2. Registrar enrollment clearance and source-backed external instructions.

Digital rows show the selected requirement, purpose, current review result/version, last update, instruction, actual deadline and permitted action. One separate clearance summary shows its safe result, instruction and recorder/time. Paper credentials are explained by one source-backed path instruction; no per-document physical-processing table is shown.

### 12.4 History and printable acknowledgment

- Earlier submitted, withdrawn, or decided applications remain read-only.
- A printable Application Acknowledgment is generated from one immutable submitted Application version and the exact published Requirement Set version that governed that submission. Later requirement changes do not rewrite the historical acknowledgment.
- The A4 portrait output contains the approved institution identity; **APPLICATION ACKNOWLEDGMENT**; Applicant display name and stable Application reference; Admission Cycle, Program, and path; submitted time; the submitted Application summary; the versioned requirement list and each selected digital-copy instruction/state as of submission; applicable physical-submission instructions; output reference and generation time; and a restrained **Generated through TALA** footer.
- The screen and every printed copy identify the source Application and Requirement Set versions. A later submitted successor or changed decision leaves the earlier acknowledgment historical and visibly labelled with its source/version; it never silently presents current requirements as if they governed the earlier submission.
- The acknowledgment is not an admission certificate, proof of official enrollment, COR, or Student record.
- The output is authenticated, monochrome-safe, semantic, and keyboard reachable; navigation and interactive controls do not print. Multi-page copies repeat the Applicant/Application identity and table headings. Stale or unavailable source prevents generation, and failure creates no partial or official-looking artifact while retaining the ordinary Application page and safe retry/support path.

## 13. Registrar Admissions UI Authority

### 13.1 Admissions workbench

Use one native Filament table with operational-count tabs:

- Needs review.
- Waiting for applicant.
- Registrar clearance.
- Ready for enrollment.
- Closed applications, retaining the existing terminal-history membership and records.

Columns:

- Applicant and application reference.
- Program and cycle.
- Plain-language state.
- Responsible party and next action.
- Preliminary-evidence readiness.
- Registrar-clearance result.
- Nearest deadline.
- Last activity.

Search supports application reference, legal name, verified email, and exact authorized LRN search without displaying LRN in the list.

Native filters are cycle, program, path, application state, submitted date/time range, last-activity date/time range, and deadline or overdue state. Filament's filter panel and active indicators replace custom column-header dropdowns. Small tab counts satisfy the admissions-analytics need; no chart dashboard, applicant score, forecast, or ranking is created.

### 13.2 Applicant Record

Keep current state and decision-critical facts visible; the record requires:

1. State, owner, next action, and one primary action.
2. Private identity or LRN match warning.
3. Application scope and minimum applicant facts.
4. Preliminary evidence review.
5. Current and historical admission decisions.
6. One Registrar enrollment clearance after admission.
7. Accessible supporting activity, notification, and technical evidence.

Only one state-appropriate primary action appears. Secondary actions remain discoverable through task-appropriate controls. There are no bulk Admit, bulk enrollment-clearance, or bulk withdrawal actions.

### 13.3 Cycle and requirement setup

Contextual Registrar pages provide:

- Admission Cycle list and derived readiness.
- Draft cycle form.
- Published requirement-set review.
- Publish, extend, close, cancel, and publish-replacement actions with reason, authority, and audit evidence.

These pages are reached from Admissions. They are not a generic Settings area.

### 13.4 Responsive and accessible interaction

On mobile, tables collapse secondary columns into labelled row detail, the Wizard remains single-column, filters use the native panel, and secondary actions remain discoverable. Empty, loading, error, inaccessible, and stale-action states must name what happened and the safe next action. Keyboard order, visible focus, labels, status text, and error association must remain usable without color or pointer input alone.

## 14. Cross-Role Visibility and Communication

### 14.1 Role projections

- Applicant sees only their own application, safe feedback, readiness, and next actions.
- Registrar owns detailed review, decisions, cycle setup, and clearance results.
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

Mail failure never rolls back submission, decision, clearance, withdrawal, or readiness. TALA records delivery outcome, keeps the workspace authoritative, and provides an authorized resend path. Email contains the safe result and a link to TALA; private evidence and sensitive review detail remain in the authorized workspace.
## 15. Lifecycle, Mutation, and Technical Boundaries

Draft applications and unreferenced Draft Admission Cycles may be discarded only under the rules in Sections 5 and 7. Submitted applications, evidence versions, decisions, clearance results, withdrawals, and readiness history are never deleted; correction, reopening, cancellation, extension, or supersession preserves the same reference chain.

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
- Preliminary acceptance never appearing as admission, clearance or official enrollment.
- External paper handling staying outside TALA; missing, cleared, action-needed, superseded and stale Registrar-clearance results.
- Source-backed in-person/school-to-school instructions without a document-fulfillment workflow.
- No arbitrary core-credential waiver or fabricated clearance; external exceptions stay school-owned.
- Derived `ReadyForEnrollment` and automatic Clinic 4 visibility without Student creation.
- Applicant withdrawal, Registrar-recorded withdrawal, and authorized reopening.
- Mail success, failure, idempotency, and resend.
- Printable acknowledgment bound to the submitted Application and Requirement Set versions, with exact A4 content, historical labelling, monochrome behavior, and no false admission or official-enrollment language.
- Cross-role authorization and inaccessible-record behavior.
- Native date/time filters, active indicators, empty, loading, and error states.
- Keyboard, screen-reader, desktop, mobile, and print journeys.
- Server-side prevention of stale or out-of-order actions.

Realistic demonstration data must cover at least one adult first-year application, one under-18 first-year application, one ALS A&E or PEPT credential basis, one transferee, one scoped correction, one identity warning, one admitted applicant awaiting Registrar clearance, one ready applicant, one not-admitted application, and one withdrawal. Demonstration data is not policy authority.

### 16.1 Synthetic Demonstration Data

Applicant data is a bounded journey set linked to the coordinated BM, IT, and THM Programs; it is not an annual-volume forecast. Ready applicants hand off to the same six-cohort/47-Student institutional scenario without fabricating a Student before Clinic 4 finalization.

All identities use `example.test`; dates, references, credentials, and authorities are synthetic and stable.

| Reference | Applicant/case | Starting condition | Demonstrated path |
|---|---|---|---|
| `APP-2026-0001` | Alma Adult, `alma.adult@example.test` | Adult first-year SHS applicant in an open cycle | Five-step draft, first submission, acknowledgment, admission, Registrar clearance, `ReadyForEnrollment` |
| `APP-2026-0002` | Ulysses Minor, `ulysses.minor@example.test` | Under-18 first-year applicant | Minimum guardian contact, scoped evidence correction, resubmission |
| `APP-2026-0003` | Alyssa Equivalency, `alyssa.als@example.test` | ALS A&E credential basis | Versioned preliminary requirements and one Registrar clearance |
| `APP-2026-0004` | Tomas Transfer, `tomas.transfer@example.test` | Transferee with school-to-school record follow-up | Preliminary copy, external school checks pending, then Registrar clearance |
| `APP-2026-0005` | Inez Identity, `inez.identity@example.test` | Exact-name/birth-date candidate warning | Private `DifferentPerson` resolution before admission |
| `APP-2026-0006` | Adrian Awaiting, `adrian.awaiting@example.test` | Admitted with external school checks incomplete | `AwaitingRegistrarClearance`; not visible as ready in Clinic 4 |
| `APP-2026-0007` | Nadia Not Admitted, `nadia.result@example.test` | Complete review | `NotAdmitted`, then append-only superseding `Admitted` decision with authority |
| `APP-2026-0008` | Wendy Withdrawn, `wendy.withdrawn@example.test` | Submitted application | Self-withdrawal and authorized reopen using the same reference |
| `CYCLE-2026-A` | First-year/transferee cycle | Initially missing transferee requirement version, then publishable | Failed-first readiness, publication, close, authorized extension, cancellation evidence |

### 16.2 Browser Acceptance Walkthrough

| Persona / preconditions | Entry | Action | Visible evidence | Cross-role result | Output | Failure branch | Pass condition |
|---|---|---|---|---|---|---|---|
| Public/Applicant; `CYCLE-2026-A` closed then open | Public gateway | Inspect closed entry, then sign in and start after publication | Open/close dates, supported paths, guidance, privacy and support | Clinic 1 derives entry availability | One application for the cycle | Close-time race blocks first submission without losing safe draft facts | Closed entry never blocks existing Applicant sign-in |
| `APP-2026-0001` | Applicant Home | Complete the five Wizard steps, save draft, submit | Step status, field errors, evidence versions, declarations, stable reference | Registrar queue receives `Submitted` | A4 Application Acknowledgment bound to the submitted Application and Requirement Set versions | Invalid upload or stale submission preserves safe recovery; output failure creates no artifact | No Student, enrollment, or Study Plan record is created, and the acknowledgment claims neither admission nor enrollment |
| Registrar and `APP-2026-0002` | Admissions queue/Applicant Record | Review and issue one scoped correction; Applicant resubmits | Action-needed scope, owner, deadline, version/history | Queue moves from waiting back to needs review | Consolidated correction email | Delivery failure leaves workspace authoritative | Only named fields/evidence reopen |
| Registrar and `APP-2026-0002`; public window closed | Applicant Record / Applicant Home | Issue a correction before the correction boundary; let its due time pass; Applicant resubmits | Separate public close, correction boundary, overdue state, and preserved active scope | Public first submission remains closed while the existing correction journey continues | Consolidated correction evidence | A new request after the correction boundary is blocked until an authorized boundary extension | Public closing or an overdue due time never auto-rejects, withdraws, or disables the active correction |
| Registrar and `APP-2026-0005` | Applicant Record | Resolve identity warning and record decision | Masked identity evidence, resolution, authorized decision | Applicant sees safe decision only | Append-only decision evidence | Unresolved warning blocks `Admitted` | No merge or other-person disclosure occurs |
| Registrar and `APP-2026-0007` | Applicant Record | Record `NotAdmitted`, then authorized superseding `Admitted` | Previous and current decisions, reason, authority, safe explanations | Applicant history updates; no Student identity exists | Decision messages keyed to each decision | Stale action is rejected | Earlier decision remains immutable |
| Applicant/Registrar and `APP-2026-0004` | Requirements | Review selected preliminary copies; record one clearance after external checks | Separate digital-review and Registrar-clearance facts | Readiness uses current decision/clearance | Clearance successor history | Stale/service/mail failure cannot fabricate clearance | Uploaded-copy acceptance alone never makes the applicant ready |
| `APP-2026-0001` and Registrar | Applicant Home / Clinic 4 queue | Record matching current Registrar clearance | `ReadyForEnrollment`, secure next action, no enrollment promise | Same application appears automatically in Clinic 4 | Readiness email and printable history | Superseded/invalid clearance removes readiness and flags the affected registration case | No handover button or copied admissions record exists |
| `CYCLE-2026-A` owner | Admission Cycle setup | Fail readiness, correct sources, publish, extend, close/cancel | Failed-first source/owner/recovery details and immutable authority history | Public entry changes; existing review continues | Cycle publication evidence | Storage unavailable blocks publication | Only complete, authorized cycles publish |

### 16.3 Authority-hardening control matrix

| Action or record | Authorization and validation | Confirmation/audit | Limits, deadlines, deletion, and correction |
|---|---|---|---|
| Admission Cycle Draft/publish/extend/close/cancel | Registrar; unique scoped code, valid Term/programs/paths/requirement versions, opening before public closing, correction boundary at or after public closing, support/privacy/storage readiness | **Publish**, **Extend**, **Close**, or **Cancel admission cycle** shows both boundaries, affected paths/applicants, public-entry/correction result, reversibility, and reason/authority | Draft hard-delete only before publication and before any Application reference. Published cycles are never deleted; changes append authority. Public closing stops new/first submissions; the correction boundary governs new correction requests, not active correction, review, decision, or clearance work |
| Start/save/discard Application | Applicant or authorized assisted entry; exactly one Application per credential account and Cycle; identity/contact/education/program/path fields use baseline primitives and cross-field date/program checks | Draft save needs no confirmation; **Discard draft** states that the unsubmitted record and temporary evidence are removed | Only unsubmitted Draft may be discarded. Submitted Application, snapshots, and reviewed evidence are never deleted |
| Submit/withdraw/reopen | Applicant submits/withdraws own record; Registrar records offline withdrawal/reopening under authority | **Submit application** shows declarations, immutable snapshot, requirements, and Registrar review; **Withdraw/Reopen application** shows readiness effect and preserved history | Stale/invalid submit posts nothing and preserves safe input. Withdrawal before Clinic 4 registration; reopening uses same reference. No arbitrary submission/reopen count while the governing state/window permits |
| Correction request/resubmission | Registrar names exact fields/evidence, responsible party, consolidated instruction, and due date on or before the current correction boundary; Applicant edits only that scope | **Request correction** shows reopened items, due date, Applicant message/email, and no decision effect | Exactly one active correction request. New issuance after the boundary requires authorized extension. Missing its deadline marks overdue/action-needed, never rejected/withdrawn; an active request remains resubmittable and each version is preserved |
| Evidence version | Applicant/Registrar within the relevant requirement and state | File uses the common private-evidence primitive; multipage evidence is one PDF; requirement/source/version must match | Replacement creates a new version. Unsubmitted temporary evidence may be removed with Draft discard; submitted/reviewed evidence never deletes |
| Identity warning resolution | Registrar with bounded identity-review authority | LRN Provided requires exactly 12 digits as text; NotIssued/NotAvailable remains explicit. A verified duplicate against another credential blocks `Admitted`; exact normalized name+birth date is a private warning only | **Resolve identity warning** never exposes the other record to Applicant. No merge is automatic; stale evidence changes nothing |
| Admissions decision/supersession | Registrar; current submitted review and valid program/path; `Admitted` requires resolved identity warnings and acceptable preliminary evidence; `NotAdmitted` records its review basis and safe explanation | **Record admission decision** shows Applicant result, credential/readiness effects and email. An ordinary first decision records the Registrar's authority without requiring a separate reference. **Supersede decision** or explicitly exceptional approval requires a separate approval reference | One current decision; correction appends an authorized successor. Earlier decisions and messages remain immutable |
| Registrar enrollment clearance | Registrar; admitted application/current decision and submitted version; `Cleared` requires confirmed external prerequisites and identity resolution; `ActionNeeded` identifies pending external checks | **Record enrollment clearance** names the ready/not-ready effect, actor/time and any safe instruction; a changed result requires reason | Atomic stale/version guards; append-only successor; invalidation refreshes the same `ReadyApplicantProjection` and flags an active registration case |

The Application data contract requires requiredness, format, range, uniqueness, immutability, and cross-record validation for legal identity, birth date, contact, prior school, program/path, LRN when present, declarations, and evidence. Duplicate, stale, concurrent, inaccessible, and partial failures create no admissions mutation. `ReadyForEnrollment` remains derived and idempotent; it cannot create a Student, placement, assessment, or copied handoff record.
## 17. Technical, Operational, and External Assumptions

- Applicant demand is represented by bounded acceptance cases because supplied evidence establishes no annual forecast.
- Institution-specific requirements, Cycle dates, decision authority, external school checking and exceptional cases are Registrar-owned operational inputs; TALA records only the necessary clearance; no additional hidden workflow is inferred.
- The owning slice reconciles existing implementation and evidence with the intake and clearance contracts before acceptance. Automatic retention disposal remains outside the MVP under the product-wide boundary.
- TALA is designed for a normally recognized and authorized Philippine college.
- Registrar is the accountable admissions-decision owner.
- First-year and transferee paths are sufficient for the capstone baseline.
- The supplied TESDA handbook is contextual evidence only and does not establish Servitech college-admissions policy; exact admission requirements and dates remain Registrar-recorded operational inputs.
- The written browser walkthrough is complete authority. Live browser execution and screenshots remain later implementation-acceptance evidence and were not performed during documentation closure.
