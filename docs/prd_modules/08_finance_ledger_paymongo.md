## 8. Finance, Ledger, & PayMongo Subsystem

---

### 8.1. Finance and Ledger

Rules:

1. Assessment, payment evidence, ledger, balance, SOA, and payment acknowledgement are separate records.
2. Ledger entries cannot be silently edited.
3. Corrections must use adjustment or reversal entries.
4. Balance must be reproducible from posted ledger entries.
5. SOA must derive from assessment and ledger.
6. Payment acknowledgement must derive from verified payment evidence and ledger posting.
7. Official tax receipt issuance happens through the institution's cashier/accounting process.

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

1. Posted ledger entries cannot be edited directly.
2. Correction requires adjustment or reversal.
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
5. Full drop or withdrawal.
6. Delivery modality change with fee impact.
7. Laboratory subject added or removed.
8. Scholarship applied or removed.
9. Discount applied or removed.
10. Payment plan approved or defaulted.
11. Summer recoup enrollment.
12. Correction of fee rule.

Rules:

1. If assessment is still draft, recalculation may update the draft.
2. If assessment is active or locked, change must create supersession or ledger adjustment.
3. Fee impact must be auditable.
4. Student-facing balance must reflect latest posted ledger state.
5. COR eligibility must recheck finance gate after material finance changes.

---

### 8.4. Business Fee Defaults

#### 8.4.1 Downpayment

1. Downpayment defaults to ₱1,000–₱2,000 depending on program.
2. Exact downpayment amount must be configurable per program and term.
3. If no program-specific downpayment is configured, staff must configure it before enrollment payment assessment becomes active.
4. Downpayment is non-refundable by default once paid, subject to institutional policy.

#### 8.4.2 Late Enrollment Fee

1. Default late enrollment fee is ₱500.
2. The rule must be configurable.
3. Fee posts as a ledger charge.
4. Fee appears in SOA.

#### 8.4.3 Delayed Payment Penalty

1. **One-Time Late Payment Surcharge:** A delayed payment of a scheduled installment (e.g., Midterm or Final installment) incurs a one-time late payment penalty of 5% calculated on the specific unpaid installment amount. The surcharge is not compounded daily.
2. **Automated Surcharge Calculation:** A background system job runs daily to identify overdue installments. For any newly overdue installment, the system automatically posts a single ledger charge of 5% of that specific installment's value and flags the installment to prevent duplicate penalty applications.
3. **Audit Labeling:** The automated charge must be distinctly labeled in the ledger (e.g., "Late Penalty - Midterm Installment") to support Accounting and the Verifier during daily turnover audits.
4. **Configuration:** The penalty percentage rate must be configurable by Accounting.
5. **RA 11984 Compliance:** Late payment penalties must never create default exam blocks or block exam permit downloads. They appear strictly as ledger charges in the student's Statement of Account (SOA).

#### 8.4.4 Shift / Schedule / Program Change Fee

1. Default fee is ₱100 for student-requested shifting of schedule, program, section, or replacement / loss of card.
2. This applies only if the change is student-requested or student-caused.
3. Institution-caused changes are recorded without charging the student.
4. Fee posts as a ledger charge.

#### 8.4.5 Dropout Fee

1. Approved full drop or withdrawal appends a flat ₱3,500.00 dropout fee to the ledger.
2. Dropout fee does not erase previous balance.
3. Dropped students with unpaid balance remain under finance or record-release hold.
4. Dropout fee must appear in SOA.

#### 8.4.6 Refund Rule

1. Admission / Enrollment Fee is refundable only within 15 calendar days from payment or OR date.
2. Tuition fee is non-refundable once the student is marked Officially Enrolled.
3. Refund processing remains Accounting-controlled.
4. Refund must use ledger reversal or adjustment.
5. Refund must not silently delete payment evidence.

---

### 8.5. PayMongo Payment Evidence

PayMongo is a payment gateway, not the ledger.

PayMongo flow:

Assessment created → student starts payment → TALA creates PayMongo checkout or payment intent with TALA reference → PayMongo sends webhook → TALA verifies event → payment evidence is recorded → Accounting confirms or auto-confirms based on policy → ledger entry posts → balance and clearance update.

