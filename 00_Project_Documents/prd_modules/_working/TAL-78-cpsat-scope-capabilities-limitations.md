# TAL-78 CP-SAT Scope, Capabilities, and Limitations

## Purpose

This document defines the safe MVP boundary for Google OR-Tools CP-SAT in TALA. It explains what the solver can do, what it cannot do, what the current implementation supports, and what must be completed before the system can claim an end-to-end published schedule.

Read this with:

1. `06_cpsat_scheduling.md`
2. `05_term_offerings_resources.md`
3. `07_enrollment_gate_model.md`
4. `12_student_hub.md`
5. `13_system_admin_reports_audit.md`
6. `architecture_specification.md`

## Executive Summary

CP-SAT is a constraint satisfaction and optimization solver. It is a good fit for class scheduling because the task is to assign meetings into valid rooms, times, faculty, and sections while respecting configured rules.

For TALA, CP-SAT should answer this question:

> Given official term offerings, sections, course components, faculty eligibility, rooms, meeting requirements, and calendar availability blocks, can the system produce a feasible candidate schedule for staff review?

CP-SAT is not generative AI, machine learning, a school-policy engine, or a replacement for Registrar or Academic Head approval. It should not decide who is allowed to enroll, who graduates, which fee applies, or whether a school should override a policy.

The MVP boundary is:

1. TALA stores official school records in Laravel/MySQL.
2. TALA generates a validated solver snapshot from those records.
3. CP-SAT attempts the optimization and returns candidate schedule rows.
4. Staff review the candidate result.
5. TALA publishes accepted rows into official `section_meetings`.
6. Student Hub, COR, and related views read only from the published official meetings.

## External CP-SAT Facts

Official OR-Tools documentation describes CP-SAT as a solver for constraint programming over integers. This matters because the scheduling model must convert time, meeting duration, room capacity, days, and assignments into discrete values.

Official CP-SAT outcomes include:

1. `OPTIMAL` — the solver proved the best result under the model.
2. `FEASIBLE` — the solver found a valid result but did not prove it is best.
3. `INFEASIBLE` — no valid result exists under the current constraints.
4. `MODEL_INVALID` — the submitted model is invalid.
5. `UNKNOWN` — the solver stopped before proving feasibility or infeasibility.

For scheduling, OR-Tools supports interval-style modeling and `NoOverlap` constraints. These are relevant for TALA because class meetings must avoid overlapping faculty, rooms, and section schedules.

## General CP-SAT Capabilities

CP-SAT can support:

1. Room conflict prevention.
2. Faculty conflict prevention.
3. Section conflict prevention.
4. Required contact-hour satisfaction.
5. Room-type matching.
6. Capacity matching.
7. Fixed assignments where staff already selected a room, teacher, day, or time.
8. Calendar block avoidance such as holidays, unavailable periods, and break periods.
9. Candidate ranking through soft constraints, penalties, or objective values.
10. Time-limited search for MVP-safe response control.

CP-SAT is strongest when the rules are explicit, the input data is complete, and the system clearly separates hard constraints from preferences.

## General CP-SAT Limitations

CP-SAT cannot:

1. Infer missing school policy.
2. Repair incomplete curriculum, room, faculty, or calendar records.
3. Guarantee a feasible schedule when the source data is contradictory.
4. Decide which institutional rule should be relaxed.
5. Explain every infeasibility in human terms unless the model is designed to expose diagnostics.
6. Replace human review for official publication.
7. Produce useful results if the model sends inaccurate or stale source data.

If CP-SAT returns `INFEASIBLE`, the correct product behavior is not to force a schedule. The system should show staff which source areas need attention: missing room capacity, missing faculty eligibility, conflicting fixed assignments, insufficient available time slots, or invalid meeting requirements.

## TALA-Specific Solver Scope

TALA should send only scheduling data to the solver:

1. Term and academic calendar scope.
2. Term offerings.
3. Sections and delivery groups.
4. Course components and required meeting duration.
5. Eligible faculty and load options.
6. Rooms, room type, and capacity.
7. Fixed room/faculty/day/time values when configured.
8. Availability and blocked calendar windows.
9. Existing readiness findings that indicate whether the demand is safe to solve.

TALA should keep these responsibilities inside Laravel/MySQL:

1. User authentication and authorization.
2. Official school records.
3. Readiness checks before solver dispatch.
4. Solver-run history and diagnostics.
5. Candidate review.
6. Official publication into `section_meetings`.
7. Student Hub schedule visibility.
8. COR/report output source of truth.
9. Audit logs and staff accountability.

## Current Implementation State

Current code already contains the main scheduling pipeline pieces:

