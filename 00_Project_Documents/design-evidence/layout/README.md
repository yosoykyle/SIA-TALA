# TALA UI implementation guide

A theme and composition reference for SIA-TALA: **Filament for authenticated screens, Bootstrap for public pages**. The goal is a compact, readable, branded system built with its existing frameworks. The current preview renders native components with fictional data; the earlier custom showcase remains an experimental library.

## What governs implementation

1. The destination project's **AGENTS.md, PRDs, UI Surface Blueprint, and architecture** govern tasks, fields, validation, permissions, records, and workflows. Check their current versions.
2. The **installed framework** governs page structure, responsive behavior, navigation, focus, controls, and action lifecycles. Prefer documented configuration and components.
3. **This guide** supplies branding, semantic color direction, typography, compact grouping, and restrained button lighting. Reconcile conflicts with existing visual authority explicitly.
4. **GNOME/Adwaita and relevant design skills** inform quality within those boundaries. They do not require recreating GTK widgets or replacing framework layouts.

Agents must utilize all four appropriately. A specimen communicates visual intent; it does not authorize school features or require copying its DOM, CSS, or JavaScript.

## Shell and behavior to follow

- **Filament:** the selected preview uses the native **sidebar-only shell**, configured with `topbar(false)`. Keep the native desktop collapse control, mobile drawer and menu trigger, content container, and normal page scrolling. The theme control lives in the supported sidebar-footer render hook. Use `sidebarCollapsibleOnDesktop()`, `sidebarWidth()`, and `maxContentWidth()` where appropriate. Preserve explicit collapse choices through navigation with native state handling and verify in the destination panel. This is the chosen reference arrangement; adopting it in SIA-TALA still follows the destination's approved UI authority.
- **Bootstrap:** public navbar, responsive collapse, grid, forms, and modal. Public pages share the branding but do not inherit the authenticated sidebar.
- **Compactness:** native compact Sections, responsive schema columns, table configuration, and spacing utilities first. Group related controls and separate tasks clearly. Retain readable labels and usable touch targets. Native spacing/radii are the starting point; Libadwaita's window radius is not a universal web-control rule.
- **Feedback:** retain native validation, loading, dialog dismissal, focus return, and notifications. Configure placement through supported APIs when required. Extra motion needs a task benefit and reduced-motion support.

Earlier hover expansion, coordinated header changes, sidebar resizing, scroll-driven collapse, mobile bottom navigation, and scroll-hiding bars are **experiments, not production requirements**. The new sample uses native navigation. Do not add custom mechanics merely to match the archived preview.

## Visual direction

Shared semantic roles update the earlier blue-primary/Solarized direction. Approximate logo samples are provenance, not official institutional brand specifications.

- **Primary:** crest-green direction. Bootstrap uses `#2f7d3b`/white in light mode and `#6acb7c`/`#17351c` in dark mode. Filament generates a complete native palette from `#2f7d3b`, selecting shades/foregrounds by component and theme. Cross-framework pixel identity is not required.
- **Information:** TALA blue, represented by `#0c53c1`; the earlier dark illustration adaptation is `#6eb4ff`.
- **Danger:** crest burgundy, represented by `#b4113f`. Keep destructive actions distinct.
- **Warnings/highlights:** native amber for warnings. Crest gold `#ebca90` and TALA star yellow approximately `#ffe271` support artwork/highlights. Status always needs a label or other non-color cue.
- **Surfaces:** neutral light and charcoal. Bootstrap uses `#fafafb`/`#222226`; Filament retains native Zinc surfaces/borders. Verify editable fields, text, selection, focus, and disabled states against their rendered backgrounds.
- **Typography:** local Inter in these samples, sentence case, clear hierarchy, readable wrapping, and numeric alignment. Preserve the destination's approved Outfit/Inter roles unless separately changed. Inter is not the customized Adwaita Sans font.
- **Branding:** original supplied crest/TALA colors, readable scale, balanced alignment. Avoid decorative logo plates and navbar gradients. Symbol-only crops must center the visible artwork. School branding and Powered by TALA attribution have separate roles.
- **Icons:** retain native Filament Heroicons/control icons. Archived Lucide examples do not mandate replacing native icons.

### Button finish

Apply restrained same-hue lighting to **filled buttons**: a small top highlight, shallow gradient, and soft shadow. Neutral controls receive quieter dark-mode shading. Preserve distinct outlined, link, navigation, selected, focus, loading, and disabled roles; do not turn every clickable element into a raised tile. Pressed controls can reduce depth; disabled controls stay flat.

