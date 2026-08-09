# PRD 06 — Accounts, Official Outputs, Operations, and Assurance

## Authority and Standalone Status

> **Authority status — Standalone and ready for vertical-slice planning.** This PRD is the complete authority for Fee Plans, exact Assessments, continuous Term Accounts, Approved Coverage, payment evidence and posting, Clinic 4/5 clearance projections, non-tax outputs, contextual exports, System Health, governance, and the explicit no-automatic-disposal boundary. It is understandable without legacy finance, COR, Student Hub, or reports PRDs.

## 1. Purpose and Successful Journey

Clinic 6 gives an Applicant, Student, or alumnus one understandable term-account position and gives Accounting the minimum records needed to publish Fee Plans, verify payment evidence, resolve exceptions, and provide bounded payment-clearance projections to Clinics 4 and 5. It also gives the System Administrator locally evidenced health and read-only governance views without pretending that TALA controls provider infrastructure or proves institutional compliance.

The ordinary journey starts when Accounting publishes one authorized Fee Plan for a Program and Term. When an approved Special Term, reduced enrollment, Individually Advised selection, adjustment, or Course Drop cannot be represented by that fixed plan, Accounting instead records one exact `AuthorizedIndividualAssessment` calculated and authorized outside TALA. Clinic 4 creates or refreshes one continuous Term Account for a Registration Case, Clinic 6 publishes the amount required now, and the learner either has approved coverage, submits private external-payment evidence, or uses optional exact-due PayMongo checkout. A verified posting clears only the action-specific requirement it satisfies. The same account continues after official enrollment and later supports Student Finance, an Account Statement, Payment Acknowledgments, and a bounded Clinic 5 output-payment clearance.

The successful ending is:

- the applicable Fee Plan or individual-assessment authority and the Assessment source are versioned and reproducible;
- the learner sees the current due, next obligation, authoritative status, source, as-of time, and safe next action;
- Accounting can explain every verified posting, adjustment, reversal, and exception without editing history;
- Clinic 4 and Clinic 5 consume small read-only projections rather than a global hold;
- official-looking TALA outputs clearly state that they are not tax invoices;
- operational status distinguishes local evidence from facts TALA has not checked; and
- historical Student access remains read-only after completion or other lifecycle exit.

## 2. Ownership, Roles, and Product Boundary

| Concern | Office owner | Human or external step | TALA responsibility | Product classification |
|---|---|---|---|---|
| Fee and individual-assessment authority | Accounting | Approves fixed Program-and-Term charges or calculates and authorizes an eligible selection-specific result outside TALA | Versioned Fee Plan or exact authorized individual assessment with readiness evidence | Source record/manual-office result record |
| Enrollment payment requirement | Accounting, consumed by Registrar | Determines approved coverage or verifies payment source | Derived read-only projection from the Term Account | Generated read-only view |
| Bank, wallet, or cash verification | Accounting | Checks the actual external institution, bank, wallet, or cash record | Private evidence intake and recorded verification result | Manual-office result record |
| PayMongo confirmation | PayMongo plus Accounting exception ownership | Provider sends a signed event; Accounting handles mismatches or later corrections | Integration attempt, verified event, idempotent posting, and exception evidence | Integration input/output |
| Tax invoice or registered Accounting document | Accounting outside TALA | Issues the institution's BIR-compliant document through its authorized procedure | Shows a disclaimer and optional safe external reference only | External/manual decision |
| TOR or other official-output payment clearance | Accounting, consumed by Registrar | Confirms the external payment or authorized `NotRequired` result | Bounded request-specific projection | Manual-office result record |
| System operation | System Administrator and infrastructure custodian | Uses provider dashboards, host access, backup media, and restore procedures outside TALA | Displays only locally recorded application evidence | Generated read-only view |
| Retention, privacy requests, legal holds, and secure disposal | Institution and privacy authority | Governs these obligations outside TALA | Shows only that automatic disposal is not provided in this MVP | External responsibility |

Clinic 6 is a narrow Student-Term Account companion. It is not a general ledger, chart of accounts, double-entry accounting, cashiering, budgeting, procurement, payroll, collections, penalty, refund, or tax-invoicing product.

## 3. Evidence and Policy Basis

#### Applicable authority and reference result

- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) protects covered disadvantaged learners' examination access while preserving lawful institutional collection and credential remedies. TALA therefore exposes action-specific projections and never creates a global finance block on login, classes, or examinations.
- [BIR Revenue Regulations No. 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%207-2024.pdf) treat the invoice as the primary tax document and statements or acknowledgments as supplementary documents. TALA outputs are expressly non-tax; Accounting retains tax-document authority.
- [Presidential Decree No. 451](https://lawphil.net/statutes/presdecs/pd1974/pd_451_1974.html) recognizes institutionally approved tuition charged by term, school year, or unit. It does not establish one Servitech calculation formula. The supplied business records likewise show fixed-term charges, a Special Term per-unit example, and inconsistent totals, so TALA records approved exact results instead of inventing a universal rule.
- [UniFAST Tertiary Education Subsidy guidance](https://unifast.gov.ph/tes.html) confirms that authorized assistance may support full or partial tertiary-education cost, including in private HEIs, but does not establish Servitech eligibility or account-processing rules. TALA therefore records only an externally approved coverage effect.
- The [Data Privacy Act IRR](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/) and [NPC Circular No. 2023-06](https://privacy.gov.ph/wp-content/uploads/2024/05/2023-compendium-2.pdf) require proportionate security, controlled access, continuity, backup, restoration, remedial-time planning, and institutionally governed retention/disposal. They do not require TALA to provide an automatic disposal engine; the MVP keeps those compliance operations external.
- [PayMongo webhook documentation](https://docs.paymongo.com/reference/webhook-resource) confirms signed event delivery, retries, and the possibility that repeatedly failing delivery becomes unavailable. TALA therefore keeps browser return non-authoritative and provides the bounded Accounting reconciliation path without adding provider controls.
- Mature PeopleSoft account-summary, amount-due, activity, and payment-history patterns support the selected summary-first learner view. They do not justify adopting a full Student Financials or cashiering suite.
- The local Academico reference contains broad invoice/payment records and responsive list/detail patterns. Only presentation patterns are relevant; its invoicing domain does not define TALA policy.
#### Implementation-evidence boundary

Current finance, payment, output, operations, schema, and test surfaces remain implementation evidence only. A later journey-complete slice must reconcile every consumer against this authority. File presence, migrations, tests, reports, or demo data cannot restore FeeRule precedence, silent percentage fallbacks, global holds, cashiering, allocations, tax documents, refunds, penalties, collections, or generic reporting.
## 4. Authoritative Records, Fields, and Invariants

These are conceptual product records and projections. They are not approved table, class, route, or API names.

| Name or family | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| FeePlan | Freeze ordinary Program-and-Term charges and obligations | Accounting | Immutable version | Assessment and Clinic 4 readiness | Draft may be deleted before use; published versions remain distinct for reproducibility |
| AssessmentVersion | Freeze the exact assessed position and source basis | Accounting | Immutable version | Term Account, Clinic 4, SOA | `AssessmentBasis` is a controlled value; Authorized Individual Assessment is this record's bounded basis, not another subsystem |
| TermAccount and AccountEvent | Preserve one continuous learner/Registration Case/Term account and append-only effects | Accounting | Persisted authoritative record plus immutable events | Learner Finance, Clinic 4/5, outputs | Events may represent assessment, coverage, posting, adjustment, or reversal without becoming a general ledger |
| ApprovedCoverage | Record an externally approved account effect | Named external funding authority approves; Accounting records the exact authority reference and effect | External reference/result with immutable history | Account and Clinic 4 projection | Remains separate from payment; no scholarship-management workflow |
| PaymentEvidence | Preserve each learner claim and review result | Learner submits; Accounting reviews | Persisted authoritative record with immutable versions | Payment Exceptions and audit | Never constitutes payment by submission |
| PaymentAttempt | Track one provider checkout lifecycle | PayMongo/TALA integration | Persisted authoritative record | Payment posting, exception handling, learner status | Distinct for signed-event idempotency; no arbitrary-amount checkout |
| PaymentPosting and reversal | Record verified monetary effect and correction | Accounting or verified integration | Immutable version or event | Account, Clinic 4, acknowledgments | Original posting remains; reversal is append-only and not refund execution |
| Clearance decision and OfficialOutputPaymentClearance | Record and publish request-specific Accounting clearance | Accounting | Persisted authoritative decision plus derived projection | PRD 05 | No global hold or generic clearance engine |
| Current due, account status, EnrollmentPaymentRequirementProjection | Explain current position and enrollment requirement | PRD 06 | Derived projection/calculation | Learner, Accounting, PRD 04 | No stored balance fallback or payment allocation model |
| System Health and Governance & Audit projections | Present locally recorded operational/audit evidence | System Administrator reads; source systems record facts | Derived projection/calculation or UI-only state | System Administrator | No provider console, second audit store, or compliance-attestation workflow |
| SOA, Payment Acknowledgment, and contextual CSVs | Reproduce authorized account/payment views | PRD 06 | Official output | Learner or Accounting | Remain non-tax outputs; no report center or generic exporter |

#### Fee Plan — ordinary assessment authority

One `FeePlan` exists per Program and Term version and is the fixed ordinary assessment authority. It does not contain a rate, formula, inheritance rule, or selection-specific calculation.

| Field | Required meaning |
|---|---|
| Plan reference and version | Stable human-readable identity and positive version number |
| Program and Term | Exact scope; no global inheritance or precedence |
| Currency | `PHP` only for MVP |
| Authority reference and authority date | External institutional approval evidence |
| Charge lines | Code, label, category, nonnegative amount, and explicit display order |
| Total assessment | Exact sum of published charge lines |
| Enrollment-required amount | Exact first obligation that Clinic 4 must satisfy |
| Obligations | Ordered label, due date, amount, and purpose; their amounts sum to the total assessment |
| State | `Draft`, `Published`, or `Superseded` |
| Audit | Creator, publisher, times, predecessor/successor reference, and publication result |

There is always at least one obligation. If no later installment schedule exists, the enrollment obligation equals the total. If later obligations exist, their sum plus the enrollment obligation equals the total. A zero Fee Plan enrollment obligation requires explicit institutional `NoPaymentRequired` authority rather than an implicit default. Learner-specific approved coverage does not rewrite the Fee Plan amount; it satisfies the corresponding Term Account obligation and remains visible as the projection basis.

Drafts are editable. Publication requires complete authority evidence, one or more charge lines, reconciled totals, valid obligation dates and ordering, and no other published version for the same Program and Term. Published plans are immutable. A successor publication marks the previous version `Superseded`; it never edits the old plan.

#### Assessment basis

Every `AssessmentVersion` has exactly one `AssessmentBasis`:

| Basis | Allowed use | Required source |
|---|---|---|
| `PublishedFeePlan` | Ordinary registration represented exactly by the applicable fixed Program-and-Term plan | Immutable published Fee Plan version |
| `AuthorizedIndividualAssessment` | Approved Special Term; reduced enrollment whose approved charges differ from the ordinary plan; an Individually Advised proposal with selection-specific charges; or an authorized adjustment/Course Drop with an institutionally determined financial effect | Exact externally calculated Accounting result and authority evidence |

An `AuthorizedIndividualAssessment` contains the Registration Case and exact proposal/change version; Program and Term; confirmed course-and-unit snapshot; reason category; external Accounting authority reference and date; ordered nonnegative charge lines; reconciled total and obligations; enrollment-required amount; recorder and time; and predecessor/supersession evidence where applicable. It records no executable formula. Course and unit facts explain the external result but do not cause TALA to calculate a rate, discount, penalty, refund, credit, or forfeiture. No global rate, inheritance, precedence, percentage, or implicit default is permitted.

#### Term Account and assessment

One conceptual `TermAccount` is anchored to the same human subject, `RegistrationCase`, and Term. `Person` is only a cross-document continuity label; it does not introduce a universal Person master record, table, profile, sign-in identifier, or separate UI.

- It may exist before official enrollment, a StudentProfile, or a student number.
- After Clinic 4 finalizes first enrollment, the same account gains the official Student reference. It is never copied or replaced.
- One immutable `AssessmentVersion` records its Assessment basis; the exact Fee Plan version or authorized individual authority; the Registration Case and confirmed proposal/change version; the course-and-unit snapshot; charge lines, obligations, totals, creation authority, and as-of time.
- An authorized changed assessment creates a successor version and linked adjustment evidence; it does not rewrite the prior version.
- Account activity is append-only: assessment charges, verified payments, approved coverage, authorized adjustments, and reversals.
- The Account Statement can reproduce the position from those authoritative events without presenting a general-ledger model.

`Current due` is the sum of applicable obligations due through the as-of time, including the enrollment obligation while the Clinic 4 finance checkpoint is pending, minus verified payments and approved coverage applicable to this Term, floored at zero.

#### Approved Coverage

`ApprovedCoverage` is one append-only record of an externally approved funding effect. It is not a scholarship, sponsorship, subsidy, grant-application, or financial-accommodation workflow.

| Field | Required meaning |
|---|---|
| Coverage reference | Stable Accounting and learner-visible reference |
| Term Account and Assessment version | Exact account position to which the authority applies |
| Applicable obligation or obligations | Named current obligations; no prior-debt or cross-term allocation |
| Category | `Scholarship`, `Sponsorship`, `GovernmentSubsidy`, or `OtherAuthorizedFunding` |
| Safe provider/source label | Learner-visible source name without private eligibility data |
| External authority reference and date | Evidence that the result was approved outside TALA |
| Approved and applicable amount | Exact positive PHP amount, to two decimal places, that may affect the named obligations |
| Effective date | Date the external result takes effect |
| Recorder and time | Authorized Accounting actor and recording evidence |
| Safe description | Minimum learner-facing explanation |
| State and history | `Applied`, `Superseded`, or `Reversed`, with predecessor/successor or reversal authority |

There is no `Pending` coverage application in TALA. Eligibility, application, ranking, renewal, documentary review, fund release, and provider administration remain external. Recording requires a current Assessment, exact obligations, complete authority, and an applicable amount no greater than the remaining named obligations. Excess, conflicting, stale, unsupported, or unreconciled authority records no account effect and remains an Accounting source-resolution problem; TALA never silently caps it, creates a negative balance, transfers it to another term, or infers a refund.

Applied coverage does not rewrite a Fee Plan or Assessment. A successor Assessment requires explicit revalidation and either a current confirmation or successor coverage record. Supersession or reversal appends evidence and refreshes the account. A post-enrollment reversal may create a current amount due but never revokes official enrollment or creates a global hold. `NoPaymentRequired` remains an institutionally authorized Fee Plan basis and is not learner-specific coverage.

#### Payment evidence

| Field | Required meaning |
|---|---|
| Evidence reference | Stable learner-visible and staff-searchable reference |
| Term Account | Exact owner and Term context |
| Submitter and submitted time | Authenticated Applicant/Student identity and timestamp |
| Channel | Approved external channel such as bank or GCash |
| Claimed amount and paid time | Positive two-decimal PHP claim and Asia/Manila time no more than five minutes in the future; not yet authoritative payment |
| External reference | Learner-supplied reference, masked outside authorized review |
| Private screenshot | Access-controlled image with content metadata and checksum |
| State | `Submitted`, `Verified`, `Rejected`, or `Superseded` |
| Review result | Accounting actor, review time, verified amount or safe rejection reason, and external-check reference |
| Replacement link | Earlier evidence superseded by a resubmission |

Submission never posts payment. Accounting must check the real bank, wallet, or institutional source. An underpayment may post its actual verified amount and leave the projection `ActionNeeded`. A claim above the remaining Term obligation enters Payment Exceptions and cannot post until the discrepancy has an authorized result. Rejection preserves the submitted evidence; resubmission creates a successor.

#### Payment attempt, posting, and correction

`PaymentAttempt` states are `Pending`, `Cancelled`, `Expired`, `Failed`, `Confirmed`, and `ReviewRequired`.

One immutable verified `PaymentPosting` contains:

- posting reference, Term Account, amount, currency, channel, and posted time;
- masked external/provider references;
- verification basis: Accounting external check or exact signed PayMongo event;
- source evidence or attempt reference;
- idempotency key;
- applicable Assessment version and account effect;
- actor or automatic integration identity; and
- current state, including a linked reversal when applicable.

An authorized reversal appends a correcting event, records authority and reason, refreshes projections, and marks the related acknowledgment reversed or superseded. It never deletes the original posting. Refund execution, chargeback handling, and cash movement remain outside TALA; TALA records only the authorized external result needed to correct its account projection.

#### Clinic 4 enrollment projection

Clinic 6 publishes one read-only `EnrollmentPaymentRequirementProjection`:

| Field | Contract |
|---|---|
| Total assessment | Current authoritative Assessment version total |
| Amount required now | Enrollment obligation applicable to finalization |
| Verified payment applied | Posted verified payment usable for the enrollment obligation |
| Approved coverage applied | Current Applied coverage usable for the enrollment obligation |
| Remaining required now | Nonnegative difference |
| State | `Cleared`, `ActionNeeded`, or `Unavailable` |
| Satisfaction basis | `VerifiedPayment`, `ApprovedCoverage`, `Mixed`, `NoPaymentRequired`, or `None` |
| Assessment source | `PublishedFeePlan` or `AuthorizedIndividualAssessment`, exact proposal/change reference, Fee Plan or external authority reference, and Assessment version |
| Source and as-of | Assessment, separate posting and coverage references, and calculation time |
| Later obligation | Whether an amount remains due after enrollment |
| Account link | Authorized contextual Student Account destination |

Clinic 4 consumes only this projection. `Unavailable` means a required source is missing, invalid, stale, unreconciled, or unauthorized and blocks only the consuming registration action. It never means zero. `Cleared` means the current enrollment obligation is satisfied, not that the lifetime or Term balance is zero. A later missed obligation never reverses official enrollment or blocks login, classes, or examinations.

For a changed registration, a cost-increasing add or replacement requires a successor Assessment version and clearance of the newly required amount before Clinic 4 applies the change. A no-additional-cost change requires an authoritative current or successor Assessment confirming that result. An authorized removal or Course Drop may take academic effect while its financial effect is `Accounting review pending`; TALA does not infer a lower balance, refund, credit, penalty, or forfeiture. A later Accounting decision appends an authorized adjustment or successor Assessment and never rewrites the original Assessment or COR.

#### Clinic 5 official-output clearance

`OfficialOutputPaymentClearance` has exactly three states: `Cleared`, `NotRequired`, and `ActionNeeded`.

It is keyed to one official-output request or issuance reference and includes the learner, output type, required amount when supplied by Accounting policy, verified external-payment reference or `NotRequired` authority/reason, responsible Accounting actor, source, and as-of time. Clinic 5 reads it without editing it. Request intake, collection, CAV, signature, seal, claiming, courier, diploma, and ceremony work remain external.

## 5. Readiness and Cross-Module Handoffs

| Check | Authoritative source | Owner | Valid condition | Effect if missing or invalid | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Fee authority | Fee Plan authority reference/date | Accounting | Complete and applicable to the Program and Term | Hard blocker | Publish Fee Plan | Correct the Draft authority fields |
| Charge reconciliation | Fee Plan charge lines and total | Accounting | Nonnegative lines sum exactly to total | Hard blocker | Publish Fee Plan | Correct charge lines |
| Obligation reconciliation | Fee Plan obligations | Accounting | At least one row; ordered dates; rows sum to total; enrollment amount matches first obligation | Hard blocker | Publish Fee Plan | Correct obligations |
| Unique current plan | Published Fee Plans | Accounting | No competing Published version for Program and Term | Hard blocker | Publish Fee Plan | Supersede through the controlled successor action |
| Registration source | Clinic 4 Registration Case and proposal/selection source | Registrar | Same credential/Applicant-or-Student continuity, Program, Term, and current authoritative version | Hard blocker | Create/refresh Assessment | Correct Clinic 4 source |
| Ordinary assessment source | Published Fee Plan plus Registration Case | Accounting/Registrar | Plan is current and the confirmed registration is represented exactly by it | Hard blocker | Publish enrollment projection | Correct the Clinic 4 source or publish the applicable plan |
| Individual-assessment eligibility | Current Clinic 4 proposal/change plus reason category | Registrar/Accounting | Approved Special Term, reduced enrollment differing from the fixed plan, Individually Advised selection-specific charges, or authorized adjustment/Course Drop effect | Hard blocker for individual assessment | Record authorized individual assessment | Correct the case/change source or use the ordinary plan when it represents the case |
| Individual-assessment authority | External Accounting authority and exact result | Accounting | Authority/date, exact proposal/change, course/unit snapshot, nonnegative lines, total, obligations, and enrollment amount are complete and reconciled | Hard blocker | Publish projection or changed-registration impact | Correct the external result; never infer a formula or amount |
| Approved Coverage authority | External approval plus current Term Account, Assessment, and named obligations | Accounting | Category/source, authority/date, applicable amount, effective date, recorder, and target obligations are complete; amount does not exceed their remaining value | No coverage account effect; missing, stale, conflicting, or invalid authority produces `Unavailable`; a valid account with an unsatisfied current obligation remains `ActionNeeded` | Record Applied coverage; publish enrollment projection | Correct or externally reconcile the authority; never cap, reallocate, refund, or infer eligibility |
| Approved Coverage continuity | Current Assessment and existing coverage history | Accounting | Coverage still applies to the exact Assessment and obligations; any successor/reversal authority is current | Stale coverage is excluded and the consuming projection refreshes safely | Revalidate, supersede, or reverse coverage | Record explicit successor/reversal evidence; never reuse coverage silently |
| Changed-registration impact | Current or successor Assessment version | Accounting/Registrar | Exact change version and additional-required, no-additional-cost, or review-pending result are authoritative | Hard blocker for the consuming change when additional clearance or confirmation is required | Apply add/replacement, confirm no-cost change, or record review state | Record/refresh the authorized Assessment result |
| Payment claim | Private evidence metadata/file | Applicant/Student | Complete, readable, authorized, and within allowed file constraints | Hard blocker for submission only | Submit evidence | Correct fields or replace file |
| External payment verification | Real bank/wallet/institutional source | Accounting | Exact owner/context, amount, reference, and no unresolved conflict | Hard blocker for posting | Verify posting | Reject, resubmit, or route to exception |
| PayMongo local readiness | Environment configuration and local callback route | System Administrator | Required configuration is present without exposing secrets | Degraded integration | Start checkout | Restore configuration; manual evidence remains available |
| PayMongo event | Signed provider event and matching attempt | Integration/Accounting | Signature, account, amount, currency, reference, and idempotency all match | Exception, never automatic posting | Confirm PayMongo payment | Accounting reviews safe evidence |
| Output source | Current Term Account and authorized requester | Owning role | Source is available, current, and accessible | Hard blocker for output | Generate SOA/acknowledgment/export | Refresh source or correct authorization |
| Automatic retention disposal | MVP product boundary | Institution/privacy owner remains responsible externally | Not provided in this MVP | No disposal action exists | None inside TALA | Handle lawful retention schedules, privacy requests, legal holds, and secure disposal outside TALA |

Passed readiness rows remain collapsed. Every failed result names the owner, source, effect, and safe next action. Missing PayMongo disables only checkout; missing SMTP never reverses a payment posting.

## 6. States, Permissions, and Actions

| State or projection | Trigger/action | Actor and authorization | Guards | Resulting record/effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|
| Fee Plan `Draft` | Create or edit Draft | Accounting with Fee Plan manage authority | Program and Term exist | Saved Draft and audit evidence | No financial effect | Readiness may show incomplete |
| Fee Plan `Published` | Publish plan | Accounting publisher | All Fee Plan readiness checks pass | Immutable published version | Cannot be edited; later version supersedes | Clinic 4 may create Assessment |
| Fee Plan `Superseded` | Publish successor | Accounting publisher | Existing Published plan and valid successor | Predecessor linked to successor | Historical version retained | Existing Assessments keep their original source |
| Ordinary Assessment current | Create/refresh `PublishedFeePlan` assessment | Authorized Clinic 4/Accounting workflow | Published plan and current Registration Case represent the confirmed selection exactly | Immutable Assessment version and activity | Later change creates successor | Learner and Clinic 4 see plan basis and source version |
| Individual Assessment current | Record `AuthorizedIndividualAssessment` | Accounting with assessment authority | Eligible case; current proposal/change; complete external authority and reconciled exact result | Immutable Assessment version and activity | Later decision creates successor | Learner and Clinic 4 see individual basis and safe authority reference |
| Coverage `Applied` | Record externally approved account effect | Accounting with coverage-recording authority | Current account/Assessment/obligations; complete authority; exact applicable amount within remaining obligation | Append-only Approved Coverage activity and refreshed projection | Cannot be edited; correction uses successor or reversal | Clinic 4 sees separate coverage amount and `ApprovedCoverage` or `Mixed`; learner sees safe source/effect |
| Coverage `Superseded` | Record an authorized replacement | Accounting with coverage-recording authority | Existing Applied record and valid successor authority | Prior coverage ceases current effect; successor applies | History remains; no deletion or transfer | Account/SOA show both records; projection uses only current effect |
| Coverage `Reversed` | Record authorized revocation/correction | Accounting with reversal authority | Existing Applied record, authority, reason, current impact preview | Append-only reversal and refreshed due | Cannot reverse enrollment or create a global hold | Learner sees safe reversal/account effect; Clinic 4 does not undo finalization |
| Projection `Unavailable` | Required source becomes missing/stale | System-derived | Source/readiness failure | Read-only unavailable result | No silent fallback | Clinic 4 cannot finalize |
| Change `AdditionalClearanceRequired` | Record cost-increasing add/replacement result | Accounting and Registrar | Successor Assessment identifies exact new required amount | Change waits for bounded clearance | Assessment and original proposal remain immutable | Clinic 4 shows the newly required amount |
| Change `NoAdditionalAmount` | Confirm no-cost change | Accounting | Current or successor Assessment explicitly confirms no added amount | Authoritative change impact | No amount is inferred from course/unit differences | Clinic 4 may continue the change |
| Change `AccountingReviewPending` | Apply authorized removal/Course Drop academically | Registrar; Accounting owns financial review | Academic removal/drop authority exists; financial effect unresolved | Review-queue item; no automatic balance change | Later result appends adjustment/successor | COR may state review pending; Finance/SOA owns current position |
| Evidence `Submitted` | Submit private evidence | Owning Applicant/Student | Authorized account, complete fields, valid private file | Review-queue item | Submission retained | Learner sees `Under review` |
| Evidence `Rejected` | Reject evidence | Accounting reviewer | External check completed; safe reason supplied | Rejection result | Evidence retained; resubmission supersedes | Learner sees reason and resubmit action |
| Evidence `Verified` | Verify and post | Accounting reviewer | Exact external evidence; amount not above unresolved obligation | Immutable Payment Posting | Correction requires reversal | Projection and learner account refresh |
| Attempt `Pending` | Start exact-due checkout | Owning Applicant/Student | Positive current due; PayMongo ready; no matching active attempt | Provider attempt and redirect | Browser return cannot confirm | Learner sees pending status |
| Attempt `Confirmed` | Process exact valid signed paid webhook | Integration identity | Signature, amount, PHP, account, reference, and idempotency match | Automatic immutable Payment Posting | Duplicate event is no-op | Projection refresh and one email |
| Attempt `ReviewRequired` | Receive mismatch/recovery/refund/reversal evidence | System-derived | Evidence cannot safely auto-post | Payment Exception | No financial effect until authorized result | Learner sees safe review status only |
| Pending attempt without provider event | Reconcile through the verified-external-payment path | Accounting reviewer | Exact provider/external source checked; account, amount, currency, and reference match; no existing posting | Manual-basis immutable Payment Posting linked to the pending attempt/reference | A later valid event with the same external reference is an idempotent no-op, not a second posting/email | Projection refreshes once; learner sees verified result rather than provider controls |
| Posting reversed | Record authorized external correction | Accounting with reversal authority | Existing posting, authority, reason, impact preview | Append-only reversal and refreshed projections | Original remains visible | Ack becomes reversed/superseded |
| Output clearance `Cleared` | Verify request-specific payment | Accounting | Exact output request and external reference | Read-only clearance | Later correction appends evidence | Clinic 5 can continue its owning action |
| Output clearance `NotRequired` | Record authorized exemption | Accounting | Authority and reason supplied | Read-only clearance | Superseding correction is auditable | Clinic 5 can continue its owning action |
| Output clearance `ActionNeeded` | Required evidence absent/invalid | System-derived/Accounting | Output request exists | Read-only action-needed result | No global hold | Clinic 5 pauses only that output action |

Every consequential action is reauthorized server-side and revalidates current source versions. A stale page cannot publish, post, reverse, or clear a request.

## 7. Normal, Alternate, and Correction Journeys

#### Publish a Fee Plan

1. Accounting selects Program and Term and creates a Draft.
2. Accounting records institutional authority, ordered charge lines, and obligations.
3. Readiness explains incomplete authority, totals, dates, or competing plan.
4. Accounting reviews the exact publication impact and publishes.
5. TALA freezes the version and records the publisher and time.
6. A correction uses a successor Draft; existing Assessment versions continue to cite the original source.

No missing value is inferred from a global rule, per-unit rate, or percentage fallback.

#### Resolve the Assessment source

1. Clinic 4 supplies the current Registration Case and exact confirmed proposal or change version.
2. If the fixed published Fee Plan represents the registration exactly, TALA creates a `PublishedFeePlan` Assessment.
3. If the case is one of the four eligible exceptions, Accounting records the exact externally calculated `AuthorizedIndividualAssessment` and its authority evidence.
4. TALA validates the source, nonnegative lines, totals, obligations, and enrollment-required amount without executing a fee formula.
5. If neither source is valid, the enrollment or change projection is `Unavailable`; it never becomes zero or uses a fallback.
6. A later authorized decision creates a successor Assessment or adjustment. The original Assessment and COR snapshot remain unchanged.

#### Manual external-payment evidence

1. The Applicant or Student opens the owning Enrollment or Finance context.
2. TALA shows the exact Term Account, current due, responsible office, and safe instructions.
3. The learner supplies channel, claimed amount, paid time, external reference, and a private screenshot.
4. TALA validates and stores the submission without posting payment or emailing.
5. Accounting reviews the oldest/highest-risk exception first and checks the real external source.
6. Accounting verifies the actual amount, rejects with a safe reason, or leaves the item under review.
7. Verification posts once, refreshes projections, generates acknowledgment eligibility, and queues exactly one verified-payment email.

Unreadable, wrong-account, duplicate-reference, mismatched, over-obligation, and conflicting submissions never post automatically. A failed upload preserves entered non-file fields where safe and clearly requests a new file. A failed posting states whether nothing was posted and preserves the review evidence.

#### Record Approved Coverage

1. Accounting opens the current Term Account and selected Assessment version.
2. TALA shows the named obligations, payments already applied, current coverage, and remaining applicable amounts.
3. Accounting records the external category/source, authority reference/date, effective date, exact applicable amount, and safe description.
4. TALA revalidates the Assessment, obligations, existing account activity, and non-excess amount.
5. A valid result appends one `Applied` record and refreshes the enrollment/current-due projection without sending email.
6. A changed Assessment, replacement, or revocation requires an explicit successor or reversal; history remains visible.

Missing, stale, unsupported, conflicting, unreconciled, or excessive authority posts nothing and identifies the responsible Accounting source. TALA does not create a scholarship application, cap the value, issue money, infer eligibility, or turn the result into a payment.

#### Optional PayMongo checkout

1. The learner may start checkout only for the exact positive current due.
2. TALA records a local Pending attempt before redirecting.
3. Success or cancellation return is informational.
4. A signed provider event is persisted and validated.
5. Exact valid paid evidence posts idempotently and changes the attempt to Confirmed.
6. Duplicate delivery changes nothing.
7. Missing account, amount/currency/reference mismatch, recovery evidence, refund, chargeback, or reversal becomes `ReviewRequired`.
8. If no signed event arrives, the attempt remains `Pending`; browser return and elapsed time do not prove payment.
9. Accounting may check the actual provider/external source and use the existing verified-external-payment path. The posting retains the attempt and external reference, and any later matching signed event is a no-op with no duplicate email.

Provider unavailability disables only the checkout action. Manual evidence and all previously verified records remain available. Raw payloads, credentials, signatures, or secret values never render.

#### Later obligation

When a later obligation reaches its due date, current due and Student Finance refresh from the same account activity. The learner may pay or submit evidence, but the amount does not retroactively invalidate official enrollment, Student access, classes, examinations, released academic results, or COR versions. Only a specifically authorized future or official-output action may consume an approved bounded projection.

#### TOR clearance

Clinic 5 supplies the exact output request reference. Accounting records `Cleared`, `NotRequired`, or leaves `ActionNeeded`. The result affects only that request. Clinic 6 does not accept transcript requests, issue TOR, collect signatures/seals, arrange claiming, or decide CAV.

## 8. Email Authority

Clinic 6 owns exactly one email event.

| Trigger | Recipient | Safe contents | Source and idempotency | Delivery failure | Excluded notifications |
|---|---|---|---|---|---|
| Verified payment posted | Owning Applicant or Student email account | Amount, Term Account reference, posting date, secure portal link, and statement that the TALA acknowledgment is not a tax invoice | Payment Posting reference | Posting remains valid; failure is recorded for authorized resend/follow-up | Proof submission/rejection, Approved Coverage creation/supersession/reversal, reminder, checkout return, exception, TOR clearance, reversal, health, export, or routine activity |

## 9. Official Outputs, Exports, and Audit Evidence

#### Account Statement / Statement of Account

The authenticated, non-tax Account Statement contains:

- institution identity and copy context;
- person and Term Account reference;
- Program and Term;
- Assessment basis, Fee Plan or safe individual-assessment authority reference, and Assessment version;
- ordered charge lines;
- chronological verified payments, Approved Coverage with safe source/category/reference/state, adjustments, and reversals;
- obligation schedule;
- current due and remaining Term balance as of generation time;
- output reference and generation time; and
- a clear statement that it is not a BIR invoice or official tax document.

#### Payment Acknowledgment

One authenticated, non-tax acknowledgment is available per verified Payment Posting and contains payment/account references, amount, date, channel, masked external reference, verification basis, account effect, current state, generation reference/time, and the non-tax disclaimer. A reversed payment remains discoverable and its acknowledgment is visibly `Reversed` or `Superseded`.

#### Contextual CSV exports

| Export | Allowed columns |
|---|---|
| Account Status CSV | Account reference, safe person reference, Program, Term, assessment total, required-now amount, verified-payment-applied amount, approved-coverage-applied amount, current due, projection state, satisfaction basis, assessment basis, safe source version/authority reference, as-of time |
| Verified Payments CSV | Payment reference, account reference, safe person reference, Term, amount, channel, masked external reference, posted time, verification basis, current state |

Exports are contextual actions, not a Reports navigation page. Sensitive export requires purpose and records actor, role, normalized filters, purpose, row count, outcome, and time. CSV values are allowlisted, formula-safe, and stable for Excel import. Private proof paths, raw provider data, bank details, secrets, and internal notes are excluded.

#### Required audit evidence

Audit covers Fee Plan creation/publication/supersession; Assessment creation/supersession; Approved Coverage application/supersession/reversal; evidence submission/review; posting and idempotent duplicate outcome; exception resolution; adjustment/reversal; clearance result; output access; export purpose/outcome; email delivery result; integration result; and locally recorded backup/restore evidence. Audit views do not expose secrets, private eligibility material, or private screenshots.

## 10. System Health, Governance, Privacy, and Recovery

#### System Health

System Health is a read-only, evidence-first System Administrator page. It may show:

- SMTP configured state, last locally recorded success/failure, and queued/failed counts;
- PayMongo local configuration, recent verified webhook, pending attempts, and open exceptions;
- solver local configuration and last accepted/failure result;
- queue/background-processing pending and failed counts;
- application, database, and private-storage locally recorded checks; and
- automated backup-job results recorded by TALA or its approved local job boundary.

Every row shows `Available`, `Attention`, `Unavailable`, or `Unknown`, evidence, as-of time, and safe next step. `Unknown` never appears healthy. Provider dashboard status, Hostinger backup state, independent object-storage state, and ORICO physical-copy/custody state display `Not checked by TALA` unless verified external evidence is deliberately imported through a later approved authority.

The page does not execute provider controls, shell commands, restores, test payments, solver runs, or arbitrary email. A bounded test email may target only the authenticated administrator's own address and remains diagnostic evidence, not a service guarantee.

#### Governance & Audit

One read-only page uses four tabs:

1. Institutional Changes
2. System Events
3. Output and Export Access
4. Privacy and Retention Boundary

The page supports authorized search and fixed filters over safe audit fields. It is not a compliance attestation, policy editor, incident-response suite, or report designer.

#### Retention boundary

Automatic retention disposal is **Not provided in this MVP**. TALA has no retention-policy engine, disposal scheduler, disposal-candidate queue, legal-hold workflow, archive center, or generic restore workflow. Core identity, official enrollment, COR, grade, academic-history, lifecycle, completion, TOR, assessment, payment, coverage, and audit records have no ordinary UI delete action. Existing hard-delete rules remain only for never-submitted, never-published, unreferenced Drafts with no dependencies. Submitted evidence and authoritative history remain protected from ordinary deletion.

Institutional retention schedules, lawful privacy requests, legal holds, and secure disposal operations are external compliance responsibilities. System Health may state this product boundary, while Governance & Audit remains read-only and makes no claim that external institutional compliance is approved or deficient.

#### Deployment and recovery authority

- Selected MVP host direction: self-managed [Hostinger KVM 1 VPS](https://www.hostinger.com/ph/vps-hosting), subject to procurement-time specification and price recheck.
- Independent encrypted off-server object storage holds automated database and private-file backup generations; the provider's weekly VPS backup is supplemental only.
- Backup automation must create a recoverable point no older than six hours to meet the accepted RPO.
- Accepted RTO is eight hours from declared recovery start to restored priority service, subject to tested procedures and available infrastructure.
- The client-owned ORICO 9548U3 with independently encrypted rotation drives supplies an additional business-day offline copy, checksum verification, separate custody, and at least quarterly restore evidence.
- Recovery procedures, keys, provider access, physical custody, and restoration remain external operational responsibilities. TALA may display only locally recorded results.

## 11. UI Interaction Contract

The complete low-fidelity wireframes and alternatives live in the Clinic 6 section of `ui_surface_blueprint.md`. This section fixes product content and action ownership.

#### Primary navigation

Accounting has exactly two primary finance destinations:

1. **Fee Plans**
2. **Student Accounts**, with **Accounts**, **Payment Exceptions**, and **TOR Clearance** tabs

System Administrator has **System Health** and **Governance & Audit**. Students have one **Finance** destination. Applicant payment status is embedded in Clinic 4 Enrollment and is not a separate Applicant navigation item.

#### Page inventory

| Page | User and purpose | Required information order | Fields, filters, actions, and evidence |
|---|---|---|---|
| Fee Plans | Accounting publishes the Program-and-Term authority | Current published plan, action-needed Drafts, upcoming Terms | Term/Program/state/search; reference, version, total, authority, readiness; `New draft`, `Continue`, `View` |
| Fee Plan detail | Accounting prepares and publishes one version | Identity/authority, charge lines, obligations, readiness, history | Visible labels for authority/date; editable ordered rows only in Draft; `Save draft`, `Publish plan`; successor action on Published |
| Student Accounts — Accounts | Accounting finds the next account decision, including `Assessment required` | Status, learner, account, Program/Term, assessment basis, required/payment/coverage/due, satisfaction basis, next action | Term/state/Program/search; contextual Account Status CSV; no separate assessment or coverage resource |
| Student Accounts — Payment Exceptions | Accounting resolves manual/provider evidence safely | Risk/reason, learner/account, amount, channel/source, age, next action | Source/state/reason/date filters; `Review`; no raw payload columns |
| Payment Exception detail | Accounting records the external-check result | Reason and current due, safe evidence, review form, history | Private preview; actual verified amount; safe reason; `Reject evidence`, `Return for review`, or exact `Verify` consequence |
| Student Accounts — TOR Clearance | Accounting records a request-specific result | Action-needed request, learner, output ref, due/reference, source | State/date/search; `Record cleared` or `Record not required`; no generic hold action |
| Student Account detail | Accounting explains one Term position or records an eligible exact individual or coverage result | Current status and due, assessment basis/source, separate payment/coverage amounts, satisfaction basis, next obligation/action, projection, then evidence tabs | Assessment/Payments/Coverage/Evidence/Outputs/Audit; contextual `Record authorized individual assessment`, `Record approved coverage`, record verified external payment, generate SOA, contextual export, authorized reversal |
| Authorized individual assessment form | Accounting records an externally calculated exact result; it is not a calculator | Registration/change version and course/unit evidence, reason/authority, exact charge lines/obligations, totals, impact preview | Reconciled nonnegative rows, enrollment amount, predecessor; no rate/formula builder |
| Approved Coverage form | Accounting records only an externally approved Term Account effect | Current Assessment/obligations, category/source, authority/date, exact amount, effective date, safe description, impact preview | `Apply` only after current non-excess reconciliation; successor/reversal from history; no eligibility, application, renewal, disbursement, or accommodation workflow |
| Enrollment payment requirement | Applicant/Student completes Clinic 4 finance checkpoint | Required now, state, assessment basis/source, account ref, submitted evidence, next action | Private upload fields; view/replace submission; no finance navigation duplication |
| Student Finance | Student or alumnus understands current/historical account | Current due/status, assessment basis/source, next obligation, actions, recent activity, outputs | Term selector; exact checkout, submit evidence, download SOA/ack; alumni read-only |
| System Health | System Administrator distinguishes evidence from unknowns | Evidence time, service rows, safe next step | Status/filter/search; local refresh; optional self-test email only |
| Governance & Audit | Authorized System Administrator investigates high-value evidence | Selected tab, filters, newest events, automatic-disposal boundary | Actor/type/date/search; read-only detail; no manual attestation or compliance verdict |
| Account Statement | Owner/authorized role reads or prints account position | Identity/context, charge/activity tables, obligations, totals, disclaimer | Authenticated browser output; print/save-as-PDF and access log |
| Payment Acknowledgment | Owner/authorized role reads or prints verified posting | Posting summary, verification basis, account effect, state, disclaimer | Authenticated browser output; print/save-as-PDF and access log |

#### Deterministic ordering

- Fee Plans: current Published, action-needed Drafts, upcoming Term opening, then Program and version.
- Accounts: `Assessment required`, `ActionNeeded`/under review, nearest deadline, oldest relevant activity, then person/account reference.
- Payment Exceptions: blocking/security mismatch, oldest submission, then reference.
- TOR Clearance: `ActionNeeded`, nearest required date, request date, then request reference.
- Account activity and learner history: newest authoritative event first.
- SOA activity: chronological ascending.
- Audit/system events: newest first, then severity/status and reference.

#### Page-specific states

| Surface | Empty | Filtered empty | Loading/stale | Inaccessible | Failed action |
|---|---|---|---|---|---|
| Fee Plans | “No Fee Plans yet. Create a Draft for an approved Program and Term.” | Name active filters and offer `Clear filters` | Preserve list structure; stale Draft requires refresh before publish | No fee or authority details disclosed | Preserve Draft; name the readiness item to correct |
| Accounts | “No Term Accounts are available for this scope.” | Name query/filters and clear them | Show last as-of time; disable assessment/posting/export when source is stale | Generic unavailable page | State whether no assessment/posting occurred and preserve safe form data |
| Authorized individual assessment | Explain that the action is available only for an eligible current case | Not applicable | Preserve entered rows; stale registration/change blocks recording | Reveal no account, course, or authority detail | No Assessment is created; retain safe entered data and identify the failed readiness check |
| Approved Coverage | Explain that no externally approved coverage applies to this account | Not applicable | Preserve safe entered fields; stale Assessment/obligation disables Apply | Reveal no account, eligibility, provider, or authority detail | No coverage effect is recorded; name the missing, conflicting, stale, unreconciled, or excessive source |
| Payment Exceptions | “No payment evidence needs review.” | Name filters and clear them | Preserve queue position; revalidate before result | No evidence metadata or screenshot disclosed | Keep item under review and explain the next safe action |
| TOR Clearance | “No output clearances need Accounting action.” | Name filters and clear them | Disable result until request/source refreshes | No request or learner details disclosed | Existing result remains unchanged |
| Student Finance/Enrollment | Explain when no applicable Term Account exists | Applicable only to Term selection | Show as-of; `Unavailable` identifies missing, stale, unreconciled, or unauthorized assessment without a fallback | No account existence disclosure | Submission/checkout status states exactly what was and was not recorded |
| System Health | “No local evidence has been recorded.” | Name filters and clear them | Show capture time and retain prior evidence as stale, never healthy | Generic unavailable page | Failed refresh does not overwrite prior evidence |
| Governance & Audit | Explain that no authorized events match the tab | Name filters and clear them | Preserve last capture; read-only | No event existence disclosure | Search/export failure changes no evidence |
| Outputs | No eligible source means no output | Not applicable | Stale source blocks generation | No output reference disclosed | No partial or official-looking artifact is produced |

## 12. Responsive, Print, Accessibility, and Keyboard Behavior

- Applicant and Student surfaces qualify at 360 and 390 CSS-pixel widths; dense staff work is desktop-first at 1366 CSS pixels while queues and read-only details remain usable at narrower sizes.
- Summary/status/next action precede accounting activity. Tables become labeled cards where practical; dense audit tables use bounded horizontal scrolling with persistent labels.
- Layout grouping uses space and shared edges. Numeric values align to the trailing edge with tabular figures. Controls remain visually distinct from evidence.
- Native landmarks, headings, forms, buttons, links, tables, and dialogs are required. Navigation visibility is never authorization.
- Tabs use `tablist`, `tab`, and `tabpanel` semantics with arrow-key movement and correct selected state.
- Visible focus, logical focus order, a skip link, no color-only meaning, 4.5:1 normal-text contrast, 3:1 component/focus contrast, and usable 200% zoom/reflow are required.
- Errors remain adjacent to labeled fields, use actionable language, set invalid state, enter the error summary, and move focus to the first invalid field.
- Dialogs have descriptive names, focus containment, safe Escape behavior, and focus return. Consequential confirmation buttons repeat the exact effect.
- Targets meet the WCAG 2.2 24×24 CSS-pixel minimum; learner primary actions prefer 44×44.
- No action depends on drag, hover, background color, or a pointer. Private evidence preview has an accessible name and never appears as decorative public media.
- Print views are monochrome-safe, repeat table headings, avoid clipped rows, retain disclaimers, and do not depend on navigation or background color.

## 13. Accepted Layout Decisions

| Area | Selected | Rejected alternatives | Reason |
|---|---|---|---|
| Account/payment status | Summary-first status page | Ledger-first account page; payment Wizard | Learners first need due/status/next action, not accounting mechanics or unnecessary steps |
| Accounting navigation | Fee Plans plus one tabbed Student Accounts workbench | Peer Resources; dashboard/report hub | Preserves the two office tasks and contextual evidence without exposing schema inventory |
| System assurance | Locally evidenced status with `Not checked by TALA` | Provider operations console; manual attestation checklist | Prevents unsupported claims and dangerous infrastructure controls |

## 14. Coordinated Synthetic Data

Finance scenarios reuse the coordinated BM, IT, and THM Students, Terms, Registration Cases, Assessment sources, and official-output requests. Account references remain stable across Clinics 4–6; PRD 06 never invents academic or enrollment facts.

All examples use fake references and `example.test` identities.

| Reference | Scenario |
|---|---|
| `FP-IT-2026-T1-v1` | Published ₱48,000 plan with ₱12,000 enrollment obligation and two later ₱18,000 obligations |
| `FP-BM-2026-T1-d2` | Incomplete Draft blocked by missing authority and unreconciled obligations |
| `ACT-260001` / Ana Reyes | Applicant without StudentProfile; manual GCash evidence under review then verified |
| `ACT-260008` / Lea Cruz | Exact PayMongo success and duplicate webhook delivery |
| `ACT-260014` / Miguel Santos | Verified underpayment with remaining current due |
| `ACT-260021` / Jo Santos | PayMongo amount mismatch routed to exception |
| `ACT-260027` / Pia Lim | Rejected evidence and a superseding resubmission |
| `ACT-260033` / Noel Garcia | Applied learner-specific scholarship coverage satisfying a nonzero enrollment obligation; successor and reversal history remain available |
| `ACT-260034` / Rosa Dela Cruz | Published Fee Plan with institutionally authorized zero enrollment obligation and `NoPaymentRequired` basis |
| `ACT-260039` / Kai Mendoza | Exact checkout with no webhook, verified external reconciliation, then a late matching event that is a no-op |
| `ACT-260041` / Eva Ramos | Later missed obligation while official enrollment remains valid |
| `ACT-260045` / Mira Flores | Reduced Individually Advised registration with an exact externally authorized individual assessment |
| `ACT-2026-ST-001` | `REG-2026-ST-001` under `TERM-2026-ST`: PHP 6,000 exact individual assessment for the confirmed Special Term class snapshot, PHP 3,000 required now, `COV-2026-ST-001` PHP 2,000 government subsidy, `PAY-2026-ST-001` PHP 1,000 verified payment, and `Mixed` clearance |
| `ACT-260047` / Sam Torres | Changed-registration branches for additional clearance, authoritative no-additional-cost confirmation, and Course Drop Accounting review pending |
| `TOR-260003/4/5` | `Cleared`, `NotRequired`, and `ActionNeeded` output clearances |
| `PAY-260009` | Reversed payment and superseded acknowledgment |
| Alumni example | Historical read-only SOAs and acknowledgments with no payment actions |
| Health example | Queue failure, successful local backup job, automatic disposal not provided, external backups not checked |

No real student number, account number, wallet reference, proof image, provider identifier, or production credential is used.

## 15. Browser Acceptance Walkthrough

| Step | Persona and entry | Action and visible evidence | Cross-role/output result | Failure branch and pass condition |
|---|---|---|---|---|
| 1 | Accounting, Fee Plans | Open incomplete BM Draft | Readiness names missing authority/reconciliation | Publish remains unavailable; no fallback |
| 2 | Accounting, valid IT Draft | Publish after impact review | Immutable plan/version/audit visible | Competing/stale plan is rejected |
| 3 | Mira and Accounting, reduced Individually Advised case | Open `Assessment required` and record the exact authorized individual result | Basis, proposal version, authority, lines, obligations, and impact are visible | Missing/stale/unreconciled authority stays `Unavailable`; no formula or fallback appears |
| 4 | Continuing Student, `REG-2026-ST-001` | Open Enrollment before the individual result exists | `TERM-2026-ST`, exact proposal/classes, course/unit evidence, and responsible Accounting action are visible | Finalization remains blocked until `ACT-2026-ST-001` is recorded |
| 5 | Accounting, `ACT-2026-ST-001` | Attempt excessive/stale coverage, then record `COV-2026-ST-001` and verify `PAY-2026-ST-001` | Failed coverage posts nothing; valid subsidy and payment remain separate with `Mixed` basis | Clinic 4 receives PHP 3,000 cleared without a scholarship workflow, silent cap, or email |
| 6 | Sam, changed registration | Exercise cost increase, no-additional-cost, and Course Drop branches | Successor clearance, authoritative confirmation, and Accounting review pending remain distinct | No automatic refund, credit, penalty, forfeiture, or COR rewrite occurs |
| 7 | Ana, Clinic 4 Enrollment | Open payment requirement before Student creation | Same human-subject/RegistrationCase account, assessment basis, and due visible | Missing assessment shows `Unavailable` |
| 8 | Ana, embedded upload | Submit private GCash evidence | `Under review`; no posting/email | Failed upload preserves safe fields and requests replacement |
| 9 | Accounting, Payment Exceptions | Verify actual external source | One posting, projection refresh, one email | Mismatch/rejection posts nothing |
| 10 | Registrar/Student | Finalize Clinic 4 enrollment | Same account gains Student identity; COR snapshot unchanged | Later due or coverage reversal does not reverse enrollment |
| 11 | Student Finance | Review status, SOA, acknowledgment | Same assessment basis/source versions, separate payment/coverage activity, and as-of evidence | Output failure produces no partial artifact |
| 12 | Lea, exact checkout | Return from browser, then receive signed event | Pending until webhook; one confirmed posting | Duplicate event is idempotent |
| 13 | Kai, exact checkout with missing webhook | Remain pending; Accounting checks the real provider source and records verified external payment | One posting linked to attempt/reference and one email | A later matching signed event creates no duplicate posting or email |
| 14 | Jo and Pia | Review mismatch; reject/resubmit evidence | Exceptions and supersession retained | Raw provider/private data remains hidden |
| 15 | Eva | Reach later due date | Finance becomes action-needed | Login/classes/exams/enrollment remain available |
| 16 | Accounting/Registrar | Resolve three TOR examples | Clinic 5 sees only request-specific projection | No global hold or TOR workflow appears |
| 17 | Accounting | Generate two contextual CSVs | Purpose and output-access audit recorded | Disallowed fields never export |
| 18 | System Administrator | Open System Health | Local evidence differs from `Not checked by TALA` | Unknown never appears green/available |
| 19 | System Administrator | Open Governance & Audit | **Automatic retention disposal: Not provided in this MVP** | No attestation, policy-approval status, or disposal action appears |
| 20 | Alumnus | Open historical Finance | Read-only outputs/history | No checkout, upload, or mutation action appears |

Documentation closure does not execute this walkthrough. Later implementation acceptance must perform it in a real browser with synthetic data and verify keyboard, 360/390 mobile, 1366 desktop, and print behavior.

## 16. Lifecycle, Validation, Confirmation, and Retry Rules

| Action or record | Actor and authorization | Validation and readiness | Confirmation and audit | Lifecycle, duplicate, and failure behavior |
|---|---|---|---|---|
| Fee Plan Draft/publish/supersede | Accounting; current Program/Term and external authority | Scoped code/reference unique; ordered unique charge codes; nonnegative two-decimal PHP; at least one obligation; valid dates/order; charge and obligation sums reconcile; exact enrollment requirement; one published version per scope | **Publish/Supersede Fee Plan** shows version, charges, obligations, affected Cases/accounts, immutability, and source authority | Draft hard-delete only before publication/reference. Published plan never edits/deletes; successor marks prior version superseded. Stale/concurrent publish posts nothing |
| Authorized Individual Assessment | Accounting records externally calculated result for only the four authorized exception categories | Current Registration Case/proposal/change, exact course/unit snapshot, reason, authority/date, ordered nonnegative lines, reconciled total/obligations, enrollment amount, predecessor when applicable | **Record authorized individual assessment** shows account, selection version, exact amounts, Clinic 4 effect, and that no formula runs | Never edited/deleted. Missing/stale/unreconciled source makes projection `Unavailable`; correction creates successor |
| Approved Coverage apply/supersede/reverse | Accounting records external result | Current Assessment/obligation, category/source, authority/date, positive applicable amount no greater than remaining named obligation, effective date, safe description | Named confirmation shows coverage—not payment—effect, remaining requirement/later obligations, and append-only consequence | Never edits/deletes. Excess/conflict/stale authority posts nothing; changed Assessment requires revalidation/successor. Reversal may create due but never revokes enrollment or creates hold |
| Manual payment evidence submit/replace | Applicant/Student for own Term Account; Accounting reviews | Common private file; positive claimed PHP amount; paid time no more than five minutes in the future; normalized external reference using the shared code primitive; current account/channel | Submission needs no critical confirmation because it posts no payment; replacement identifies superseded evidence | A matching account/channel/reference/amount is a possible duplicate routed to review without posting. Every evidence attempt remains; no arbitrary resubmission cap while account action is available |
| Verify/reject manual evidence | Accounting after checking real external source | Current evidence, account/Assessment, actual verified amount, external-check reference, safe rejection reason | **Verify payment** shows actual amount/account/projection/email/output effect; **Reject evidence** shows safe learner reason and no posting | Atomic/idempotent posting. Underpayment may post actual amount; excess/mismatch stays exception. Rejection never deletes evidence; stale/concurrent review posts nothing |
| PayMongo attempt/webhook/reconciliation | Learner starts exact current-due checkout; integration posts only a valid signed event | PHP exact due; current Assessment/account; one matching pending account/amount attempt; signed/idempotent provider event; matching account/reference/currency/amount | Browser return is informational. Automatic success uses immutable provider event key; exceptions/reversals use named Staff confirmation | Provider-reported Cancelled/Expired/Failed permits a new attempt. Browser elapsed time alone never fabricates expiry. Duplicate delivery creates no duplicate posting/email; mismatch enters exception |
| Payment/coverage reversal | Accounting records authorized external correction | Current posting/effect, authority, reason, amount/account, external result | **Record reversal** shows due/projection, acknowledgment supersession, and no refund/cash movement | Append-only; original remains. No lifetime cap when current authority exists. Refund/chargeback execution stays external |
| TOR clearance | Accounting | Exact Clinic 5 request/issuance reference, required amount/reference, `Cleared` or authority-backed `NotRequired` basis | Named confirmation shows only request-specific effect | No global hold; correction appends result. Clinic 5 consumes read-only projection |
| SOA/acknowledgment/CSV | Authorized learner/Accounting purpose as defined | Current immutable source; scoped rows; purpose for sensitive export; formula-safe cell treatment; no private paths/payloads/notes | **Generate/Export** shows scope, row count estimate/purpose, official/non-tax status, and access evidence | Failure creates no partial/official-looking artifact. Outputs version/supersession remain; exports audit actor, role, filters, row count, completion, time |
| System Health/Governance | System Administrator read-only | Locally evidenced fact and as-of time only | No consequential provider/control confirmation exists | Missing evidence is `Unknown`/`Not checked by TALA`, never healthy. No arbitrary-recipient email, test charge, solver run, backup, restore, command, or attestation action |

Authorized Individual Assessments, account events, coverage, payment postings, reversals, clearance results, export access, and generated output history are never edited or deleted through ordinary UI. Financial mutations are transactional and idempotent, payment and coverage remain separate effects, and an inaccessible response reveals no person, account, proof, provider, or balance fact. Automatic retention disposal is outside the MVP; the institution remains responsible for lawful privacy governance and externally controlled disposal.

## 17. Explicit Exclusions

Clinic 6 does not authorize:

- a public HTTP API or physical schema;
- generic fee precedence, automated unit-based calculation, implicit defaults, penalties, refunds, or prior-debt allocation; course/unit evidence may only accompany an exact externally calculated authorized individual result;
- scholarship, sponsorship, subsidy, grant, or financial-accommodation application/eligibility/ranking/renewal/disbursement management; TALA records only externally approved account effects;
- general ledger, double-entry accounting, cashiering, collections campaigns, budgeting, procurement, or payroll;
- Billing Slip, Official Receipt, BIR invoice, OR number, or tax-document substitution;
- global holds or finance-controlled login, class, examination, grade, or official-enrollment reversal;
- arbitrary payment amount checkout, browser-return confirmation, unsigned auto-posting, or raw provider payload UI;
- a Reports navigation destination, BI/dashboard product, pivot/chart builder, or legacy 27-report catalog;
- provider/server controls, shell commands, restore buttons, test payments, test solver runs, or manual assurance attestations;
- retention-policy configuration, disposal queues/scheduling, legal-hold workflows, archive centers, generic restoration, or automatic deletion;
- changing Clinic 4 COR into a live account statement; or
- changing Clinic 5 into a document-request, payment-collection, CAV, signing, sealing, or delivery system.

## 18. External and Technical Boundaries

No Clinic 6 product decision remains gated. Automatic retention disposal is intentionally outside the MVP, while lawful retention schedules, privacy requests, legal holds, and secure disposal remain external institutional responsibilities. Accounting tax documents, cash movement, provider administration, refunds, and collection activity likewise remain external.

This PRD names conceptual product records and projections, not physical tables, routes, APIs, migrations, or task order. A later journey-complete slice must reconcile existing finance, payment, output, health, governance, authorization, UI, and test surfaces against this authority while preserving provider secrets and producer-owned version boundaries.
