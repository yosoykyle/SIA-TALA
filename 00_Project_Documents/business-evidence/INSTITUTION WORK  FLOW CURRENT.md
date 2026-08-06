# CONSOLIDATED INSTITUTION WORKFLOW & POLICY MANUAL

> **Supporting business evidence — not TALA product or policy authority.** This file records reported/manual institutional practice and includes legacy rules such as five configurable gates, lifetime-zero-balance blocking, and global holds that canonical 00–06 supersedes. Use it only for terminology, form shape, and bounded workflow clarification.

> **College-only scope update (2026-06-21):** The current TALA deployment covers the College department only. Senior High School is no longer an active offered level and must not drive active enrollment, grading, fee, scheduling, faculty, or UAT workflows. Grade 12, Form 138, Form 137, Good Moral, PSA, and similar records remain valid only as prior-education admission credentials for College applicants.

> **Document-request system boundary (2026-06-21):** The institution continues to accept and fulfill official-document requests through its existing manual office process, but TALA will not implement a document-request portal, catalog, fee workflow, fulfillment queue, pickup/claiming workflow, courier/delivery workflow, or shipping-fee automation. References below document the institution's manual operation only and must not create TALA product requirements. Admission-document intake/review and system-generated core artifacts such as COR and finance evidence remain separate TALA capabilities.

## 1. SHARED CORE POLICIES & WORKFLOW DIAGRAMS

### 1.1 5 Enrollment Clearance Gates
Continuing students must clear five manual eligibility gates before registration paperwork printing.
* **Financial Gate**: Previous balance must be exactly zero (₱0.00). If flagged on Unpaid Ledger Sheet, registration slip is blocked.
* **Documentary Gate**: Permanent folder must contain all original requirements (e.g., Form 137/138, PSA Birth Certificate). Unsubmitted retention documents trigger enrollment blocks.
* **Behavioral Gate**: Requires Student Affairs certification of clean behavioral standing and no pending major disciplinary cases.
* **Disciplinary Gate**: Student must not be serving campus probation, suspension, or physical detention.
* **Academic Remediation Review**: Registrar audits past grades. Regular path (passed all subjects) yields block section code. Failed subjects trigger progression review.

```text
[ Continuing Student ]
           │
    [ Clearance Gates ]
    ├─ Financial:   Verify previous balance is 0.00
    ├─ Documentary: Verify all required documents present
    ├─ Behavioral:  Verify clean disciplinary record
    ├─ Disciplinary:Verify not serving suspension/detention
    └─ Academic:    Verify passing grade status
```

### 1.2 Academic Progression, Retention & Repeat Policies
Progression rules enforce College retention and repeat standards.
* **Cumulative GWA Below Threshold**: Academic probation. Must repeat entire year level when institutional retention rules require full-year repetition.
* **Single Subject Failure**: Labeled irregular. Must retake the failed subject in a regular semester when hosted in the master schedule.
* **Prerequisite Failure**: Blocks downstream subjects until the prerequisite is passed or a formally approved equivalency is recorded.

```text
               [ Academic Progression Review ]
                          │
                    [ Check Cum. GWA ]
                          │
                  Failed Retention GWA
                    (Repeat Whole Year)
                          │
              Failed Subject With Passing GWA
              (Irregular: Retake in host class)
```

### 1.3 Irregular Student Schedule Mapping Workflow
Continuing irregular students require manual evaluation and timetabling.
1. **Record Retrieval**: Pull permanent folder (Form 137/TOR Ledger) to isolate failed/skipped prerequisite subjects.
2. **Prerequisite Check**: Match requirements against Subject Prerequisite Matrix.
3. **Timetabling**: Cross-reference master schedule. Handwrite custom timetable on Irregular Student Schedule Slip (triplicate carbon) showing Subject Codes, Sections, Room Assignments, and Lecture Hours. Prevent overlaps.
4. **Registration**: Transcribe custom timetable into local system to print Certificate of Registration (COR).

```text
[ Student Flagged Irregular ] ──> [ Pull Folder (Form 137/TOR) ] ──> [ Identify Missing Prerequisites ]
                                                                               │
                                                                               ▼
[ Print Registration Slip ] <── [ Write Custom Paper Schedule ] <── [ Cross-Ref Master Schedule ]
```

### 1.4 Learning Delivery Modalities
* **Online Modality**: Materials, activities, and exams deployed via Google Classroom.
* **Face-to-Face Modality**: Student reports to physical classrooms per scheduled hours.
* **Modular Modality**: Independent study. Student/guardian signs counter Modular Distribution Ledger to claim packets. Completed workbooks dropped in secure Modular Drop-Box on assigned days. Failing to submit two (2) consecutive packets triggers Uncommunicative status, halting distribution.

```text
            [ Select Delivery Modality ]
                        │
          ┌─────────────┼─────────────┐
          ▼             ▼             ▼
      [ Online ]   [ Face-to-Face ] [ Modular ]
```

