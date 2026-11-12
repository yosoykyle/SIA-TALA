---
version: 1
slug: "reg-g01"
primary_target: "app/Filament/Pages/GradesAndCompletion.php"
related_targets: ["REG-G01", "REG-G02", "REG-G05", "FAC-003", "STU-003", "AHD-001", "resources/views/filament/pages/grades-and-completion.blade.php", "app/Filament/Pages/FacultyGradeRoster.php", "resources/views/filament/pages/faculty-grade-roster.blade.php", "app/Filament/Resources/GradeRosters/GradeRosterResource.php", "app/Filament/Pages/CompletionAndTor.php", "resources/views/filament/pages/completion-and-tor.blade.php"]
---

# Surface Brief: Grades, Academic Records, Lifecycle and Completion

<!-- impeccable:surface-brief 1 -->

## Job and authority

**Mode: Operate.** Faculty finishes an assigned official roster; Registrar reviews, returns or releases results and records authorized academic/lifecycle/completion outcomes. Student and permitted Head readers understand the released facts and responsible next office.

PRD 05 owns academic rules, lifecycle, completion and TOR; PRDs 03/04 own teaching/calendar sources and enrollment; PRD 06 owns financial review and output restrictions. The Blueprint owns capabilities and interaction, PRODUCT.md purpose, DESIGN.md reusable appearance, and the Protocol execution permissions. [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and children own assignments, amendments, acceptance and evidence.

Coverage includes REG-G01–G06, FAC-003 and connected STU-003/AHD-001 projections only as assigned. Reusing this brief does not expand a child into every domain. Accounting/System Administrator gain no academic mutation rights.

## Direction and shared frame

The work leads: class to finish, submitted roster to review, released result to inspect, school change to record or actual TOR request to prepare. Use DESIGN.md's school-first identity, green-led actions, restrained tactile controls, Inter hierarchy and semantic light/dark surfaces.

Use native fields/actions/dialogs for bounded decisions and page context for sustained review. Reuse record-workspace, identity, result emphasis, disclosures, collection footer and Now/Next. Sources, page sizes, permissions and workflow remain task-owned. Short collections stay content-sized; long list/detail panes scroll independently within the shared cap. Phone list/detail transitions preserve selection and return context. Reset detail scroll and independent history paging when selection changes.

## Class work, results and history

- Faculty's assignment-scoped chooser leads with course/class/Term, actual membership or update-needed state, roster status and Open class. Preserve native search/Term filtering, eight-class pages, sole-class direct entry and explicit All classes recovery.
- Registrar Class grades uses native query-backed responsive class cards: six per page with 6/12/24 choices, one Term/search/status row, all-state defaults unless explicitly scoped, and Term/year per card only across Terms. Entered-result counts never imply release.
- Faculty owns controlled final-result entry, conditional INC notes, designated/co-Faculty rights, private autosave/retry, returned-row scope and complete-roster submission. Released results use the shared read-only display, not disabled entry fields. Grade Entry permission follows the actual window or scoped late authorization, never Examination Period.
- Registrar released-class and INC/correction work use the compact collection/selected-result frame. Keep one search, supported native scope filters, current result/deadline/remaining work, learner identity and contextual actions. Empty results clear stale selection. INC state and expiry retain PRD-owned action guards and real next responsibility.
- Export, Class activity and Delivery share one responsive toolbar with actual status/Faculty below. Activity/delivery use native side panels. Teaching assignment, submission history and delivery history remain distinct. Do not duplicate Head's existing header inside its shared inspector.
- Class submission history belongs with Class grades, not a duplicate History destination. Find any class or earlier term and old links preserve authorized Term/state/search/sort/page/student context. Submission/return/release dates, actors and reasons remain separately labelled. Result/correction and submission histories have independent ten-entry server pages; missing historical evidence remains unavailable.
- Notification groups show real counts, status and latest time; only eligible original events can retry. Preserve contextual return, source/version guards, after-commit behavior, immutable history and privacy. No automatic enrollment-subject change follows academic release.

## Registrar Student records

All programs is one flat native paginated directory with Current program; selecting a program shows one heading without repeated program blocks. Use ten rows with 10/25/50 choices, real source search/filtering and explicit selected-Term membership. The desktop filter/results layout fits available content width with native anchored popups; phones retain readable identity, accessible record actions and grouped program/section facts.

Summary combines shared identity, native view tabs, dominant current enrollment and quieter academic guidance/requirements. Reuse enrollment-context and mount native checklist/restriction managers only for existing source groups. Record actions derive the selected Student and exact source rather than repeat a picker. Retain authorized resolution and history; no generic hold creation or producer-owned field edit.

Academic record leads with released results, average readiness and contextual INC work. Small collections keep compact context below rows; larger ones may use a supporting column. Missing curriculum is not zero progress. History uses actual assessment-created and lifecycle-effective dates with named source links. DESIGN.md and the Blueprint own the detailed Student ledger composition.

## Lifecycle recording and inspection

Use DESIGN.md's illustrated Student picker, native six-choice form, conditional facts, effects review and compact Now/Next. Student, exact Term, effective date, concise reason and validated impact lead; enrollment is derived. Ordinary entry omits generic authority/reference/date boxes under PRD 05. Retain historical metadata and genuine late-exception evidence. Changing type clears irrelevant hidden fields and acknowledgement; changing Student confirms before discarding work.

One collection/selected inspector contains the complete record; the separate full-record destination is retired. Keep ten-entry server pages, named native filters, original change/effects, source-owned status, effective dates, attribution and actual credits. Old links authorize and open the containing page with supported scope. Missing snapshots never become today's profile or invented zero effects.

Future approvals show unchanged current facts and intended effects until Registrar applies on/after effective date and Term start. Expected return is planning information only. Pending, applied, cancelled and unavailable sources retain distinct actions. Course Drop belongs to Enrollment; graduation remains separate contextual recording. Historical types and original approval snapshots remain protected.

Accounting's authorized source inspection retains exact Student/Term/enrollment matching and staff-only evidence. Pending review/disposition belongs to PRD 06 and reg-e01; an old five-source preview cannot limit pending work. Academic Applied status never establishes financial review completion or clearance.

## Completion and TOR

Use one shared request collection/contextual inspector with ten-request server pages, independent overflow and phone list/detail/back. Native TOR requests/Graduation navigation stays above the panes; Record TOR request belongs beside the collection heading. Search/status/selection preserve safe unsaved-input recovery.

Actual request reference/date start the clock independently of graduation. Missing identity, academic/configuration/signatory or restriction sources block the appropriate output action without losing the request. Shared school signatory setup is separate from intake/per-request data. Review the exact printable document before native issuance confirmation; preserve historical templates and immutable inputs. Do not fabricate physical sealing/delivery.

Graduation derives from the selected Student's complete curriculum evaluation and actual institutional result; missing requirements never imply completion. Head remains read-only; Student sees permitted results; Accounting owns only its genuine output restrictions. No per-request finance-approval queue is introduced.

Request/graduation history stays with its record, using the shared timeline and independent ten-entry server paging. Keep recorded degrees attributable when later sources are unavailable. Issued, superseded, void/replacement, blocked and failed-preview states retain their actual guards and recovery. No duplicate history workbench or invented document source.

## Academic guidance and Student history

Registrar/Student guidance names its exact Term or student-wide scope and agrees with the consuming enrollment effect. Distinguish current studies, next enrollment, calendar closure, restriction and ambiguous context. Lead with safe reason, responsible office and available action, without a compulsory extra scope-selection step or generic all-clear.

Academic record contains every own applied school change with type, Term/AY and labelled effective date, using independent ten-entry server pages before and after grade release. Old links open the same section and the page of an authorized change; foreign/pending/cancelled/missing targets recover generically. Remove latest-five, View all/View change and duplicate parent-action detours. Staff retain original records and private evidence.

Reuse DESIGN.md's primary-absence/task-state hierarchy. Overview responds to actual widgets; enrollment has one clear scope/reason/action. No primary COR/results/account uses its existing appropriate empty treatment, while missing sources in existing records, nested absence, restrictions and filter misses remain compact recovery. Planned-versus-official orientation is a lead line inside Study plan; actionable guidance is separate. Shared semantic tile/type/surface rules remain consistent.

## Empty states, prototypes and evidence

Whole-destination absence may use one approved contextual illustration and permitted action. Awaiting selection may reuse the focused picker. Nested sections, missing values, filter misses and inaccessible/failed sources use compact recovery. Clear stale inspectors, hide meaningless zero footers and retain active search/reset. DESIGN.md owns the reusable hierarchy.

Follow Protocol Section 6: structure across affected roles, meaningful per-surface prototype → implementation → rendered check, supported shared-pattern reuse and bounded verification. Cover changed pages/dialogs, role variants, narrow layouts, keyboard/focus, paired appearances and meaningful failure/recovery states. A shared pattern does not transfer domain permissions or source rules.

Agents own technical verification; owner review assesses appearance and understandable use. Tests, captures, synthetic fixture limits, owner verdicts and unresolved coverage belong in the owning Issue and evidence. Rendered technical checks do not establish a separate human verdict. Earlier alternatives and dated checks remain recoverable through the [historical source index](../../00_Project_Documents/archive/project-progress/2026-11-12-executor-continuity-and-design-history.md).
