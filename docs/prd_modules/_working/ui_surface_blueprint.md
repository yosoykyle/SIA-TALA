# TALA UI Surface Blueprint

Working status: proposed planning artifact for PRD alignment and vertical-slice planning. This document does not change module requirements by itself. Use it to decide how each PRD module should appear in the product before opening implementation slices.

## Research Grounding

Version-aware documentation and component inventory checked during this pass:

1. Laravel Boost docs for `filament/filament` 5.x, `livewire/livewire` 4.x, and Tailwind CSS.
2. Context7 docs for Filament 5, Livewire 4, and Tailwind CSS 4.
3. TallStackUI MCP component inventory for TallStackUI v3 components.
4. Current repo inventory under `app/Filament/Resources`, `app/Filament/Pages`, and `app/Models`.

Confirmed capabilities:

1. Filament 5 supports multiple panels, each with its own resources, pages, widgets, navigation, and auth features.
2. Filament panel access should be controlled through `User::canAccessPanel(Panel $panel)` and policies.
3. Filament panels can enable login, registration, password reset, email verification, and profile pages.
4. Filament resources provide the main CRUD surface using forms, tables, infolists, filters, actions, relation managers, widgets, and notifications.
5. Livewire 4 supports full-page public pages through `Route::livewire()`, SPA-style `wire:navigate`, redirects, lazy/defer loading, and form validation.
6. Tailwind CSS 4 uses CSS-first configuration with `@import "tailwindcss"` and `@theme`.
7. TallStackUI v3 provides public-page Blade components such as Button, Link, Card, Icon, Badge, Banner, Alert, Timeline, Step, Accordion, Stats, and Theme Switch.

Guardrails:

1. Use Filament for authenticated operational workspaces unless a workflow clearly needs a custom public-facing experience.
2. Use TallStackUI/Blade/Livewire for the public institutional landing page.
3. Do not use generic public registration for all roles. Applicant self-registration is the only public account creation path.
4. Student accounts are activated through handover, not public registration.
5. Staff accounts are created and managed by authorized System Super Admin users.
6. Do not call the student area a "student Filament panel" in product language. The product term remains Student Hub; Filament is only the proposed implementation shell.
7. Avoid new dependencies until a slice proves an existing Filament/TallStackUI component cannot meet the requirement.

## Proposed Application Surfaces

| Surface | Audience | Proposed shell | Purpose | Account creation |
| --- | --- | --- | --- | --- |
| Public Landing Page | Public visitors, applicants, students, staff | Blade/Livewire + TallStackUI | Institution information, admission guidance, sign-in/apply links, FAQ | None |
| Applicant Workspace | Applicants before handover | Filament authenticated workspace | Application draft/submission, checklist, uploads, status, correction responses | Applicant self-registration only |
| Student Hub | Students after handover | Filament authenticated workspace | Current student records, COR/SOA/payment acknowledgement, schedule, grades, holds, notices | Activated through handover |
| Staff Workspace | Registrar, Accounting, Faculty, Academic Head, System Super Admin | Filament authenticated workspace | Operational queues, approvals, setup, reports, audit | Created by System Super Admin |

Panel structure options:

1. Single authenticated panel: `/portal`
   - Lowest routing complexity.
   - Requires strict policies and role-aware navigation.
   - Good rescue-plan default if the team wants the fewest moving parts.

2. Two authenticated panels: `/portal` and `/admin`
   - `/portal`: applicant, student, faculty.
   - `/admin`: registrar, accounting, academic head, system super admin.
   - Clearer mental model, slightly more implementation setup.

3. Three authenticated panels: `/applicant`, `/student`, `/admin`
   - Strongest UX separation.
   - More provider/resource duplication risk.
   - Use only if applicant and student surfaces become large enough to justify separate navigation shells.

Recommended rescue-plan default: start with one authenticated Filament portal and one public TallStackUI landing page. Split panels only if policy/navigation complexity becomes harder than panel setup.

## Public Landing Page Plan

Goal: one page, institutional, direct, and useful. It should explain what TALA is, who can use it, and where account holders go next. It should not become a marketing-heavy site or a second application shell.

Design direction:

1. Quiet institutional style, not SaaS hero excess.
2. White and slate surfaces with deep blue primary actions and restrained gold or cyan accents.
3. Use real school/logo imagery if available; otherwise keep visual treatment typographic and clean.
4. Mobile-first layout with accessible contrast, 16px minimum body text, visible focus states, and 44px minimum interactive targets.
5. Keep cards as repeated information units only. Do not nest cards.
6. Use subtle opacity/transform transitions only. Respect reduced motion.

Sections:

| Section | Purpose | TallStackUI components |
| --- | --- | --- |
| Header | Brand, anchor navigation, theme switch, sign-in/apply actions | Button, Link, Theme Switch |
| Hero | State the system purpose and primary routes | Button, Badge, Icon |
| What TALA Handles | Explain admissions, enrollment, schedules, finance evidence, records | Card, Icon |
| Applicant Flow | Show the admission path before student handover | Timeline or Step |
| Account Access | Clarify applicant, student, staff access boundaries | Card, Badge, Alert |
| Notices | Admission reminders, office hours, document reminders | Banner or Alert |
| FAQ | Common account, application, receipt, COR, and office-contact questions | Accordion |
| Footer | Contact, privacy note, campus info, sign-in/apply links | Link |

CTA rules:

1. Primary CTA: Apply Online.
2. Secondary CTA: Sign In.
3. Do not show "Student Registration." Students do not self-register.
4. When applicant registration is not implemented yet, the Apply CTA may point to a temporary information anchor or disabled state in non-production.

## Auth And Registration Plan

Filament can provide registration, but TALA should not expose a generic registration page.

Applicant registration:

1. Label as "Create Applicant Account" or "Apply Online."
2. Creates a user with the `applicant` role only.
3. Creates or starts the applicant intake record.
4. Requires email/password and email verification unless the PRD explicitly allows unverified applicants to proceed.
5. Prevents duplicate applicant/student identity matches from silently creating new accounts.
6. Shows applicant-specific next steps after registration.

Student access:

1. No public student signup.
2. Student number is generated or reused during official handover.
3. Existing applicant account is promoted to student access when handover/account activation rules pass.
4. Login remains email/password unless a future approved requirement changes the credential policy.

Staff access:

1. No public staff signup.
2. System Super Admin creates staff accounts.
3. Staff users receive exactly one staff role unless a later policy permits multi-role staff.

Auth states to plan:

| State | Applicant behavior | Student behavior | Staff behavior |
| --- | --- | --- | --- |
| Guest | Can view landing, sign in, apply | Can view landing, sign in | Can view landing, sign in |
| Unverified email | Can be blocked or limited by policy | Should not access sensitive outputs | Should not access staff workspace |
| Pending applicant | Sees checklist/status | No Student Hub | Not applicable |
| Action required | Sees correction prompts | No Student Hub | Not applicable |
| Approved applicant | Sees handover/payment/enrollment next step | No Student Hub until activation | Staff can process |
| Active student | Not applicant workspace | Sees Student Hub | Not applicable |
| Inactive/archived | Limited or blocked with reason | Limited or blocked with reason | Limited or blocked |

## Filament UX Standards

Use these standards when planning each Filament resource, page, action, or dashboard.

Forms:

1. Use visible labels and helper text for business-specific fields.
2. Prefer step/wizard layouts only for long applicant intake or complex setup flows.
3. Use selects for controlled vocabularies and date pickers for dates.
4. Use file upload components only where public/private storage and visibility rules are explicit.

Tables:

1. Each operational queue should have a default filter matching the user's role and next action.
2. Use badges for statuses.
3. Use row actions for record-level work and bulk actions only where the workflow supports bulk decisions.
4. Use saved filters only after the slice has enough repeated usage to justify them. (Note: Saved filters are not built into Filament by default; consider using plugins like 'filament-table-presets' if needed).

Actions:

1. Controlled actions require a modal reason field.
2. Approve/reject/post/override/void actions must be policy-protected and audited.
3. Actions should call application service classes, not place business logic in resources.

Loading and feedback:

1. Use warnings for readiness blockers instead of silent disabled buttons.

Dashboards and widgets:

1. Dashboards should be role-specific summaries, not a replacement for queues.
2. Widgets should show counts, blockers, and next actions only.
3. Avoid charts until a report module needs trend/comparison data.

