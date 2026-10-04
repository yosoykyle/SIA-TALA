---
version: 1
slug: "reg-t04"
primary_target: "REG-T04"
related_targets: ["REG-T06"]
---

# Surface Brief: REG-T04 / REG-T06 — Timetable Optimization & Published Timetable Surfaces

<!-- impeccable:surface-brief 1 -->

## Scope & Visitor Mode
- **Primary Target:** `REG-T04` (Timetable Candidate Review & Constraint Inspection)
- **Related Targets:** `REG-T06` (Published Timetable & Revision / Official Schedule)
- **Visitor Mode:** `Operate`

## Audience & Job
- **Audience:** Registrar Officers and Academic Heads at Servitech Institute Asia Inc.
- **Context:** Pre-term timetable candidate generation and review (`REG-T04`), and post-publication official schedule viewing and revisions (`REG-T06`).
- **Primary Task:**
  - `REG-T04`: Inspect CP-SAT generated candidate schedules, verify zero room/instructor collisions, inspect solver outcomes (Optimal, Feasible, Infeasible, Unknown, ModelInvalid, TechnicalFailure), and validate whole-term feasibility prior to publication.
  - `REG-T06`: Access the authoritative published timetable, generate A4 landscape print outputs, and record controlled revisions to published meetings.

## Operational Realities & Proof
- **Data Ranges (Exploratory):** Canonical institution dataset demands, multiple classrooms/laboratories, eligible faculty instructors, and recurring weekly meeting blocks.
- **States That Matter:** Candidate In-Flight, Candidate Solved (Optimal / Feasible), Unfeasible/Invalid (Infeasible, Unknown, ModelInvalid, TechnicalFailure), Published Timetable (Frozen v1/v2), Published Revision Draft.
- **Zero Collision Rule:** No timetable may be published while hard constraint violations exist. Every move or adjustment must clearly delineate candidate adjustment vs published revision.

## Direction Contract

### THESIS
The Registrar understands the candidate timetable, its hard validity and quality, the effects of a proposed correction and the authority needed for publication. Weekly relationships and the accessible meeting view remain clear across review and published history.

### OWN-WORLD
Inherit the October 2 direction from DESIGN.md: green-led primary actions, supporting TALA blue, neutral light/dark native surfaces, Inter typography, native Heroicons, and the school-first crest with secondary Powered by TALA. The weekly grid and meeting table need clear hierarchy, readable selection, and text-backed semantic states. The owning Issue and current canonical authority govern workflow-specific decisions.

### STORY
The Registrar initiates a CP-SAT solve, inspects room and faculty allocations across a dense weekly matrix in `REG-T04`, verifies zero collisions and constraint satisfaction, and executes immutable publication into `REG-T06` with instant version incrementing and print readiness.

### FIRST VIEWPORT
The task heading and exact selected Term lead within the shared school shell. Show the current result, hard-validity evidence, permitted next action and relevant filters. Publication is available only for a current independently valid candidate with the required review and authority; other states explain the owner and remedy.

### FORM
Use the owner-selected Choice 3 hybrid review direction within the current Servitech identity from DESIGN.md and the owning Issue's authorized scope.

Impeccable leads task composition with relevant skills. Research Filament capabilities, supported customization and rendered examples through Boost, official documentation and the demo. Reuse the accepted hybrid review direction while choosing task-fit details within the existing stack.

### APPROVED WORKFLOW & LAYOUT DIRECTION: CHOICE 3 (2026-09-25)
- **Workbench Integration Topology:** Hybrid Split-Pane on Tab 4 (`Generate & Review`) of Term Planning Workbench. Use the workbench's single selectable exact-Term context; do not repeat Term selection inside generation or review.
- **Review controls and evidence:** Current result, hard validity, failure reason and permitted next action stay visible. Secondary quality details and historical diagnostics may be disclosed on demand. Use the individual fixed quality measures from PRD 03.
- **Candidate View Topology:** Sub-view toggle between:
  1. *Time-Block Matrix (Grid)*: Weekly matrix derived from the selected approved Term calendar, teaching grid, breaks and dated exceptions using the current DESIGN.md identity and text-backed visual distinctions for lectures, labs, warnings, and collisions.
  2. *Filterable Registry List (Table)*: High-density tabular registry filterable by Faculty, Room, Section, and Modality.
- **Publication & Output Transition:** Modal Sign-Off with Direct Tab 5 Transition. "Publish Official Timetable" requires recorded external sign-off (`authority_reference`), produces immutable `PublishedTimetableVersion`, and automatically switches to Tab 5 (`Published Timetable`) with prominent A4 Landscape print action (`OUT-002`).
- **Role Isolation:** Academic Head maintains read-only oversight; mutation actions (generate, accept, publish, retry) are restricted to Registrar.

### FINISH
The owning Issue requires attributable behavior and rendered evidence, independent review and its authorized completion/publication boundary.
