<!-- SEED: established with the user before implementation; re-run $impeccable document once there's code to capture the actual tokens and components. -->
---
name: Servitech Institute Asia — TALA
description: School-first academic operations with neutral surfaces, green-led actions, and supporting TALA blue
---

# Design System: Servitech Institute Asia — TALA

## Overview

**Creative North Star: "Clear Institutional Operations"**

Use neutral light/dark surfaces, green-led primary actions, supporting TALA blue, Inter typography, and restrained tactile controls. Preserve institutional artwork, framework boundaries, and evidence-backed UX review. Servitech Institute Asia Inc. leads; TALA appears secondarily as “Powered by TALA.”

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

Restrained same-hue gradients and inset/outer shading may finish filled action buttons. Neutral buttons remain quieter and disabled controls remain flat. Navigation, links, fields, and status indicators keep distinct native treatments rather than becoming uniformly raised controls. This treatment must preserve legibility and forced-colors behavior. Screen tokens do not alter official-output or print contracts.

## Typography

Use Inter for headings, interface text, controls, tables, amounts, and identifiers, with system sans-serif fallbacks. Preserve the Blueprint’s type scale: no text below 12 pixels, mobile body and input text at least 16 pixels, and dense Staff table text normally at least 14 pixels. Use semantic heading hierarchy, readable wrapping, tabular figures, selectable text, and zoom/reflow. Error and printable outputs retain reliable system fallbacks.

## Layout

Public arrival, guided learner tasks, operational workbenches, and official outputs use compositions suited to their tasks within one visual identity. Put the current context and next meaningful action where the user can find them; progressively disclose secondary evidence and exceptional controls. Keep responsive reading order and accessible alternatives. A visual weekly timetable may be the primary scheduling view when that best supports review, paired with the equivalent filterable meeting table required by the UI Blueprint. Choose each native component for the information and action it supports.

Specific split ratios, navigation dimensions, and screen compositions belong to approved surface briefs and verified implementation, not this global seed.

### UI/UX assessment and component selection

Impeccable leads the assessment of an operating surface's purpose, hierarchy, cognitive load, copy, composition, and responsive transformation. Use the relevant writing, layout, accessibility, color, typography, and UI skills where they resolve a specific problem. Laravel Boost, Serena, and installed-version official documentation support code tracing and implementation choices; tool use alone is not design evidence.

For a demonstrated problem, compare the incumbent composition with a suitable native configuration before preserving it. Native Tables may use stacked mobile cells or supported Split/Stack layouts; Sections, Tabs, Infolists, Forms, Wizards, and Action Groups serve different jobs. Required information remains reachable, while secondary details can be progressively disclosed. A green theme on an unchanged crowded screen does not resolve a hierarchy problem.

Record the accepted task-specific direction in `.impeccable/surfaces/`, derived from the owning Issue and canonical UI contract. Inspect representative desktop/mobile states in one bounded pass, fix the demonstrated problems together, and confirm the result once. Evidence must show the user-visible improvement and retained behavior. Check tool capability before scheduling a verification method; use truthful task-applicable evidence for the outcome and never label a simulation as a direct browser observation.

## Native composition

Adopt clean shell/content separation, aligned grouping, clear action hierarchy, and task-appropriate responsive components. Display authorized records and capabilities from the owning PRD.

Use Filament’s sidebar-only layout, native desktop collapse, mobile menu/drawer, and ordinary page scrolling, with supported theming and extension points. Preserve complete authorized navigation, account/role/theme controls, safe areas, focus, task data, and reduced-motion access. Verify this behavior within each owning delivery slice.

Choose native components and supported configuration first, then existing compatible extensions. Consider a version-compatible plugin only for a demonstrated gap and with dependency approval; use focused custom UI when the justified workflow still requires it. Framework capability guides implementation effort, while canonical product requirements determine school capabilities. Use Boost and official Filament/Bootstrap documentation for APIs and native behavior, with the official demo when rendered examples help.

Servitech leads. Powered by TALA may occupy an appropriate header, sidebar footer, or shell footer. The supplied crest may use a non-distorting symbol crop on compact screens, accompanied by readable institutional identity; complete artwork remains preserved.

Counts represent useful authorized work. Prioritize status, responsible owner, remarks, and next action over oversized summaries. Essential feedback accompanies each affected workflow; additional motion polish follows verified functionality.

Surface briefs apply these shared visual foundations to their task-specific decisions.

## HTTP and session-recovery presentation

Use one centered reading path, a plain-language H1, a short explanation, one context-safe primary recovery action, secondary technical code, quiet school branding, and secondary Powered by TALA attribution. Keep necessary support and account-recovery actions reachable. The selected `experimental.html` specimen supplies supporting composition and animation evidence.

The selected background uses soft state-aware color blooms, a brief arrival, and a slow ambient pulse behind static readable content. Retain this bounded decorative exception rather than spreading animation across operational workbenches. Motion never signals retry, progress, provider recovery, or a record-state change. Animate only when reduced motion is not requested; provide a static fallback, remove decoration in forced colors/print, and pause while hidden or offscreen. Continuous movement lasting more than five seconds requires a visible keyboard-accessible pause/stop control. Decoration must not capture input, obscure focus, flash, move content, or delay recovery. Verify contrast throughout the effect in both appearances and on narrow screens.

Reuse the existing shared standalone error presentation instead of the prototype's simulated runtime. Preserve actual HTTP responses, permission checks, authorized destinations, session/account protections, and support access. An unconfirmed submission/payment outcome requires checking recorded state before resubmission; a generic retry must not replay a mutation. Core recovery remains readable and operable without animation, JavaScript, Vite, Livewire, remote fonts, or the prototype project. Session expiry is a recovery state, not a newly invented universal HTTP code. The specimen's branding/motion switches do not introduce a production settings subsystem; the required pause/stop control is local to the effect.

## Elevation & Depth

Distinguish navigation, canvas, task surfaces, and overlays through spacing, surface contrast, and restrained boundaries. Allow subtle tactile control shading; reserve stronger elevation for floating layers. Keep navigation surfaces neutral.

## Shapes

Start from the destination framework’s native corner, spacing, and component treatments. Extend them only where an observed task or approved visual direction warrants a restrained change. Use purposeful native variants for circles, status pills, and edge-to-edge mobile surfaces.

## Do's and Don'ts

### Do
- Lead visible school services and official documents with Servitech Institute Asia Inc.; keep “Powered by TALA” secondary.
- Apply the October 2 school-first visual direction while refining terminology, workflow, hierarchy, and components where evidence warrants it.
- Preserve native Light/Dark/System behavior, readable contrast, visible focus, and meaning that does not depend on color alone.
- Choose a component for the user's task; use focused custom UI within Laravel/Filament when native presentation is inadequate.

### Don't
- Treat historical prototype layouts or today's production screens as automatically approved compositions.
- Force scheduling into a table-only view, or use a visual grid without an accessible equivalent.
- Add decorative density, redundant cards, or arbitrary new theme elements that slow ordinary tasks.
