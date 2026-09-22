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
Dignified archival navy (`#0f2b48`) header, warm parchment (`#fdfcf7`) grid backdrop, crisp hairline cell rules, high-contrast section badges, restriction crimson conflict alerts with exact collision reasons, and verification green (`#15803d`) for feasible and optimal proofs.

### STORY
The Registrar initiates a CP-SAT solve, inspects room and faculty allocations across a dense weekly matrix in `REG-T04`, verifies zero collisions and constraint satisfaction, and executes immutable publication into `REG-T06` with instant version incrementing and print readiness.

### FIRST VIEWPORT
Servitech Institute Asia Inc. institutional header leads; prominent action bar indicates Candidate Status (e.g. `Candidate v3 · CP-SAT Feasible · Hard Conflicts: 0`), primary "Publish Official Timetable" commitment action, and filter controls for room and instructor views.

### FORM
The Philippine Archival Collegiate Registry (Position #6 on grounded list; seed key `0d15d662`).

### FINISH
unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
