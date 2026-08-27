# TALA Experience and Official-Output Design Definition — Human-Centered Operations

## Authority and scope

**Status:** Approved design-definition companion for successor-cycle planning and implementation.

This document records the approved Human-Centered Operations direction, its ethical behavioral-design rules, its production-stack translation, and the complete successor coordination map. It refines presentation without creating business workflows. The canonical PRDs continue to own product behavior, the [UI Surface Blueprint](ui_surface_blueprint.md) owns UI IDs and presentation contracts, and the [Architecture Specification](architecture_specification.md) owns integration and deployment boundaries. If this companion conflicts with those documents, the canonical owner governs and the conflict must be corrected before implementation.

The approved React prototype and its browser captures are visual and interaction evidence only. The curated [Human-Centered Operations design-evidence pack](design-evidence/human-centered-operations/README.md) makes the accepted comparison evidence portable without adding the React source. Production TALA remains Blade, Bootstrap, Filament, Livewire, Alpine, and print CSS. No React runtime, generic design system product, behavioral engine, or duplicate business logic is introduced.

## 1. Product and visual direction

- Servitech Institute Asia is the institution and issuer; TALA is its academic lifecycle and administration product.
- Preserve recognizable institutional branding, a calm blue-led palette, learner-friendly guidance, efficient Staff workbenches, restrained semantic status colors, and formal monochrome-capable outputs.
- Preserve the one-page Public Gateway, full-color institution crest and TALA mark, progressive-blur navigation and footer strip, compact mobile controls, factual Program projection, in-page FAQ, location/map context, approved hero-media boundary, and contextual sign-in.
- Avoid yellow side-strip selections, loud mobile controls, architecture-facing routine copy, excessive card repetition, ornamental dashboard statistics, and indiscriminate disclosure of all available information.
- Use familiar, task-oriented language. Prototype assumptions remain hypotheses until representative Applicants, Students, Faculty, and Staff validate them.

## 2. Ethical behavioral-design standard

Behavioral principles may reduce confusion, preserve genuine work, communicate truthful progress, clarify real consequences, or improve decision hierarchy. They may never fabricate progress, preselect consent, hide alternatives, create artificial scarcity or urgency, use fear or shame, or undermine informed choice and institutional accuracy.

| Principle | Classification | Accepted use | Safeguard |
|---|---|---|---|
| Smart defaults | Beneficial with an explicit rule | Default to an unambiguous authorized workspace, current exact Term, current record, or role-scoped actionable queue when the source proves it | Show the selected context and allow easy change. Never preselect consent, publication, payment, release, access change, or another consequential decision |
| Goal-gradient effect | Bounded journeys only | Show truthful completed, current, and remaining stages for fixed workflows such as the Application Wizard, registration readiness, completion review, and TOR requests | Derive every stage from canonical persisted state. No invented percentages, bonus progress, artificial milestones, or universal cross-clinic progress bar |
| Reciprocity | Unsuitable as persuasion | Provide requirements, explanations, support, and previews before requesting action as ordinary service design | Never imply that a user owes data, consent, payment, or action because TALA provided help |
| Endowment effect | Bounded journeys only | Make genuine saved drafts, submitted evidence, immutable records, and recoverable work visible | Never fabricate ownership, advancement, rewards, badges, or earned-state language. Saved status requires persisted evidence |
| Loss aversion | Factual safety warnings only | Explain the real consequence of abandoning unsaved work, missing an authorized deadline, superseding a version, voiding an output, or changing access | State the exact consequence, source, date, owner, and recovery path. No fear, shame, artificial scarcity, countdown pressure, or exaggeration |
| Contrast effect | Already part of the design system | Distinguish primary and secondary actions, current and proposed state, official and unofficial output, available and blocked status, and ordinary and destructive actions | No decoy choices, misleading baselines, hidden alternatives, or color-only meaning |

Visual contrast remains an accessibility requirement, not permission for psychological manipulation. Text, controls, focus, and status must satisfy [WCAG text contrast](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) and [non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html).

## 3. Journey and UI application

