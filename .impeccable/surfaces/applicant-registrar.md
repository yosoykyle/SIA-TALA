---
version: 1
slug: "applicant-registrar"
primary_target: "applicant-registrar"
related_targets: ["app/Filament/Applicant/Pages/Dashboard.php","app/Filament/Applicant/Pages/Application.php","app/Filament/Applicant/Pages/Requirements.php","app/Filament/Resources/AdmissionApplications/AdmissionApplicationResource.php","app/Filament/Resources/AdmissionApplications/Pages/ListAdmissionApplications.php","app/Filament/Resources/AdmissionApplications/Pages/ViewAdmissionApplication.php"]
---

# Surface Brief: Applicant–Registrar Journey — #57

<!-- impeccable:surface-brief 1 -->

## Job, audience, and mode

`Operate`. Applicants must understand the current application and take the permitted next action. Registrar staff must find the right application, inspect private evidence, request a scoped correction, record an attributable decision, and record one enrollment clearance after external school checks. The journey ends at derived enrollment readiness.

## Outcome and authority

Use the current [#57 contract](https://github.com/yosoykyle/SIA-TALA/issues/57), [#48 delivery map](https://github.com/yosoykyle/SIA-TALA/issues/48), PRD 02, the UI Surface Blueprint, PRODUCT.md, DESIGN.md, and Architecture. This brief records their settled direction; it introduces no new policy, capabilities, delivery date, or implementation authorization. Issue bodies own current task scope and status; older comments remain attributable history.

## Selected direction

- **Shared shell:** One compact school-identity area leads. Role/workspace context identifies the current service separately; Powered by TALA occupies a quiet footer. Page headings describe the task or person, with application references secondary. Preserve approved artwork, readable branding, theme controls and complete authorized navigation.
- **APP-001 / Home:** Current situation, responsible office, relevant deadline and permitted next step or waiting condition lead. Show the preliminary review, admission decision and Registrar clearance as distinct concise facts. Compact application context follows; history and acknowledgments remain reachable. Information needed to understand the current state stays visible.
- **APP-002 / Application:** Preserve the accepted five-step journey and adopted minimum intake in PRD 02 Section 10. Group related inputs, show understandable progress and keep Save draft, validation and submission truthful. Place each instruction with the field or action it explains. Correction mode reopens only named fields/files.
- **APP-003 / Requirements and APP-004 / acknowledgment:** Users recognize the selected private preliminary copies, current review result, external paper instructions, Registrar clearance and version-bound acknowledgment. The exact authorized file is easy to open at the review step. Current evidence and relevant instructions remain visible; superseded files and historical outputs are reachable in their own context.
- **REG-A01 / Admissions:** The accepted five operational groups support triage. Staff recognize the applicant, state, responsible party and next action before technical references. Search, filters, counts, deadlines and activity retain their meaning across desktop/mobile. Choose the supported composition by scanability and review efficiency.
- **REG-A02 / Applicant Record:** The person/task heading, current situation and permitted action lead. Keep the evidence needed for the decision visible with its review controls, followed by supporting facts and attributable history. Preserve contextual setup and assisted drafts; the Applicant owns final submission.

## Visual and component judgment

Inherit green-led actions, supporting blue, neutral light/dark native surfaces, Inter, Heroicons, school-first artwork, and secondary Powered by TALA from DESIGN.md. Use native sidebar/mobile drawer behavior and ordinary scrolling. The layout reference supplies supporting visual evidence.

Impeccable leads hierarchy, copy, cognitive-load and composition assessment. Relevant skills address the actual problem. Use Laravel Boost, the official Filament documentation and demo to research the relevant range of capabilities, styling and extension points for the installed version; Serena traces existing behavior. Compare supported implementations against the user task and use focused customization within the existing stack when it improves the outcome. Preserve framework validation, authorization, actions, focus and theme behavior. Rendered evidence must demonstrate the chosen improvement.

Apply the shared setup and unavailable-action contract in baseline Section 3.2 and the UI Blueprint. Preserve a visible safe reason, owner and remedy for authorized blocked work. Use Impeccable-led task composition and the accepted five-step plan.

## Scope and stable concern mapping

| Source concern | Owning surface / criterion |
| --- | --- |
| F14–F17, F22–F23, M07 | Applicant identity, navigation, Home hierarchy, and meaningful states; APP-001, AC3/AC8/AC9 |
| F18–F19 | Draft/save placement, Wizard progress, validation, and hierarchy; APP-002, AC5/AC8/AC9 |
| S04 | Correct inspectable private evidence beside review; APP-003, REG-A02, AC2/AC4 |
| F32–F35, M03–M04 | Admissions triage, density, contextual setup/assisted entry, and next actions; REG-A01/REG-A02, AC3/AC6/AC8/AC9 |
| F24–F25, S11 | Recovery states reached by this journey; AC8/AC9 |
| S10, S12, M02 | Purposeful intake, explicit LRN availability, conditional contacts, field-specific date/year validation and understandable labels; APP-002, AC5/AC8/AC9 |

This mapping does not mark any concern resolved. F20–F21 and other authentication/account/role surfaces retain their owning scope; downstream scheduling, enrollment/finance/COR, academic records, and public redesign remain outside #57. Shared changes receive proportionate regression checks.

## Latest client feedback — reconciled October 3, 2026

The owner endorses the October 2 client-meeting, client-QA and developer feedback as correct/current. Its complete source, existing-concern expansion and new S05–S13 are preserved in the [existing #48 register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932). Read #57's current feedback clarification with this brief. Older documents may need correction; use Impeccable and relevant skills to deliver the client's outcomes rather than defend obsolete assumptions.

- **Home:** Show factual journey progress and authoritative status, remarks and dates prominently. Start/Continue or another primary action follows actual state and permission. Keep prior applications/acknowledgments compact and reachable rather than dominant. Waiting/rejected/closed states do not promise Continue.
- **Wizard:** Use plain task/step/field labels, responsive grouping, visible optional/required and verified-email cues, accessible date entry and honest save/continue wording. Preserve scoped correction and the five-step review/submission. S12 requires proof of field-accurate malformed/non-future graduation-year validation.
- **Evidence and location:** Make the permitted private file accessible beside review. Distinguish review-copy acceptance, admission, Registrar enrollment clearance and readiness, with source-backed instructions/dates. Use meaningful page/breadcrumb labels while keeping the application reference visible.
- **Recovery:** S11 records the fatal timeout/path-disclosure experience. Investigate the reached failure; an execution-limit exception is not automatically session expiry. Retain truthful response, authorized recovery and safe mutation handling.

The single-sign-in direction is clarified in the local identity PRD and UI Blueprint. On October 3 the owner delegated minimum field and feature decisions across the connected system. PRD 02 Sections 8-10 now adopt required identity/contact, city/province, truthful prior-school/attainment/year and application choice; optional bounded address details, sex and civil status; one required guardian contact for minors and an optional complete emergency-contact group for adults; an explicit no-LRN route and no arbitrary minimum admission age. The selected PSA copy and 2x2 photo are the required baseline digital uploads. Privacy-notice acknowledgement precedes the first private upload; optional sex/civil-status consent and final accuracy/submit acknowledgement remain separate under PRDs 01/02. The optional group obtains its specific consent before transmitting or saving either value, including reactive updates and Draft saves; an unchecked group sends neither value. Clearing that choice before submission clears current Draft values through the existing save action and preserves submitted history. Declining those optional facts preserves the ordinary application path. All evidence actions verify exact record/version/path ownership under PRD 00. Cancellation stops new starts/first submissions while existing cases resolve under their own authority; changed clearance after official enrollment routes a Registrar discrepancy without reversing enrollment. Other paper documents are handled outside TALA. The client workflow supports PSA/photo requirements; the COR sample is output evidence, and the TESDA handbook does not establish college age rules.

One append-only Cleared / Action needed Registrar enrollment-clearance result is bound to the current submitted application and admission decision, with actor/time, a safe recovery instruction when needed and an attributable correction history. Missing or stale clearance is never treated as Cleared. Legacy individual credential records remain historical evidence; they cannot silently fabricate the new result. PRD 04 consumes the same derived readiness and revalidates it before finalization. Reuse the accepted plan and five-step journey; no duplicate planning or adoption gate remains. No paper custody, individual physical-document checklist or office workflow is added.

The baseline and PRODUCT.md now state the minimum outcomes retained in every journey, including public school discovery and access. The Blueprint defines information and interaction outcomes across the system; DESIGN.md and the matching briefs govern researched composition, and actual rendered acceptance belongs to each owning child. PRDs 04/06 define registration/payment order, Accounting-owned versioned amounts and external refunds with verified local corrections. Exact published amounts and requirements are operational source data. The refund-evidence sequencing finding belongs to the coordinated finance journey and does not expand #57. This brief grants no execution assignment.

## States, interaction, and proof

Cover representative draft, submitted, named correction, resubmission, admitted-with-blockers, ready, terminal history, denied, stale, loading, empty, and failed states. Preserve authorization, deadlines, current evidence, immutable history, source-bound emails, acknowledgment printing, keyboard/focus, announcements, entered data, theme preferences, and motion/accessibility safeguards.

Before executing checks, match required outcomes to tool capability. Use direct rendered observations for visual outcomes and focused automated behavior tests for integrity/event/concurrency boundaries. Label emulated states and documented native behavior accurately. An unavailable automation control is not permission to fabricate evidence, weaken the product requirement, or repeat the same failed method.

Use one batched desktop/mobile inspection, one bounded correction pass, and one confirmation pass for the demonstrated UI problems. Return source concern → component decision → rendered change → criterion evidence. All owning criteria must be Verified before acceptance; unresolved design or behavior remains unfinished. Implementation, completion, publication, and later work each retain their owner-selected boundaries.
