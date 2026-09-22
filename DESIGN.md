<!-- SEED: established with the user before implementation; re-run $impeccable document once there's code to capture the actual tokens and components. -->
---
name: Servitech Institute Asia — TALA
description: Authoritative Philippine collegiate registry and academic lifecycle operations engine
---

# Design System: Servitech Institute Asia — TALA

## Overview

**Creative North Star: "The Archival Collegiate Registry"**

The visual world of TALA is rooted in the dignified, authoritative tradition of Philippine collegiate administration. Rather than adopting the ephemeral, hollow aesthetic of modern venture-backed SaaS dashboards—with their floating pastel cards, oversized pill buttons, and detached metric widgets—TALA feels like a living institutional record. It marries the gravitas of linen ledger books, dry-embossed seals, and hairline-ruled grade sheets with the speed, precision, and mathematical guarantees of a 21st-century operations engine.

Every surface is designed for high-stakes operational clarity. Administrative staff process heavy volumes of student records, class schedules, and financial transactions under strict regulatory standards (CHED/TESDA). The interface communicates institutional permanence, scrupulous record-keeping, and zero ambiguity. When an action is blocked, the interface does not show a vague error; it renders a precise, explainable ledger note identifying the owner and required resolution.

**Key Characteristics:**
- Institutional primacy: Servitech Institute Asia Inc. leads with dignified academic typography; system branding is secondary.
- Tabular discipline: Dense, hairline-ruled data layouts organized like official registry sheets, maximizing information density without visual chaos.
- Tactile institutional palette: Deep archival navy, warm parchment backgrounds, seal amber for urgent states, and verification green for certified records.
- Focused candidate inspection: Dedicated candidate review surfaces (`REG-T04`) and published timetable views (`REG-T06`) providing full constraint validation before publication.
- Structural hierarchy brackets: Visual connectors linking academic years, terms, offerings, and section rosters.

## Colors

### Established Workshop Palette (Candidate #6)
The following core colors were established during the Candidate #6 selection:
- **Archival Navy** (`#0f2b48`): The authoritative anchor of the institution. Used for institutional shell headers, primary navigation, major titles, and primary commitment actions.
- **Warm Parchment Ground** (`#fdfcf7`): Main application page background providing warm, glare-free, paper-like contrast for administrative staff.
- **Institutional Slate Blue** (`#1e4976`): Active tabs, selected table rows, contextual links, and secondary interactive borders.
- **Collegiate Seal Amber** (`#b45309`): Used for deadline warnings, expiring seat reservations, incomplete grade countdowns, and pending review flags.
- **Verification Green** (`#15803d`): Used for officially enrolled certifications, validated payment transactions, passing marks, and verified solver feasibility proofs.

### Provisional Exploration Placeholders
The following secondary tones and neutrals are nonbinding exploration placeholders, to be formalized during component tokenization:
- *Restriction Crimson* (`#991b1b` [provisional]): Reserved for active scoped administrative holds, timetable collisions, and blocking validation failures.
- *Paper White* (`#ffffff` [provisional]): Card, ledger table, and modal background providing crisp contrast against parchment.
- *Archival Ink Charcoal* (`#111827` [provisional]): High-contrast body text and table entries.
- *Slate Gray* (`#4b5563` [provisional]): Secondary captions, field hints, and timestamps.
- *Hairline Rule Gray* (`#d1d5db` [provisional]): Table row separators, panel boundaries, and ledger grid lines.

### Named Rules
**The Institutional Primacy Rule.** The Servitech seal and institutional identity dominate all top-level surfaces. The secondary system mark ("TALA") remains visually subordinate to the institution's title and authoritative headings (e.g., through smaller scale, secondary positioning, or muted prominence).

**The Semantic Color Discipline Rule.** Restriction Crimson is never used for mere decorative accents or general negative values; it indicates an active, enforceable administrative hold (`blocking_level`) or hard scheduling collision.

## Typography

Canonical typography is governed by the UI Surface Blueprint (`00_Project_Documents/ui_surface_blueprint.md`):
- **Display / Heading Font:** Outfit (weights 600–700), with system sans-serif fallback.
- **Body / Interface / Table Font:** Inter (weights 400, 500, 600), with system sans-serif fallback.
- **Label / Tabular / Identifier Font:** Inter with `tabular-nums` / system monospace fallback (`SFMono-Regular, Menlo, monospace`) for identifiers (`SIA-YYYY-NNNN`), course codes, and technical logs.

*(Note: Traditional collegiate serif letterforms discussed in earlier concepts remain nonbinding historical inspiration; web interface rendering canonically adheres to Outfit and Inter).*