### 1.5 End-of-Term Final Grading & Audit Pipeline
1. **Grade Computation**: Faculty computes College grades using approved term grading sheets and the institutional point grading scale.
2. **Dual Submission**: Faculty submits signed physical hard copy grade sheets and digital Excel file.
3. **Registrar Audit**: Clerk cross-checks printed digital sheets with signed hard copies using a ruler. Verifies math formulas for random samples on calculator.
4. **Error Loop**: If errors are found (typos, miscalculations), Clerk halts audit and issues pink Grade Correction Memo. Folder is returned to faculty mailbox for correction. Faculty resubmits corrected and signed records.
5. **Approval**: Clerically sound rosters wet-stamped "VERIFIED & APPROVED FOR LEDGER ENTRY".
6. **Ledger Transcription**: Clerk records verified College grades into the academic ledger or TOR record. Checklist is updated. Failed grades trigger Blue Tab ("Academic Deficit Hold").

```text
[ Faculty Computes Ratings ] ──> [ Dual Submission (Hard + Soft) ]
                                            │
                                            ▼
                                [ Registrar Clerk Audit ]
                                      /          \
                          (Errors Found)        (No Errors)
                                │                     │
                                ▼                     ▼
                    [ Grade Sheet Rejected ]    [ Roster Approved ]
                                │                     │
                                ▼                     ▼
                    [ Returned to Faculty ]     [ Permanent Ledger Entry ]
```

### 1.6 Graduation Evaluation & Prerequisite Gate
Rigorous checking of academic history for graduation eligibility.
* **Regular Students**: General clearances checked (financial, behavioral, documents).
* **Irregular Students (Blue Tab)**:
  * **Deficiencies Present**: Blocked from graduation. Stamped "DENIED: OUTSTANDING DEFICIENCIES". Must enroll in missing subjects next term.
  * **Zero Deficiencies**: Cleared for general clearance step. Blue Tab removed.
* **Compliance**: Registrar prepares CHED Special Order (S.O.) Form, signs, and submits to regional CHED office. Approved S.O. number logged on cardboard ledger and physical diploma.

```text
              [ Student Applies for Graduation ]
                             │
                 [ Curriculum & Credit Audit ]
                             │
               [ Evaluate Academic Status ]
                       /            \
              (Regular)            (Irregular)
                  │                     │
      [ General Clearance ]    [ Prerequisite Gate ]
                  │               /            \
                  │       (Deficiencies)   (Zero Deficiencies)
                  │             │               │
                  │             ▼               ▼
                  │       [ Not Qualified ]  [ General Clearance ]
                  │       [ Force Retake ]      │
                  └─────────────┬───────────────┘
                                │
                          (All Cleared)
                                │
                      [ Approved Graduate ]
                                │
                     [ Diploma Preparation ]
                                │
                  [ Submit Graduation List ]
```

---

## 2. DOCUMENT ADMISSION & RETENTION REQUIREMENTS

### 2.1 Admissions Requirements by Student Type

| Type of Student | Documents for Admission (Required Upfront) | Retention Documents (To be Filled/Submitted) |
| :--- | :--- | :--- |
| **Regular College Freshman** | Grade 12 Report Card (Form 138), PSA Birth Certificate, Good Moral, 2x2 Picture | Diploma/Certificate of Graduation, Entrance Exam results, ID photos, Form 137 when released by prior school |
| **Old Curriculum College Entrant** | Prior completion records, PSA Birth Certificate, Good Moral, 2x2 Picture | Certificate of Completion, Form 137 or equivalent prior-school permanent record |
| **Transfer Student (College)** | TOR / Copy of Grades, Honorable Dismissal, Good Moral, PSA Birth Certificate, 2x2 Picture | Official transfer credentials, final TOR |
| **Returning Student (College)** | Previous school records, PSA Birth Certificate, 2x2 Picture | Re-admission form, Registrar clearance, previous enrollment clearance |
| **ALS College Entrant** | Certificate of Rating, ALS Completion Certificate, PSA Birth Certificate, Good Moral, 2x2 Picture | Relevant rating forms, completion certifications |
| **Indigenous Peoples (IP) College Applicant** | PSA Birth Certificate, prior-school records, Community/Tribal Leader Certification, Government scholarship docs | Support program documents |
| **PWD/SEN College Applicant** | PSA Birth Certificate, prior-school records, Good Moral, Medical Certificate, 2x2 Picture | Medical/psychological assessment reports |
| **Foreign College Student** | Birth Certificate, academic records, 2x2 Picture | Passport, student visa, immigration files, English proficiency, medical clearance |
| **College Cross-Enrollee** | PSA Birth Certificate, Copy of Grades/TOR, Home Institution Cross-Enroll Permit, Both Schools Approval | Registered course clearances |

### 2.2 Document Categories & Purposes

