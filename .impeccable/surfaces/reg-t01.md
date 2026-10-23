---
version: 1
slug: "reg-t01"
primary_target: "resources/views/filament/pages/term-overview.blade.php"
related_targets: ["resources/views/filament/pages/term-calendar.blade.php", "resources/views/filament/pages/term-classes.blade.php", "resources/views/filament/pages/term-class-proposal.blade.php", "resources/views/filament/pages/term-resources.blade.php", "resources/views/filament/pages/academic-approvals.blade.php"]
---

# Surface Brief: REG-T01–REG-T03 — Exact-Term Preparation

<!-- impeccable:surface-brief 1 -->

## Job, authority and scope

**Mode: Operate.** Registrar prepares one exact Term's calendar, classes and resources; Academic Head inspects the same evidence read-only. Faculty supplies owned availability and reads official results through [reg-t04](reg-t04.md), which also owns candidate/official timetable composition.

Canonical inventory: REG-T01, REG-T02, REG-T03 and the planning portion of AHD-001. Reuse the existing [reg-t04](reg-t04.md), [reg-e01](reg-e01.md) and [applicant-registrar](applicant-registrar.md) briefs for their respective surfaces. PRD 03/05 own behavior, Blueprint role coverage, PRODUCT/DESIGN product and visual identity, and Architecture technical boundaries. None of these design choices grants institutional approval or relaxes a safeguard.