This approved tactile influence is cosmetic styling, not a claim that modern Adwaita is skeuomorphic. The samples use the documented Filament `.fi-btn` hook and Bootstrap button classes/variables. Preserve native focus rings and behavior. Verify foreground contrast across the gradient and interaction states. Forced-color mode removes decorative shading. Share definitions rather than adding per-screen overrides.

## Adapt through native APIs

Start from the actual requirement, identify its native component, then apply the shared theme. Use Filament panel colors/fonts, custom themes, documented CSS hooks, and render hooks. Use Bootstrap Sass/CSS variables, color modes, utilities, and component APIs. Production styling belongs in the destination's existing theme pipeline; the isolated sample loads a small CSS extension through a render hook.

For a real capability gap, document the requirement and choose a supported extension. Evaluate plugins for installed-major compatibility, accessibility, maintenance, license, and configuration before adoption. The official Filament Compact Theme is an optional candidate, not an installed dependency. Preserve vendor views and core layouts.

For each adopted composition, identify its native component/configuration, any justified plugin/custom work, the product requirement, and verification. Authentication, authorization, persistence, upload security, retries, scheduling, and exports remain application responsibilities.

### Apply the design skills appropriately

Read applicable skills in SIA-TALA's `.agents/skills` and their relevant supporting references:

- **laravel-filament / Livewire:** installed-version APIs and native component lifecycles.
- **Better Layout / Better Typography:** grouping, density, hierarchy, alignment, wrapping, and responsive composition.
- **Better Colors / Better UI:** semantic palettes, measured contrast, optical alignment, and restrained surfaces.
- **Better Accessibility / Better Writing:** names, keyboard paths, focus, announcements, recovery, and precise action labels.
- **Better Interface:** coordinated review when useful and authorized.
- **Impeccable:** relevant adapt, clarify, distill, harden, polish, or audit guidance and its detector. Evaluate findings within the native-framework brief; address applicable findings and document intentional exceptions.

Use tools that produce useful evidence. Reading or naming a skill alone is not applying it. Avoid indiscriminate command runs or replacing the approved direction with generic defaults. Recheck skill/framework versions, inspect the rendered result, and state partial/unverified coverage.

## Why these examples

Foundations establish visual decisions once. Native components establish control behavior once. Selected school compositions show assembly without duplicating every product screen or rebuilding a framework library.

The **native sample** covers a responsive form, text/select/radio/checkbox/toggle controls, disabled state, validation, a searchable/sortable table, badges, record dialog, confirmation, and notifications. The **experimental library** retains student details, final-grade roster, requirements/evidence, enrollment proposal, curriculum progress, term account activity, wizard/readiness, calendar/timetable, import/export, viewers, and recovery states.

These compositions reflect previously reviewed PRDs 01–06 and the UI Blueprint; recheck current authority before adoption. General overview visuals do not introduce a global staff dashboard. Attendance and raw-score gradebooks remain outside the reviewed TALA scope. Illustrative fields, counts, limits, and simulated outcomes are not product contracts.

Draggable timetable correction and standalone branded status pages may warrant bounded custom work with accessible alternatives and supported integration points. Status background pulses are optional experiments. The archived Show branding switch controls both crest and attribution.

## Run locally

The isolated native preview reads existing installed packages, with its own bootstrap, configuration, cache, sessions, and views. It does not load SIA-TALA's `.env`, application providers, or database connections. It has no real users/login. This is a local reference, not a deployable application.

Installed versions checked on 2026-10-02: **Filament 5.6.7, Laravel 12.66.0, Livewire 4.3.1**. Public example: **Bootstrap 5.3.3**. Recheck when the package source changes.

From this directory, use separate terminals:

```powershell
python -m http.server 4173 --bind 127.0.0.1
```

```powershell
# Optional override for another compatible installed dependency source
$env:TALA_DEPENDENCY_ROOT = 'C:/C SCHOOL/1st_SEM_Resources/Fundamentals_of_Research/GROUP/ACTIVITIES/SIA-TALA'
./native/setup.ps1
Set-Location native
php -S 127.0.0.1:4174 -t public router.php
```

