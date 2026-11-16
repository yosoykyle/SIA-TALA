---
version: 1
slug: "reg-c01"
primary_target: "app/Filament/Pages/CatalogCurriculaWorkbench.php"
related_targets: ["REG-C01", "AHD-001", "app/Filament/Resources/Programs", "app/Filament/Resources/Courses", "app/Filament/Resources/CourseSpecifications", "app/Filament/Resources/CurriculumVersions", "app/Filament/Resources/ImportBatches", "resources/views/filament/pages/catalog-curricula-workbench.blade.php"]
---

# Surface Brief: Catalog and Curricula

<!-- impeccable:surface-brief 1 -->

## Job and authority

**Mode: Operate.** Registrar records an approved academic source, prepares a complete curriculum and activates it with understandable effects. Academic Head inspects the permitted source and readiness without an approval or mutation workflow.

PRD 03 Section 5 owns Program/Course/Curriculum facts, effective authority, CSV v1, activation and immutable history. PRDs 04/05 own assigned-curriculum consumers; PRD 06 owns financial effects. The Blueprint owns REG-C01 and connected AHD-001 interaction outcomes; PRODUCT.md purpose, DESIGN.md shared appearance and the Protocol permissions. [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and [#91](https://github.com/yosoykyle/SIA-TALA/issues/91) own assignments, acceptance and evidence. This brief describes intended delivery, not implementation or owner acceptance.

## Entry and information hierarchy

Enter through Registrar's Catalog & Curricula. Academic Head enters through permitted Catalog navigation or a source drill-in from Academic Oversight. Start with recognizable Program identity, applicable authority/current curriculum, remaining work and the next permitted action. Show Program name with its supporting code; preserve the selected Program, version and authorized return scope across related destinations.

Follow **choose Program → inspect applicable authority → prepare one grouped sheet manually or through CSV → resolve findings → review impact → activate**. The groups are connected tasks, not another mandatory wizard. Reuse stored facts and the PRD's plain approval-source labels. Do not repeat a Program picker when the context supplies it. Counts are secondary only where they help choose work; a six-destination menu cannot replace the journey. A healthy state need not invent a primary action.

The current-authority display follows PRD 03's effective-period/as-of rule. Keep real approval evidence, but place detailed source/version history behind a recognizable contextual disclosure. Do not keep a field, screen or stored fact solely because it exists. Preserve information with a genuine academic, historical or integrity purpose.

## Sheet, source and correction

One curriculum sheet grouped by year and term is the sustained preparation surface. Course identity, revision, units, placement and readiness remain recognizable together. Progressive disclosure exposes the necessary requisites, scheduling treatment, recurring components, modes and room requirements without repeating every source fact in every row. External competency treatment follows PRD 03; evaluation-sheet outcomes never become catalog facts. Externally arranged courses retain their academic units and make the absence of recurring timetable meetings clear.

Use focused row/metadata forms for bounded corrections and a page for sustained review. Keep one canonical editing path; preserve authorized old links through contextual recovery when a duplicate path is retired. Draft corrections are practical; active or historically used data is read-only with a successor path. A blocker states what is wrong, who corrects it and an available source-owned action. Opening a source and returning preserves the current sheet and valid context.

Activation names the selected version, actual source/readiness and affected future use, including preservation of existing Student curriculum assignments. Retain explicit confirmation and current-source/permission checks. No Academic Head approval queue, generic rules, automatic reassignment or financial mutation follows.

## CSV and history

Offer the optional fixed CSV v1 beside manual preparation and link the downloadable template and constraints from PRD 03. Do not duplicate its field contract in this brief or add a mapping designer. Preview shows the original row, interpretation, current-source comparison, proposed Draft effect and actionable findings. Correct the original template, acknowledge warnings and create Drafts only when the source is valid. Draft creation and activation remain visibly distinct.

Empty, parsing, validation and stale-source states retain useful context and recovery. A failed creation never implies partial success; a retry never silently duplicates Drafts. Source values remain escaped plain text and findings use PRD-owned safe CSV rules. Existing batches, files, findings and posted results remain attributable; legacy evidence is not silently reinterpreted under v1.

Registrar owns upload, preview creation, curriculum-import findings download and commit. Academic Head retains authorized read-only review and existing source-file access. Findings and source-file download are distinct permissions; visible controls and underlying actions agree. Preserve unrelated import rights and never expose foreign Program context.

## Shared composition and components

Reuse DESIGN.md's school-first identity, semantic light/dark surfaces, tactile action family, typography, record context and shared collection/inspector patterns where suitable. Use project-local Impeccable and relevant interface guidance. Native Filament tables, grouped rows, forms, infolists, actions, filters and dialogs own standard behavior; focused TALA composition connects context and the bounded preview/commit flow. Use record subnavigation only when it helps sustained work on the same record. Do not create another application shell or custom table engine.

Search/filter controls remain native, relevant and nonduplicated. Retain existing usable shared patterns from delivered journeys; their appearance does not transfer domain fields, permissions or source rules. Actions, breadcrumbs, contextual returns and dialog/page choices are consistent. Remove redundant back controls when native navigation already supplies the same safe return; retain a distinct recovery/return action when it serves a different context.

## States, responsive behavior and accessibility

Reuse existing native loading feedback without duplicate overlays. Distinguish no Programs, no curriculum, no selection, filtered-empty results, missing source, unauthorized access and failed work. Only a true whole-destination absence needs the shared primary-empty treatment. A record with missing required source is a recovery problem, not an empty success.

Wide layouts support sheet scanning and sustained source review. Narrow layouts retain readable identity, year/term grouping, essential values and access to row details without hiding required actions. Preserve table semantics, keyboard navigation, focus/return after dialogs, labelled status and readable light/dark and forced-color behavior. Avoid essential hover-only information, decorative motion and color-only readiness cues.

## Prototypes and verification

Follow Protocol Section 6 and the accepted Issue contract. Inventory the affected Registrar entry, Program/source context, Course/revision and curriculum sheet, CSV preview/recovery, activation and materially changed dialogs; cover Academic Head's read-only composition and return path. For meaningful changes use **prototype → implementation → rendered check** per surface, reusing proven shared patterns when another prototype adds no information. A single entry-page mockup does not cover deeper work. An inspectable prototype may use available local sketch/image/HTML tools; no environment-only plugin is required.

Verify the main manual/import journey and proportionate alternate/error/cancellation/stale recovery, narrow layout and keyboard/focus. Reuse owning tests for effective authority, safe Draft creation, roles, immutable activation and assigned-curriculum preservation. Target relevant Term/enrollment/Student projections without redesigning their unrelated destinations. Broader Registrar curriculum evaluation and Academic Head/Public/admin work remain separate.

Agents own technical verification; owner review assesses appearance and understandable use. Local readiness stops for owner review/refinements and reporting follows explicit permission. Captures, test results, removals, legacy limitations and owner verdicts belong in the Issue/evidence rather than a progress timeline in this brief. Recording this brief grants no implementation, publication or deployment permission.