Current scope, owner, delivery order, progress and evidence live in [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and its children. [#76](https://github.com/yosoykyle/SIA-TALA/issues/76) owns the bounded experience follow-up to #58/#66, using the shared clarity achieved in #68. Earlier rejected concepts, dated annotations, synthetic-fixture setup and deployment authorizations remain in their owning Issues and Git history; they are not current design or operation permissions.

## Direction contract

**THESIS:** One compact exact-Term frame makes current work, actual state and the next useful action clear. Classes and resources can be prepared in parallel.

**OWN-WORLD:** Preserve the accepted school-first crest, Inter 700/600/400 roles, green, supporting blue, shallow skeuomorphic Aqua-like controls, opaque overlays and paired light/dark/system appearance from DESIGN.md. Retain the established Term Preparation Guide identity; refine its hierarchy rather than replace the brand.

**STORY:** Select Term → inspect real unfinished work → record calendar and prepare classes/resources → review a whole-Term candidate → publish → inspect the official result.

**FIRST VIEWPORT:** Show Term identity/change once, five discoverable task destinations and actual working content. Status, owner and remedy precede secondary evidence. Avoid repeated mastheads, introductions, status matrices and return controls.

**FORM:** Use supported native Filament pages, navigation, forms, tables and actions; bounded inspection/decisions use dialogs, contextual preparation may use slide-overs, sustained source work uses pages. Choose composition by task rather than repeating cards or a compulsory stepper.

**FINISH:** Account for affected role surfaces and contextual returns, with proportionate behavioral and rendered checks. Agents own technical verification; the owner's first-time-user review judges appearance, clarity and understandable use.

## Shared frame, navigation and information

Keep Overview, Calendar, Sections & classes, Faculty & rooms and Timetable separately discoverable with one consistent current marker. The selected label may use understandable equivalent wording consistently. These are parallel task destinations; a wizard may divide one recording command, not gate the whole workflow.

Retain exact Term, role, current task, local view and valid criteria through drill-in, cancellation, reload and return. Breadcrumb/sidebar/URL ancestry and contextual links must agree. Remove a body Back control only when it duplicates a usable return; Cancel may discard changes and is a distinct action. Head never gains editing/preparation/approval powers.

With no selected Term, offer the role-appropriate create/select action and readable Terms grouped by Academic Year, including type, dates and recorded state. Required Academic Year setup is reachable through ordinary UI. Several current Terms require explicit choice; never silently select an arbitrary one. Switching context safely resets stale pending inputs.

Show a current fact once at its useful location. In particular, active calendar authority, a proposed successor, actual generation outcome, accepted draft and current official version are different facts. Use one consistent projection of existing state; do not invent a new workflow engine or a false all-clear.

Challenge each information item, field, feature and step. Remove proven redundancy and unnecessary routine inputs within product authority; do not remove required facts, capabilities or meaningful history. Current records lead; named source/history detail remains findable without an always-open evidence rail. Equivalent intent uses consistent supported controls.

## Overview and Calendar

Overview shows actual unfinished work, responsible source and direct remedy, plus source-backed Program/curriculum-year coverage. Record real student sections, classes and weekly meetings; count shared classes once globally while retaining each association and distinct totals. Count Programs with classes and retain advisory empty/unprepared rows.

A curriculum gap does not prove a year is running or cancelled, nor require every year to run. Additional, mixed-year and unassociated demand stays explicit. Counts or deadlines alone do not prove complete readiness. Never infer learner type/year from a group or create missing classes through generation.

Calendar leads with teaching dates, approved weekdays/hours, breaks, exceptions and current/proposed authority. Enrollment and Grade Entry dates are separate consuming-action windows; Examination Period is informational with a named unavailable state. Each missing activity states its actual consumer effect and an adjacent source-owned remedy.

Preserve focused calendar recording, separate guarded activation and immutable successors/history. Core activation does not require unrelated operational dates or the Faculty reply deadline. Record later operational dates through the approved successor path; do not mutate Active core facts or confuse superseding a package with closing a Term. Keep a canonical calendar entry and any legacy entry still needed by a retained consumer.

Activated Term identity reads as protected facts rather than ineffective Edit controls. Draft identity guards remain; the ongoing Faculty teaching-load default is reached from Faculty & rooms with per-Faculty precedence preserved.

## Classes, student sections and proposal

Use human terms: student section (TermCohort), class (Class Offering), weekly meeting requirement (SchedulingDemand), draft timetable (candidate) and official timetable (published version). Technical source identities remain available. Classes and Student sections have distinct local purposes; grading-teacher assignment belongs to Grades & Completion.

Class details lead with course identity, linked student sections/counts, delivery groups and requirements, then findings and meaningful history. Head shares read-only inspection; Registrar's permission-bound Manage class opens sustained editing. Resolve linked classes across the whole exact Term independently of display filters; refuse cross-Term records.

Make the full proposal manageable through task-fit search/grouping and conditional preparation fields. Course decisions remain discoverable; only intended preparation exposes required details. Preserve hidden/local draft choices when filtering. Applicable curriculum rows start as Skip until Registrar supplies intended classes, real learner counts and approved capacity. No automatic all-year selection, invented counts/capacity or uniform-year restriction follows.

Keep useful single-class and bulk paths where their purposes differ; consolidate only redundant controls. Class preparation remains available while generation resources are incomplete. Preserve complete proposal scope, atomic writes, source/version checks and cancellation guards for published, enrolled or reserved work.

## Faculty and rooms

Faculty rows distinguish qualifications, load/default exceptions, availability declaration and actual request/delivery evidence. Missing declaration never proves a request was sent. Requests show due time and genuinely awaiting responses together. Request availability and Set/Change deadline remain separate effects; the deadline is generation readiness, not core activation.

Show current official teaching load with its basis labelled; never imply it is candidate allocation. Distinct canonical-course counts and teaching units retain PRD 03 definitions. No institutional preparation cap has been supplied: informational counts add no new required setup or blocker.

Give direct source-bound correction paths for missing qualifications, load or unavailable-time facts. Do not require unrelated Faculty responses or retype facts already owned elsewhere. Weekly unavailable times are recurring exclusions; dated no-class exceptions remain Calendar.

Rooms distinguish active catalog state, capacity/type/equipment and time availability. Quick inspection is a compact native read-only dialog shared with Head; authorized maintenance uses one form/save. Readable equipment names preserve exact keys/identities and imply no new quantity, catalog, deletion, maintenance or booking policy. No Online room, preferred-time field, travel matrix or availability approval queue is introduced.

Reached forms/pages keep compact Term context, meaningful task/record headings, source history and originating-task return. Required contextual correction/commitment reasons and authority remain.

## Components, states and ranges

Use installed-version documented Filament APIs and supported extension points; new dependencies require authorization. Native search, sorting and pagination serve working lists; changing a local view resets incompatible search/page state. Fixed grids, complete generation-confirmation scope and official outputs retain their own structure. Ordinary related records render eagerly; real pending/disabled feedback remains visible.

At desktop, group related facts without competing panels. Tablet/phone stack in reading order, wrap toolbars and retain consequential labels. Check long names, mostly skipped proposals, many Terms/versions, varied approved teaching hours and missing operational windows without imposing every state/viewport permutation.

Cover first-use/no package, blocked/ready Draft, Active/historical Closed, Special/concurrent Terms, missing/upcoming/open/closed operational dates, filtered-empty, validation, stale/concurrent changes, failure and inaccessible states. Each blocker names the failed fact, owner, source and safe remedy. Missing/unmeasured evidence remains unavailable.

Preserve labels, text-backed status, visible focus, keyboard access, zoom/reflow, error association and accessible announcements. Native dialogs retain Close/Escape/focus return with opaque overlays; keep the shared dialog host outside collapsed or scroll-hidden content, including conditional views with no table. Status updates retain focus; invalid submissions may focus the error summary/first invalid control.

Purposeful feedback explains saved facts, updated readiness and activation with reduced-motion equivalents. Preserve finite shared recovery motion, immediate recovery actions and no visible pause/stop control. No global animation or branding rebuild follows.

## Prototype, implementation and verification

For each changed page, task view or materially changed dialog/role variant: inspect/simplify → image prototype → implementation → rendered check, then continue. One overall image is insufficient. Reuse the selected world/components and valid evidence; account for surfaces deliberately retained unchanged. This brief does not authorize image generation or execution by itself.

Reuse meaningful affected regression coverage and batch representative desktop/phone and paired-appearance checks, fix demonstrated defects, then perform a bounded confirmation. Preserve exact-Term switching, staleness, Head read-only, whole-Term command scope and source/output returns. Do not require new copy-only tests, arbitrary design scores or repeated full-suite/browser matrices.

Synthetic data is identified by a compact factual annotation, not review instructions. Fixture presentation is not live policy, solver/deployment proof or human acceptance. Preserve the owning Issue's review/reporting and publication boundary under the [Orchestrator Protocol](../../00_Project_Documents/TALA-Orchestrator-Protocol.md).
