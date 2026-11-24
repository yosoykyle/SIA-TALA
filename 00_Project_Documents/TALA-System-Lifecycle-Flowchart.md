# TALA whole-system lifecycle flowchart

## How this is organized, and why

Start with D0, then open the relevant journey in D1–D6. These diagrams explain the **academic and data lifecycle**. The implementation sequence, assignments, progress and completion evidence live in [#48](https://github.com/yosoykyle/SIA-TALA/issues/48). D1–D6 are diagram labels, not delivery stages or #48 decision IDs. This reader describes the approved product; it does not establish that the code or rendered journeys conform.

The goal is one simple, connected college system: applicants understand their next step; staff work from current evidence; students can enroll, see their accounts and released results; authorized staff produce reliable official records. CP-SAT timetable generation is the principal research innovation. Independent journeys can be developed separately; their shared sources must agree when connected.

All seven roles appear here: **Applicant, Student, Registrar, Faculty, Accounting, Academic Head and System Administrator**, alongside Public visitors. Role access remains server-authorized. The Academic Head inspects authorized academic sources read-only; the System Administrator manages access, bounded public content, Health and Audit rather than academic or financial decisions.

Exact fields, validation, deadlines, permissions and transaction guards remain in the [canonical PRDs](prd_modules/README.md). The [UI Surface Blueprint](ui_surface_blueprint.md) owns observable role outcomes; [PRODUCT.md](../PRODUCT.md), [DESIGN.md](../DESIGN.md) and applicable surface briefs guide composition. The [Architecture Specification](architecture_specification.md) owns integration and deployment boundaries.

## Legend: every shape, line, and color, drawn for real

Boxes are tasks or source facts; diamonds are decisions; rounded boxes are entry or exit points. Solid arrows show the next step or a dependency; dotted arrows show a projection or feedback to another journey. Labels identify the actor and source, so color is supplementary: green for an official result, amber for a decision and light red for recovery. D0–D6 links let the reader follow a shared dependency without repeating its full rules.

Across every journey:

- Lead with the task, current status and next permitted action. Retain exact person, Term and version when opening a source or returning; provide understandable navigation, responsive keyboard access and concise error recovery.
- Reuse verified facts. Routine actions capture actor/time automatically; routine recording references are optional. Genuine exceptions and institutional curriculum, fee or policy evidence retain their actual authority requirements.
- Reject stale, unauthorized or conflicting actions without partial changes. Preserve immutable versions and attributable corrections; a display filter never narrows an official command's scope.
- Show only the named action restriction and its owner/remedy. Academic or financial restrictions do not block sign-in and do not create a general Holds & Blockers engine.
- Email failure never reverses a saved academic or money fact. Send selective action-required and official-result messages after commit via TALA's Filament database notification bell (`#database-notifications`) across Staff, Student, and Faculty panels alongside email and operational action queues.
- Keep the accepted recovery animation finite, settling within five seconds, with immediate recovery actions and a reduced-motion equivalent. Remove the visible pause/stop button on recovery. Continuing public hero motion retains its separate accessibility controls.

## D0 — Overview: the whole lifecycle on one screen

Academic planning and admissions can proceed in parallel. Enrollment consumes both; payment and academic results feed back into their owning actions.

```mermaid
flowchart LR
  PUBLIC(["Public entry"]) --> ACCESS["D1 Access and authorized workspace"]
  ACCESS --> CATALOG["Programs & Curricula (Programs, Subject catalog, Curricula & incoming batches)"]
  ACCESS --> RESOURCES["School Resources (setup-once campus rooms & cross-term qualified faculty)"]
  CATALOG --> SETUP["D3 Term Planning (Steps 1–4: 1. Calendar → 2. Classes & sections → 3. Faculty & rooms → 4. Timetable → Publish official timetable)"]
  RESOURCES --> SETUP
  SETUP -->|Existing Term, active Program & CurriculumVersionBatch| ADMISSION["D2 Admissions (AdmissionCycle & readiness)"]
  SETUP -->|Published classes & consumer dates| ENROLL["D4 Students & Enrollment"]
  ADMISSION -->|Ready Applicant| ENROLL
  ACCOUNTS_PLAN["D6 Accounting (Published Fee Plan)"] -->|Fee Plan prerequisite| ENROLL
  ENROLL -->|Assessment source| ACCOUNTS["D6 Accounts and payments"]
  ACCOUNTS -->|Exact due-now clearance| ENROLL
  ENROLL -->|Official class roster| GRADES["D5 Grades & Records"]
  SETUP -->|Grade Entry dates| GRADES
  GRADES -.->|Released-result eligibility| ENROLL
  ENROLL -.->|Unmet class demand| SETUP
  SETUP -.->|Timetable revision impacts| ENROLL
  GRADES -->|Academic snapshot| TOR["D5 TOR preview and issue"]
  ACCOUNTS -->|Applicable output restriction| TOR
  ENROLL -->|First official enrollment| STUDENT(["Student identity and COR"])
  CLOSE["Term::StateClosed lifecycle closure"] -.->|Locks Admissions, Enrollment, Grades & new Fee Assessments| ADMISSION
  CLOSE -.-> ENROLL
  CLOSE -.-> GRADES
  CLOSE -.-> ACCOUNTS
  classDef paperBox fill:#4CB05E,stroke:#000000,color:#000000;
  class STUDENT,TOR paperBox;
```

[Access](#d1--public-entry-sign-in-accounts-and-staff-access), [Admissions](#d2--admissions-application-review-decision-and-readiness), [Academic setup](#d3--academic-setup-catalog-calendar-classes-and-timetable), [Enrollment](#d4--enrollment-proposal-seats-finalize-certificate-of-registration-and-changes), [Grades](#d5--grades-records-graduation-degree-award) and [Accounts](#d6--accounts-payment-clearance-official-outputs) give the detailed paths below.

## D1 — Public entry, sign-in, accounts and Staff access

Authority: [PRD 01](prd_modules/01_identity_access_public_entry.md).

```mermaid
flowchart TB
  ENTRY(["Public: programs, notices, answers, Apply and Sign in"])
  ENTRY --> CHOICE{"New Applicant or existing account?"}
  CHOICE -->|New Applicant| SIGNUP["Applicant: register and verify email"]
  CHOICE -->|Existing account| LOGIN["Person: sign in"]
  SIGNUP --> LOGIN
  LOGIN -->|Applicant or Student| ROLE["Open authorized workspace; choose role when needed"]
  LOGIN -->|Staff| SECURE["Staff: complete required MFA"]
  SECURE --> ROLE
  ROLE --> WORK(["Continue to D2, D3, D4, D5 or D6"])
  LOGIN -->|Access problem| RECOVER["Use account recovery or authorized access support"]
  RECOVER --> LOGIN
  ADMIN["System Administrator: invite staff and manage fixed role access"] -.-> ROLE
  classDef askBox fill:#F1AC4B,stroke:#000000,color:#000000;
  classDef warnBox fill:#F87777,stroke:#000000,color:#000000;
  class CHOICE askBox;
  class RECOVER warnBox;
```

Public content is bounded About/program information, current announcements and useful FAQ/location facts. Apply is the main entry action; Programs browsing and source-backed Read more details remain available. New events/news and a general CMS are deferred. When Apply is unavailable, its reason is clear and Sign in remains available. Application status belongs to the signed-in Applicant's own Home; the public status-request/link journey is retired without deleting application or audit history.

One account can carry several authorized roles; the active workspace keeps that context clear. Password reset, verified email changes, session recovery and staff MFA follow PRD 01. The System Administrator cannot grant academic or financial decision authority by editing a screen or bypassing a role check.

## D2 — Admissions: application, review, decision and readiness

Authority: [PRD 02](prd_modules/02_application_admission_decision_enrollment_readiness.md).

```mermaid
flowchart TB
  CONTEXT["Applicant: choose supported program and applicant type"]
  CONTEXT --> CYCLE["TALA: derive the unique applicable Admission Cycle"]
  CYCLE --> FORM["Applicant: complete five-step application; save or exit"]
  FORM --> SUBMIT["Applicant: review and submit; see on-screen receipt"]
  SUBMIT --> REVIEW["Registrar: start review; inspect evidence and identity in one context"]
  SUBMIT -->|Optional before review starts| EDIT["Applicant: append validated edits"]
  EDIT --> REVIEW
  REVIEW --> EVIDENCE["Registrar: review preliminary evidence"]
  EVIDENCE --> OUTCOME{"Application screening: what does the evidence support?"}
  OUTCOME -->|Correction needed| FIX["Applicant: respond to the named correction"]
  FIX --> REVIEW
  OUTCOME -->|Not admitted| HISTORY(["Final rejection notice and retained history"])
  OUTCOME -->|Admitted; papers pending| PAPERS{"Registrar: check enrollment papers"}
  OUTCOME -->|Admitted; checks already confirmed| READY(["Ready for enrollment"])
  PAPERS -->|Deficiencies| NOTIFY["Applicant: bring the missing papers"]
  NOTIFY --> PAPERS
  PAPERS -->|Cleared, or cleared with a permitted held paper| READY
  PAPERS -->|Paper deadline passed| EXPIRED["Expired: Registrar may re-admit with a new deadline"]
  EXPIRED --> PAPERS
  READY -->|Applicant starts, or Registrar starts for a walk-in| D4(["Enrollment case in D4"])
  PERIOD(["Admission period ended: unfinished application Closed, final"])
  classDef askBox fill:#F1AC4B,stroke:#000000,color:#000000;
  classDef warnBox fill:#F87777,stroke:#000000,color:#000000;
  class OUTCOME,PAPERS askBox;
  class FIX,NOTIFY,EXPIRED warnBox;
```

Registrar publishes source-backed cycles (`AdmissionCycle`, which depends on an existing `Term`, active `Program`, and `CurriculumVersionBatch`) and requirement sets. An Applicant chooses ordinary program/type context rather than internal cycle identifiers. Ambiguous configuration goes to Registrar setup; existing application bindings stay stable. The form covers scope, personal/contact facts, Educational Background, preliminary evidence and review. Notice acknowledgment and optional sensitive-field consent remain separate.

Draft saving and first submission recheck their public window. A submitted application can append validated successor versions until **explicit authorized review starts**; merely opening history or inspecting read-only facts does not start that lock. Afterwards only named correction scope reopens. Final decisions lock ordinary edits. Corrections and resubmissions retain unaffected uploaded files and metadata; authorized replacement preserves prior versions. A correction has its own due boundary and recovery; an overdue active correction does not automatically reject the application.

Use common correction/decision reasons plus Other, keeping the private decision basis separate from Applicant instructions. Review, evidence preview, identity resolution, decision and clearance share one working context, guided by the record's **Process evaluation** checklist. Preliminary evidence review comes before **Application screening**, and Not admitted is final. Combine admission and papers confirmation only when the physical requirement checks are already satisfied; otherwise **Check enrollment papers** records each paper's result, notifies deficiencies, and may clear with a permitted non-core paper on hold. This creates readiness, not Student identity, fees or official enrollment. A ready applicant starts enrollment, or the Registrar starts it from the admission record for a walk-in; payment stays inside the enrollment case.

Optional Cycle deadlines add two derived outcomes (PRD 02 §7.4). An admitted application that misses the paper or payment deadline becomes **Expired**, and the Registrar may re-admit it with a new deadline. Once the admission period ends, any unfinished application becomes **Closed**, which is final and takes priority over Expired. Officially enrolled applications are never either.

Discard applies to eligible Drafts. Withdrawal before registration starts, authorized reopening/reconsideration and all prior versions retain their PRD guards. New submission uses an on-screen receipt; historically issued acknowledgments keep authorized access and complete immutable print facts. Applicant Home shows current status, the next action and contextual returns.

## D3 — Academic setup: catalog, calendar, classes and timetable

Authority: [PRD 03](prd_modules/03_academic_setup_offerings_published_timetable.md); Faculty projections also follow [PRD 05](prd_modules/05_teaching_grades_academic_records_completion.md).

```mermaid
flowchart TB
  subgraph GLOBAL["Global Setup-Once Foundations"]
    CATALOG["Registrar: Programs, Subject catalog, Curricula & incoming batches"]
    AY["Registrar: Academic Years (auto-activating on first active term)"]
    SCHOOL_RES["Registrar: School Resources (campus rooms & cross-term qualified faculty with Program/GE filter)"]
  end
  subgraph TERM["Per-Term Planning (Steps 1–4)"]
    STEP1["Step 1 Calendar: prepare and activate exact-Term core calendar"]
    STEP2["Step 2 Classes & sections: Load from curriculum (1-step offering generation + capacity headroom)"]
    STEP3["Step 3 Faculty & rooms: per-term faculty availability, load caps, and room closures"]
    CHECK{"Whole-Term generation inputs current and valid?"}
    REMEDY["Correct source or retry as outcome requires; preserve class preparation"]
    STEP4["Step 4 Timetable: Generate / Regenerate via OR-Tools CP-SAT"]
    ADJUST["0ms inline local adjustments, Lock slot, Undo last change / Reset to solver draft"]
    DECIDE{"Current candidate satisfies every hard constraint?"}
    PUBLISH["Publish official timetable + read-only Timetable audit record"]
    CLOSED["Term::StateClosed: locks Admissions, Enrollment, Grades & new Fee Assessments"]
  end
  CATALOG --> STEP2
  AY --> STEP1
  STEP1 --> STEP2
  SCHOOL_RES --> STEP3
  STEP2 --> CHECK
  STEP3 --> CHECK
  CHECK -->|Missing or conflicting| REMEDY
  REMEDY --> CHECK
  CHECK -->|Ready| STEP4
  STEP4 --> ADJUST
  ADJUST --> DECIDE
  DECIDE -->|No or no candidate| REMEDY
  DECIDE -->|Yes| PUBLISH
  PUBLISH --> VIEWS(["Faculty own Schedule; Head inspection; D4 placement"])
  PUBLISH -->|Authorized revision| REVISION["Registrar: validate successor and resolve enrollment impacts"]
  REVISION --> VIEWS
  PUBLISH --> CLOSED
  classDef askBox fill:#F1AC4B,stroke:#000000,color:#000000;
  classDef warnBox fill:#F87777,stroke:#000000,color:#000000;
  classDef paperBox fill:#4CB05E,stroke:#000000,color:#000000;
  class CHECK,DECIDE askBox;
  class REMEDY warnBox;
  class PUBLISH paperBox;
```

The core calendar (Step 1) fixes the grid at 30 minutes and records approved teaching dates, weekly hours, breaks and no-class dates. Core activation does not wait for unrelated operational windows or the Faculty reply deadline. Enrollment and Grade Entry dates govern their own consumers. Missing optional Late Enrollment, Adjustment or Drop dates keep only those actions closed unless the exact authorized late path applies. Faculty reply due belongs to generation readiness. Examination Period is informational on Student Home and Faculty Schedule, with Registrar source editing and authorized Head inspection; arranging exams remains external.

Global setup-once **School Resources** (campus rooms and cross-term qualified faculty with Program/GE filter) and **Academic Years** (auto-activating on the first active term) feed into per-term **Term Planning (Steps 1–4)**. In Step 2, `Load from curriculum` performs 1-step offering generation with capacity headroom. Shared classes count once globally. Curriculum-year coverage findings are advisory; do not require every year in every Term or invent a cohort year/count. Class preparation remains available while Step 3 per-term faculty availability, load caps, and room closures are completed. Existing curriculum import remains available under its source guards.

In Step 4, `Generate` / `Regenerate` runs OR-Tools CP-SAT against **all recorded classes in the exact Term** and a coherent captured source version. Display filters never narrow generation. Distinguish Optimal, Feasible, Infeasible, Unknown, ModelInvalid and TechnicalFailure: an unknown result or technical failure does not prove infeasibility. Show truthful metric units and named source remedies. Registrars can apply 0ms inline local adjustments, `Lock slot`, `Undo last change`, or `Reset to solver draft`. Independent hard-constraint validation governs publication and local adjustments; never waive a hard constraint or silently accept a partial repair.

Candidate and official timetable share one destination (`Publish official timetable`) with a read-only `Timetable audit record`, clearly retaining their different state/version. Print this view and complete-schedule output preserve scope. Faculty sees only own official assignments, availability response/corrections and attributable history; Head reads authorized sources. Revisions preserve prior official snapshots and coordinate affected enrollment placements before changing their current projections. `Term::StateClosed` governs lifecycle closure, locking Admissions, Enrollment, Grades, and new Fee Assessments.

## D4 — Enrollment: proposal, seats, finalize, Certificate of Registration and changes

Authority: [PRD 04](prd_modules/04_current_term_registration_official_enrollment.md), using [PRD 06](prd_modules/06_accounts_official_outputs_operations_assurance.md) finance and released [PRD 05](prd_modules/05_teaching_grades_academic_records_completion.md) results.

```mermaid
flowchart TB
  PERSON["Ready Applicant or continuing Student: open eligible exact-Term case"]
  PERSON --> STANDARD{"Standard curriculum proposal unambiguous?"}
  STANDARD -->|Yes| DERIVE["TALA: derive subjects from current curriculum and released results"]
  STANDARD -->|No| ADVISE["Registrar: resolve deviations and explain advised subjects"]
  DERIVE --> PREPARE["Reserve valid seats and obtain Published Fee Plan assessment"]
  ADVISE --> PREPARE
  FEEPLAN["D6: Published Fee Plan"] --> PREPARE
  PREPARE --> CONFIRM["Student or applicant: review Why these subjects, schedule and assessment; confirm"]
  CONFIRM --> MONEY["D6: verify exact payment, coverage or authorized no-payment basis"]
  MONEY --> FINAL["Registrar: finalize individually or batch with atomic per-case rechecks"]
  FINAL --> OFFICIAL(["Official enrollment, roster and COR; first Student identity only now"])
  PREPARE -->|Shortage or missing source| FIX["Named owner corrects class, eligibility or finance source"]
  FIX --> PREPARE
  CONFIRM -->|Material revision| PREPARE
  PERSON -->|Cancel or final cutoff expiry| CLOSED(["Release seats; retain case and money history"])
  OFFICIAL --> CHANGE["Registrar: authorized adjustment or drop with connected rechecks"]
  CHANGE --> OFFICIAL
  classDef askBox fill:#F1AC4B,stroke:#000000,color:#000000;
  classDef warnBox fill:#F87777,stroke:#000000,color:#000000;
  classDef paperBox fill:#4CB05E,stroke:#000000,color:#000000;
  class STANDARD askBox;
  class FIX warnBox;
  class OFFICIAL paperBox;
```

Opening one eligible Registration Case derives the unambiguous standard proposal, temporary reservations and assessment **before student or applicant confirmation or payment**. It does not create cases for everyone merely because Enrollment opens. Ambiguous or exceptional selection goes to Registrar advice, promptly explaining the actual reason; this is not unrestricted course shopping or a permanent Regular label.

Current admission/identity facts, curriculum, released prerequisites/credits, published classes, schedule, capacity and load remain real checks. Reuse verified identity/contact facts rather than asking for another confirmation. A stale or missing source names its owner and recovery. Consumer windows and genuine late authority govern the relevant action. Registrar sees Students & Enrollment; Accounting sees focused Student Accounts from the same underlying records.

Finalization rechecks current sources, exact student or applicant confirmation, reservations and due-now finance evidence atomically for each case. Only the first successful official enrollment creates the minimal Student profile, permanent student number and Student access on the existing account. It creates the official roster and immutable COR version; retries and failed mail cannot duplicate or reverse them.

Expiry or cancellation releases seats once and preserves money history in the same Term Account. Authorized reopening restores no stale proposal, confirmation, reservation, assessment or clearance automatically. Retained funds require Accounting reconciliation, not automatic refunds or lost history.

Adjustments and drops retain their window or exact late authority, placement/load/capacity checks and material student confirmation. An add/replacement needs cleared additional cost or an authoritative no-additional-amount result. Removals route applicable finance review; TALA does not invent a refund. Released grade or timetable changes invalidate affected preparation before finalization and trigger Registrar impact review after enrollment. Existing official facts remain until an authorized change is applied. COR history stays immutable; live finance remains in the account view.

## D5 — Grades, records, graduation, degree award

Authority: [PRD 05](prd_modules/05_teaching_grades_academic_records_completion.md).

```mermaid
flowchart TB
  ROSTER["Faculty: open own official class roster"]
  ROSTER --> RESULTS["Designated Faculty: save drafts and submit complete results"]
  RESULTS --> REVIEW["Registrar: review; return named rows or release atomically"]
  REVIEW -->|Return| RESULTS
  REVIEW --> RELEASED["Student: see released results, averages and curriculum progress"]
  RELEASED -.->|Eligibility and affected-case review| ENROLL(["D4 current-term registration"])
  RELEASED --> INC["INC: completion, overdue state or authorized extension; preserve history"]
  INC --> REVIEW
  RELEASED --> GRAD["Registrar: evaluate full curriculum and actual external institutional result"]
  GRAD --> AWARD(["Record graduation once; retain degree and approved honor facts"])
  RELEASED --> TOR["Registrar: request and preview exact TOR for current or former Student"]
  TOR --> RESTRICTION["D6: check any current OUTPUT-scoped restriction"]
  RESTRICTION --> ISSUE(["Registrar: issue immutable TOR after source and rendering checks"])
  LIFE["Registrar: record authorized leave, full withdrawal or return"] -.-> ENROLL
  classDef paperBox fill:#4CB05E,stroke:#000000,color:#000000;
  class AWARD,ISSUE paperBox;
```

Only designated Faculty author results for official roster members. Registrar returns specified rows, releases complete results and records genuine authorized corrections. Released results, not Drafts or fabricated zeros, govern Student Academics, the Unofficial Student Record, averages and curriculum evaluation. Preserve PRD 05's numeric inclusion rules, completeness explanations, attempt history, exact approved credits and satisfying retakes.

INC completion follows its current deadline and authorized release path. An overdue INC keeps its historical mark and no-credit consequence; time does not invent a grade or send an overdue email. A valid future extension can reopen completion. A satisfying released retake or exact approved credit can satisfy the curriculum requirement without overwriting the old INC. Pending and excluded nonnumeric attempts affect average readiness according to PRD 05, rather than making every historic INC a universal blocker.

Leave, full withdrawal, return and other genuine academic restrictions block their named actions. Lifecycle changes preserve source authority and connected enrollment/account effects. Further program-shift/transfer UI refinement is deferred; existing history and guards remain.

Use one Record graduation action after full curriculum evaluation and the actual externally approved institutional result. Recording reference/date fields are optional; actual degree, effective date and approved honor facts must be real. Do not fabricate conferral or recreate an internal graduation-approval system. Keep the labelled synthetic external-competency authority-guard demonstration; it is not evidence that the institution adopted the hypothetical requirement.

A TOR can be issued for an authorized current or former Student **without a graduation record**. Request → preview → issue checks verified identity, releasable academic snapshot, request clock/reference, applicable current OUTPUT restriction, template/signatories and successful rendering. Accounting supplies the restriction source rather than approving each TOR request. Failure creates no partial artifact. Physical signature, seal, claiming/delivery and CAV remain external. Further replacement, conferral-correction, external-result and INC-amendment UI refinement is deferred; retained history, safety guards and core INC extension remain available.

## D6 — Accounts, payment, clearance, official outputs

Authority: [PRD 06](prd_modules/06_accounts_official_outputs_operations_assurance.md); operations also follow [Architecture](architecture_specification.md) and the [Runbook](TALA-Deployment-Recovery-Runbook.md).

```mermaid
flowchart TB
  SOURCE["Accounting: published Fee Plan or eligible authorized individual assessment"]
  SOURCE --> ACCOUNT["One Term Account view: assessment, coverage, payment and due-now basis"]
  ACCOUNT --> PAY{"How is the exact current requirement satisfied?"}
  PAY -->|Bank transfer or e-wallet receipt| VERIFY["Accounting: inspect submitted receipt, verify amount, and map OR"]
  PAY -->|Cash at Campus Cashier| ONSITE["Accounting/Cashier: record onsite payment directly with OR"]
  PAY -->|Coverage or authorized zero due| COVER["Accounting: record current source-backed basis"]
  VERIFY --> CLEAR["Publish current clearance to D4"]
  ONSITE --> CLEAR
  COVER --> CLEAR
  VERIFY -->|Discrepancy or rejected proof| RESOLVE["Accounting: reject or resolve with retained history"]
  RESOLVE --> ACCOUNT
  ACCOUNT --> SOA(["Statement of Account with payment acknowledgment"])
  ACCOUNT --> OUTPUT["Current named OUTPUT restriction and recovery owner for D5 TOR"]
  ADMIN["System Administrator: read core Health and Audit evidence"]
  classDef askBox fill:#F1AC4B,stroke:#000000,color:#000000;
  classDef warnBox fill:#F87777,stroke:#000000,color:#000000;
  classDef paperBox fill:#4CB05E,stroke:#000000,color:#000000;
  class PAY askBox;
  class RESOLVE warnBox;
  class SOA paperBox;
```

Use one understandable assessment presentation, preserving distinct Fee Plan, individual assessment, coverage and payment source records. Missing fee authority is unavailable, not an invented FeeRule rate or zero amount. Show the exact amount required now and its basis; total account balance is not a universal zero-balance gate.

Payment is manual-first across approved school channels configured in Accounting's **Payment channels** settings (multi-account `Bank Transfer`, multi-account `E-Wallets` replacing standalone GCash, and `Cash (Campus Cashier)`). Students and applicants view **How to pay** instructions and use `Submit receipt` only when at least one digital channel (`Bank Transfer` or `E-Wallets`) is enabled, viewing or downloading uploaded files in the shared `<x-document-viewer>` modal; when only onsite Campus Cashier is active, no student receipt upload UI appears. Onsite Campus Cashier payments are recorded directly by Accounting/Cashier inside Student Accounts for both enrolled Students and pre-student Applicants without requiring a student receipt upload. Submitted receipt evidence captures an immutable `channel_snapshot`, stays private, and is externally verified; Official Receipt (OR) numbers can be recorded at posting or mapped inline later on verified posted payments missing an OR. Resolve receipt discrepancies, rejections, and externally completed refunds/reversals in one Accounting context with attributable, append-only history; executing refunds remains external.

Payment acknowledgment belongs in the Statement of Account and is not a tax receipt. Historically issued separate acknowledgments retain authorized access, snapshots and print guards. A current OUTPUT-scoped restriction affects only its named official output; there is no per-request approval queue or blanket service hold. Unknown/stale source evidence closes the affected action with an owner and remedy.

Health covers mail and the solver with attributable read-only evidence; unknown is not healthy. Further Health beyond those two is deferred, without removing production backup/recovery duties from the Runbook. Audit remains authorized, read-only and privacy-scoped; record retention/disposal decisions remain external. Further refinement of the three CSV exports is deferred; existing exports, authorized operational prints and their data/access safety remain. Notifications are delivered via TALA's Filament database notification bell (`#database-notifications`) across Staff, Student, and Faculty panels alongside email and operational action queues; no general Report Hub or payment-gateway console is introduced.

## Authority and notation

Use this reader to find the ordinary path, exception owner and connected source. Open the owning PRD for exact guards rather than copying a box into implementation as a complete contract. Sources are the [system baseline](prd_modules/00_system_definition_baseline.md), PRDs linked above, the Blueprint, Architecture and current composition guidance. Delivery order and concern dispositions remain in #48; [the Orchestrator Protocol](TALA-Orchestrator-Protocol.md) owns execution, verification and publication.

Reviewing these diagrams is not rendered usability acceptance, runtime verification or institutional policy evidence. Preserve earlier issued facts and attributable history while applying the current product contract.
