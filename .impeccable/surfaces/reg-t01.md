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

**THESIS:** Focused workspace: stable sidebar wayfinding and one compact exact-Term frame leave each page to its current job. Calendar, classes and resource preparation remain freely reachable; only genuine dependencies gate a command.

**OWN-WORLD:** Preserve the accepted school-first crest, Inter 700/600/400 roles, green, supporting blue, shallow skeuomorphic Aqua-like controls, opaque overlays and paired light/dark/system appearance from DESIGN.md. Reuse the accepted #68 task-list, surface and control patterns. Reimagine planning composition around understandable jobs while preserving the brand.

**STORY:** Select Term → inspect real unfinished work → record calendar and prepare classes/resources → review a whole-Term candidate → publish → inspect the official result.

**FIRST VIEWPORT:** One page title and compact Term context identify the academic period and school-wide scope. Exact dates, recorded state and full recorded name stay in the full-width native Term details disclosure. Its header uses the shared surface and primary text; the open body uses the shared sunken surface with primary values and muted supporting explanation in both appearances. The existing Term Planning sidebar entry contains Term overview, Teaching calendar, Classes & student sections, Faculty & rooms and Timetable; the surrounding global navigation stays unchanged. Overview names the prepare → generate/review → publish relationship once, with a native optional How Term planning works explanation. It groups the four planning areas in one list, keeping each area's current state and outstanding work together; Program/year coverage and settings/history remain reachable below the main work. Keep full recorded identities in named details. No top destination strip, repeated orientation blocks or competing navigation panels.

**FORM:** Owner-selected Focused workspace, grounded structure 1 of the surface round, seed 1ae5a82c; code-led. Reuse native Filament navigation, forms, tables and dialogs. Collapse contextual navigation at narrow widths without losing exact-Term returns. The signature is a stable place and one obvious current job across the connected journey.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Shared frame, navigation and information

The Term chooser selects the Term Planning parent and shows no Term-specific children. After a Term is opened, keep Term overview, Teaching calendar, Classes & student sections, Faculty & rooms and Timetable discoverable in the contextual sidebar, including reached Term/class source pages. Keep the shared global sidebar groups, destinations and order stable across modules. Show planning destinations as native children of the existing Term Planning entry, matching Admissions queue navigation and DESIGN.md's shared separate parent surface and aligned child group with icons in both sidebar sizes; never replace the surrounding sidebar with a planning-only layout. Preserve manual expansion preferences. Do not repeat a strip of clustered destination options above working content. These are parallel task destinations; a wizard may divide one recording command, not gate the whole workflow. Overview presents Calendar, Classes & student sections, Faculty & rooms and Timetable once, with each area's actual state, outstanding work and waiting replies together. Keep all distinct unfinished work visible in its area, including candidate state and the official version still in use. Avoid a separate recommendation banner or secondary work queue that repeats the same tasks. Program/year coverage stays reachable in a full-width disclosure. Main class rows lead with the course, linked learner groups and preparation state; the searchable full class code remains in Details and an optional native column.

Retain exact Term, role, current task, local view and valid criteria through drill-in, cancellation, reload and return. Use the shared native Filament breadcrumb for page ancestry throughout planning and reached source pages, including the exact-Term planning root. Do not alternate that ancestry with standalone Back buttons or repeat both. Keep source-specific continuation controls only where they perform a distinct task. Breadcrumb/sidebar/URL ancestry and contextual links must agree. Reuse native Heroicons Outline consistently: plus for adding, pencil-square for preparation/editing, check for confirmation, eye for inspection, printer for printing, arrow-path for retry/refresh; text names the action and icons never replace consequential labels. Primary work uses green, routine navigation/inspection uses neutral gray, cancellation color follows DESIGN.md’s effect-based rule. Remove a body Back control only when it duplicates a usable return; Cancel may discard changes and is a distinct action. Head never gains editing/preparation/approval powers.

