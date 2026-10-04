---
version: 1
slug: "reg-e01"
primary_target: "REG-E01"
related_targets: ["REG-E02"]
---

# Surface Brief: REG-E01 / REG-E02 — Enrollment Operations & Recovery Workbench

<!-- impeccable:surface-brief 1 -->

## Scope & Visitor Mode
- **Primary Target:** `REG-E01` (Students & Enrollment Queue)
- **Related Targets:** `REG-E02` (Registration Case Detail & Recovery Dossier)
- **Visitor Mode:** `Operate`

## Audience & Job
- **Audience:** Registrar Officers and Enrollment Processing Staff at Servitech Institute Asia Inc.
- **Context:** High-pressure term enrollment windows processing incoming Ready Applicants and continuing students.
- **Primary Task:** Triage enrollment queues, initiate registration for verified Ready Applicants, review expired seat reservations, inspect source-owned eligibility and finance blockers, and atomically finalize official enrollment with COR v1 issuance.

## Operational Realities & Proof
- **Data Ranges (Exploratory):** Typical term queues range from dozens to hundreds of active registration cases; concurrent Ready Applicants awaiting case creation; temporary seat reservations with time-bounded expiration.
- **States That Matter:** Ready Applicant without a case, proposal waiting for confirmation or confirmed, active or expired reservation, source-owned eligibility/placement blocker, finance unavailable/pending/cleared, ready to finalize and officially enrolled.
- **Zero Ambiguity Rule:** A blocked finalization action shows the exact failed checkpoint from PRD 04: Student eligibility, confirmed proposed subjects, valid class placement, Accounting clearance or Registrar finalization. Show the source, owning office and safe remedy. Current/historical official COR access follows its output authorization and source validity.

## Direction Contract

### THESIS
A Registrar can find the right learner, understand the current registration case and take the next authorized enrollment action from one connected context.

### OWN-WORLD
Inherit the October 2 direction from DESIGN.md: green-led primary actions, supporting TALA blue, neutral light/dark native surfaces, Inter typography, native Heroicons, and the school-first crest with secondary Powered by TALA. Distinguish reservation, source-owned eligibility, financial clearance and official enrollment states with accessible text and semantic treatments from the UI Blueprint. The owning Issue and current canonical authority govern workflow-specific decisions.

### STORY
The Registrar opens the workbench, sees immediate counts of Ready Applicants and expiring reservations, selects a learner, inspects the five current checkpoints (Student eligibility, confirmed proposed subjects, valid class placement, Accounting clearance and Registrar finalization), and executes atomic enrollment finalization or guided placement re-validation when a reservation has lapsed.

### FIRST VIEWPORT
Within the shared school shell, the task heading and exact Term context lead. The active learner, next authorized action and relevant checkpoint/recovery information stay visible. Show exceptional evidence in the context of the affected case.

### FORM
Use the current Servitech identity from DESIGN.md. Impeccable determines task composition, supported by relevant skills. Research the relevant range of Filament capabilities and supported customization through Boost, official documentation and the demo; choose by usability and engineering feasibility within the existing stack. Implementation follows the owning Issue and its authorized scope.

### FINISH
The owning Issue requires verified behavior, attributable rendered evidence, and independent review. Preserve current global direction and any raster provenance; completion and publication remain separately authorized.
