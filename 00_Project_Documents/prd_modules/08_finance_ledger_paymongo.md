## 8. Finance, Ledger, & PayMongo Subsystem

---

### 8.1. Finance and Ledger

Rules:

1. Assessment, payment evidence, ledger, balance, SOA, and payment acknowledgement are separate records.
2. Ledger corrections use adjustment or reversal entries.
3. Posted ledger entries preserve the original source reference.
4. Balance must be reproducible from posted ledger entries.
5. SOA must derive from assessment and ledger.
6. Payment acknowledgement must derive from verified payment evidence and ledger posting.
7. Official tax receipt issuance happens through the institution's cashier/accounting process.
8. Enrollment Finance Gate readiness derives from posted ledger payment for the required enrollment amount, or from an active Financial Accommodation with an explicit enrollment effect.

---

### 8.2. Ledger Direction Convention

Direction rules:

1. Charge increases balance.
2. Penalty increases balance.
3. Payment decreases balance.
4. Discount decreases balance.
5. Scholarship decreases balance.
6. Waiver decreases balance.
7. Refund increases balance unless separately represented as cash-out with linked reversal policy.
8. Adjustment may increase or decrease balance depending on adjustment type.
9. Reversal negates a specific prior ledger entry.

Every ledger entry must link to one source record:

1. Assessment
2. Payment Evidence
3. Discount / Scholarship
4. Adjustment Request
5. Reversal Request
6. Drop / Withdrawal Record
7. Program Shift Record
8. Manual Correction Record

Rules:

1. Posted ledger corrections use adjustment or reversal.
2. Each correction records actor, reason, authority, and source reference.
3. Balance must be reproducible from posted ledger entries.
4. Voided or reversed entries must remain visible to authorized Accounting users.
5. Ledger exports must include entry direction and source reference.

---

### 8.3. Assessment and Fee Rules

Assessment is generated before payment.

Assessment states:

1. Draft
2. Pending Review
3. Active
4. Superseded
5. Cancelled
6. Locked

Assessment recalculation or adjustment must occur when finance-relevant enrollment data changes.

Trigger events:

1. New enrollment.
2. Section transfer with fee impact.
3. Program shift.
4. Subject add or drop.
5. Withdrawal or institution-directed term drop.
6. Delivery modality change with fee impact.
7. Laboratory subject added or removed.
8. Scholarship applied or removed.
9. Discount applied or removed.
10. Financial Accommodation activated, fulfilled, defaulted, expired, or cancelled.
11. Summer or other Special Offering enrollment.
12. Correction of fee rule.

Rules:

1. If assessment is still draft, recalculation may update the draft.
2. If assessment is active or locked, change must create supersession or ledger adjustment.
3. Fee impact must be auditable.
4. Student-facing balance must reflect latest posted ledger state.
5. COR eligibility must recheck finance gate after material finance changes.

---

### 8.4. MVP Fee Configuration and Defaults

Accounting maintains fee setup through one Fee Rules Editable Table and its Record Form. Each fee rule contains:

1. Fee code and name.
2. Program and Term scope, where either scope may be left global for ordinary charge rules.
3. Calculation type: fixed amount, per-unit peso amount, or manual charge.
4. Fixed amount or per-unit rate in PHP, recorded to two decimal places.
5. Effective dates and active status.
6. Ledger category and SOA/COR display category.
7. Authority.

When more than one active rule has the same fee code, TALA selects one rule in this order:

1. Exact Program and exact Term.
2. Global Program and exact Term.
3. Exact Program and global Term.
4. Global Program and global Term.

Within the selected scope, the newest effective date applies. The newest record ID resolves an otherwise equal priority.

TALA generates assessments from the active fee setup and records corrections through ledger adjustment or reversal.

#### 8.4.1 Downpayment

1. Downpayment defaults to ₱1,000–₱2,000 depending on program.
2. Accounting configures the downpayment as an active exact Program-and-Term fee rule.
3. Draft assessment generation remains available while configuration is incomplete, with no required downpayment derived from broader scopes.
4. Assessment activation requires the active exact Program-and-Term downpayment rule.
5. Downpayment is non-refundable by default once paid, subject to institutional policy.

#### 8.4.2 Late Enrollment Fee

1. Default late enrollment fee is ₱500.
2. The rule must be configurable.
3. Fee posts as a ledger charge.
4. Fee appears in SOA.

#### 8.4.3 Delayed Payment Penalty

