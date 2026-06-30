## 10. Grades

---

### 10.1. Grades (Period Equivalents Only)

The SIS captures only the **Period Equivalents** (Prelim Equiv, Midterm Equiv, Final Equiv) and auto-computes the General Average using the Servitech v1 institutional formula: **30% Preliminary + 30% Midterm + 40% Final**.

Faculty use school-approved Excel templates to compute raw quiz, exam, class-standing, lecture, or laboratory source scores. For Servitech v1, school templates may compute lecture subjects from 60% class standing + 40% term exam, and lecture-laboratory hybrids from 60% lecture class standing/exam + 40% laboratory activities. TALA captures only the final Period Equivalents entered into the SIS; raw template calculations remain outside TALA.

TALA converts the computed General Average to the released numeric grade using the active Servitech v1 scale:

| Released grade | General Average range |
| --- | --- |
| `1.00` | 98-100 |
| `1.25` | 95-97 |
| `1.50` | 92-94 |
| `1.75` | 89-91 |
| `2.00` | 86-88 |
| `2.25` | 83-85 |
| `2.50` | 80-82 |
| `2.75` | 77-79 |
| `3.00` | 75-76 |
| `5.00` | Below 75 |

The passing threshold is `3.00`, corresponding to 75%. `INC`, `P`, and `DRP` are controlled non-numeric outcomes and are not produced by the numeric conversion scale.

Grade rosters are generated from official enrolled class rosters. One official course enrollment creates one grade row for the student.

Lecture and Laboratory components of one Course Specification Revision share one released grade unless the institution defines separate subject codes or separate released grades. Grade rosters are created from the official course enrollment, not from each component schedule row.

If different faculty are assigned to linked Lecture and Laboratory components, TALA still keeps one released grade roster for the course. The institution decides outside TALA how the assigned faculty coordinate the final Period Equivalent values before roster submission.

Administrative class standing recorded for an approved Subject Drop, Withdrawal, or Leave of Absence is lifecycle metadata when institutional policy requires it. Student grade history shows released Grade Outcomes only.

Allowed Grade Outcome categories for v1:

1. `Passing` — released numeric grade that satisfies completion and prerequisites according to policy.
2. `Failed` — released failing numeric grade, such as 5.00, that does not satisfy completion or prerequisites.
3. `Incomplete` — `INC`, a temporary student-academic mark requiring completion/removal within the institutional deadline.
4. `Pending Grade` — `P`, a temporary administrative mark used when the faculty grade is not yet finalized or encoded.
5. `Withdrawn` — lifecycle-derived outcome from an approved Subject Drop, Withdrawal, or Leave of Absence.
6. `Transfer Credit` — `TC`, an approved external credit result used for curriculum and prerequisite checks but excluded from GWA.

Rules:

1. `INC` is not the same as failed. It is resolved through completion/removal or lapses according to institutional policy.
2. `P` is not the same as `INC`. It means the faculty result is pending; it does not mean the student failed or lacks academic requirements.
3. `P`, `INC`, blank, withdrawn, dropped, and failed outcomes do not satisfy prerequisites by default.
4. Enrollment that depends on a pending prerequisite grade requires a scoped Academic Exception.
5. `P`, `INC`, lifecycle-derived withdrawn outcomes, and `TC` are excluded from GWA unless institutional policy explicitly defines otherwise.
6. Blank grade cells remain internal incomplete roster state. They are not released to the student as official grade marks.
7. Faculty may submit `INC` as a controlled roster outcome according to institutional rules. Registrar still controls Post & Release and records approved corrections after posting.
8. `DRP` is a controlled non-numeric dropped outcome used when the official lifecycle record produces a dropped result; it is not a faculty-entered numeric grade.
9. `W` is not a faculty-entered grade mark by default. If Servitech formally uses `W` as a printed grade mark, System Super Admin enables it as a controlled Grade Outcome label mapped to the lifecycle-derived withdrawn category.
10. School-specific unresolved marks such as `Not S` require formal adoption as controlled Grade Outcomes before use.

---

### 10.2. Flat Grade Release Policy

The multi-stage grade review (Draft -> Head Review -> Return -> Post) is flattened:

1. Faculty encodes Period Equivalents and hits "Submit".
2. Grades route directly to the Registrar queue.
3. Registrar clicks "Post & Release" in a single action.

