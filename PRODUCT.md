# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Registrar:** Operational custodian responsible for admissions decisions, one enrollment-clearance result after external school checks, official academic structures, term calendar packages, published timetables, registration finalization, COR issuance, grade releases, and transcript (TOR) issuance. Restrictions and corrections follow the owning PRD's authority and direct system effect.
- **Accounting Officer:** Responsible for publishing approved versioned fee plans, recording exact assessments and approved coverage, verifying payment evidence, maintaining Term Accounts, and providing bounded enrollment/output-clearance results and non-tax account outputs. Cash refunds and institutional financial decisions remain outside TALA.
- **Faculty:** Academic instructors responsible for viewing official class meeting schedules, monitoring class rosters, inputting final grade rosters, and resolving incomplete (INC) grades within the 1-year window.
- **Student / Ready Applicant:** Learners tracking admissions readiness, confirming proposed subjects and placement, reviewing Term Accounts, using available exact-due payment options or submitting payment proof, viewing released grades, and accessing official enrollment certificates (COR). TALA protects bounded reservations during registration; learners do not operate an open seat marketplace.
- **Academic Head:** Academic leadership reviewing institutional calendar and curriculum evidence in read-only TALA oversight; calendar approval occurs outside TALA.
- **System Administrator:** Technical administrator managing identity security, staff role assignments, TOTP multi-factor authentication, audit logs, and local service health monitoring.

## Product Purpose

Tertiary Academic Lifecycle Administration (TALA) is Servitech Institute Asia Inc.'s lean college information system. It records the necessary connected outcomes of admissions, timetable publication, registration, enrollment, final grades, academic records and Term Accounts. Success means a usable, attributable lifecycle with validated schedules, atomic enrollment and clear next actions. Office deliberation, individual paper-document processing, raw grade calculations, cash refunds and general financial accounting remain with their responsible owners outside TALA.

## Positioning

TALA connects Philippine college operations through attributable records, atomic enrollment and validated timetable publication. CP-SAT through Google OR-Tools supplies scheduling candidates; Registrar publishes an independently validated version. Bounded reservations and append-only academic/financial history protect capacity and record integrity.

## Operating Context

Operates in the daily administrative and academic cycle of Servitech Institute Asia Inc., a Philippine higher education institution. Administrative staff (Registrar, Accounting, Academic Head, Admin) work via desktop-first Filament management panels with Livewire interactivity. Students and applicants access self-service responsive web portals. Faculty interact via dedicated desktop and mobile-friendly grading and roster views. System operates within the CHED regulatory framework, term calendar packages (1st, 2nd, and Special Terms), and official document generation standards (COR, TOR, Statement of Account).

## Capabilities and Constraints

- **Six Core Journeys:**
  1. *Identity & Access:* Fortify authentication, mandatory TOTP MFA for staff, single identity across applicant-to-student transition.
  2. *Admissions & Evaluation:* Minimum necessary intake, selected private preliminary copies, scoped correction, an attributable admission decision and one Registrar enrollment clearance. Ready Applicant status is derived from current admitted identity and matching clearance, without premature Student identity or number creation. Other paper documents are handled externally.
  3. *Academic Setup & Published Timetable:* Versioned curricula, Term Calendar Packages, draft offerings, automated constraint solving via CP-SAT, and frozen Published Timetables.
  4. *Registration & Official Enrollment:* Selection basis (Standard Curriculum vs Individually Advised), temporary seat reservations, five atomic checkpoints (eligibility, proposal confirmation, valid placement, financial clearance, Registrar approval), Student Number creation on first enrollment, and immutable COR v1.
  5. *Teaching & Academic Records:* Single final grade per enrolled student per class, complete roster release by Registrar, 1-year bounded Incomplete (INC) resolution without auto-failure, exact GWA calculation, and immutable TOR snapshot issuance.
  6. *Accounts, Payments & Outputs:* Single continuous Term Account, frozen approved Fee Plans or eligible Authorized Individual Assessments, approved coverage, verified manual payment evidence, optional exact-due PayMongo checkout, non-tax account outputs and local health assurance. Refund execution remains external; TALA records authorized corrections only with matching evidence and an atomic attributable effect.