Color and density:

1. Use Filament's standard surfaces for authenticated workspaces.
2. Keep status colors semantic: warning for pending/action needed, success for accepted/posted, danger for rejected/blocked/voided, info for advisory.
3. Keep data-dense staff queues compact but readable.
4. Keep applicant/student workspaces calmer, with fewer columns and clearer guidance.

Animation:

1. Use built-in Filament/TallStackUI transitions.
2. Do not add decorative animation plugins in v1.
3. Any loading indicator must communicate real work.

Plugin policy:

1. Start with core Filament.
2. Existing installed plugin `pxlrbt/filament-activity-log` can support audit visibility if compatible with the final panel plan.
3. Add plugins only after documenting the exact missing capability, maintenance risk, and test plan.

## Module-To-UI Blueprint

| PRD module | Primary surface | Suggested Filament/TallStackUI pieces | Key states | First vertical slice |
| --- | --- | --- | --- | --- |
| 01 Product Intent & Architecture | Public Landing, Staff Workspace | Landing sections, staff dashboard cards | Public, authenticated, role-scoped | Public landing page with accurate surface messaging |
| 02 Identity, Access, and Workspaces | Filament auth and portal shell | Panel provider(s), auth pages, role-aware dashboard, policies | guest, unverified, active, inactive, archived | Authenticated portal shell with role routing and access tests |
| 03 Admissions & Student Handover | Applicant Workspace, Registrar Workspace | Applicant intake resource/page, checklist table, upload form, registrar review actions | draft, pending, action required, for evaluation, approved, handed over | Applicant registration + intake draft/submission + registrar review queue |
| 04 Academic Setup | Registrar, Academic Head | Programs, Subjects, Curriculums, AcademicYears, Terms resources | draft, active, superseded, locked, approved | Curriculum/program setup resource with approval/readiness state |
| 05 Term Offerings & Resources | Registrar, Academic Head | Sections, DeliveryPatterns, Rooms, FacultySubjectEligibilities resources | draft, ready, active, cancelled, overloaded | Term offering setup and section resource readiness queue |
| 06 CP-SAT Scheduling | Registrar, Faculty, Academic Head | ScheduleGenerationRuns, CandidateScheduleRows relation manager, SectionMeetings, availability resources | pending, running, feasible, infeasible, reviewed, published, revised | Solver run review page with candidate rows and publish action |
| 07 Enrollment Gate Model | Registrar, Student Hub | Enrollments resource, gate review page/action modals, student status cards | not started, blocked, overridden, pre-enrolled, official | Enrollment gate evaluation with visible blockers and override reason |
| 08 Finance, Ledger, PayMongo | Accounting, Student Hub | PaymentAttempts, Payments, LedgerEntries, FeeTemplates, InstallmentPolicies, PromissoryNotes | pending, confirmed, rejected, posted, mapped OR, overdue, active promissory | Payment queue + OR mapping + student SOA visibility |
| 09 COR Subsystem | Registrar, Student Hub | CorVerifications resource, COR view action, print/download logs | unavailable, blocked, available, printed, revoked | COR readiness/check and student current COR view |
| 10 Grades | Faculty, Registrar, Academic Head, Student Hub | GradeSubmissionPackages, EnrollmentSubjects, Grades, GradeCorrections | draft, submitted, returned, posted, released, corrected | Faculty grade package submission and registrar release queue |
| 11 Student Lifecycle | Registrar, Accounting, Student Hub | StudentProfile resource/page, Holds resource or relation, status action modals | active, inactive, archived, hold active, hold resolved, waived | Central holds table UI with student-facing hold summary |
| 12 Student Hub | Student Hub | Student dashboard, current COR/SOA/schedule/grades/holds pages or resources | no handover, active, blocked, available, released | Student dashboard showing current enrollment, holds, schedule, outputs |
| 13 System Admin, Reports, Audit | System Super Admin, Staff Workspace | Users, Roles, SystemSettings, Activities, FaqEntries, reports pages | configured, changed, archived, audited, export ready | Staff account management + read-only roles + audit log visibility |

## Existing Repo Coverage To Reuse

Current Filament resources already exist for many modules:

1. Admissions: ApplicantIntakes, DocumentUploads, DocumentRequirementItems, AdmissionOfferings, AdmissionRequirementPolicies, AdmissionCapacityPlans.
2. Academic setup: Programs, Subjects, Curriculums, AcademicYears, Terms.
3. Scheduling: ScheduleGenerationRuns, SectionMeetings, Sections, SectionDeliveryGroups, Rooms, FacultyAvailabilityPeriods, FacultyAvailabilitySubmissions, FacultyAvailabilityChangeRequests, FacultySubjectEligibilities, DeliveryPatterns.
4. Enrollment and outputs: Enrollments, EnrollmentSubjects, CorVerifications.
5. Finance: PaymentAttempts, Payments, LedgerEntries, AccountingAdjustments, FeeTemplates, InstallmentPolicies, InstallmentPolicyMilestones, PromissoryNotes.
6. Grades: Grades, GradeSubmissionPackages, GradeCorrections.
7. System admin: Users, Roles, SystemSettings, FaqEntries, Activities.

Current model coverage also includes ChecklistItem, Hold, StudentProfile, CandidateScheduleRow, and ScheduleRevisionEvent, which are important for the simplified PRD.

## Vertical Slice Planning Template

Use this template for each implementation slice:

1. PRD module:
2. Surface:
3. Primary role:
4. User goal:
5. Existing model/resource to reuse:
6. New or changed Filament pages/resources/actions:
7. Form fields:
8. Table columns and filters:
9. Infolist/current-view fields:
10. Empty state:
11. Loading state:
12. Success state:
13. Error/blocker state:
14. Authorization policy:
15. Audit event:
16. Required tests:
17. Docs to update after implementation:

## Proposed Vertical Slice Order

1. Public landing page and route cleanup.
   - Proves public/TallStackUI surface and correct links to sign in/apply.

2. Authenticated Filament portal shell.
   - Proves panel access, role routing, navigation, login, email verification, and staff/applicant/student separation.

3. Applicant registration and intake shell.
   - Proves applicant-only account creation, intake draft/submission, and duplicate guard.

4. Applicant checklist and upload status.
   - Proves applicant sees only own checklist and correction requirements.

5. Registrar applicant review and handover readiness.
   - Proves staff queue, approve/reject/correction actions, reasons, and audit.

6. Student profile activation and Student Hub shell.
   - Proves handover promotes the account and exposes current student dashboard only after activation.

7. Enrollment gate and holds.
   - Proves central blockers, override reasons, and student-facing hold summaries.

8. Finance visibility and payment queue.
   - Proves accounting payment workflow and student SOA/payment acknowledgement visibility.

9. COR readiness and current COR view.
   - Proves official output access, blocking states, and print/download logging.

10. Scheduling publication and student/faculty schedule views.
   - Proves candidate-to-live schedule path and role-specific visibility.

11. Grade submission, release, and student grade view.
   - Proves faculty-to-registrar-to-student grade workflow.

12. System admin, audit, reports, and cleanup.
   - Proves user lifecycle, settings, activity log, and export boundaries.

## PRD Alignment Changes To Make Later

After this blueprint is accepted, update the PRD modules in a separate pass:

1. Module 02: add Application Surfaces and clarify Filament as the authenticated operational shell.
2. Module 02: clarify applicant self-registration, student handover activation, and staff-only account creation.
3. Module 02: rename "Faculty Portal" to "Faculty Workspace" unless a separate faculty panel is intentionally chosen.
4. Module 12: revise Student Hub wording so it is not locked to a separate Livewire page set if Filament is chosen as the authenticated shell.
5. Module 13: clarify role management as fixed canonical roles plus staff role assignment, unless arbitrary role creation is intentionally allowed.

## Open Decisions

1. One authenticated Filament panel or multiple panels?
2. Does applicant registration require email verification before application submission, or only before sensitive status/output access?
3. Should faculty be grouped with staff or placed in the general portal with applicant/student?
4. Should Student Hub be implemented as Filament resources/pages inside the portal, or custom Filament pages with limited table/resource exposure?
5. Which reports are v1 operational tables and which are later exports?
6. Is database notification support required for v1, or is email-only enough as Module 13 currently suggests?

