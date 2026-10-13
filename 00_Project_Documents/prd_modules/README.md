# TALA Product Requirements

Baseline 00 and PRDs 01–06 define required behavior, data, office decisions, safeguards and outputs. Numbering follows product domains; delivery order/progress belong to [#48](https://github.com/yosoykyle/SIA-TALA/issues/48).

## Choose the contract

1. [00 — System Definition Baseline](00_system_definition_baseline.md): goal, roles, vocabulary, shared rules, evidence model, coordinated demonstration and handoffs.
2. [01 — Identity, access and public entry](01_identity_access_public_entry.md): accounts, verification/recovery, roles, entry and public content.
3. [02 — Application, admission and enrollment readiness](02_application_admission_decision_enrollment_readiness.md): intake, evidence, correction, decision and Registrar clearance.
4. [03 — Academic setup, offerings and timetable](03_academic_setup_offerings_published_timetable.md): curricula, calendar, classes/resources, availability, CP-SAT, review/publication.
5. [04 — Registration and official enrollment](04_current_term_registration_official_enrollment.md): eligibility/proposal, placement, clearance, finalization, activation, adjustment/drop and COR.
6. [05 — Teaching, grades, records and completion](05_teaching_grades_academic_records_completion.md): rosters/results, INC, academic/lifecycle projections, conferral and TOR.
7. [06 — Accounts, outputs and assurance](06_accounts_official_outputs_operations_assurance.md): fees/assessments, accounts, coverage/payment evidence, clearance, SOA/acknowledgment, exports and health/privacy/audit.

Baseline Sections 10–12 define cross-module dependencies, coordinated demonstration and acceptance. Each module owns exact fields, sources, actions, failures and outputs; this index links to them rather than repeating them.

## Adjacent owners

- [Blueprint](../ui_surface_blueprint.md): role capability, navigation, interaction, states, accessibility and output presentation.
- [PRODUCT](../../PRODUCT.md): product brief. [DESIGN](../../DESIGN.md) and surface briefs: shared visual foundation and task direction.
- [Architecture](../architecture_specification.md): technical/integration boundaries.
- [Protocol](../TALA-Orchestrator-Protocol.md): execution and permission boundaries.
- [Documentation guide](../README.md): authority/evidence/history classification.

PRDs define what must happen. Blueprint and Impeccable guide task-fit interaction. Source records are edited only by their owning role; other surfaces show authorized projections. Computed results remain read-only. Fixed import/export/output contracts create no generic report/mapping/template designer.

## Evidence and changes

TALA is Servitech-first. Missing/confidential evidence is never invented or disclosed. Apply the Baseline's ordered source and safe-default/policy-gate rules; another institution's thresholds, sanctions, deadlines, fees or vocabulary do not automatically become Servitech policy. Missing authority must retain an owner, usable remainder, remedy and reopening condition.

Former Product Intent, Term Offerings, CP-SAT, COR, Student Lifecycle, Student Hub and System Administration modules remain in [_legacy/](./_legacy/) as preserved non-authoritative inputs. Their numbering creates no additional authority.

Keep each rule with its owner. A merge, task closure, code model or historical review does not change product policy. Reconcile an adopted material change in its owning PRD before implementing it. Delivery decisions and acceptance evidence belong in GitHub.

<a id="audience-and-authority"></a>
<a id="canonical-authority-and-preserved-inputs"></a>
<a id="mvp-system-surface-map"></a>
<a id="standalone-authority-map"></a>
<a id="table-of-contents"></a>
<a id="writing-and-surface-rules"></a>
