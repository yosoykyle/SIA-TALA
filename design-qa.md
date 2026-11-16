# Selected Accounting workspace: design QA

Scope: the selected persistent account workspace, selected grouped History, and owner amendments. This is local implementation evidence, not acceptance of all Accounting. Payment-source review variants, assessment states and Fee Plans remain in the per-surface prototype sequence.

## Visual truth and captures

- Source: `C:/Users/HUAWEI/.codex/generated_images/01a1cf21-45a6-77e3-aa6e-cac09cfa2263/exec-4bdd6a8b-0efd-4a2f-87ad-127458e328ac.png` (1487 × 1058).
- Implementation: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/41-selected-workspace-desktop-final.jpg` (1440 × 1024).
- Phone: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/40-selected-workspace-phone-final.jpg` (381 × 825 capture; requested 390 × 844 viewport, browser reports 391 × 844 CSS pixels).
- Dark: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/46-selected-workspace-dark.jpg`.
- Dialog: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/42-shared-review-modal.jpg`.
- Corrected source navigation: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/43-original-change-navigation.jpg`.

State: Accounting, disposable preview database, Alyssa Cruz, Second Semester, two pending applied changes, PHP 12,000 due. No review or financial result was submitted in the browser. The dark capture uses the student-changes queue; the light comparison uses all accounts. That difference changes collection rows and page copy intentionally.

Full-view comparison: the source and final desktop capture were opened together in the same comparison input. The phone capture was included in that input. Source and desktop have effectively the same aspect ratio; compare at equal displayed width, not pixel offsets across differently sized rasters. Browser screenshot density is backend-managed rather than explicitly fixed. No claim of pixel-exact fidelity is made. Text and controls were readable at full view; no additional crop was needed for this bounded comparison.

## Owner-directed differences

- Retain the existing shared identity header, native tactile tabs/buttons and existing hover/focus/selection rather than reproducing generated control variants.
- Retain sidebar sub-options and real queue state. The source image incorrectly highlights Student changes while displaying all-account records; implementation uses the actual active queue.
- Replace the duplicate worklist dropdown with the current collection heading and native filters.
- Show charges and payment dates in a visible sunken section. They are not collapsed.
- Present exact source context in the guarded result dialog, avoiding another source-launcher dropdown.
- Preserve authoritative model labels, real ordering, exact account amounts, source identifiers and permissions. Generated wording and records do not override product truth.

## Findings and comparison history

First comparison used `38-selected-workspace-desktop.jpg` and `39-selected-workspace-phone.jpg`:

- P2: a short two-item review list displayed a redundant range footer. Fixed: review-list footers appear only when pagination is required. The outer account collection retains its meaningful range and pagination.
- P2: the phone inspector scrolled inside an already scrolling page, reducing reachable task content. Fixed in the shared record-workspace mobile rule: the visible pane scrolls with the page; desktop panes retain independent overflow.

Confirmation used the final desktop and phone captures above. The redundant review footer is absent. Phone computed inspector overflow is `visible`, pane max-block-size is `none`, and document width (381) does not exceed reported viewport width (391). Current work remains selected and charges/payment dates remain visible sections. No actionable P0/P1/P2 mismatch remains for this selected composition.

## Required fidelity surfaces

- Typography: existing Inter, shared heading/body hierarchy, native action sizes, readable student identity and green tabular financial figure. Native token sizes are retained by explicit owner instruction rather than copied from generated text rendering. One page h1; selected student h2; charge and obligation headings use the next semantic levels.
- Spacing/layout: shared card gap and its doubled section interval; native collection/inspector components; responsive list/detail/back; finance grouped separately. Desktop density intentionally follows the existing split pattern. Source controls and mock financial disclosures are superseded by owner amendments.
- Colors/tokens: shared white/graphite surface, sunken supporting section, green financial emphasis, existing sage selection (the decorative green collection stripes were subsequently removed to restore the original Student neutral surface pairing), warning review state. Paired light/dark appearance inspected. No new hover recipe.
- Assets: existing school crest, shared student identity artwork and Heroicons retained. No generated screenshot is embedded as UI. The selected mock introduces no new required illustration.
- Copy/content: student, number, Term, change type and effective date come from the fixture's actual source records; current due comes from the existing financial projection. Review-only effect is stated beside the work and in the dialog.

## Interaction and implementation checks

Browser: phone Back returns to collection; account selection opens detail; History/Current work preserve selection; source breadcrumb returns to the selected account/queue; result dialog opens and cancels; Light appearance restored after dark inspection. Dialog body is white and footer is shared sunken gray, with native controls and ordering preserved.

Browser console checked: no new errors from this inspection; the retained log includes an older Alpine persistence error at 01:50 UTC, before this round (02:39 UTC), which is not attributed to these changes.

Automated evidence: affected workbench/workspace suite passed 33 tests / 528 assertions; workspace suite with added exact-source URL coverage passed 6 tests / 44 assertions. Latest frontend build and diff whitespace check passed. Earlier behavior/security/concurrency tests remain recorded separately. No publication or remote CI claim.

## Remaining scope

History Prototype 3 was selected and its neutral revision is implemented and checked below. Payment proof, assessment variants, correction/reviewed states, Fee Plans and remaining connected handoffs still require their scoped prototype/rendered review (online-payment recovery was retired under broadened #88). This report does not accept those screens or close Issue 88.

## History Prototype 3 — selected and implemented

Visual target: `C:/Users/HUAWEI/.codex/generated_images/01a1cf21-45a6-77e3-aa6e-cac09cfa2263/exec-05962a90-d5ae-4bda-b82c-c64f3b6b2571.png` (1487 × 1058), the scoped neutral-color revision of selected result `exec-f889e73f-6ec0-43db-9c63-1808e3661832.png`.

Implementation evidence in `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/`:

- `50-history-grouped-desktop-final.jpg`: 1440 × 1024 desktop capture, requested 1440 × 1024 CSS viewport, reported width 1441.
- `54-history-grouped-light-detail.jpg`: same viewport, inspector scrolled to show both full typed records.
- `51-history-grouped-phone-final.jpg`: full-page 380 × 1695 capture, requested 390 × 844 CSS viewport, reported 391 × 844.
- `53-history-grouped-dark.jpg`: paired dark appearance, same record and scrolled inspector.
- `52-history-proof-viewer-phone.jpg`: existing native private viewer opened from History, showing the disposable synthetic preview image.

State: Accounting, Mikaela Reyes, Second Semester, one submitted receipt claiming PHP 3,000 and one assessment of PHP 12,000. Neither a verified payment nor an export exists. No financial action was performed.

The revised target, desktop capture and full-page phone capture were opened together in one comparison input. Normalize source and desktop to equal displayed width; their aspect ratio is effectively equal. Capture density is backend-managed. The native identity header and explicit source facts are retained. A focused live inspector capture and native dialog capture supplement the full-view comparison; all source copy and amounts are readable. The desktop pane's independent scroll deliberately remains the existing shared behavior, and phone rows stack with one page scroll.

### History findings and confirmation

- P1, first pass (`48-history-grouped-desktop.jpg` and `49-history-grouped-phone-first.jpg`): the legacy table foundation imposed an 800px minimum width, hiding amounts and document controls in a split inspector. Fixed: record-table variant removes that minimum and uses fixed tracks; compact records stack. The obsolete global 50rem minimum was also removed so new Student/native table consumers do not acquire it.
- P2, owner correction: green decorative stripes and headers were an executor-created adaptation, not the original Student scheme. Removed the row stripes and unselected-record tint. Shared headers and inset content now use neutral sunken tokens; selection remains the original green treatment. Corrected DESIGN.md and the surface brief.
- Confirmation: both desktop tables have client/scroll width 712/712; both phone tables 324/324. Phone document and viewport widths are equal (391); semantic rows/cells remain exposed. No History disclosures remain. Header/inset computed light color is rgb(244, 244, 245); unselected collection records have transparent backgrounds on the neutral parent. Dark paired capture retains neutral tiers.

### History fidelity surfaces

- Typography: existing Inter/native shared controls, 20px section hierarchy, semibold record titles and quieter source metadata. Assessment version is retained to distinguish older source records; generated wording does not erase real provenance.
- Spacing/layout: existing selected workspace, shared 12/24px intervals, visible source details, three-column typed ledgers on desktop and labelled stacked rows on compact containers. The actual target/obligation and approval reference add truthful copy beyond the mock; the desktop pane scrolls to retain it.
- Colors/tokens: raised parent and sunken neutral ledger, muted header labels, existing green selection/active control and library icons. No decorative green rows.
- Assets: existing school crest, identity artwork and native Heroicons. No new illustration or rasterized UI. The viewer capture contains a synthetic test fixture, not a quality sample of a real receipt.
- Copy/content: submitted claim stays separate from assessed charges and verified payments. Empty categories are omitted. No verified-payment export appears when there are no verified payments. Non-monetary review/export records have no meaningless amount column/value. No total combines different record meanings.

Interaction checks: native View proof loads its image, Escape closes it and focus returns to View proof. Dark appearance was checked and original Light restored. Native links retain exact assessment/proof targets. Current work/History preserve the account. Console returned no errors after the fresh reload. Empty-account History is covered by the affected component test rather than a newly persisted browser fixture.

Verification: full affected workbench/workspace suite passed 34 tests / 543 assertions; subsequent workspace suite including the new empty-account case passed 7 tests / 55 assertions. Independent pagination/foreign-source exclusion and retained payment/export ownership checks passed. Latest build, Pint and diff whitespace checks are recorded in the issue-private logs. No commit, publication or owner acceptance is implied.

No actionable P0/P1/P2 finding remains for the selected History composition. Remaining Accounting surfaces still follow their own prototype/rendered check.

## Payment-proof Prototype 2 — selected and implemented

Source: `C:/Users/HUAWEI/.codex/generated_images/01a1cf21-45a6-77e3-aa6e-cac09cfa2263/exec-38d0d54f-4e73-4ae1-a445-db2094077927.png` (1487 × 1058).

Captures in `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/`:

- `61-proof-comparison-desktop-final.jpg`: 1440 × 1024, requested 1440 × 1024 CSS viewport, reported 1441 × 1024.
- `62-proof-comparison-supporting-details.jpg`: same inspector scrolled to visible charges/payment dates.
- `60-proof-comparison-phone-final.jpg`: full-page phone capture at requested 390 × 844, reported 391 × 844.
- `59-proof-comparison-dialog-phone.jpg`: existing guarded verification dialog and native two-tone footer.
- `63-proof-comparison-dark.jpg`: same comparison and visible supporting records in dark appearance.

State: Mikaela Reyes, Second Semester, PHP 3,000 receipt claim, PHP 12,000 exact unpaid target, no verified payment. The native dialog was opened and cancelled; no payment was recorded.

The exact selected image, final desktop and full-page phone captures were opened together in one comparison input. Source and desktop use effectively equal aspect ratio and equal displayed width; capture density remains browser-managed. The supporting-record capture covers the lower inspector rather than pretending the entire live pane fits above the fold.

First comparison found P2: the larger native figure wrapped the word unpaid onto its own line. Fixed: compact responsive 24–32px comparison figures with baseline labels. Receipt date copy shortened to Paid on receipt. Confirmation shows figures and their meaning together and no horizontal overflow. Desktop supporting tables have client/scroll width 349/349; phone tables 324/324 and document/viewport width 391/391. Comparison stacks to one column at compact container widths. No disclosures remain in the comparison or its supporting section.

Intentional source differences: retain the existing shared identity header, native action sizes and desktop pane scrolling. Real source references wrap when needed. Charges and payment obligations use separate labelled supporting tables; the generated combined fee/date row is not reproduced because a payment obligation date is not necessarily a charge-line date. Preserve actual approval provenance and no-payment authority when present.

Required fidelity surfaces:

- Typography: native Inter, shared heading/body sizes, 28.5px computed desktop comparison figure, readable metadata and tabular amounts.
- Layout: same selected account workspace, side-by-side receipt/target comparison, neutral inset facts, decisions directly after comparison; shared 12/24px rhythm. Phone stacks comparison and supporting tables with one page scroll.
- Colors: existing green financial emphasis and selected record; neutral white/graphite parent and sunken fact/table surfaces. No decorative green striping. Light restored after dark review.
- Assets: unchanged existing crest, student identity artwork and Heroicons; native private proof viewer retained. No new image assets needed.
- Copy: claimed versus unpaid explicit, no preview subtraction on ordinary screen, source-change recovery instead of assumed amounts, exact target dates and approval references remain visible.

Behavior: receipt choice preserves account, queue and search; multiple target totals use integer cents. Changed source renders a disabled verification trigger. The original guarded action still rejects a source that changes after the dialog opened; action-level disabling was deliberately avoided because it made a mounted native action unresolvable. Independent verification reference, actual amount, allocation preview, safe cancellation and server source checks remain unchanged. Browser dialog cancelled successfully and console check returned no errors.

Verification: affected workspace suite passed 36 tests / 569 assertions; selected-proof security/validation/allocation/rejection tests passed 4 / 73; final comparison test rerun passed 1 / 16. Latest build, Pint and diff whitespace checks passed. These are local evidence; no owner acceptance, commit or publication is implied.

No actionable P0/P1/P2 finding remains for the selected payment-proof composition. Assessment variants, manual/onsite payment recording, and Fee Plans remain in the broader Accounting prototype sequence (online-payment recovery was retired under broadened #88).

## Accounting connected-workspace cleanup — final local review

Current direction: selected single-screen reference `C:/Users/HUAWEI/.codex/generated_images/01a1cf21-45a6-77e3-aa6e-cac09cfa2263/exec-744f6dcb-0d26-4ead-860f-94ea1e56a7aa.png`, qualified by the owner's later explicit correction: retain the sidebar sub-options and remove the duplicate top filter cluster. The owner's header sketch places the collection title at left, Filters at right and full-width search below. These corrections supersede the earlier top-filter experiment and its captures 66–73.

References: the selected workspace image and genuine Student Finance `27-student-finance-shared-reference.jpg` were opened with the final workspace, Fee Plan and phone captures in one comparison input. Preserve existing Inter, Heroicons, identity artwork, native hover/focus/selection and guarded actions. This is application of the selected shared composition and later owner refinements; Fee Plan fields and publication authority remain destination-specific.

Final captures in `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-accounting-audit/`:

- `81-accounting-overview-final.png`: balanced content-sized work cards, purposeful nonempty-queue emphasis and compact student lookup.
- `82-account-history-reactive-final.png`: History in the selected workspace with the original sidebar and no duplicate navigation.
- `83-fee-plan-blocker-final.png`: visible competing-current-plan blocker, native disabled publication, current-plan recovery, separate charges/payment schedule and visible source versions.
- `84-fee-plan-phone-final.png`, `85-fee-plan-edit-phone-dialog.png`: stacked reading layout and native sticky two-tone modal footer.
- `86-accounting-phone-proof-final.png`: selected proof comparison, visible dates/charges and native decisions; document/viewport width 391/391.
- `87-accounting-workspace-final.png`: restored sidebar, shared header, and term-labelled collection (captured prior to broadened #88 retirement of online-payment review); document/viewport width 1441/1441.
- Earlier `72-unified-empty-final.png`, `73-unified-online-dark.png` (historical capture prior to broadened #88 online-payment retirement), `75-fee-plan-new-draft-dialog.png`, `76-fee-plans-shared-controls.png` and `78-fee-plan-publication-dialog.png` cover empty, dark, collection and native dialog states. Their navigation or blocker copy may predate the final correction; final captures above own current presentation.

Desktop requested 1440 × 1024 and reported 1441 × 1024; phone requested 390 × 844 and reported 391 × 844. Captures are browser-scaled, not pixel-exact raster clones. Native identity/control dimensions and independent desktop pane scrolling remain intentional. The viewport override was reset and original Light appearance retained. Latest console query returned no errors.

### Verified remediation

The external source-only audit was checked against current code, routes, tests and rendered evidence. Implemented remaining applicable findings:

- Consolidated the conflicting shared table base rules. Headers and alternating rows use the existing neutral sunken surface against neutral ordinary table bodies, preserving native hover/selection. Removed the enclosing gray wash that hid alternation. TOR request tables now opt into the same foundation and shared collection empty state.
- Shared `record-collection-controls` across Student accounts and Fee Plans. No zero filter badge or duplicate top work-filter bar. Sidebar, tab definitions and collection headings use the same destination labels; action labels retain useful verbs. Active filter labels remain visible. The owner's title/Filters/search arrangement is retained rather than introducing another always-visible filter row.
- Added Term to collection rows. Missing-account reviews show both the pending work and unavailable source. Removed outdated numbered fee-first widget copy.
- Kept all applicable current tasks in one selected pane, with filtered work first. Current work/History update in place; browser verification retained the same parent workspace identity and changed the URL section. Old Accounting account URLs return to the selected workspace, preserving scope and exact evidence IDs. Source breadcrumbs retain contextual return paths.
- Completed student-change results and guarded correction actions remain reachable in account History after leaving pending work. Contextual TOR restrictions remain reachable for students with actual requests.
- Kept charge dates, approvals, change sources, payment recovery facts (subsequently simplified to manual/onsite proof upon broadened #88 online-payment retirement), and Fee Plan versions visible. The auditor's suggestion to collapse History details was declined because the owner explicitly requested visible information. Compact empties remain compact; native/shared headings now use the same green rule.
- Balanced the four overview work types without a two-row spanning card, removed retired queue/search media selectors, and highlighted only a queue with actual work. No aggregate combines overlapping queues.
- Added blocker associations to disabled proof/change decisions and a truthful first-review Cancel label. Existing source-change, correction, duplicate, foreign-source and financial verification safeguards remain intact.
- Fee Plan publication readiness now exposes the same competing-plan rule already enforced by publication. A new regression check proves that the unrelated draft stays blocked and the current plan and existing assessment stay unchanged.
- Found and fixed an inspector-only missing History URL in ended-account review. Regression coverage now renders ended and unavailable account variants inside the actual selected workspace without assuming a zero balance.

Shared controls compute to 12px gaps and independent selected-account groups to 24px. Native forms and controls retain their established dimensions. Existing Student/Registrar components and semantic tokens own the visual behavior; no new hover recipe, decorative green stripes or artwork were introduced. No actionable P0/P1/P2 visual finding remains in the reviewed compositions. Further taste changes remain owner review, not automatic acceptance.

### Scope and evidence limits

The six older finance resources named by the external auditor are not registered in the active panel; read-only route inspection found no corresponding live routes. They were not reintroduced or given duplicate workflows. Registrar-owned workflow composition remains with its existing owner; shared empty-heading presentation applies without changing that workflow.

Browser checks covered overview, populated/empty collections, selected account/proof/history/external-payment states (prior to broadened #88 online-payment retirement), legacy-link consolidation, Fee Plan collection/detail and new/edit/publication dialogs, phone return behavior and representative light/dark states. Rare source, stale, ended, missing, overlapping-review, correction and financial-action variants are additionally covered by affected component/journey tests. This does not claim a manual browser visit to every possible state combination or a live screen-reader certification.

Fee Plan preview records were created through canonical actions only after proving both configured and active database `test_tala_db_issue88_preview`. Their labels and authority reference identify them as synthetic preview fixtures. Existing student assessments were not reassessed. No payment, review result, draft edit or publication was submitted in the browser; dialogs were cancelled.

### Verification

- Connected Accounting/workspace/navigation/lifecycle/Fee Plan suite: **104 tests / 1,072 assertions passed**.
- Selected proof, external-payment (prior to broadened #88 online-payment retirement), assessment and reconciliation source guards: **19 tests / 330 assertions passed**.
- Combined affected checks: **123 tests / 1,402 assertions**.
- Latest frontend build, Pint and whitespace checks passed. Logs are retained under `storage/app/private/issue88/`.
- A broader unchanged legacy reconciliation class requires the shared `test_tala_db` in its own precondition and was not run against another workspace's database. Its failed precondition is not reported as behavioral verification; relevant canonical journey checks above ran on the isolated test database.

The earlier remaining-work notes in this evidence file are superseded by this final connected cleanup review. Local implementation and verification are ready for owner hands-on review. No owner acceptance, commit, publication, Issue write, merge, deployment or orchestrator return is implied.

final result: passed

## 15 November 2026 — Owner scope-controls and Payment channels refinement

- Owner annotations: reduce the repeated Accounting scope header; refine native account repeaters; remove fabricated bank/wallet starter destinations; simplify enabled wording; match digital previews to Cash neutral materials; harden all Payment channels inputs.
- Shared changes: `record-scope-bar` removes its duplicated title/icon when selectors are present, retains context and outside-scope recovery, and serves all four Student accounts queues plus Fee plans. Fee plans omits academic-year metadata already present in its selector. Digital preview groups share raised outer surfaces and sunken number/details tiles across Accounting preview, Student Finance, Enrollment and receipt submission.
- Form behavior: configuration starts with empty bank/wallet lists; toggles create blank account fields. Existing saved and legacy destinations retain read compatibility. Account rows remain expanded; single rows have no reorder controls. Unsaved state is explicit; configured-account counts exclude unfinished rows. Bank guidance matches the existing 10–16-digit validation and preserves leading zeroes and spaces/hyphens.
- Validation: shared raw-input rules reject malformed groups, repeaters, field values, booleans and schedules before normalization; check text limits, timezone and duplicate account identities; align cutoff/holiday label limits. Existing provider-specific number checks, schedule ordering/date checks, office authorization and versioned save behavior remain active.
- Verification: `test_tala_db` configured and SQL-active identity checked before the test run. Four affected suites passed (94 tests / 1,467 assertions); following the final refinement, PaymentChannelsAndOnsitePaymentTest passed again (21 tests / 463 assertions). These runs overlap; they are not additive. Pint, Vite build and diff whitespace check passed. Impeccable Assessment B detector returned [] on all three shared Blade targets; independent Assessment A findings were resolved in the scoped changes.
- Browser: 390×844 and desktop captures, light/dark appearance, empty/newly-enabled digital accounts, configured unsaved synthetic preview, selected-Term Fee plan controls, native keyboard traversal and document overflow checked. No settings were saved during browser QA. Only Grammarly extension log entries were observed; no application console errors were identified in the checked tab.
- Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-refinement-2026-11-15/ (assessment-scope-desktop.jpg, assessment-scope-mobile.jpg, payment-channels-mobile.jpg, payment-channels-final-desktop.jpg, payment-channels-dark.jpg, fee-plans-scope-desktop.jpg).
- Owner review remains open. No commit, publication, Issue write, orchestrator report, merge or deployment was performed in this refinement.

### Owner correction — Payment channel outer surfaces and radii (15 November)
The owner rejected the sunken section headers blending into the canvas and painting beyond the outer radius. All three channel sections now retain the raised neutral surface in enabled/disabled states, transparent radius-inheriting headers, and sunken enabled editor bodies with inherited lower radii. No overflow clipping was added around form controls. Rendered checks covered disabled Bank/Wallet with enabled Cash, all-off state, light/dark and 390×844; computed light card/canvas colors were #fff/#f4f4f5 and dark #2b2d32/#151619. Header fill was transparent and its radius matched the 10px outer radius. No horizontal document overflow was observed. No settings were saved and the owner's original unsaved tab was retained. Styling-only correction; no PHP or behavior tests were changed. git diff --check passed.

### Consolidated Payment channels refinement — nested groups, previews and shared rules
Owner additions cover cashier preview width, native repeater/header painting, empty Bank/E-Wallet previews, weekly/special-hours panel contrast, and the save/reason footer. The shared How-to-pay component now splits Cashier details/calendar when its container is wide and preserves one-column reading order below that; status and instruction rows wrap compactly. Single-account wrappers have no extra shadow/padding/frame, and empty digital drafts use one explicit prompt with Details incomplete. Schedule subpanels are raised inside the inset cashier editor; day rows are inset with raised enabled controls. The save/reason footer is raised against the inset preview body. Native section/repeater upper/lower corner inheritance now lives once in tala-filament.css, imported by the shared panel theme. DESIGN.md Material layers records parent-relative neutral nesting while retaining native enabled/disabled/focus/status semantics; page-specific layout stays with its shared component.
Verification: PaymentChannelsAndOnsitePaymentTest 22 passed / 475 assertions (including empty-preview safety); HumanCenteredSharedThemeTest + SharedAccessPresentationTest 41 passed / 343 assertions; Pint passed; Vite build passed; diff check passed. Browser checks covered enabled bank/wallet repeaters, a draft holiday row, empty digital preview, Cashier preview and footer in both appearances, wide split and narrow stack (actual narrow CSS width 358px), with no horizontal document overflow. Repeater headers computed transparent with matching 10px parent radii; electronic single-account wrappers computed padding 0 and shadow none. No payment settings were saved. The owner's working tab remains preserved.
Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-channel-detail-refinement/ (cashier-wide.jpg, cashier-editor-surfaces.jpg, cashier-and-footer-final.jpg, previews-dark.jpg, previews-mobile.jpg). Owner review remains open; no external publication/reporting action occurred.


### Owner refinement — Accounting overview clarity (15 November)
The owner requested clearer wording, information hierarchy and layout within the established overview. Impeccable clarify/layout assessment found a repeated review aggregate, sequence numbering on independent destinations, and receipt/change figures labelled differently from the affected-account counts returned by the collection. The refinement retains the four shared cards and destinations, places each figure directly after its task name, states the deduplicated review total once, labels open term accounts without implying unpaid balances, and gives Submitted receipts copy that matches its Review receipts destination. All student accounts explains onsite recording and coverage; TOR guidance expands the abbreviation. Existing neutral materials, priority tint, shared spacing, native buttons and responsive columns remain. Reusable count/sequence guidance and the current overview contract were reconciled in DESIGN.md, the UI blueprint and existing brief. No image prototype was necessary for this scoped refinement.
Verification: AccountingWorkspaceTest and StudentAccountsWorkbenchAcceptanceTest passed together: 47 tests / 745 assertions. Tests cover the connected workspace and existing overview scope/count cases; copy assertions were updated. Pint passed after line-ending normalization; Vite build passed; final Impeccable layout detector returned []; git diff --check passed. Browser checks covered light/dark desktop and narrow layout (actual CSS width 358px), with readable single-column cards and no horizontal document overflow. No financial actions or configuration saves were submitted. Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-overview-refined-desktop.jpg, SIA-TALA-88-overview-refined-mobile.jpg and SIA-TALA-88-overview-refined-dark.jpg. This is scoped overview evidence, not a fresh whole-system acceptance claim. Owner review remains open; no commit, publication, Issue write, orchestrator return, merge or deployment occurred.


### Owner refinement — selected account hierarchy and shared controls (15 November)
Owner annotations rejected the large identity block, one-item Account & tasks cluster, congested review composition, excess scope height and plain collection headings. Impeccable distill/layout/clarify assessment identified competing balance emphasis above the task, repeated lifecycle guidance, and oversized label/control grouping. The selected account now reuses the compact record identity, full-pane Current work / History navigation (omitted when History is unavailable), task-first reading order, and a neutral inset financial context after the review. Embedded receipt comparison groups are sunken with raised fact rows; source facts, guarded actions and visible charge/payment-date records remain. Missing term accounts have one recovery warning, disabled review actions and concise review consequences.
Shared work: record-collection-controls owns the green section heading and reduced top inset in populated and empty collections. record-scope-bar uses inline persistent labels when the container is at least 18rem, with stacked control rows on narrow screens and native touch sizes preserved. Removed scope overflow clipping after the rendered dropdown check exposed clipped options; first/last child radius inheritance preserves the outer shape. The existing compact identity variant and new stretch navigation variant remain reusable. Current guidance was reconciled in DESIGN.md, the UI blueprint and the existing brief. No new visual world or image prototype was required.
Verification: final AccountingWorkspaceTest, StudentAccountsWorkbenchAcceptanceTest and LifecycleAccountingReviewTest passed: 66 tests / 832 assertions. HumanCenteredSharedThemeTest passed separately: 27 / 266. Total distinct final tests: 93 / 1,098. The first combined run exposed one stale assertion expecting a one-item switcher; it was updated to check its absence and the two-section case checks both destinations. Final Pint, Vite build, whitespace check and scoped Impeccable layout detector passed. Configured and SQL-active test database test_tala_db were verified before tests.
Rendered checks: receipt comparison, missing-account student changes, assessment empty collection, full-width History navigation, Fee plans three-field scope, unclipped searchable Term options, and light/dark material separation. At 358px actual CSS width, scope height decreased from about 199px to 137px; desktop scope is about 76px. The mobile and desktop account navigation matched the full selected-pane width; no horizontal document overflow was observed. Phone selection and History remained in the selected account. Existing financial actions were not submitted and settings were not saved. This is scoped visual evidence; RTL, localization and live screen-reader use were not separately tested.
Captures: C:/Users/HUAWEI/AppData/Local/Temp/ — SIA-TALA-88-receipt-workspace-refined.jpg, SIA-TALA-88-student-changes-refined.jpg, SIA-TALA-88-assessment-empty-refined.jpg, SIA-TALA-88-scope-menu-refined.jpg, SIA-TALA-88-account-workspace-mobile.jpg, SIA-TALA-88-account-workspace-dark.jpg, SIA-TALA-88-fee-scope-refined.jpg. The signed-in receipt workspace remains open. Owner review stays open; no commit, publication, Issue write, orchestrator return, merge or deployment occurred.


### Owner refinement — native filters in the collection search row (15 November)
The owner requested native filter options in the side panel, then explicitly placed Filters beside search rather than the collection heading. Verified installed filament/tables v5.9.0 and its native FiltersLayout enum, form, trigger, Apply and Reset APIs against official 5.x filter documentation and installed source. The reusable record-collection-filters component renders the existing table form inside Filament’s dropdown; record-collection-controls owns one search-and-filter row beneath the heading. All Student accounts collections, including empty and filtered states, use it. Term-first entry remains; the selected scope and outside-scope recovery stay visible in the panel. Fee plans retains its existing scope controls. Native filters apply live and native Reset remains available; no new query or filtering implementation was added.
Popup corrections: native filter styling normally depends on a table ancestor, so the reusable record composition supplies the missing padding/header layout from shared tokens. It sizes the native popup to the collection’s available width, with a native-width cap; the collection permits overlay overflow and keeps list scrolling. No teleport is used. The final popup and nested Term options were visible; live selection produced No matching accounts with hidden-work recovery and native Reset restored the unfiltered collection. Zero filter badges are omitted. Mobile popup bounds were within the viewport (about x27–332 at actual width 358px); document width equalled viewport width. Search retains the accessible Name or student number label and uses the compact Name or number placeholder.
Split-pane action groups now align to the trailing edge through shared record-action selectors. Receipt preview uses the existing record-actions group; verification/rejection, onsite/coverage, other grouped review actions and wrapped statement actions share this rule. Native navigation, inline table actions and modal confirm/cancel semantics are preserved. Rendered measurements confirmed View receipt and the last review/onsite buttons share their group’s right edge; keyboard Tab reached Verify payment without activation. No financial action was submitted.
Verification: AccountingWorkspaceTest, StudentAccountsWorkbenchAcceptanceTest and HumanCenteredSharedThemeTest passed together after the shared search-row change: 74 tests / 1,014 assertions. Configured and SQL-active test_tala_db were verified before tests. Final Vite build, Pint, whitespace check and scoped Impeccable layout detector passed. Existing tests continue covering filter/selection changes, term gates, hidden pending work, recovery and connected tasks. No new behavior tests were needed for the final placeholder-only edit.
Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-native-filter-row.jpg, SIA-TALA-88-native-filter-row-mobile.jpg and SIA-TALA-88-trailing-review-actions.jpg. Current design guidance and existing brief were updated. This remains local implementation for owner review; no commit, publication, Issue write, orchestrator report, merge or deployment occurred.


### Owner correction — nested filter selects, scope and mobile trigger (15 November)
The owner flagged the nested Term options being constrained inside the filter popup, the orphan All terms line, and the bare mobile funnel. The shared theme previously forced overflow-y:auto on every filter/column-manager popup. Height limiting and scrolling now apply only to Filament’s explicit fi-scrollable mode, retaining native scrolling inside long option lists. The short record filter popup computes overflow:visible and max-height:none; expanded Term options visibly float beyond its edge without an outer scrollbar. This shared theme correction serves native filter and column-manager popups throughout the panel.
The record-collection-filters composition uses the established native labeledFrom(null) convention, retaining the labelled button at every width. Shared collection search/filter controls have a 44px minimum narrow-screen height. Browser measurement found the mobile trigger about 92×44px. Default All terms copy is omitted; selected Term/Program scope uses compact native gray badges in populated and empty collections. Removed unused scope-caption calculations. Existing hidden-work notices and native filter/Reset behavior remain.
Verification: AccountingWorkspaceTest, StudentAccountsWorkbenchAcceptanceTest and HumanCenteredSharedThemeTest passed: 74 tests / 1,015 assertions, including an assertion that the unfiltered view has no scope-caption region while the account collection remains available. Configured and SQL-active test_tala_db were verified before testing. Pint, Vite build, whitespace check and scoped Impeccable layout detector passed. Browser checks covered expanded native Term options, selected-Term scope badge and mobile labelled controls at 358px actual CSS width. The original unfiltered selected account History URL and default viewport were restored. No financial action or setting save was submitted.
Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-filter-nested-corrected.jpg, SIA-TALA-88-filter-mobile-corrected.jpg and SIA-TALA-88-filter-controls-corrected-desktop.jpg. Shared guidance and existing brief were reconciled. Owner review remains open; no commit, publication, Issue write, orchestrator report, merge or deployment occurred.


### Owner shared rule — adaptive list/detail navigation and popup-host audit (15 November)
The owner approved the narrow-screen collection/detail behavior as a system rule, requested a full-width return control and clearer wording, and asked for a broad popup-clipping audit. The shared record-workspace now labels its return action Show [collection], fills the content width with a 44px minimum height, and applies one-pane list/detail navigation to both narrow viewports and main containers below 54rem. Selection focus follows the actual visibility of the return control instead of a viewport-only assumption. Returning focuses the collection without clearing selected record, query/filter state or form state. Wide containers retain two panes and hide the return control.
Source inventory found seven shared workspace uses: Accounting accounts; lifecycle changes; released course results; INC/correction results; faculty roster student entry; completion students; TOR requests. All use the same component. Other selection candidates were Term gates, academic filter controls, payment-calendar selection and timetable/resource editors rather than separate list/detail implementations; those retain their task-specific layout. DESIGN.md, the UI blueprint and existing surface brief record the shared rule.
Popup source audit covered all four owned native dropdown roots (record collection filters, Term options, INC filters and lifecycle More filters), custom menu/listbox/x-float candidates (none found), shared native filter/column-manager CSS, directory side filters, empty collections and table-backed workspace list panes. Removed clipping/scroll-host rules at the identified collection and directory filter ancestors; overlay-capable record browsers and empty collections preserve visible overflow and inherited corner radii. Result rows and long native option lists retain their own scrolling. Installed Filament table record-action groups already default to dropdown teleport, which remains intact. Informational media/identity clipping is retained.
Verification: six affected cross-role suites passed together: HumanCenteredSharedThemeTest, AccountingWorkspaceTest, TAL89AGradesAcceptanceTest, TAL89CIncResolutionCorrectionTest, CompletionTorCurrentContractTest and StudentLifecycleChangeTest — 176 tests / 1,902 assertions. Configured and SQL-active test_tala_db were verified before the run. The final CSS-only removal of the redundant Accounting empty-host clipping and table-backed browser clipping was followed by a successful Vite build and rendered empty-popup check; the earlier behavioral tests remain applicable. Final whitespace check and scoped Impeccable layout detector passed.
Browser evidence: actual mobile CSS width 448px; Show student accounts button and content each measured 373.33px, with no horizontal document overflow. Return moved focus to tala-record-browser, kept the same selected account and showed only the collection. At actual viewport 917px the narrow container showed one pane; selecting focused accounting-account-inspector and the return button matched its 818.74px content width. At restored default 1113px, both panes displayed (about 287px/669px) and the return control was hidden. In the empty assessment collection, the filter host and popup both computed overflow:visible, and the expanded Term options visibly extended beyond the popup without an outer clipping scrollbar.
Captures: C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-shared-split-return-mobile.jpg and SIA-TALA-88-empty-filter-host-audit.jpg. A separate authenticated review tab was used; the owner’s Payment channels tab was preserved. The selected account History review tab remains available at the default viewport. No payment, setting, academic outcome or review decision was submitted.
Audit limits: no additional owned list/detail implementation or unexplained clipping rule was found in the inspected popup hosts. This source inventory and representative live checks do not certify every dynamic modal/action/plugin combination as clipping-free, nor claim a live browser visit to every role. Any further runtime finding remains actionable. Owner review remains open; no commit, publication, Issue write, orchestrator report, merge or deployment occurred.

### Owner refinement — payment clarity and document companions (15 November)

Implemented in the Issue #88 review worktree, owner review remains open:
- Removed Channels / Digital accounts / Configuration metrics. Initial settings are configuration provenance, not a preferred payment method. The summary now names methods shown to students/applicants, explains unsaved changes, and states the standard campus cashier fallback when every method is off. A real saved timestamp remains secondary. Removed the unused count projection and duplicate PHPDoc; payment validation and fallback behavior are unchanged.
- Added shared `sticky-companion` with a resize observer for live rail height. At the existing two-column breakpoint, short previews pin near the top; tall previews scroll naturally before the lower edge pins. Removed the minimum-window-height restriction and nested preview scrollbar. Stacked layouts remain ordinary document flow.
- Added optional `record-workspace` document scrolling and adopted it across Accounting's populated account task/History views. The collection stays bounded and sticky only in the wide layout. Existing consumers keep the default pane variant and compact return/focus behavior.
- Updated DESIGN, the UI blueprint and the connected Enrollment/Accounting brief with the shared adoption rules.

Evidence: Payment channels desktop rendered at a roughly 1058x666 requested browser size (the host resized/scaled during review). Confirmed plain summary, unsaved/all-off explanation, live rail-height updates, short rail top inset 12px, tall rail lower edge at viewport minus 12px, visible Save control, and zero internal `overflow-y:auto` regions in the preview. No save was submitted during browser inspection. Screenshot: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-sticky-payment-preview.jpg`. Subsequent mobile/account rendered checks remain unverified after the browser connection was lost.

