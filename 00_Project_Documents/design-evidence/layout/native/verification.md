# Native preview verification — 2026-10-02

This is an isolated local preview. SIA-TALA application code, product documents, dependencies, and databases were not changed.

## Confirmed

- Sidebar-only update: the rendered panel contains no topbar; desktop expand/collapse and the sidebar theme control were observed. The native mobile menu opens the drawer at 390 × 844 without document overflow. The isolated checks still pass: 3 tests, 27 assertions. Mobile Escape dismissal was attempted without an observed close and remains unverified.

- Filament component and Bootstrap public routes return HTTP 200. The static entry server responds on port 4173.
- PHP syntax and public demo JavaScript syntax checks pass.
- Isolated PHPUnit/Livewire checks pass: **3 tests, 27 assertions**. They cover required/email/program validation, valid submission notification, name sorting, table filtering, empty results, confirmation action lifecycle, and an empty database-connections configuration.
- Browser observations confirm native Filament form controls, confirmation dialog, successful notification, table empty state, desktop sidebar collapse, mobile drawer, local Inter loading, and light/dark switching.
- Filament was inspected at the normal desktop viewport and a 390 × 844 mobile viewport; the mobile document did not exceed the viewport width. No custom bottom bar, hover-expansion, or scroll-hiding script is applied.
- Bootstrap's public page renders native navigation, grid, controls, and modal; dark-theme switching and modal opening were observed.
- The public HTML mirrors are identical. Setup copies assets into the preview and does not write to the dependency source.
- Contrast: the observed Filament light button's native foreground/base pair is approximately **6.9:1**. Bootstrap's primary light button is approximately **5.1:1** at its base and **4.6:1** at its brightest gradient stop; the primary dark base pair is approximately **6.7:1**. These measurements cover those specified pairs, not every control/state.
- Impeccable's scoped type/layout scan completed. Its only returned finding was `overused-font` for Inter. Retaining Inter is intentional: it is the approved typography and native Filament default.

## Still requires destination verification

Bootstrap mobile navigation/reflow, Escape dismissal and focus recovery in both frameworks, full keyboard traversal, screen readers, zoom/high contrast/reduced motion, cross-browser behavior, all contrast/state pairs, and desktop collapse persistence across destination routes. The bootstrap sample's browser follow-up was interrupted by navigation; modal opening is verified, dismissal/focus recovery is not.

Production permissions, authorization, uploads, persistence, exports, and school workflows are outside this preview. Owner visual acceptance remains pending.

## Repeat the isolated checks

From `native/`, using the installed dependency source:

```powershell
php "$env:TALA_DEPENDENCY_ROOT/vendor/phpunit/phpunit/phpunit" --no-configuration --bootstrap bootstrap/app.php --do-not-cache-result tests/NativePreviewTest.php
```

Set `TALA_DEPENDENCY_ROOT` as described in the main README. The test application sets its own testing environment and uses no database connection.