1. **One-Time Late Payment Surcharge:** A delayed payment of a scheduled installment (e.g., Midterm or Final installment) incurs a one-time late payment penalty of 5% calculated on the specific unpaid installment amount. The surcharge is not compounded daily.
2. **Accounting Posting:** Accounting records the approved surcharge through the manual charge form after confirming the affected installment, amount, reason, and authority.
3. **Audit Labeling:** The ledger charge is labeled by installment or due schedule reference (e.g., "Late Penalty - Midterm Installment") to support Accounting and daily turnover review.
4. **Configuration:** The penalty percentage rate is configurable by Accounting.
5. **RA 11984 Compliance:** Late payment penalties appear as ledger charges in the student's SOA while exam access remains governed by institutional law and policy.

#### 8.4.4 Shift / Schedule / Program Change Fee

1. Default fee is ₱100 for student-requested shifting of schedule, program, section, or replacement / loss of card.
2. This applies only if the change is student-requested or student-caused.
3. Institution-caused changes are recorded with a zero-charge finance effect.
4. Fee posts as a ledger charge.

#### 8.4.5 Dropout Fee

1. An approved Withdrawal or institution-directed term drop appends a flat ₱3,500.00 dropout fee to the ledger when institutional policy applies it.
2. Dropout fee is added to the existing ledger balance.
3. Dropped students with unpaid balance remain under finance or record-release hold.
4. Dropout fee must appear in SOA.

#### 8.4.6 Refund Rule

1. Admission / Enrollment Fee is refundable only within 15 calendar days from payment or OR date.
2. Tuition fee is non-refundable once the student is marked Officially Enrolled.
3. Refund processing remains Accounting-controlled.
4. Refund must use ledger reversal or adjustment.
5. Refund records preserve the original payment evidence and show the reversal or adjustment trail.

---

### 8.5. PayMongo Payment Evidence

PayMongo creates verified payment evidence. TALA ledger posting updates the student's balance.

PayMongo flow:

Registrar confirms section placement and Enrollment Seat Reservation → assessment is created → student starts payment → TALA creates PayMongo checkout or payment intent with TALA reference → PayMongo sends webhook → TALA verifies event → payment evidence is recorded → Accounting confirms or auto-confirms based on policy → ledger entry posts → balance and clearance update.

Auto-confirm PayMongo when all validation checks pass:

1. Webhook is verified.
2. Status is paid.
3. Amount matches.
4. Currency is PHP.
5. TALA reference matches.
6. The TALA payment reference has no posted ledger entry yet.
7. Risk and mismatch checks are clear.

Accounting review is required for:

1. Amount mismatch.
2. Reference mismatch.
3. Duplicate event.
4. Unknown reference.
5. Partial-payment ambiguity.
6. Refund or reversal.
7. Delayed or missing webhook.
8. Student payment claim without verified event.

Rules:

1. Verified webhook evidence or Accounting manual entry is the source for ledger posting.
2. Webhook processing is idempotent.
3. Duplicate events link to the existing payment evidence and posted ledger entry.
4. Raw webhook payloads are retained as operational or audit records according to retention policy.
5. **Paper OR Mapping:** TALA supports receipt mapping for physical paper Official Receipts (OR) across cash and online payments. When PayMongo webhooks verify an online payment, TALA posts the ledger entry to update the student's balance. This entry appears as "Pending OR Mapping" in the Accounting Workspace. The Accounting Recorder writes the physical paper OR, photographs it for the student, and encodes the OR Number directly onto the existing TALA payment record.
6. Section capacity remains controlled by the Registrar-confirmed Enrollment Seat Reservation.
7. Finance Gate readiness updates after the required ledger posting or active Financial Accommodation effect.
8. Pending payment evidence review stays in Accounting review until verified.
9. Pending OR mapping stays in Accounting reconciliation after required ledger posting unless the institution configures a separate enrollment-blocking hold for that condition.

---

### 8.5.1 Payment Evidence, Ledger Posting, and OR Mapping Sequence

V1 finance flow must follow this sequence:

1. **Billing Slip:** Student generates an internal billing slip from an active assessment.
2. **Payment Evidence:** PayMongo webhook or Accounting manual entry creates verified payment evidence.
3. **Ledger Posting:** Accounting or policy-approved auto-confirm posts a ledger entry from verified payment evidence.
4. **OR Mapping:** Accounting later maps the physical paper OR number to the existing payment evidence record (not the ledger entries).
5. **Payment Acknowledgement:** Student can view payment acknowledgement after verified payment evidence and ledger posting. The acknowledgement shows the OR number only if it has already been mapped.

Rules:

1. OR mapping links the physical receipt reference to an existing payment or ledger entry for reconciliation.
2. Payment acknowledgement is labeled as an internal payment acknowledgement.
3. Verified webhook evidence or Accounting manual payment entry creates payment evidence before ledger posting.
4. Finance Gate readiness may update after ledger posting. OR mapping remains Accounting reconciliation unless configured as a separate hold.

---