- **Bounded restrictions:** The owning PRD defines the responsible office, exact consuming action, authority, safe learner instruction and correction history. Current code models or enum values do not expand product scope. Blanket service or login bans and a generic policy/approval engine are outside the product boundary.
- **Accounting clearances:** Enrollment-payment and official-output clearance are source-specific calculations from current approved assessments, verified payment and coverage. They are distinct from administrative restrictions and from the Registrar's admissions clearance.
- **Demonstration:** Use the owning PRDs' coordinated synthetic institution to show the connected lifecycle in a guarded test environment. The active Issues record actual execution and acceptance evidence. Architecture owns integration activation and production boundaries.
- **Technical Stack:** Laravel 12 on PHP 8.4, Livewire 4, Tailwind CSS, MySQL system of record.

## Brand Commitments

- **School-First Institutional Branding:** "Servitech Institute Asia Inc." (or "Servitech Institute Asia") identifies the service at public entry and in the authenticated shell. Page headings name the user's task or person being reviewed. Official outputs and transactional notifications identify the school as issuer.
- **Secondary System Attribution:** Student and Staff shells place "Powered by TALA" in a quiet footer, separate from school identity and workspace context. Under the October 10 owner decisions F65/F66 (Issue #59 D3/D2), the Applicant panel and its account-entry pages show school identity without "Applicant Workspace" or "Powered by TALA"; signed-in Applicant identity includes the person's name. Public and error-page attribution remains unchanged. Official outputs retain their source-appropriate "Generated through TALA" attribution.
- **Recognizable Visual Identity:** Apply the October 2 owner-selected school-first direction: neutral light/dark surfaces, green-led primary actions, supporting TALA blue, restrained gold/yellow cues, Inter typography, native Heroicons, full-color institutional crest, and secondary TALA attribution on the surfaces permitted above. Existing page layouts and controls are evidence to assess, not requirements to preserve or import.
- **October 9 material refinement, locally implemented; owner review pending:** The owner requests a modern tactile shared shell, with Admissions as the closest liked reference, for users spanning ages and levels of confidence with technology. Clear surface separation, recognizable controls, readable solid task content, opaque floating overlays and paired accessible appearances are the goals. Claude's design handback has been reconciled and implemented locally by Codex. DESIGN.md owns the shared material grammar; the review record distinguishes rendered evidence from unverified performance and owner acceptance. This does not change product capabilities or #58 business rules.
- **Subsequent owner refinement:** The overall shell/colors and light appearance are positively reviewed. Preserve them while extending the existing **skeuomorphic Aqua-like buttons** family to daily-use controls through native components, improving dark layer separation and consolidating equivalent navigation across roles. Similar intent consumes shared components rather than per-area copies; different intent keeps its own semantics. Codex owns local implementation/documentation. The owner canceled the additional Claude review on October 9 and its pending handoff was deleted; no new external verdict is required. The earlier completed reviews remain historical evidence. Overall shell/colors and light appearance were positively reviewed; later sidebar/dark/component refinements still need owner visual review. #58 business logic and owner-authorized reporting boundaries remain unchanged.
- **Tone & Voice:** Authoritative, clean, professional, academic, reassuring, transparent, and precise. Generic labels like "TALA Staff Workspace" without the institution name are prohibited.

## Evidence on Hand

- Comprehensive PRD modules (`00_Project_Documents/prd_modules/00_system_definition_baseline.md` through `06_accounts_official_outputs_operations_assurance.md`).
- Canonical UI Blueprint (`00_Project_Documents/ui_surface_blueprint.md`).
- Architecture Specification (`00_Project_Documents/architecture_specification.md`).
- Product rules and scope belong to the canonical PRDs; supplied business evidence informs their decisions within its evidenced scope. The documentation registry classifies supporting and historical material.
- Shared presentation decisions, including branding, native-framework color adaptation, and selected HTTP/session-recovery composition and background animation, are consolidated in DESIGN.md and the canonical UI Blueprint. These decisions do not add product capabilities or change business rules.
- Laravel Boost and installed-version official Filament/Bootstrap documentation are the primary implementation references; the official Filament demo provides optional examples of native composition and behavior. `00_Project_Documents/design-evidence/layout/` and historical captures under `design-evidence/human-centered-operations/` supply supporting visual evidence. DESIGN.md and the UI Surface Blueprint own current visual decisions.
- Existing tests and active Eloquent models/services; execution claims belong to their dated Issue and CI records, not the presence of test files alone.

## Product Principles

- **Lean capstone scope:** Retain the accepted connected student lifecycle and make each role's purpose, transaction sequence, handoff, and next action understandable. CP-SAT adaptation is the principal innovation; payment integration supports the lifecycle. Issue #48 coordinates the delivery order, and each child proves one bounded journey. Existing screens, fields, and components are evidence to challenge against their purpose and authority, rather than a requirement to preserve their composition.
- **UI/UX judgment and implementation evidence:** Ground each decision in the owning PRD, then use Impeccable for task-level hierarchy, cognitive load, copy, composition and responsiveness, followed by make-interfaces-feel-better for typography, surfaces, motion, icons and performance detail. Relevant domain skills and installed-version framework guidance support that judgment. Equivalent interaction intent shares native components; each role retains its own purpose and information hierarchy. Functional tests, a clean detector, or reading skills do not establish visual acceptance; the owning Issue needs attributable rendered evidence and independent review. The October 11 three-composition comparison remains unselected design evidence. Its original isolated critique is retained unchanged; corrections and owner confirmation are recorded separately.
- **Authority and Integrity First:** TALA is an official record-keeper, not an autonomous decider. Authoritative human decisions govern; external helpers (solvers, payment gateways) provide evidence.
- **Atomic, Safe State Transitions:** Actions that affect records (enrollment finalization, grade release, payment posting) execute atomically with full validation, never leaving records in partial or corrupt states.
- **Zero Unresolved Conflicts:** Timetables and course placements must be free of room, instructor, and student schedule collisions prior to publication.
- **Radical Transparency and Explainability:** When an action is blocked or unavailable, the system explicitly explains what is missing, which office owns the resolution, and the exact steps required to recover.
- **Append-Only Auditable Truth:** Financial and academic history is never silently mutated or erased; adjustments and corrections supersede or create versioned snapshots with full actor attribution.

## Accessibility & Inclusion

- Target WCAG 2.2 AA across public, student and administrative interfaces; verify the relevant outcomes within each owning delivery slice.
- High-contrast typography (minimum 4.5:1 ratio for normal text, 3:1 for large text/icons).
- Full keyboard navigability with visible, non-obscured focus indicators (`focus-visible`).
- Semantic HTML with appropriate ARIA roles, landmarks, and live regions for dynamic Livewire updates.
- Screen-reader accessible status badges (e.g., `sr-only` text alongside visual color indicators).

### October9 Term Planning workflow extension

The owner rejects the former local Plan/five-card workflow and delegates a new Claude critique/recommendation with Codex execution. Claude's signed-in handback is now reconciled: native persistent Overview, Calendar, Sections & classes, Faculty & rooms and one draft/official Timetable destination; source-supported Program/year coverage; a whole-Term student-group overview and focused weekly/list views. Codex implements and verifies the supported decisions, preserving the approved school-first branding, skeuomorphic Aqua-like buttons, solid overlays and explicit owner-directed reporting boundary. This is local execution, not owner acceptance or Issue completion.

The HR/Education/Math first-through-third-year scenario is hypothetical review data. An absent curriculum row does not establish that a year runs this Term; no universal completeness policy or compulsory cohort year field is invented. Generation reviews the actual recorded whole-Term scope and existing server-side readiness. Shared and mixed-year associations remain visible without classifying a learner. PRD04's new/transferee Applicant and continuing/authorized returning Student categories remain separate from Standard Curriculum/Individually Advised selection basis. Prerequisite, transfer-credit evaluation, reactivation and retake rules retain their owning authority; shared or mixed-year scheduling groups establish none of these learner classifications. Philippine evidence is qualified by issuance, program/regional scope and date; historical CHED guidance does not become an automatic school-specific rule. DESIGN owns the shared vocabulary/hierarchy and PRD03/Blueprint own the relevant behavior/surface records. No publication, closure, deployment or automatic orchestrator report is authorized.