| Experience | UI IDs | Accepted application | Required safeguards |
|---|---|---|---|
| Public Gateway | `SHR-001` | Current admission state, factual Programs, one-page FAQ/support/location, clearly ranked Apply and Sign-in paths | No silently selected role, artificial urgency, or unavailable-Program marketing |
| Authentication and recovery | `SHR-002`, `SHR-003`, `SHR-006` | Contextual sign-in, explicit verification/recovery status, native autofill, one valid recovery action | Privacy acknowledgement and Remember device remain active choices; generic errors do not disclose accounts or roles |
| Applicant | `APP-001–APP-004` | Resume the last valid incomplete Application step; show `Step x of 5`, saved state, owner, deadline, and next action | Progress comes from persisted Application state; blocked/correction states replace false forward progress; withdrawal and submission require factual confirmation |
| Student | `STU-001–STU-006` | Default to a unique current Term and highest-priority safe action; progressively disclose enrollment, academics, finance, profile, and output state | Term and source remain visible and changeable; no automatic course, payment, output, or correction decision |
| Faculty | `FAC-001–FAC-003` | Open a current assigned Term/class when unambiguous; preserve roster drafts; show completion and submission readiness | Submission and result changes require active confirmation; no fake completion percentage for invalid, returned, or unavailable rows |
| Registrar and Academic Head | `REG-A01–A03`, `REG-C01`, `REG-T01–T06`, `REG-E01–E04`, `REG-G01–G06`, `AHD-001` | Role-scoped actionable queues, current/proposed comparisons, impact previews, and source-based readiness | Filters stay visible and clearable; publish, decision, correction, release, enrollment, conferral, and issuance are never defaulted; alternatives remain visible |
| Accounting | `ACC-001–ACC-008` | Default to the exact current Term/account context; show current due and named obligations; preserve Draft Fee Plans and evidence | Never preselect coverage, clearance, payment method, verification, posting, or reversal; warnings use exact amounts and consequences without pressure |
| System Administration | `SYS-001–SYS-004` | Prioritize genuine action-needed evidence, plain next steps, and progressively disclosed technical detail | Unknown remains unknown; local evidence never implies provider health; invitations, disablement, MFA reset, publication, and access changes remain explicit actions |
| Official outputs | `OUT-001–OUT-007` | Preview the current authorized version and distinguish official, operational, Draft, superseded, voided, and unofficial states | Preview never issues automatically; issue, void, and replace remain explicit; orientation, margins, repeated headers, and page numbers follow each document's information needs |

Responsive progress uses semantic lists and text labels rather than decorative progress bars. The current step uses `aria-current="step"` when appropriate. Loading, saved, error, and success changes use accessible status messaging without stealing focus.

## 4. Production-stack translation

| Family | Current canonical surface | Production owner | Preferred native capability | Bounded customization | Verification evidence |
|---|---|---|---|---|---|
| Public arrival and sign-in | `SHR-001–SHR-003`, `SHR-006` | Blade and Bootstrap | Navbar/dropdown, accordion, modal, responsive grid, server-rendered state | Progressive blur, dual-brand lockup, contextual state, tracked approved hero media | 360/390, 768, 1366; keyboard, focus return, reduced motion, forced colors, no horizontal overflow |
| Authenticated shell | All Applicant, Student, and Staff panels | Filament, Livewire, Alpine | Panel navigation, `sidebarCollapsibleOnDesktop()`, mobile drawer, groups, account menu | Shared TALA theme tokens and role-aware identity only | Role entry, collapse/drawer, 200% zoom, keyboard, screen reader, responsive hierarchy |
| Forms and guided journeys | Application, enrollment, roster, completion, finance, access actions | Filament Forms/Schemas and Livewire | Sections, Tabs, Action Groups, confirmations, and Wizard only for truly chronological work | Source-backed progress, saved/dirty status, impact comparison, concise recovery copy | Positive, validation, stale/concurrent, correction/recovery, and authorization cases |
| Dynamic presentation | Loading, saved, failure, filters, disclosure | Livewire and bounded Alpine | Server-backed validation/loading/dirty state; native disclosure and menu behavior | Accessible status announcements and progressive disclosure | No fake client-only completion state; focus and status-message checks |
| Operational assurance | `SYS-003`, `SYS-004` | Filament and Livewire | Native tables, filters, Sections, Tabs, Infolists, Actions | Plain-language summary with technical evidence behind disclosure | Unknown/degraded/stale/missing evidence, safe next action, no provider overclaim |
| Official and operational outputs | `OUT-001–OUT-007` and `FAC-003` roster | Blade and shared print CSS | Server-rendered authorized view and browser print | Institution-first frame, document-specific orientation, 12 mm practical margins, repeated headers, automatic page numbers | Print preview, multipage behavior, status/version, no partial artifact, landscape/portrait contract |

Public state comes from authoritative server projections. No CMS, carousel builder, React runtime, generic marketing subsystem, or duplicated Program catalog is added. Alpine stays limited to local presentation behavior. Business rules remain in canonical actions, services, policies, and models rather than UI-only state.

## 5. Official-output system

- Application Acknowledgment, COR, TALA Standard TOR, Unofficial Student Record, Account Statement/SOA, and Payment Acknowledgment remain A4 portrait unless their owning information contract later proves otherwise.
- Published Timetable and current Class Roster use A4 landscape.
- Outputs use practical 12 mm print margins, repeating institution/identity and table headers, deliberate page breaks, and automatic page numbers for multiple pages.
- Official, unofficial, operational-reference, Draft, superseded, voided, and replacement states remain explicit in text and do not depend on color.
- The institution is the issuer. A restrained `Generated through TALA` footer may identify the product.
- Preview never issues an output. A stale, inaccessible, or failed source creates no partial or official-looking artifact.

## 6. Current implementation disposition

