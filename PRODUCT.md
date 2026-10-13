# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- Applicants and Students complete their permitted next step and understand their records.
- Registrar prepares academic work, records admissions/enrollment decisions and issues academic outputs.
- Accounting Officer records approved assessments, coverage and verified financial effects.
- Faculty supplies teaching availability, uses official schedules/rosters and submits final results.
- Academic Head inspects academic evidence read-only; institutional approval remains external.
- System Administrator manages access, security, public content and local service assurance.

[PRDs 00–06](00_Project_Documents/prd_modules/README.md) define each role's exact authority.

## Product purpose

TALA (Tertiary Academic Lifecycle Administration) is Servitech Institute Asia Inc.'s lean college information system. It connects admissions, academic preparation, timetable publication, registration, official enrollment, accounts, final grades and academic records.

The goal is a capstone-sized system people can understand and use without a developer explaining each screen. Users can tell where they are, what happened, who owns the next step, what they can do and how to return. CP-SAT through Google OR-Tools is the principal research innovation; payments support the connected lifecycle.

## Core journeys

1. Enter the service and reach the authorized workspace.
2. Apply, respond to corrections and reach enrollment readiness.
3. Prepare academic sources and publish an independently validated timetable.
4. Confirm registration, reach official enrollment and obtain a COR.
5. Submit/release final results and reach academic records, completion and TOR.
6. Review an account, establish a verified financial effect and obtain its permitted output.

This list describes the product. Delivery order, assignments and completion belong to [#48](https://github.com/yosoykyle/SIA-TALA/issues/48) and child Issues.

## Operating context and boundaries

Servitech is a Philippine higher education institution. Staff work mainly in desktop Filament panels; learner and Faculty tasks also need usable mobile views. TALA supports First, Second and Special Terms and source-bound school outputs.

TALA records attributable office results. Office deliberation, detailed paper-document handling, raw grade calculations, institutional financial decisions and cash-refund execution remain with their responsible owners outside TALA. The owning PRDs preserve bounded restrictions and distinct admissions/payment/output clearances. Blanket login/service bans, a generic policy engine, an open seat marketplace and autonomous institutional decisions remain outside scope.

Unavailable school evidence is never invented. Qualified Philippine sources and the coordinated synthetic demonstration institution follow the [Baseline](00_Project_Documents/prd_modules/00_system_definition_baseline.md). A demonstration establishes neither school policy nor production readiness.

## Product principles

- Clear tasks: necessary facts and the permitted next action lead; secondary detail stays reachable.
- Human authority: solvers and providers supply evidence; authorized school actors decide.
- Safe transitions: consequential changes validate and complete atomically with capacity/conflict safeguards.
- Auditable records: corrections retain attribution, history and immutable issued snapshots.
- Truthful recovery: zero, missing, blocked, stale and unmeasured remain distinct, with an owner and usable remedy.

Exact fields, consent, prerequisites, deadlines, permissions, source precedence and output rules belong to the owning PRDs.

## Brand and accessibility commitments

Servitech school identity leads; TALA remains secondary where the [Blueprint](00_Project_Documents/ui_surface_blueprint.md) permits it. Applicant screen exceptions and school-issued email/print identity follow that contract.

Use a professional, reassuring academic voice, approved artwork, readable hierarchy and paired light/dark appearances. Target WCAG 2.2 AA, keyboard access and meaning beyond color. Blueprint owns interaction/accessibility outcomes; [DESIGN.md](DESIGN.md) owns shared appearance.

## Document boundaries

- PRDs own behavior, data, office responsibilities and safeguards.
- Blueprint owns role journeys, navigation, visible states, interactions and outputs.
- DESIGN owns identity, tokens, typography, material, components and motion; surface briefs apply them to a task.
- [Architecture](00_Project_Documents/architecture_specification.md) owns technical structure, trust, integrations and operations.
- GitHub owns live coordination and evidence; the [Protocol](00_Project_Documents/TALA-Orchestrator-Protocol.md) owns execution/reporting permissions.

The [documentation guide](00_Project_Documents/README.md) classifies supporting and historical sources.
