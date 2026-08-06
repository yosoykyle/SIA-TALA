# PRD 06 — Accounts, Official Outputs, Operations, and Assurance

> **Clinic 6 status — Approved product authority; complete-authority review passed.** This is the canonical Clinic 6 journey authority. It defines the desired Clinic 6 product and UI contract and authorizes later implementation-task derivation only; it is not an implementation task, public API, physical-schema specification, or authority to change the application.

### 6.1 Purpose and successful journey

Clinic 6 gives an Applicant, Student, or alumnus one understandable term-account position and gives Accounting the minimum records needed to publish Fee Plans, verify payment evidence, resolve exceptions, and provide bounded payment-clearance projections to Clinics 4 and 5. It also gives the System Administrator locally evidenced health and read-only governance views without pretending that TALA controls provider infrastructure or proves institutional compliance.

The normal journey starts when Accounting publishes one authorized Fee Plan for a Program and Term. Clinic 4 creates or refreshes one continuous Term Account for a Registration Case, Clinic 6 publishes the amount required now, and the learner either has approved coverage, submits private external-payment evidence, or uses optional exact-due PayMongo checkout. A verified posting clears only the action-specific requirement it satisfies. The same account continues after official enrollment and later supports Student Finance, an Account Statement, Payment Acknowledgments, and a bounded Clinic 5 output-payment clearance.

The successful ending is:

- the Fee Plan and assessment source are versioned and reproducible;
- the learner sees the current due, next obligation, authoritative status, source, as-of time, and safe next action;
- Accounting can explain every verified posting, adjustment, reversal, and exception without editing history;
- Clinic 4 and Clinic 5 consume small read-only projections rather than a global hold;
- official-looking TALA outputs clearly state that they are not tax invoices;
- operational status distinguishes local evidence from facts TALA has not checked; and
- historical Student access remains read-only after completion or other lifecycle exit.

### 6.2 Ownership and product boundary

| Concern | Office owner | Human or external step | TALA responsibility | Product classification |
|---|---|---|---|---|
| Fee authority | Accounting | Approves the institution's Program-and-Term charges and dates outside TALA | Versioned source record and publication readiness | Source record |
| Enrollment payment requirement | Accounting, consumed by Registrar | Determines approved coverage or verifies payment source | Derived read-only projection from the Term Account | Generated read-only view |
| Bank, wallet, or cash verification | Accounting | Checks the actual external institution, bank, wallet, or cash record | Private evidence intake and recorded verification result | Manual-office result record |
| PayMongo confirmation | PayMongo plus Accounting exception ownership | Provider sends a signed event; Accounting handles mismatches or later corrections | Integration attempt, verified event, idempotent posting, and exception evidence | Integration input/output |
| Tax invoice or registered Accounting document | Accounting outside TALA | Issues the institution's BIR-compliant document through its authorized procedure | Shows a disclaimer and optional safe external reference only | External/manual decision |
| TOR or other official-output payment clearance | Accounting, consumed by Registrar | Confirms the external payment or authorized `NotRequired` result | Bounded request-specific projection | Manual-office result record |
| System operation | System Administrator and infrastructure custodian | Uses provider dashboards, host access, backup media, and restore procedures outside TALA | Displays only locally recorded application evidence | Generated read-only view |
| Retention schedule | Institution and privacy authority | Approves retention and disposal policy | Shows readiness; disables automatic disposal until approved | External/manual decision |

Clinic 6 is a narrow Student-Term Account companion. It is not a general ledger, chart of accounts, double-entry accounting, cashiering, budgeting, procurement, payroll, collections, penalty, refund, or tax-invoicing product.

### 6.3 Evidence and legacy verdicts

#### Applicable authority and reference result