Auto-confirm PayMongo only when:

1. Webhook is verified.
2. Status is paid.
3. Amount matches.
4. Currency is PHP.
5. TALA reference matches.
6. Payment has not been posted before.
7. No risk or mismatch exists.

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

1. Success page alone must not post ledger entries.
2. Webhook processing must be idempotent.
3. Duplicate events cannot double-post.
4. Raw webhook payloads are retained only as operational or audit records according to retention policy.
5. **Paper OR Mapping (Three-Way Parity Audit):** Because the institution mandates a physical paper Official Receipt (OR) for all transactions (including online payments) to support the daily reconciliation audit (Ledger = Receipts = Cash), TALA must support receipt mapping. When PayMongo webhooks verify an online payment, TALA posts the ledger entry to update the student's balance. This entry is queued as "Pending OR Mapping" in the Accounting Workspace. The Accounting Recorder writes the physical paper OR, photographs it for the student, and encodes the OR Number directly onto the existing TALA payment record. This links the paper receipt to the digital payment without double-crediting the student.

---

### 8.5.1 Payment Evidence, Ledger Posting, and OR Mapping Sequence

V1 finance flow must follow this sequence:

1. **Billing Slip:** Student generates an internal billing slip from an active assessment.
2. **Payment Evidence:** PayMongo webhook or Accounting manual entry creates verified payment evidence.
3. **Ledger Posting:** Accounting or policy-approved auto-confirm posts a ledger entry from verified payment evidence.
4. **OR Mapping:** Accounting later maps the physical paper OR number to the existing payment evidence record (not the ledger entries).
5. **Payment Acknowledgement:** Student can view payment acknowledgement after verified payment evidence and ledger posting. The acknowledgement shows the OR number only if it has already been mapped.

Rules:

1. OR mapping must not post a second payment.
2. OR mapping links the physical receipt reference to an existing payment or ledger entry for reconciliation.
3. Payment acknowledgement must not claim to be an official tax receipt.
4. PayMongo success page alone must not create payment evidence or ledger posting.
5. Manual payment entry must create payment evidence before ledger posting.

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

### 8.7. Refined Promissory Note (Deferred Installment Plans)

Deferred payment plans use a rigid, low-complexity workflow:

1. **Request Submission:** In the Student Hub, a student applies for a Promissory Note by providing a mandatory reason (text) and uploading a single combined document (PDF or image) containing both their Parent ID and Proof of Income to the `evidence_url` field.
2. **Dual-Boolean Approval:** The request requires two independent reviews:
   - The Registrar reviews the academic standing and supporting documents, setting `registrar_approved` to `true`.
   - The Accounting Head reviews financial status, setting `accounting_head_approved` to `true`.
   - When both approvals are `true`, the system sets the status to `ACTIVE`.
3. **Strict Limit Guard:** A student is strictly capped at at most **one (1) active promissory note per academic year**. The database enforces this via a partial unique index, blocking duplicate requests in the same year.
4. **Dynamic Holds Bypass (RA 11984 Compliance):** In strict compliance with RA 11984, exam permit downloads and class access are never blocked by financial status. Instead, an `ACTIVE` promissory note automatically bypasses finance-related enrollment and finance-related document request holds. It does not bypass academic, registrar document, or disciplinary holds. The student settles their deferred balance offline at the Cashier when funds are available.
5. **Holds-Based Reactivation:** If a student leaves a term with an outstanding balance, the system automatically posts a `Financial Hold` (blocking enrollment) on their profile. To reactivate their account and enroll in a future term, they must either pay the debt in full at the Cashier (lifting the hold) or establish an approved Promissory Note / payment plan for the debt.

---

### 8.8. SOA and Payment Acknowledgement

SOA is a source-derived finance output generated from assessment and ledger records.

Payment acknowledgement is a source-derived finance output confirming verified payment evidence and ledger posting. It is not an official tax receipt.

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

1. SOA cannot be manually invented outside ledger evidence.
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
8. Cleared by Payment Plan

Rules:

1. SOA must derive from assessment and ledger.
2. SOA must not be manually invented.
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
3. Duplicate events must not double-post ledger entries.
4. Failed webhook processing must be logged.
5. Payment evidence must remain separate from ledger posting.

---
