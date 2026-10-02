# TALA Documentation Authority Registry

This registry defines how project-authored documentation must be interpreted. A document's existence, age, title, task ID, implementation status, or level of detail does not make it product authority.

## Product authority

These ten documents are the complete approved product authority set:

1. [`prd_modules/00_system_definition_baseline.md`](prd_modules/00_system_definition_baseline.md)
2. [`prd_modules/README.md`](prd_modules/README.md)
3. [`prd_modules/01_identity_access_public_entry.md`](prd_modules/01_identity_access_public_entry.md)
4. [`prd_modules/02_application_admission_decision_enrollment_readiness.md`](prd_modules/02_application_admission_decision_enrollment_readiness.md)
5. [`prd_modules/03_academic_setup_offerings_published_timetable.md`](prd_modules/03_academic_setup_offerings_published_timetable.md)
6. [`prd_modules/04_current_term_registration_official_enrollment.md`](prd_modules/04_current_term_registration_official_enrollment.md)
7. [`prd_modules/05_teaching_grades_academic_records_completion.md`](prd_modules/05_teaching_grades_academic_records_completion.md)
8. [`prd_modules/06_accounts_official_outputs_operations_assurance.md`](prd_modules/06_accounts_official_outputs_operations_assurance.md)
9. [`ui_surface_blueprint.md`](ui_surface_blueprint.md)
10. [`architecture_specification.md`](architecture_specification.md)

The baseline and PRDs own product behavior, the UI Surface Blueprint owns interface mapping, and the Architecture Specification owns system and integration boundaries. Current shared visual direction is recorded in [`PRODUCT.md`](../PRODUCT.md) and [`DESIGN.md`](../DESIGN.md), while the UI Surface Blueprint governs observable interface and role-surface contracts and Architecture governs technical boundaries. The October 2 owner-selected guide is supporting evidence whose accepted traits are promoted into those records. Existing surface briefs retain task-specific decisions but cannot override superseded global visual requirements. The historical [TALA Experience and Official-Output Design Definition](TALA-Experience-and-Official-Output-Design-Definition.md) is superseded historical design rationale preserved for traceability; it cannot override canonical owners.

## Workflow and operational authority

| Classification | Documents | Boundary |
| --- | --- | --- |
| Workflow authority | [`../AGENTS.md`](../AGENTS.md), [`TALA-Orchestrator-Protocol.md`](TALA-Orchestrator-Protocol.md) | Planning, execution, verification, preservation, Git, and external-mutation rules only |
| Workflow quick reference | [`TALA-Orchestration-Cheat-Sheet.md`](TALA-Orchestration-Cheat-Sheet.md) | Derived operational companion for humans and agents; introduces no authority, and the Orchestrator Protocol governs any conflict |
| Active design foundation | [`../PRODUCT.md`](../PRODUCT.md), [`../DESIGN.md`](../DESIGN.md), [`.impeccable/surfaces/`](../.impeccable/surfaces/) | October 2 school-first presentation direction and applicable task-specific briefs; canonical UI and architecture owners govern conflicts |
| Historical design rationale | [`TALA-Experience-and-Official-Output-Design-Definition.md`](TALA-Experience-and-Official-Output-Design-Definition.md) | Historical Human-Centered Operations layouts and rationale; selected identity traits survive through current canonical UI authority and DESIGN.md, not the prototype itself |
| Task management | GitHub Issues and the public [`TALA Development`](https://github.com/users/yosoykyle/projects/3) project | Issues own tracked scope; the project provides `All Work` and `Board` views, with approved implementation intake and terminal `Done` transitions automated as defined by the protocol |
| Developer setup | [`../README.md`](../README.md), [`../CONTRIBUTING.md`](../CONTRIBUTING.md), [`developer-guides/`](developer-guides/), [`../CLAUDE.md`](../CLAUDE.md), [`../GEMINI.md`](../GEMINI.md) | Application setup, integration runbooks, fresh-clone readiness, and compatibility routing; never product authority |

Task IDs, tracker rows, commits, demonstrations, tests, code, schema, seeders, and implementation history cannot create or restore product requirements.

## Evidence and archive classification

Classification is determined by the first matching rule below. Every project-authored document in scope is covered by an exact file or directory rule.

| Path | Classification | Reading rule |
| --- | --- | --- |
| [`design-evidence/layout/`](design-evidence/layout/) | Supporting evidence — current owner-selected adaptation reference | Use the current README and rendered specimens through promoted canonical contracts. Native Heroicons override prototype Lucide for production. Fictional workflows, historical captures, and QA are not product or acceptance authority |
| [`design-evidence/`](design-evidence/) | Supporting evidence — historical comparison | Historical comparisons and other supporting design material; current reference directories require their explicit classification. No prototype independently grants product or implementation authority |
| `prd_modules/_legacy/**` | Supporting evidence — replaced PRDs | Traceability and bounded salvage only; canonical 00–06 wins |
| [`business-evidence/`](business-evidence/) | Supporting evidence — institutional material | Clarifies terminology, forms, and current/manual practice; cannot override accepted policy or product authority |
| [`research paper/`](research%20paper/) | Supporting evidence — research | Technical and academic support; not an implementation contract |
| `archive/raw-source-files/**`, `archive/PAPER/**`, `archive/0/**`, `archive/45 FINAL SORTTED/**`, `archive/archive system req/**` | Supporting evidence | Preserved source, research, or requirements inputs |
| [`archive/project-progress/`](archive/project-progress/) | Implementation history | Superseded plans, benchmarks, audits, logs, and recovery notes |
| [`archive/demo/`](archive/demo/) | Demonstration material | Historical scripts, walkthroughs, fixtures, UAT, and test-case evidence |
| [`archive/tooling/`](archive/tooling/) | Implementation history — tooling | Superseded agent/tool guidance preserved for traceability |
| `archive/prd_modules_bck/**` | Obsolete/duplicate | Older PRD backup; canonical and `_legacy` sets govern classification |
| `archive/deprecated-shs-scope-2026-06-21/**` and `archive/DEPRICATED INSTITUTION WORK  FLOW CURRENT.md.cleaned.md` | Obsolete/duplicate | Senior-high or replaced institutional material; not active college scope |
| Other files directly under `archive/` | Supporting evidence, implementation history, demonstration material, or obsolete/duplicate as catalogued by [`archive/README.md`](archive/README.md) | Never product or workflow authority |
| [`cloud/scheduler-solver/README.md`](../cloud/scheduler-solver/README.md) | Implementation evidence | Describes the current solver implementation; Architecture and PRD 03 govern desired behavior |

Dependencies, installed agent skills/plugins, caches, generated artifacts, `tmp/`, `migration_log.txt`, vendor documentation, and application source comments are excluded from this documentation inventory. Their presence never grants authority.

## Historical test rule

Historical manual test cases and UAT documents are demonstration material. Automated tests are implementation evidence. Neither is maintained during documentation cleanup. Each later vertical slice must classify relevant tests against canonical acceptance behavior as aligned, partially aligned, superseded, or outside the slice.

## Change rule

- Update product authority before changing a settled product decision.
- Update workflow authority before changing orchestration rules.
- Keep live shared task status only in GitHub Issues; do not create a local shadow tracker.
- Preserve historical material in the archive with an explicit scope boundary.
- Delete only material proven redundant and recoverable; ignored or local-only evidence is preserved by default.
