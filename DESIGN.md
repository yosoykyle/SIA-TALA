<!-- SEED: established with the user before implementation; re-run $impeccable document once there's code to capture the actual tokens and components. -->
---
name: Servitech Institute Asia — TALA
description: School-first academic operations with neutral surfaces, green-led actions, and supporting TALA blue
---

# Design System: Servitech Institute Asia — TALA

## Overview

**Creative North Star: "Clear Institutional Operations"**

Use neutral light/dark surfaces, green-led primary actions, supporting TALA blue, Inter typography, and restrained tactile controls. Preserve institutional artwork, framework boundaries, and evidence-backed UX review. Servitech Institute Asia Inc. leads; TALA appears secondarily as “Powered by TALA” outside the Applicant panel.

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

Shared action buttons use a coherent modern Aqua-like material: an upper highlight, defined lower edge, restrained elevation and a depressed active state. Reuse foundation tokens across Filament and Bootstrap, retaining native semantic palettes and distinct primary, neutral, warning and danger roles. Apply this material to action buttons, icon action buttons, sidebar navigation and account buttons, native tabs and segmented navigation, dropdown menu items, theme switches and table record expand/collapse buttons. Keep an explicit selector whitelist. Ordinary inline links, sortable headers, wizard steps, selects, section toggles and reorder handles retain their native presentation. Preserve readable focus, loading/disabled meaning, native semantics and forced-colors behavior in both appearances. Shared Filament table indicators use the native delayed loading lifecycle; existing data and controls remain intact and no decorative delay is introduced.

## Typography

Shared shell and authentication attribution remains compact and secondary to school identity. Choose screen mark sizes by legibility, hierarchy and available space. Preserve approved artwork and official-output print constraints. Native MFA code digits remain centered, readable and clearly bounded without replacing input, validation, focus or paste behavior.

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

## Native composition

Adopt clean shell/content separation, aligned grouping, clear action hierarchy, and task-appropriate responsive components. Display authorized records and capabilities from the owning PRD.

Use Filament’s sidebar-only layout, native desktop collapse, mobile menu/drawer, and ordinary page scrolling, with supported theming and extension points. Preserve complete authorized navigation, account/role/theme controls, safe areas, focus, task data, and reduced-motion access. Verify this behavior within each owning delivery slice.

Choose native components and supported configuration first, then existing compatible extensions. Consider a version-compatible plugin only for a demonstrated gap and with dependency approval; use focused custom UI when the justified workflow still requires it. Framework capability guides implementation effort, while canonical product requirements determine school capabilities. Use Boost and official Filament/Bootstrap documentation for APIs and native behavior, with the official demo when rendered examples help.

The expanded sidebar has one compact school-identity block: the supplied crest and readable school name. Workspace or role context sits with navigation or the page context. Powered by TALA occupies a quiet sidebar footer, with a shell-footer placement when the sidebar is unavailable. The Applicant panel instead pairs the school name with the signed-in Applicant's name and shows neither a workspace label nor TALA attribution. The mobile identity area shows the school once while the native drawer owns navigation. Page headings lead with the task. Preserve approved artwork and readable school identity. All roles reuse the same school-first sidebar, framed account-menu trigger and responsive action defaults. The page finder supports staff and student destinations; the two-page Applicant workspace uses direct navigation. The expanded header balances the crest and two-line Servitech / Institute Asia name with the native collapse control at the trailing edge. The selected role names the workspace separately from school identity. Find a page appears inside the native search field below identity and searches authorized workspace destinations only. Shared alignment edges join identity, search, navigation and account controls; compact controls keep usable hit areas. The account trigger uses restrained offset elevation, a visible surface and focus/hover/pressed states in both themes and sidebar sizes. Native icon actions compact below the small breakpoint while keeping accessible names; text-only controls, Save/Submit progression and consequential confirmations retain labels. Native unsaved-change alerts protect Create/Edit forms and writable action modals across panels; existing domain-specific draft persistence remains authoritative. Role-specific content and authorization remain contextual. Applicant task navigation uses native breadcrumbs; draft progression uses Save and continue with secondary Save and exit and confirmed discard in the native action menu.

Counts represent useful authorized work. Prioritize status, responsible owner, remarks, and next action over oversized summaries. Essential feedback accompanies each affected workflow; additional motion polish follows verified functionality.

Surface briefs apply these shared visual foundations to their task-specific decisions.

## HTTP and session-recovery presentation

Use one centered reading path, a plain-language H1, a short explanation, one context-safe primary recovery action, secondary technical code, quiet school branding, and secondary Powered by TALA attribution. Keep necessary support and account-recovery actions reachable. The selected `experimental.html` specimen supplies supporting composition and animation evidence.

The selected background uses soft state-aware color blooms and a brief arrival behind readable content. Complete the decorative animation within five seconds of page arrival, then retain the settled background throughout that page visit. Respect reduced motion with an immediate static composition; omit decoration in forced colors and print. Suspend active decoration while hidden or offscreen and preserve the original completion deadline when the page returns. Recovery actions stay immediately available, content stays stationary, and focus remains visible. Verify contrast throughout the effect in both appearances and on narrow screens. This finite treatment follows [WCAG 2.2 Pause, Stop, Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html).

Reuse the existing shared standalone error presentation. Preserve actual HTTP responses, permission checks, authorized destinations, session/account protections, and support access. An unconfirmed submission/payment outcome requires checking recorded state before resubmission; a generic retry must not replay a mutation. Core recovery remains readable and operable without animation, JavaScript, Vite, Livewire, remote fonts, or the prototype project. Session expiry uses the actual response and a context-safe recovery destination.

## Elevation & Depth

Distinguish navigation, canvas, task surfaces, and overlays through spacing, surface contrast, and restrained boundaries. Allow subtle tactile control shading; reserve stronger elevation for floating layers. Keep navigation surfaces neutral.

## Shapes

Start from the destination framework’s native corner, spacing, and component treatments. Extend them only where an observed task or approved visual direction warrants a restrained change. Use purposeful native variants for circles, status pills, and edge-to-edge mobile surfaces.

## Do's and Don'ts

### Do
- Lead visible school services and official documents with Servitech Institute Asia Inc.; keep “Powered by TALA” secondary, and omit it from the Applicant panel.
- Apply the October 2 school-first visual direction while refining terminology, workflow, hierarchy, and components where evidence warrants it.
- Preserve native Light/Dark/System behavior, readable contrast, visible focus, and meaning that does not depend on color alone.
- Choose a component for the user's task; use focused custom UI within Laravel/Filament when native presentation is inadequate.

### Don't
- Treat historical prototype layouts or today's production screens as automatically approved compositions.
- Force scheduling into a table-only view, or use a visual grid without an accessible equivalent.
- Add decorative density, redundant cards, or arbitrary new theme elements that slow ordinary tasks.
