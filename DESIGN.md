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
  dark-canvas: '#151619'
  dark-chrome: '#202226'
  dark-surface: '#2b2d32'
  dark-sunken: '#1b1d21'
  dark-overlay: '#393c42'
  dark-ink: '#f5f6f7'
  dark-muted: '#c2c8cf'
  dark-boundary: '#a2acb9'
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
    fontSize: 'clamp(1.5rem, 1.2rem + 1vw, 1.875rem)'
    fontWeight: 700
    lineHeight: 1.25
  section:
    fontFamily: "'Inter Variable', Inter, system-ui, sans-serif"
    fontSize: '1.125rem'
    fontWeight: 600
    lineHeight: 1.3
rounded:
  compact: '6px'
  control: '8px'
  surface: '12px'
components:
  button-primary:
    backgroundColor: '{colors.primary-deep}'
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

Neutral light/dark surfaces, green-led actions, supporting TALA blue, Inter typography and restrained tactile controls form the shared visual identity. Page titles and primary task/record headings use 700; supporting section headings, meaningful data emphasis and actions use 600; body copy uses 400. Supporting labels may use 500. Program identity, the current task and the main subject/account record are primary groups; use size and spacing to keep their headings subordinate to the page title. Avoid competing 550/650 weights. Sidebar navigation uses restrained surfaces and a tinted selected destination; primary task controls retain the restrained Aqua finish and readable contrasting text. Preserve school artwork. Servitech leads; the Blueprint governs workspace/person identity and where system attribution appears.

Within one role workspace, global sidebar groups, module destinations and ordering stay stable when moving between modules. Use the same native parent/child pattern for module work (such as Admissions queues and Term Planning tasks); expand the active module's children without replacing the surrounding navigation. Compact task composition changes the working page, not the user's global map. The collapsed sidebar's collapse and search buttons share a centered 44px axis, with balanced space around their divider; inherited negative icon-button margins must not pull the search button toward that divider. A module with child destinations uses two aligned neutral surfaces: a separate parent link above one contained child list, with a narrow spacing gap and no curved bridge. The parent has stronger text and the shared detail elevation; the quieter child list uses the shared inset material. Keep the existing 8px parent and 12px group corners. Match their outer edges and keep parent/child icons and label starts on the same axes as surrounding module destinations. The child frame supplies the inset rather than adding a second indent. Show native child icons beside wrapping labels; collapse the same two-surface arrangement to an icon rail with matching outer edges. The current child keeps the shared green selection treatment within the group. Preserve native links, accessible names, tooltips, focus order, and touch targets; omit connector lines and decorative tree dots. Implement this once on Filament's shared child-navigation hooks for every panel rather than adding module-specific wrappers or vendor overrides.

[PRODUCT.md](PRODUCT.md) owns purpose. The [Blueprint](00_Project_Documents/ui_surface_blueprint.md) owns role/page flow, fields and interaction outcomes. Surface strategy belongs in the matching brief; live work and acceptance belong in GitHub.

Operational overviews use the shared heading weight for actionable queue figures, emphasis weight for task names, and regular body weight for explanation. Scale and grouping establish the hierarchy; a count remains attached to its named task and destination. Reusable financial form sections follow one reading sequence with compact field rows and a reachable action footer. Shared tokens own type, color, spacing and restrained Aqua control depth across role workspaces.

Use the shared `tala-task-surface` class when a task group needs separation from the canvas: a solid surface, fine edge, inset rim and restrained elevation from the paired theme tokens. Apply it to meaningful groups rather than every nested section. Financial totals lead their supporting breakdown; dated payments use an ordered schedule with prominent amounts and readable dates. Approval and retained source details stay secondary and reachable. Preserve forced-colors boundaries and remove screen elevation in print.

