---
version: 1
slug: "reg-e01"
primary_target: "REG-E01"
related_targets: ["REG-E02", "REG-E03", "REG-E04", "STU-001", "STU-002", "STU-004", "STU-006", "ACC-001", "ACC-002", "ACC-003", "ACC-004", "ACC-005", "ACC-006", "ACC-007", "OUT-006", "OUT-007"]
---

# Surface Brief: REG-E01 — Connected Enrollment, Accounts & COR

<!-- impeccable:surface-brief 1 -->

## Scope & Visitor Mode
- **Primary Target:** `REG-E01` (Students & Enrollment Queue)
- **Related Targets:** `REG-E02`–`REG-E04` case, placement and change tasks; `STU-002`, `STU-004`, `STU-006` Enrollment, Finance and COR; enrollment-related `STU-001` Home; `ACC-001`–`ACC-007` Fee Plans, Accounts and contextual finance tasks; `OUT-006` SOA and retained `OUT-007` history. Faculty/Head consume official membership through their existing surfaces; Applicant Home remains separately owned.
- **Visitor Mode:** `Operate`

## Audience & Job
- **Audience:** Registrar and Accounting staff, Ready Applicants and continuing Students at Servitech Institute Asia Inc.; Faculty and Academic Head consume the resulting official membership within their existing permissions.
- **Context:** High-pressure term enrollment windows processing incoming Ready Applicants and continuing students.
- **Primary Task:** Triage enrollment queues, initiate registration for verified Ready Applicants, review expired seat reservations, inspect source-owned eligibility and finance blockers, and atomically finalize official enrollment with COR v1 issuance.

## Operational Realities & Proof
- **Data Ranges (Exploratory):** Typical term queues range from dozens to hundreds of active registration cases; concurrent Ready Applicants awaiting case creation; temporary seat reservations with time-bounded expiration.
- **States That Matter:** Ready Applicant without a case, proposal waiting for confirmation or confirmed, active or expired reservation, source-owned eligibility/placement blocker, finance unavailable/pending/cleared, ready to finalize and officially enrolled.
- **Zero Ambiguity Rule:** A blocker states the short reason, responsible office and safe next step. Keep actual eligibility, confirmation, placement, payment and finalization safeguards inspectable without a visible typed hold engine or five-card checklist. Current/historical COR access retains output authorization and source validity.

## Direction Contract

### THESIS
A Registrar can find the right learner, understand the current registration case and take the next authorized enrollment action from one connected context.

### OWN-WORLD
Inherit the October 2 direction from DESIGN.md: green-led primary actions, supporting TALA blue, neutral light/dark native surfaces, Inter typography, native Heroicons, and the school-first crest with secondary Powered by TALA. Distinguish reservation, source-owned eligibility, financial clearance and official enrollment states with accessible text and semantic treatments from the UI Blueprint. The owning Issue and current canonical authority govern workflow-specific decisions.

### STORY
The Registrar opens an exact-Term work queue, finds the learner and follows one next enrollment task. Eligible case opening prepares the standard subjects, temporary seats and authoritative assessment; the learner confirms and pays from one clear flow. Registrar can finalize ready cases in a batch with each case rechecked. Ambiguous/advised placement and expired reservations offer contextual recovery. Accounting sees its focused account view of the same records.

The learner reviews subjects, reserved schedule, charges and the amount needed now before confirming, without selecting an arbitrary Term or repeating verified identity/contact facts. Accounting can discover an assessment-required case before an account exists, then work from one account context. Manual payment verification remains usable independently of optional online checkout; submitted proof, verified payment and externally approved coverage are visibly distinct. COR retains its coherent frozen assessment snapshot, while later account activity belongs in Finance/SOA.

### FIRST VIEWPORT
Within the shared school shell, the task heading and exact Term context lead. The active learner, next authorized action and relevant checkpoint/recovery information stay visible. Show exceptional evidence in the context of the affected case.

Learner views lead with current status, useful amount/date and next action; staff views lead with actionable cases or the selected decision. Show supporting history/source evidence on demand. Remove information and repeated labels without a task purpose, distinguish unavailable from zero/empty, and use page versus modal consistently with the task and return context. Student Home keeps useful source-owned summaries rather than an empty hold/lifecycle dashboard.

### FORM
Use the current Servitech identity from DESIGN.md. Impeccable determines task composition, supported by relevant skills. Research the relevant range of Filament capabilities and supported customization through Boost, official documentation and the demo; choose by usability and engineering feasibility within the existing stack. Implementation follows the owning Issue and its authorized scope.

Use Product Design for an initial image-based direction for owner selection, then prototype every changed in-scope task surface before implementing it. Reuse the chosen patterns across related surfaces; one prototype may establish a shared pattern without requiring repeated owner choices for minor variants. Compare the rendered implementation with its prototype. This remains the existing Laravel/Filament product, with no separate prototype application or dependency implied.

Apply relevant Impeccable and better-interface guidance for composition, wording, layout, typography, color, responsive accessibility, purposeful motion/delight and hardened recovery. Bolder or quieter treatment and ambitious effects must serve the task. Preserve keyboard/focus behavior, text status meaning, reduced-motion handling and safe loading, empty, stale, concurrent, validation and failure states. No numerical design score substitutes for understandable use.

### FINISH
Agents establish behavior and proportionate rendered evidence against the owning Issue. The owner reviews appearance and understandable use as a user; the Protocol owns review, refinement and explicit permission before an executor reports onward. Preserve current global direction and raster provenance; completion and publication remain separately authorized.
