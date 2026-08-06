# TALA Representative Scheduling Acceptance Evidence

> **Archived implementation evidence — not product or execution authority.** Preserve the measurements as historical solver evidence; PRD 03 and the Architecture Specification govern current behavior and claims.

**Evidence revision:** 18 July 2026
**Current solver revision:** `tala-scheduler-solver-b4f-ad9177e472f8`

## Purpose

This file records the corrected end-to-end acceptance of the client-aligned scheduling workload. It is an operational evidence companion to the standalone technical formulation, not a separate mathematical model.

The workload contains 54 course-component demands for six logical program-year cohorts. Each course retains a course-specific delivery-group record, while all courses attended by the same cohort share one conflict identity. The solver and Laravel use that shared identity to prevent students from being required in simultaneous subjects.

## Input composition

| Category | Count | Meaning |
| --- | ---: | --- |
| Programs | 3 | Business Management, Information Technology, and Tourism and Hospitality Management |
| Contextual students | 47 | Synthetic student profiles distributed according to the client-reported cohort counts |
| Logical cohorts | 6 | One first-year and one second-year cohort per program |
| Distinct subjects | 40 | Subjects represented by the accepted curricula |
| Course-specific delivery groups | 54 | Traceable course/cohort delivery rows |
| Scheduling Demands | 54 | Required components assigned by CP-SAT |
| Faculty | 12 | Synthetic qualified scheduling resources |
| Rooms | 6 | Synthetic physical scheduling resources |
| Half-hour time-grid units | 156 | Monday–Saturday, 07:00–20:00, in 30-minute increments |
| Candidate assignments | 10,356 | Permitted demand/faculty/room/time combinations after filtering |
| Model variables | 31,488 | Selection and auxiliary optimization variables |
| Model constraints | 62,832 | Complete pre-presolve constraints |
| `NoOverlap` groups | 138 | Faculty/day, room/day, and shared-cohort/day interval groups |

The student count supplies attendance and room-capacity context; CP-SAT schedules cohort delivery demands rather than individual students. Faculty, room, qualification, and availability records are synthetic but structurally complete enough to exercise the real database-to-solver pipeline.

## Verified Cloud configuration

| Setting | Value |
| --- | --- |
| Profile | B |
| vCPU / memory | 2 vCPU / 4 GiB |
| CP-SAT workers | 2 |
| Cloud Run concurrency | 1 request per instance |
| Search / HTTP limits | 30 seconds / 300 seconds |
| OR-Tools / seed | 9.15.6755 / `20260718` |
| Image digest | `sha256:3b46df2a712949bba3caf99bcc4c3dc75a3e474959b0586ad079b85b4e7e4612` |
| Access | Private; one dedicated invoker and no public invoker |

## Post-promotion solver results

| Run | Status | Coverage | Hard violations | Objective | Best bound | Relative gap | CP-SAT wall time |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | `feasible` | 54/54 | 0 | 383,480 | 452,737 | 18.0601% | 30.058 s |
| 2 | `feasible` | 54/54 | 0 | 383,480 | 452,248 | 17.9326% | 30.060 s |

`feasible` means a valid schedule was found but optimality was not proved before the time limit. The relative gap measures the remaining distance between the returned objective and CP-SAT's bound; it is not an accuracy score. Both responses passed Laravel's independent hard-constraint validation.

## Publication and role-projection evidence

Laravel ingested 54 candidate rows and published 54 official meetings inside a database transaction. The transaction was rolled back after verification, leaving no official test schedule behind.

| Cohort | Program / year | Published meetings |
| --- | --- | ---: |
| `DTBM-1A` | BM first year | 10 |
| `DTBM-2A` | BM second year | 9 |
| `DIT-1A` | IT first year | 8 |
| `DIT-2A` | IT second year | 8 |
| `DTHM-1A` | THM first year | 10 |
| `DTHM-2A` | THM second year | 9 |
| **Total** | **Six cohorts** | **54** |

The Registrar projection contained all 54 official meetings. The 12 Faculty accounts collectively received all 54 assigned meetings. Representative Student projections contained 10 meetings for `DTBM-1A`, 8 for `DIT-1A`, and 10 for `DTHM-1A`. Every timetable row contained course, description, component, assigned faculty, weekday, start/end time, room when required, and modality.

## Acceptance conclusion

The corrected implementation produces a normal, complete timetable for all six cohorts and prevents cross-course overlap through shared cohort identity. It achieved 100% demand coverage and zero solver or Laravel hard violations on the tagged candidate and on both post-promotion canonical runs. This evidence establishes correctness for the disclosed client-shaped fixture; it does not claim the client's actual faculty/room timetable, a universal institution size, or predictive accuracy.

Larger-workload capacity and cost evidence is recorded in [TAL-96B3-Cloud-Run-Capacity-Benchmark.md](TAL-96B3-Cloud-Run-Capacity-Benchmark.md). The complete equations, pipeline explanation, timetable examples, and research interpretation are consolidated in [TALA_CP-SAT_Technical_Formulation.md](../../research%20paper/TALA_CP-SAT_Technical_Formulation.md).