The October 23 shared dark refinement uses a charcoal canvas, separate sidebar chrome, graphite task surfaces and a lighter overlay tier. Keep brand-tinted foreground/background pairs distinct from standalone green text/icons; enabled neutral commands retain readable icons and a raised neutral fill while native disabled, danger, focus and loading meanings remain intact. Apply this through shared semantic tokens, with restrained green emphasis on current navigation, current handoff ownership and the existing enrollment-status surface. Student and Applicant identity gradients reuse these roles. Preserve layout, Light/Dark/System selection, forced colors, print and the approved light appearance. Adwaita's paired/standalone color roles and Apple's semantic base/elevated backgrounds inform this adaptation; neither system replaces the product's existing school identity or native Filament components.

Cross-role waiting states reuse the `workflow-handoff` component: an ordered **Now → Next** row with the current owner/task emphasized and the following owner/task readable. Keep its context and explanation in the same task group; stack the row on narrow screens. It conveys responsibility rather than completion, percentages or a new approval gate. The caller supplies source-backed role/task text and retains its existing actions and safeguards. Use the same semantic text and surface tokens across roles.

In the shared handoff, give the next step a quiet surface outline. When steps stack, center the downward connector in the gap between them; keep it outside the step boundary. The current step retains the stronger tinted treatment.

Place tinted handoff steps on a neutral parent surface so the current step stays distinct. In Registrar confirmation waits, group status and deadline first, the shared handoff second, and assisted confirmation in a separate action row below. Keep the explanation beside that action rather than interleaving it with the deadline.

In that waiting state, expose subject changes beside the subject list and explain the effect of saving before submission. Keep cancellation separate with its consequences and existing confirmation. Do not put these actions in a generic options menu or offer another class-place reservation while the displayed places are already valid. Placement recovery keeps its own contextual action. Use “class places” in plain-language guidance instead of assuming the meaning of “seats” is understood.

Supporting enrollment details remain a native disclosure. Put status and update time first, with labels above their values; place the long, selectable enrollment reference on a separate full-width row. Stack the facts on phones and avoid repeating an explanation of what the details are.

The shared visual direction uses neutral light/dark surfaces, green-led primary actions, supporting TALA blue, Inter typography, and native Heroicons. Product behavior, school-first branding, role ownership, accessibility, and official-output contracts remain governed by canonical authority. Historical prototype captures remain comparison evidence, not binding screen templates. Light-first references do not disable native Light/Dark/System behavior. The approved yellow TALA star artwork is the product mark; the word **TALA** is rendered as live text rather than a separate raster wordmark. The institution-supplied Servitech/SIA crest is the institution mark. Both marks remain full color on screen. File presence, a legacy screenshot, or an existing template cannot substitute for approval of the underlying artwork. The Blueprint governs role/output outcomes; these shared visual rules govern appearance.

## Colors

Use the paired semantic tokens below. Green carries primary action and selection emphasis; supporting blue carries appropriate links/information and secondary identity. Primary action, selected navigation, success status, and destructive action retain distinct component treatments and text-backed meaning.

Compose light and dark appearances through the existing theme mechanism rather than mechanically inverting colors. Verify actual rendered text, control boundaries, focus, gradients, and status pairs. Crest samples are adaptation references, not claimed official institutional color specifications.

The paired values guide semantic color adaptation. Filament generates its native primary palette from the approved green anchor `#2F7D3B` and retains native Zinc surfaces/borders; Bootstrap uses the paired light/dark anchors through its existing variables and color modes. Preserve each framework's accessible component/state palette. Keep school identity, meaning, hierarchy, and measured rendered contrast consistent across both frameworks.

Shared action controls use the framework's native semantic palettes, outlined variants and state behavior. Green identifies the current primary task; achieved success remains a status, neutral controls support navigation and routine secondary work, warning marks consequential interruption, and danger marks withdrawal, discard or cancellation. A healthy operating state need not invent a primary task. Restrained tactile shading may clarify a control without overriding its native color or outlined boundary. Consequential actions keep their visible labels on small screens; compact filters and utilities retain accessible names. Preserve native navigation/link semantics, focus, loading, disabled and forced-colors behavior. Screen styling preserves official-output and print contracts.

