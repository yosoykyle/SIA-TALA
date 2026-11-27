# PRD 06 — Accounts, Official Outputs, Operations, and Assurance

## Authority

This PRD owns Fee Plans, exact Assessments, continuous Term Accounts, Approved Coverage, payment evidence and posting, Clinic 4/5 clearance projections, non-tax outputs, contextual exports, System Health and governance. Section 10 defines the institution's ownership of lawful retention and disposal decisions.

## 1. Purpose and Successful Journey

Clinic 6 gives an Applicant, Student, or alumnus one understandable term-account position and gives Accounting the minimum records needed to configure payment channels, publish Fee Plans, verify payment evidence, record onsite/cashier payments and Official Receipt (OR) mappings, resolve exceptions, and provide bounded payment-clearance projections to Clinics 4 and 5. It also gives the System Administrator locally evidenced health and read-only governance views without pretending that TALA controls infrastructure hosting or proves institutional compliance.

The ordinary journey starts when Accounting publishes one authorized Fee Plan for a Program and Term. When an approved Special Term, reduced enrollment, Individually Advised selection, adjustment, or Course Drop cannot be represented by that fixed plan, Accounting instead records one exact `AuthorizedIndividualAssessment` calculated and authorized outside TALA. Clinic 4 creates or refreshes one continuous Term Account for a Registration Case, Clinic 6 publishes the amount required now, and the student or applicant either has approved coverage, submits private receipt evidence (`Submit receipt`) when a configured digital channel (`Bank Transfer` or `E-Wallets`) is enabled, or pays onsite at the Campus Cashier where Accounting records the payment directly (no receipt upload UI when onsite-only, and no upload needed) for both enrolled Students and pre-student Applicants. A verified or onsite posting clears only the action-specific requirement it satisfies, and Accounting can record or map the physical Official Receipt (OR) number inline on verified posted payments missing an OR. The same account continues after official enrollment and later supports Student Finance, an Account Statement, Payment Acknowledgments, and a bounded Clinic 5 output-payment clearance.

The successful ending is:

- the applicable Fee Plan or individual-assessment authority and the Assessment source are versioned and reproducible;
- the student or applicant sees the current due, next obligation, authoritative status, source, as-of time, and safe next action;
- Accounting can explain every verified posting, adjustment, reversal, and exception without editing history;
- Clinic 4 and Clinic 5 consume small read-only projections rather than a global hold;
- official-looking TALA outputs clearly state that they are not tax invoices;
- operational status distinguishes local evidence from facts TALA has not checked; and
- historical Student access remains read-only after completion or other lifecycle exit.

## 2. Ownership, Roles, and Product Boundary

| Concern | Office owner | Human or external step | TALA responsibility | Product classification |
|---|---|---|---|---|
| Fee and individual-assessment authority | Accounting | Approves fixed Program-and-Term charges or calculates and authorizes an eligible selection-specific result outside TALA | Versioned Fee Plan or exact authorized individual assessment with readiness evidence | Source record/manual-office result record |
| Enrollment payment requirement | Accounting, consumed by Clinic 4 auto-finalization and Registrar | Determines approved coverage or verifies payment source | Derived read-only projection from the Term Account; post-commit clearance triggers Clinic 4 System Auto-Finalization in an isolated transaction | Generated read-only view |
| Bank, wallet, or cash verification and onsite recording | Accounting | Checks the actual external bank/e-wallet record or collects onsite cash at the Campus Cashier and issues the physical OR | Private submitted-receipt intake (digital channels only), direct onsite/cashier payment posting (for enrolled Students and pre-student Applicants), recorded verification result, and inline Official Receipt (OR) mapping | Manual-office result record |
| Payment channel settings | Accounting | Approves multi-account institutional Bank Transfer details, multi-account E-Wallets details (replacing standalone GCash), and Campus Cashier location/hours/notes | Versioned **Payment channels** settings page under **Accounting** navigation (`SystemSetting` `'payment_channels'` v2, with no generic settings table) projected as student/applicant **"How to pay"** instructions | Configuration record |
| Tax invoice or registered Accounting document | Accounting outside TALA | Issues the institution's BIR-compliant document through its authorized procedure | Shows a disclaimer and optional safe external reference only | External/manual decision |
| Official-output restriction | Accounting, consumed by Registrar | Records an actual restriction with office, reason, blocked action, source and history | Read-only OUTPUT-scoped restriction | No per-request payment-clearance approval workflow |
| System operation | System Administrator and infrastructure custodian | Uses provider dashboards, host access, backup media, and restore procedures outside TALA | Displays only locally recorded application evidence | Generated read-only view |
| Retention, privacy requests, legal holds, and secure disposal | Institution and privacy authority | Governs these obligations outside TALA | Shows only that automatic disposal is not provided in this MVP | External responsibility |

Clinic 6 is a narrow Student-Term Account companion. It is not a general ledger, chart of accounts, double-entry accounting, cashiering, budgeting, procurement, payroll, collections, penalty, refund, or tax-invoicing product.

## 3. Evidence and Policy Basis

#### Applicable authority and reference result

- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) protects covered disadvantaged students' examination access while preserving lawful institutional collection and credential remedies. TALA therefore exposes action-specific projections and never creates a global finance block on login, classes, or examinations.
- [BIR Revenue Regulations No. 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%207-2024.pdf) treat the invoice as the primary tax document and statements or acknowledgments as supplementary documents. TALA outputs are expressly non-tax; Accounting retains tax-document authority.
- [Presidential Decree No. 451](https://lawphil.net/statutes/presdecs/pd1974/pd_451_1974.html) recognizes institutionally approved tuition charged by term, school year, or unit. It does not establish one Servitech calculation formula. The supplied business records likewise show fixed-term charges, a Special Term per-unit example, and inconsistent totals, so TALA records approved exact results instead of inventing a universal rule.
- [UniFAST Tertiary Education Subsidy guidance](https://unifast.gov.ph/tes.html) confirms that authorized assistance may support full or partial tertiary-education cost, including in private HEIs, but does not establish Servitech eligibility or account-processing rules. TALA therefore records only an externally approved coverage effect.
- The [Data Privacy Act IRR](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/) and [NPC Circular No. 2023-06](https://privacy.gov.ph/wp-content/uploads/2024/05/2023-compendium-2.pdf) require proportionate security, controlled access, continuity, backup, restoration, remedial-time planning, periodic review/testing, processor safeguards, and institutionally governed retention/disposal. They prescribe no numeric recovery-point or recovery-time objective. TALA's six-hour RPO and eight-hour RTO are project-authorized prospective engineering targets, not statutory, CHED, Servitech, SLA, or achieved-capability claims. They do not require TALA to provide an automatic disposal engine; the MVP keeps those compliance operations external.
- The supplied current-workflow evidence records an end-of-business-day Google Drive copy of the manual finance ledger. That is scoped practice evidence for routine backup only; it neither establishes a six-hour requirement nor approves Google Drive as TALA's production backup provider.
- Mature PeopleSoft account-summary, amount-due, activity, and payment-history patterns support the selected summary-first student view. They do not justify adopting a full Student Financials or cashiering suite.
- The local Academico reference contains broad invoice/payment records and responsive list/detail patterns. Only presentation patterns are relevant; its invoicing domain does not define TALA policy.
#### Implementation-evidence boundary

Current finance, payment, output, operations, schema, and test surfaces remain implementation evidence only. A later journey-complete slice must reconcile every consumer against this authority. File presence, migrations, tests, reports, or demo data cannot restore FeeRule precedence, silent percentage fallbacks, global holds, cashiering, allocations, tax documents, refunds, penalties, collections, or generic reporting.

**Payment-channel boundary:** Payment is manual-first across approved institutional channels configured by Accounting (multi-account `Bank Transfer` with 10 Philippine bank datalist presets that also accept custom bank names, multi-account `E-Wallets` with 8 Philippine provider presets replacing standalone GCash, and `Cash (Campus Cashier)`). Both digital account lists start empty, toggling either digital channel on adds blank fields without fabricated destination details, and enabled `Bank Transfer` account numbers validate 10–16 digits (allowing spaces and hyphens) alongside provider-aware `E-Wallets` validation. Core accounts, Fee Plans, authorized individual assessments, submitted-receipt verification (digital-channel uploads only via `Submit receipt`), direct onsite/cashier recording (for both Students and pre-student Applicants), shared `<x-document-viewer>` previews, and inline Official Receipt (OR) mapping operate without any online payment gateway or webhook integration.

**Clearance and restriction boundary:** `EnrollmentPaymentRequirementProjection` is a source-specific payment calculation. Accounting OUTPUT-scoped restrictions are source-backed action restrictions, not per-request clearance decisions. Baseline Section 3.7 owns other source-backed restrictions and their attribution. Accounting records exact financial effects and OUTPUT-scoped restrictions; it does not operate a generic hold/waiver editor or block unrelated sign-in, classes, examinations or grade viewing. Schema fields and hold-type inventories do not add product behavior.
## 4. Authoritative Records, Fields, and Invariants

These are conceptual product records and projections. They are not approved table, class, route, or API names.

| Name or family | Purpose | Authority owner | Classification | Required consumers | Distinction or consolidation decision |
|---|---|---|---|---|---|
| FeePlan | Freeze ordinary Program-and-Term charges and obligations | Accounting | Immutable version | Assessment and Clinic 4 readiness | Draft may be deleted before use; published versions remain distinct for reproducibility |
| AssessmentVersion | Freeze the exact assessed position and source basis | Accounting | Immutable version | Term Account, Clinic 4, SOA | `AssessmentBasis` is a controlled value; Authorized Individual Assessment is this record's bounded basis, not another subsystem |
| TermAccount and AccountEvent | Preserve one continuous student or applicant Registration Case/Term account and append-only effects | Accounting | Persisted authoritative record plus immutable events | Student Finance, Clinic 4/5, outputs | Events may represent assessment, coverage, posting, adjustment, or reversal without becoming a general ledger |
| ApprovedCoverage | Record an externally approved account effect | Named external funding authority approves; Accounting records the exact authority reference and effect | External reference/result with immutable history | Account and Clinic 4 projection | Remains separate from payment; no scholarship-management workflow |
| PaymentEvidence | Preserve each student or applicant receipt claim and review result | Student or applicant submits; Accounting reviews | Persisted authoritative record with immutable versions | Payment Exceptions and audit | Never constitutes payment by submission |
| PaymentPosting and reversal | Record verified monetary effect, onsite/cashier posting, OR mapping, and correction | Accounting | Immutable version or event | Account, Clinic 4, acknowledgments | Original posting remains; reversal is append-only and not refund execution |
| Accounting OUTPUT-scoped restriction | Preserve an actual action-scoped restriction and history | Accounting | Source-backed restriction | PRD 05 | No global hold, waiver engine or per-request clearance approval |
| Current due, account status, EnrollmentPaymentRequirementProjection | Explain current position and enrollment requirement | PRD 06 | Derived projection/calculation | Student/applicant, Accounting, PRD 04 | No stored balance fallback or generic payment-allocation engine; every posting names its exact obligation effects |
| System Health and Governance & Audit projections | Present locally recorded operational/audit evidence | System Administrator reads; source systems record facts | Derived projection/calculation or UI-only state | System Administrator | No provider console, second audit store, or compliance-attestation workflow |
| SOA, Payment Acknowledgment, and contextual CSVs | Reproduce authorized account/payment views | PRD 06 | Official output | Student/applicant or Accounting | Remain non-tax outputs; no report center or generic exporter |

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

There is always at least one obligation. If no later installment schedule exists, the enrollment obligation equals the total. If later obligations exist, their sum plus the enrollment obligation equals the total. A zero Fee Plan enrollment obligation requires explicit institutional `NoPaymentRequired` authority rather than an implicit default. Student-specific approved coverage does not rewrite the Fee Plan amount; it satisfies the corresponding Term Account obligation and remains visible as the projection basis.

Drafts are editable. Publication requires complete authority evidence, one or more charge lines, reconciled totals, valid obligation dates and ordering, and no other published version for the same Program and Term. Published plans are immutable. A successor publication marks the previous version `Superseded`; it never edits the old plan.

The Fee Plan list shows the full Program and Term, current/draft/superseded status and relevant date, total charges and enrollment payment, with one explicit open/continue action. The selected plan leads with readable charge amounts and dated payments. Approval evidence remains visible for published plans; numeric version history, source codes, categories, purposes and linked plans are secondary and reachable. Irrelevant zero-payment authority placeholders are omitted.

The shared create/edit form asks for charge names/amounts and ordered payment names/amounts/dates. TALA generates identifiers for new lines and retains existing identifiers, charge categories and nonempty purposes as source metadata. The form explicitly identifies the first payment as the enrollment requirement; saving records that first payment as the only enrollment-required row. All other payment rows remain scheduled later payments. Dates are entered in Asia/Manila and stored in UTC; entered money has at most two decimal places. A live total comparison supports correction, while the server still enforces required fields, chronology and exact reconciliation before saving. Saving a draft does not publish it or change an existing assessment.

Edit, publication and successor confirmations bind the reviewed stored content, including child lines, rather than relying only on the parent update timestamp. A changed version rejects the action and tells the user to review the current record. Creating a successor copies the selected current published version into a draft; publishing it requires fresh institutional approval, preserves the predecessor and leaves existing assessments and payments on their recorded sources.

#### Assessment basis

Every `AssessmentVersion` has exactly one `AssessmentBasis`:

| Basis | Allowed use | Required source |
|---|---|---|
| `PublishedFeePlan` | Ordinary registration represented exactly by the applicable fixed Program-and-Term plan | Immutable published Fee Plan version |
| `AuthorizedIndividualAssessment` | Approved Special Term; reduced enrollment whose approved charges differ from the ordinary plan; an Individually Advised proposal with selection-specific charges; or an authorized adjustment/Course Drop with an institutionally determined financial effect | Exact externally calculated Accounting result and authority evidence |

An `AuthorizedIndividualAssessment` contains the Registration Case and exact current reserved proposal or authorized confirmed change version; Program and Term; the matching course-and-unit snapshot; reason category; external Accounting authority reference and date; ordered nonnegative charge lines; reconciled total and obligations; enrollment-required amount; recorder and time; and predecessor/supersession evidence where applicable. Initial enrollment assessment is prepared before student confirmation under PRD 04; confirmation remains required before official finalization. It records no executable formula. Course and unit facts explain the external result but do not cause TALA to calculate a rate, discount, penalty, refund, credit, or forfeiture. No global rate, inheritance, precedence, percentage, or implicit default is permitted. In the individual-assessment form, TALA derives the enrollment-payment label and numbered later-payment labels from their established purpose and order; charge names, payment amounts, and due dates remain the exact Accounting-approved external result.

#### Term Account and assessment

One conceptual `TermAccount` is anchored to the same human subject, `RegistrationCase`, and Term. `Person` is only a cross-document continuity label; it does not introduce a universal Person master record, table, profile, sign-in identifier, or separate UI.

- It may exist before official enrollment, a StudentProfile, or a student number.
- After Clinic 4 finalizes first enrollment, the same account gains the official Student reference. It is never copied or replaced.
- One immutable `AssessmentVersion` records its Assessment basis; the exact Fee Plan version or authorized individual authority; the Registration Case and current reserved proposal or authorized confirmed change version; the course-and-unit snapshot; charge lines, obligations, totals, creation authority, and as-of time.
- An authorized changed assessment creates a successor version and linked adjustment evidence; it does not rewrite the prior version.
- Account activity is append-only: assessment charges, verified payments, approved coverage, authorized adjustments, and reversals.
- The Account Statement can reproduce the position from those authoritative events without presenting a general-ledger model.

`Current due` is the sum of applicable obligations due through the as-of time, including the enrollment obligation while the Clinic 4 finance checkpoint is pending, minus verified-payment and Approved-Coverage amounts applied to those due obligations, floored at zero. Later-obligation payments do not reduce current due until that obligation is due, while the Term remaining balance reflects all valid obligation applications.

#### Approved Coverage

`ApprovedCoverage` is one append-only record of an externally approved funding effect. It is not a scholarship, sponsorship, subsidy, grant-application, or financial-accommodation workflow.

| Field | Required meaning |
|---|---|
| Coverage reference | Stable Accounting and student-visible reference |
| Term Account and Assessment version | Exact account position to which the authority applies |
| Applicable obligation or obligations | Named current obligations; no prior-debt or cross-term allocation |
| Category | `Scholarship`, `Sponsorship`, `GovernmentSubsidy`, or `OtherAuthorizedFunding` |
| Safe provider/source label | Student-visible source name without private eligibility data |
| External authority reference and date | Evidence that the result was approved outside TALA |
| Approved and applicable amount | Exact positive PHP amount, to two decimal places, that may affect the named obligations |
| Effective date | Date the external result takes effect |
| Recorder and time | Authorized Accounting actor and recording evidence |
| Safe description | Minimum student-facing explanation |
| State and history | `Applied`, `Superseded`, or `Reversed`, with predecessor/successor or reversal authority |

There is no `Pending` coverage application in TALA. Eligibility, application, ranking, renewal, documentary review, fund release, and provider administration remain external. Recording requires a current Assessment, exact obligations, complete authority, and an applicable amount no greater than the remaining named obligations after valid verified-payment and Applied-coverage effects. Payment and coverage mutations revalidate that shared remaining amount transactionally so concurrent actions cannot over-satisfy an obligation. Excess, conflicting, stale, unsupported, or unreconciled authority records no account effect and remains an Accounting source-resolution problem; TALA never silently caps it, creates a negative balance, transfers it to another term, or infers a refund.

Applied coverage does not rewrite a Fee Plan or Assessment. A successor Assessment requires explicit revalidation and either a current confirmation or successor coverage record. Supersession or reversal appends evidence and refreshes the account. A post-enrollment reversal may create a current amount due but never revokes official enrollment or creates a global hold. `NoPaymentRequired` remains an institutionally authorized Fee Plan basis and is not student-specific coverage.

#### Payment evidence

| Field | Required meaning |
|---|---|
| Evidence reference | Stable student- and staff-searchable reference |
| Term Account | Exact owner and Term context |
| Submitter and submitted time | Authenticated Applicant/Student identity and timestamp |
| Channel | Approved configured digital account (`Bank Transfer` or `E-Wallets` account, preserving historical `'gcash_manual'` compatibility alongside `'bank_transfer'`, `'e_wallet'`, and `'cash'`) with immutable destination `channel_snapshot` |
| Claimed amount and paid time | Positive two-decimal PHP claim and Asia/Manila time no more than five minutes in the future; not yet authoritative payment |
| External reference | Student- or applicant-supplied reference, masked outside authorized review |
| Private receipt file | Access-controlled receipt file (PDF, JPEG, or PNG) with content metadata and checksum |
| State | `Submitted`, `Verified`, `Rejected`, or `Superseded` |
| Review result | Accounting actor, review time, verified amount, named target obligations and proposed application, or safe rejection reason, plus external-check reference |
| Replacement link | Earlier evidence superseded by a resubmission |

Submission never posts payment. When at least one digital channel (`Bank Transfer` or `E-Wallets`) is enabled (`hasEnabledDigitalChannels()`), the owning Enrollment or Finance action displays Accounting-configured **"How to pay"** instructions (`Bank Transfer` accounts, `E-Wallets` accounts, and `Cash (Campus Cashier)`) with explicit walk-in guidance (*if paying onsite at school, Cashier/Accounting records it directly — no receipt upload needed*) and promotes `Submit receipt`. When only `Cash (Campus Cashier)` is enabled, no student receipt upload UI is rendered and server-side submission enforces that electronic receipt uploads are turned off. For digital submissions, Accounting checks the real bank or e-wallet source against the captured `channel_snapshot` and confirms the actual verified amount and proposed application. An underpayment may post its actual verified amount and leave the projection `ActionNeeded`. An amount above the remaining named target obligations enters Payment Exceptions and cannot post until the discrepancy has an authorized result. Rejection preserves the submitted receipt; resubmission creates a successor.

#### Payment posting, onsite recording, OR mapping, and correction

One immutable verified `PaymentPosting` contains:

- posting reference, Term Account, optional Student reference (nullable for pre-student Applicants), amount, currency, channel (`Bank Transfer`, `E-Wallets`, legacy `GCash`, or `Cash (Campus Cashier)`), nullable destination `channel_snapshot`, and posted time;
- masked external/bank/wallet references and optional Official Receipt (`OR`) number;
- verification basis: Accounting external check (from a submitted receipt) or direct onsite/cashier recording;
- source evidence reference (when verified from a submitted receipt);
- idempotency key;
- applicable Assessment version and exact named obligations with the amount applied to each;
- Accounting actor; and
- current state, including a linked reversal when applicable.

Every posting is obligation-keyed. The owning action supplies a bounded target set from the current Assessment; TALA applies the verified or onsite amount to those obligations in published order, oldest due first, and records no unassigned remainder. Both manual verification and direct onsite/cashier recording preview or apply the same named effects deterministically and never cross a Term or apply to prior debt. Accounting records onsite/cashier payments directly inside Student Accounts for both enrolled Students (`student_profile_id` present) and pre-student Applicants (`student_profile_id === null`), and can map an Official Receipt (`OR`) number inline onto any verified posted payment missing an OR (including receipt-verified and Applicant payments). Once a verified payment, onsite posting, Approved Coverage, retained-effect reconciliation, or zero-due (`NoPaymentRequired`) assessment commits and clears the enrollment requirement, Clinic 4's post-commit `AttemptAutoOfficialEnrollment` runs in an isolated transaction to auto-finalize Official Enrollment and issue a system-attributed COR (`issued_by = null`, recording the Accounting actor strictly as payment-clearance provenance rather than COR issuer); if any non-finance blocker exists, the verified payment stays committed and the case routes to Registrar exception recovery.

An authorized reversal appends a correcting event, records authority and reason, reverses the exact obligation effects, refreshes projections, and marks the related acknowledgment reversed or superseded. It never deletes the original posting. Refund execution, chargeback handling, and cash movement remain outside TALA; TALA records only the authorized external result needed to correct its account projection.

External refund requests and unverified claims do not prove completed money movement. Accounting must match the actual external result, payment, amount and authority before recording a local correction. A partial external result cannot justify reversing the entire original posting. Validate external refund evidence before applying the local effect, and commit its reconciliation record and correcting effect atomically. These integrity requirements refine the existing boundary; they add no refund-execution capability.

#### Clinic 4 enrollment projection

Clinic 6 publishes one read-only `EnrollmentPaymentRequirementProjection`:

| Field | Contract |
|---|---|
| Total assessment | Current authoritative Assessment version total |
| Amount required now | Enrollment obligation applicable to finalization |
| Verified payment applied | Posted verified payment explicitly applied to the enrollment obligation |
| Approved coverage applied | Current Applied coverage usable for the enrollment obligation |
| Remaining required now | Nonnegative difference |
| State | `Cleared`, `ActionNeeded`, or `Unavailable` |
| Satisfaction basis | `VerifiedPayment`, `ApprovedCoverage`, `Mixed`, `NoPaymentRequired`, or `None` |
| Assessment source | `PublishedFeePlan` or `AuthorizedIndividualAssessment`, exact proposal/change reference, Fee Plan or external authority reference, and Assessment version |
| Source and as-of | Assessment, separate posting and coverage references, and calculation time |
| Later obligation | Whether an amount remains due after enrollment |
| Account link | Authorized contextual Student Account destination |

Clinic 4 consumes only this projection. `Unavailable` means a required source is missing, invalid, stale, unreconciled, or unauthorized and blocks only the consuming registration action. It never means zero. `Cleared` means the current enrollment obligation is satisfied, not that the lifetime or Term balance is zero. A later missed obligation never reverses official enrollment or blocks login, classes, or examinations.

For a changed registration, a cost-increasing add or replacement requires a successor Assessment version and clearance of the newly required amount before Clinic 4 applies the change. A no-additional-cost change requires Accounting's unused confirmation for the exact Registration Case, current course registration and replacement class. An Assessment alone does not authorize that path. An authorized removal or Course Drop may take academic effect while its financial effect is `Accounting review pending`; TALA does not infer a lower balance, refund, credit, penalty, or forfeiture. A later Accounting decision appends an authorized adjustment or successor Assessment and never rewrites the original Assessment or COR.

A canceled, expired or `NotEnrolled` Registration Case may still have verified money in its same-Term Account. Retain payment, coverage and obligation history, show **Registration ended — Accounting review required**, and link the student or applicant to the existing account/support path. Accounting resolves the exact external result and infers no forfeiture, refund, credit or reallocation. A same-Term reopen revalidates the current Assessment and named obligations under the ordinary transaction guards. Retained valid applications count once; changed assessment authority requires a successor and explicit Accounting reconciliation before clearance.

Accounting records a distinct no-additional-cost classification for the exact student-confirmed pending registration change. The current subject and proposed class are derived and read-only; the internal confirmation records the accountable decision, actor and time without asking for a fabricated external approval number. Recheck the reviewed proposal, confirmation, current registration and class sources before recording the classification. The confirmation changes no official subject or COR until Registrar applies the authorized change.

The same focused Account view handles pending changes, Course Drop financial review, retained-effect reconciliation, unavailable assessment sources and ended registration. Show the source-owned task before retained charges and activity. Suppress an ordinary due/payment schedule when the current position is unavailable or needs review. An unknown registration outcome requires Registrar review without an inferred ended status. An ordinary recorded Course Drop remains an eligible exact assessment source without a routine Registrar reference; its owning drop, removed course, current COR and genuine external Accounting authority still require validation.

#### Accounting review of applied student changes

An actually applied, enrollment-linked lifecycle change from PRD 05 opens one Accounting review for that exact change, Student, enrollment and Term. A future approval creates no review before Registrar applies it; a cancelled approval or permitted status-only change without an enrollment creates none. A later applied change needs its own review. Existing valid source/event pairs remain discoverable as pending until an attributable disposition exists; age and newer activity never imply completion. Preserve original application and event times rather than manufacturing review history.

Accounting may record **No account change required** only after completing the external review and determining that no financial action is needed, with a concise reason. Alternatively, **Authorized financial action recorded** links an existing authoritative financial record that addresses this particular change in the same account. Accounting explains that relationship; merely belonging to the same account is insufficient. The linked action retains its existing eligibility, evidence and authority requirements. This review adds no assessment basis or financial action. An undecided external result, missing account/source or unsupported financial action remains pending with source-specific recovery; none qualifies as no change required.

Only Accounting may record or correct the review disposition. Each result binds the exact reviewed source and current review, records actor/time and reason, and preserves its source links. Revalidate the applied state, Student/Term/enrollment/account relationships and relevant financial source at save. Repeated submission records one result; stale or competing review saves nothing. A correction appends an attributable successor, retaining the prior result, and either records a corrected permitted outcome or returns the review to pending. Keep durable review evidence source-linked without copying the full academic record or introducing a general approval engine.

Recording a review disposition changes no charge, payment, coverage, balance, academic fact, enrollment, restriction or issued output. Review status is distinct from available recorded account amounts and canonical payment clearance; it never implies a zero balance or clearance. Existing financial actions remain responsible for their own guarded effects and outputs. Accounting reasons and private source evidence stay within their authorized staff context; Registrar and Student receive only the permitted safe status and recovery. No lifecycle-review email is added; Section 8 remains authoritative.

#### Clinic 5 official-output restriction

Accounting records only a genuine OUTPUT-scoped restriction: responsible office, reason, exact blocked output action, source and history. Clinic 5 consumes the current restriction source before preview/issue. An absent restriction permits the normal guarded TOR journey; no request-specific payment-clearance approval, `Cleared`/`NotRequired` decision queue or arbitrary output fee is introduced. Real payment/source facts remain in the same account records. Request intake, collection, CAV, signature, seal, claiming, courier, diploma and ceremony remain external.

## 5. Readiness and Cross-Module Handoffs

| Check | Authoritative source | Owner | Valid condition | Effect if missing or invalid | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Fee authority | Fee Plan authority reference/date | Accounting | Complete and applicable to the Program and Term | Hard blocker | Publish Fee Plan | Correct the Draft authority fields |
| Charge reconciliation | Fee Plan charge lines and total | Accounting | Nonnegative lines sum exactly to total | Hard blocker | Publish Fee Plan | Correct charge lines |
| Obligation reconciliation | Fee Plan obligations | Accounting | At least one row; ordered dates; rows sum to total; enrollment amount matches first obligation | Hard blocker | Publish Fee Plan | Correct obligations |
| Unique current plan | Published Fee Plans | Accounting | No competing Published version for Program and Term | Hard blocker | Publish Fee Plan | Supersede through the controlled successor action |
| Registration source | Clinic 4 Registration Case and proposal/selection source | Registrar | Same credential/Applicant-or-Student continuity, Program, Term, and current authoritative version | Hard blocker | Create/refresh Assessment | Correct Clinic 4 source |
| Ordinary assessment source | Published Fee Plan plus Registration Case | Accounting/Registrar | Plan is current and the current reserved proposal or authorized confirmed change is represented exactly by it | Hard blocker | Publish enrollment projection | Correct the Clinic 4 source or publish the applicable plan |
| Individual-assessment eligibility | Current Clinic 4 proposal/change plus reason category | Registrar/Accounting | Approved Special Term, reduced enrollment differing from the fixed plan, Individually Advised selection-specific charges, or authorized adjustment/Course Drop effect | Hard blocker for individual assessment | Record authorized individual assessment; for an approved initial proposal, TALA then issues the subjects to the learner (PRD 04 §4.3), or keeps the assessment and returns the case to Registrar with the reason | Correct the case/change source or use the ordinary plan when it represents the case |
| Individual-assessment authority | External Accounting authority and exact result | Accounting | Authority/date, exact proposal/change, course/unit snapshot, nonnegative lines, total, obligations, and enrollment amount are complete and reconciled | Hard blocker | Publish projection or changed-registration impact | Correct the external result; never infer a formula or amount |
| Approved Coverage authority | External approval plus current Term Account, Assessment, and named obligations | Accounting | Category/source, authority/date, applicable amount, effective date, recorder, and target obligations are complete; amount does not exceed their remaining value | No coverage account effect; missing, stale, conflicting, or invalid authority produces `Unavailable`; a valid account with an unsatisfied current obligation remains `ActionNeeded` | Record Applied coverage; publish enrollment projection | Correct or externally reconcile the authority; never cap, reallocate, refund, or infer eligibility |
| Approved Coverage continuity | Current Assessment and existing coverage history | Accounting | Coverage still applies to the exact Assessment and obligations; any successor/reversal authority is current | Stale coverage is excluded and the consuming projection refreshes safely | Revalidate, supersede, or reverse coverage | Record explicit successor/reversal evidence; never reuse coverage silently |
| Changed-registration impact | Exact Accounting no-cost confirmation or cleared successor Assessment; recorded removal/drop for review | Accounting/Registrar | Exact change version and additional-required, no-additional-cost, or review-pending result are authoritative | Hard blocker for the consuming change when additional clearance or confirmation is required | Apply add/replacement, confirm no-cost change, or record review state | Record/refresh the exact Accounting confirmation or authorized Assessment result |
| Payment claim | Private evidence metadata/file | Applicant/Student | Complete, readable, authorized, and within allowed file constraints | Hard blocker for submission only | Submit evidence | Correct fields or replace file |
| External payment verification and onsite recording | Real bank, e-wallet, or Campus Cashier source | Accounting | Exact owner/context (Student or pre-student Applicant), current Assessment, named obligations, amount, reference (and OR for cash or inline OR mapping), and no unresolved conflict; remaining amounts revalidated transactionally against current payment/coverage effects | Hard blocker for posting | Verify receipt, record onsite payment, or map Official Receipt (OR) | Reject, resubmit, route to exception, or supply valid Official Receipt (OR) |
| Output source | Current Term Account and authorized requester | Owning role | Source is available, current, and accessible | Hard blocker for output | Generate SOA with payment activity; historical acknowledgments retain access; contextual CSV access | Refresh source or correct authorization |
| Automatic retention disposal | MVP product boundary | Institution/privacy owner remains responsible externally | Not provided in this MVP | No disposal action exists | None inside TALA | Handle lawful retention schedules, privacy requests, legal holds, and secure disposal outside TALA |

Successful readiness checks use a concise summary with accessible source detail. Every failed result names the owner, source, effect, and safe next action. Missing SMTP never reverses a payment posting.

## 6. States, Permissions, and Actions

| State or projection | Trigger/action | Actor and authorization | Guards | Resulting record/effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|
| Fee Plan `Draft` | Create or edit Draft | Accounting with Fee Plan manage authority | Program and Term exist | Saved Draft and audit evidence | No financial effect | Readiness may show incomplete |
| Fee Plan `Published` | Publish plan | Accounting publisher | All Fee Plan readiness checks pass | Immutable published version | Cannot be edited; later version supersedes | Clinic 4 may create Assessment |
| Fee Plan `Superseded` | Publish successor | Accounting publisher | Existing Published plan and valid successor | Predecessor linked to successor | Historical version retained | Existing Assessments keep their original source |
| Ordinary Assessment current | Create/refresh `PublishedFeePlan` assessment | Authorized Clinic 4/Accounting workflow | Published plan and current Registration Case represent the current reserved proposal or authorized confirmed change exactly | Immutable Assessment version and activity | Later change creates successor | Students, applicants, and Clinic 4 see plan basis and source version |
| Individual Assessment current | Record `AuthorizedIndividualAssessment` | Accounting with assessment authority | Eligible case; current proposal/change; complete external authority and reconciled exact result | Immutable Assessment version and activity | Later decision creates successor | Students, applicants, and Clinic 4 see individual basis and safe authority reference |
| Coverage `Applied` | Record externally approved account effect | Accounting with coverage-recording authority | Current account/Assessment/obligations; complete authority; exact applicable amount within remaining obligation | Append-only Approved Coverage activity and refreshed projection | Cannot be edited; correction uses successor or reversal | Clinic 4 sees separate coverage amount and `ApprovedCoverage` or `Mixed`; the student or applicant sees safe source/effect |
| Coverage `Superseded` | Record an authorized replacement | Accounting with coverage-recording authority | Existing Applied record and valid successor authority | Prior coverage ceases current effect; successor applies | History remains; no deletion or transfer | Account/SOA show both records; projection uses only current effect |
| Coverage `Reversed` | Record authorized revocation/correction | Accounting with reversal authority | Existing Applied record, authority, reason, current impact preview | Append-only reversal and refreshed due | Cannot reverse enrollment or create a global hold | Student sees safe reversal/account effect; Clinic 4 does not undo finalization |
| Projection `Unavailable` | Required source becomes missing/stale | System-derived | Source/readiness failure | Read-only unavailable result | No silent fallback | Clinic 4 cannot finalize |
| Change `AdditionalClearanceRequired` | Record cost-increasing add/replacement result | Accounting and Registrar | Successor Assessment identifies exact new required amount | Change waits for bounded clearance | Assessment and original proposal remain immutable | Clinic 4 shows the newly required amount |
| Change `NoAdditionalAmount` | Confirm no-cost change | Accounting | Unused Accounting confirmation matches the exact Registration Case, current course registration and replacement class | Authoritative change impact | No amount is inferred from course/unit differences | Clinic 4 may continue the change |
| Change `AccountingReviewPending` | Apply authorized removal/Course Drop academically | Registrar; Accounting owns financial review | Academic removal/drop authority exists; financial effect unresolved | Review-queue item; no automatic balance change | Later result appends adjustment/successor | COR may state review pending; Finance/SOA owns current position |
| Receipt `Submitted` | Submit receipt (`Submit receipt`, digital channels only) | Owning Applicant/Student | At least one active digital channel (`Bank Transfer` or `E-Wallets`), authorized account, complete fields, destination `channel_snapshot`, valid private file (`Transfer receipt or screenshot`) | Review-queue item (`Receipt to review`) | Submission retained; digital-disabled submission is rejected safely | Student or applicant sees `Under review` and previews via `<x-document-viewer>` |
| Receipt `Rejected` | Reject submitted receipt | Accounting reviewer | External check completed; safe reason supplied | Rejection result | Submitted receipt retained; resubmission supersedes | Student or applicant sees reason and resubmit action |
| Receipt `Verified` | Verify and post | Accounting reviewer | Exact external evidence against `channel_snapshot`; current Assessment; named target obligations; amount not above their remaining total | Immutable obligation-keyed Payment Posting (OR optional at posting, mappable inline afterward) | Correction requires exact-effect reversal | Projection and student/applicant account refresh |
| Onsite / Cashier posting | Record direct onsite payment | Accounting / Cashier | Enrolled Student or pre-student Applicant account; current Assessment; amount and reference/OR (OR required for cash) | Immutable obligation-keyed Payment Posting (`Posted`, `verified`) | Correction requires exact-effect reversal | Projection and student/applicant account refresh immediately for Registrar finalization |
| Official Receipt mapped | Map Official Receipt (OR) inline | Accounting | Verified posted payment missing an OR (including receipt-verified and Applicant payments); unique OR number | Official Receipt number attached to existing posting | Immutable once mapped unless corrected via authorized reversal | Account history, SOA, and exports display the mapped OR |
| Posting reversed | Record authorized external correction | Accounting with reversal authority | Existing posting, authority, reason, impact preview | Append-only reversal and refreshed projections | Original remains visible | Ack becomes reversed/superseded |
| OUTPUT-scoped restriction active | Record actual authorized restriction | Accounting | Office, reason, exact blocked output action and current source | Read-only named blocker for Clinic 5 | Later resolution appends evidence/history | Only the named output action pauses |
| OUTPUT-scoped restriction resolved | Record source-backed resolution | Accounting | Current restriction/source and attributable resolution | Named action may continue after all its other checks | Prior restriction/source history remains | No request-by-request approval |

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

1. Clinic 4 supplies the current Registration Case and exact current reserved proposal or authorized confirmed change version. Initial assessment preparation precedes student confirmation; official finalization still consumes current confirmation under PRD 04.
2. If the fixed published Fee Plan represents the registration exactly, TALA creates a `PublishedFeePlan` Assessment.
3. If the case is one of the four eligible exceptions, Accounting records the exact externally calculated `AuthorizedIndividualAssessment` and its authority evidence.
4. TALA validates the source, nonnegative lines, totals, obligations, and enrollment-required amount without executing a fee formula.
5. If neither source is valid, the enrollment or change projection is `Unavailable`; it never becomes zero or uses a fallback.
6. A later authorized decision creates a successor Assessment or adjustment. The original Assessment and COR snapshot remain unchanged. A no-additional-cost classification is a distinct Accounting decision over the exact student-confirmed proposal and subject/class change. TALA records the authorized actor, time, and a generated internal confirmation identity; that identity records the Accounting decision and does not claim an external approval number.

#### Manual external-payment evidence and onsite/cashier recording

1. The Applicant or Student opens the owning Enrollment or Finance context.
2. TALA shows the exact Term Account, current due, named target obligations, responsible office, and Accounting-configured **"How to pay"** instructions (multi-account `Bank Transfer`, multi-account `E-Wallets` replacing standalone GCash, and `Cash (Campus Cashier)` with explicit walk-in guidance that Cashier/Accounting records onsite payments directly with no receipt upload needed). When at least one digital channel (`Bank Transfer` or `E-Wallets`) is enabled, TALA promotes `Submit receipt` as the primary CTA; when only onsite `Cash (Campus Cashier)` is active, no student receipt upload UI is shown.
3. For Bank Transfer or E-Wallet transfers (only when a digital channel is active; server-side upload fails safely otherwise), the student or applicant selects the active channel and specific destination account (auto-selected when only one account exists under that channel), supplies claimed amount, paid time, and a private `Transfer receipt or screenshot` file; an external payment reference is optional with format guidance tailored to the chosen bank or e-wallet. Enrollment and Finance share the same concise `Submit receipt` dialog and **"How to pay"** guidance; the owning context supplies its exact current target fees. The amount paid is the actual receipt amount, not an automatically assumed account balance. The owning context retains the bounded target obligations. Students and applicants can view their submitted PDF, JPEG, or PNG inside the shared `<x-document-viewer>` modal (`<x-payment-proof-viewer>`) and download the original via `Download receipt` from the same private viewer. Viewing or submitting a receipt does not post payment.
4. TALA validates (enforcing `hasEnabledDigitalChannels()`) and stores the submission with its destination `channel_snapshot` without posting payment or emailing.
5. Accounting opens submitted receipts (`Review receipts` / `Compare submitted receipt`) from the owning account, reviews the claimed payment and named targets alongside the private viewer, and checks the real bank or e-wallet source against `channel_snapshot`. The submitted receipt alone does not verify payment.
6. Accounting verifies the actual amount and previews its deterministic application to the named obligations, rejects with a safe reason, or leaves the item under review.
   - An in-progress enrollment whose current-subject Assessment still has an unpaid enrollment-required balance and no submitted receipt stays in Accounting's **Waiting for payment** queue (row action **Record payment**). A submitted receipt moves it to **Review receipts**; it leaves once the enrollment payment clears and official enrollment completes (#99, PR #101).
7. For onsite Campus Cashier payments, Accounting records the payment directly inside Student Accounts for either an enrolled Student or a pre-student Applicant (`student_profile_id === null`), generating the immutable `PaymentPosting` and allocations immediately without requiring a student receipt upload. Accounting can also map Official Receipt (OR) numbers inline on any verified posted payment missing an OR.
8. Confirmation or onsite posting refreshes projections (advancing Finance readiness for Registrar `Finalize enrollment`), updates payment activity in SOA and retains historical acknowledgment access, and queues exactly one verified-payment email.

Unreadable, wrong-account, duplicate-reference, mismatched, over-obligation, and conflicting submissions never post automatically. A failed upload preserves entered non-file fields where safe and clearly requests a new file. A failed posting states whether nothing was posted and preserves the review evidence.

#### Record Approved Coverage

1. Accounting opens the current Term Account and selected Assessment version.
2. TALA shows the named obligations, payments already applied, current coverage, and remaining applicable amounts.
3. Accounting records the external category/source, authority reference/date, effective date, exact applicable amount, and safe description.
4. TALA revalidates the Assessment, obligations, existing account activity, and non-excess amount.
5. A valid result appends one `Applied` record and refreshes the enrollment/current-due projection without sending email.
6. A changed Assessment, replacement, or revocation requires an explicit successor or reversal; history remains visible.

Missing, stale, unsupported, conflicting, unreconciled, or excessive authority posts nothing and identifies the responsible Accounting source. TALA does not create a scholarship application, cap the value, issue money, infer eligibility, or turn the result into a payment.

#### Later obligation

When a later obligation reaches its due date, current due and Student Finance refresh from the same account activity. The student may pay onsite or submit a digital receipt (when enabled), but the amount does not retroactively invalidate official enrollment, Student access, classes, examinations, released academic results, or COR versions. Only a specifically authorized future or official-output action may consume an approved bounded projection.

#### TOR request and issuance handoff

Clinic 5 records the TOR request and previews/issues it under academic-source, identity, template, signatory, rendering and authorization checks. Accounting's applicable OUTPUT-scoped restriction blocks only its named action with a reason and source. Resolving that source refreshes the same restriction/history. Accounting operates no per-request TOR payment-clearance approval workflow and issues no TOR.

## 8. Email Authority

Emails are selective action-required or official-event messages. Preserve security, authorization and event/recipient idempotency. There is no exactly-twelve-message requirement or bell-notification system.

Clinic 6 owns exactly one email event.

| Trigger | Recipient | Safe contents | Source and idempotency | Delivery failure | Excluded notifications |
|---|---|---|---|---|---|
| Verified payment posted | Owning Applicant or Student email account | Amount, Term Account reference, posting date, secure portal link, and statement that the TALA acknowledgment is not a tax invoice | Payment Posting reference | Posting remains valid; failure is recorded for authorized resend/follow-up | Receipt submission/rejection, Approved Coverage creation/supersession/reversal, reminder, exception, OUTPUT-scoped restriction, reversal, health, export, or routine activity |

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

The statement shows the Term Account position as of generation. Heading, charge lines, basis/authority, version and balances must use the same active Assessment source. An authorized link opened from a superseded Assessment remains accessible and labels that historical origin secondarily; it must not label current balances with the old Assessment's authority. If an active source is unavailable, balances remain unavailable and retained earlier charge/source facts are clearly historical. One useful account-statement link per Term is sufficient; earlier Assessment versions remain readable history without creating duplicate statement choices.

#### Payment activity and historical acknowledgments

New payment acknowledgment is folded into the SOA presentation: verified posting reference, amount/date/channel, masked external reference, account effect and reversal/supersession state remain visible with the non-tax disclaimer. The existing authorized printable payment record reads retained original posting and allocation facts, alongside current attributable reversal/supersession status. Identity and obligation wording may reflect current records, and the displayed generation time identifies this viewing/copy rather than an original issuance event. Private access is logged. Preserve any actually retained issued artifact and its authorized access. Reversal preserves the original monetary facts and labels the later effect clearly.

An unavailable statement leads with its actual source problem and Accounting recovery step. Recorded charges and activity remain attributable, without a current due amount, payment schedule or inferred cleared balance. A payment copy with recorded refunds leads with the amount still applied and current posting state, then the original verified amount, recorded refunds and explicitly original allocations. The original monetary posting stays unchanged. Return links lead Applicants to Enrollment, Students to Finance and Accounting to the owning account.

#### Contextual CSV exports

Account Status and selected-account Verified Payments exports use the fixed contextual contracts below. Class Roster membership and its selected-class export remain governed by PRD 04/05.

Exports are CSV-only native Filament actions with fixed ordered columns. Column selection, alternate formats, a generic exporter, and a Reports navigation page are not provided.

**Account Status CSV** uses the exact normalized Account records filters, actor authorization scope and deterministic visible ordering confirmed at export time. Preparation cases without a Term Account remain in the work list and are explicitly excluded from this report. Its filename is `tala-account-status-YYYYMMDD-HHmmss-PHT.csv`, and its ordered labels are:

1. `Account reference`
2. `Student / applicant number`
3. `Student / applicant name`
4. `Program`
5. `Term`
6. `Assessed charges (PHP)`
7. `Verified payments applied (PHP)`
8. `Approved coverage applied (PHP)`
9. `Due now (PHP)`
10. `Later fees (PHP)`
11. `Remaining term fees (PHP)`
12. `Account status`
13. `Assessment version`
14. `Assessment basis`
15. `Approval reference`
16. `As of (Asia/Manila)`

`Student / applicant number` uses the recorded Student number or application reference; email is not an identity fallback. `Student / applicant name` comes from the recorded Student or Applicant identity, with the existing credential name used only when those source fields are unavailable. `Later fees` is the remaining balance of obligations that are not yet due, not their gross scheduled amount or uncommitted capacity. `Account status` uses `Payment due`, `No payment due now` or `Needs review` from the same current assessment/change interpretation used by the Account records view. A pending recorded change or unavailable current source cannot be exported as cleared. Unavailable applied/due/later/remaining figures stay blank; known recorded assessment/source details remain attributable. The ambiguous gross `Required Now` and redundant `Satisfaction Basis` columns are removed.

**Verified Payments CSV** is available from one selected Student Account's payment context and uses only its current entry-type and verification-date filters and actor authorization scope. It is not a system-wide payment report. Its filename is `tala-verified-payments-YYYYMMDD-HHmmss-PHT.csv`, and its ordered labels are:

1. `Payment reference`
2. `Account reference`
3. `Student / applicant number`
4. `Student / applicant name`
5. `Term`
6. `Entry type`
7. `Amount (PHP)`
8. `Channel`
9. `Masked external reference`
10. `Payment or refund at (Asia/Manila)`
11. `Verified at (Asia/Manila)`
12. `Reverses payment reference`
13. `Verification basis`

`Entry type` distinguishes `Verified payment` and `Reversal`; the amount is the positive recorded amount, with direction carried explicitly by that type. Date filters and ordering remain based on verification time. Reversal linkage resolves the original payment even when it falls outside the selected dates; ordinary payment rows leave that column blank. Payment/refund time and verification time retain their distinct source meanings. `Channel` renders the captured destination provider/bank and account detail when `channel_snapshot` is recorded, falling back to the channel label for legacy rows. Verification basis uses readable words without changing its source value. Student and applicant identity follows the Account Status identity rule. Prior generated artifacts retain their original bytes and access history.

Both files are UTF-8 with a byte-order mark, comma-delimited, RFC 4180 quoted, and generated with CRLF line endings. Money is an ungrouped two-decimal value such as `12000.00` without a currency symbol; date/time is RFC 3339 with `+08:00`. Blank means not applicable or unavailable and never silently becomes zero. Text cells beginning with `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with one apostrophe before export; numeric and date/time cells are generated only from typed authoritative values.

Purpose is fixed by the authorized contextual action: **Accounting account-position review** for Account Status and **Accounting verified-payment reconciliation** for Verified Payments. Show and audit that purpose without requiring repetitive free-text justification. The confirmation names the export, normalized scope, row-count estimate, a small current-row preview, fixed columns and private retrieval. New or changed filter selections refresh that preview. Empty results explain how to change the scope; over-limit results require narrower filters. Preview figures are estimates; generation re-reads the same contextual source and records the actual outcome. Export rechecks role, per-record visibility, current filters, and source/as-of state and is limited to 10,000 rows; a larger result requires narrower filters. Zero matching rows records a `NoRows` attempt and creates no artifact. A completed export is retrievable only by the initiating authorized actor through private storage. Failure records no successful output, retains the normalized scope for retry, and creates no partial file. Audit records actor, role, normalized filters/context, purpose, row count, outcome, and time. Exports send no email.

Private receipt paths, raw provider data, internal bank-account credentials, secrets, eligibility material, and internal notes are excluded. Output access and retry remain contextual to Student Accounts or the selected Account; no notification center, export history destination, or report hub is added.

#### Required audit evidence

Audit covers Fee Plan creation/publication/supersession; Assessment creation/supersession; Approved Coverage application/supersession/reversal; evidence submission/review; posting and idempotent duplicate outcome; exception resolution; adjustment/reversal; OUTPUT-scoped restriction/resolution; output access; export purpose/outcome; email delivery result; integration result; and locally recorded backup/restore evidence. Audit views do not expose secrets, private eligibility material, or private screenshots.

## 10. System Health, Governance, Privacy, and Recovery

#### System Health

System Health is an evidence-first System Administrator page structured as a compact operational status summary banner (`Last checked` in `Asia/Manila`, inline status breakdown, and `Refresh status` action firing a toast confirmation; an inline warning banner appears only when a capture is stale) above a 4-card live operational grid (`items-start` without vertical stretching):

1. **App & Database** — Live PHP and Laravel version, read-only database connectivity (`Available` / `Unavailable`), private local storage writability check, and a `Technical details` slide-over modal.
2. **Job Queue** — Separates `Pending` and `Failed` counts (`0 failed` with pending jobs displays `Queued (waiting for worker)` instead of an error state; `failed > 0` displays `Needs attention`), with an `Inspect failed jobs` modal (job class, queue, `Asia/Manila` timestamp, and sanitized first-line exception summary — zero raw payload or PII) and a `Retry failed jobs` action (visible when `failed > 0`, running `queue:retry all`, logging `activity()`, and notifying).
3. **Mail / SMTP** — Distinguishes `Ready`, `Queued (waiting for worker)`, and `Delivery failed`, surfaces sanitized transport error categories without exposing secrets or recipient PII, and provides a rate-limited (`1/60s`) self-only `Send test email` action directly on the card.
4. **Scheduling Solver** — Displays solver driver/mode, latest run, and probe status, with a rate-limited (`1/30s`) `Check solver` action calling `SchedulingSolverClient::probe()` (`GET /health`) without generating a timetable, recording safe allowlisted diagnostics (`classification`, `status_code`, `driver`) to render plain-English **Error reason + Troubleshooting steps** on failure (including HTTP 403 IAM/GCP billing and HTTP 429 quota guidance), plus a read-only **`Solver runs & reports`** slide-over (`schedule_runs` operational history).

Below the four cards, System Health displays one calm boundary note: *"Automated database backups and disaster recovery are managed at the hosting infrastructure level."* Every card uses plain status labels (`Available`, `Needs attention`, `Unavailable`, or `Not recently checked`) and never exposes provider controls, shell commands, restores, browser SQL dumps, test payments, timetable generation, or arbitrary-recipient email.

#### Governance & Audit

One read-only page covers four tabs backed by a 3-source audit projection (`activity_log` plus minimal unlogged domain fallbacks, `authentication` + `operational_events`, and `output_access_logs`), with a compact tab-context guide above the table explaining what each tab tracks:

1. `Institutional changes`
2. `System events`
3. `Output access`
4. `Privacy & retention`

The evidence table presents five minimal columns (`When` in `Asia/Manila`, `Event` paired with its domain `Category`, `Who` with real name, role, and institutional identifier, `Target`, and `Summary`), with dropdown filters (`Category`, `Actor`, `Event type`, `Date range`), search, a structured 2-column `View detail` slide-over (`Reference ID`, `Category`, `Status`, `When`, `Who`, `Role`, `Target`, and full `Summary`), and allowlisted `Export CSV`. Deep-links (`?actor=`) bind directly into the native `Actor` filter. It is not a compliance attestation, policy editor, incident-response suite, or report designer.

#### Retention boundary

Routine Governance copy says **Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.** Technical details retain the exact boundary that automatic retention disposal is not provided in this MVP. TALA has no retention-policy engine, disposal scheduler, disposal-candidate queue, legal-hold workflow, archive center, or generic restore workflow. Core identity, official enrollment, COR, grade, academic-history, lifecycle, completion, TOR, assessment, payment, coverage, and audit records have no ordinary UI delete action. Existing hard-delete rules remain only for never-submitted, never-published, unreferenced Drafts with no dependencies. Submitted evidence and authoritative history remain protected from ordinary deletion.

Institutional retention schedules, lawful privacy requests, legal holds, and secure disposal operations are external compliance responsibilities. System Health may state this product boundary, while Governance & Audit remains read-only and makes no claim that external institutional compliance is approved or deficient.

Active and `Completed` Student, enrollment, grade, academic-history, account, and official-output records remain in the live authoritative system until an attributable institutional retention decision lawfully directs otherwise. Completion preserves the existing read-only historical Student access; a backup never removes, archives, or replaces a live record. Disaster-recovery generations are unavailable for ordinary browsing, reporting, or record retrieval and do not extend an approved lawful-retention period. When a restore uses a generation created before an externally completed lawful disposition, the Infrastructure Custodian must reconcile that disposition before restored service reopens so disposed material is not silently republished.

#### Deployment and recovery boundary

Production recovery is an external operational responsibility, not a Clinic 6 product workflow or capstone browser journey. The product contract is deliberately provider-neutral:

- The live MySQL database and private application storage remain the sole authoritative operational source. A backup is a time-bounded disaster-recovery copy, not an archive database or alternate TALA record store.
- Prospective production requires an independently controlled, client-side encrypted off-host copy outside the primary hosting failure and owner-account boundary, generated from a transaction-consistent export with integrity and application-version evidence.
- A named Infrastructure Custodian must monitor the configured backup objective and prove restoration in isolation before production acceptance. The institution's privacy/DPO authority owns provider, processor/transfer, retention, key-custody, removable-media, and lawful-disposal decisions.
- Exact provider, tool, cadence, retry, retention, RPO/RTO, offline-media, and restoration order belong to the Architecture Specification and deployment runbook. Cloudflare R2, restic, and the client-owned ORICO enclosure are evaluated production candidates, not mandatory TALA features or capstone acceptance dependencies.
- TALA exposes no provider, shell, media, or restore controls and may display only safe locally recorded outcome, as-of, overdue, and next-action evidence. Provider and physical-media facts use plain not-recently-checked guidance and never appear healthy without separately imported approved evidence; technical details retain the exact source and verification boundary.

Routine actions and routine Fee Plan publication capture actor/time automatically (`published_by` and `published_at`) and accept an optional approval note/memo and optional approval date (owner decision, 19 November 2026: Servitech does not require a formal external memo number for routine fee-plan updates; UI hides blank optional notes and presents plain-language `Current` / `Updated [date]` / `Plan history` labels while preserving immutable backend version records). Genuine exceptions — including zero enrollment payment waivers (`NoPaymentRequired`), custom individual assessments, approved coverage, reversals, and refunds — retain their required school approval memo/voucher reference. One unified assessment presentation explains the same standard fee plan, custom assessment, coverage, and verified payment sources without merging their distinct records, inventing discounts, or adding a migration. New application acknowledgment is a screen receipt; actually retained issued artifacts and authorized access remain preserved. Printable payment records follow the original-posting and current-copy contract in Section 9.

## 11. UI Interaction Contract

The Clinic 6 UI Surface Blueprint owns capability coverage, hierarchy, states and responsive/accessibility outcomes. This section fixes product content and action ownership. DESIGN.md and the matching surface brief govern Impeccable-led composition and framework research.

#### Primary navigation

Accounting navigation owns:

1. **Fee Plans**
2. **Student Accounts**, with focused account/payment views, onsite/cashier recording, inline Official Receipt (OR) mapping, and contextual OUTPUT-scoped restrictions; no TOR approval queue
3. **Payment channels**, a dedicated Accounting settings page (`SystemSetting` key `'payment_channels'` v2, with no generic settings table) for configuring and toggling multi-account `Bank Transfer` (PH bank presets), multi-account `E-Wallets` (PH presets replacing standalone GCash), and `Cash (Campus Cashier)` location/hours/notes

System Administrator has **System Health** and **Governance & Audit**. Students have one **Finance** destination. Applicant payment status is embedded in Clinic 4 Enrollment and is not a separate Applicant navigation item.

#### Page inventory

| Page | User and purpose | Required information and priority | Fields, filters, actions, and evidence |
|---|---|---|---|
| Fee Plans | Accounting publishes the Program-and-Term authority | Current published plan, action-needed Drafts, upcoming Terms | Term/Program/state/search; reference, version, total, authority, readiness; `New draft`, `Continue`, `View` |
| Fee Plan detail | Accounting prepares and publishes one version | Identity/authority, charge lines, obligations, readiness, history | Visible labels for authority/date; editable ordered rows only in Draft; `Save draft`, `Publish plan`; successor action on Published |
| Student Accounts — Accounts | Accounting finds the next account decision, including `Assessment required` | Status, student/applicant, account, Program/Term, assessment basis, required/payment/coverage/due, satisfaction basis, next action | Term/state/Program/search; contextual Account Status CSV preview and export; retained history; no separate assessment or coverage resource |
| Student Accounts — Review receipts | Accounting resolves manual receipt submissions safely | Risk/reason, student/applicant and account, amount, channel/source (`channel_snapshot`), age, next action | Source/state/reason/date filters (`Receipts` / `Review receipts`); `Review`; no raw payload columns |
| Submitted receipt detail (`Compare submitted receipt`) | Accounting records the external-check result | Reason and current due, safe receipt evidence, review form, history | Shared `<x-document-viewer>` preview (`<x-payment-proof-viewer>`); actual verified amount; safe reason; optional Official Receipt (OR) number; `Reject`, `Return for review`, or exact `Verify` consequence |
| Payment channels settings | Accounting configures institutional payment instructions | Empty-by-default multi-account lists (toggling a digital channel on adds one blank entry), 10 PH bank datalist presets (`Bank Transfer`, 10–16 digits allowing spaces/hyphens), 8 PH e-wallet presets (`E-Wallets`, replacing standalone GCash), and `Cash (Campus Cashier)` location/hours/notes | Versioned save with audit metadata (`'payment_channels'` v2); drives student/applicant **"How to pay"** display and gates digital `Submit receipt` availability |
| Student Accounts — contextual output restriction | Accounting explains/records an actual restriction on the same source records | Office, reason, exact blocked output action, source and history | Contextual source-backed restriction/resolution; no TOR approval queue or generic waiver action |
| Student Account detail | Accounting understands one Term position and records only its eligible next result | Current task/status and due, dated payments and approved charges; payment versus coverage stays truthful; authority/source and prior activity remain secondary | Contextual authorized assessment, coverage, receipt verification, direct onsite/cashier payment recording (for Students and pre-student Applicants), inline OR mapping on verified posted payments missing an OR, and reversal; shared `<x-document-viewer>` file preview; named account history, SOA and Verified Payments export |
| Authorized individual assessment form | Accounting records an externally calculated exact result; it is not a calculator | Registration/change version and course/unit evidence (subject count, units, proposal), reason/authority, exact charge lines/obligations, totals, impact preview | Reconciled nonnegative rows, enrollment amount, predecessor; optional 1-click **Load charges & due dates from Published Fee Plan** pre-fill when an active Fee Plan applies (editable, no rate/formula builder) plus single-payment balance helper |
| Approved Coverage form | Accounting records only an externally approved Term Account effect | Current Assessment/obligations, category/source, authority/date, exact amount, effective date, safe description, impact preview | `Apply` only after current non-excess reconciliation; successor/reversal from history; no eligibility, application, renewal, disbursement, or accommodation workflow |
| Enrollment payment requirement | Applicant/Student completes Clinic 4 finance checkpoint | Required now, state, assessment basis/source, account ref, **"How to pay"** channels (multi-account `Bank Transfer`, multi-account `E-Wallets`, and `Cash (Campus Cashier)`), walk-in guidance, submitted receipt evidence, next action | `Submit receipt` primary CTA (shown and enabled only when a digital channel is active; omitted when onsite-only); private upload fields (`Bank Transfer`, `E-Wallets` with account selection when multiple active accounts exist); shared `<x-document-viewer>` (`<x-payment-proof-viewer>`) view/download/replace submission; no finance navigation duplication |
| Student Finance | Student understands the current payment task and can retrieve retained records; alumni read their records | Current due/no-due, next dated payment, **"How to pay"** channels (multi-account `Bank Transfer`, multi-account `E-Wallets`, `Cash (Campus Cashier)`), walk-in guidance, relevant review/recovery and recent activity; prior authority/history secondary | Current account by default; `Submit receipt` primary CTA (only when at least one digital channel is enabled; absent when onsite-only); SOA/ack. Past statements and payments appears only when older owned accounts exist: choose a term, then open its statement and payment records. Show `Submitted receipts` (`<x-document-viewer>` preview) and fee assistance only when recorded; keep earlier statements secondary. Historical reading keeps the current payment context unchanged. Alumni read-only |
| System Health | System Administrator reviews live local runtime, queue, mail, and solver health | 4-card live operational grid (`App & Database`, `Job Queue`, `Mail / SMTP`, `Scheduling Solver`), `Last checked` (`Asia/Manila`), and 1-line hosting backup/DR note | `Refresh status`, `Technical details`, `Inspect failed jobs`, `Retry failed jobs`, `Send test email` (`1/60s` self-only), and `Check solver` (`1/30s` `GET /health` probe) |
| Governance & Audit | Authorized System Administrator investigates high-value evidence | Single-line tabs (`Institutional changes`, `System events`, `Output access`, `Privacy & retention`), 5-column table (`When`, `Event`, `Who`, `Target`, `Summary`), and plain records-procedure guidance | Dropdown `Actor` (with `?actor=` deep-link binding), `Event type`, `Date range`, search, `View detail` slide-over, `Export CSV`; read-only, no compliance verdict |
| Account Statement | Owner/authorized role reads or prints account position | Identity/context, charge/activity tables, obligations, totals, disclaimer | Authenticated browser output; print/save-as-PDF and access log |
| Payment Acknowledgment | Owner/authorized role reads or prints verified posting | Posting summary, verification basis, account effect, state, disclaimer | Authenticated browser output; print/save-as-PDF and access log |

Student Finance uses one account composition across due, satisfied, covered and receipt-review states. The current amount and its next task lead; **"How to pay"** channel instructions (multi-account `Bank Transfer`, multi-account `E-Wallets` replacing standalone GCash, and `Cash (Campus Cashier)`) and walk-in guidance stay readable without requiring a receipt upload for onsite payments (and omitting student receipt upload controls altogether when only onsite Cashier is active), while later dated obligations remain separate. The latest relevant submitted-receipt state (`Receipt to review` / `Submitted receipts`) supplies a focused status and recovery instruction alongside the shared `<x-document-viewer>` preview, while earlier receipt, payment, coverage and assessment records remain reachable as history/source detail. A retained rejected receipt or coverage record does not select an older, denser layout. A zero obligation balance may be labelled satisfied; it is called paid only when the actual verified-payment source supports that description. Missing dates remain explicitly unrecorded in both the account and its statement.

Accounting reviews submitted-receipt discrepancies and payment reversals in one selected-account workspace (`Review receipts` / `Compare submitted receipt`), records onsite/cashier payments directly for both enrolled Students and pre-student Applicants, and maps Official Receipt (OR) numbers inline on verified posted payments missing an OR. The claimed payment, named target balances, discrepancy, shared private file viewer, and current financial decision lead. Registrar receives the canonical enrollment-payment consequence and Accounting recovery guidance, without private receipt files or accounting controls.

#### Deterministic ordering

- Fee Plans: current Published, action-needed Drafts, upcoming Term opening, then Program and version.
- Accounts: `Assessment required`, `ActionNeeded`/under review, nearest deadline, oldest relevant activity, then person/account reference.
- Payment Exceptions: blocking/security mismatch, oldest submission, then reference.
- Output restrictions: unresolved exact action first, then effective date and source reference; no approval queue.
- Account activity and student/applicant history: newest authoritative event first.
- SOA activity: chronological ascending.
- Audit/system events: newest first, then severity/status and reference.

#### Page-specific states

| Surface | Empty | Filtered empty | Loading/stale | Inaccessible | Failed action |
|---|---|---|---|---|---|
| Fee Plans | “No Fee Plans yet. Create a Draft for an approved Program and Term.” | Name active filters and offer `Clear filters` | Preserve list structure; stale Draft requires refresh before publish | No fee or authority details disclosed | Preserve Draft; name the readiness item to correct |
| Accounts | “No Term Accounts are available for this scope.” A zero-row export records `NoRows` and creates no file | Name query/filters and clear them | Show last as-of time; disable assessment/posting/export when source is stale | Generic unavailable page | State whether no assessment/posting/export occurred, retain normalized export scope, and preserve safe form data |
| Authorized individual assessment | Explain that the action is available only for an eligible current case | Not applicable | Preserve entered rows; stale registration/change blocks recording | Reveal no account, course, or authority detail | No Assessment is created; retain safe entered data and identify the failed readiness check |
| Approved Coverage | Explain that no externally approved coverage applies to this account | Not applicable | Preserve safe entered fields; stale Assessment/obligation disables Apply | Reveal no account, eligibility, provider, or authority detail | No coverage effect is recorded; name the missing, conflicting, stale, unreconciled, or excessive source |
| Payment Exceptions | “No payment evidence needs review.” | Name filters and clear them | Preserve queue position; revalidate before result | No evidence metadata or screenshot disclosed | Keep item under review and explain the next safe action |
| Output restrictions | “No output restrictions need Accounting action.” | Name filters and clear them | Disable result until request/source refreshes | No request, student, or applicant details disclosed | Existing result remains unchanged |
| Student Finance/Enrollment | Explain when no applicable Term Account exists | Applicable only to Term selection | Show as-of; `Unavailable` identifies missing, stale, unreconciled, or unauthorized assessment without a fallback | No account existence disclosure | Receipt submission status states exactly what was and was not recorded |
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
- Targets meet the WCAG 2.2 24×24 CSS-pixel minimum; student and applicant primary actions prefer 44×44.
- No action depends on drag, hover, background color, or a pointer. Private evidence preview has an accessible name and never appears as decorative public media.
- Print views use practical 12 mm margins and are A4 portrait unless an owning PRD expressly selects landscape. They are monochrome-safe, repeat identity and table headings, add automatic page numbers when multiple pages are produced, avoid clipped rows, retain status/disclaimers, and do not depend on navigation, remote fonts, or background color. CSVs remain downloadable data rather than printable documents.

## 13. Composition Ownership

The Clinic 6 [UI Surface Blueprint](../ui_surface_blueprint.md) owns composition for student and applicant account status, Accounting work and System Health. Apply this PRD's content, permissions and source-evidence requirements when composing each surface.

## 14. Coordinated Synthetic Data

Finance scenarios reuse the coordinated BM, IT, and THM Students, Terms, Registration Cases, Assessment sources, and official-output requests. Account references remain stable across Clinics 4–6; PRD 06 never invents academic or enrollment facts.

All examples use fake references and `example.test` identities.

| Reference | Scenario |
|---|---|
| `FP-IT-2026-T1-v1` | Published ₱48,000 plan with ₱12,000 enrollment obligation and two later ₱18,000 obligations |
| `FP-BM-2026-T1-d2` | Incomplete Draft blocked by missing authority and unreconciled obligations |
| `ACT-260001` / Ana Reyes | Applicant without StudentProfile; manual E-Wallets (GCash preset) receipt under review then verified, plus inline Official Receipt (OR) mapping |
| `ACT-260008` / Lea Cruz | Applicant without StudentProfile; direct onsite Campus Cashier payment recorded by Accounting with OR, clearing Enrollment readiness |
| `ACT-260014` / Miguel Santos | Verified underpayment with remaining current due |
| `ACT-260027` / Pia Lim | Rejected evidence and a superseding resubmission |
| `ACT-260033` / Noel Garcia | Applied student-specific scholarship coverage satisfying a nonzero enrollment obligation; successor and reversal history remain available |
| `ACT-260034` / Rosa Dela Cruz | Published Fee Plan with institutionally authorized zero enrollment obligation and `NoPaymentRequired` basis |
| `ACT-260041` / Eva Ramos | Later missed obligation while official enrollment remains valid |
| `ACT-260045` / Mira Flores | Reduced Individually Advised registration with an exact externally authorized individual assessment |
| `ACT-2026-ST-001` | `REG-2026-ST-001` under `TERM-2026-ST`: PHP 6,000 exact individual assessment for the confirmed Special Term class snapshot, PHP 3,000 required now, `COV-2026-ST-001` PHP 2,000 government subsidy, `PAY-2026-ST-001` PHP 1,000 verified payment, and `Mixed` clearance |
| `ACT-260047` / Sam Torres | Changed-registration branches for additional clearance, authoritative no-additional-cost confirmation, and Course Drop Accounting review pending |
| `TOR-260003/4/5` | No applicable OUTPUT restriction, active source-backed restriction, and resolved restriction examples; no per-request clearance decisions |
| `PAY-260009` | Reversed payment and superseded acknowledgment |
| Alumni example | Historical read-only SOAs and acknowledgments with no payment actions |
| Health example | Required mail and solver evidence; further queue/backup/other health demonstration deferred, existing history preserved |

No real student number, account number, wallet reference, receipt image, bank identifier, or production credential is used.

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
| 8 | Ana, embedded upload | Submit private receipt via `Submit receipt` (digital channels enabled) with destination `channel_snapshot` (`E-Wallets` / `Bank Transfer`) | `Under review`; no posting/email; preview via `<x-document-viewer>` | Failed upload (or onsite-only disabled digital channels) preserves safe fields and explains/requests replacement |
| 9 | Accounting, Review receipts | Verify actual external source against `channel_snapshot`, preview named obligation application, and optionally map Official Receipt (OR) inline afterward | One obligation-keyed posting, projection refresh, one email | Excess beyond named targets, mismatch, or rejection posts nothing |
| 10 | Registrar/Student | Finalize Clinic 4 enrollment | Same account gains Student identity; COR snapshot unchanged | Later due or coverage reversal does not reverse enrollment |
| 11 | Student Finance | Review **"How to pay"** channels (multi-account `Bank Transfer`, multi-account `E-Wallets`, and `Cash (Campus Cashier)`), payment status, shared `<x-document-viewer>` receipt preview, and SOA payment activity; preserve historical acknowledgment access | Same assessment basis/source versions, separate payment/coverage activity, and as-of evidence | Output failure produces no partial artifact |
| 12 | Lea and Accounting | Pre-student Applicant pays onsite at Campus Cashier; Accounting records payment directly in Student Accounts with OR | One obligation-keyed posting (`student_profile_id === null`), Finance readiness cleared, one email; Registrar finalizes enrollment | Duplicate OR/reference is rejected; no receipt upload required (and onsite-only omits upload UI) |
| 13 | Accounting | Configure **Payment channels** settings (multi-account `Bank Transfer`, multi-account `E-Wallets` replacing standalone GCash, and `Cash (Campus Cashier)`) and map OR inline on verified posted payment missing an OR | Student and applicant **"How to pay"** updates immediately and gates digital `Submit receipt` availability; mapped OR appears on Account history, SOA, and Verified Payments export | Unauthorized non-Accounting actors are denied |
| 14 | Pia | Reject and resubmit manual receipt submission (`Submit receipt`) | Rejection reason and supersession history retained | Private submitted receipt files remain protected and preview inside `<x-document-viewer>` |
| 15 | Eva | Reach later due date | Finance becomes action-needed | Login/classes/exams/enrollment remain available |
| 16 | Accounting/Registrar | Review TOR request/preview/issue with no, active and resolved OUTPUT-scoped restriction | Clinic 5 consumes the applicable office/reason/blocked-action/source/history | No global hold or per-request payment-clearance approval |
| 17 | Accounting | Preview and export current Account Status or selected-account Verified Payments | Named fixed purpose, useful columns, exact current scope/count, initiating-actor retrieval and retained history | Verify populated/empty, scope change, over-limit, stale/failure and formula-safe output without partial artifacts |
| 18 | System Administrator | Review required mail and solver evidence | Honest locally evidenced state, as-of time, owner and next action | Further queue/database/storage/backup health UI demonstration deferred; no provider controls |
| 19 | System Administrator | Open Governance & Audit | **Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.** Technical details retain the exact product boundary | No attestation, policy-approval status, or disposal action appears |
| 20 | Alumnus | Open historical Finance | Read-only outputs/history | No upload or mutation action appears |

Documentation closure does not execute this walkthrough. Later implementation acceptance must perform it in a real browser with synthetic data and verify keyboard, 360/390 mobile, 1366 desktop, and print behavior.

## 16. Lifecycle, Validation, Confirmation, and Retry Rules

| Action or record | Actor and authorization | Validation and readiness | Confirmation and audit | Lifecycle, duplicate, and failure behavior |
|---|---|---|---|---|
| Fee Plan Draft/publish/supersede | Accounting; current Program/Term (automatic `published_by` / `published_at` attribution; optional approval note/memo & date) | Plan code unique within its scope; optional approval note/memo reusable; ordered unique charge codes; nonnegative two-decimal PHP; at least one obligation; valid dates/order; charge and obligation sums reconcile; exact enrollment requirement (zero enrollment payment still requires explicit `NoPaymentRequired` approval reference); one published version per scope | **Publish plan** shows charges, obligations, affected Cases/accounts, immutability, automatic publisher attribution, and optional approval note/memo | Draft hard-delete only before publication/reference. Published plan never edits/deletes; preparing an updated draft marks the prior published plan superseded on publish. Stale/concurrent publish posts nothing |
| Authorized Individual Assessment | Accounting records externally calculated result for only the four authorized exception categories | Current Registration Case/proposal/change, exact course/unit snapshot, reason, required approval memo/date, ordered nonnegative lines, reconciled total/obligations, enrollment amount, predecessor when applicable | **Custom assessment** modal shows account, reviewed subjects, exact amounts, Clinic 4 effect, and that no formula runs | Never edited/deleted. Missing/stale/unreconciled source makes projection `Unavailable`; correction creates an immutable updated record |
| Approved Coverage apply/supersede/reverse | Accounting records external result | Current Assessment/obligation, category/source, authority/date, positive applicable amount no greater than remaining named obligation, effective date, safe description | Named confirmation shows coverage—not payment—effect, remaining requirement/later obligations, and append-only consequence | Never edits/deletes. Excess/conflict/stale authority posts nothing; changed Assessment requires revalidation/successor. Reversal may create due but never revokes enrollment or creates hold |
| Manual receipt submission/replacement (`Submit receipt`, digital channels only) | Applicant/Student for own Term Account; Accounting reviews | At least one active digital channel (`Bank Transfer` or `E-Wallets`; server guard rejects digital-disabled uploads); common private file (`Transfer receipt or screenshot`); positive claimed PHP amount; paid time no more than five minutes in the future; external reference follows the shared plain-text reference primitive and preserves issuer formatting; current account/channel + `channel_snapshot` | Submission needs no critical confirmation because it posts no payment; replacement identifies superseded evidence; view/download uses shared `<x-document-viewer>` (`<x-payment-proof-viewer>`) | A matching account/channel/reference/amount is a possible duplicate routed to review without posting. Every receipt attempt remains; no arbitrary resubmission cap while account action is available |
| Verify/reject submitted receipt, record onsite payment, or map OR | Accounting after checking real external source or collecting onsite payment | Current receipt submission (if digital), account/Assessment (Student or pre-student Applicant), actual verified/received amount, named target obligations and deterministic oldest-due-first application, external-check/OR reference, safe rejection reason | **Verify payment** / **Record onsite payment** shows actual amount, each obligation effect, account/projection/email/output effect; **Map Official Receipt** (`Map OR`) attaches a unique Official Receipt (OR) number inline on verified posted payments missing an OR; **Reject** shows student/applicant-safe reason and no posting | Atomic/idempotent obligation-keyed posting (with nullable `student_profile_id` for pre-student Applicants) preserving destination `channel_snapshot`. Underpayment may post actual amount; excess beyond named targets or mismatch stays exception. Rejection never deletes submitted receipt evidence; stale/concurrent review posts nothing |
| Payment/coverage reversal | Accounting records authorized external correction | Current posting/effect, exact unreversed obligation applications, authority, reason, amount/account, and verified external refund evidence | **Record reversal** previews only the evidenced amount and linked effects; partial evidence permits only the matching partial correction. Commit evidence and account effects atomically | Append-only; original remains. Unverified or mismatched refund evidence applies no correction. Refund/chargeback execution stays external |
| OUTPUT-scoped restriction | Accounting | Office/reason/exact blocked action/current source/history; identity and source versions current | Named confirmation shows only the actual restriction or source-backed resolution | No global hold or per-request approval; append-only history; Clinic 5 consumes read-only source |
| SOA payment activity/retained printable payment records and contextual CSV access | Authorized student/applicant or Accounting purpose as defined; CSV retrieval remains initiating-actor only | Current immutable source; exact contextual scope; fixed columns; 10,000-row limit; purpose; per-record authorization; UTF-8 BOM/comma/CRLF; typed PHP/date representation; formula-safe text; no private paths/payloads/notes | **Generate/Export** disables the `Export account status` trigger when zero term accounts match the current view and otherwise shows exact scope, row-count estimate, purpose, concise included-field summary (`PHP · Asia/Manila`), official/non-tax status where applicable, and access evidence | Output failure creates no partial/official-looking artifact. CSV zero rows records `NoRows` without a file; over-limit requires narrower filters; retry reuses normalized scope; exports audit actor, role, context/filters, purpose, row count, outcome, and time and send no email |
| System Health/Governance | System Administrator read-only | Locally evidenced fact and as-of time only | No consequential provider/control confirmation exists | Missing evidence is shown as `Not recently checked`, never healthy, with the exact source/boundary in technical details. No arbitrary-recipient email, test charge, solver run, backup, restore, command, or attestation action |
| External backup/recovery evidence | Named Infrastructure Custodian operates outside TALA; privacy/DPO authority owns provider, processor/transfer, retention, key, media, and disposal approval | Configured production objective, scoped credentials, valid manifest/integrity result, current retained generation, and isolated restore evidence | TALA records only safe local outcome/as-of/overdue/next-action evidence; no secret, personal-data payload, provider control, or media command is exposed | Failed operations alert the custodian; invalid keys or media stop recovery; the deployment runbook selects the next valid source without silently mutating live records |

Authorized Individual Assessments, account events, coverage, payment postings, reversals, clearance results, export access, and generated output history are never edited or deleted through ordinary UI. Financial mutations are transactional and idempotent, payment and coverage remain separate effects, and an inaccessible response reveals no person, account, receipt, provider, or balance fact. Automatic retention disposal is outside the MVP; the institution remains responsible for lawful privacy governance and externally controlled disposal.

## 17. Explicit Exclusions

Further Quick Tour, concurrent-Term demonstration, special TESDA-results/TOR replacement/conferral correction/INC amendment UI and health beyond mail/solver are deferred. Preserve current code/history and required whole-term CP-SAT plus the labelled synthetic competency-guard demonstration. Recovery animation remains required and is never treated as deferred.

Clinic 6 does not authorize:

- a public HTTP API or physical schema;
- generic fee precedence, automated unit-based calculation, implicit defaults, penalties, refunds, or prior-debt allocation; course/unit evidence may only accompany an exact externally calculated authorized individual result;
- scholarship, sponsorship, subsidy, grant, or financial-accommodation application/eligibility/ranking/renewal/disbursement management; TALA records only externally approved account effects;
- general ledger, double-entry accounting, cashiering, collections campaigns, budgeting, procurement, or payroll;
- Billing Slip, BIR invoice, or tax-document substitution (recording/mapping an externally issued physical Official Receipt (OR) number does not make TALA a BIR invoicing or tax-receipt generator);
- global holds or finance-controlled login, class, examination, grade, or official-enrollment reversal;
- online payment gateway checkout, webhook endpoints, browser-return confirmation, unsigned auto-posting, or raw provider payload UI;
- a Reports navigation destination, BI/dashboard product, pivot/chart builder, or legacy 27-report catalog;
- provider/server controls, shell commands, restore buttons, test payments, test solver runs, or manual assurance attestations;
- retention-policy configuration, disposal queues/scheduling, legal-hold workflows, archive centers, generic restoration, or automatic deletion;
- changing Clinic 4 COR into a live account statement; or
- changing Clinic 5 into a document-request, payment-collection, CAV, signing, sealing, or delivery system.

## 18. External and Technical Boundaries

The institution owns lawful retention schedules, privacy requests, legal holds, secure disposal, hosting/backup processor/transfer approval, recovery-key custody, optional media rotation and disaster restoration. Completed/alumni records continue in the live system with the documented read-only access until an attributable lawful retention decision applies. Disaster-recovery copies serve restoration. Accounting tax documents, cash movement, gateway/bank administration, refunds and collection activity remain external.

Production readiness is gated on institution-owned hosting and independent-backup accounts; named billing, Infrastructure Custodian, and privacy/DPO owners; approved processor and international-transfer safeguards; current hosting/backup pricing; controlled recovery-key custody; installed and monitored backup automation; current verified generations; and successful isolated restore evidence. The Architecture Specification may evaluate concrete hosting/backup providers and media, but procurement and operational proof do not create a capstone browser-acceptance or data-migration requirement and are never presented as already operating.

This PRD names conceptual product records and projections, not physical tables, routes, APIs, migrations, or task order. A later journey-complete slice must reconcile existing finance, payment, output, health, governance, authorization, UI, and test surfaces against this authority while preserving integration secrets and producer-owned version boundaries.
