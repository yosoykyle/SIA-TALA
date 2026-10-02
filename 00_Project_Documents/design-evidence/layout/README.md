# TALA UI guide

A visual and interaction reference for SIA-TALA, intended for implementation agents and developers. Use this guide to adapt the updated interface through **Bootstrap for public pages** and **Filament for authenticated screens**. It showcases essential components using fictional data.

## What must govern implementation

1. **Product behavior:** follow the destination project's AGENTS.md, applicable PRDs, permissions, validation rules, and architecture. Example content does not define school policy. And all authorative docs
2. **Visual direction:** follow this guide's current semantic colors, Inter typography, compact shell, component hierarchy, and responsive behavior. Older Solarized and blue-primary screenshots are historical.
3. **Framework behavior:** use supported native components, themes, configuration, CSS hooks, and extension points. Adapt the intended result rather than copying this prototype's DOM, JavaScript, or accumulated CSS overrides.
4. **Design quality:** appropriately utilize the installed design skills and their relevant references throughout implementation and verification. Select them by the actual problem; use their tools where they provide useful evidence. Reading or naming a skill alone does not constitute applying it.

When these sources conflict, explain the specific conflict and reconcile it before materially diverging. This prototype records owner-selected updated visual guidance; integration must explicitly reconcile older SIA-TALA visual documents. Preserve product workflows and framework responsibilities.

## Shell: layout and behavior to follow

The shell is part of the reference, including its interactions.

- **Desktop:** an aligned, compact navigation rail beside a flexible content panel, with 12px outer padding and 10px panel gaps to preserve room for content. Expanded navigation includes labels; collapsed navigation keeps recognizable icons. The crest remains visible. The compact logo has no expand action. The content panel is rounded consistently on all four corners.
- **Collapse control:** explicitly toggles the sidebar and coordinated header/search layout. Hover expansion must not prevent clicking this control.
- **Hover:** a collapsed sidebar expands after 500 ms of mouse hover. Temporary expansion collapses after the pointer leaves and focus no longer remains in the sidebar or search. Keyboard and touch paths remain available without hover.
- **Search:** clicking the compact search icon, or hovering it for 500 ms on desktop, widens the sidebar and reveals the inset search field. Leaving search focus collapses the compact desktop arrangement. Search filters specimens in the current section; table search remains scoped to records.
- **Resize:** desktop drag and keyboard resizing adjust the shared sidebar width. Expansion and collapse remain coordinated with the header. Respect available content width and clear interrupted drag states.
- **Desktop scrolling:** content scrolling can compact the shell; returning to the top restores the appropriate state. Explicitly selected or temporarily focused states take precedence as demonstrated by the prototype.
- **Mobile:** use the top header and bottom navigation. Hide both on downward scrolling and show them on slight upward scrolling. Restore navigation when its controls receive focus or the route changes. Preserve safe-area spacing and reachable actions.
- **Profile:** a compact framed group with the avatar centered when collapsed and text aligned with navigation labels when expanded.
- **Feedback:** toasts appear at the top right, include an accessible close button, and auto-dismiss routine success messages. Dialogs use protected focus, Escape dismissal, and return focus to their trigger.
- **Motion and access:** preserve visible state cues, readable labels, native keyboard behavior, reduced-motion handling, and mobile touch targets. Wide tables scroll internally; page content should reflow.

Use framework-native facilities for these intentions where practical. Explain material capability gaps and proposed adaptations. Public pages use a public landing/entry structure with the same visual language; their layout does not inherit the authenticated sidebar automatically.

## Styling system to follow

Use semantic roles consistently in both themes. Match the rendered prototype and current tokens in `styles.css`; sampled logo values are representative, not official institutional brand specifications.