### 8.6. Manual Official Receipt Boundary & Digital Billing Slips

The institutional workflow uses manual, physical paper Official Receipts (OR). The cashier issues official receipts, while TALA bridges the digital ledger with the physical cashier:

1. **Digital Billing Slip Generation:** In the Student Hub, students can generate and print (or display on a mobile screen) a clean, lightweight **Digital Billing Slip** for their due payments (e.g., Downpayment, Midterm, Final Installment).
2. **Billing Slip Fields:** The billing slip displays the Student Number, Student Name, the target Term, the specific Installment Category, the exact Amount Due, and a simple reference text or internal machine-readable reference. Any QR-style reference is only for internal billing lookup and is not a public artifact verification workflow.
3. **Cashier Processing:** The student presents the billing slip to the Accounting Cashier. The Cashier processes the cash payment, writes the manual paper OR, and hands the OR to the student.
4. **TALA Payment Entry:** Accounting staff manually encodes the OR reference number and final paid amount into TALA's payments table. This posts the required ledger entries, updates the student's financial clearance, and lifts any applicable holds.
5. **Lump-Sum OR Allocation:** When encoding a physical OR, Accounting enters the singular OR Number and the Total Cash Paid once. The system allows distributing this total amount across distinct unpaid ledger lines (e.g., allocating a portion to old historical debt and the rest to a new term's downpayment). These ledger lines are stored separately for audit transparency but remain linked to the same unique Payment record (which holds the OR Number), preventing duplicate OR database blockages while supporting daily cash turnover audits.

Receipt boundaries:

1. The cashier issues official tax receipts.
2. TALA records payment evidence, ledger impact, billing slips, and payment acknowledgements.
3. Payment acknowledgements are internal billing verification documents.
4. Official receipt printing remains a cashier process.

Payment acknowledgements and billing slips must clearly state:
“This document is for internal billing verification only and is not an official tax receipt.”

---

### 8.7. Financial Accommodation and Promissory-Note Record

Financial Accommodation uses a recorded-result workflow. The institution reviews and approves the accommodation through its authorized office procedure; Accounting records the approved result in TALA through the Accounting Workspace.

Flow:

Student reports inability to follow the normal payment schedule → authorized office determines the accommodation basis → Accounting verifies the approved basis and current ledger balance → a promissory note is signed outside TALA when institutional policy requires it → Accounting records the Financial Accommodation and its exact effects → payments continue through cashier or PayMongo → TALA marks the accommodation fulfilled, defaulted, expired, or cancelled as applicable.

Required Financial Accommodation fields:

1. Student ID.
2. Academic Year and Term.
3. Outstanding Balance Snapshot.
4. Covered Amount.
5. Basis: `DSWD_LGU_CERTIFICATION` or `INSTITUTIONAL_ACCOMMODATION`.
6. Certification Issuer, Reference Number, Issue Date, and Validity Period, when applicable.
7. Promissory Note Required.
8. Responsible Payer or Maker, when a note is required.
9. Payment Amount and Due Date or Installment Schedule.
10. Signed Note Private-File Reference or Physical-Document Reference, when applicable.
11. Decision Authority.
12. Recorded By and Recorded At.
13. Explicit Effects, including whether the arrangement allows current-term enrollment, next-term enrollment, or credential release.
14. Status.
15. Reason and Audit Metadata.

Statuses:

1. Pending.
2. Active.
3. Fulfilled.
4. Defaulted.
5. Expired.
6. Cancelled.

Rules:

1. Examination, exam-permit, and regular class access follow TALA's v1 institutional policy and applicable law.
2. An active accommodation affects next-term enrollment, credential release, or another finance restriction when that effect is explicitly approved and recorded.
3. Financial Holds resolve through payment, adjustment, reversal, waiver, or an active Financial Accommodation effect that covers the blocked workflow.
4. Academic, documentary, disciplinary, and other non-finance holds continue under their own source records.
5. TALA stores certification metadata and a private document reference when institutional policy requires a copy.
6. The signed note must identify the maker, payee, covered amount, execution date, payment due date or schedule, and signature when a formal promissory note is required.
7. Payments are recorded as verified payment evidence and ledger entries linked to the accommodation.
8. Default or expiry applies the institution's recorded policy prospectively.
9. If official RA 11984 implementing rules add certificate requirements, authorized staff update the configurable policy without rewriting historical accommodations.
10. Financial Accommodation passes the Finance Gate only when its explicit effects allow enrollment for the covered term.

---

### 8.8. SOA and Payment Acknowledgement

SOA is a source-derived finance output generated from assessment and ledger records.

Payment acknowledgement is a source-derived finance output confirming verified payment evidence and ledger posting. The acknowledgement is labeled as internal billing verification.

SOA must show:

1. Student identity.
2. Program and term.
3. Assessment summary.
4. Ledger line items.
5. Payments.
6. Discounts / scholarships.
7. Adjustments / reversals.
8. Current balance.
9. Generated timestamp.
10. Version and verification status.

Payment acknowledgement must show:

1. Student identity.
2. Payment amount.
3. Payment date.
4. Payment method.
5. Payment reference.
6. Ledger entry reference.
7. Confirmation status.
8. Generated timestamp.
9. Verification status.

Rules:

1. SOA is generated from assessment and ledger evidence.
2. Payment acknowledgement requires verified payment evidence and posted ledger entry.
3. New ledger activity refreshes, regenerates, or marks the current SOA output as non-current depending on institutional configuration.
4. Reversed or refunded payments must supersede or mark acknowledgements accordingly.
5. Student can view only their own SOA and payment acknowledgement.

---

### 8.9. SOA / Registration-Assessment Template

TALA defines two related official outputs:

1. COR / Registration Form — official enrollment and schedule proof.
2. SOA / Assessment Statement — finance output derived from assessment and ledger.

The system may generate a combined Registration Form with Assessment Section when configured.

#### 8.9.1 SOA Header

Required fields:

1. SERVITECH INSTITUTE ASIA INC.
2. Institution address.
3. Copy Type:
   - Registrar’s Office
   - Accounting Office
   - Student’s Copy

4. Semester / Term
5. Academic Year

#### 8.9.2 SOA Student Information

Required fields:

1. Student No.
2. LRN / Prior-Education Identifier, if available
3. Full Name
4. Program
5. Year Level
6. Registration Date

#### 8.9.3 SOA Fee Computation

Required rows:

1. Tuition Fee
2. Laboratory Fee
3. Miscellaneous Fee
4. Other Fee
5. Registration Fee, if applicable
6. Discount
7. Total Fees
8. Down Payment
9. Balance
10. Status

Status values:

1. Unpaid
2. Partially Paid
3. Full Paid
4. Installment
5. Payment Pending
6. Payment Under Review
7. Payment Rejected

Rules:

1. SOA must derive from assessment and ledger.
2. SOA is generated from recorded finance source records.
3. New ledger activity refreshes, regenerates, or marks the current SOA output as non-current depending on institutional configuration.
4. Student may view or download current SOA.
5. Accounting may view current and historical SOA.
6. Registrar may view SOA status or summary when needed for enrollment or COR gate checking.

---

### 8.10. PayMongo Integration Settings

Settings:

1. API credential reference.
2. Webhook endpoint status.
3. Webhook signing secret reference.
4. Active / inactive status.
5. Last successful webhook.
6. Last failed webhook.
7. Exception queue visibility.

Rules:

1. Webhooks must be verified.
2. Webhooks must be idempotent.
3. Duplicate events resolve to the existing payment evidence and posted ledger entry.
4. Failed webhook processing must be logged.
5. Payment evidence must remain separate from ledger posting.

---

### 8.11. Finance Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Fee definitions and term fee matrix | One Accounting-owned Fee Rules Editable Table and Record Form using controlled fee type, Program and Term scope, calculation type, fixed amount or per-unit PHP rate, effective dates, active status, and authority; Downpayment requires exact Program and Term scope |
| Student assessment | Generated Read-Only View of charge lines, discounts/adjustments, totals, required downpayment, and due schedule |
| Manual charge, penalty, adjustment, reversal, or refund | Focused Record Form selecting the affected source entry and requiring amount, direction, reason, authority, and reference |
| Manual cashier payment | Record Form for student/account, OR reference, payment date, method, total received, and evidence reference |
| Lump-sum OR allocation | Editable allocation table of unpaid lines; allocation total must equal the payment total before posting |
| PayMongo payment | Student-facing checkout action followed by a read-only pending/success/failure state; only verified webhook evidence may update TALA |
| Pending OR Mapping | Accounting Review Table with a focused form to enter the physical OR number/date against existing payment evidence |
| Finance Gate readiness | Generated Read-Only View derived from ledger posting and active Financial Accommodation effects; no manual paid toggle |
| Financial Accommodation | Record Form capturing the approved result, basis, covered amount, due schedule rows, explicit effects, authority, private reference, and state |
| Promissory-note evidence | Optional restricted File Upload or evidence-reference field according to institutional retention policy; it is not the approval control |
| Student ledger and SOA | Generated Read-Only View with authorized print/download; corrections occur through adjustment or reversal forms |
| Reconciliation | Review Table comparing payment evidence, ledger posting, and OR mapping, with exception filters |

Amounts, balances, allocation differences, penalties, and installment totals are computed read-only values. Corrections use adjustment or reversal forms linked to the original source entry.

---
