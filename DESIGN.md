---
name: Servitech Institute Asia — TALA
description: School-first academic operations with neutral surfaces, green-led actions, and supporting TALA blue
colors:
  primary: '#2f7d3b'
  primary-deep: '#256331'
  primary-dark-anchor: '#6acb7c'
  on-primary: '#ffffff'
  supporting-link: '#1e3a8a'
  focus: '#a16207'
  canvas: '#f4f4f5'
  chrome: '#ececee'
  surface: '#ffffff'
  sunken: '#f4f4f5'
  overlay: '#ffffff'
  ink: '#18181b'
  muted: '#52525c'
  boundary: '#71717b'
  dark-canvas: '#111114'
  dark-chrome: '#1b1b20'
  dark-surface: '#242429'
  dark-sunken: '#16161a'
  dark-overlay: '#303036'
  dark-ink: '#fafafa'
  dark-muted: '#d4d4d8'
  dark-boundary: '#9f9fa9'
  dark-focus: '#facc15'
typography:
  body:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '1rem'
  label:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '0.875rem'
  caption:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '0.75rem'
  title:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '1.5rem'
    lineHeight: 1.25
  section:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '1.125rem'
    lineHeight: 1.3
rounded:
  compact: '6px'
  control: '8px'
  surface: '12px'
components:
  button-primary:
    backgroundColor: '{colors.primary}'
    textColor: '{colors.on-primary}'
    rounded: '{rounded.control}'
  input:
    rounded: '{rounded.control}'
  card:
    backgroundColor: '{colors.surface}'
    rounded: '{rounded.surface}'
---

# Design System: Servitech Institute Asia — TALA

## Overview

**Creative North Star: "Clear Institutional Operations"**

