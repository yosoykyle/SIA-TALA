# Replaced PRD Inputs — Non-Authoritative Evidence

This directory preserves the product-requirements inputs replaced during the TALA definition-first rebaseline. The files remain available for traceability, coverage review, and later bounded implementation reconciliation, but they are not active product authority and must not be implemented as requirements.

The canonical authority set is [`00_system_definition_baseline.md`](../00_system_definition_baseline.md) through [`06_accounts_official_outputs_operations_assurance.md`](../06_accounts_official_outputs_operations_assurance.md), together with the owning sections of the [UI Surface Blueprint](../../ui_surface_blueprint.md) and the [Architecture Specification](../../architecture_specification.md).

## Preserved inputs

- `01_product_intent_architecture.md` — former broad product-intent module; shared authority now belongs to canonical `00` and the Architecture Specification.
- `05_term_offerings_resources.md` and `06_cpsat_scheduling.md` — split inputs unified and superseded by canonical PRD 03.
- `09_cor_subsystem.md` — COR and finance-overlap input superseded by canonical PRDs 04 and 06.
- `11_student_lifecycle.md` — lifecycle and financial-hold input superseded by canonical PRDs 05 and 06.
- `12_student_hub.md` — fragmented Student Hub and output input superseded by the owning canonical journey PRDs.
- `13_system_admin_reports_audit.md` — broad reports, administration, operations, audit, and retention input superseded by canonical PRDs 01 and 06.

Preservation does not imply acceptance. If a legacy statement conflicts with canonical `00`–`06`, the canonical authority governs unless the product decision is explicitly reopened and reconciled.