Validation: build passed; affected PHP syntax passed; Pint passed after formatting; layout detector returned []; git diff --check passed. Logs: `storage/logs/issue88-sticky-companion-build.log`, `issue88-sticky-companion-pint-final.log`, `issue88-sticky-companion-views.log`. The first combined test attempt hit system paging-file exhaustion and an obsolete removed-metric assertion, which has been corrected. A focused rerun could not execute either test: MySQL84 is stopped (connection refused); Start-Service was denied by Windows service permissions. `issue88-payment-summary-tests.log` records 2 errors / 0 assertions, not a passing result. Database-dependent tests and remaining live account/mobile verification must be rerun after MySQL84 is started. Earlier evidence does not establish these latest changes as fully verified.

Additional verification limit: view:cache produced no result while the service was unavailable and was stopped; it is not counted as passing. Browser tooling became unavailable before viewport reset and draft cleanup could be confirmed. No browser Save was submitted.

### Resumed verification — Codex in-app browser only (15 November)

Supersedes the environment blocker above for this refinement. MySQL84 is running. Fresh configured/active database checks matched `test_tala_db` for tests and `test_tala_db_issue88_preview` for the server. Restarted the preview server in a hidden background process. The interrupted suite had no completion result; it was rerun to completion.

- Focused payment summary/default/blank-destination cases: 2 passed, 60 assertions (`storage/logs/issue88-payment-summary-tests-resumed.log`).
- PaymentChannelsAndOnsitePaymentTest, AccountingWorkspaceTest, HumanCenteredSharedThemeTest: 64 passed, 923 assertions, 79.975 seconds (`storage/logs/issue88-sticky-companion-tests-resumed.log`).
- The separate CLI browser was closed at the owner's direction. All subsequent UI verification used Codex's in-app browser through its native Browser service. Existing credentials and existing TOTP were used for normal Accounting sign-in; authentication configuration was unchanged.
- Codex desktop: plain method summary, sticky preview, zero nested auto-scroll preview regions, no horizontal document overflow. On scrolling, the tall preview's lower edge pinned about 12px above the viewport edge and Save stayed visible (44px high).
- Account Current work: document mode active, detail maximum height none, inspector visible overflow with equal client/scroll heights; page scrolling moved the collection to the shared 12px sticky inset.
- History opened in the same selected account. At 448x667 actual CSS viewport, the full-width Show student accounts button measured 373px within a 405px inspector with 16px inline padding. It returned to the collection with Mikaela Reyes still selected and History context preserved. No horizontal overflow.
- Mobile Filters: labelled button visible, popup and collection overflow visible, panel inside the viewport. Nested native Term options remain independently scrollable; no custom clipping scrollport was introduced.
- Restored the default viewport and left Payment channels authenticated in Codex. Browser console warning/error log was empty for the inspected session. No payment, setting save, financial review or academic decision was submitted.