Cancellation color follows its effect across every workspace. An action that cancels an enrollment, import, cycle or approved change, withdraws an application, or discards a record uses the framework's `danger` palette; its confirming submit uses the same palette. A form's Cancel, Close, Back or Keep action that exits without applying the change uses neutral `gray`. Define the semantic color on the owning action and reuse it in templates; page-level overrides must not turn a destructive cancellation gray or green. Use explicit object labels where Cancel alone could mean either effect.


### Paired semantic anchors

| Semantic role | Light appearance | Dark appearance | Use |
|---|---|---|---|
| Primary action | `#2F7D3B` / `#FFFFFF` | `#6ACB7C` / `#17351C` | Primary action background / foreground |
| Accent text | `#15772E` | `#8DE698` | Standalone interactive accent |
| Supporting TALA blue | `#0C53C1` | `#6EB4FF` | Appropriate links/information and secondary identity |
| Selection | `#B8D4B8` / `#0E4A0B` | `#35533E` / `#8DE698` | Selected surface / foreground |
| Canvas | `#F4F4F5` | `#111114` | Application background |
| Secondary surface | `#ECECEE` | `#1B1B20` | Opaque navigation chrome |
| Task surface | `#FFFFFF` | `#242429` | Solid forms, tables, and task panels |
| Primary ink | `#18181B` | `#FAFAFA` | Headings and body text |
| Muted ink | `#52525C` | `#D4D4D8` | Secondary explanation and metadata |
| Structural divider | `#D4D4D8` | `#52525C` | Quiet separation |
| Inset field | `#FFFFFF` | `#1D1D20` | Editable-field background |
| Control boundary | `#71717B` | `#9F9FA9` | Shared boundary anchor; native validation/focus shades remain |
| Success | `#17451E` / `#DFEFDF` | `#A4E6B5` / `#274433` | Status foreground / background |
| Warning | `#765311` / `#F4E7C9` | `#F4D598` / `#4B3D26` | Status foreground / background |
| Danger | `#9C1437` / `#F6DCE3` | `#FFC0CC` / `#542D37` | Status foreground / background |
| Destructive action | `#B4113F` / `#FFFFFF` | `#B4113F` / `#FFFFFF` | Destructive background / foreground |
| Restrained gold | `#805B16` | `#EBCA90` | Supporting highlights, never a broad navigation stripe |

These are semantic adaptation anchors, not a complete framework color ramp or a certification of every rendered state. Information uses supporting-blue foreground on a suitable neutral surface. Status meanings remain source-derived and text-backed. Primary actions and success indicators must remain distinguishable by component treatment and labels.

Filament generates its native primary palette from `#2F7D3B` and retains native Zinc surfaces/borders, choosing shades and foregrounds for each component/state. Bootstrap uses the paired light/dark anchors through its existing variables and color modes. Do not force identical component pixels or replace every native shade with a single anchor; consistent identity and meaning, plus verified rendered contrast, govern cross-framework adaptation.

Preserve native Light/Dark/System behavior and stored preferences. Filament panels and Auth Designer default to System; the public Bootstrap gateway retains its existing system-theme following. Do not build a second theme engine or reset stored user choices. Normal text meets 4.5:1; large text and applicable non-text indicators meet 3:1. Verify actual backgrounds, opacity, gradients, hover/focus/disabled states, and forced colors. A custom focus perimeter is at least 2 pixels with sufficient offset and contrast against adjacent colors. Status combines label, icon, and semantic context. Print remains independent and monochrome-capable.

## Typography

Use Inter for headings, interface text, controls, tables, amounts, and identifiers, with system sans-serif fallbacks. Choose a readable type hierarchy for the task, density, device and zoom behavior; accessibility requirements govern the minimum usable result. Use semantic heading hierarchy, readable wrapping, tabular figures, selectable text, and zoom/reflow. Error and printable outputs retain reliable system fallbacks.

