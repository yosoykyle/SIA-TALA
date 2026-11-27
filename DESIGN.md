---
name: Servitech Institute Asia — TALA
description: School-first academic operations with pure neutral Zinc surfaces, Servitech green-led actions and links, warm gold accents, and institutional crimson destructive states (zero blue shades)
colors:
  primary: '#2f7d3b'
  primary-deep: '#256331'
  primary-dark-anchor: '#6acb7c'
  on-primary: '#ffffff'
  link: '#1f5f2c'
  dark-link: '#8de698'
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
  dark-chrome: '#1e2024'
  dark-surface: '#27282c'
  dark-surface-raised: '#303237'
  dark-sunken: '#1b1c20'
  dark-overlay: '#35373d'
  dark-ink: '#f5f6f7'
  dark-muted: '#c6c6ce'
  dark-boundary: '#a1a1aa'
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

<!-- impeccable:design-map -->
Name: Servitech Institute Asia — TALA
Colors: primary: primary #2f7d3b; neutral: canvas #f4f4f5; warning-accent: focus #a16207; danger: danger #b91c1c
Fonts: Inter Variable (body, label, caption, title, section)
Radius: compact 6px, control 8px, surface 12px
North Star: "Clear Institutional Operations"
Rules:
- The Control Meaning Rule. Primary commands and affirmative submissions use native green; Cancel or Close that exits without applying work uses neutral gray.
- Alternating neutral surface tiers and card surface rule. Outer cards—including filter/scope cards such as `x-record-scope-bar` and `x-record-collection-controls`—keep a continuous raised `--tala-surface` body with hairline dividers (`border-block-start: 1px solid var(--tala-edge)`).
- Canonical Profile Card (`<x-record-identity>`). The shared `<x-record-identity>` profile card style (initials/photo, name, institutional identity pill, subtle Servitech green surface accent, and learner journey art where appropriate) is the canonical profile card style across the system (reused across Student Hub Overview, Class Schedule, Users & Access modals/profiles, and Profile headers).
- Unified Raised-Card Status & Advisory Callout. Status and advisory callouts (`.tala-warning-banner`, `<x-servitech-task-state>`, etc.) use a clean raised Neutral Zinc `--tala-surface` card with a `1px solid var(--tala-edge)` hairline border, subtle top rim, and a compact tinted semantic icon badge/status pill—avoiding muddy full-card amber/green washes, nested cards, or thick colored side-borders (`> 1px`).
- Full-Width Segmented View Switchers & Native Buttons. Multi-view workspaces use full-width segmented view switchers (`<x-tala-navigation stretch>`) for 1-tap switching on mobile and desktop alongside consistent native Filament buttons (`<x-filament::button>`).
Don't:
- Treat historical prototype layouts or today's production screens as automatically approved compositions.
- Force scheduling into a table-only view, or use a visual grid without an accessible equivalent.
- Add decorative density, redundant cards, or arbitrary new theme elements that slow ordinary tasks.
Do:
- Lead visible school services and official documents with Servitech Institute Asia Inc.
- Apply the shared school-first visual direction while refining terminology, workflow, hierarchy and components where evidence warrants it.
- Preserve native Light/Dark/System behavior, readable contrast, visible focus, and meaning that does not depend on color alone.
- Choose a component for the user's task; use focused custom UI within Laravel/Filament when native presentation is inadequate.
Map: public/images/brand/servitech-crest-outlined-192.webp
Map: PRODUCT.md
Map: 00_Project_Documents/ui_surface_blueprint.md
Map: public/css/tala-filament.css
Map: public/css/tala-catalog.css
Map: public/css/tala-foundation.css
Map: .impeccable/design.json
Map: resources/css/filament/tala/theme.css
Full: .impeccable/design-full.md (read a section when you need its detail)