| Category | Document | Target Audience | Purpose |
| :--- | :--- | :--- | :--- |
| **Admission** | PSA Birth Certificate | All students | Verifies identity, age, and citizenship |
| | Report Card (Form 138) | College freshmen when used as Grade 12 prior-education evidence | Proof of completed prior education |
| | Transcript of Records (TOR) | Transfer and second-degree | Evaluates previous subjects for credit |
| | Diploma / Certificate of Graduation | College freshmen / Graduates | Confirms successful completion of prior tier |
| | Good Moral Certificate | All new and transfer | Verifies conduct standing from prior school |
| | Honorable Dismissal | College transfers | Authorizes transfer from previous college |
| | Passport & Visa Documents | Foreign students | Verifies legal study status in the Philippines |
| | Recent ID Photos | All students | Used for identification and physical folders |
| | Enrollment Form | All students | Collects demographic and contact info |
| **Enrollment** | Certificate of Registration (COR) | Enrolled students | Proof of registration and assigned subjects |
| | Assessment Form | Enrolled students | Lists tuition charges and miscellaneous fees |
| | Class Schedule | Enrolled students | Details classrooms, sections, and instructors |
| **Academic Records** | Permanent Record (Form 137) | College freshmen/transfers when released by prior school | Official prior-education history used for admission and evaluation |
| | Certificate of Grades (COG) | College students | Provides grades for scholarship/verification |
| | Academic Evaluation Records | All students | Tracks curriculum progress and academic honors |
| | Graduation Clearance | Graduating students | Confirms completion of all institutional requirements |
| **Financial** | Official Receipts | Payees | Proof of tuition and fees settlement |
| | Statement of Account (SOA) | Enrolled students | Details balances, billing charges, and histories |
| **Graduation** | Diploma | Graduates | Official proof of program completion |
| | Certificate of Graduation | Graduates | Confirms academic requirements completion |
| | Certificate of Completion | Non-degree graduates | Verifies completion of special programs |
| **Requested** | Certificate of Enrollment (COE) | Enrolled students | Verifies current enrollment status |

---

## 3. SYSTEM REQUIREMENTS SPECIFICATION (SRS)

### 3.1 Manual Document Request Services (Outside TALA)

The institution may continue accepting, assessing, preparing, releasing, and delivering requested records through Registrar and Accounting office procedures. These activities are evidence of the current manual operation only.

- TALA does not provide a student document-request form or tracking page.
- TALA does not maintain a request catalog, request pricing, request-payment queue, fulfillment state machine, pickup/claiming evidence, courier data, delivery consent, shipping charges, or request notifications.
- Any future digitization of this manual process requires a new approved scope decision, benchmark and legal review, FS/TS revision, SDD slice, implementation, and UAT cases.

### 3.2 Section or Program Transfer Services
* **Fields**: Locked Student Profile, Transfer Type (Section vs. Program), Target Program/Section, Reason for Transfer (Mandatory), Support Documents Upload (PDF/JPG).
* **Validation**: Student must be active. Current date must fall within official transfer window. Outstanding balance must be exactly zero.
* **Processing**: Registrar checks academic capacity/prerequisites. Accounting checks tuition fee changes. Upon joint approval, database updates student's section/program and triggers automatic ledger recalculation:
  $$\text{New Ledger Balance} = \text{Previous Payments} \pm \Delta \text{Program Fees}$$

### 3.3 Modality Change Requests
* **Fields**: Locked Profile, Target Modality (Face-to-Face, Pure Online, Hybrid), Reason for Change (Mandatory), Supporting Documents (Optional).
* **Validation**: Submission limited to first two (2) weeks of the term.
* **Processing**: Registrar reviews documents. Approved transfers update LMS profiles, instruct teachers, modify Class Lists, and trigger laboratory fee waivers if online.

### 3.4 Promissory Note Assistance
* **Fields**: Locked Profile, Reason for Deferral (Mandatory), Installment Payment Plan (e.g. 2-part, 3-part), Parent ID and Proof of Income Upload.
* **Cap Limit**: Strict maximum of one (1) approved promissory note per academic year. Secondary requests blocked.
  * *Error*: "Submission Blocked: Only one Promissory Note is permitted per academic year. Please visit the Accounting Office directly for manual assistance."
* **Processing**: Dual approval required from Registrar and Accounting Head. System suspends automatic exam payment-overdue blocks, allowing permit printing.

### 3.5 Dropping & Account Lifecycle Management

#### 3.5.1 Official Drop & Dropout Fee
* **Filing**: Active students can file drop requests even with active outstanding balances.
* **Dropout Penalty**: Approved drops append a flat ₱3,500.00 fee to student ledger.
* **Hold Restriction**: Dropped students cannot request academic documents (TOR, Certifications, Transfer Credentials) until total balance (including the ₱3,500.00 drop fee) is paid.

```text
[ Active Student ]
       │
   (Files Drop Request)
       │
       ▼
[ Dropping Process ] ──> [ Apply ₱3,500 Fee to Balance ]
       │
       ├─(Has Outstanding Balance)─> [ Archived / Inactive ]
       │                                   │
       │                     (Settle Balance & Request Reactivation)
       │                                   │
       │                                   ▼
       │                             [ Active Student ]
       │
       └─(No Balance)──────────────> [ Closed / Cleared ]
```

#### 3.5.2 Account Archiving & Reactivation
* **Archiving**: Students leaving with outstanding balances are marked Inactive/Archived. Records and debts are preserved. Future enrollment and document requests are blocked.
* **Reactivation**: Manually triggered by Admin/Registrar. Requires complete debt clearance or approved payment plan before enrollment is enabled.

