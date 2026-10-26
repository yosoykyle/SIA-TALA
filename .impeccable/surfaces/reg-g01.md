---
version: 1
slug: "reg-g01"
primary_target: "app/Filament/Pages/GradesAndCompletion.php"
related_targets: ["REG-G01", "REG-G02", "FAC-003", "STU-003", "AHD-001", "resources/views/filament/pages/grades-and-completion.blade.php", "app/Filament/Pages/FacultyGradeRoster.php", "resources/views/filament/pages/faculty-grade-roster.blade.php", "app/Filament/Resources/GradeRosters/GradeRosterResource.php"]
---

# Surface Brief: REG-G01–REG-G02 / FAC-003 — Final Grades and Academic Records

<!-- impeccable:surface-brief 1 -->

## Job, authority and boundaries

**Mode: Operate.** Faculty finishes an assigned official class roster; Registrar reviews and releases it, returns named rows, or records an authorized INC/correction outcome. Learners and permitted Academic Head users understand the resulting released record and next responsible office.

PRD 05 §§3–5 owns results, release, INC, correction, academic averages and enrollment effects. PRD 03 owns calendar/teaching sources; PRD 04 owns official membership and registration changes. The Blueprint owns role capabilities and outputs; PRODUCT.md and DESIGN.md own product/design identity; Architecture owns trust and integrations. [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and its children own assignments, decisions, progress and evidence. [#78](https://github.com/yosoykyle/SIA-TALA/issues/78) owns this bounded implementation contract.

Coverage is REG-G01/REG-G02, FAC-003, the released-grade/average portion of Registrar Student Record, connected STU-003 data/output adapters, and the Grades & Progress inspection portion of AHD-001. The Student Hub's separately assigned page composition is preserved. Broader external-competency recording, curriculum/lifecycle decisions, completion, graduation and TOR remain separate tasks; their existing routes and sources are not removed by this brief. Accounting and System Administrator receive no academic mutation authority.

## Direction contract

**THESIS:** The work itself leads: a class to finish, a submitted roster to review, or a released result needing a specific next action. Remove duplicated launch steps and unexplained status walls.

**OWN-WORLD:** Inherit DESIGN.md's school-first identity, Inter hierarchy, green-led primary actions, supporting blue, restrained Aqua-like controls, semantic light/dark surfaces and native Heroicons. Reuse the shared navigation, task surfaces, disclosures and handoff components. Apple/Adwaita-inspired hierarchy and material already adapted in DESIGN.md support this world; they do not introduce another visual system.

**STORY:** Assigned class → current official learners → draft/complete submission → Registrar review or named-row return → atomic release → consistent academic records. An INC completion, deadline amendment or authorized correction remains a contextual successor task with meaningful history and any required registration-impact review.

**FIRST VIEWPORT:** Show the current Term/class or selected learner, actual state, relevant deadline, responsible role and one primary next action. Faculty worklists expose assigned classes directly. Registrar Grade Review and INC & Corrections expose the relevant work, not repeated cards leading to the same unfiltered list. Keep source details and history reachable after the task.

**FORM:** Use supported native Filament tables, forms, actions and disclosure patterns where they make the work clear. Inline rows serve final-result entry; a focused dialog serves a bounded reason/authority decision; a page serves sustained roster or learner-record review. Choose by task complexity and return context, not historical component choice. A focused TALA-owned layout is justified only by a demonstrated composition gap and preserves native guards and accessibility.

**FINISH:** Verify the connected outcome and actual rendered surfaces; record evidence and limits in the owning Issue. The owner judges appearance and understandable use. Technical readiness, prototype approval and passing tests do not substitute for that judgment.

## Information and action hierarchy

- **Faculty worklist/detail:** course, class, exact Term, current official learner count or update-needed state, roster state and Open class. Selected learner identity is read-only; entry asks only for the controlled final result and conditional INC note. Preserve the designated submitter/co-Faculty distinction, complete submission and returned-row scope. Print/CSV are contextual operational outputs with their existing private, current-source and formula-safe contracts.
- **Registrar work:** queue → exact roster/result → review/release or named-row return. INC completion, deadline amendment, late entry and grade correction expose only their applicable facts, real authority and consequences. A Grade Entry closure points to the actual window or scoped late authority; informational Examination Period dates cannot explain permission to encode grades. Routine saves need no invented external references.
- **Released academic record:** results and useful INC/correction context lead. Fixed average labels, source-backed readiness, exclusions and last complete Through [term] values follow PRD 05. Do not show a partially calculated value, an open-INC completion action after expiry, a fabricated zero, or a historical record as current authority. Keep the history intact and secondarily accessible.
- **Role handoffs:** initial release, INC resolution and correction identify any real Registrar enrollment review without promising an automatic subject change. The Head remains read-only and sees no unreleased grade values. Student views use the same released facts and retain their separately owned layout. Notifications retain PRD 05 privacy and after-commit behavior.

Challenge every step, field, duplicated feature and information placement against the user's task and #48's standing rule. Derive known facts; simplify unjustified collection or display. Material product/contract changes are reconciled through their owning records before affected implementation. Shared history, authorization and source integrity remain purposeful safeguards.

## States, layout and accessibility

Cover assigned/unassigned and filtered-empty work, no official learners, draft/submitted/returned/released, Grade Entry open/closed/late-authorized, changed membership or submitter, stale/concurrent actions, and INC open/overdue/amended/resolved. Reuse the canonical four average-readiness states and deadline rules rather than inventing new statuses. Typical small classes and queues must also accommodate long names, mixed programs and larger lists without losing context; no unsupported numeric capacity promise follows.

Preserve the selected Term, class/learner and meaningful filters through inspect, cancel and return. Use stable shared sidebar/navigation and recognizable breadcrumbs. Avoid redundant Back controls when the existing context-preserving navigation already serves the return; retain one when it solves a real task exit. Errors preserve safe entered data and identify the changed source, responsible office and safe retry/refresh/support action.

Desktop supports readable result entry and comparison; narrow screens retain identity, state and action order with labelled stacked facts or an explicitly contained table when comparison needs it. Keep primary controls reachable, history discoverable, and operational print independent of screen layout. Use native labels, keyboard/focus behavior and announced validation/status; result or readiness meaning never depends on color alone. Purposeful motion communicates state and respects reduced motion. Inherit shared contrast, forced-colors and light/dark behavior.

## Prototype, implement and check

Before implementation, inventory affected pages, role variants, materially changed dialogs, data/state variants, entry/return paths and reusable components. For each materially changed task surface: inspect and simplify → image prototype → implement → rendered check, then proceed to the next. One picture of the Registrar landing page does not cover Faculty entry, INC/correction dialogs or Head inspection. Reuse approved shared patterns; prototype a dialog separately when its hierarchy or interaction materially changes. Incidental states need appropriate checks rather than a separate image for every combination.

Use Impeccable judgment with relevant Product Design and better-* skills. Confirm installed framework versions and consult matching Laravel Boost/official Filament composition/customization guidance; use Serena reference tracing when available. Preserve native source, action, validation, theme and focus lifecycles. Resolve missing tools through sufficient authoritative alternatives and label inspection limits.

Reuse valid existing evidence. Check representative changed role surfaces, phone/desktop behavior, meaningful failure paths and source/output connections, then fix demonstrated owned defects. Consolidate equivalent tests and obsolete code only after tracing consumers; retain distinct permission, privacy, atomicity, concurrency and immutable-history coverage. Avoid arbitrary scores, exhaustive viewport/state matrices and repeated full-suite runs as default gates. Report the actual verified scope and remaining limits.