- Inter remains the interface family with system sans-serif fallbacks. Establish a readable task-led hierarchy through native framework typography, with weight and size chosen for each surface's content and use.
- Keep text, labels and entered values readable at supported mobile sizes and zoom. Use at least 16-pixel mobile input text where needed to prevent platform focus zoom; this does not prescribe the whole page's body size.
- Choose line height and prose measure for comfortable reading and distinguish headings, explanations and compact operational data. Wrapped labels and long values retain their meaning without overlap or clipping.
- Amounts, changing counts, identifiers, dates, and times use tabular numerals. Text remains selectable, and meaningful truncation always has an expanded or detail view.

## Layout

Public arrival, guided learner tasks, operational workbenches, and official outputs use compositions suited to their tasks within one visual identity. Put the current context and next meaningful action where the user can find them; progressively disclose secondary evidence and exceptional controls. Keep responsive reading order and accessible alternatives. A visual weekly timetable may be the primary scheduling view when that best supports review, paired with the equivalent filterable meeting table required by the UI Blueprint. Choose each native component for the information and action it supports.

Determine dimensions and component arrangements through task-led judgment and rendered evidence. Surface briefs record required outcomes and accepted decisions without prescribing visual recipes.
- Context selection must be understandable: show the exact record scope and any automatic-selection basis. Group routine context facts into one quiet visual unit; disclose long secondary names and explanations without hiding the selected identity or scope. A Change context command must permit an explicit choice even when a default exists. Keep one parent/detail breadcrumb hierarchy; preserve a distinct Cancel when it discards work or returns to the originating task.
- Group related facts and controls, separate distinct tasks, and preserve reading order as layouts reflow. Start from native framework spacing and component treatments, choosing task-led composition rather than universal dimension recipes.
- Keep content and actions clear of device safe areas and viewport edges. Mobile controls remain reachable without clipping or overlap, with accessible touch targets.
- Borders, surfaces and elevation communicate grouping, interaction and state. Preserve native component affordances and use stronger elevation for floating drawers, menus and dialogs.
- Public navigation and the fixed footer strip use the approved progressive-blur layers without a uniform tint overlay, hard bottom border, or abrupt color cut. Mobile footer content reserves the full blur height plus the device safe area so no link is obscured.
- Selected authenticated navigation uses filled surface, contrast, icon, and weight. It has no narrow yellow side strip.
- Routine state changes use no decorative entrance animation. Retain native interaction timing unless a bounded, verified adjustment is justified; high-frequency feedback stays immediate or restrained. Theme changes remain immediate. Every transition preserves static cues, interruption recovery, and reduced-motion access; never use `transition: all`.
- Native Heroicons Outline is the production interface icon family, matching the reference's visual weight through size and optical alignment. Authenticated workspaces use Filament's PHP `Heroicon` abstraction; the separately declared npm Heroicons package gains no independent responsibility. Active states use color/fill without requiring a separate asset. Lucide remains prototype-only; no new icon dependency is implied.
- Qualification frames are 390×844 and 360×800 for learner mobile, 768×1024 for intermediate review, and 1366×768 for dense Staff work.

## Elevation & Depth

The shared ladder is canvas → neutral chrome → solid content surface → floating overlay. Dark appearance separates these layers through lightness, rim/edge definition and soft shadow; increased shadow alone is insufficient. Equivalent task groups across Staff, Student and Applicant use `tala-task-surface` and one primary elevation per stack. Nested custom groups and explanations use `--tala-shadow-detail`; nested native sections/tables retain their quiet structural ring. Floating menus, dialogs and search results use `--tala-overlay` with `--tala-shadow-overlay`. Navigation chrome stays opaque and neutral. Action and selection materials are defined once under Actions, navigation and inspection below.

`public/css/tala-foundation.css` owns the reusable material tokens. Filament's theme adapts native CSS hooks and shadow/ring variables; the public Bootstrap adapter uses the same foundation without replacing either framework's lifecycle. The frontmatter captures implemented shared primitives; this document records paired semantic/material roles. `.impeccable/design.json` extends these records with extracted shadows, motion and component specimens.