Open [native Filament components](http://127.0.0.1:4174/filament/components), [theme guidance](http://127.0.0.1:4174/filament/guidance), [Bootstrap public example](http://127.0.0.1:4174/public), [static entry](http://127.0.0.1:4173/), or [earlier experiments](http://127.0.0.1:4173/experimental.html#projects).

`native/setup.ps1` prepares runtime directories and copies framework assets from the dependency source. No package installation or production build is required for this local sample.

## Files and verification

`native/app/PreviewPanelProvider.php` owns native panel configuration; `Components.php` defines the schema/table/actions. Blade views and `native/public/assets/tala-theme.css` contain the presentation extension. `public-example.html` is mirrored to `index.html` and the native public view; public appearance lives in `assets/bootstrap-theme.css`. Keep mirrors synchronized.

`experimental.html`, `styles.css`, `app.js`, and the showcase/pattern/control/timetable scripts preserve earlier experiments. Historical screenshots and `design-qa.md` do not govern native implementation.

Verify adopted work in the destination: light/dark and narrow layouts, keyboard operation, focus return, validation, disabled/loading/empty states, contrast, zoom, and relevant workflows. Local Chromium checks do not certify every browser or screen reader. Current coverage is recorded in `native/verification.md`; owner visual acceptance is separate from technical checks.

## References

- **Filament visual examples:** [official demo](https://demo.filamentphp.com), [demo source](https://github.com/filamentphp/demo), [official theme previews](https://filamentphp.com/themes). Compare native navigation, composition, hierarchy, and density. Theme previews are references; no paid theme plugin is installed in this sample.

- **GNOME/Adwaita:** [HIG](https://developer.gnome.org/hig/), [UI styling](https://developer.gnome.org/hig/guidelines/ui-styling.html), [typography](https://developer.gnome.org/hig/guidelines/typography.html), [icon/illustration palette](https://developer.gnome.org/hig/reference/palette.html), [Libadwaita CSS variables](https://gnome.pages.gitlab.gnome.org/libadwaita/doc/1-latest/css-variables.html).
- **Filament 5:** [styling](https://filamentphp.com/docs/5.x/styling/overview), [CSS hooks](https://filamentphp.com/docs/5.x/styling/css-hooks), [colors](https://filamentphp.com/docs/5.x/styling/colors), [navigation](https://filamentphp.com/docs/5.x/navigation/overview), [schema layouts](https://filamentphp.com/docs/5.x/schemas/layouts), [Sections](https://filamentphp.com/docs/5.x/schemas/sections), [forms](https://filamentphp.com/docs/5.x/forms/overview), [custom-data tables](https://filamentphp.com/docs/5.x/tables/custom-data), [modal actions](https://filamentphp.com/docs/5.x/actions/modals), [notifications](https://filamentphp.com/docs/5.x/notifications/overview), [render hooks](https://filamentphp.com/docs/5.x/advanced/render-hooks), [Infolists](https://filamentphp.com/docs/5.x/infolists/overview), [widgets](https://filamentphp.com/docs/5.x/widgets/overview), [statistics](https://filamentphp.com/docs/5.x/widgets/stats-overview), [charts](https://filamentphp.com/docs/5.x/widgets/charts), [optional Compact Theme](https://filamentphp.com/plugins/filament-compact-theme).
- **Bootstrap 5.3:** [customization](https://getbootstrap.com/docs/5.3/customize/overview/), [variables](https://getbootstrap.com/docs/5.3/customize/css-variables/), [color modes](https://getbootstrap.com/docs/5.3/customize/color-modes/), [buttons](https://getbootstrap.com/docs/5.3/components/buttons/), [navbar](https://getbootstrap.com/docs/5.3/components/navbar/), [forms](https://getbootstrap.com/docs/5.3/forms/overview/), [modal](https://getbootstrap.com/docs/5.3/components/modal/).
- **Better skills:** [Jakub Krehel's collection](https://github.com/jakubkrehel/skills), [author's page](https://jakub.kr/skills). This is the collection called Better Design in discussion.
- **Impeccable:** [documentation](https://impeccable.style/docs/), [source](https://github.com/pbakaus/impeccable). Local skill version reviewed: 4.3.1.
- **Font/icons:** [Inter](https://rsms.me/inter/), [Inter source/license](https://github.com/rsms/inter), [Heroicons](https://heroicons.com/), [Lucide](https://lucide.dev/), [Lucide source](https://github.com/lucide-icons/lucide). Inter/Lucide licenses are bundled in `assets/`; Lucide belongs to the archived library.
- **Tactile influence:** [Jon Kantner's CSS technique](https://dev.to/jonkantner/how-to-create-simple-skeuomorphic-buttons-in-css-3gf4), [Josh Comeau's depth guide](https://www.joshwcomeau.com/animation/3d-button/). Inspiration; native state/accessibility rules govern adaptation. Skeuos was discussed, not adopted as a dependency.
- **Earlier comparison:** [Frappe student records](https://docs.frappe.io/education/student), [OpenEMIS attendance](https://support.openemis.org/core/en/student-attendance/). Comparison sources, not TALA scope.

Brand artwork came from SIA-TALA's `public/images/brand/servitech-crest.webp` and `public/talalogo.png`, bundled in `assets/`. Samples were estimated from those images; replace estimates with official approved brand specifications when available.