#### 3.5.3 Absence, Grace Periods, and Auto-Archiving
Unnotified student absence ("no show") triggers systematic tracking.
* **Grace Period**: Student is allowed one (1) term (quarter or semester) to appeal, file official LOA, or settle accounts.
* **Auto-Archiving**: If grace period expires without communication:
  1. System marks account "Inactive/For Archiving".
  2. Registrar reviews dashboard and manually confirms status.
  3. Profile is updated to Archived, freezing ledger balances.
* **Notification Schedule**:
  * *14 Days Before Term End*: "Notice: Our records show you have not attended or enrolled for the current term. Please coordinate with the Registrar's Office before the end of the term to discuss your options."
  * *7 Days Before Archiving*: "Final Notice: Your account is scheduled to be archived due to inactivity. To prevent restricted access to your records, please contact the Registrar immediately."
  * *Status Change*: "Account Status Updated: Your student profile has been archived. Future enrollment and document requests are restricted until your account status is resolved."

```text
[ Student Stops Attending Without Notice ]
                   │
                   ▼
         [ 1-Term Grace Period ]
                   │
                   ├─(Admin Sends Reminders Before/At Term End)
                   │
                   ▼ (No Appeal or Communication Received)
         [ Auto-Marked: Inactive ]
                   │
                   ▼ (Registrar Confirms Status)
         [ Account Status: Archived ]
```

### 3.6 SRS Workflow Diagrams

#### 3.6.1 Drop Subject Request
```text
[ Student Files Drop Request ] ──> [ Registrar Reviews Request ] ──> [ Check Academic Load ]
                                                                               │
                                                                               ▼
[ END ] <── [ Update Records ] <── [ Recompute Fees ] <── [ Remove Subject ] <── [ Approved? (Yes) ]
                                                                               │
                                                                               ▼ (No)
                                                                        [ Notify Student ]
```

#### 3.6.2 Section Transfer Request
```text
[ Student Files Section Transfer ] ──> [ Registrar Reviews Capacity ] ──> [ Check Available Slots ]
                                                                                    │
                                                                                    ▼
      [ END ] <── [ Update Records ] <── [ Transfer Section ] <── [ Approved? (Yes) ]
                                                                                    │
                                                                                    ▼ (No)
                                                                             [ Notify Student ]
```

#### 3.6.3 Update Personal Information
```text
[ Student Submits Update Request ] ──> [ Submit Supporting Documents ] ──> [ Registrar Verification ]
                                                                                     │
                                                                                     ▼
[ END ] <── [ Share with Faculty ] <── [ Update Physical Folder ] <── [ Approved? (Yes) ]
                                                                                     │
                                                                                     ▼ (No)
                                                                              [ Notify Student ]
```

#### 3.6.4 Correction of Personal Data
```text
[ Student Files Correction Request ] ──> [ Submit PSA Birth Certificate ] ──> [ Registrar Validation ]
                                                                                        │
                                                                                        ▼
                [ END ] <── [ Update Databases ] <── [ Correct Records ] <── [ Approved? (Yes) ]
                                                                                        │
                                                                                        ▼ (No)
                                                                                 [ Notify Student ]
```

#### 3.6.5 Submit Missing Requirements
```text
[ Student Submits Missing Document ]
                │
                ▼
   [ Registrar Receives Document ]
                │
                ▼
    [ Validate Authenticity ]
                │
                ▼
    [ Update Student Folder ]
                │
                ▼
   [ Remove Documentary Hold ]
                │
                ▼
            [ END ]
```

#### 3.6.6 Graduation Application
```text
[ Student Applies for Graduation ] ──> [ Registrar Pulls Academic Record ] ──> [ Curriculum Audit ]
                                                                                        │
                                                                                        ▼
     [ END ] <── [ Approved Graduate ] <── [ Graduation Clearance ] <── [ Complete? (Yes) ]
                                                                                        │
                                                                                        ▼ (No)
                                                                             [ Issue Deficiency List ]
```

#### 3.6.7 Diploma Processing
```text
[ Approved Graduate ]
                │
                ▼
    [ Prepare Graduate List ]
                │
                ▼
  [ Administrative Approval ]
                │
                ▼
       [ Print Diploma ]
                │
                ▼
    [ Record Diploma Number ]
                │
                ▼
      [ Graduate Claiming ]
                │
                ▼
            [ END ]
```

#### 3.6.8 Graduation Document Release
```text
[ Graduate Requests Documents ] ──> [ Check Clearance ] ──> [ Outstanding Balance? ]
                                                                     │
                                                                     ▼
                        [ END ] <── [ Release Documents ] <── [ Balance = 0? (Yes) ]
                                                                     │
                                                                     ▼ (No)
                                                                [ Place Hold ]
```

#### 3.6.9 Leave of Absence (LOA)
```text
[ Student Files LOA Request ] ──> [ Registrar Evaluation ] ──> [ Accounting Clearance ]
                                                                          │
                                                                          ▼
      [ END ] <── [ Update Faculty ] <── [ Mark Status LOA ] <── [ Approved? (Yes) ]
                                                                          │
                                                                          ▼ (No)
                                                                   [ Notify Student ]
```