1. `GenerateSchedulingDemand` creates scheduling demand from official records.
2. `SchedulingDemand` stores source snapshots and readiness findings.
3. `ScheduleSolverSnapshotService` prepares solver input.
4. `ScheduleSolverDispatchJob` dispatches solver work.
5. `ScheduleCloudResultIngestor` stores returned candidate results.
6. `CandidateScheduleRow` stores candidate assignments.
7. `SchedulePublishService` publishes accepted candidates.
8. `SectionMeeting` stores official published meetings.
9. Filament resources expose demand, solver run, candidate review, and official meeting surfaces.

Current dirty-work verification found:

1. Scheduling demand generation was adjusted to update by the database identity columns instead of only by `demand_key`.
2. Nested solver diagnostics were changed to render safely as JSON text in the schedule-run view.
3. Focused scheduling tests pass against `test_tala_db`.
4. The current dirty set still includes demo-support files and role-policy/demo-access changes that must be classified separately before packaging.

## Safe Demo Claims

Safe to say:

1. TALA can collect scheduling demand from official school records.
2. TALA can check readiness and preserve source snapshots.
3. TALA has a solver-run surface and a candidate-publication path.
4. CP-SAT is used as a constraint optimization service, not as generative AI.
5. Staff review remains part of the official schedule lifecycle.

Do not say unless verified in the current demo database:

1. The solver has already produced a published student schedule.
2. The Student Hub schedule is already populated from a live published CP-SAT result.
3. Cloud Run has completed a current end-to-end solve for the demo dataset.

The safe wording is:

> The implemented scheduling workflow separates school records from optimization. TALA prepares validated demand, the CP-SAT service attempts the schedule, and staff publish accepted results into official meetings. The current MVP hardening task is to prove the full path from demo demand to published section meetings and Student Hub visibility.

## Recommended MVP Boundary

For MVP, keep CP-SAT narrow:

1. Generate demand from official term records.
2. Validate readiness before dispatch.
3. Send only clean scheduling input to the solver.
4. Store solver status and diagnostics.
5. Store candidate rows.
6. Let staff publish accepted candidate rows.
7. Read official student schedules from `section_meetings`.

Defer these post-MVP:

1. Automatic policy relaxation.
2. Multi-objective tuning UI.
3. What-if simulation dashboards.
4. Student preference scheduling.
5. Fully automatic publication without staff review.
6. AI-generated schedule explanations.
7. Complex cross-campus optimization.

## Risks and Mitigations

| Risk | Impact | MVP mitigation |
|---|---|---|
| Incomplete source data | Solver cannot produce a valid schedule | Keep readiness checks and show actionable findings |
| Contradictory fixed assignments | `INFEASIBLE` result | Treat fixed assignments as hard constraints and require staff correction |
| Solver returns candidate rows but staff do not publish | Student Hub remains empty | Make publication state explicit |
| Demo data is too thin | Integration looks fake or incomplete | Seed realistic rooms, faculty, sections, demands, and meetings |
| Cloud Run unavailable during recording | Live solve cannot be shown | Keep retained solver evidence and explain the separation of Laravel and solver service |

## Main-Chain Implications

The next MVP scheduling work should not expand the solver. It should harden the existing path:

1. Confirm solver endpoint and demo credentials are configured.
2. Dispatch one real Cloud Run run using seeded demo demand.
3. Verify candidate rows are ingested.
4. Verify candidate rows are visible for staff review.
5. Publish accepted rows into `section_meetings`.
6. Verify Student Hub and COR/schedule outputs read the same official meetings.
7. Keep failure states visible when the solver returns blocked, failed, infeasible, invalid, or unknown.

## References

External references:

1. Google OR-Tools CP-SAT Solver: <https://developers.google.com/optimization/cp/cp_solver>
2. OR-Tools CP-SAT scheduling recipes: <https://github.com/google/or-tools/blob/stable/ortools/sat/docs/scheduling.md>
3. OR-Tools CP model status definitions: <https://github.com/google/or-tools/blob/stable/ortools/sat/cp_model.proto>

Local references:

1. `00_Project_Documents/prd_modules/06_cpsat_scheduling.md`
2. `00_Project_Documents/prd_modules/05_term_offerings_resources.md`
3. `00_Project_Documents/prd_modules/07_enrollment_gate_model.md`
4. `00_Project_Documents/architecture_specification.md`
5. `app/Actions/Scheduling/GenerateSchedulingDemand.php`
6. `app/Actions/Scheduling/ScheduleSolverSnapshotService.php`
7. `app/Jobs/ScheduleSolverDispatchJob.php`
8. `app/Actions/Scheduling/ScheduleCloudResultIngestor.php`
9. `app/Actions/Scheduling/SchedulePublishService.php`
10. `app/Models/SchedulingDemand.php`
11. `app/Models/ScheduleGenerationRun.php`
12. `app/Models/CandidateScheduleRow.php`
13. `app/Models/SectionMeeting.php`
