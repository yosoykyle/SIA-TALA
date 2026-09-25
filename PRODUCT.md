# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Registrar:** Primary operational custodian responsible for official academic structures, term calendar packages, published timetables, registration finalization, COR issuance, grade releases, transcript (TOR) generation, and academic administrative holds.
- **Accounting Officer:** Financial controller responsible for publishing term fee plans, evaluating manual payment claims, verifying online payments, computing clearance projections, issuing official financial statements, and placing financial administrative holds.
- **Faculty:** Academic instructors responsible for viewing official class meeting schedules, monitoring class rosters, inputting final grade rosters, and resolving incomplete (INC) grades within the 1-year window.
- **Student / Ready Applicant:** Learners tracking admissions readiness, confirming course proposals, reserving class seats, reviewing term accounts, executing PayMongo checkouts or submitting payment proof, viewing released grades, and accessing official enrollment certificates (COR).
- **Academic Head:** Academic leadership reviewing institutional calendar and curriculum evidence in read-only TALA oversight; calendar approval occurs outside TALA.
- **System Administrator:** Technical administrator managing identity security, staff role assignments, TOTP multi-factor authentication, audit logs, and local service health monitoring.

## Product Purpose

Tertiary Academic Lifecycle Administration (TALA) is the official institutional college information system for Servitech Institute Asia Inc. It captures, validates, and preserves the complete student academic lifecycle—from admissions evaluation and timetable publishing to registration, enrollment, grading, financial ledger management, and graduation clearance. Success means maintaining one single, authoritative, auditable institutional record that ensures zero scheduling conflicts, atomic enrollment integrity, reliable grade records, and transparent financial tracking without manual reconciliation friction.

## Positioning

Unlike generic higher education ERPs or fragmented LMS plugins, TALA is a lightweight, high-assurance academic operations engine tailored to Philippine college regulations and institutional policies. It integrates a discrete constraint optimization solver (CP-SAT via Google OR-Tools) into academic timetable publishing, enforces atomic registration with bounded seat reservations, and maintains auditable financial and academic record histories. Independent schedule validation, transactional enrollment, and attributable records protect against conflicts, over-enrollment, and silent record changes.

## Operating Context

Operates in the daily administrative and academic cycle of Servitech Institute Asia Inc., a Philippine higher education institution. Administrative staff (Registrar, Accounting, Academic Head, Admin) work via desktop-first Filament management panels with Livewire interactivity. Students and applicants access self-service responsive web portals. Faculty interact via dedicated desktop and mobile-friendly grading and roster views. System operates within the CHED regulatory framework, term calendar packages (1st, 2nd, and Special Terms), and official document generation standards (COR, TOR, Statement of Account).

## Capabilities and Constraints

- **Six Core Journeys:**
  1. *Identity & Access:* Fortify authentication, mandatory TOTP MFA for staff, single identity across applicant-to-student transition.
  2. *Admissions & Evaluation:* Verification of applicant credentials, deriving read-only Ready Applicant status without premature student profile or student number generation.
  3. *Academic Setup & Published Timetable:* Versioned curricula, Term Calendar Packages, draft offerings, automated constraint solving via CP-SAT, and frozen Published Timetables.
  4. *Registration & Official Enrollment:* Selection basis (Standard Curriculum vs Individually Advised), temporary seat reservations, five atomic checkpoints (eligibility, proposal confirmation, valid placement, financial clearance, Registrar approval), Student Number creation on first enrollment, and immutable COR v1.
  5. *Teaching & Academic Records:* Single final grade per enrolled student per class, complete roster release by Registrar, 1-year bounded Incomplete (INC) resolution without auto-failure, exact GWA calculation, and immutable TOR snapshot issuance.
  6. *Accounts, Payments & Outputs:* Single continuous Term Account, frozen Fee Plans or Authorized Individual Assessments, manual payment proof verification, PayMongo online checkout, official PDF outputs, and local health assurance.
