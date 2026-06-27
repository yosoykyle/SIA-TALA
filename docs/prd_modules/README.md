# TALA Product Requirements — Modules Table of Contents

This directory is the shared product requirements baseline for TALA. It describes the expected capabilities, business rules, actors, records, outputs, integrations, and product boundaries by module.

The modules provide context for client stakeholders, product owners, developers, and coding agents. They define **what the product must do**, but they are not an implementation sequence, sprint plan, issue breakdown, or definition of done. Technical references that clarify a product constraint do not by themselves prescribe the final implementation.

For multi-session development, use the relevant modules as context for Matt's `/grill-with-docs` -> `/to-prd` -> `/to-issues` flow. The resulting feature PRD and approved vertical-slice issues control implementation. Architecture choices are documented separately in the [System Architecture Specification](../architecture_specification.md).

## Audience and Authority

- **Client and product review:** scope, workflows, roles, business rules, outputs, integrations, and product boundaries.
- **Agent and development context:** domain intent, cross-module dependencies, constraints, and terminology needed to prepare feature PRDs and implementation issues.
- **Implementation authority:** the approved feature PRD and its tracer-bullet issues, interpreted consistently with these product modules.
- **Change rule:** a development decision must not silently alter a client-facing requirement. Product changes must first be reflected in the relevant module.

Working audits, clarification trackers, and reconciliation notes are kept in [`_working/`](./_working/) so the module list stays focused on the PRD surface.

## Table of Contents

1. [01. Product Intent & Architecture](./01_product_intent_architecture.md)
2. [02. Identity, Access, and Workspaces](./02_identity_access_workspaces.md)
3. [03. Admissions & Student Handover](./03_admissions_student_handover.md)
4. [04. Academic Setup](./04_academic_setup.md)
5. [05. Term Offerings & Resources](./05_term_offerings_resources.md)
6. [06. CP-SAT Scheduling Subsystem](./06_cpsat_scheduling.md)
7. [07. Enrollment Gate Model & Execution](./07_enrollment_gate_model.md)
8. [08. Finance, Ledger, & PayMongo Subsystem](./08_finance_ledger_paymongo.md)
9. [09. Certificate of Registration (COR) Subsystem](./09_cor_subsystem.md)
10. [10. Grades](./10_grades.md)
11. [11. Student Lifecycle Status & Holds](./11_student_lifecycle.md)
12. [12. Student Hub & Generated Output Access](./12_student_hub.md)
13. [13. System Administration, Reports, & Audit](./13_system_admin_reports_audit.md)