The Academic Calendar strictly governs when the Faculty Workspace is open for encoding.

Grade window rules:

1. Faculty may encode during the active Grade Encoding window.
2. When the window closes, unsubmitted rosters are marked `Late / Not Submitted`.
3. Authorized Registrar or Academic Head staff may open a scoped Late Grade Encoding Authorization for a specific class section, faculty member, grading period, reason, and deadline.
4. Faculty grade entry opens during the active window or a scoped late authorization.
5. When a final grade later replaces `P`, TALA preserves the previous pending outcome and replacement history.

Pending-grade enrollment rule:

1. If a student needs to enroll while a prerequisite grade is `P`, the Academic Progression Gate remains failed unless a scoped Academic Exception is recorded.
2. If the final replacement grade is passing, the pending issue resolves.
3. If the final replacement grade is failing, TALA flags the affected enrollment for Registrar or Academic Head review.
4. When a pending grade later becomes failing, TALA flags the affected official enrollment for Registrar or Academic Head review.

---

### 10.3. INC Completion / Removal

INC completion/removal follows the institution's approved academic policy.

Required INC resolution record:

1. Student ID.
2. Class / Subject / Term.
3. Original `INC` Grade Outcome.
4. Completion or removal deadline.
5. Final replacement grade or lapsed result.
6. Decision Authority.
7. Evidence Reference, when required.
8. Recorded By and Recorded At.
9. Audit Metadata.

Rules:

1. INC resolution is Registrar-recorded or Registrar-controlled according to institutional policy.
2. INC does not satisfy prerequisites until replaced by a released passing outcome or covered by a scoped Academic Exception.
3. Student Hub shows `INC` as Incomplete and shows the student-facing deadline when configured.
4. Lapsed or unresolved INC outcomes follow the configured institutional result, such as conversion to failed.
5. The INC completion/removal deadline and lapsed-INC result are configured institutional policy values for implementation; TALA does not invent a hard default when Servitech has not supplied one.

---

### 10.4. Grade Correction (Official Appeal & Manual Entry Policy)

Grade correction follows the physical school policy after posting. Registrar records approved posted-grade corrections in TALA.

Flow:

Physical correction approval happens outside TALA -> Registrar records the authorized correction action inside TALA -> TALA logs the correction and checks affected source-derived outputs.

Required correction record:

1. Student ID
2. Class / Subject / Term
3. Previous Grade
4. Corrected Grade
5. Reason
6. Approving Authority
7. Evidence Reference
8. Recorded By
9. Recorded At
10. Affected Output Review
11. Audit Metadata

Rules:

1. Faculty may respond to returned grade rosters before posting.
2. Posted grade corrections are recorded by the Registrar only.
3. Approved corrections must preserve the previous grade for audit.
4. Student Hub shows released corrected grade values with student-facing labels.

---

### 10.5. Grade Interaction Contract

| Information or action | Required interaction form |
| --- | --- |
| Faculty class selection | Selection List limited to the faculty member's assigned course sections and active grade window; linked Lecture/Laboratory meetings for the same course do not appear as separate grade rosters by default even when different faculty teach the components |
| Period Equivalent or allowed mark entry | Class-roster Editable Table: one student per row and one controlled numeric/allowed-mark field per configured period |
| General Average | Read-only computed column using the active institutional grading formula |
| Draft save | Table action preserving incomplete allowed rows without posting them |
| Submit roster | Read-only validation summary showing missing/invalid values, followed by explicit submission confirmation |
| Registrar review | Operational Queue / Review Table with class-level completeness, anomalies, submission history, and focused return or Post & Release actions |
| Return before posting | Focused action requiring a correction reason; Faculty reopens the same roster table |
| Late grade encoding authorization | Focused Record Form selecting class section, faculty, grading period, reason, approver, and open-until date/time |
| INC completion/removal | Registrar Record Form selecting one INC outcome and recording replacement result, deadline, authority, evidence reference, and audit metadata |
| Posted grade correction | Registrar Record Form selecting one posted student grade and recording previous value, corrected value, reason, authority, and evidence reference |
| Student grade history | Generated Read-Only View showing released numeric grades, `INC`, `P`, lifecycle-derived withdrawn labels, and `TC` with student-facing labels only |

V1 grade entry uses direct roster-table encoding of Period Equivalents. Registrar records approved posted-grade corrections after the physical school policy is completed.

---