Use neutral light/dark surfaces, green-led primary actions, supporting TALA blue, Inter typography, and restrained tactile controls. Preserve institutional artwork, framework boundaries, and evidence-backed UX review. Servitech Institute Asia Inc. leads; TALA appears secondarily as “Powered by TALA” outside the Applicant panel and its account-entry pages, following the October 10 owner decisions F65/F66 (Issue #59 D3/D2).

Each operating surface makes its task, current state, responsible owner, primary action, and recovery path easy to understand. Assess existing screens and prototype specimens against the owning product and UI contracts. Laravel Boost and installed-version official framework documentation are the primary implementation references; the official Filament demo provides optional examples of native composition and behavior. `00_Project_Documents/design-evidence/layout/` supplies supporting visual evidence. Current visual decisions belong to this document and the UI Surface Blueprint. Use native Heroicons for production interface icons.

Framework references for the currently installed majors: [Filament 5 documentation](https://filamentphp.com/docs/5.x), [Bootstrap 5.3 documentation](https://getbootstrap.com/docs/5.3/), and the optional [official Filament demo](https://demo.filamentphp.com). Recheck installed versions before relying on APIs; demo data and workflows never define TALA requirements.

**Key characteristics:**
- Neutral surfaces, green-led actions, supporting TALA blue, restrained gold/yellow cues, and full-color institutional artwork.
- Clear hierarchy and task-oriented language across public, Applicant, Student, Faculty, and Staff experiences.
- Operational layouts suited to the information being used, not a universal dense-table or card pattern.
- Formal, version-bound, monochrome-capable official outputs with the school as issuer.

## Colors

Use the paired semantic tokens in the canonical UI Surface Blueprint. Green carries primary action and selection emphasis; supporting blue carries appropriate links/information and secondary identity. Primary action, selected navigation, success status, and destructive action retain distinct component treatments and text-backed meaning.

Compose light and dark appearances through the existing theme mechanism rather than mechanically inverting colors. Verify actual rendered text, control boundaries, focus, gradients, and status pairs. Crest samples are adaptation references, not claimed official institutional color specifications.

The Blueprint's paired values guide semantic color adaptation. Filament generates its native primary palette from the approved green anchor `#2F7D3B` and retains native Zinc surfaces/borders; Bootstrap uses the paired light/dark anchors through its existing variables and color modes. Preserve each framework's accessible component/state palette. Keep school identity, meaning, hierarchy, and measured rendered contrast consistent across both frameworks.

Shared action controls use the framework's native semantic palettes, outlined variants and state behavior. Green identifies the current primary task; achieved success remains a status, neutral controls support navigation and routine secondary work, warning marks consequential interruption, and danger marks withdrawal, discard or cancellation. A healthy operating state need not invent a primary task. Restrained tactile shading may clarify a control without overriding its native color or outlined boundary. Consequential actions keep their visible labels on small screens; compact filters and utilities retain accessible names. Preserve native navigation/link semantics, focus, loading, disabled and forced-colors behavior. Screen styling preserves official-output and print contracts.

TALA uses one material grammar across Filament and Bootstrap. Call the existing control family **skeuomorphic Aqua-like buttons** consistently. Action buttons, eligible icon actions (including the collapsed-sidebar “Find a workspace page” shortcut), the account trigger, public menu trigger and action-style links use its full highlight, lower edge, modest elevation and depressed press state. Frequently used sidebar navigation, native tabs/task selectors, theme selection and pagination use a shallower member of the same family; the current choice stays depressed/seated and distinguishable from an action. These priorities follow task structure, not measured usage telemetry. Editable fields and standalone navigation tracks use inset depth. Surfaces hold content: solid, softly elevated and hairline-edged, with a top rim in dark appearance. Floating menus and search results use solid opaque overlays with no backdrop blur. Reuse the explicit foundation selector whitelist; ordinary text links, menu rows, record expansion, input-affix icons, notification close, section toggles, sort headers, wizard steps and reorder handles retain quiet native presentation. Preserve native semantic palettes, focus, loading/disabled meaning and forced-colors behavior. Busy indicators reflect real pending work without hiding existing results; ordinary relationship tables and widgets follow the Loading Rule below.

## Typography

Student/Staff shell and non-Applicant authentication attribution remains compact and secondary to school identity. Applicant shell and account-entry pages omit Powered by TALA; their school identity includes the signed-in Applicant's name when available. Choose screen mark sizes by legibility, hierarchy and available space. Preserve approved artwork and official-output print constraints. Native MFA code digits remain centered, readable and clearly bounded without replacing input, validation, focus or paste behavior.

Use Inter for headings, interface text, controls, tables, amounts, and identifiers, with system sans-serif fallbacks. Choose a readable type hierarchy for the task, density, device and zoom behavior; accessibility requirements govern the minimum usable result. Use semantic heading hierarchy, readable wrapping, tabular figures, selectable text, and zoom/reflow. Error and printable outputs retain reliable system fallbacks.

## Layout

Public arrival, guided learner tasks, operational workbenches, and official outputs use compositions suited to their tasks within one visual identity. Put the current context and next meaningful action where the user can find them; progressively disclose secondary evidence and exceptional controls. Keep responsive reading order and accessible alternatives. A visual weekly timetable may be the primary scheduling view when that best supports review, paired with the equivalent filterable meeting table required by the UI Blueprint. Choose each native component for the information and action it supports.

Determine dimensions and component arrangements through task-led judgment and rendered evidence. Surface briefs record required outcomes and accepted decisions without prescribing visual recipes.

Cycle overview pairs compact intake context with explicit application dates, separating public closing from the boundary for new correction requests. Blocked publication findings lead; programs/paths, guidance and accountable setup facts follow, with history secondary and native requirement-set relation navigation. Applicant surfaces cover conditional correction, consent, identity, evidence and recovery states as well as the default page. Public arrival uses an academic school composition: a confident Servitech masthead, approved institutional artwork, a clear factual introduction and Explore programs lead. School photography/video is optional; the crest and authored static/ambient composition support the current direction without an additional media asset. Compact source-derived admission availability and effective school Announcements share one clearly labelled masthead card stack, with manual Previous/Next controls and position. Their sources remain independent. A visually composed Program catalog follows with authoritative name/duration/availability; FAQ and Visit/location complete discovery before directly reachable returning-applicant tracking. Keep compact source-derived admission availability/deadline, the Asia/Manila clock and one shared Sign in function. Native Home/Programs/conditional Announcements/Visit/FAQ anchors have an accurate current cue. Preserve the existing navbar backdrop, top/bottom blur strips and footer background while refining foreground composition, buttons, sign-in placement, responsive branding and footer grouping. Status tracking reaches the form directly without a redundant collapsible heading, retaining action/hash entry, visible validation/confirmation and entered values. Visitor copy explains useful actions and school facts, avoiding internal codes, versions, checked timestamps and system mechanics. Full Application references remain selectable and copyable; new grouped references improve readability while existing identifiers remain stable.

### UI/UX assessment and component selection

Impeccable leads the assessment of an operating surface's purpose, hierarchy, cognitive load, copy, composition, and responsive transformation. Use the relevant writing, layout, accessibility, color, typography, and UI skills where they resolve a specific problem. Laravel Boost, Serena, and installed-version official documentation support code tracing and implementation choices; tool use alone is not design evidence.

Start with the user's task and the information needed for the current decision. Impeccable determines the reading order, grouping, action hierarchy and responsive composition. Match that composition to supported native configuration and reusable installed components. Where those options leave a demonstrated usability gap, use a focused Blade/Livewire layout with the existing Filament primitives and Tailwind theme. The public gateway uses its existing Bootstrap surface. Choose the smallest implementation that satisfies the task and preserves framework lifecycles.

Research the relevant capabilities across the installed framework's documentation and official demo, including supported configuration, styling and extension points. Compare arrangements by what users can recognize, understand and complete. Current decision information stays visible; occasional history or technical evidence may be disclosed on demand. Group related facts before introducing another container. Select a control by the choice it represents, the number of options and the need to compare them. The demo supplies visual and interaction evidence; its business content remains separate from TALA's requirements.

Record the accepted task-specific direction in `.impeccable/surfaces/`, derived from the owning Issue and canonical UI contract. Inspect representative desktop/mobile states in one bounded pass, fix the demonstrated problems together, and confirm the result once. Evidence must show the user-visible improvement and retained behavior. Check tool capability before scheduling a verification method; use truthful task-applicable evidence for the outcome and never label a simulation as a direct browser observation.

### Data hierarchy and working records

Present operational data in this order: selected scope and purpose; the records being worked on with decision-critical facts; truthful state and the permitted next action; supporting record detail; optional quality measures, history and technical provenance. A directory of links is insufficient when the task is to prepare or review records. Explain a missing prerequisite beside its affected record and link to the remedy. A source count or recorded deadline must never stand in for whole-task readiness.

Missing, unmeasured and zero are different states. Show unknown measurements as unavailable, retain units, and identify the source/version and time basis where they affect a decision. Candidate and official representations share the same reading structure while identifying their different authority. A planning overview groups source records by Program, curriculum year and student section before presenting totals. Shared classes may serve several groups but count once in whole-Term totals. An absent curriculum-year setup is visible evidence, not proof that the year is running or cancelled. Mixed-year, shared and unassociated records remain explicit; never infer a student's status or year from a section reference. PRD 03 owns scheduling scope and PRD 04 owns individual registration eligibility.

Timetable geometry and student-group identities come from the selected run's captured source; a meeting occupies its real duration once. Current reference labels are supplemental and must not silently change captured group membership. The whole-Term overview shows student sections against captured teaching days; one-section, teacher and room views show weekly relationships, with an equivalent native meeting list. Draft and official versions are states of one Timetable destination with clear authority, rather than competing workspaces. Filters change the view only and never the generation scope. Show the actual scope before generation, including visible curriculum gaps; do not claim that generation creates absent classes.

Use full pages for owned records with their own lifecycle, related setup and a useful return destination. Use contextual native slide-overs for list-driven creation and preparation, and centered native modals for bounded atomic decisions. A supported modal wizard may divide one complex recording command into understandable choices; it does not force the entire parallel planning workflow into a wizard. Choose consistently by intent and complexity, rather than by which resource generated the action. Each reached screen states its purpose, preserves the exact selected Term in its URL, and exposes a return route. Native sub-navigation order communicates orientation; truthful status text communicates preparation. The former numbered Term task cards are superseded by persistent Overview, Calendar, Sections & classes, Faculty & rooms and Timetable navigation, including reached record pages.

Registrar-facing scheduling vocabulary: **student section** is the learner group (`TermCohort`); **class** teaches one course to one or more student sections (`Section`/Class Offering); **weekly meeting requirement** describes the meetings to place (`SchedulingDemand`); **draft timetable** is a candidate; **official timetable** is a published version. Code record names and distinct lifecycle/readiness states remain intact. Technical evidence may name its source records; routine work uses the same words in its link, breadcrumb, heading and action dialog. Assigning the grading teacher belongs to Grades & Completion and must not be mistaken for the timetable's teacher assignment.

## Native composition

Adopt clean shell/content separation, aligned grouping, clear action hierarchy, and task-appropriate responsive components. Display authorized records and capabilities from the owning PRD.

Use Filament’s sidebar-only layout, native desktop collapse, mobile menu/drawer, and ordinary page scrolling, with supported theming and extension points. Preserve complete authorized navigation, account/role/theme controls, safe areas, focus, task data, and reduced-motion access. Verify this behavior within each owning delivery slice.

Choose native components and supported configuration first, then existing compatible extensions. Consider a version-compatible plugin only for a demonstrated gap and with dependency approval; use focused custom UI when the justified workflow still requires it. Framework capability guides implementation effort, while canonical product requirements determine school capabilities. Use Boost and official Filament/Bootstrap documentation for APIs and native behavior, with the official demo when rendered examples help.

The expanded sidebar has one compact school-identity block: the supplied crest and readable school name. Student/Staff workspace or role context sits with navigation or the page context. Powered by TALA occupies their quiet sidebar footer, with a shell-footer placement when the sidebar is unavailable. The Applicant panel instead shows Servitech Institute Asia plus the signed-in Applicant's name, without Applicant Workspace or Powered by TALA; Applicant account-entry pages retain the school identity without those labels. The mobile identity area shows the school once while the native drawer owns navigation. Page headings lead with the task. Preserve approved artwork and readable school identity. All roles reuse the same school-first sidebar, framed account-menu trigger and responsive action defaults. The page finder supports staff and student destinations; the two-page Applicant workspace uses direct navigation. The expanded header balances the crest and two-line Servitech / Institute Asia name with the native collapse control at the trailing edge. Student/Staff selected roles name their workspace separately from school identity; Applicant identity uses the person's name rather than a workspace label. Find a page appears inside the native search field below identity and searches authorized workspace destinations only. Shared alignment edges join identity, search, navigation and account controls; compact controls keep usable hit areas. In the collapsed desktop sidebar, inset hairline dividers separate the identity/collapse control, search shortcut, native destination groups and account footer. Preserve group boundaries when labels are hidden; avoid a divider between every destination. Expanded and mobile navigation retain labeled native groups. Expanded and collapsed sidebar destinations share solid surface fills, subtle decorative hairline edges and readable muted icons, with the existing native icon stroke. Keep green for the seated current destination and gold for keyboard focus. Avoid heavy outlines or thicker icons as a substitute for contrast; the identifying icon carries contrast, while the edge only defines the material. The account trigger uses restrained offset elevation, a visible surface and focus/hover/pressed states in both themes and sidebar sizes. Native icon actions compact below the small breakpoint while keeping accessible names; text-only controls, Save/Submit progression and consequential confirmations retain labels. Native unsaved-change alerts protect Create/Edit forms and writable action modals across panels; existing domain-specific draft persistence remains authoritative. Role-specific content and authorization remain contextual. Applicant task navigation uses native breadcrumbs; draft progression uses Save and continue with secondary Save and exit and confirmed discard in the native action menu.

Counts represent useful authorized work. Prioritize status, responsible owner, remarks, and next action over oversized summaries. Essential feedback accompanies each affected workflow; additional motion polish follows verified functionality.

Surface briefs apply these shared visual foundations to their task-specific decisions.

**Shared component principle:** Similar intent across areas and roles consumes the same installed component and shared styling. Prefer native framework reuse over a parallel component library. `x-tala-navigation` is a thin layout/attribute wrapper around `x-filament::tabs`; native `tabs.item` owns links, buttons, current state, icons and badges. Term timetable view selectors, Grades, Student Academics and Student Enrollment consume it. Term workspace pages use native cluster sub-navigation; native resource tabs in Admissions/Enrollments/Payments/Applicant Intakes consume the same underlying framework components. Keep labels, summaries, URLs, authorization and state in the owning surface. Keep ordinary view navigation distinct from wizard progression, disclosures and an ARIA tab widget with its own keyboard contract. Extract an additional abstraction only for demonstrated repeated intent, never to replace framework behavior or create speculative variants. Public Bootstrap uses its own native components with shared foundation tokens.

Conditional record views must retain the framework's native action-dialog host even when the table is absent. Verify opening, canceling and subsequent navigation in every view; a successful server response alone does not establish that the dialog is usable. Display deadline summaries and editing fields in the same explicitly named timezone, while preserving the canonical stored instant.

Shared form errors remain visible beside their owning controls, including native custom Selects. Associate each inline message with the actual control through `aria-describedby`, preserve existing description tokens and authored IDs, and mark the current invalid state through `aria-invalid`. After a failed submission, focus the first visible invalid control in that submitted form, or its existing purposeful error summary; ordinary loading, saved, filtered and status updates preserve focus. A stable polite summary announces the need to correct fields without implying spoken assistive-technology verification. When errors disappear, clear the shared bridge's error association, invalid state and announcement while retaining unrelated authored semantics. Preserve safe input and native validation/authorization; duplicate-record feedback names the conflicting source and the available correction. Mobile drawer actions that open a content dialog first close the drawer through the native sidebar store, then open the native confirmation after its focus trap releases; cancellation returns to a visible control.

## HTTP and session-recovery presentation

Use one centered reading path, a plain-language H1, a short explanation, one context-safe primary recovery action, secondary technical code, quiet school branding, and secondary Powered by TALA attribution. Keep necessary support and account-recovery actions reachable. The selected `experimental.html` specimen supplies supporting composition and animation evidence.

The selected background uses soft state-aware color blooms and a brief arrival behind readable content. Complete the decorative animation within five seconds of page arrival, then retain the settled background throughout that page visit. Respect reduced motion with an immediate static composition; omit decoration in forced colors and print. Suspend active decoration while hidden or offscreen and preserve the original completion deadline when the page returns. Recovery actions stay immediately available, content stays stationary, and focus remains visible. Verify contrast throughout the effect in both appearances and on narrow screens. This finite treatment follows [WCAG 2.2 Pause, Stop, Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html).

Reuse the existing shared standalone error presentation. Preserve actual HTTP responses, permission checks, authorized destinations, session/account protections, and support access. An unconfirmed submission/payment outcome requires checking recorded state before resubmission; a generic retry must not replay a mutation. Core recovery remains readable and operable without animation, JavaScript, Vite, Livewire, remote fonts, or the prototype project. Session expiry uses the actual response and a context-safe recovery destination.

## Elevation & Depth

The shared ladder is canvas → neutral chrome → solid content surface → floating overlay. Preserve the owner's approved light palette and existing action finish. Dark appearance uses explicit lightness separation, stronger rim/edge definition and soft shadow; more shadow alone cannot separate near-black layers. Its canvas/chrome/content/overlay values are `#111114`/`#1B1B20`/`#242429`/`#303036`, with sunken fields/tracks `#16161A`. Keep one visible elevation per nested content stack. Navigation chrome stays opaque and neutral. Skeuomorphic Aqua-like buttons use full action and shallow navigation variants, with seated current states; inset fields communicate editing. Native validation and focus rings remain authoritative. Check text across the brightest gloss stop as well as the base fill; native inactive/selected navigation labels in dark mode use readable shared muted/primary-300 values.

`public/css/tala-foundation.css` owns the reusable material tokens. Filament's theme adapts native CSS hooks and shadow/ring variables; the public Bootstrap adapter uses the same foundation without replacing either framework's lifecycle. The frontmatter captures implemented shared primitives; the Blueprint records paired semantic/material roles. `.impeccable/design.json` extends these records with extracted shadows, motion and component specimens.

### October 9 owner direction: modern tactile — locally implemented; owner review pending

The owner prioritizes the system-wide shell and branding, with Admissions as the closest liked reference. Preserve school-first identity and the improved navigation. At that shell-only checkpoint, further #58 business logic and workflow work was deferred. The owner subsequently reopened Term Planning end to end under #48; the data-hierarchy rule above and current PRD 03/UI Blueprint extension govern that authorized local work. Claude's returned shell-branding handback recommended solid lit surfaces, raised actions, seated selection and inset fields. The owner subsequently removed its floating-menu glass proposal; menus and search results now use solid opaque overlays. Codex reconciled and implemented this direction; the review journal records the dispositions and evidence limits. Local implementation is neither owner visual acceptance nor Issue acceptance.

Use Filament's structure, Adwaita's restraint and paired appearances, and the existing skeuomorphic Aqua-like buttons family with Aqua/Aero as historical inspiration. Solid forms, tables, sidebar, notifications and modal content remain the readability baseline. Avoid decoration that competes with the task, including a new canvas wash or blurred modal scrim. The existing public masthead/progressive blur and finite recovery motion retain their own contracts.

The owner's subsequent feedback accepts the overall shell/colors and excellent light appearance, requests broader daily-use skeuomorphic Aqua-like buttons, richer dark surroundings and shared navigation reuse. This refinement preserves that direction. Codex owns local implementation and documentation. The owner canceled the additional Claude review and requested deletion of its pending handoff on October 9; no further Claude handback is required. Retain the earlier completed Claude reports as historical evidence. Positive feedback accepts the overall shell/colors and light appearance; subsequent sidebar/dark/shared-component refinements still await owner visual acceptance. The later owner-reopened Term Planning assignment supersedes this checkpoint's business-work deferral; local readiness still precedes owner hands-on review and explicitly permitted orchestrator reporting.

The October9 owner refinement removes the floating-menu glass treatment entirely. Native user/action menus and page-search results use the solid `--tala-overlay` fill in both appearances, with existing edge, elevation and focus cues. No translucent menu fill, backdrop blur, glass tokens, feature gate or developer glass override remains. Forms, filter/column panels, modals, notifications and sidebar stay solid. The existing public masthead/progressive blur has a separate decorative contract and is outside this floating-overlay change.

[NN/g's signifier study](https://www.nngroup.com/articles/flat-ui-less-attention-cause-uncertainty/) supports recognizable clickability, rather than a universal raised-button prescription. [Apple's material guidance](https://developer.apple.com/videos/play/wwdc2025/219/) supplies inspiration for separating controls/navigation from content, rather than a web implementation contract.

Use supported [Filament CSS hooks](https://filamentphp.com/docs/5.x/styling/css-hooks) and [Bootstrap component variables](https://getbootstrap.com/docs/5.3/customize/css-variables/). No dependency, business policy, PRD, architecture or official-output change accompanies this refinement. Screen-print stripping stays scoped to adapted components; official outputs use their independent templates.

## Shapes

### Shared operating components — October 11, 2026

**The Same Intent Rule.** Equivalent commands, selections, record inspections and record-management destinations share native components and interaction behavior across roles. Role authorization controls available work, rather than changing what an identically named control does. Registrar and Academic Head use the same Class details inspection dialog; a separate permission-bound Manage class link opens sustained editing, delivery-group and source-history work. Meeting details opens a bounded course/time/location-first inspection dialog. A dialog-opening meeting button carries no checkbox or pressed selection state.

**The Control Meaning Rule.** Native Filament actions and Bootstrap buttons consume the existing full skeuomorphic Aqua-like action material; task navigation, Week/Meeting list and pagination consume its shallower seated selection material. Primary commands use native green; destructive commands retain native danger meaning. Supporting blue identifies appropriate ordinary links and information. Text links stay quiet, with underline and visible keyboard focus; menu rows, sort/disclosure/notification-close controls and input-affix icons keep the native quiet whitelist. Heroicons use native sizing and currentColor rather than a second icon family. Dropdowns and dialogs are solid opaque overlays. Searchable choices show human names first with codes as supporting identity; searching and clearing scope preserve the exact Term and source version. Keep the Applicant-only identity/attribution exception from Issue #59.

**The Working Record Rule.** One compact selected scope and task heading precede the toolbar and records. Course title leads a class/meeting; time and location lead schedule inspection. Comparable working lists use native searchable, sortable, pageable tables with the same controls and page choices; changing scope resets the page. Fixed teaching-day summaries, timetable geometry, complete generation-confirmation scope and official print tables retain their task-specific structure. Align values, wrap names, use tabular numerals and offer clear empty/filter recovery. Status labels name source state; selection, validation and publication remain distinct. Missing facts remain unavailable. Official outputs preserve issuer, immutable source/version and full/subset scope.

**The Loading Rule.** Ordinary record and relationship content loads with its owning page. Remove decorative skeleton/deferred-placeholder surfaces for the current shared interface. Keep visible results, native busy/disabled feedback, accessible status and truthful progress for saving or asynchronous generation; never hide loading with a blanket CSS rule.

**The Resource Task Rule.** Quick Room inspection uses the same compact read-only dialog for Registrar and Academic Head; only authorized Registrar management opens editing. Capacity, type and equipment belong in one Room form/save. Equipment choices show understandable labels while preserving the exact existing scheduling keys, IDs and constraints; they imply no quantity that the model does not record. Faculty availability has one request area with the current reply deadline and pending responses: Request availability leads, Set/Change reply deadline is subordinate and clearly records only the due time. Weekly unavailable times names recurring exclusions; dated exceptions remain in Calendar. Term details show compact current facts and inspectable history. Activated identity remains protected; ongoing editing is named Faculty teaching-load default and appears in Faculty & rooms, with existing per-Faculty override precedence. Preserve originating Term/task/view through inspection, edit, cancellation and return.

**The Evidence Presentation Rule.** Keep the selected Term, actual official/draft status, responsible owner and next action visible with the working records. Place a clearly named source entry point beside the relevant fact where it helps the user judge credibility. Detailed evidence opens when the user inspects a record: use names such as Class details or Calendar source details, with source version, actor, date and history accessible in that inspection. Avoid an always-open empty or generic evidence rail. Preserve all authorized evidence and history, and expose existing permission-bound source destinations clearly. Use a bounded native read-only dialog or inline expansion for inspection; sustained source editing retains its full page. At narrow widths, inspection must preserve a usable working area; closing returns focus and the selected context. Academic Head retains the visible planning summary; Faculty retains the current answer and own official context; Registrar retains source state and the next action for blocked work. This owner-delegated October 11 presentation decision supports novice orientation and credibility; it selects none of the three composition options and changes no data, permission or product policy.

These rules extend the existing foundation and native adapters. The owner rejected the three standalone comparison variants; they are historical design evidence. Current direction refines the actual shared components and role tasks under these rules. Other domain journeys retain their owning delivery and acceptance records.

Supporting references: [UniTime Timetable](https://help.unitime.org/timetable) demonstrates resource-focused grids, course-title display and scope-bound print; [Class Timetable](https://help.unitime.org/class-timetable) demonstrates exact-session URL filtering. These presentation references establish no TALA role, scheduling, generation or publication rule. Local tests and mechanical checks remain technical evidence; whole-system visual acceptance remains open.

Start from the destination framework’s native corner, spacing, and component treatments. Extend them only where an observed task or approved visual direction warrants a restrained change. Use purposeful native variants for circles, status pills, and edge-to-edge mobile surfaces.

## Do's and Don'ts

### Do
- Lead visible school services and official documents with Servitech Institute Asia Inc.; keep “Powered by TALA” secondary outside the Applicant panel and its account-entry pages. Preserve official-output “Generated through TALA” attribution.
- Apply the October 2 school-first visual direction while refining terminology, workflow, hierarchy, and components where evidence warrants it.
- Preserve native Light/Dark/System behavior, readable contrast, visible focus, and meaning that does not depend on color alone.
- Choose a component for the user's task; use focused custom UI within Laravel/Filament when native presentation is inadequate.

### Don't
- Treat historical prototype layouts or today's production screens as automatically approved compositions.
- Force scheduling into a table-only view, or use a visual grid without an accessible equivalent.
- Add decorative density, redundant cards, or arbitrary new theme elements that slow ordinary tasks.

Owner-approved Faculty scheduling refinement (2026-10-10): the connected availability-input/official-schedule-output tasks share one Schedule & Availability sidebar destination and exact-Term My Schedule/My Availability sibling navigation. Use the shared compact/content-width centered navigation variant for a small task pair; preserve full multi-step navigation where it supports the task. Current declaration/action comes before one purposeful availability-history section; hide empty legacy-history paths and Registrar-owned capacity detail from this Faculty task surface. Use visible mutually exclusive teaching choices, current-value correction prefill and conditional recurring-unavailability fields. Schedule controls reuse native filter state and shared calendar/list grammar; active criteria remain visible while filter choices may be disclosed progressively. Keep official identity/version context concise, Examination Period informational, and affected changes discoverable. Faculty print separates the filtered subset from all own official meetings and avoids repeating header identity in every row. Assess location, available action, next step and return through actual navigation; source/test evidence alone does not grant visual acceptance.


### Connected Term Planning working grammar (October 11, 2026)

Within the approved Inter/crest/green branding and Aqua-like buttons, working data leads the first viewport. One compact selected-Term context precedes content-aligned native task navigation and the local work toolbar. Avoid repeating identity, explanatory introductions, status strips and universal attention bands. Place actual source-backed states adjacent to the task; separate institutionwide findings from selected-Term findings. Course title, time and location lead meeting tiles; details open a bounded native dialog. Compact native disclosures retain source/provenance without expanding above the timetable. Mobile preserves reading order and wraps toolbars; the captured timetable geometry and explicit filtered scope remain intact. Floating overlays stay solid and opaque. Schedule screen/print appearance may use this grammar while preserving issuer, attribution, immutable version identity and authority facts.


### October 11 connected role composition and working controls

One exact-Term scope region precedes native task navigation and actual records. Registrar keeps the five existing planning destinations; Academic Head reads selected-Term source rows and separate institution-wide curriculum evidence; Faculty uses the compact, content-width centered My Schedule/My Availability task pair. Role-specific permission, official/draft state, responsible owner and exact-Term ancestry remain explicit. This standard applies to the actual #58 participants and shared same-intent controls; unrelated workflows retain their owning delivery slices.

View selections use the shallow skeuomorphic Aqua-like family with a depressed current state; modifying commands retain green primary meaning. Print and other output utilities use gray. Reuse native inputs, filters, links, Heroicons, tables and modal behavior. Bounded record decisions and focused meeting inspection use centered dialogs; contextual creation/preparation uses existing slide-overs; sustained source/history review uses pages. Generation uses the native wider modal and a compact accessible Program/year/Classes/Meetings/Status scope table, retains all advisory empty rows and explains one draft consequence. Display filters never narrow generation.

Human course titles lead course choices, list rows, tiles and print; codes remain subordinate identifiers. Timetable display uses the 12-hour clock in Asia/Manila, while source times and minute-based geometry stay unchanged. A focusable bounded time canvas retains every captured hour, meeting, overlap and break, with sticky day/time references and a visible recorded range. No auto-scroll or hidden-hour policy is added. Narrow screens retain the equivalent meeting agenda and native details. The timetable label type uses the documented 14px step and compact tile corners use 6px.

Faculty context groups Term, official version/assigned meetings and informational examination dates with named source disclosure. Availability groups current answer, due date and correction action before one purposeful history. Required source/version/Registrar/as-of evidence, current criteria, subset/full print and own revision history stay reachable. Mobile search, filter and column controls share a compact row; all retain usable touch targets. Synthetic provenance is a short factual scope annotation; avoid review instructions beneath working records. Overlays remain solid and opaque, with approved Inter, crest, green, supporting blue/gold and existing Light/Dark/System behavior.

Local implementation and root render evidence remain separate from owner visual acceptance. Rejected standalone variants and dated 25/40 and 28 historical assessments remain historical. The initial Medium finish attempt stopped at its usage limit before findings. The subsequent fresh Light independent finish review returned exact disposition: ship for the captured incumbent extension: eight role captures, inspected dialogs/output and refreshed generation captures. Persistence pass; fidelity faithful; bounded ceiling reached; material fixes none. Browser journeys are root-provided evidence. This grants no new score, whole-app acceptance, runtime benchmark, measured contrast/zoom or10%-motion result. Owner visual acceptance remains OPEN. Owner visual acceptance remains open.

Generation scope stays in a bounded, focusable, named horizontal-scroll region inside the native decision dialog. Retain every scope row, including empty advisories, and expose offscreen columns through keyboard scrolling; count unique classes once across shared associations. Display criteria never narrow generation. Source times/minute geometry and role handlers remain unchanged.

### Faculty owner refinements - October 11, 2026

The owner supersedes the previous bounded ship disposition for the nine affected Faculty/shared controls; that review remains historical. Owner visual acceptance is OPEN. Root has recorded bounded desktop/mobile evidence in the existing review; final mobile history dialog is confirmed with complete own response and X/focus return. PRD03/05 rules remain unchanged; these are Blueprint, composition and implementation gaps.

Faculty scope starts with a readable native Term selector, then stacked local response/official status and factual source. Informational examination dates/source are smaller secondary schedule context; Availability performs no extra query to duplicate them. Native Filters have explicit width, at most two columns, a full-width Course and one column on mobile, searchable owned Course/Class/Room choices and neutral Reset. Human course titles are primary identity. Schedule search matches the title on the meeting-linked course specification, plus course/class/room codes, with identical actor/exact-Term official screen and output criteria.

Meeting inspection surfaces are opaque green members of the existing skeuomorphic Aqua-like family across focused/week/mobile/master views. Green conveys the meeting inspection affordance; text/source governs draft, validity and official meaning. Preserve captured full-hour windows, breaks, overlaps, dimensions and source payloads. The owner explicitly removes modal X from the quiet-close whitelist: ALL native Filament and Bootstrap modal X controls use utility Aqua-like depth/gloss, with native names, Escape, focus and cancellation. Notification dismissals and ordinary disclosures retain the existing quiet policy.

Availability leads with: Your availability helps the Registrar plan teaching assignments. Current request/due, actual answer, Submit my availability or Update my availability and next action remain discoverable. The approved consolidated navigation is Schedule & Availability; My Schedule/My Availability and Grade Rosters keep their destinations and search aliases. A selected Term with no official meetings links directly to My Availability without changing Term access. PRD03 requires one action-required email with a direct My Availability link. The Registrar sends the request through Faculty & rooms; creating a Term alone does not send it. Email names the selected Term/deadline, the two form questions and Submit my availability or Update my availability, retaining school issuer and the fact that it makes no assignment; no new in-app notification feed is introduced.

The native progressive form asks Can you teach this Term? then, for Yes, Any weekly times you cannot teach? with explicit Yes/No. Initial weekly answer is required; corrections prefill current intervals and start with a fresh required reason (2000-character limit). Weekly Yes seeds one empty Day/From/To row, inventing no facts, and requires at least one valid row; No and whole-Term No submit empty intervals. Toggling preserves interval drafts until Cancel where supported. One Asia/Manila cue, leading Add another unavailable time and native Delete keep the input compact. The weekly question is UI-only, with no persistence/service-policy change.

Owner follow-up places Availability history View details in the native final right-hand column, superseding the earlier mobile-first position; narrow screens retain native horizontal table scrolling by allowing this table's grid item to shrink with min-inline-size: 0. Own immutable history exposes a native read-only View details with exact Term, answer, version, recorded date, complete weekly periods and full correction reason or Initial response. Resolve actor and selected Term again at authorization/content time; reject colleague, tampered and cross-Term records and close old details on Term change. Current Term request source remains separate evidence; declarations store no one-to-one request association. Constraints remain distinct from own official output and never silently move meetings.
### Shared email presentation - October 11, 2026

Owner-authorized email branding uses one native Laravel Markdown theme/header for all current application HTML mailables, account notifications and framework/Filament authentication mail. Servitech school identity and full-color crest lead; TALA remains secondary in header/footer, including Applicant email. The Applicant screen-only exception does not apply to school-issued communications. Primary actions use #2f7d3b with white text; supporting links use #1e3a8a, the school name #256331, neutral canvas #f4f4f5, white content and muted ink #52525c. Inter is preferred with system fallbacks, without remote font loading. Existing error/success meanings remain source-defined.

Native table layout, responsive rules and automatic inline CSS are retained. The shared primary button follows the full skeuomorphic Aqua-like action family: an 8px rounded face, visible upper-lens gloss transition, inset top highlight, darker lower bevel, real 1px green rim and modest offset shadow. Padding replaces the former thick flat border so the finish spans the full face. Opaque gradient stops preserve readable white text; solid green remains the fallback when clients omit gradient/shadow effects. Essential content and action remain usable when clients omit those effects, images or Inter. No app stylesheet, JavaScript or client-specific layout dependency is introduced. System Health's former bare HTML self-test now uses the same Markdown layout while preserving subject, message and transport/trigger. No per-message theme override is present in current source. Plain-text alternatives retain readable native content rather than visual styling. This is shared presentation only; recipient rules, authentication, delivery, business copy and product policy remain under their existing authorities. The owner approved Faculty availability-request email content only; shared visual acceptance remains OPEN. Local renders/tests and root browser evidence are distinct from owner acceptance.