Captures: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-codex-payment-review.jpg`, `SIA-TALA-88-codex-sticky-payment.jpg`, `SIA-TALA-88-codex-mobile-history.jpg`. Browser/API emulation is representative desktop/mobile coverage, not a visit to every role or dynamic state. Owner hands-on review and the broader Issue #88 reconciliation remain open; no publication or orchestrator return is implied.

### Owner correction — stable sticky heading and scrollable body (15 November)

The owner rejected height-dependent bottom pinning. This supersedes the earlier sticky-companion design and its visual acceptance claims. Root cause was the shared height observer feeding a negative top offset for tall content; enabling Cash made the preview taller and moved its sticky anchor into the card body.

Removed the observer and `--tala-companion-height`. Shared sticky companions now use a constant positive top inset. Shared `tala-companion-card` defines natural header/footer rows and a shrinkable middle `tala-companion-body`, constrained by the available viewport only in the two-column layout. Payment preview adopts these classes and a labelled, focusable body scroll region. Its heading and save/reason footer remain visible. Stacked layouts remove the viewport cap. Source tracing found one sticky-companion consumer; existing record-workspace sticky collections already retain a separately scrollable record list, and pane-mode consumers retain their internal scrolling.

Codex in-app browser verification: Cash enabled produced a 392px body with 799px content and internal keyboard scrolling; heading settled at approximately 12.76px and Save remained visible. Cash off / Bank Transfer and E-Wallets on produced the same 12px inset and heading top, with a naturally sized 289px body. Mobile actual CSS viewport 448x667 had static positioning, max-height none, equal body client/scroll height and no horizontal overflow. Default viewport and saved state restored; no Save or financial action submitted; console warnings/errors empty. Capture: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-codex-sticky-scroll-body.jpg`.

