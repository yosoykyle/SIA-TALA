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
- **Secondary System Attribution:** Student and Staff shells place "Powered by TALA" in a quiet footer, separate from the school identity and workspace context. The Applicant panel omits TALA attribution and workspace labels, showing Servitech Institute Asia with the signed-in Applicant's name. Official outputs retain their source-appropriate "Generated through TALA" attribution.
- **Recognizable Visual Identity:** Apply the October 2 owner-selected school-first direction: neutral light/dark surfaces, green-led primary actions, supporting TALA blue, restrained gold/yellow cues, Inter typography, native Heroicons, full-color institutional crest, and secondary TALA attribution. Existing page layouts and controls are evidence to assess, not requirements to preserve or import.
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
- **UI/UX judgment and implementation evidence:** Impeccable leads task-level analysis of hierarchy, cognitive load, copy, composition, and responsive behavior. Relevant design skills and installed-version framework guidance support that judgment. Preserve school behavior and safeguards while comparing suitable native configurations. Functional tests, a clean detector, or reading skills do not establish visual acceptance; the owning Issue needs attributable rendered evidence and independent review.
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