With no selected Term, offer one calm grouped chooser or a first-use surface when no records exist. Keep Add Term compact in the appropriate action zone; an empty-state primary action lives in its content surface. Type names and dates lead; custom names/Academic Year labels wrap without clipping. Required Academic Year setup is reachable through the Term form. Several active calendar sources require explicit choice; an unambiguous active-calendar default names its basis. Change Term always opens the chooser and invalid IDs never silently substitute another Term. Switching context safely resets stale pending inputs.

Show a current fact once at its useful location. In particular, active calendar authority, a proposed successor, actual generation outcome, accepted draft and current official version are different facts. Use one consistent projection of existing state; do not invent a new workflow engine or a false all-clear.

Challenge each information item, field, feature and step. Remove proven redundancy and unnecessary routine inputs within product authority; do not remove required facts, capabilities or meaningful history. Current records lead; named source/history detail remains findable without an always-open evidence rail. Equivalent intent uses consistent supported controls.

## Overview and Calendar

Overview explains that a Term is one school-wide academic period, not a selected Program or year. Lead with the single planning task list; the detailed Programs and year levels surface follows it so coverage does not displace the work from the first viewport. Show recorded Programs and served curriculum years visibly, with compact per-Program expansion for source-backed details and years with no classes. Do not bury this scope in a wide table. Keep missing class links within the same Program context, with a concrete remedy; do not show a detached association rail or floating Student sections button. Settings belong in the header action zone. Record real student sections, classes and weekly meetings; count shared classes once globally while retaining each association and distinct totals. Empty years remain advisory.

A curriculum gap does not prove a year is running or cancelled, nor require every year to run. Additional, mixed-year and unassociated demand stays explicit. Counts or deadlines alone do not prove complete readiness. Never infer learner type/year from a group or create missing classes through generation.

Calendar leads with prominent teaching-date endpoints and a source-proportional diagram of approved teaching hours and breaks, with the same facts in readable text. Keep exceptions and current/proposed authority explicit. Activity dates remain visible as compact icon/date/state rows; disclose longer consumer explanations separately. The shared context is one quiet date/status row with secondary recorded names behind Term details, and the chooser groups clear Open Term rows by Academic Year. Enrollment and Grade Entry dates are separate consuming-action windows; Examination Period is informational with a named unavailable state. Each missing activity states its actual consumer effect and an adjacent source-owned remedy.

Preserve focused calendar recording, separate guarded activation and immutable successors/history. Core activation does not require unrelated operational dates or the Faculty reply deadline. Setting that reply deadline is routine Registrar recording: its approval reference is optional, actor/time are automatic, and correcting an existing deadline still requires a reason and current-source check. Record later operational dates through the approved successor path; do not mutate Active core facts or confuse superseding a package with closing a Term. Keep a canonical calendar entry and any legacy entry still needed by a retained consumer.

Activated Term identity reads as protected facts rather than ineffective Edit controls. Draft identity guards remain; the ongoing Faculty teaching-load default is reached from Faculty & rooms with per-Faculty precedence preserved.

## Classes, student sections and proposal

On Overview, distinguish actual work from quiet reference destinations with text-backed Review/Inspect or Waiting cues. Keep every destination reachable without claiming the whole Term is complete. Summarize candidate/official state once per row. Reached task pages retain exact Term identity/dates and Change Term, while the school-wide explanation is available through Term details rather than repeated above every task. The optional How Term planning works explanation reuses the same raised disclosure as Program coverage, with shared inset cards inside; never place inset cards on an identical inset wrapper. Registrar wording names preparation and publication; Academic Head wording names inspection and the Registrar’s ownership.

Faculty availability defaults to teachers linked through this Term’s offered-course qualifications, compatible requests, responses or current official assignments. The native filter also exposes all active faculty. Header reply counts stay scoped to Term-linked faculty; catalog-wide counts are labelled as such. This is a reading scope, not a change to request recipients, eligibility, assignment or generation.