- **Reuse:** canonical services, policies, projections, Application Wizard, FAQ records, one-page landing foundation, Filament actions/tables/forms, role authorization, and the shared official-output component.
- **Replace or simplify:** panel theme and shell presentation, oversized or untracked authentication backgrounds, architecture-facing routine copy, inconsistent role navigation, repeated standalone output CSS, and pages that expose all evidence before the user needs it.
- **Temporarily retain with a removal trigger:** `AcademicReadiness`, `AcademicApprovals`, `ClassPlanning`, and `CompletionAndTor` until their unique canonical behavior is absorbed into the approved workbenches and reference tests prove safe retirement.
- **Delete after reference proof:** the unreachable Billing Slip controller/view, superseded presentation-only fixtures, unused oversized branding copies, and replaced runtime authentication assets. Migrations, attributable audit/history records, and completed research evidence remain preserved.
- **Implementation correction:** add the missing canonical `PublicNotice` management and public projection beside the existing FAQ capability.
- **Prototype disposition:** preserve the React prototype outside the repository, keep only the curated approved direction and QA captures in the [tracked evidence pack](design-evidence/human-centered-operations/README.md), and never copy its React implementation into TALA.

## 7. Targeted authority corrections

This definition is implemented through the corresponding targeted changes in the UI Surface Blueprint, PRD 01, PRD 05, PRD 06, and Architecture Specification. PRDs 02–04 require no behavioral-policy rewrite because their canonical state machines, confirmations, recovery paths, and ownership already support this experience.

## 8. Successor coordination map

**Coordination title:** Human-Centered TALA experience, official outputs, and lean legacy retirement

The complete successor cycle has seven journey-preserving slices:

1. **Public arrival → contextual secure access** — shared brand/theme primitives, ethical defaults, the one-page public experience, authentication shell, tracked assets, `PublicNotice`, and responsive navigation.
2. **Applicant application → admission outcome** — truthful Wizard progress, saved-state presentation, correction recovery, requirements, acknowledgment, and Applicant workspace refinement.
3. **Academic authority → published timetable** — Registrar/Faculty workbenches, exact-Term defaults, impact comparison, candidate correction, timetable publication, and landscape output.
4. **Registration and account readiness → Official Enrollment and COR** — enrollment checkpoints, assessment/payment handoffs, explicit consequences, Student guidance, and COR presentation.
5. **Official roster → released Student Academics** — Faculty roster work, release/correction decisions, INC recovery, Student Academics, landscape roster, and unofficial record.
6. **Completion readiness → immutable conferral and TOR** — completion guidance, clearance presentation, conferral confirmation, TOR preview/issuance, and version history.
7. **Operational evidence → clear assurance and integrated experience** — System Health/Governance language, safe legacy retirement, shared-shell consistency across roles, and final integrated UX/codebase audit.

Slice 1 is a true dependency only for shared theme, navigation, branding, and reusable behavioral-presentation primitives. Remaining order is planning guidance; a later slice is blocked only by a proven shared implementation dependency. Cleanup belongs to the slice that replaces the affected surface. Slice 7 verifies retirement and integration; it is not a dumping ground for unresolved behavior.

## 9. Successor acceptance contract

Every successor implementation Issue must prove:

- no consent, payment, publication, release, issuance, access change, or destructive choice is preselected;
- every default has a visible authoritative basis and reversible alternative;
- progress matches persisted canonical state, and saved/unsaved state is factual;
- warnings state the real consequence, owner, date/source, and recovery path;
- alternatives remain visible without decoys or coercive hierarchy;
- status and progress do not depend on color, motion, or position alone;
- role journeys work at 360/390, 768×1024, and 1366×768, at 200% zoom, keyboard-only, forced colors, and reduced motion;
- screen readers receive meaningful headings, step/status changes, errors, and recovery actions;
- official outputs satisfy orientation, margins, repeating identity/table headers, automatic page numbering, print preview, and multi-page behavior;
- fresh production-stack screenshots are compared with the accepted [prototype evidence pack](design-evidence/human-centered-operations/README.md);
- focused tests and the full PHPUnit suite pass; and
- every Issue criterion is individually `Verified` before publication.

## 10. Research basis and safeguards

The accepted rules use behavioral research cautiously rather than assuming every influence technique belongs in an institutional system. Defaults can guide choices without awareness; goal proximity can motivate action; and transparent choice architecture better preserves autonomy. These findings support explicit, reversible defaults, truthful source-backed progress, and the prohibition on coercive or fabricated presentation.

- [Default-choice research](https://business.columbia.edu/faculty/research/choice-without-awareness-ethical-and-policy-implications-defaults)
- [Goal-gradient research](https://home.uchicago.edu/ourminsky/Goal-Gradient_Illusionary_Goal_Progress.pdf)
- [Choice-architecture transparency research](https://www.sciencedirect.com/science/article/pii/S2214804325000175)
- [GOV.UK user-needs guidance](https://www.gov.uk/service-manual/user-research/start-by-learning-user-needs)
- [GOV.UK form-structure guidance](https://www.gov.uk/service-manual/design/form-structure)
- [WCAG status-message guidance](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html)