### Hierarchy (Seed Specifications)
- **Display** (Outfit Bold 700, clamp(1.75rem, 3vw, 2.25rem), 1.2): Main institutional titles, official document headers (COR/TOR), and major screen headings.
- **Headline** (Outfit SemiBold 600, 1.25rem (20px), 1.3): Section headers, workbench panel titles, and dialog titles.
- **Title** (Inter Medium 500, 1.0rem (16px), 1.4): Table column groupings, card headers, and student names in rosters.
- **Body** (Inter Regular 400, 0.875rem (14px), 1.5): Standard table records, form inputs, audit explanations, and descriptions.
- **Label / Tabular** (Inter Medium 500, 0.75rem (12px), 1.2, uppercase, tracking-wider): Status badges, table headers, LRN identifiers, course codes, and timestamp labels.
- **Code / Telemetry** (System Monospace Regular 400, 0.75rem (12px), 1.4): CP-SAT solver constraint logs, student IDs (`SIA-YYYY-NNNN`), and transaction reference numbers.

### Named Rules
**The Strict Tabular Alignment Rule.** All numerical values, units, grades, and currency amounts must use tabular lining figures (`font-variant-numeric: tabular-nums`) and right-align within table cells.

## Layout & Spatial Organization

The spatial model prioritizes administrative efficiency, rapid scanability, and dense operational coordination across desktop monitors (1280px–1920px).

*(Note: Specific pixel widths and split ratios below are nonbinding exploratory placeholders; final responsive breakpoints and spacing derive from the UI Surface Blueprint and implementation tokens).*

- **Grid Model:** Fixed-fluid desktop workbench layout. Institutional navigation rail (exploratory ~260px), sticky institutional top bar, and a responsive multi-column workbench canvas.
- **Dual-Pane Action Topology (Exploration Note):** For complex workflows (e.g., Enrollment Recovery, Timetable Conflict Resolution), candidate layouts may explore an asymmetric split (e.g. ~40% queue list paired with ~60% inspection dossier) to minimize navigation friction where supported by screen real estate.
- **Structural Bracketing:** Hierarchical data (Academic Year → Term → Program → Year Level → Section) is visually joined by continuous hairline bracket lines (`border-l-2 border-slate-300`) rather than nested floating cards.
- **Candidate Inspection vs. Published Timetable Surfaces:**
  - `REG-T04` (Generate & Review): Dedicated candidate inspection and solver result review (Optimal, Feasible, Infeasible, Unknown, ModelInvalid, TechnicalFailure), validating zero collisions and whole-term feasibility prior to publication.
  - `REG-T06` (Published Timetable): Dedicated view for the authoritative published timetable, landscape print views (A4), and controlled revision history.
  - *Telemetry Drawer Concept:* Exploratory concept for streaming solver diagnostics; runtime integration adheres to native Filament/Livewire panels and modals as specified in the UI Surface Blueprint.

## Elevation & Depth

TALA strictly avoids generic floating drop shadows and blur backdrops that degrade readability.

- **Flat-By-Default Invariant:** Surfaces sit flat on the parchment ground. Visual separation is achieved through crisp hairline rules (`1px solid #d1d5db`) and alternating row tones.
- **Layered Sheet Elevation (Exploration Placeholder):** Elevated sheets (modals, active popovers) use a single subtle, crisp administrative shadow: `box-shadow: 0 4px 12px -2px rgba(15, 43, 72, 0.12), 0 2px 4px -1px rgba(15, 43, 72, 0.06)`.
- **Active Focus Outline:** Interactive elements gain a high-visibility, accessible 2px solid institutional blue focus ring with 2px offset (`ring-2 ring-[#1e4976] ring-offset-2`).

## Shapes

- **Form Language:** Clean, rectilinear geometry evoking printed cards, certificates, and bound ledger volumes.
- **Corner Radii (Exploration Placeholders):**
  - Standard buttons and inputs: Subtly rounded `4px` (`rounded`).
  - Record cards, tables, and modal frames: `6px` (`rounded-md`).
  - Oversized pill shapes (`rounded-full`) are strictly prohibited except for small, compact status indicators (chips).
- **Borders:** Consistent `1px solid` rules in light slate (`#d1d5db` [provisional]) framing tables, headers, and inputs.

## Do's and Don'ts

### Do:
- **Do** lead every view with "Servitech Institute Asia Inc." before any TALA system text.
- **Do** use tabular lining figures (`tabular-nums`) for student IDs, units, fees, dates, and grades.
- **Do** display exact explanatory context (owner, missing requirement, remediation step) whenever an action is disabled.
- **Do** provide keyboard shortcuts and focus states for all high-frequency workbench operations.
- **Do** keep administrative table density high with compact padding.

### Don't:
- **Don't** use generic SaaS floating card layouts with excessive padding that force endless vertical scrolling.
- **Don't** display ambiguous, unlabelled color dots without accompanying accessible text or `sr-only` labels.
- **Don't** present blanket account bans when an administrative hold is strictly scoped to a specific action (`blocking_level`).
- **Don't** use decorative animations or slow transitions in operational workbenches.
- **Don't** truncate student names, course codes, or financial balances in primary administrative tables.