Use human terms: student section (TermCohort), class (Class Offering), weekly meeting requirement (SchedulingDemand), draft timetable (candidate) and official timetable (published version). Technical source identities remain available. Classes and Student sections have distinct local purposes; grading-teacher assignment belongs to Grades & Completion. Optional unrecorded section years do not become repeated missing-value warnings. Classes already included in a published timetable offer Keep in the preparation proposal and explain that changes require the controlled timetable revision; the server guard remains authoritative.

Class details lead with course identity, linked student sections/counts, delivery groups and requirements, then findings and meaningful history. Head shares read-only inspection; Registrar's permission-bound Manage class opens sustained editing. Resolve linked classes across the whole exact Term independently of display filters; refuse cross-Term records.

The proposal contains existing classes and explicitly selected courses, not one empty decision form for every curriculum entry. A searchable Program/year-labelled course picker adds intended courses or another split class. The complete approved catalog remains available and existing/selected decisions survive search. Only intended preparation exposes required fields. Unselected courses remain unchanged; no automatic all-year selection, invented counts/capacity or uniform-year restriction follows.

Use one canonical exact-Term Classes workbench. Retained source-list links return there rather than presenting a second Classes table. A bounded Add draft class dialog records the offered course, class code and capacity, deriving Planned state consistently, including retained direct-create routes; preparation/learner confirmation is a separate explicit effect. Quick class details use a compact dialog without a nested identity card or repeated raw Term label; sustained delivery-group/source editing remains a page. Breadcrumbs must not repeat the same Classes ancestor/current item.

Keep useful single-class and bulk paths where their purposes differ; consolidate only redundant controls. Class preparation remains available while generation resources are incomplete. Preserve complete proposal scope, atomic writes, source/version checks and cancellation guards for published, enrolled or reserved work.

## Faculty and rooms

Faculty rows distinguish qualifications, load/default exceptions, availability declaration and actual request/delivery evidence. Missing declaration never proves a request was sent. Requests show due time and genuinely awaiting responses together. Request availability and Set/Change deadline remain separate effects; the deadline is generation readiness, not core activation.

Show current official teaching load with its basis labelled; never imply it is candidate allocation. Distinct canonical-course counts and teaching units retain PRD 03 definitions. No institutional preparation cap has been supplied: informational counts add no new required setup or blocker.

Give direct source-bound correction paths for missing qualifications, load or unavailable-time facts. Do not require unrelated Faculty responses or retype facts already owned elsewhere. Weekly unavailable times are recurring exclusions; dated no-class exceptions remain Calendar.

Rooms distinguish active catalog state, capacity/type/equipment and time availability. Quick inspection is a compact native read-only dialog shared with Head; authorized maintenance uses one form/save. Readable equipment names preserve exact keys/identities and imply no new quantity, catalog, deletion, maintenance or booking policy. No Online room, preferred-time field, travel matrix or availability approval queue is introduced.

Program coverage opens as one compact grouped list: year identity, class count with meeting/section context, and a contextual action align across rows. Keep association/coverage findings visible; place long curriculum and student-section references in a supporting disclosure rather than repeat them at equal emphasis in every row. Preserve exact program/year destinations and the school-wide Term scope.

Expanded supporting detail uses the same subtly shaded, padded surface as program/year-level rows. Disclosure triggers keep their leading position when opened; revealed surfaces fill their container while prose retains a readable measure. Term details uses the same dedicated full-width row on Overview and reached task headers. Apply the shared reveal treatment to identity, calendar/source, resource limits, proposal references, timetable measures/history and reached native collapsible sections, keeping native disclosure and focus behavior.

