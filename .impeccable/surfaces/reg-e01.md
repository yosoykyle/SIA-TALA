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
A Registrar can find the right learner and take the next authorized enrollment action without navigating a maze of disconnected records. The exact future composition remains a task-level design decision; this brief does not require a ledger metaphor or a dense dossier.

### OWN-WORLD
Inherit the October 2 direction from DESIGN.md: green-led primary actions, supporting TALA blue, neutral light/dark native surfaces, Inter typography, native Heroicons, and the school-first crest with secondary Powered by TALA. Distinguish reservation, scoped restriction, financial clearance, and official enrollment states with accessible text and semantic treatments from the UI Blueprint. This supersedes the former blue-primary and Outfit/Inter styling in this brief; its workflow-specific decisions remain subject to their owning Issue and current canonical authority.

### STORY
The Registrar opens the workbench, sees immediate counts of Ready Applicants and expiring reservations, selects a learner, inspects the 5-checkpoint verification ledger (Eligibility, Proposal Confirmation, Class Placement, Financial Clearance, Registrar Approval), and executes atomic enrollment finalization or guided placement re-validation when a reservation has lapsed.

### FIRST VIEWPORT
Institutional identity and exact Term context lead. The active learner, next authorized action, and relevant checkpoint/recovery information are visible without forcing every exception into the ordinary path. Queue-plus-detail remains an exploration option, not an approved split ratio or a requirement to rebuild completed #51 behavior.

### FORM
Use the current Servitech identity from DESIGN.md. Candidate #6 (seed key `0d15d662`) remains historical visual exploration. Review task-appropriate native compositions when this journey is separately activated; this brief does not activate implementation or approve its exploratory layout.

### FINISH
The owning Issue requires verified behavior, attributable rendered evidence, and independent review. Preserve current global direction and any raster provenance; completion and publication remain separately authorized.
