# Surface Brief: REG-E01 / REG-E02 — Enrollment Operations & Recovery Workbench

<!-- impeccable:surface-brief 1 -->

## Scope & Visitor Mode
- **Primary Target:** `REG-E01` (Students & Enrollment Queue)
- **Related Targets:** `REG-E02` (Registration Case Detail & Recovery Dossier)
- **Visitor Mode:** `Operate`

## Audience & Job
- **Audience:** Registrar Officers and Enrollment Processing Staff at Servitech Institute Asia Inc.
- **Context:** High-pressure term enrollment windows processing incoming Ready Applicants and continuing students.
- **Primary Task:** Triage enrollment queues, initiate registration for verified Ready Applicants, review expired seat reservations, inspect discrete scoped administrative holds (`blocking_level`), and atomically finalize official enrollment with instant COR v1 issuance.

## Operational Realities & Proof
- **Data Ranges (Exploratory):** Typical term queues range from dozens to hundreds of active registration cases; concurrent Ready Applicants awaiting case creation; temporary seat reservations with time-bounded expiration.
- **States That Matter:** Ready Applicant unassigned, Proposal Confirmed with Active Seat Reservation, Reservation Expired, Scoped Hold Present (action-scoped `blocking_level`: `blocks_enrollment`, `blocks_cor_print`), Financial Requirement Cleared vs Pending, Officially Enrolled.
- **Zero Ambiguity Rule:** A blocked finalization button must always render an explicit ledger note stating the exact failing checkpoint (Academic Eligibility including requisite/hold clearance, Proposal Confirmation, Class Placement & Capacity, Financial Clearance, or Registrar Approval) and owning office.

## Direction Contract

### THESIS
A high-density collegiate operational ledger that unifies Ready Applicant conversion, seat reservation review, and scoped hold inspection into an actionable dossier—refusing the standard SaaS pattern of detached modal popups and bloated paginated card grids.

### OWN-WORLD
Deep archival navy (`#0f2b48`) institutional headers with Servitech Institute Asia Inc. insignia, warm parchment page ground (`#fdfcf7`), crisp hairline grid lines, seal amber (`#b45309`) countdown badges for temporary reservations, restriction crimson tags for active scoped holds, and verification green (`#15803d`) for officially cleared checkpoints.

### STORY
The Registrar opens the workbench, sees immediate counts of Ready Applicants and expiring reservations, selects a learner, inspects the 5-checkpoint verification ledger (Eligibility, Proposal Confirmation, Class Placement, Financial Clearance, Registrar Approval), and executes atomic enrollment finalization or guided placement re-validation when a reservation has lapsed.

### FIRST VIEWPORT
Institutional header leads; workbench layout explores an asymmetric split (filterable queue: Ready Applicants, Expiring Reservations, Pending Finance, Scoped Holds) paired with an Active Learner Dossier displaying the 5 atomic checkpoints, reserved course blocks, and the primary "Finalize Official Enrollment" commitment action.

### FORM
The Philippine Archival Collegiate Registry (Position #6 on grounded list; seed key `0d15d662`).

### FINISH
unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