- [Republic Act No. 11984](https://lawphil.net/statutes/repacts/ra2024/ra_11984_2024.html) protects covered disadvantaged learners' examination access while preserving lawful institutional collection and credential remedies. TALA therefore exposes action-specific projections and never creates a global finance block on login, classes, or examinations.
- [BIR Revenue Regulations No. 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%207-2024.pdf) treat the invoice as the primary tax document and statements or acknowledgments as supplementary documents. TALA outputs are expressly non-tax; Accounting retains tax-document authority.
- The [Data Privacy Act IRR](https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/) and [NPC Circular No. 2023-06](https://privacy.gov.ph/wp-content/uploads/2024/05/2023-compendium-2.pdf) require proportionate security, controlled access, continuity, backup, restoration, remedial-time planning, and policy-governed retention. They do not supply a Servitech retention schedule, so TALA cannot invent disposal periods.
- [PayMongo webhook documentation](https://docs.paymongo.com/reference/webhook-resource) confirms signed event delivery, retries, and the possibility that repeatedly failing delivery becomes unavailable. TALA therefore keeps browser return non-authoritative and provides the bounded Accounting reconciliation path without adding provider controls.
- Mature PeopleSoft account-summary, amount-due, activity, and payment-history patterns support the selected summary-first learner view. They do not justify adopting a full Student Financials or cashiering suite.
- The local Academico reference contains broad invoice/payment records and responsive list/detail patterns. Only presentation patterns are relevant; its invoicing domain does not define TALA policy.

#### Bounded salvage classification

| Verdict | Clinic 6 disposition |
|---|---|
| `Retain` | Append-only/versioned assessment and account-event foundations, private files and output access evidence, Laravel policies, signed webhook verification, provider idempotency, queued delivery, operational events, and authenticated print foundations when later conformance is proven. |
| `Simplify` | Ledger presentation into understandable Term Account activity; integration status into locally evidenced health; broad reporting into two contextual exports; payment correction into append-only adjustment/reversal evidence. |
| `Replace` | Generic `FeeRule` precedence with one published Program-and-Term Fee Plan; Enrollment/StudentProfile-only account ownership with same-human-subject plus Registration Case continuity; manual confirmation with private submission and external verification; silent 20% fallback with an explicit enrollment obligation; fragmented finance UI with the Clinic 6 workbenches. |
| `Remove after dependency migration` | Billing Slip, OR mapping, prior-debt allocation, generic financial-accommodation engine, full cashiering/refund behavior, the 27-report catalog, global holds, automatic disposal product, and provider-recovery console behavior. |
| `Quarantine` | Existing finance tables, columns, models, services, resources, routes, seeders, and tests remain untouched until a later separately authorized vertical task maps every consumer. |

File presence, migrations, tests, or demo data do not approve the legacy behavior.

### 6.4 Authoritative conceptual contract

These are conceptual product records and projections. They are not approved table, class, route, or API names.

#### Fee Plan

One `FeePlan` exists per Program and Term version.

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

#### Term Account and assessment

One conceptual `TermAccount` is anchored to the same human subject, `RegistrationCase`, and Term. `Person` is only a cross-document continuity label; it does not introduce a universal Person master record, table, profile, sign-in identifier, or separate UI.

- It may exist before official enrollment, a StudentProfile, or a student number.
- After Clinic 4 finalizes first enrollment, the same account gains the official Student reference. It is never copied or replaced.
- One immutable `AssessmentVersion` records the exact Fee Plan version, Registration Case, confirmed selection/proposal source, charge lines, obligations, totals, creation authority, and as-of time.
- An authorized changed assessment creates a successor version and linked adjustment evidence; it does not rewrite the prior version.
- Account activity is append-only: assessment charges, verified payments, approved coverage, authorized adjustments, and reversals.
- The Account Statement can reproduce the position from those authoritative events without presenting a general-ledger model.

`Current due` is the sum of applicable obligations due through the as-of time, including the enrollment obligation while the Clinic 4 finance checkpoint is pending, minus verified payments and approved coverage applicable to this Term, floored at zero.

#### Payment evidence

| Field | Required meaning |
|---|---|
| Evidence reference | Stable learner-visible and staff-searchable reference |
| Term Account | Exact owner and Term context |
| Submitter and submitted time | Authenticated Applicant/Student identity and timestamp |
| Channel | Approved external channel such as bank or GCash |
| Claimed amount and paid time | Learner's claim; not yet authoritative payment |
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
| Arrangement or coverage | Approved authority/reference or none |
| Amount required now | Enrollment obligation applicable to finalization |
| Verified applicable amount | Posted payment/coverage usable for that obligation |
| Remaining required now | Nonnegative difference |
| State | `Cleared`, `ActionNeeded`, or `Unavailable` |
| Basis | `VerifiedPayment`, `ApprovedCoverage`, `NoPaymentRequired`, or `None` |
| Source and as-of | Fee Plan, Assessment, posting/coverage references, and calculation time |
| Later obligation | Whether an amount remains due after enrollment |
| Account link | Authorized contextual Student Account destination |

Clinic 4 consumes only this projection. `Unavailable` means a required source is missing, invalid, or stale and blocks finalization. `Cleared` means the current enrollment obligation is satisfied, not that the lifetime or Term balance is zero. A later missed obligation never reverses official enrollment or blocks login, classes, or examinations.

#### Clinic 5 official-output clearance

`OfficialOutputPaymentClearance` has exactly three states: `Cleared`, `NotRequired`, and `ActionNeeded`.

It is keyed to one official-output request or issuance reference and includes the learner, output type, required amount when supplied by Accounting policy, verified external-payment reference or `NotRequired` authority/reason, responsible Accounting actor, source, and as-of time. Clinic 5 reads it without editing it. Request intake, collection, CAV, signature, seal, claiming, courier, diploma, and ceremony work remain external.

### 6.5 Readiness contract

| Check | Authoritative source | Owner | Valid condition | Effect if missing or invalid | Consuming action | Recovery |
|---|---|---|---|---|---|---|
| Fee authority | Fee Plan authority reference/date | Accounting | Complete and applicable to the Program and Term | Hard blocker | Publish Fee Plan | Correct the Draft authority fields |
| Charge reconciliation | Fee Plan charge lines and total | Accounting | Nonnegative lines sum exactly to total | Hard blocker | Publish Fee Plan | Correct charge lines |
| Obligation reconciliation | Fee Plan obligations | Accounting | At least one row; ordered dates; rows sum to total; enrollment amount matches first obligation | Hard blocker | Publish Fee Plan | Correct obligations |
| Unique current plan | Published Fee Plans | Accounting | No competing Published version for Program and Term | Hard blocker | Publish Fee Plan | Supersede through the controlled successor action |
| Registration source | Clinic 4 Registration Case and proposal/selection source | Registrar | Same credential/Applicant-or-Student continuity, Program, Term, and current authoritative version | Hard blocker | Create/refresh Assessment | Correct Clinic 4 source |
| Assessment source | Published Fee Plan plus Registration Case | Accounting/Registrar | Both current and mutually consistent | Hard blocker | Publish enrollment projection | Refresh the Assessment version |
| Payment claim | Private evidence metadata/file | Applicant/Student | Complete, readable, authorized, and within allowed file constraints | Hard blocker for submission only | Submit evidence | Correct fields or replace file |
| External payment verification | Real bank/wallet/institutional source | Accounting | Exact owner/context, amount, reference, and no unresolved conflict | Hard blocker for posting | Verify posting | Reject, resubmit, or route to exception |
| PayMongo local readiness | Environment configuration and local callback route | System Administrator | Required configuration is present without exposing secrets | Degraded integration | Start checkout | Restore configuration; manual evidence remains available |
| PayMongo event | Signed provider event and matching attempt | Integration/Accounting | Signature, account, amount, currency, reference, and idempotency all match | Exception, never automatic posting | Confirm PayMongo payment | Accounting reviews safe evidence |
| Output source | Current Term Account and authorized requester | Owning role | Source is available, current, and accessible | Hard blocker for output | Generate SOA/acknowledgment/export | Refresh source or correct authorization |
| Retention schedule | Approved institutional schedule | Institution/privacy owner | Approved scope, periods, authority, and review date exist | Automatic disposal disabled | Any disposal process | Approve policy outside TALA, then reopen authority |

Passed readiness rows remain collapsed. Every failed result names the owner, source, effect, and safe next action. Missing PayMongo disables only checkout; missing SMTP never reverses a payment posting.

### 6.6 Consolidated State and Action Matrix

| State or projection | Trigger/action | Actor and authorization | Guards | Resulting record/effect | Irreversible or superseding behavior | Cross-role projection |
|---|---|---|---|---|---|---|
| Fee Plan `Draft` | Create or edit Draft | Accounting with Fee Plan manage authority | Program and Term exist | Saved Draft and audit evidence | No financial effect | Readiness may show incomplete |
| Fee Plan `Published` | Publish plan | Accounting publisher | All Fee Plan readiness checks pass | Immutable published version | Cannot be edited; later version supersedes | Clinic 4 may create Assessment |
| Fee Plan `Superseded` | Publish successor | Accounting publisher | Existing Published plan and valid successor | Predecessor linked to successor | Historical version retained | Existing Assessments keep their original source |
| Assessment current | Create/refresh assessment | Authorized Clinic 4/Accounting workflow | Published plan and current Registration Case | Immutable Assessment version and activity | Later change creates successor | Learner and Clinic 4 see same source version |
| Projection `Unavailable` | Required source becomes missing/stale | System-derived | Source/readiness failure | Read-only unavailable result | No silent fallback | Clinic 4 cannot finalize |
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

### 6.7 Chronological journeys and failure paths

#### Publish a Fee Plan

1. Accounting selects Program and Term and creates a Draft.
2. Accounting records institutional authority, ordered charge lines, and obligations.
3. Readiness explains incomplete authority, totals, dates, or competing plan.
4. Accounting reviews the exact publication impact and publishes.
5. TALA freezes the version and records the publisher and time.
6. A correction uses a successor Draft; existing Assessment versions continue to cite the original source.

No missing value is inferred from a global rule, per-unit rate, or percentage fallback.

#### Manual external-payment evidence

1. The Applicant or Student opens the owning Enrollment or Finance context.
2. TALA shows the exact Term Account, current due, responsible office, and safe instructions.
3. The learner supplies channel, claimed amount, paid time, external reference, and a private screenshot.
4. TALA validates and stores the submission without posting payment or emailing.
5. Accounting reviews the oldest/highest-risk exception first and checks the real external source.
6. Accounting verifies the actual amount, rejects with a safe reason, or leaves the item under review.
7. Verification posts once, refreshes projections, generates acknowledgment eligibility, and queues exactly one verified-payment email.

Unreadable, wrong-account, duplicate-reference, mismatched, over-obligation, and conflicting submissions never post automatically. A failed upload preserves entered non-file fields where safe and clearly requests a new file. A failed posting states whether nothing was posted and preserves the review evidence.

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

### 6.8 Email authority

Clinic 6 owns exactly one email event.

| Trigger | Recipient | Safe contents | Source and idempotency | Delivery failure | Excluded notifications |
|---|---|---|---|---|---|
| Verified payment posted | Owning Applicant or Student email account | Amount, Term Account reference, posting date, secure portal link, and statement that the TALA acknowledgment is not a tax invoice | Payment Posting reference | Posting remains valid; failure is recorded for authorized resend/follow-up | Proof submission/rejection, reminder, checkout return, exception, TOR clearance, reversal, health, export, or routine activity |

### 6.9 Official outputs, exports, and audit evidence

#### Account Statement / Statement of Account

The authenticated, non-tax Account Statement contains:

- institution identity and copy context;
- person and Term Account reference;
- Program and Term;
- Fee Plan and Assessment version references;
- ordered charge lines;
- chronological verified payments, approved coverage, adjustments, and reversals;
- obligation schedule;
- current due and remaining Term balance as of generation time;
- output reference and generation time; and
- a clear statement that it is not a BIR invoice or official tax document.

#### Payment Acknowledgment

One authenticated, non-tax acknowledgment is available per verified Payment Posting and contains payment/account references, amount, date, channel, masked external reference, verification basis, account effect, current state, generation reference/time, and the non-tax disclaimer. A reversed payment remains discoverable and its acknowledgment is visibly `Reversed` or `Superseded`.

#### Contextual CSV exports

| Export | Allowed columns |
|---|---|
| Account Status CSV | Account reference, safe person reference, Program, Term, assessment total, required-now amount, verified applicable amount, current due, projection state, basis, source version, as-of time |
| Verified Payments CSV | Payment reference, account reference, safe person reference, Term, amount, channel, masked external reference, posted time, verification basis, current state |

Exports are contextual actions, not a Reports navigation page. Sensitive export requires purpose and records actor, role, normalized filters, purpose, row count, outcome, and time. CSV values are allowlisted, formula-safe, and stable for Excel import. Private proof paths, raw provider data, bank details, secrets, and internal notes are excluded.

#### Required audit evidence

Audit covers Fee Plan creation/publication/supersession; Assessment creation/supersession; evidence submission/review; posting and idempotent duplicate outcome; exception resolution; adjustment/reversal; clearance result; output access; export purpose/outcome; email delivery result; integration result; and locally recorded backup/restore evidence. Audit views do not expose secrets or private screenshots.

### 6.10 System Health, governance, privacy, and recovery

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
4. Privacy and Retention Readiness

The page supports authorized search and fixed filters over safe audit fields. It is not a compliance attestation, policy editor, incident-response suite, or report designer.

#### Retention boundary

The institutional retention schedule is `Not approved`. Automatic disposal and disposal-candidate queues are disabled. TALA does not invent periods for applications, evidence, account events, outputs, audits, or academic records. Historical and official academic records remain accessible to authorized staff, and former Students/alumni retain read-only access to their authorized history. When an approved schedule exists, product authority must be reopened before any automated disposal behavior is planned.

#### Deployment and recovery authority

- Selected MVP host direction: self-managed [Hostinger KVM 1 VPS](https://www.hostinger.com/ph/vps-hosting), subject to procurement-time specification and price recheck.
- Independent encrypted off-server object storage holds automated database and private-file backup generations; the provider's weekly VPS backup is supplemental only.
- Backup automation must create a recoverable point no older than six hours to meet the accepted RPO.
- Accepted RTO is eight hours from declared recovery start to restored priority service, subject to tested procedures and available infrastructure.
- The client-owned ORICO 9548U3 with independently encrypted rotation drives supplies an additional business-day offline copy, checksum verification, separate custody, and at least quarterly restore evidence.
- Recovery procedures, keys, provider access, physical custody, and restoration remain external operational responsibilities. TALA may display only locally recorded results.

### 6.11 UI interaction contract

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
| Student Accounts — Accounts | Accounting finds the next account decision | Status, learner, account, Program/Term, assessment, required/verified/due, next action | Term/state/Program/search; contextual Account Status CSV |
| Student Accounts — Payment Exceptions | Accounting resolves manual/provider evidence safely | Risk/reason, learner/account, amount, channel/source, age, next action | Source/state/reason/date filters; `Review`; no raw payload columns |
| Payment Exception detail | Accounting records the external-check result | Reason and current due, safe evidence, review form, history | Private preview; actual verified amount; safe reason; `Reject evidence`, `Return for review`, or exact `Verify` consequence |
| Student Accounts — TOR Clearance | Accounting records a request-specific result | Action-needed request, learner, output ref, due/reference, source | State/date/search; `Record cleared` or `Record not required`; no generic hold action |
| Student Account detail | Accounting explains one Term position | Current status and due, next obligation/action, projection, then evidence tabs | Assessment/Payments/Evidence/Outputs/Audit; record verified external payment, generate SOA, contextual export, authorized reversal |
| Enrollment payment requirement | Applicant/Student completes Clinic 4 finance checkpoint | Required now, state, account ref, submitted evidence, next action | Private upload fields; view/replace submission; no finance navigation duplication |
| Student Finance | Student or alumnus understands current/historical account | Current due/status, next obligation, actions, recent activity, outputs | Term selector; exact checkout, submit evidence, download SOA/ack; alumni read-only |
| System Health | System Administrator distinguishes evidence from unknowns | Evidence time, service rows, safe next step | Status/filter/search; local refresh; optional self-test email only |
| Governance & Audit | Authorized System Administrator investigates high-value evidence | Selected tab, filters, newest events, retention state | Actor/type/date/search; read-only detail; no manual attestation |
| Account Statement | Owner/authorized role reads or prints account position | Identity/context, charge/activity tables, obligations, totals, disclaimer | Authenticated browser output; print/save-as-PDF and access log |
| Payment Acknowledgment | Owner/authorized role reads or prints verified posting | Posting summary, verification basis, account effect, state, disclaimer | Authenticated browser output; print/save-as-PDF and access log |

#### Deterministic ordering

- Fee Plans: current Published, action-needed Drafts, upcoming Term opening, then Program and version.
- Accounts: `ActionNeeded`/under review, nearest deadline, oldest relevant activity, then person/account reference.
- Payment Exceptions: blocking/security mismatch, oldest submission, then reference.
- TOR Clearance: `ActionNeeded`, nearest required date, request date, then request reference.
- Account activity and learner history: newest authoritative event first.
- SOA activity: chronological ascending.
- Audit/system events: newest first, then severity/status and reference.

#### Page-specific states

| Surface | Empty | Filtered empty | Loading/stale | Inaccessible | Failed action |
|---|---|---|---|---|---|
| Fee Plans | “No Fee Plans yet. Create a Draft for an approved Program and Term.” | Name active filters and offer `Clear filters` | Preserve list structure; stale Draft requires refresh before publish | No fee or authority details disclosed | Preserve Draft; name the readiness item to correct |
| Accounts | “No Term Accounts are available for this scope.” | Name query/filters and clear them | Show last as-of time; disable posting/export when source is stale | Generic unavailable page | State whether no posting occurred and preserve safe form data |
| Payment Exceptions | “No payment evidence needs review.” | Name filters and clear them | Preserve queue position; revalidate before result | No evidence metadata or screenshot disclosed | Keep item under review and explain the next safe action |
| TOR Clearance | “No output clearances need Accounting action.” | Name filters and clear them | Disable result until request/source refreshes | No request or learner details disclosed | Existing result remains unchanged |
| Student Finance/Enrollment | Explain when no applicable Term Account exists | Applicable only to Term selection | Show as-of; `Unavailable` identifies Accounting setup/source without a fallback | No account existence disclosure | Submission/checkout status states exactly what was and was not recorded |
| System Health | “No local evidence has been recorded.” | Name filters and clear them | Show capture time and retain prior evidence as stale, never healthy | Generic unavailable page | Failed refresh does not overwrite prior evidence |
| Governance & Audit | Explain that no authorized events match the tab | Name filters and clear them | Preserve last capture; read-only | No event existence disclosure | Search/export failure changes no evidence |
| Outputs | No eligible source means no output | Not applicable | Stale source blocks generation | No output reference disclosed | No partial or official-looking artifact is produced |

### 6.12 Responsive, print, accessibility, and keyboard behavior

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

### 6.13 Accepted layout decisions

| Area | Selected | Rejected alternatives | Reason |
|---|---|---|---|
| Account/payment status | Summary-first status page | Ledger-first account page; payment Wizard | Learners first need due/status/next action, not accounting mechanics or unnecessary steps |
| Accounting navigation | Fee Plans plus one tabbed Student Accounts workbench | Peer Resources; dashboard/report hub | Preserves the two office tasks and contextual evidence without exposing schema inventory |
| System assurance | Locally evidenced status with `Not checked by TALA` | Provider operations console; manual attestation checklist | Prevents unsupported claims and dangerous infrastructure controls |

### 6.14 Synthetic demonstration data

All examples use fake references and `example.test` identities.

| Reference | Scenario |
|---|---|
| `FP-BSIT-2026-T1-v1` | Published ₱48,000 plan with ₱12,000 enrollment obligation and two later ₱18,000 obligations |
| `FP-BSA-2026-T1-d2` | Incomplete Draft blocked by missing authority and unreconciled obligations |
| `ACT-260001` / Ana Reyes | Applicant without StudentProfile; manual GCash evidence under review then verified |
| `ACT-260008` / Lea Cruz | Exact PayMongo success and duplicate webhook delivery |
| `ACT-260014` / Miguel Santos | Verified underpayment with remaining current due |
| `ACT-260021` / Jo Santos | PayMongo amount mismatch routed to exception |
| `ACT-260027` / Pia Lim | Rejected evidence and a superseding resubmission |
| `ACT-260033` / Noel Garcia | Approved learner-specific coverage satisfying a nonzero enrollment obligation |
| `ACT-260034` / Rosa Dela Cruz | Published Fee Plan with institutionally authorized zero enrollment obligation and `NoPaymentRequired` basis |
| `ACT-260039` / Kai Mendoza | Exact checkout with no webhook, verified external reconciliation, then a late matching event that is a no-op |
| `ACT-260041` / Eva Ramos | Later missed obligation while official enrollment remains valid |
| `TOR-260003/4/5` | `Cleared`, `NotRequired`, and `ActionNeeded` output clearances |
| `PAY-260009` | Reversed payment and superseded acknowledgment |
| Alumni example | Historical read-only SOAs and acknowledgments with no payment actions |
| Health example | Queue failure, successful local backup job, no approved retention schedule, external backups not checked |

No real student number, account number, wallet reference, proof image, provider identifier, or production credential is used.

### 6.15 Browser acceptance walkthrough

| Step | Persona and entry | Action and visible evidence | Cross-role/output result | Failure branch and pass condition |
|---|---|---|---|---|
| 1 | Accounting, Fee Plans | Open incomplete BSA Draft | Readiness names missing authority/reconciliation | Publish remains unavailable; no fallback |
| 2 | Accounting, valid BSIT Draft | Publish after impact review | Immutable plan/version/audit visible | Competing/stale plan is rejected |
| 3 | Ana, Clinic 4 Enrollment | Open payment requirement before Student creation | Same human-subject/RegistrationCase account and due visible | Missing assessment shows `Unavailable` |
| 4 | Ana, embedded upload | Submit private GCash evidence | `Under review`; no posting/email | Failed upload preserves safe fields and requests replacement |
| 5 | Accounting, Payment Exceptions | Verify actual external source | One posting, projection refresh, one email | Mismatch/rejection posts nothing |
| 6 | Registrar/Student | Finalize Clinic 4 enrollment | Same account gains Student identity; COR snapshot unchanged | Later due does not reverse enrollment |
| 7 | Student Finance | Review status, SOA, acknowledgment | Same source versions and as-of evidence | Output failure produces no partial artifact |
| 8 | Lea, exact checkout | Return from browser, then receive signed event | Pending until webhook; one confirmed posting | Duplicate event is idempotent |
| 9 | Kai, exact checkout with missing webhook | Remain pending; Accounting checks the real provider source and records verified external payment | One posting linked to attempt/reference and one email | A later matching signed event creates no duplicate posting or email |
| 10 | Jo and Pia | Review mismatch; reject/resubmit evidence | Exceptions and supersession retained | Raw provider/private data remains hidden |
| 11 | Eva | Reach later due date | Finance becomes action-needed | Login/classes/exams/enrollment remain available |
| 12 | Accounting/Registrar | Resolve three TOR examples | Clinic 5 sees only request-specific projection | No global hold or TOR workflow appears |
| 13 | Accounting | Generate two contextual CSVs | Purpose and output-access audit recorded | Disallowed fields never export |
| 14 | System Administrator | Open System Health | Local evidence differs from `Not checked by TALA` | Unknown never appears green/available |
| 15 | System Administrator | Open Governance & Audit | Retention `Not approved`; disposal disabled | No attestation or disposal action appears |
| 16 | Alumnus | Open historical Finance | Read-only outputs/history | No checkout, upload, or mutation action appears |

Documentation closure does not execute this walkthrough. Later implementation acceptance must perform it in a real browser with synthetic data and verify keyboard, 360/390 mobile, 1366 desktop, and print behavior.

### 6.16 Explicit exclusions

Clinic 6 does not authorize:

- a public HTTP API or physical schema;
- generic fee precedence, unit-based calculation, implicit defaults, penalties, refunds, or prior-debt allocation;
- general ledger, double-entry accounting, cashiering, collections campaigns, budgeting, procurement, or payroll;
- Billing Slip, Official Receipt, BIR invoice, OR number, or tax-document substitution;
- global holds or finance-controlled login, class, examination, grade, or official-enrollment reversal;
- arbitrary payment amount checkout, browser-return confirmation, unsigned auto-posting, or raw provider payload UI;
- a Reports navigation destination, BI/dashboard product, pivot/chart builder, or legacy 27-report catalog;
- provider/server controls, shell commands, restore buttons, test payments, test solver runs, or manual assurance attestations;
- retention periods, disposal queues, or automatic deletion before approved institutional policy;
- changing Clinic 4 COR into a live account statement; or
- changing Clinic 5 into a document-request, payment-collection, CAV, signing, sealing, or delivery system.

### 6.17 Clinic approval and next boundary

Clinic 6 satisfies the complete-clinic documentation contract when this PRD, the Clinic 6 UI authority, baseline summary, PRD index, and architecture boundary agree; every primary page has a direct or explicitly shared low-fidelity wireframe; the nineteen clinic checklist items are present; and the contradiction search and documentation diff pass.

Clinic 6 has passed the final cross-module review and the complete authority set is approved for implementation-task derivation. Any later Accounts/Payments/Operations task must be journey-complete and separately planned and authorized. This approval does not authorize application/schema change, tracker/Linear mutation, commit, push, PR, or deployment.
