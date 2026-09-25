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
A collegiate scheduling matrix pairing a high-density weekly grid with clear constraint validation and structured revision diffs—refusing static uneditable grid printouts and unvalidated automatic schedule shifts.

### OWN-WORLD
Refine the existing institutional blue, light canvas, white task surfaces, restrained yellow accent, Outfit/Inter typography, and school-first crest/secondary TALA mark. The weekly grid and meeting table need clear hierarchy, readable selection, and text-backed semantic states. Do not import the superseded parchment/archival palette.

### STORY
The Registrar initiates a CP-SAT solve, inspects room and faculty allocations across a dense weekly matrix in `REG-T04`, verifies zero collisions and constraint satisfaction, and executes immutable publication into `REG-T06` with instant version incrementing and print readiness.

### FIRST VIEWPORT
Servitech Institute Asia Inc. institutional header leads; prominent action bar indicates Candidate Status (e.g. `Candidate v3 · CP-SAT Feasible · Hard Conflicts: 0`), primary "Publish Official Timetable" commitment action, and filter controls for room and instructor views.

### FORM
User-selected Choice 3 hybrid split-pane workflow within the established blue-led Servitech identity. Candidate #6 (seed key `0d15d662`) is historical palette exploration, not an active visual constraint.

### APPROVED WORKFLOW & LAYOUT DIRECTION: CHOICE 3 (2026-09-25)
- **Workbench Integration Topology:** Hybrid Split-Pane on Tab 4 (`Generate & Review`) of Term Planning Workbench. Use the workbench's single selectable exact-Term context; do not repeat Term selection inside generation or review.
- **Collapsible Control Deck:** Solver dispatch, status KPIs (Hard conflicts, Soft score, Seat waste, Faculty/Cohort idle, Runtime), and failure/infeasibility diagnostics reside in a collapsible header deck, allowing the Registrar to maximize vertical screen canvas for timetable inspection.
- **Candidate View Topology:** Sub-view toggle between:
  1. *Time-Block Matrix (Grid)*: Weekly matrix (Monday–Saturday columns, 07:00–21:00 time rows) using the established blue-led identity and text-backed visual distinctions for lectures, labs, warnings, and collisions.
  2. *Filterable Registry List (Table)*: High-density tabular registry filterable by Faculty, Room, Section, and Modality.
- **Publication & Output Transition:** Modal Sign-Off with Direct Tab 5 Transition. "Publish Official Timetable" requires recorded external sign-off (`authority_reference`), produces immutable `PublishedTimetableVersion`, and automatically switches to Tab 5 (`Published Timetable`) with prominent A4 Landscape print action (`OUT-002`).
- **Role Isolation:** Academic Head maintains read-only oversight; mutation actions (generate, accept, publish, retry) are restricted to Registrar.

### FINISH
unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