Build passed (`storage/logs/issue88-sticky-body-correction-build.log`), layout detector [], git diff --check passed. This correction changes Blade composition and CSS only; the preceding 64-test/923-assertion PHP result is retained as earlier logic evidence, not represented as a new run for this correction. Shared DESIGN, UI blueprint and connected brief now require stable top pinning plus body scrolling. Owner review remains open.

### Owner consistency correction — Fee Plans filters and neutral actions (15 November)

Fee Plans had deliberately hidden its native Filters trigger and indicators, rendered Term/Program/Status in a separate scope form, and used outlined gray scope-navigation actions. The owner asked for search-adjacent filtering and a single understandable neutral action treatment.

Restored Filament 5.9.0's native table Filters dropdown beside search, with an always-labelled gray trigger and active filter indicators. Removed the duplicate selector rendering and CSS that hid the native controls. The independent outside-scope draft notice renders only when drafts outside the current view exist; its recovery remains. Entry Term gates, query/filter state, native reset, pagination and guarded publishing remain unchanged.

Removed outlined styling from matching Accounting scope actions in Fee Plans and Student Accounts. Shared Filament theme rules now give neutral actions one filled material in light/dark appearances, including legacy outlined gray calls. This is a hierarchy choice, not an Accounting state: primary brand and semantic destructive/status actions retain their own meanings. Updated DESIGN, blueprint and connected brief.