#### 3.6.10 Readmission Process
```text
[ Former Student Requests Readmission ] ──> [ Registrar Reviews Records ] ──> [ Check Balance ]
                                                                                    │
                                                                                    ▼
      [ END ] <── [ Re-enrollment ] <── [ Reactivate Record ] <── [ Approved? (Yes) ]
                                                                                    │
                                                                                    ▼ (No)
                                                                             [ Notify Student ]
```

#### 3.6.11 Transfer-Out Process
```text
[ Student Requests Transfer ] ──> [ Submit Transfer Form ] ──> [ Registrar Clearance Check ]
                                                                           │
                                                                           ▼
[ END ] <── [ Mark Transferred ] <── [ Release TOR ] <── [ Clear? (Yes) ] <── [ Accounting Clearance ]
                                                                           │
                                                                           ▼ (No)
                                                                      [ Hold Request ]
```

---

## 4. MANUAL ADMISSION, ENROLLMENT & OPERATIONS MANUAL

### 4.1 Admission & Registration Pipeline
* **First-Come, First-Served Gate**: Inquiry or form completion does not reserve student slots. Enrollment slots officially secured only upon final payment at cashier (Step 13). If maximum limit is reached, unpaid registrations are discarded.
* **Step 1: Student Inquiry**: Applicant arrives at admissions counter. Mr. Warien or Ms. Elaiza log name and mobile number in Daily Admissions Visitor Logbook.
* **Step 2: Program Identification**: Officer interviews applicant to determine College program, year-entry basis, and applicant profile category.
* **Step 3: Face-to-Face Orientation**: Mandatory briefing on:
  * [Learning Delivery Modalities](#14-learning-delivery-modalities).
  * Financial terms: Downpayment details, installment options, and refund policies (Admission fee refundable only within 15 calendar days; base tuition non-refundable once student is marked Enrolled).
* **Step 4: Decision Gate**: If applicant confirms intent, proceed. If not, transaction terminates.
* **Step 5: Registration Form Issuance**: Blank physical Registration Form hand-delivered.
* **Step 6: Form Completion**: Applicant completes form in ink.
* **Step 7: Retrieval**: Mr. Warien verifies legibility of vital contact and demographic fields.
* **Step 8: Curriculum Credit Review**: Registrar Evaluator audits applicant credentials:
  * Regular student: Pre-set block section assigned.
  * Irregular student: Map custom non-conflicting schedule (refer to [Irregular Student Schedule Mapping](#13-irregular-student-schedule-mapping-workflow)).
  * Foreign student: Hold registration for detailed credit evaluation.
* **Step 9: Official Registration Slip Printing**: Mr. Warien registers profile in local database, assigns sections, and prints two (2) Registration Slips.
* **Step 10: Accounting Routing**: Applicant hand-carries slips to Accounting office.
* **Step 11: Payment Cashiering**: Cashier collects payment (OTC cash or checks GCash/bank transaction references).
* **Step 12: Financial Briefing**: Accounting details installment schedules and balance deadlines.
* **Step 13: Stamping & Receipt**: Accounting stamps slips "PAID / ENROLLED", registers transaction, prints physical Official Receipt (OR), and hands it to student. **Slot is officially secured.**
* **Step 14: Payment Logging**: Cashier marks student paid in daily ledger.
* **Step 15: Return to Admissions**: Student presents stamped PAID slip and OR to Mr. Warien.
* **Step 16: Communication Setup**: Mr. Warien registers student mobile number and sends invites to cohort Class and Section Group Chats (GC).
* **Step 17: LMS Deployment**: Email registered in subject-specific Google Classrooms.
* **Step 18: Class List Distribution**: Prior to classes, Registrar prints manual Official Class Lists and delivers physical copies to faculty mailboxes.
* **Step 19: Module Distribution**: Faculty distributes physical modules to modular/F2F students or uploads soft copies to Google Classrooms for online classes on day one.

### 4.2 Physical Document Management
* **Sorting System**: Group documents into:
  1. Admission Gate Documents (Required upfront).
  2. Retention Documents (Pending submission, subject to [Document Release Holds](#5.3-provisional-admission--monitoring-routine)).
* **Capacity Bounds**: Maximum campus limit is exactly 100 active students. Enrollment halts when 100 Official Receipts are issued.
* **Continuing Student Holds**: To register for next term, students must clear [5 Enrollment Clearance Gates](#11-5-enrollment-clearance-gates). Failing any gate blocks registration form access.
* **Summer Recoup**: Recovers failed subjects. Offered at school's discretion.
  * *Load Cap*: Max 6 to 9 units.
  * *College pricing*: $\text{Tuition} = \text{Summer Enrollment Fee} + (\text{Units} \times \text{Price})$.

---

## 5. REGISTRAR DEPARTMENT SOP MANUAL

### 5.1 Registrar General Policies
* **100-Student Ceiling**: Strictly 100 active enrolled students. Slips and inquiries do not secure slots. Cashier receipt (OR) payment secures slots. Halts enrollment immediately when capacity is hit.
* **Financial Terms**: Admission/Enrollment Fee refundable only within 15 days of OR date. Tuition fee non-refundable once registered as "OFFICIALLY ENROLLED".

### 5.2 Student Credentials Intake & Verification
* **Step 1: Document Intake**: Applicant submits credentials in long brown envelope at Registrar window. Assistant checks requirements.
* **Step 2: Authenticity Inspection**: Check for wet-ink signatures, dry seals, and tampering. Photocopies require red/blue wet stamp certifying authenticity.
* **Step 3: Provisional Undertaking (Retention Hold)**: If non-critical documents are missing, Registrar drafts a Missing Documents Undertaking Form (triplicate carbon), establishing a 30-to-60-day submission deadline. Red Cardboard Flag ("DOCUMENTARY HOLD") stapled to student physical folder.
* **Step 4: Folder Filing**: Verified documents placed inside colored folders:
  * Blue Folders: College (Regular)
  * Yellow Folders: Transferees
  * Red flags: Documentary or clearance holds
  Folder labeled (Surname, First Name, Entry Year, Student ID) and filed alphabetically in locked cabinets.
* **Step 5: External Roster Preparation**: Registrar maintains College student demographics and enrollment rosters for external reporting when required. TALA supports internal roster inventory and generic exports only; official regulator portals, templates, or submission queues remain outside the active system workflow.

### 5.3 Provisional Admission & Monitoring Routine
* **Binder Audits**: Clerk checks Missing Requirements Monitoring Sheet monthly.
* **Follow-up Protocol**: Verbal reminders at Registrar window, manual phone calls/SMS, and homeroom adviser memos.
* **Resolution**: Upon submission, documents verified and red flag removed from folder.

### 5.4 Academic Standing & Schedule Mapping
* **Standings**: Clerk pulls cardboard records (Form 137 / TOR) and reviews grades against checklists and prerequisite rules:
  * Regular: All passed. No academic flags. Status: "CLEARED FOR ENROLLMENT".
  * Irregular: Missing prerequisites. Blue Tab ("Academic Deficit Hold") stapled to folder.
  * Probationary: Term GWA falls below retention limits. Probation Warning Sheet inserted.
* **Irregular Timetabling**: Clerk maps schedule on custom slip to avoid scheduling overlaps. Student carries slip to Admissions desk for COR printing.

### 5.5 Registrar Graduation Audit & S.O. Filing
* **Audit**: Clerk retrieves folder, curriculum checklist, and TOR ledger. Performs line-by-line check.
* **Irregular Gate**: Check for failed/missing subjects. Deny if deficiencies remain. Zero deficiencies remove the Blue Tab.
* **S.O. Request**: Typist fills CHED Special Order Form. Registrar signs, registers it with CHED, and handwrites S.O. details on card ledger and diploma.

### 5.6 Registrar Summer Term Management
* **Offering**: Program-based demand and director discretion. Unoffered remedial courses force students to wait until regular term offerings.
* **Billing Setup**: Clerk computes fees on physical Summer Enrollment Slip (triplicate carbon) and routes student to cashier.

### 5.7 Registrar Transaction Matrix

| Category | Transaction Name | Required Paper Form | Action / Verification Layer |
| :--- | :--- | :--- | :--- |
| **Academic** | Enroll in Term | Registration Form / Slip | Verify 5-Clearance Holds in filing cabinets |
| | Drop Subject | Subject Modification Slip | Check prerequisite rules & signature of adviser |
| | Withdraw Enrollment | Official Drop Form | Collect drop fee of ₱3,500; schedule Guidance |
| | Change Section | Section Transfer Request Form | Check section capacity limits & conflict schedules |
| | Change Program/Course | Program Shifting Request | Audit prerequisite compliance & credit transfer rules |
| | Request Summer Class | Summer Enrollment Slip | Check unit limits (6 to 9 units) & calculate fees |
| **Records & Certs** | Request TOR | Document Request Form (DRF) | Verify Red Flags/Blue Tabs; typewrite TOR ledger |
| | Request COE | Document Request Form (DRF) | Verify enrollment status in masterlist; manual stamp |
| | Request COG | Document Request Form (DRF) | Extract grades from permanent card ledger |
| | Request Good Moral | Document Request Form (DRF) | Coordinate with Student Affairs for clearance |
| | Request Form 137/138 | Document Request Form (DRF) | Pull permanent cardboard ledger from lockbox |
| | Certified True Copy | Document Request Form (DRF) | Inspect photocopy against original; wet stamp & sign |
| | Update Personal Info | Information Correction Slip | Request physical PSA Birth Cert; update paper file |
| **Financial** | Settle Tuition/Installments | Hand-written Billing Slip | Route to Accounting Cashier; wait for OR presentation |
| | View Outstanding Balance | Cashier Ledger Inquiry Slip | Read from physical weekly delinquent account checklist |
| | Apply for Promissory Note | Promissory Note Request | Check 1-note/year limit; dual Registrar/Accounting sign |
| | Request OR Copy | Cashier Receipt Reprint Slip | Search Accounting cash book archives |
| **Graduation** | Apply for Graduation | Graduation Application Form | Pull physical folder; start final line-by-line audit |
| | Complete Grad Clearance | Multi-Department Clearance Slip | Collect actual wet-signatures from all office heads |
| | Request Diploma | Graduation Application Form | Match with approved CHED Special Order (S.O.) log |
| **Student Status** | File Leave of Absence (LOA) | LOA Form (Triplicate Carbon) | Limit to 1 year; file folder in LOA drawer |
| | Return from LOA | Readmission Request Form | Check curriculum alignment & 100-student cap |
| | Reactivate Account | Account Reactivation Form | Pull file from Archived Binder; settle old debts |
| | Transfer Out | Transfer Credential Request | Settle balances; issue Honorable Dismissal; seal file |

---

## 6. FACULTY OPERATIONS MANUAL

### 6.1 Faculty Core Triad & Grading Sanctuary
Faculty coordinate activities with Registrar files and Accounting ledger checks. Instructors have no authority to edit, erase, or alter submitted grades unilaterally. Corrective updates require Registrar authorization via signed correction memos. Unsigned changes are void.

### 6.2 Legal Compliance (RA 11984 - "No Permit, No Exam")
No student is barred from quarterly, midterm, or final examinations due to unpaid fees. Zero discrimination enforced (no withholding of papers, locking digital exam forms, or announcing payment lists). Delinquencies trigger next-term enrollment blocks and document holds only.

### 6.3 College Faculty Workflow
* **Step 1: Notification**: Roster pulled from SIA Faculty Portal.
* **Step 2: Class List**: Update digital spreadsheets and registers.
* **Step 3: Materials Setup**: syllabus delivery, course syllabus mapping, Outcomes-Based Education (OBE) alignment.
* **Step 4: Term Start**: Syllabus explanation, schedule major exams, and orient academic integrity.
* **Step 5: Instruction**: Focus on cognitive development (Bloom's Taxonomy) with academic autonomy.
* **Step 6: Attendance check**: Track hours. Absences > 20% trigger automatic "Failed due to Absences (FA)" or "Dropped (DRP)". Implement two (2) hours/week of on-campus consultation.
* **Step 7: Assessment**: Terms quizzes and exams. Grade weighting: Lecture (60% class standing, 40% term exam); Lecture-Lab hybrid (60% lecture class standing/exam, 40% lab activities).
* **Step 8: Payment Tracker**: Accounting provides weekly Delinquent Accounts Checklist.
* **Step 9: Discretion**: Private student advice. Offer scholarship and promissory note advice.
* **Step 10: Temporary Restrictions**: Attendance/LMS restrictions active during regular weeks. Exam weeks completely open. Next-term blockages and transcript holdings apply.
* **Step 11: Clearance**: Audit term-end papers. Issue INC for missed final exams.
* **Step 12: Grade Calculations**: Convert percentages to Point Grading Scale.
* **Step 13: Grade Release**: Host consultation sessions.
* **Step 14: Documentation**: Sign Master Grading Sheets, compile logs, and syllabi.
* **Step 15: Submission**: Handover signed papers and Excel files.
* **Step 16: Grade Reading**: Face-to-face cross-checks with Registrar using ruler.
* **Step 17: Correction**: Changes require Registrar-signed correction memos.
* **Step 18: Archive**: Stamped "VERIFIED & APPROVED FOR LEDGER ENTRY" and saved in locked cabinets.

### 6.4 College Point Grading Scale

| Percentage Range | Point Grade Value | Description |
| :--- | :--- | :--- |
| 98 - 100 | 1.00 | Excellent |
| 95 - 97 | 1.25 | Superior |
| 92 - 94 | 1.50 | Very Good |
| 89 - 91 | 1.75 | Good |
| 86 - 88 | 2.00 | Satisfactory |
| 83 - 85 | 2.25 | Average |
| 80 - 82 | 2.50 | Fair |
| 77 - 79 | 2.75 | Passable |
| 75 - 76 | 3.00 | Passing |
| Below 75 | 5.00 | Failure |
| INC | INC | Incomplete |
| DRP | DRP | Dropped |

---

## 7. ACCOUNTING / FINANCE OPERATIONS MANUAL

### 7.1 Accounting Role Segregation
* **Collector (Cashier)**: Physical intake of cash/payments and verification of GCash/Bank transfers. No Excel editing authority.
* **Recorder (Bookkeeper)**: Accesses student ledger cards in Excel, performs balance checks, writes paper receipts, and encodes digital Excel records. Must not collect cash.
* **Verifier (Supervisor)**: Reconciles actual cash and digital deposits against Excel entries and receipts at the end of the day.

### 7.2 Workflow A: Monthly Payment Reminders
1. **Due List**: Recorder prints list of due payments from Excel database.
2. **Notification**: Recorder broadcasts general reminder in Class GCs and sends private messages to students or parents.
3. **Amount Delivery**: Recorder provides exact outstanding fee calculations and available GCash/Bank details.

### 7.3 Workflow B: Online Payment Processing (GCash / Bank Transfer)
1. **Inquiry**: Student requests balance details.
2. **Confirmation**: Recorder checks Excel ledger and replies with confirmed payment amount.
3. **Submission**: Student sends payment and uploads transaction screenshot (Proof of Payment).
4. **Verification**: Collector checks school GCash/Bank app, verifies reference number/timestamp, and clears Recorder to post.
5. **Recording**: Recorder logs payment in Excel ledger and updates payment status.
6. **Student Confirmation**: Recorder sends verification confirmation message.
7. **Receipt Issuance**: Recorder writes paper Official Receipt (OR), photographs it, sends image to student, and archives duplicate.

### 7.4 Workflow C: Onsite Payment Processing
1. **Inquiry**: Student inquires balance at counter. Recorder confirms amount from Excel.
2. **Collection**: Collector accepts and counts cash in front of payee.
3. **Ledger Update**: Recorder logs transaction in Excel and updates index tracker.
4. **Receipt**: Recorder writes manual OR and hands it to student.

### 7.5 Workflow D: Enrollment & Admission Payments
1. **Slip Presentation**: Student presents Registration/Assessment Slip.
2. **Verification**: Recorder reviews downpayment requirement and applies authorized scholarship/discount rules.
3. **Collection**: Collector processes cash (Workflow C) or digital (Workflow B) payments.
4. **Log Book**: Recorder writes transaction in manual Enrollment Log Sheet (Student Name, Course, Date, Amount, Signature).
5. **Receipt**: Recorder writes manual OR and updates Excel database status to "Enrolled".
6. **Registrar Sync**: Recorder sends validated list to Registrar to authorize student card and class releases.

### 7.6 Workflow E: Manual Document Fees & LBC Shipping (Outside TALA)
> This is retained as institutional operating evidence only. TALA does not automate or track this workflow.

1. **Assessment**: Student requests document. Recorder calculates document service fee and notifies student. (Shipping cost excluded at this point).
2. **Payment**: Student pays document fee online (Workflow B). Recorder logs payment and writes OR.
3. **Release & Dispatch**: Registrar prepares document. School representative mails parcel via LBC. School advances shipping fee. LBC issues receipt showing tracking number and exact cost.
4. **Ledger Entry**: Recorder logs advanced LBC cost in student's Excel ledger as a Miscellaneous Fee.
   * *Hold Rule*: Outstanding shipping balances block subsequent document requests and trigger record release holds.
5. **Notification**: Recorder photographs LBC receipt and sends it to student.
6. **Settlement**: Student chooses payment path:
   * Option A: Pay shipping fee immediately via GCash/Bank (Workflow B).
   * Option B: Merge cost into general ledger to settle at next billing/clearance cycle.

### 7.7 Workflow F: Daily Reconciliation & Cash Turnover
1. **Turnover**: Collector totals physical cash and GCash transaction summaries. Hands over cash to Verifier.
2. **Report**: Verifier prepares Daily Collection Report showing exact collections.
3. **Reconciliation Audit**: Verifier audits three-way parity: Excel Ledger = Receipts = Cash. Reconciles every manual receipt.

### 7.8 Financial Clearance & Digital Backups
* **Clearance**: If student ledger shows ₱0.00 balance, Recorder and Verifier sign Financial Clearance slip and forward it to Registrar. Active balances trigger Statement of Account (SOA).
* **Backups**: Recorder saves digital Excel ledger backups to Google Drive at the end of every business day.

---

## 8. PRINCIPAL & ACADEMIC HEAD WORKFLOW

### 8.1 Phase 1: Pre-Academic Year Preparation & Planning
*Timeline: 1–2 months before term classes*
* **Calendar & Schedule Setup**: Draft academic calendar. Ensure total days meet DepEd/CHED minimums. Plan class sections, assign faculty workloads, and establish exam timelines.
* **Curriculum Alignment**: Conduct curriculum evaluations. Ensure syllabus and outcomes align with DepEd K-12/CHED. Formally approve coordinators' teaching files.
* **Operations Sync**: Review enrollment projections, plan sections with Registrar, and sync with Accounting on course fee matrices.
* **Accreditation & Quality Assurance**: Confirm faculty compliance and facility readiness against PAASCU/CHED/DepEd regulations.

### 8.2 Phase 2: Term Operations & Ongoing Management
*Timeline: Weekly/Daily during active academic term*
* **Operational Meetings**: Conduct bi-weekly alignment sessions with department chairs to monitor curriculum pacing.
* **Instructional Audits**: Perform classroom walk-throughs, audit teacher lesson plans, and verify assessment methods.
* **Guidance Coordination**: Review counseling reports on student behavior or drops in performance. Ensure guidance programs are active.
* **Enforcements**: Resolve grade disputes, parent complaints, and academic integrity violations. Apply handbook rules.

### 8.3 Phase 3: Periodical Evaluation & Interventions
*Timeline: Midterm/Quarterly*
* **Academic Review**: Collect and review grade distributions and fail-rates at the end of each grading term.
* **Interventions**: Approve teacher-led remedial programs, tutorials, or bridge classes for at-risk students.
* **Academic Probation**: Co-sign probation letters. Meet with students and parent/guardian alongside Guidance Counselor.

### 8.4 Phase 4: Year-End Performance Audit & Compliance
*Timeline: 1 month before up to end of academic year*
* **Academic Decisions**: Review final rosters and grade charts. Approve graduation candidates, honor rolls, and award recipients. Rule on probationary status students.
* **Institutional Audit**: Reconcile school performance metrics against year targets (NAT tests, cohort survival rates).
* **Accreditation Reporting**: Compile annual report files for DepEd/CHED and accreditation audits.
* **Executive Summary**: Present school status logs to the Board of Trustees and Director to plan the next year.