- **Administrative Holds:** Strictly scoped restrictions defined by the `Hold` model (`blocking_level`: `blocks_enrollment`, `blocks_cor_print`, `blocks_clearance`, `blocks_record_release`, `blocks_graduation_eligibility`, `blocks_reactivation`, `advisory_only`). Blanket account or login bans are prohibited. Holds record office attribution (financial to Accounting; academic deficit/prerequisite to Academic Head; others to Registrar), internal reason and staff-only reason, student-facing resolution requirements (`student_message`, `resolution_requirement`), placing actor, optional expiry, and immutable resolution/waiver audit history (`resolved_by`/`resolved_at`, `waived_by`/`waived_at`).
- **Ledger Clearances vs Holds:** `EnrollmentPaymentRequirementProjection` and `OfficialOutputPaymentClearance` are real-time derived accounting calculations, strictly separated from administrative holds.
- **Demonstration Boundaries:** Capstone demonstration follows the retained academic lifecycle. Issue #53 records an actual local Python CP-SAT solve, independent validation, human publication, and schedule projection on guarded `test_tala_db`; the August 19, 2026 Cloud Run activation remains dated evidence, not verification of its current live state. PayMongo hosted checkout and webhooks are implemented and test-backed (`tests/Feature/Finance/ExactDuePayMongoJourneyTest.php`, `tests/Feature/TAL95BPayMongoWebhookPipelineTest.php`), while live sandbox and production endpoints remain unverified here; live merchant activation is owner-gated.
- **Technical Stack:** Laravel 12 on PHP 8.4, Livewire 4, Tailwind CSS, MySQL system of record.

## Brand Commitments

- **School-First Institutional Branding:** "Servitech Institute Asia Inc." (or "Servitech Institute Asia") must lead on all page headers, shells, sidebars, print outputs, document headers, and transactional notifications.
- **Secondary System Attribution:** "TALA" is strictly secondary attribution (e.g., "Powered by TALA" or "Generated through TALA from authenticated records").
- **Recognizable Visual Identity:** Retain and refine the established blue-led institutional interface, light surfaces, restrained yellow accent, Outfit/Inter typography, full-color school crest, and secondary TALA mark. Existing page layouts and controls are evidence to improve, not a requirement to preserve confusing workflows.
- **Tone & Voice:** Authoritative, clean, professional, academic, reassuring, transparent, and precise. Generic labels like "TALA Staff Workspace" without the institution name are prohibited.

## Evidence on Hand

- Comprehensive PRD modules (`00_Project_Documents/prd_modules/00_system_definition_baseline.md` through `06_accounts_official_outputs_operations_assurance.md`).
- Canonical UI Blueprint (`00_Project_Documents/ui_surface_blueprint.md`).
- Architecture Specification (`00_Project_Documents/architecture_specification.md`).
- Governing Business Policy (`00_Project_Documents/TALA-Business-Policy.md`).
- Committed visual baseline screenshots (`00_Project_Documents/design-evidence/human-centered-operations/*.png`).
- Existing tests and active Eloquent models/services; execution claims belong to their dated Issue and CI records, not the presence of test files alone.

## Product Principles

- **Authority and Integrity First:** TALA is an official record-keeper, not an autonomous decider. Authoritative human decisions govern; external helpers (solvers, payment gateways) provide evidence.
- **Atomic, Safe State Transitions:** Actions that affect records (enrollment finalization, grade release, payment posting) execute atomically with full validation, never leaving records in partial or corrupt states.
- **Zero Unresolved Conflicts:** Timetables and course placements must be free of room, instructor, and student schedule collisions prior to publication.
- **Radical Transparency and Explainability:** When an action is blocked or unavailable, the system explicitly explains what is missing, which office owns the resolution, and the exact steps required to recover.
- **Append-Only Auditable Truth:** Financial and academic history is never silently mutated or erased; adjustments and corrections supersede or create versioned snapshots with full actor attribution.

## Accessibility & Inclusion

- Compliance with WCAG 2.1 AA standards across all public, student, and administrative interfaces.
- High-contrast typography (minimum 4.5:1 ratio for normal text, 3:1 for large text/icons).
- Full keyboard navigability with visible, non-obscured focus indicators (`focus-visible`).
- Semantic HTML with appropriate ARIA roles, landmarks, and live regions for dynamic Livewire updates.
- Screen-reader accessible status badges (e.g., `sr-only` text alongside visual color indicators).