- **Primary actions:** a lighter crest-green adaptation `#2f7d3b` with white text in light mode; a brighter green adaptation `#6acb7c` with deep-green text `#17351c` in dark mode. Standalone accent text uses Adwaita green `#15772e` in light mode and `#8de698` in dark mode.
- **Supporting visuals:** TALA blue `#0c53c1` in light mode and `#6eb4ff` in dark mode.
- **Selection:** `#b8d4b8` in light mode with deep-green foreground; `#35533e` in dark mode with green foreground `#8de698`.
- **Gold:** crest sample `#ebca90`; darker light-mode adaptation `#805b16`. Use sparingly for supporting highlights.
- **Destructive actions:** crest burgundy `#b4113f`; keep destructive and primary actions distinct. Status colors always have labels or icons.
- **Surfaces:** Adwaita-inspired neutral dark values `#222226`, `#2e2e32`, `#36363a`; light values `#fafafb`, `#ebebed`, and white.
- **Inset fields:** Adwaita view background `#1d1d20` in dark mode and white in light mode; control borders `#85858f` distinguish editable fields and neutral actions from their containers. Structural dividers remain quieter.
- **Typography:** bundled Inter; consistent page, section, record, body, and caption roles. Sentence case, readable line spacing, balanced headings, tabular numeric values, and 16px mobile inputs.
- **Crest treatment:** the compact header crops the supplied artwork to its crest symbol at a larger scale, excluding the tiny dark institutional wordmark. Keep its original colors on the normal neutral header surface; do not add white or gray plates, gradients, or recolor the whole image. The complete asset remains available in image-viewer examples.
- **Corner roles:** desktop shell panels and dialogs use 15px, referencing Libadwaita's window radius; cards use 12px and controls 8px as compact web adaptations. These are distinct roles, not a GNOME requirement to round every component identically. Keep circles and status pills; mobile edge-to-edge surfaces retain their responsive treatment.
- **Button finish:** primary, secondary, destructive, icon, close, tab, navigation, section shortcuts, menus, sorting, and viewer controls share restrained same-hue gradients and inset/outer shading in both themes. Use quieter, low-contrast shading on neutral controls and retain 12px between the expanded collapse control and its label. Preserve semantic foreground pairs; selected tabs appear pressed and disabled buttons are flat. Keep focus, reduced-motion behavior, and forced-color fallbacks. This owner-selected tactile finish adapts [Jon Kantner's technique](https://dev.to/jonkantner/how-to-create-simple-skeuomorphic-buttons-in-css-3gf4) and [Josh Comeau's depth guidance](https://www.joshwcomeau.com/animation/3d-button/); it is custom styling rather than a native Adwaita treatment.
- **Controls:** compact grouping, aligned edges, rounded controls, restrained structural borders, symbolic Lucide icons, clear keyboard focus, and immediate theme changes.

Follow Adwaita's paired background/foreground roles and separate standalone accent colors. Adapt brand colors for each theme instead of reusing the same logo sample everywhere. Dark actions must remain distinguishable from charcoal containers; verify text, control boundaries, and focus against their actual rendered backgrounds.

The crest comes from the supplied `public/images/brand/servitech-crest.webp`; TALA artwork comes from `public/talalogo.png`. Warm yellow, approximately `#ffe271`, remains in the star artwork. Assets are bundled under `assets/`.

## How agents must use the design skills

Use the local SIA-TALA `.agents/skills` collection adaptively, alongside GNOME guidance and the destination framework documentation:

- **better-interface:** coordinate a cross-discipline review when the task calls for one, consolidate findings, and state coverage honestly.
- **better-accessibility:** native semantics, names, keyboard operation, focus, announcements, touch targets, and reduced motion.
- **better-layout:** grouping, alignment, reading order, spacing, responsive reflow, and overflow.
- **better-writing:** action labels, required-field guidance, error recovery, empty states, and consistent terminology.
- **better-typography:** hierarchy, wrapping, line length, font weights, input sizing, and numeric alignment.
- **better-colors:** semantic roles and measured rendered contrast in both themes.
- **better-ui:** coherent surfaces, radii, icon weight, optical alignment, and restrained interaction polish.
- **Impeccable:** load project context and the playbooks relevant to the task, such as polish, adapt, harden, clarify, or audit. Use its detector when instructed, inspect findings in context, and verify the rendered result. Preserve this accepted visual direction during refinement.

Use relevant supporting references and tools, not only each skill's overview. Avoid running every command indiscriminately. Variant generation, new visual directions, and extra motion need a concrete benefit and appropriate scope. Keep checks bounded; distinguish measured results, intentional exceptions, and unverified coverage.

The local Impeccable skill inspected for this guide is version 4.3.1. Recheck local skill and framework versions before relying on version-specific instructions.

## Adapt through the existing frameworks

**Filament:** prefer native Tables, Forms, Schemas, Sections, Infolists, Tabs, Wizards, Actions, Notifications, and Widgets. Apply the design through panel colors/fonts, custom themes, documented CSS hooks, and focused extensions. Preserve native authorization, validation, accessibility, and plugin responsibilities. The previously reviewed lockfile contained Filament 5.6.7; verify the installed version during integration.

**Bootstrap:** retain the public-page foundation. Customize with supported Sass variables, CSS variables, color modes, utilities, and component APIs. Verify the bundled version and keep public/authenticated assets scoped.

Create a custom component only for a demonstrated capability gap. Consolidate prototype CSS into framework-appropriate tokens and styles during integration.

## Essential component coverage

- **Foundations:** color roles, typography, spacing, surfaces, and implementation guidance.
- **Components:** actions, forms/validation, searchable and sortable records tables, editable rows, dialogs, image/file selection and previews, feedback, tabs, badges, chips, avatars, menus, and accordions.
- **School patterns:** overview statistics and labelled bar charts; student record with related-record/history tabs; final-grade roster with INC notes and linked validation summary; document requirement and evidence states; enrollment proposal with totals and consequence preview; curriculum progress; term account activity; curriculum import preview, scheduling failures, and contextual export states; application wizard, readiness, calendar/timetable, and sign-in/verification.
- **Shared recovery:** sample workspace switching, inaccessible and session-expired states, and labelled mobile record cards.

Foundations define shared visual rules; components define reusable controls; composed patterns show how controls assemble into a task. This avoids repeating every screen while giving agents concrete examples. Each destination screen still needs an explicit contract for its fields, permissions, authoritative sources, and workflow consequences.

Overview visuals are general reusable specimens. Current TALA authority excludes a global Staff dashboard, attendance tracking, and a raw-score gradebook; these examples do not introduce them. The private school-evidence specimen reflects the Blueprint's PDF/JPEG/PNG, 10 MiB-per-version guidance; the general image-viewer demo remains a separate PNG/JPEG/WebP, 5 MB local preview. Simulated evidence/import/export actions do not send files, save records, or produce downloads.

These cover recurring needs identified in the reviewed PRDs 01-06 and UI Surface Blueprint. They give agents enough reusable examples to assemble essential screens consistently. This is a selected reference rather than an exhaustive replacement for Bootstrap or Filament. Add examples when a real requirement reveals a gap; document normal, empty, loading, error, disabled, responsive, and keyboard states. The shortened wizard and sample fields do not replace real application requirements.

## Official references must be referenced too

- [GNOME Human Interface Guidelines](https://developer.gnome.org/hig/), [UI styling](https://developer.gnome.org/hig/guidelines/ui-styling.html), and [typography](https://developer.gnome.org/hig/guidelines/typography.html). [GNOME palette](https://developer.gnome.org/hig/reference/palette.html) is a reference for icons and illustrations, rather than the source of arbitrary widget backgrounds.
- [Libadwaita CSS variables](https://gnome.pages.gitlab.gnome.org/libadwaita/doc/1-latest/css-variables.html): foreground/background and accent roles. This is a web adaptation, not an exact GTK widget port.
- [Jakub Krehel's Better interface skills](https://github.com/jakubkrehel/skills) and [author's page](https://jakub.kr/skills): the collection referred to as Better Design in discussion.
- [Impeccable documentation](https://impeccable.style/docs/) and [official source](https://github.com/pbakaus/impeccable).
- [Filament styling](https://filamentphp.com/docs/5.x/styling/overview), [CSS hooks](https://filamentphp.com/docs/5.x/styling/css-hooks), and [colors](https://filamentphp.com/docs/5.x/styling/colors).
- [Bootstrap customization](https://getbootstrap.com/docs/5.3/customize/overview/), [CSS variables](https://getbootstrap.com/docs/5.3/customize/css-variables/), and [color modes](https://getbootstrap.com/docs/5.3/customize/color-modes/).
- [Inter](https://rsms.me/inter/) and [source](https://github.com/rsms/inter): bundled font, with license in `assets/Inter-LICENSE.txt`. Inter follows the agreed typography direction; it is not the customized Adwaita Sans font.
- [Lucide documentation](https://lucide.dev/) and [source](https://github.com/lucide-icons/lucide): bundled symbolic icons; these are not the native Adwaita icon assets.

Additional composition references: [Filament widgets](https://filamentphp.com/docs/5.x/widgets/overview), [stats](https://filamentphp.com/docs/5.x/widgets/stats-overview), [charts](https://filamentphp.com/docs/5.x/widgets/charts), and [Infolists](https://filamentphp.com/docs/5.x/infolists/overview). [Frappe student records](https://docs.frappe.io/education/student) and [OpenEMIS attendance](https://support.openemis.org/core/en/student-attendance/) informed the general-system comparison; their broader workflows are not TALA requirements.

## Run and inspect

From this directory:

```powershell
python -m http.server 4173 --bind 127.0.0.1
```

Open [the showcase](http://127.0.0.1:4173/#overview). No build step is required. `py` may replace `python` on Windows.

`index.html` defines the shell; `styles.css` holds visual rules; `app.js` handles shell behavior and preferences; `showcase.js` defines base specimens and demo interactions; `school-patterns.js` defines composed school examples; `assets/` contains logos, font, license, and icons. PNG captures and `design-qa.md` are historical evidence, not styling authority.

## Boundaries and verification

The profile name, appearance, spacing, and sidebar preferences are browser-local under `workspace-layout-v2`. Demo data is fictional and temporary. Selected images remain local. This guide performs no school authentication, payment, email, or real-record mutation; it does not change SIA-TALA application code.

Verify adopted components through the destination framework: light/dark contrast, keyboard access, focus recovery, responsive behavior, zoom, screen readers, touch gestures, validation, and relevant workflows. Prior prototype checks cover representative Chromium layouts and selected interactions; they do not certify every browser, device, or accessibility requirement. Production authorization, persistence, upload validation, and integrations remain the application's responsibility.

Use pill switches for immediate on/off settings, checkboxes for selection or confirmation, and radio groups for one mutually exclusive choice. Preserve native keyboard semantics, visible focus, labeled states, and disabled states when adapting these controls through Bootstrap or Filament.

Supporting controls include mixed selection, range adjustment, segmented choices, and list reordering. Reordering always offers keyboard and touch-friendly move actions alongside dragging, announces the resulting order, and stays a fictional presentation example. Shared styles govern every occurrence; framework implementations should reuse their native components and theme hooks.

Selection and input tools cover filtered selection with clear/loading/empty states, multiple choices with removable chips, currency and password actions, bounded number adjustment, and explicit field states. Records tables include page numbers, row counts, and page-size selection. `supporting-controls.js` contains these shared examples; keep the native input semantics and framework-native customization approach.

The weekly timetable supports dragging fictional meetings into available slots and equivalent day/time move controls in a dialog. Occupied slots block moves with recovery guidance. `timetable.js` owns this demonstration; moving an example never runs scheduling or changes a published timetable.

Timetable meeting blocks show the day, start/end times with AM/PM, room, and duration. The fictional examples are one hour long; actual implementations must derive duration from each meeting and preserve it during manual placement corrections.

Meeting widgets adapt to their own container width: course and duration form the identity row, the time range leads the content, and day/room details wrap below. Preserve that hierarchy and compact grouping rather than stretching a plain text block.

Mobile navigation uses one compact rounded shell with equal touch targets and a green pressed selection. Avoid stacking circular raised buttons inside a pill-shaped frame. Mobile content maintains balanced inline gutters; its scrollbar must not reserve extra space on only one side.

Mobile search expands within the existing header row, keeping header height and profile placement stable. A full-width backdrop fades from the shell background at the bottom to transparent above the floating navigation. The backdrop disappears with the navigation during scrolling and never intercepts input. Use the desktop shell’s 180 ms ease timing for mobile header, navigation, and backdrop transitions. Respect reduced-motion preferences.

Status pages share one reusable composition under Components: 404, 403, 500, 503, and session expiry. Keep usable application navigation for local failures; use a minimal branded shell when the app shell cannot load. Present a clear explanation and relevant recovery action, with codes as supporting detail. Session expiry is an application state rather than a universal HTTP code. Preview actions only describe recovery; Bootstrap or Filament integration must supply real responses, permissions, destinations, retry logic, and authentication.

Status-page backgrounds use restrained color blooms from shared tokens: green branding with supporting blue, burgundy for unexpected failure, and gold for temporary service interruption. Text and symbolic icons remain the primary state indicators. Keep blooms behind content, visible in light mode while maintaining readable contrast, and omit them in forced-color mode.

Status backgrounds remain stationary. An Animate attention pulse switch controls an initial brightness cue followed by gentle reminder pulses. Pause it when offscreen or the document is hidden; show a static background for reduced-motion preferences. Do not animate blur strength or content layout.

On status-preview entry or a state change, begin at full pulse brightness and settle over 0.9 seconds. Then repeat a slower six-second pulse that rises only to half of the initial overlay strength. Keep the background stationary and the message readable; the pulse never delays recovery actions. The pause switch, visibility handling, and reduced-motion preference govern the complete sequence.

The recovery-page composition places the crest in the header, the status code as supporting context, a responsive message and recovery action, and the TALA logo with Powered by TALA attribution below. Use the supplied assets and shared crest crop. Avoid oversized generic icon tiles. Light-mode blooms retain visible color rather than washing out into white.

Wide recovery previews use a single centered reading path: crest, message, recovery action, small status code, and TALA attribution. Bound the message width so it reads as one group. Narrow previews keep the approved compact header and centered message. Choose adaptation by available component width, not device name.

The status code supports the explanation rather than competing with the heading. Desktop branding stays modest; preserve compact branding on narrow previews.

The status preview’s Show branding switch displays or hides both the crest and Powered by TALA attribution. Branding starts enabled; this control demonstrates branding visibility rather than changing the surrounding application shell.
