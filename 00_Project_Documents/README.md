# TALA Documentation Guide

Use the document that owns your question:

- **What is TALA for, and who uses it?** [PRODUCT.md](../PRODUCT.md)
- **What must it do, and what rules/fields apply?** [PRDs 00–06](prd_modules/README.md)
- **What should a role see/do, and how do they navigate/recover?** [Blueprint](ui_surface_blueprint.md)
- **What should colors, typography, controls and motion look like?** [DESIGN.md](../DESIGN.md); [surface briefs](../.impeccable/surfaces/) apply it to a task.
- **How do components, data, security, integrations and operations work?** [Architecture](architecture_specification.md)
- **How do we execute, verify, report and publish?** [AGENTS](../AGENTS.md) and [Protocol](TALA-Orchestrator-Protocol.md)
- **What is done, assigned or next?** [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and child Issues. [TALA Development](https://github.com/users/yosoykyle/projects/3) is their view.

Keep one owner for each rule; derived summaries link to it. Code/tests/schema and dated evidence describe implementation. Their presence or success creates no product requirement or proof of a whole usable journey. PRODUCT, DESIGN, PRDs, Blueprint and Architecture remain foundational.

## Authority and companions

The approved numbered product set is Baseline 00 and PRDs 01–06, with their index. Blueprint and Architecture govern their own domains. The [Lifecycle Flowchart](TALA-System-Lifecycle-Flowchart.md) is a derived reader aid; the [Cheat Sheet](TALA-Orchestration-Cheat-Sheet.md) is a derived Protocol companion. [README](../README.md), [CONTRIBUTING](../CONTRIBUTING.md) and [developer guides](developer-guides/) retain setup commands, examples and runbooks. Generated agent instructions route compatibility; they do not define school policy.

Impeccable leads UI/UX judgment. Relevant skills, Laravel Boost, Serena and installed-version official documentation support the task; Filament demo content is not school authority. Surface briefs hold durable direction, not progress.

Keep original F/S/M/C concern IDs, D decisions, surface IDs and AC criteria. The [#48 source register](https://github.com/yosoykyle/SIA-TALA/issues/48#issuecomment-5918342932) retains feedback provenance.

## Evidence and archive classification

Classification is determined by the first matching rule below. Every project-authored document in scope is covered by an exact file or directory rule.

| Path | Classification | Reading rule |
| --- | --- | --- |
| [`design-evidence/layout/`](design-evidence/layout/) | Supporting/historical evidence | Preserved visual specimens and provenance for composition, including recovery backgrounds/animation. DESIGN.md and the UI Surface Blueprint own adopted visual decisions; the PRDs own school capabilities |
| [`design-evidence/`](design-evidence/) | Supporting evidence — historical comparison | Historical comparisons and other supporting design material; current reference directories require their explicit classification. No prototype independently grants product or implementation authority |
| `prd_modules/_legacy/**` | Supporting evidence — replaced PRDs | Traceability and bounded salvage only; canonical 00–06 wins |
| [`business-evidence/`](business-evidence/) | Supporting evidence — institutional material | Clarifies terminology, forms, and current/manual practice; cannot override accepted policy or product authority |
| `feedback direction/**` | Supporting evidence — independent review and owner feedback | Read dated assessments with their stated source/runtime limits. Accepted refinements belong in their owning PRDs, UI Blueprint, DESIGN and Issue contract; historical Adopt/Reject judgments and raw observations remain traceable. Local-only review files stay outside the code PR unless separately selected for publication |
| [`research paper/`](research%20paper/) | Supporting evidence — research | Technical and academic support; not an implementation contract |
| `archive/raw-source-files/**`, `archive/PAPER/**`, `archive/0/**`, `archive/45 FINAL SORTTED/**`, `archive/archive system req/**` | Supporting evidence | Preserved source, research, or requirements inputs |
| [`archive/project-progress/`](archive/project-progress/) | Implementation history | Superseded plans, benchmarks, audits, logs, and recovery notes |
| [`archive/demo/`](archive/demo/) | Demonstration material | Historical scripts, walkthroughs, fixtures, UAT, and test-case evidence |
| [`archive/tooling/`](archive/tooling/) | Implementation history — tooling | Superseded agent/tool guidance preserved for traceability |
| `archive/prd_modules_bck/**` | Obsolete/duplicate | Older PRD backup; canonical and `_legacy` sets govern classification |
| `archive/deprecated-shs-scope-2026-06-21/**` and `archive/DEPRICATED INSTITUTION WORK  FLOW CURRENT.md.cleaned.md` | Obsolete/duplicate | Senior-high or replaced institutional material; not active college scope |
| Other files directly under `archive/` | Supporting evidence, implementation history, demonstration material, or obsolete/duplicate as catalogued by [`archive/README.md`](archive/README.md) | Never product or workflow authority |
| [`cloud/scheduler-solver/README.md`](../cloud/scheduler-solver/README.md) | Implementation evidence | Describes the current solver implementation; Architecture and PRD 03 govern desired behavior |

Dependencies, installed agent skills/plugins, caches, generated artifacts, `tmp/`, `migration_log.txt`, vendor documentation, and application source comments are excluded from this documentation inventory. Their presence never grants authority. Excluded execution evidence, comparison captures, scratch logs, and temporary evaluation files (such as local review captures and solver experiment records) are implementation artifacts and are preserved privately outside repository tracking; they do not reside in the tracked repository and never create or override product authority.

## Historical test rule

Historical manual test cases and UAT documents are demonstration material. Automated tests are implementation evidence. Neither is maintained during documentation cleanup. Each later vertical slice must classify relevant tests against canonical acceptance behavior as aligned, partially aligned, superseded, or outside the slice.

## Change rule

- Product rules belong to PRDs; interface outcomes to Blueprint; appearance to DESIGN; technical boundaries to Architecture; execution permissions to Protocol.
- Reconcile material product/workflow changes with their owner before depending on them.
- Record live concerns, delivery order, shared status and acceptance in GitHub; temporary reports never become another task queue.
- Preserve history with its scope. Delete only proven redundant/recoverable material; preserve ignored/local evidence by default.

<a id="product-authority"></a>
<a id="workflow-and-operational-authority"></a>