The visual direction inherited from #48 combines Swiss/Bauhaus/utilitarian structure with restrained tactile materials. Structure and clarity resolve competing influences. Brutalist contrast/shapes serve selective emphasis; Memphis accents belong to suitable illustrations, empty states and public/landing content, never data tables or forms. Preserve the existing school identity, readable hierarchy and native Filament composition. Adwaita and Apple provide references for restraint, layering, controls and paired appearances.

The implemented overlay recipe is solid and opaque in both appearances. Liquid Glass supplies edge/layer inspiration; it does not require translucent reading surfaces or blurred modal scrims. Any future glass treatment must use the owning shared recipe, preserve contrast/solid fallbacks and remain economical on school PCs. The existing public masthead/progressive blur and finite recovery motion keep their separate contracts.

[NN/g's signifier study](https://www.nngroup.com/articles/flat-ui-less-attention-cause-uncertainty/) supports recognizable clickability, rather than a universal raised-button prescription. [Apple Materials](https://developer.apple.com/design/human-interface-guidelines/materials) guides functional layering and restraint; [Apple Buttons](https://developer.apple.com/design/human-interface-guidelines/buttons) guides primary-action emphasis and interaction states. [GNOME Typography](https://developer.gnome.org/hig/guidelines/typography.html) guides a small readable type hierarchy, while [GNOME Buttons](https://developer.gnome.org/hig/patterns/controls/buttons.html) guides recognizable, consistent controls. These are adapted to Filament's web components and the school task, rather than claimed native-platform conformance. Swiss/Bauhaus influence means deliberate grids, alignment and information hierarchy; bento grouping earns its place through distinct tasks. Brutalist influence supplies directness and strong contrast without overriding readability with thick outlines or hard shadows. [Poster House's Swiss design exhibition](https://posterhouse.org/wp-content/uploads/2019/10/PH_Exh_Swiss_Archive_interactive_Final.pdf) and [NN/g's neobrutalism guidance](https://www.nngroup.com/articles/neobrutalism/) supply visual context, not additional UI features.

Formal school outputs use an independent, monochrome-safe document composition: portable Arial body text, clear institution/document identity, compact tabular facts and source references after the useful record. Their screen-only Back and Print controls reuse the shared foundation color, gloss, depth and focus tokens. Formal document type sizes and grayscale rules are intentional print values; they do not replace Inter or the application type hierarchy. The browser print action removes all controls and uses the output's A4 orientation, margins and continuation rules.

Use supported [Filament CSS hooks](https://filamentphp.com/docs/5.x/styling/css-hooks) and [Bootstrap component variables](https://getbootstrap.com/docs/5.3/customize/css-variables/). Shared material changes preserve business policy and architecture. Screen-print stripping stays scoped to adapted components; official outputs use their independent templates.


### Material layers

`public/css/tala-foundation.css` owns shared values; this document owns their composition. These roles apply across both frameworks. Filament primary panels use `--tala-surface`; explicitly secondary panels and disabled input faces use `--tala-surface-sunken`. Enabled input faces use the shared surface; native focus, invalid, disabled and status semantics remain intact. All three authenticated panels load this mapping through `TalaPanelTheme`. These are the current shared layer values.

| Foundation role | Light | Dark | Purpose |
|---|---|---|---|
| `--tala-surface-sunken` | `#F4F4F5` | `#1B1D21` | Inset standalone tab track |
| `--tala-overlay` | `#FFFFFF` | `#393C42` | Solid modal, menu and search baseline |
| `--tala-chrome-divider` | `#D4D4D8` | white at 8% | Real navigation separator |
| `--tala-edge` / strong | black-Zinc at 9% / 14% | white at 16% / 22% | Decorative grouping rings; control boundaries remain stronger |
| `--tala-rim` | white at 85% | white at 13% | Restrained top edge |
| `--tala-control-depth` | 55% top highlight, 7% bottom bevel, 1px/4.5% outer shadow | 18% top highlight, 16% bottom bevel, 1px/14% outer shadow | Shallow tactile actions; do not recreate per page |
| `--tala-control-pressed` | 1px/2px inset at 14% | Same shared pressed cue | Perceptible press without a heavy lower shelf |
| `--tala-shadow-surface` | Soft three-part ambient shadow | Soft two-part ambient shadow at 25% / 30% | Main content groups, once per content stack |
| `--tala-shadow-detail` | Shallow 1px / 2px shadow at 3% | Shallow 1px / 2px shadow at 15% | Inner groups and explanatory backgrounds |
| `--tala-shadow-overlay` | Stronger three-part shadow | Stronger dark two-part shadow | Floating layers |
| `--tala-inset-well` | black-Zinc at 7% | black at 35% | Inset editable controls; native rings retained |

## Shapes

Start from the destination framework’s native corner, spacing, and component treatments. Extend them only where an observed task or approved visual direction warrants a restrained change. Use purposeful native variants for circles, status pills, and edge-to-edge mobile surfaces.

## Components

### Actions, navigation and inspection

TALA uses one material grammar across Filament and Bootstrap. Call its control family **skeuomorphic Aqua-like buttons**. Actions, eligible icon actions (including the collapsed-sidebar search shortcut), account/public-menu triggers and action-style links use the shared highlight, lower edge, modest elevation and depressed press state. Sidebar navigation, native tabs/task selectors, theme selection and pagination use the shallower seated-selection material. These priorities follow task structure rather than measured usage telemetry. Editable fields and navigation tracks use inset depth. Use the foundation's explicit selector whitelist; surface/overlay materials follow Elevation & Depth above. Preserve native semantic palettes, focus, loading/disabled meaning and forced-colors behavior. Check contrast across the brightest gloss stop and base fill. Busy indicators reflect real pending work; ordinary relationship tables and widgets follow the Loading Rule below.

**The Control Meaning Rule.** Primary commands and affirmative submissions use native green; Cancel or Close that exits without applying work uses neutral gray. A destructive cancellation uses native danger meaning because of its effect, rather than its label. Supporting blue identifies appropriate ordinary links and information. Text links stay quiet, with underline and visible keyboard focus; menu rows, sort/disclosure/notification-close controls and input-affix icons keep the native quiet whitelist. Heroicons use native sizing and currentColor rather than a second icon family. Searchable choices show human names first with codes as supporting identity; searching and clearing scope preserve the exact Term and source version. Preserve the Applicant identity exception from Issue #59; every authenticated role omits Powered by TALA attribution.

**Shared dialog placement and actions.** Preserve native Filament modal positioning, viewport backdrop, scrolling and dismissal. The shared Action configuration uses the supported `modalFooterActionsAlignment(Alignment::End)` API; custom Blade modals use its `footer-actions-alignment="end"` prop. Filament owns responsive footer layout and its native submit/extra/cancel order. Use the supported submit, cancel and extra-action APIs instead of rebuilding the footer or overriding its flex/grid direction. The visual result places neutral dismissal before the main action at the trailing edge. Destructive commands retain danger styling. Keep wizard and slide-over behavior native.

**Material recipes and edge ownership.** The foundation composes `--tala-material-raised`, `--tala-material-detail` and `--tala-material-overlay` from the paired rim and elevation tokens. Native Filament shadow variables and custom surfaces consume the same complete recipes, once per component. Inset wells use `--tala-inset-well`; selected controls use `--tala-material-selected`; buttons and navigation retain their shared control/seated recipes. Raised containers use the soft `--tala-edge`; overlays use `--tala-edge-strong`; editable fields retain `--tala-control-border` for their functional boundary. Avoid a native ring plus a second decorative border, or an outer raised shadow on an inset well. Native input wrappers use Filament's single ring for the field boundary; their focus, invalid and disabled rings retain native precedence. Neutral buttons use the common edge token, including Filament's default neutral buttons that have no color class. Course colors, status edges, separators, focus and forced-colors boundaries retain their distinct meanings. The common theme owns native adapters; feature styles own layout and semantic state, without inventing another highlight.

**Specular highlight adaptation.** The foundation owns `--tala-rim-inset`, `--tala-control-gloss`, `--tala-control-depth` and the shallower navigation recipe for both appearances. All eligible controls consume those recipes; page styles must not replace the gloss with a separate gradient. Native surfaces, dialogs and menus consume the same single upper-edge highlight in light and dark mode, with appearance-specific strength. Content and inset wells stay quiet; pressed and disabled controls keep their existing distinct states. Preserve semantic fills, focus rings, forced-colors stripping and opaque overlays. Filled danger buttons use the same upper edge and shallow depth with an opaque face: a white gloss over the native red reduces white-label contrast below 4.5:1, so the shared recipe omits that wash for this semantic role. This adapts [Apple Materials](https://developer.apple.com/design/human-interface-guidelines/materials) and [Dark Mode](https://developer.apple.com/design/human-interface-guidelines/dark-mode): restrained functional layering, semantic paired appearances and readable foreground contrast; it does not simulate reflective glass or add animation.

ALL native Filament and Bootstrap modal X controls use utility Aqua-like depth/gloss while retaining native names, Escape, focus and cancellation. This is the explicit modal-close exception to the quiet-control whitelist. Meeting inspection uses the opaque green Aqua-like family; source labels convey draft/valid/official meaning.

### Shared sidebar geometry

`resources/css/filament/tala/theme.css` owns sidebar layout for every panel configured through `TalaPanelTheme`: Staff, Student and Applicant. The desktop collapsed rail uses `--tala-sidebar-rail-size` for collapse/search targets, destination rows, group widths, separators and the account-control column. Center these on the same rail; native content-sized groups must not push navigation off that axis. Child destinations use meaningful native Heroicons and stay directly available in both sidebar states. Expanded rows show their icons and wrapping names; collapsed rows retain the same icons with native accessible labels/tooltips. The parent frame and child frame keep matching outer widths and one icon axis in both states. Fix missing icons in the navigation builder; never hide child destinations as a spacing workaround. Active parent/child entries consume the same paired `--tala-sidebar-current-bg` and `--tala-sidebar-current-ink` values. Keep native expand/search, keyboard, mobile drawer and permission behavior. Correct shared geometry here instead of adding module/role overrides.

### State and role consistency

Give outstanding work a text-backed visual cue while keeping routine reference destinations quiet and available. Explain scope once at the parent and retain concise identity on reached tasks. Working lists start with records relevant to that task; wider catalog access remains explicit, and counts name the scope they measure. Switching related tasks preserves explicit shared context without importing unrelated filters. These principles reduce repeated reading and selection; they introduce no universal completion score, compulsory sequence or permission change.

Apply the same control meanings, semantic tokens, hierarchy and native feedback across every affected role and record/component variant. Check loading, first use, empty content, partial data, success, warnings, errors, disabled controls, read-only access and denied access where each applies. A pleasing populated screen alone does not establish usable completion; attractiveness can hide navigation or recovery problems.

Distinguish an empty source from a search or filter with no matches. Offer clearing criteria before suggesting new records. Read-only users see the responsible office and a permitted inspection or contact path, rather than instructions to perform an unavailable edit. Missing or unmeasured facts remain distinct from zero or success. Reuse native table empty states, actions, validation, dialogs and shared semantic styles; avoid a second component family or custom permission/error layer. Shared fixes belong in their owning component or tokens and are checked against their affected consumers; task-specific layout remains appropriate to each role.

### Loading

**The Loading Rule.** Ordinary record and relationship content loads with its owning page. Remove decorative skeleton/deferred-placeholder surfaces for the current shared interface. Keep visible results, native busy/disabled feedback, accessible status and truthful progress for saving or asynchronous generation; never hide loading with a blanket CSS rule.

Long-running scheduling requests reuse `x-tala-operation-status`: a shared neutral panel, the native Filament activity indicator, a truthful queued/worker state and an accessible text status. Do not infer a completion percentage or solver stage that the service has not reported. Keep results visible during ordinary view updates; scope delayed loading feedback to the actual action, excluding background polling. Native indicator motion already respects reduced motion; its text remains visible. This follows [Apple Progress indicators](https://developer.apple.com/design/human-interface-guidelines/progress-indicators) and [GNOME Progress Bars](https://developer.gnome.org/hig/patterns/feedback/progress-bars.html), adapted to the existing web components.

### Recovery-background motion

The selected background uses soft state-aware color blooms and a brief arrival behind readable content. Complete the decorative animation within five seconds of page arrival, then retain the settled background throughout that page visit. Respect reduced motion with an immediate static composition; omit decoration in forced colors and print. Suspend active decoration while hidden or offscreen and preserve the original completion deadline when the page returns. Recovery actions stay immediately available, content stays stationary, and focus remains visible. Verify contrast throughout the effect in both appearances and on narrow screens. This finite treatment follows [WCAG 2.2 Pause, Stop, Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html).

Keep this decorative animation and omit the visible pause/stop button. The finite five-second recovery treatment is separate from continuing public hero media. The Blueprint owns HTTP meaning, recovery destinations, safe retry and fallback behavior.

### School-issued email

Owner-authorized email branding uses one native Laravel Markdown theme/header for all current application HTML mailables, account notifications and framework/Filament authentication mail. Servitech school identity and full-color crest lead; TALA remains secondary in header/footer, including Applicant email. The Applicant screen-only exception does not apply to school-issued communications. Primary actions use #2f7d3b with white text; supporting links use #1e3a8a, the school name #256331, neutral canvas #f4f4f5, white content and muted ink #52525c. Inter is preferred with system fallbacks, without remote font loading. Existing error/success meanings remain source-defined.

Native table layout, responsive rules and automatic inline CSS are retained. The shared primary button uses the full skeuomorphic Aqua-like family: 8px rounded face, visible upper-lens gloss, inset top highlight, darker lower bevel, real 1px green rim and modest offset shadow. Padding supplies the full face rather than a thick flat border. Opaque gradient stops keep white text readable; solid green is the fallback when effects are omitted. Essential content/actions remain usable without effects, images or Inter. No application stylesheet, JavaScript or client-specific layout dependency is introduced. System Health self-test uses the same Markdown layout without changing its subject, message or trigger. Current mailables use the common theme; plain-text alternatives remain readable. Recipient, authentication, delivery, business copy and policy stay with their owning authorities. Email-client fidelity and transport must not be inferred from local synthetic renders.


## Do's and Don'ts

### Do
- Lead visible school services and official documents with Servitech Institute Asia Inc.; omit “Powered by TALA” from all authenticated pages. Public and anonymous non-Applicant account-entry attribution remains secondary; Applicant account-entry pages continue to omit it. Preserve official-output “Generated through TALA” attribution.
- Apply the shared school-first visual direction while refining terminology, workflow, hierarchy and components where evidence warrants it.
- Preserve native Light/Dark/System behavior, readable contrast, visible focus, and meaning that does not depend on color alone.
- Choose a component for the user's task; use focused custom UI within Laravel/Filament when native presentation is inadequate.

### Don't
- Treat historical prototype layouts or today's production screens as automatically approved compositions.
- Force scheduling into a table-only view, or use a visual grid without an accessible equivalent.
- Add decorative density, redundant cards, or arbitrary new theme elements that slow ordinary tasks.

Supported implementation, extension/dependency boundaries and verification follow [Architecture](00_Project_Documents/architecture_specification.md) and the [Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md). Use Laravel Boost and installed-version official documentation; the Filament demo supplies native composition examples.



<a id="colors"></a>
<a id="connected-role-composition-and-working-controls"></a>
<a id="connected-term-planning-working-grammar"></a>
<a id="data-hierarchy-and-working-records"></a>
<a id="faculty-controls-and-response-history"></a>
<a id="http-and-session-recovery-presentation"></a>
<a id="native-composition"></a>
<a id="shared-email-presentation"></a>
<a id="shared-material-direction"></a>
<a id="shared-operating-components"></a>
<a id="uiux-assessment-and-component-selection"></a>