Verification: build passed (`storage/logs/issue88-fee-filter-build.log`), Pint passed after formatting (`issue88-fee-filter-pint-final.log`), layout detector [], git diff --check passed. The combined affected run exercised 67 tests: 66 passed; one obsolete assertion for the removed scope-row title failed. Removed that presentation-specific assertion and updated the outside-draft notice label; the failed gate/filter-reset test then passed (1 test, 83 assertions, `issue88-fee-filter-retest.log`). The unchanged results from the combined run remain in `issue88-fee-filter-tests.log`; no fresh all-green combined run is claimed.

Codex in-app browser: default/filtered Fee Plans had Filters beside search and no standalone selector row; Status Draft yielded one record with visible Term/Status indicators. A search miss retained Filters and native recovery. Mobile actual 448x667 kept the labelled 44px controls and semantic stacked records with no horizontal overflow. Native dropdown overflow remained visible; ordinary page scrolling brought its lower fields into view (popup bottom ~390px within 667px viewport). No custom popup scroll/clipping wrapper was added. Neutral header and row buttons use the same shared material. Console warnings/errors empty. Screenshot: `C:/Users/HUAWEI/AppData/Local/Temp/SIA-TALA-88-codex-fee-filters.jpg`. Temporary QA tab closed, default viewport restored, owner's Fee Plan detail page preserved. No draft/save/publication or financial action submitted. Owner review remains open.