Term settings presents the Term type/status and names first, a visual Term-date range, then the teaching-load default. Distinguish academic-year status from Term status and class dates from Term dates. Its active teaching week reuses the Calendar page's source-driven diagram and textual key; historical grid settings and recorded calendar versions remain secondary native disclosures. History emphasizes each version/state and class-date range, with approval/recorder/time below in one continuous inset body. Native contained sections own corner geometry and elevation; their revealed content must not add competing rounded corners or a second shadow. The protected Term edit context is compact and read-only, with long identity references disclosed and the actual editable teaching-load field in its own native form section. Preserve no-active-calendar and no-teaching-days states without falling back to historical hours.

Add Term leads with one native section containing its four required identity/date fields. Custom naming and the optional Faculty load default live in a clearly named collapsed Optional settings section on creation; draft editing exposes existing optional values, and activated-Term load editing remains open. Preserve required/date bounds, optional values, name derivation, Draft creation and native save/cancel/create-another paths.

Reached forms/pages keep compact Term context, meaningful task/record headings, source history and originating-task return. Keep the Classes/student-sections view switch above the working table rather than after all rows. Qualification actor/time and the load-exception base snapshot are system-owned facts, not editable routine inputs; retain original provenance and the exception's exact Term on edit. Required contextual correction/commitment reasons and authority remain.

## Components, states and ranges

Use installed-version documented Filament APIs and supported extension points; new dependencies require authorization. Native search, sorting and pagination serve working lists; changing a local view resets incompatible search/page state. Fixed grids, complete generation-confirmation scope and official outputs retain their own structure. Ordinary related records render eagerly; real pending/disabled feedback remains visible.

At desktop, group related facts without competing panels. Tablet/phone stack in reading order, wrap toolbars and retain consequential labels. Check long names, mostly skipped proposals, many Terms/versions, varied approved teaching hours and missing operational windows without imposing every state/viewport permutation.

Cover first-use/no package, blocked/ready Draft, Active/historical Closed, Special/concurrent Terms, missing/upcoming/open/closed operational dates, filtered-empty, validation, stale/concurrent changes, failure and inaccessible states. Each blocker names the failed fact, owner, source and safe remedy. Missing/unmeasured evidence remains unavailable.

Preserve labels, text-backed status, visible focus, keyboard access, zoom/reflow, error association and accessible announcements. Native dialogs retain Close/Escape/focus return with opaque overlays; keep the shared dialog host outside collapsed or scroll-hidden content, including conditional views with no table. Status updates retain focus; invalid submissions may focus the error summary/first invalid control.

Purposeful feedback explains saved facts, updated readiness and activation with reduced-motion equivalents. Preserve finite shared recovery motion, immediate recovery actions and no visible pause/stop control. No global animation or branding rebuild follows.

## Prototype, implementation and verification

Inventory affected role, record and component variants as well as pages: loading, first use, empty, filtered-empty, partial, success, warning, error, disabled, read-only and denied states where applicable. Use DESIGN.md's shared state/role guidance. Empty search results offer criteria recovery; missing sources identify the responsible office without instructing Head to edit. Keep source, automated and rendered evidence distinct in #76; reuse valid evidence proportionately.

For each changed page, task view or materially changed dialog/role variant: inspect/simplify → image prototype → implementation → rendered check, then continue. One overall image is insufficient. Reuse the selected world/components and valid evidence; account for surfaces deliberately retained unchanged. This brief does not authorize image generation or execution by itself.

Reuse meaningful affected regression coverage and batch representative desktop/phone and paired-appearance checks, fix demonstrated defects, then perform a bounded confirmation. Preserve exact-Term switching, staleness, Head read-only, whole-Term command scope and source/output returns. Do not require new copy-only tests, arbitrary design scores or repeated full-suite/browser matrices.

Synthetic data is identified by a compact factual annotation, not review instructions. Fixture presentation is not live policy, solver/deployment proof or human acceptance. Preserve the owning Issue's review/reporting and publication boundary under the [Orchestrator Protocol](../../00_Project_Documents/TALA-Orchestrator-Protocol.md).
