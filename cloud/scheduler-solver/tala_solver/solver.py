from __future__ import annotations

import os
from contextlib import nullcontext
from dataclasses import dataclass
from datetime import datetime, timezone
from time import perf_counter
from typing import Any

from ortools import __version__ as ORTOOLS_VERSION
from ortools.sat.python import cp_model

from tala_solver.runtime import RequestBudgetExceeded, SolverRequestContext


LEGACY_CONTRACT_VERSION = "tal94-demand-v2"
CONTRACT_VERSION = "tala-timetable-v2"
SOLVER_VERSION = "cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2"
LEXICOGRAPHIC_TERMS = (
    "cohort_mode_switches",
    "cohort_idle_time",
    "faculty_load_imbalance",
    "faculty_idle_time",
    "room_seat_waste",
    "stable_earlier_placement",
)
LEGACY_SOFT_TERMS = (
    "prefer_earlier_time_blocks",
    "reduce_faculty_idle_gaps",
    "balance_faculty_load",
    "use_rooms_efficiently",
)
HARD_CONSTRAINTS = (
    "assign_every_ready_scheduling_demand_once",
    "faculty_no_overlap",
    "room_no_overlap",
    "section_delivery_group_no_overlap",
    "respect_fixed_assignments",
    "respect_calendar_blocks",
    "respect_room_capacity_type_and_features",
    "respect_faculty_qualification_and_load",
)
BALANCED_V1_WEIGHTS = {term: 1 for term in LEGACY_SOFT_TERMS}
DEFAULT_SOLVER_WORKER_COUNT = 1
DEFAULT_SOLVER_RANDOM_SEED = 20_260_718
APPROVED_SOLVER_WORKER_COUNTS = (1, 2, 4, 8)


@dataclass(frozen=True)
class Candidate:
    scheduling_demand_id: int
    demand_key: str
    term_offering_id: int
    section_id: int
    section_delivery_group_id: int
    cohort_or_student_group_id: int
    cohort_or_student_group_ids: tuple[int, ...]
    modality: str
    subject_id: int | None
    course_component_id: int | None
    faculty_id: int
    room_id: int | None
    day_of_week: int
    starts_at: str
    ends_at: str
    starts_minute: int
    ends_minute: int
    time_slot_id: int | None
    time_block_key: str
    meeting_sequence: int
    meeting_pattern: str
    priority: int
    duration_minutes: int
    load_units_scaled: int
    room_capacity: int
    expected_count: int


@dataclass(frozen=True)
class SolverRuntimeConfiguration:
    worker_count: int
    random_seed: int


@dataclass(frozen=True)
class CandidateIndexes:
    by_assignment: dict[tuple[int, int], tuple[int, ...]]
    by_cohort_day: dict[tuple[int, int], tuple[int, ...]]
    by_faculty_day: dict[tuple[int, int], tuple[int, ...]]
    by_room_day: dict[tuple[int, int], tuple[int, ...]]
    by_offering_group_faculty: dict[tuple[int, int, int], tuple[int, ...]]
    by_placement: dict[tuple[Any, ...], tuple[int, ...]]


def solver_runtime_configuration() -> SolverRuntimeConfiguration:
    return SolverRuntimeConfiguration(
        worker_count=_approved_environment_integer(
            "SOLVER_WORKER_COUNT",
            DEFAULT_SOLVER_WORKER_COUNT,
            APPROVED_SOLVER_WORKER_COUNTS,
        ),
        random_seed=_approved_environment_integer(
            "SOLVER_RANDOM_SEED",
            DEFAULT_SOLVER_RANDOM_SEED,
            (DEFAULT_SOLVER_RANDOM_SEED,),
        ),
    )


def solve_snapshot(
    snapshot: dict[str, Any],
    timeout_seconds: int = 300,
    *,
    request_context: SolverRequestContext | None = None,
) -> dict[str, Any]:
    started_at = perf_counter()
    timeout_seconds = max(1, min(int(timeout_seconds), 300))
    request_context = request_context or SolverRequestContext(
        timeout_seconds,
        response_reserve_seconds=0,
    )
    solver_run_id = _solver_run_id(snapshot)
    runtime_configuration = solver_runtime_configuration()

    request_context.checkpoint("normalization")

    contract_version = snapshot.get("contract_version")
    if contract_version not in {CONTRACT_VERSION, LEGACY_CONTRACT_VERSION}:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("unsupported_contract_version", f"Solver requires {CONTRACT_VERSION} snapshots.")],
            infeasible_reasons=[_reason("unsupported_contract_version", f"Solver requires {CONTRACT_VERSION} snapshots.")],
            runtime_configuration=runtime_configuration,
        )

    profile = snapshot.get("constraint_profile")
    legacy_profile = contract_version == LEGACY_CONTRACT_VERSION
    if legacy_profile and (
        not isinstance(profile, dict)
        or profile.get("key") != "balanced_v1"
        or profile.get("version") != 1
        or tuple(profile.get("hard_constraints") or ()) != HARD_CONSTRAINTS
        or profile.get("soft_weights") != BALANCED_V1_WEIGHTS
    ):
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("unsupported_constraint_profile", "Legacy snapshots require the unchanged balanced_v1 profile at version 1.")],
            infeasible_reasons=[_reason("unsupported_constraint_profile", "Legacy snapshots require the unchanged balanced_v1 profile at version 1.")],
            runtime_configuration=runtime_configuration,
        )

    if not legacy_profile and (
        not isinstance(profile, dict)
        or profile.get("key") != "lexicographic_v1"
        or profile.get("version") != 1
        or tuple(profile.get("hard_constraints") or ()) != HARD_CONSTRAINTS
        or tuple(profile.get("objective_hierarchy") or ()) != LEXICOGRAPHIC_TERMS
        or "soft_weights" in profile
    ):
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("unsupported_constraint_profile", "The timetable contract requires the fixed six-level lexicographic_v1 hierarchy.")],
            infeasible_reasons=[_reason("unsupported_constraint_profile", "The timetable contract requires the fixed six-level lexicographic_v1 hierarchy.")],
            runtime_configuration=runtime_configuration,
        )

    with request_context.measure("normalization"):
        demands = _demands(snapshot)
        cohort_ids_by_delivery_group = _cohort_ids_by_delivery_group(snapshot, demands)

    if cohort_ids_by_delivery_group is None:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("invalid_student_cohort_mapping", "Every demand requires one consistent shared cohort mapping.")],
            infeasible_reasons=[_reason("invalid_student_cohort_mapping", "Every demand requires one consistent shared cohort mapping.")],
            runtime_configuration=runtime_configuration,
        )

    with request_context.measure("normalization"):
        demands = [
            {
                **demand,
                "cohort_or_student_group_id": cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])][0],
                "cohort_or_student_group_ids": list(cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])]),
            }
            for demand in demands
        ]
    invalid_meeting_patterns = [
        demand for demand in demands
        if not _has_valid_meeting_pattern(demand)
    ]
    if invalid_meeting_patterns:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("invalid_meeting_pattern", "Every Scheduling Demand requires one to three equal-duration meetings.")],
            infeasible_reasons=[_reason("invalid_meeting_pattern", "Every Scheduling Demand requires one to three equal-duration meetings.")],
            runtime_configuration=runtime_configuration,
        )

    if any(_decimal_or_none(demand.get("load_units")) is None for demand in demands) or any(
        _decimal_or_none(row.get("max_allowed_units")) is None
        for row in snapshot.get("faculty", [])
        if isinstance(row, dict)
    ):
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("invalid_faculty_load", "Every demand and eligible faculty row requires a numeric unit-load value.")],
            infeasible_reasons=[_reason("invalid_faculty_load", "Every demand and eligible faculty row requires a numeric unit-load value.")],
            runtime_configuration=runtime_configuration,
        )

    if not legacy_profile and any((_int_or_none(demand.get("expected_count")) or 0) <= 0 for demand in demands):
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("invalid_expected_count", "Every timetable demand requires a positive confirmed expected count.")],
            infeasible_reasons=[_reason("invalid_expected_count", "Every timetable demand requires a positive confirmed expected count.")],
            runtime_configuration=runtime_configuration,
        )
    with request_context.measure("candidate_enumeration"):
        candidates, unassignable_reasons = _enumerate_candidates(
            snapshot,
            demands,
            request_context,
        )
    request_context.record_metric("candidate_count", len(candidates))

    if unassignable_reasons:
        assignments = [
            _conflict_assignment(
                demand,
                meeting_sequence,
                unassignable_reasons.get(int(demand["scheduling_demand_id"])) or [
                    _reason("solver_unassigned", "No conflict-free candidate exists for this Scheduling Demand."),
                ],
            )
            for demand in demands
            for meeting_sequence in range(1, (_meeting_count(demand) or 1) + 1)
        ]
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="infeasible",
            assignments=assignments,
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[],
            infeasible_reasons=[item for items in unassignable_reasons.values() for item in items],
            runtime_configuration=runtime_configuration,
        )

    with request_context.measure("hard_model_construction"):
        model = cp_model.CpModel()
        variables = [model.new_bool_var(f"candidate_{index}") for index, _ in enumerate(candidates)]
        indexes = _candidate_indexes(candidates)

        for candidate_indexes in indexes.by_assignment.values():
            model.add(sum(variables[index] for index in candidate_indexes) == 1)

        repair_error = _apply_repair_hard_requirement(snapshot, model, variables, candidates)
        if repair_error is not None:
            return _result(
                snapshot=snapshot,
                solver_run_id=solver_run_id,
                solver_status="model_invalid",
                assignments=[],
                objective_score=None,
                timeout=False,
                started_at=started_at,
                warnings=[repair_error],
                infeasible_reasons=[repair_error],
                runtime_configuration=runtime_configuration,
            )

        _add_no_overlap_constraints(model, variables, candidates, indexes)
        _add_modality_transition_buffer_constraints(
            model,
            variables,
            candidates,
            indexes,
            request_context,
        )
        _add_same_faculty_constraints(model, variables, candidates, demands, indexes)
        load_variables = _add_faculty_load_constraints(model, variables, candidates, snapshot)
        request_context.record_metric("model_variable_count", len(model.proto.variables))
        request_context.record_metric("model_constraint_count", len(model.proto.constraints))

    feasibility_solver = _configured_solver(
        timeout_seconds=max(0.001, request_context.remaining_search_seconds()),
        runtime_configuration=runtime_configuration,
    )
    feasibility_started_at = perf_counter()
    with request_context.measure("feasibility_search"):
        feasibility_status = feasibility_solver.solve(model)
    feasibility_stage = _search_stage_statistics(
        model=model,
        solver=feasibility_solver,
        solver_status=feasibility_status,
        measured_wall_time_seconds=perf_counter() - feasibility_started_at,
    )

    if feasibility_status not in {cp_model.OPTIMAL, cp_model.FEASIBLE}:
        if feasibility_status == cp_model.INFEASIBLE:
            assignments = [
                _conflict_assignment(
                    demand,
                    meeting_sequence,
                    [_reason("solver_infeasible", "CP-SAT proved that no assignment satisfies every hard constraint.")],
                )
                for demand in demands
                for meeting_sequence in range(1, (_meeting_count(demand) or 1) + 1)
            ]
            infeasible_reasons = [
                item
                for assignment in assignments
                for item in assignment["violations"]
            ]
            warnings: list[dict[str, str]] = []
        elif feasibility_status == cp_model.MODEL_INVALID:
            assignments = []
            infeasible_reasons = [
                _reason("solver_model_invalid", "CP-SAT rejected the generated hard-constraint model."),
            ]
            warnings = []
        else:
            assignments = []
            infeasible_reasons = []
            warnings = [
                _reason(
                    "search_limit",
                    "The feasibility search ended before CP-SAT found or disproved a complete timetable.",
                ),
            ]

        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status=_status_name(feasibility_status),
            assignments=assignments,
            objective_score=None,
            timeout=feasibility_status == cp_model.UNKNOWN,
            started_at=started_at,
            warnings=warnings,
            infeasible_reasons=infeasible_reasons,
            objective_details=_empty_objective_details(snapshot),
            solver_statistics=_solver_statistics(
                snapshot=snapshot,
                candidates=candidates,
                model=model,
                result_solver=feasibility_solver,
                objective_score=None,
                runtime_configuration=runtime_configuration,
                result_source="none",
                feasibility_stage=feasibility_stage,
                optimization_stage=_not_run_stage_statistics(),
            ),
            runtime_configuration=runtime_configuration,
        )

    feasibility_selected = _selected_candidates(
        candidates=candidates,
        variables=variables,
        solver=feasibility_solver,
    )
    feasibility_objective_details = _objective_details(
        feasibility_selected,
        BALANCED_V1_WEIGHTS,
        snapshot,
    )
    feasibility_objective_score = int(feasibility_objective_details["total"])

    if not legacy_profile:
        return _solve_lexicographic(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            model=model,
            variables=variables,
            candidates=candidates,
            load_variables=load_variables,
            feasibility_solver=feasibility_solver,
            feasibility_selected=feasibility_selected,
            feasibility_stage=feasibility_stage,
            started_at=started_at,
            request_context=request_context,
            indexes=indexes,
            runtime_configuration=runtime_configuration,
        )

    for variable in variables:
        model.add_hint(variable, int(feasibility_solver.boolean_value(variable)))

    with request_context.measure("objective_construction"):
        _add_soft_objective(
            model=model,
            variables=variables,
            candidates=candidates,
            load_variables=load_variables,
        )

    remaining_seconds = request_context.remaining_search_seconds()

    if remaining_seconds <= 0.001:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="feasible",
            assignments=[
                _assignment(candidate)
                for candidate in sorted(feasibility_selected, key=_candidate_sort_key)
            ],
            objective_score=feasibility_objective_score,
            timeout=False,
            started_at=started_at,
            warnings=[
                _reason(
                    "optimization_budget_exhausted",
                    "A complete timetable was found, but no solver budget remained for soft-preference optimization.",
                ),
            ],
            infeasible_reasons=[],
            objective_details=feasibility_objective_details,
            solver_statistics=_solver_statistics(
                snapshot=snapshot,
                candidates=candidates,
                model=model,
                result_solver=feasibility_solver,
                objective_score=feasibility_objective_score,
                runtime_configuration=runtime_configuration,
                result_source="feasibility_fallback",
                feasibility_stage=feasibility_stage,
                optimization_stage=_not_run_stage_statistics(),
            ),
            runtime_configuration=runtime_configuration,
        )

    optimization_solver = _configured_solver(
        timeout_seconds=remaining_seconds,
        runtime_configuration=runtime_configuration,
    )
    optimization_started_at = perf_counter()
    with request_context.measure("optimization_search"):
        optimization_status = optimization_solver.solve(model)
    optimization_stage = _search_stage_statistics(
        model=model,
        solver=optimization_solver,
        solver_status=optimization_status,
        measured_wall_time_seconds=perf_counter() - optimization_started_at,
    )

    if optimization_status in {cp_model.OPTIMAL, cp_model.FEASIBLE}:
        selected = _selected_candidates(
            candidates=candidates,
            variables=variables,
            solver=optimization_solver,
        )
        objective_score = int(optimization_solver.objective_value)

        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status=_status_name(optimization_status),
            assignments=[
                _assignment(candidate)
                for candidate in sorted(selected, key=_candidate_sort_key)
            ],
            objective_score=objective_score,
            timeout=False,
            started_at=started_at,
            warnings=[],
            infeasible_reasons=[],
            objective_details=_objective_details(selected, BALANCED_V1_WEIGHTS, snapshot),
            solver_statistics=_solver_statistics(
                snapshot=snapshot,
                candidates=candidates,
                model=model,
                result_solver=optimization_solver,
                objective_score=objective_score,
                runtime_configuration=runtime_configuration,
                result_source="optimization",
                feasibility_stage=feasibility_stage,
                optimization_stage=optimization_stage,
            ),
            runtime_configuration=runtime_configuration,
        )

    if optimization_status == cp_model.UNKNOWN:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="feasible",
            assignments=[
                _assignment(candidate)
                for candidate in sorted(feasibility_selected, key=_candidate_sort_key)
            ],
            objective_score=feasibility_objective_score,
            timeout=False,
            started_at=started_at,
            warnings=[
                _reason(
                    "optimization_limit_reached",
                    "The soft-preference search reached its limit; the complete hard-valid feasibility timetable was retained.",
                ),
            ],
            infeasible_reasons=[],
            objective_details=feasibility_objective_details,
            solver_statistics=_solver_statistics(
                snapshot=snapshot,
                candidates=candidates,
                model=model,
                result_solver=optimization_solver,
                objective_score=feasibility_objective_score,
                runtime_configuration=runtime_configuration,
                result_source="feasibility_fallback",
                feasibility_stage=feasibility_stage,
                optimization_stage=optimization_stage,
            ),
            runtime_configuration=runtime_configuration,
        )

    return _result(
        snapshot=snapshot,
        solver_run_id=solver_run_id,
        solver_status="model_invalid",
        assignments=[],
        objective_score=None,
        timeout=False,
        started_at=started_at,
        warnings=[],
        infeasible_reasons=[
            _reason(
                "optimization_model_inconsistent",
                "The soft-objective stage rejected a hard-valid feasibility assignment.",
            ),
        ],
        objective_details=_empty_objective_details(snapshot),
        solver_statistics=_solver_statistics(
            snapshot=snapshot,
            candidates=candidates,
            model=model,
            result_solver=optimization_solver,
            objective_score=None,
            runtime_configuration=runtime_configuration,
            result_source="none",
            feasibility_stage=feasibility_stage,
            optimization_stage=optimization_stage,
        ),
        runtime_configuration=runtime_configuration,
    )


def _solve_lexicographic(
    snapshot: dict[str, Any],
    solver_run_id: str,
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    load_variables: dict[int, cp_model.IntVar],
    feasibility_solver: cp_model.CpSolver,
    feasibility_selected: list[Candidate],
    feasibility_stage: dict[str, Any],
    started_at: float,
    request_context: SolverRequestContext,
    indexes: CandidateIndexes,
    runtime_configuration: SolverRuntimeConfiguration,
) -> dict[str, Any]:
    for variable in variables:
        model.add_hint(variable, int(feasibility_solver.boolean_value(variable)))

    selected = feasibility_selected
    result_solver = feasibility_solver
    completed_levels: list[dict[str, Any]] = []
    latest_stage = _not_run_stage_statistics()

    try:
        with request_context.measure("objective_construction"):
            objectives = _lexicographic_objectives(
                model,
                variables,
                candidates,
                load_variables,
                indexes,
                request_context,
            )
            repair_objective = _repair_change_objective(snapshot, variables, candidates)
            if repair_objective is not None:
                objectives.insert(0, ("changed_non_requested_meetings", repair_objective))
    except RequestBudgetExceeded:
        return _lexicographic_result(
            snapshot, solver_run_id, model, candidates, selected, result_solver,
            "feasible", started_at, runtime_configuration, feasibility_stage,
            latest_stage, completed_levels,
            [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
            request_context,
        )

    for index, (name, expression) in enumerate(objectives):
        remaining = request_context.remaining_search_seconds()

        if remaining <= 0.001:
            return _lexicographic_result(
                snapshot, solver_run_id, model, candidates, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
                request_context,
            )

        model.maximize(expression)
        solver = _configured_solver(
            timeout_seconds=max(0.001, remaining / max(1, len(objectives) - index)),
            runtime_configuration=runtime_configuration,
        )
        phase_started = perf_counter()
        try:
            with request_context.measure(f"lexicographic_search_{name}"):
                status = solver.solve(model)
        except RequestBudgetExceeded:
            return _lexicographic_result(
                snapshot, solver_run_id, model, candidates, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
                request_context,
            )
        latest_stage = _search_stage_statistics(
            model=model,
            solver=solver,
            solver_status=status,
            measured_wall_time_seconds=perf_counter() - phase_started,
        )

        if status not in {cp_model.OPTIMAL, cp_model.FEASIBLE}:
            return _lexicographic_result(
                snapshot, solver_run_id, model, candidates, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_limit_reached", f"The hard-valid timetable was retained before completing {name}.")],
                request_context,
            )

        selected = _selected_candidates(candidates, variables, solver)
        result_solver = solver
        value = int(round(solver.objective_value))
        completed_levels.append({
            "name": name,
            "status": _status_name(status),
            "value": value,
        })

        if status != cp_model.OPTIMAL:
            return _lexicographic_result(
                snapshot, solver_run_id, model, candidates, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("lexicographic_level_feasible", f"{name} was bounded but not proved optimal; lower levels were not allowed to alter it.")],
                request_context,
            )

        model.add(expression == value)

    return _lexicographic_result(
        snapshot, solver_run_id, model, candidates, selected, result_solver,
        "optimal", started_at, runtime_configuration, feasibility_stage,
        latest_stage, completed_levels, [], request_context,
    )


def _lexicographic_result(
    snapshot: dict[str, Any],
    solver_run_id: str,
    model: cp_model.CpModel,
    candidates: list[Candidate],
    selected: list[Candidate],
    solver: cp_model.CpSolver,
    status: str,
    started_at: float,
    runtime_configuration: SolverRuntimeConfiguration,
    feasibility_stage: dict[str, Any],
    optimization_stage: dict[str, Any],
    completed_levels: list[dict[str, Any]],
    warnings: list[dict[str, str]],
    request_context: SolverRequestContext,
) -> dict[str, Any]:
    return _result(
        snapshot=snapshot,
        solver_run_id=solver_run_id,
        solver_status=status,
        assignments=[_assignment(candidate) for candidate in sorted(selected, key=_candidate_sort_key)],
        objective_score=None,
        timeout=False,
        started_at=started_at,
        warnings=warnings,
        infeasible_reasons=[],
        objective_details=_lexicographic_objective_details(snapshot, selected, completed_levels),
        solver_statistics=_solver_statistics(
            snapshot=snapshot,
            candidates=candidates,
            model=model,
            result_solver=solver,
            objective_score=None,
            runtime_configuration=runtime_configuration,
            result_source="lexicographic",
            feasibility_stage=feasibility_stage,
            optimization_stage=optimization_stage,
        ),
        runtime_configuration=runtime_configuration,
        request_context=request_context,
    )


def _configured_solver(
    timeout_seconds: float,
    runtime_configuration: SolverRuntimeConfiguration,
) -> cp_model.CpSolver:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.001, timeout_seconds)
    solver.parameters.num_workers = runtime_configuration.worker_count
    solver.parameters.random_seed = runtime_configuration.random_seed

    return solver


def _selected_candidates(
    candidates: list[Candidate],
    variables: list[cp_model.IntVar],
    solver: cp_model.CpSolver,
) -> list[Candidate]:
    return [
        candidate
        for index, candidate in enumerate(candidates)
        if solver.boolean_value(variables[index])
    ]


def _add_soft_objective(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    load_variables: dict[int, cp_model.IntVar],
) -> None:
    weights = BALANCED_V1_WEIGHTS
    objective_terms: list[cp_model.LinearExpr] = []
    objective_terms.extend(
        weights["prefer_earlier_time_blocks"] * _earlier_candidate_score(candidate) * variables[index]
        for index, candidate in enumerate(candidates)
    )
    objective_terms.extend(
        weights["use_rooms_efficiently"] * _room_efficiency_score(candidate) * variables[index]
        for index, candidate in enumerate(candidates)
    )
    objective_terms.extend(
        _idle_gap_objective_terms(
            model,
            variables,
            candidates,
            weights["reduce_faculty_idle_gaps"],
        )
    )
    objective_terms.extend(
        _load_balance_objective_terms(
            model,
            load_variables,
            weights["balance_faculty_load"],
        )
    )
    model.maximize(sum(objective_terms))


def _lexicographic_objectives(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    load_variables: dict[int, cp_model.IntVar],
    indexes: CandidateIndexes,
    request_context: SolverRequestContext,
) -> list[tuple[str, cp_model.LinearExpr]]:
    return [
        (
            "cohort_mode_switches",
            sum(
                _cohort_mode_switch_objective_terms(
                    model,
                    variables,
                    indexes,
                    request_context,
                )
            ),
        ),
        ("cohort_idle_time", sum(_cohort_idle_gap_objective_terms(model, variables, candidates))),
        ("faculty_load_imbalance", sum(_load_balance_objective_terms(model, load_variables, 1))),
        ("faculty_idle_time", sum(_idle_gap_objective_terms(model, variables, candidates, 1))),
        (
            "room_seat_waste",
            sum(_room_efficiency_score(candidate) * variables[index] for index, candidate in enumerate(candidates)),
        ),
        (
            "stable_earlier_placement",
            sum(_earlier_candidate_score(candidate) * variables[index] for index, candidate in enumerate(candidates)),
        ),
    ]


def _cohort_mode_switch_objective_terms(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    indexes: CandidateIndexes,
    request_context: SolverRequestContext,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    placement_variables: dict[tuple[Any, ...], cp_model.IntVar] = {}

    for placement_index, (placement_key, candidate_indexes) in enumerate(indexes.by_placement.items()):
        placement = model.new_bool_var(f"placement_{placement_index}")
        model.add_max_equality(placement, [variables[index] for index in candidate_indexes])
        placement_variables[placement_key] = placement

    placements_by_cohort_day_start: dict[
        tuple[int, int],
        dict[int, list[tuple[Any, ...]]],
    ] = {}
    for placement_key in indexes.by_placement:
        cohort_ids = placement_key[2]
        day = int(placement_key[3])
        starts_minute = int(placement_key[4])
        for cohort_id in cohort_ids:
            placements_by_cohort_day_start.setdefault(
                (int(cohort_id), day),
                {},
            ).setdefault(starts_minute, []).append(placement_key)

    modality_codes = {
        modality: index
        for index, modality in enumerate(
            sorted({str(key[6]) for key in indexes.by_placement}),
            start=1,
        )
    }
    maximum_mode = max(modality_codes.values(), default=1)

    for (cohort_id, day), placements_by_start in placements_by_cohort_day_start.items():
        previous_mode: cp_model.IntVar | None = None

        for position, placement_keys in enumerate(
            dict(sorted(placements_by_start.items())).values()
        ):
            request_context.checkpoint("objective_construction")
            placement_keys = sorted(set(placement_keys))
            current_selected = [placement_variables[key] for key in placement_keys]
            starts_here = model.new_bool_var(
                f"cohort_starts_{cohort_id}_{day}_{position}"
            )
            model.add(starts_here == sum(current_selected))
            current_mode = model.new_int_var(
                0,
                maximum_mode,
                f"cohort_mode_{cohort_id}_{day}_{position}",
            )
            model.add(
                current_mode
                == sum(
                    modality_codes[str(key[6])] * placement_variables[key]
                    for key in placement_keys
                )
            )
            last_mode = model.new_int_var(
                0,
                maximum_mode,
                f"cohort_last_mode_{cohort_id}_{day}_{position}",
            )

            if previous_mode is None:
                model.add(last_mode == current_mode)
                previous_mode = last_mode
                continue

            model.add(last_mode == current_mode).only_enforce_if(starts_here)
            model.add(last_mode == previous_mode).only_enforce_if(starts_here.Not())
            has_previous = model.new_bool_var(
                f"cohort_has_previous_{cohort_id}_{day}_{position}"
            )
            model.add(previous_mode != 0).only_enforce_if(has_previous)
            model.add(previous_mode == 0).only_enforce_if(has_previous.Not())
            changed = model.new_bool_var(
                f"cohort_mode_changed_{cohort_id}_{day}_{position}"
            )
            model.add(current_mode != previous_mode).only_enforce_if(changed)
            model.add(current_mode == previous_mode).only_enforce_if(changed.Not())
            switch = model.new_bool_var(
                f"cohort_mode_switch_{cohort_id}_{day}_{position}"
            )
            model.add_bool_and([starts_here, has_previous, changed]).only_enforce_if(switch)
            model.add_bool_or([
                starts_here.Not(),
                has_previous.Not(),
                changed.Not(),
                switch,
            ])
            terms.append(-switch)
            previous_mode = last_mode

    return terms


def _cohort_idle_gap_objective_terms(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    cohort_days: dict[tuple[int, int], list[int]] = {}

    for index, candidate in enumerate(candidates):
        for cohort_id in candidate.cohort_or_student_group_ids:
            cohort_days.setdefault((cohort_id, candidate.day_of_week), []).append(index)

    for (cohort_id, day), indexes in cohort_days.items():
        if len(indexes) < 2:
            continue

        horizon_start = min(candidates[index].starts_minute for index in indexes)
        horizon_end = max(candidates[index].ends_minute for index in indexes)
        active = model.new_bool_var(f"cohort_day_active_{cohort_id}_{day}")
        model.add_max_equality(active, [variables[index] for index in indexes])
        effective_starts: list[cp_model.IntVar] = []
        effective_ends: list[cp_model.IntVar] = []

        for index in indexes:
            candidate = candidates[index]
            effective_start = model.new_int_var(horizon_start, horizon_end, f"cohort_day_start_{cohort_id}_{day}_{index}")
            effective_end = model.new_int_var(horizon_start, horizon_end, f"cohort_day_end_{cohort_id}_{day}_{index}")
            model.add(effective_start == candidate.starts_minute).only_enforce_if(variables[index])
            model.add(effective_start == horizon_end).only_enforce_if(variables[index].Not())
            model.add(effective_end == candidate.ends_minute).only_enforce_if(variables[index])
            model.add(effective_end == horizon_start).only_enforce_if(variables[index].Not())
            effective_starts.append(effective_start)
            effective_ends.append(effective_end)

        first_start = model.new_int_var(horizon_start, horizon_end, f"cohort_first_{cohort_id}_{day}")
        last_end = model.new_int_var(horizon_start, horizon_end, f"cohort_last_{cohort_id}_{day}")
        idle = model.new_int_var(0, horizon_end - horizon_start, f"cohort_idle_{cohort_id}_{day}")
        duration = sum(candidates[index].duration_minutes * variables[index] for index in indexes)
        model.add_min_equality(first_start, effective_starts)
        model.add_max_equality(last_end, effective_ends)
        model.add(idle == last_end - first_start - duration).only_enforce_if(active)
        model.add(idle == 0).only_enforce_if(active.Not())
        terms.append(-idle)

    return terms


def evaluate_candidate_membership(
    snapshot: dict[str, Any],
    assignments: list[dict[str, Any]],
) -> dict[str, Any]:
    """Replay assignments through candidate generation without invoking CP-SAT."""
    demands = _demands(snapshot)
    cohort_ids_by_delivery_group = _cohort_ids_by_delivery_group(snapshot, demands)

    if cohort_ids_by_delivery_group is None:
        raise ValueError("Every demand requires one consistent shared cohort mapping.")

    demands = [
        {
            **demand,
            "cohort_or_student_group_id": cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])][0],
            "cohort_or_student_group_ids": list(cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])]),
        }
        for demand in demands
    ]
    candidates, _ = _enumerate_candidates(
        snapshot,
        demands,
        SolverRequestContext(300),
    )
    candidate_keys = {_candidate_membership_key(candidate) for candidate in candidates}
    results = [
        {
            "scheduling_demand_id": _int_or_none(assignment.get("scheduling_demand_id")),
            "meeting_sequence": _int_or_none(assignment.get("meeting_sequence")) or 1,
            "admissible": _assignment_membership_key(assignment) in candidate_keys,
        }
        for assignment in assignments
        if isinstance(assignment, dict)
    ]
    admissible_count = sum(1 for result in results if result["admissible"])
    expected_assignment_keys = {
        (int(demand["scheduling_demand_id"]), meeting_sequence)
        for demand in demands
        for meeting_sequence in range(1, (_meeting_count(demand) or 1) + 1)
    }
    assigned_assignment_keys = [
        (result["scheduling_demand_id"], result["meeting_sequence"])
        for result in results
    ]
    complete_demand_coverage = (
        len(assigned_assignment_keys) == len(expected_assignment_keys)
        and len(set(assigned_assignment_keys)) == len(assigned_assignment_keys)
        and set(assigned_assignment_keys) == expected_assignment_keys
    )

    return {
        "expected_demand_count": len(demands),
        "expected_assignment_count": len(expected_assignment_keys),
        "assignment_count": len(results),
        "admissible_count": admissible_count,
        "complete_demand_coverage": complete_demand_coverage,
        "all_admissible": complete_demand_coverage and admissible_count == len(results),
        "assignments": results,
    }


def _result(
    snapshot: dict[str, Any],
    solver_run_id: int | None,
    solver_status: str,
    assignments: list[dict[str, Any]],
    objective_score: int | None,
    timeout: bool,
    started_at: float,
    warnings: list[dict[str, str]],
    infeasible_reasons: list[dict[str, str]],
    objective_details: dict[str, Any] | None = None,
    solver_statistics: dict[str, Any] | None = None,
    runtime_configuration: SolverRuntimeConfiguration | None = None,
    request_context: SolverRequestContext | None = None,
) -> dict[str, Any]:
    measurement = (
        request_context.measure("result_construction", reserve_response=False)
        if request_context is not None
        else nullcontext()
    )

    with measurement:
        runtime_configuration = runtime_configuration or solver_runtime_configuration()
        conflict_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "conflict")
        warning_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "warning")
        assigned_count = len(assignments) - conflict_count
        expected_assignment_count = sum(
            _meeting_count(demand) or 1
            for demand in snapshot.get("scheduling_demands", [])
            if isinstance(demand, dict)
        )

        result = {
            "solver_run_id": solver_run_id,
            "solver_status": solver_status,
            "candidate_schedule_id": f"cp-sat-{solver_run_id or 'unknown'}",
            "assignments": assignments,
            "hard_constraint_violations": infeasible_reasons,
            "hard_violation_count": conflict_count,
            "soft_constraint_scores": {
                "assigned_count": assigned_count,
                "conflict_count": conflict_count,
                "prefer_earlier_time_blocks": _earlier_time_score(assignments),
            },
            "infeasible_reasons": infeasible_reasons,
            "warnings": warnings,
            "runtime_seconds": round(perf_counter() - started_at, 6),
            "objective_score": objective_score,
            "objective_details": objective_details or _empty_objective_details(snapshot),
            "solver_statistics": solver_statistics or _empty_solver_statistics(snapshot, runtime_configuration),
            "solver_version": SOLVER_VERSION,
            "model_version": str(snapshot.get("contract_version") or CONTRACT_VERSION),
            "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            "assigned_count": assigned_count,
            "unassigned_count": max(0, expected_assignment_count - assigned_count),
            "warning_count": warning_count,
            "timeout": timeout,
        }

    if request_context is not None:
        request_context.checkpoint_total("result_construction")

    return result


def _empty_solver_statistics(
    snapshot: dict[str, Any],
    runtime_configuration: SolverRuntimeConfiguration,
) -> dict[str, Any]:
    return {
        "ortools_version": ORTOOLS_VERSION,
        "input_demand_count": _list_count(snapshot.get("scheduling_demands")),
        "input_faculty_count": _list_count(snapshot.get("faculty")),
        "input_room_count": _list_count(snapshot.get("rooms")),
        "input_time_slot_count": _list_count(snapshot.get("time_slots")),
        "candidate_count": 0,
        "model_variable_count": 0,
        "model_constraint_count": 0,
        "no_overlap_constraint_count": 0,
        "best_objective_bound": None,
        "relative_optimality_gap": None,
        "boolean_variable_count": None,
        "branch_count": None,
        "conflict_count": None,
        "deterministic_time_seconds": None,
        "wall_time_seconds": None,
        "worker_count": runtime_configuration.worker_count,
        "random_seed": runtime_configuration.random_seed,
        "result_source": "none",
        "search_stages": {
            "feasibility": _not_run_stage_statistics(),
            "optimization": _not_run_stage_statistics(),
        },
    }


def _solver_statistics(
    snapshot: dict[str, Any],
    candidates: list[Candidate],
    model: cp_model.CpModel,
    result_solver: cp_model.CpSolver,
    objective_score: int | None,
    runtime_configuration: SolverRuntimeConfiguration,
    result_source: str,
    feasibility_stage: dict[str, Any],
    optimization_stage: dict[str, Any],
) -> dict[str, Any]:
    statistics = _empty_solver_statistics(snapshot, runtime_configuration)
    model_proto = model.proto
    best_objective_bound = (
        _float_metric(result_solver, "best_objective_bound")
        if optimization_stage["status"] != "not_run"
        else None
    )
    relative_optimality_gap = None

    if (
        objective_score is not None
        and best_objective_bound is not None
    ):
        relative_optimality_gap = abs(float(objective_score) - best_objective_bound) / max(
            1.0,
            abs(float(objective_score)),
        )

    stages = [feasibility_stage, optimization_stage]
    statistics.update({
        "candidate_count": len(candidates),
        "model_variable_count": len(model_proto.variables),
        "model_constraint_count": len(model_proto.constraints),
        "no_overlap_constraint_count": sum(
            1
            for constraint in model_proto.constraints
            if constraint.has_no_overlap()
        ),
        "best_objective_bound": best_objective_bound,
        "relative_optimality_gap": _rounded_float(relative_optimality_gap),
        "boolean_variable_count": _int_metric(result_solver, "num_booleans"),
        "branch_count": _sum_stage_metric(stages, "branch_count"),
        "conflict_count": _sum_stage_metric(stages, "conflict_count"),
        "deterministic_time_seconds": _sum_stage_metric(stages, "deterministic_time_seconds"),
        "wall_time_seconds": _sum_stage_metric(stages, "wall_time_seconds"),
        "result_source": result_source,
        "search_stages": {
            "feasibility": feasibility_stage,
            "optimization": optimization_stage,
        },
    })

    return statistics


def _search_stage_statistics(
    model: cp_model.CpModel,
    solver: cp_model.CpSolver,
    solver_status: int,
    measured_wall_time_seconds: float,
) -> dict[str, Any]:
    model_proto = model.proto
    solver_wall_time = _float_metric(solver, "wall_time")

    return {
        "status": _status_name(solver_status),
        "model_variable_count": len(model_proto.variables),
        "model_constraint_count": len(model_proto.constraints),
        "no_overlap_constraint_count": sum(
            1
            for constraint in model_proto.constraints
            if constraint.has_no_overlap()
        ),
        "boolean_variable_count": _int_metric(solver, "num_booleans"),
        "branch_count": _int_metric(solver, "num_branches"),
        "conflict_count": _int_metric(solver, "num_conflicts"),
        "deterministic_time_seconds": _float_metric(solver, "deterministic_time"),
        "wall_time_seconds": solver_wall_time
        if solver_wall_time is not None
        else _rounded_float(measured_wall_time_seconds),
    }


def _not_run_stage_statistics() -> dict[str, Any]:
    return {
        "status": "not_run",
        "model_variable_count": 0,
        "model_constraint_count": 0,
        "no_overlap_constraint_count": 0,
        "boolean_variable_count": None,
        "branch_count": None,
        "conflict_count": None,
        "deterministic_time_seconds": None,
        "wall_time_seconds": 0.0,
    }


def _sum_stage_metric(stages: list[dict[str, Any]], key: str) -> int | float | None:
    values = [
        stage[key]
        for stage in stages
        if isinstance(stage.get(key), (int, float))
    ]

    if values == []:
        return None

    total = sum(values)

    return int(total) if all(isinstance(value, int) for value in values) else _rounded_float(float(total))


def _metric(solver: cp_model.CpSolver, attribute: str) -> Any:
    try:
        value = getattr(solver, attribute)
    except RuntimeError:
        return None

    try:
        return value() if callable(value) else value
    except RuntimeError:
        return None


def _int_metric(solver: cp_model.CpSolver, attribute: str) -> int | None:
    value = _metric(solver, attribute)

    return int(value) if isinstance(value, (int, float)) else None


def _float_metric(solver: cp_model.CpSolver, attribute: str) -> float | None:
    value = _metric(solver, attribute)

    return _rounded_float(float(value)) if isinstance(value, (int, float)) else None


def _rounded_float(value: float | None) -> float | None:
    return round(value, 9) if value is not None else None


def _approved_environment_integer(
    name: str,
    default: int,
    approved_values: tuple[int, ...],
) -> int:
    raw_value = os.environ.get(name)

    if raw_value is None:
        return default

    try:
        value = int(raw_value)
    except ValueError as exception:
        raise RuntimeError(f"{name} must be an approved integer value.") from exception

    if value not in approved_values:
        approved = ", ".join(str(item) for item in approved_values)
        raise RuntimeError(f"{name} must be one of: {approved}.")

    return value


def _list_count(value: Any) -> int:
    return len(value) if isinstance(value, list) else 0


def _demands(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        demand
        for demand in snapshot.get("scheduling_demands", [])
        if isinstance(demand, dict) and demand.get("scheduling_demand_id") is not None
    ]


def _cohort_ids_by_delivery_group(
    snapshot: dict[str, Any],
    demands: list[dict[str, Any]],
) -> dict[int, tuple[int, ...]] | None:
    declared: dict[int, set[int]] = {}

    for row in snapshot.get("student_cohort_groups", []):
        if not isinstance(row, dict):
            return None

        delivery_group_id = _int_or_none(row.get("section_delivery_group_id"))
        cohort_id = _int_or_none(row.get("cohort_or_student_group_id"))

        if delivery_group_id is None or cohort_id is None:
            return None

        declared.setdefault(delivery_group_id, set()).add(cohort_id)

    resolved: dict[int, tuple[int, ...]] = {}

    for demand in demands:
        delivery_group_id = _int_or_none(demand.get("section_delivery_group_id"))
        explicit_cohort_id = _int_or_none(demand.get("cohort_or_student_group_id"))
        raw_explicit_ids = demand.get("cohort_or_student_group_ids")
        explicit_cohort_ids = tuple(sorted({
            cohort_id
            for cohort_id in (
                _int_or_none(value)
                for value in raw_explicit_ids
            )
            if cohort_id is not None
        })) if isinstance(raw_explicit_ids, list) else ()

        if delivery_group_id is None:
            return None

        declared_cohort_ids = tuple(sorted(declared.get(delivery_group_id, set())))
        explicit = explicit_cohort_ids or ((explicit_cohort_id,) if explicit_cohort_id is not None else ())

        if explicit and declared_cohort_ids and explicit != declared_cohort_ids:
            return None

        cohort_ids = explicit or declared_cohort_ids

        if not cohort_ids:
            return None

        if delivery_group_id in resolved and resolved[delivery_group_id] != cohort_ids:
            return None

        resolved[delivery_group_id] = cohort_ids

    return resolved


def _rooms(snapshot: dict[str, Any]) -> dict[int, dict[str, Any]]:
    return {
        int(room["room_id"]): room
        for room in snapshot.get("rooms", [])
        if isinstance(room, dict) and room.get("room_id") is not None
    }


def _time_slots(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return sorted(
        [
            slot
            for slot in snapshot.get("time_slots", [])
            if isinstance(slot, dict)
            and slot.get("day_of_week") is not None
            and slot.get("starts_at") is not None
        ],
        key=lambda slot: (
            _int_or_none(slot.get("day_of_week")) or 0,
            _time_to_minutes(slot.get("starts_at")) or 0,
            _int_or_none(slot.get("time_slot_id")) or 0,
        ),
    )


def _faculty_availability(snapshot: dict[str, Any]) -> dict[int, list[dict[str, Any]]]:
    grouped: dict[int, list[dict[str, Any]]] = {}

    for row in snapshot.get("faculty_availability", []):
        if not isinstance(row, dict):
            continue

        faculty_id = _int_or_none(row.get("faculty_id") or row.get("faculty_user_id"))

        if faculty_id is None:
            continue

        windows = [window for window in row.get("windows", []) if isinstance(window, dict)]
        grouped[faculty_id] = windows

    return grouped


def _existing_commitments(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        commitment
        for commitment in snapshot.get("existing_commitments", [])
        if isinstance(commitment, dict)
    ]


def _calendar_blocks(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        block
        for block in snapshot.get("calendar_blocks", [])
        if isinstance(block, dict)
    ]


def _faculty_ids(demand: dict[str, Any]) -> list[int]:
    fixed_faculty_id = _int_or_none(demand.get("fixed_faculty_user_id"))
    eligible = [
        int(faculty_id)
        for faculty_id in demand.get("eligible_faculty_user_ids", [])
        if _int_or_none(faculty_id) is not None
    ]

    if fixed_faculty_id is not None:
        return [fixed_faculty_id] if fixed_faculty_id in eligible else []

    return sorted(set(eligible))


def _room_ids(demand: dict[str, Any], rooms: dict[int, dict[str, Any]]) -> list[int | None]:
    if not _room_required(demand):
        return [None]

    fixed_room_id = _int_or_none(demand.get("fixed_room_id"))

    if fixed_room_id is not None:
        room = rooms.get(fixed_room_id)

        return [fixed_room_id] if room is not None and _room_suits_demand(demand, room) else []

    return [
        room_id
        for room_id, room in sorted(rooms.items())
        if _room_suits_demand(demand, room)
    ]


def _room_suits_demand(demand: dict[str, Any], room: dict[str, Any]) -> bool:
    room_type = demand.get("room_type_requirement")
    expected_count = _int_or_none(demand.get("expected_count")) or 0
    required_features = {
        str(feature).strip().upper()
        for feature in demand.get("required_room_feature_keys", [])
        if str(feature).strip()
    }
    room_features = {
        str(feature).strip().upper()
        for feature in room.get("feature_keys", [])
        if str(feature).strip()
    }

    if room_type not in {None, ""} and room.get("room_type") != room_type:
        return False

    if not required_features.issubset(room_features):
        return False

    return (_int_or_none(room.get("capacity")) or 0) >= expected_count


def _enumerate_candidates(
    snapshot: dict[str, Any],
    demands: list[dict[str, Any]],
    request_context: SolverRequestContext,
) -> tuple[list[Candidate], dict[int, list[dict[str, str]]]]:
    rooms = _rooms(snapshot)
    time_slots = _time_slots(snapshot)
    availability = _faculty_availability(snapshot)
    existing_commitments = _commitment_indexes(_existing_commitments(snapshot))
    calendar_blocks = _calendar_block_indexes(_calendar_blocks(snapshot))
    candidates: list[Candidate] = []
    unassignable_reasons: dict[int, list[dict[str, str]]] = {}

    for demand in demands:
        request_context.checkpoint("candidate_enumeration")
        demand_id = _int_or_none(demand.get("scheduling_demand_id"))

        if demand_id is None:
            continue

        reasons: list[dict[str, str]] = []
        faculty_ids = _faculty_ids(demand)
        room_ids = _room_ids(demand, rooms)
        slots = _slots_for_demand(demand, time_slots)

        if not faculty_ids:
            reasons.append(_reason("missing_faculty", "No eligible faculty was available in the Scheduling Demand snapshot."))

        if _room_required(demand) and not room_ids:
            reasons.append(_reason("missing_room", "No active room matched the Scheduling Demand room requirement."))

        if not slots:
            reasons.append(_reason("missing_time_slot", "No usable time slot matched the Scheduling Demand duration or fixed time."))

        if reasons:
            unassignable_reasons[demand_id] = reasons

        for meeting_sequence in range(1, (_meeting_count(demand) or 1) + 1):
            for faculty_id in faculty_ids:
                for room_id in room_ids:
                    for slot in slots:
                        if len(candidates) % 500 == 0:
                            request_context.checkpoint("candidate_enumeration")
                        candidate = _candidate(
                            demand,
                            meeting_sequence,
                            faculty_id,
                            room_id,
                            slot,
                            rooms.get(room_id, {}),
                        )

                        if candidate is None:
                            continue

                        if not _inside_faculty_availability(candidate, availability):
                            continue

                        if _conflicts_existing(
                            candidate,
                            _relevant_commitments(candidate, existing_commitments),
                        ):
                            continue

                        if _conflicts_calendar(
                            candidate,
                            _relevant_calendar_blocks(candidate, calendar_blocks),
                        ):
                            continue

                        candidates.append(candidate)

    candidate_demand_ids = {candidate.scheduling_demand_id for candidate in candidates}

    for demand in demands:
        demand_id = int(demand["scheduling_demand_id"])

        if demand_id not in candidate_demand_ids and demand_id not in unassignable_reasons:
            unassignable_reasons[demand_id] = [
                _reason("solver_unassigned", "No candidate remains after availability and commitment constraints."),
            ]

    return candidates, unassignable_reasons


def _candidate_indexes(candidates: list[Candidate]) -> CandidateIndexes:
    by_assignment: dict[tuple[int, int], list[int]] = {}
    by_cohort_day: dict[tuple[int, int], list[int]] = {}
    by_faculty_day: dict[tuple[int, int], list[int]] = {}
    by_room_day: dict[tuple[int, int], list[int]] = {}
    by_offering_group_faculty: dict[tuple[int, int, int], list[int]] = {}
    by_placement: dict[tuple[Any, ...], list[int]] = {}

    for index, candidate in enumerate(candidates):
        by_assignment.setdefault(_candidate_assignment_key(candidate), []).append(index)
        for cohort_id in candidate.cohort_or_student_group_ids:
            by_cohort_day.setdefault((cohort_id, candidate.day_of_week), []).append(index)
        by_faculty_day.setdefault((candidate.faculty_id, candidate.day_of_week), []).append(index)
        if candidate.room_id is not None:
            by_room_day.setdefault((candidate.room_id, candidate.day_of_week), []).append(index)
        by_offering_group_faculty.setdefault(
            (
                candidate.term_offering_id,
                candidate.section_delivery_group_id,
                candidate.faculty_id,
            ),
            [],
        ).append(index)
        placement_key = (
            candidate.scheduling_demand_id,
            candidate.meeting_sequence,
            candidate.cohort_or_student_group_ids,
            candidate.day_of_week,
            candidate.starts_minute,
            candidate.ends_minute,
            candidate.modality,
        )
        by_placement.setdefault(placement_key, []).append(index)

    freeze = lambda values: {key: tuple(indexes) for key, indexes in values.items()}

    return CandidateIndexes(
        by_assignment=freeze(by_assignment),
        by_cohort_day=freeze(by_cohort_day),
        by_faculty_day=freeze(by_faculty_day),
        by_room_day=freeze(by_room_day),
        by_offering_group_faculty=freeze(by_offering_group_faculty),
        by_placement=freeze(by_placement),
    )


def _commitment_indexes(
    commitments: list[dict[str, Any]],
) -> dict[tuple[str, int, int], list[dict[str, Any]]]:
    indexes: dict[tuple[str, int, int], list[dict[str, Any]]] = {}
    for commitment in commitments:
        day = _int_or_none(commitment.get("day_of_week"))
        if day is None:
            continue
        resources = {
            "delivery_group": _int_or_none(commitment.get("section_delivery_group_id")),
            "faculty": _int_or_none(commitment.get("faculty_id") or commitment.get("faculty_user_id")),
            "room": _int_or_none(commitment.get("room_id")),
        }
        for resource, resource_id in resources.items():
            if resource_id is not None:
                indexes.setdefault((resource, resource_id, day), []).append(commitment)
    return indexes


def _relevant_commitments(
    candidate: Candidate,
    indexes: dict[tuple[str, int, int], list[dict[str, Any]]],
) -> list[dict[str, Any]]:
    keys = [
        ("delivery_group", candidate.section_delivery_group_id, candidate.day_of_week),
        ("faculty", candidate.faculty_id, candidate.day_of_week),
    ]
    if candidate.room_id is not None:
        keys.append(("room", candidate.room_id, candidate.day_of_week))

    return list({id(item): item for key in keys for item in indexes.get(key, [])}.values())


def _calendar_block_indexes(
    blocks: list[dict[str, Any]],
) -> dict[tuple[str, int | None, int], list[dict[str, Any]]]:
    indexes: dict[tuple[str, int | None, int], list[dict[str, Any]]] = {}
    for block in blocks:
        day, _, _ = _block_window(block)
        if day is None:
            continue
        room_id = _int_or_none(block.get("room_id"))
        faculty_id = _int_or_none(block.get("faculty_user_id") or block.get("faculty_id"))
        if room_id is not None:
            key = ("room", room_id, day)
        elif faculty_id is not None:
            key = ("faculty", faculty_id, day)
        else:
            key = ("global", None, day)
        indexes.setdefault(key, []).append(block)
    return indexes


def _relevant_calendar_blocks(
    candidate: Candidate,
    indexes: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> list[dict[str, Any]]:
    keys: list[tuple[str, int | None, int]] = [
        ("global", None, candidate.day_of_week),
        ("faculty", candidate.faculty_id, candidate.day_of_week),
    ]
    if candidate.room_id is not None:
        keys.append(("room", candidate.room_id, candidate.day_of_week))

    return [item for key in keys for item in indexes.get(key, [])]


def _slots_for_demand(demand: dict[str, Any], time_slots: list[dict[str, Any]]) -> list[dict[str, Any]]:
    fixed_day = _int_or_none(demand.get("fixed_day_of_week"))
    fixed_start = _time_to_minutes(demand.get("fixed_start_time"))

    duration = _duration_minutes(demand)
    day_ends = _day_ends(time_slots)
    slots: list[dict[str, Any]] = []

    for slot in time_slots:
        day = _int_or_none(slot.get("day_of_week"))
        starts_minute = _time_to_minutes(slot.get("starts_at"))

        if day is None or starts_minute is None:
            continue

        if fixed_day is not None and day != fixed_day:
            continue

        if fixed_start is not None and starts_minute != fixed_start:
            continue

        if starts_minute + duration > day_ends.get(day, starts_minute):
            continue

        slots.append(slot)

    return slots


def _candidate_membership_key(candidate: Candidate) -> tuple[Any, ...]:
    return (
        candidate.scheduling_demand_id,
        candidate.meeting_sequence,
        candidate.term_offering_id,
        candidate.section_id,
        candidate.section_delivery_group_id,
        candidate.cohort_or_student_group_id,
        candidate.subject_id,
        candidate.course_component_id,
        candidate.faculty_id,
        candidate.room_id,
        candidate.day_of_week,
        candidate.starts_at,
        candidate.ends_at,
        candidate.time_slot_id,
        candidate.time_block_key,
    )


def _candidate_assignment_key(candidate: Candidate) -> tuple[int, int]:
    return candidate.scheduling_demand_id, candidate.meeting_sequence


def _assignment_membership_key(assignment: dict[str, Any]) -> tuple[Any, ...]:
    return (
        _int_or_none(assignment.get("scheduling_demand_id")),
        _int_or_none(assignment.get("meeting_sequence")) or 1,
        _int_or_none(assignment.get("term_offering_id")) or 0,
        _int_or_none(assignment.get("section_id")) or 0,
        _int_or_none(assignment.get("section_delivery_group_id")) or 0,
        _int_or_none(assignment.get("cohort_or_student_group_id")) or 0,
        _int_or_none(assignment.get("subject_id") or assignment.get("course_id")),
        _int_or_none(assignment.get("course_component_id")),
        _int_or_none(assignment.get("faculty_user_id") or assignment.get("faculty_id")),
        _int_or_none(assignment.get("room_id")),
        _int_or_none(assignment.get("day_of_week") or assignment.get("day")),
        _time_or_none(assignment.get("starts_at") or assignment.get("start_time")),
        _time_or_none(assignment.get("ends_at") or assignment.get("end_time")),
        _int_or_none(assignment.get("time_slot_id")),
        str(assignment.get("time_block_key") or assignment.get("time_block_reference") or ""),
    )


def _day_ends(time_slots: list[dict[str, Any]]) -> dict[int, int]:
    ends: dict[int, int] = {}

    for slot in time_slots:
        day = _int_or_none(slot.get("day_of_week"))
        ends_at = _time_to_minutes(slot.get("ends_at"))

        if day is None or ends_at is None:
            continue

        ends[day] = max(ends.get(day, ends_at), ends_at)

    return ends


def _candidate(
    demand: dict[str, Any],
    meeting_sequence: int,
    faculty_id: int,
    room_id: int | None,
    slot: dict[str, Any],
    room: dict[str, Any],
) -> Candidate | None:
    starts_minute = _time_to_minutes(slot.get("starts_at"))
    day = _int_or_none(slot.get("day_of_week"))

    if starts_minute is None or day is None:
        return None

    duration = _duration_minutes(demand)
    ends_minute = starts_minute + duration

    return Candidate(
        scheduling_demand_id=int(demand["scheduling_demand_id"]),
        demand_key=str(demand.get("demand_key") or demand["scheduling_demand_id"]),
        term_offering_id=_int_or_none(demand.get("term_offering_id")) or 0,
        section_id=_int_or_none(demand.get("section_id")) or 0,
        section_delivery_group_id=_int_or_none(demand.get("section_delivery_group_id")) or 0,
        cohort_or_student_group_id=_int_or_none(demand.get("cohort_or_student_group_id")) or 0,
        cohort_or_student_group_ids=tuple(
            sorted({
                cohort_id
                for cohort_id in (
                    _int_or_none(value)
                    for value in demand.get("cohort_or_student_group_ids", [])
                )
                if cohort_id is not None
            })
        ) or (_int_or_none(demand.get("cohort_or_student_group_id")) or 0,),
        modality=str(demand.get("modality") or "").upper(),
        subject_id=_int_or_none(demand.get("course_id") or demand.get("subject_id")),
        course_component_id=_int_or_none(demand.get("course_component_id")),
        faculty_id=faculty_id,
        room_id=room_id,
        day_of_week=day,
        starts_at=_minutes_to_time(starts_minute),
        ends_at=_minutes_to_time(ends_minute),
        starts_minute=starts_minute,
        ends_minute=ends_minute,
        time_slot_id=_int_or_none(slot.get("time_slot_id")),
        time_block_key=str(slot.get("time_block_key") or f"D{day}-{starts_minute}"),
        meeting_sequence=meeting_sequence,
        meeting_pattern=_meeting_pattern(demand),
        priority=_faculty_priority(demand, faculty_id),
        duration_minutes=duration,
        load_units_scaled=_decimal_scaled(demand.get("load_units")),
        room_capacity=_int_or_none(room.get("capacity")) or 0,
        expected_count=_int_or_none(demand.get("expected_count")) or 0,
    )


def _add_no_overlap_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    indexes: CandidateIndexes,
) -> None:
    intervals = [
        model.new_optional_fixed_size_interval_var(
            candidate.starts_minute,
            candidate.duration_minutes,
            variables[index],
            f"candidate_interval_{index}",
        )
        for index, candidate in enumerate(candidates)
    ]
    for grouped_indexes in (
        indexes.by_cohort_day,
        indexes.by_faculty_day,
        indexes.by_room_day,
    ):
        for candidate_indexes in grouped_indexes.values():
            resource_intervals = [intervals[index] for index in candidate_indexes]
            if len(resource_intervals) > 1:
                model.add_no_overlap(resource_intervals)


def _add_modality_transition_buffer_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    indexes: CandidateIndexes,
    request_context: SolverRequestContext,
) -> None:
    seen_pairs: set[tuple[int, int]] = set()
    for candidate_indexes in indexes.by_cohort_day.values():
        request_context.checkpoint("hard_model_construction")
        for position, left_index in enumerate(candidate_indexes):
            left = candidates[left_index]
            for right_index in candidate_indexes[position + 1:]:
                pair = (min(left_index, right_index), max(left_index, right_index))
                if pair in seen_pairs:
                    continue
                seen_pairs.add(pair)
                right = candidates[right_index]
                if (
                    left.modality == right.modality
                    or left.scheduling_demand_id == right.scheduling_demand_id
                ):
                    continue

                left_to_right_gap = right.starts_minute - left.ends_minute
                right_to_left_gap = left.starts_minute - right.ends_minute
                if 0 <= left_to_right_gap < 30 or 0 <= right_to_left_gap < 30:
                    model.add(variables[left_index] + variables[right_index] <= 1)


def _apply_repair_hard_requirement(
    snapshot: dict[str, Any],
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
) -> dict[str, str] | None:
    operation = snapshot.get("operation")
    if operation is None:
        return None
    if not isinstance(operation, dict) or operation.get("kind") not in {"generation", "repair"}:
        return _reason("invalid_operation", "The timetable operation must be generation or repair.")
    if operation.get("kind") == "generation":
        return None

    source = operation.get("source_candidate")
    requested = operation.get("requested_assignment")
    if (
        not isinstance(source, dict)
        or not isinstance(source.get("assignments"), list)
        or not source.get("assignments")
        or not isinstance(requested, dict)
    ):
        return _reason("invalid_repair_contract", "Repair requires an immutable source candidate and requested assignment.")

    assignment_keys = {_candidate_assignment_key(candidate) for candidate in candidates}
    baseline = _repair_baseline(operation)
    if set(baseline) != assignment_keys:
        return _reason("invalid_repair_coverage", "Repair source assignments must cover the complete exact-Term candidate.")

    requested_demand_id = _int_or_none(requested.get("scheduling_demand_id"))
    requested_meeting_sequence = _int_or_none(requested.get("meeting_sequence")) or 1
    requested_key = (requested_demand_id, requested_meeting_sequence)
    if requested_demand_id is None or requested_key not in assignment_keys:
        return _reason("invalid_repair_request", "Repair requires one requested meeting from the source candidate.")

    matching = [
        variables[index]
        for index, candidate in enumerate(candidates)
        if _candidate_assignment_key(candidate) == requested_key
        and _candidate_matches_assignment(candidate, requested)
    ]
    if not matching:
        return _reason("repair_request_unavailable", "No hard-valid candidate matches the requested repair assignment.")

    model.add(sum(matching) == 1)

    return None


def _repair_change_objective(
    snapshot: dict[str, Any],
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
) -> cp_model.LinearExpr | None:
    operation = snapshot.get("operation")
    if not isinstance(operation, dict) or operation.get("kind") != "repair":
        return None

    requested = operation.get("requested_assignment")
    requested_demand_id = _int_or_none(requested.get("scheduling_demand_id")) if isinstance(requested, dict) else None
    requested_meeting_sequence = (
        _int_or_none(requested.get("meeting_sequence")) or 1
        if isinstance(requested, dict)
        else 1
    )
    requested_key = (requested_demand_id, requested_meeting_sequence)
    baseline = _repair_baseline(operation)
    changed_terms = [
        variables[index]
        for index, candidate in enumerate(candidates)
        if _candidate_assignment_key(candidate) != requested_key
        and _candidate_assignment_key(candidate) in baseline
        and not _candidate_matches_assignment(candidate, baseline[_candidate_assignment_key(candidate)])
    ]

    return -sum(changed_terms)


def _repair_baseline(operation: dict[str, Any]) -> dict[tuple[int, int], dict[str, Any]]:
    source = operation.get("source_candidate")
    assignments = source.get("assignments") if isinstance(source, dict) else None
    if not isinstance(assignments, list):
        return {}

    baseline: dict[tuple[int, int], dict[str, Any]] = {}
    for assignment in assignments:
        if not isinstance(assignment, dict):
            continue
        demand_id = _int_or_none(assignment.get("scheduling_demand_id"))
        meeting_sequence = _int_or_none(assignment.get("meeting_sequence")) or 1
        key = (demand_id, meeting_sequence)
        if demand_id is not None and key not in baseline:
            baseline[key] = assignment

    return baseline


def _candidate_matches_assignment(candidate: Candidate, assignment: dict[str, Any]) -> bool:
    return (
        candidate.meeting_sequence == (_int_or_none(assignment.get("meeting_sequence")) or 1)
        and candidate.day_of_week == _int_or_none(assignment.get("day_of_week") or assignment.get("day"))
        and candidate.starts_at == _time_or_none(assignment.get("starts_at") or assignment.get("start_time"))
        and candidate.ends_at == _time_or_none(assignment.get("ends_at") or assignment.get("end_time"))
        and candidate.faculty_id == _int_or_none(assignment.get("faculty_user_id") or assignment.get("faculty_id"))
        and candidate.room_id == _int_or_none(assignment.get("room_id"))
    )


def _repair_evidence(snapshot: dict[str, Any], selected: list[Candidate]) -> dict[str, Any] | None:
    operation = snapshot.get("operation")
    if not isinstance(operation, dict) or operation.get("kind") != "repair":
        return None

    source = operation.get("source_candidate") if isinstance(operation.get("source_candidate"), dict) else {}
    requested = operation.get("requested_assignment") if isinstance(operation.get("requested_assignment"), dict) else {}
    requested_demand_id = _int_or_none(requested.get("scheduling_demand_id"))
    requested_meeting_sequence = _int_or_none(requested.get("meeting_sequence")) or 1
    requested_key = (requested_demand_id, requested_meeting_sequence)
    baseline = _repair_baseline(operation)
    changes = []

    for candidate in sorted(selected, key=_candidate_sort_key):
        candidate_key = _candidate_assignment_key(candidate)
        previous = baseline.get(candidate_key)
        if previous is None or _candidate_matches_assignment(candidate, previous):
            continue
        changes.append({
            "scheduling_demand_id": candidate.scheduling_demand_id,
            "meeting_sequence": candidate.meeting_sequence,
            "requested": candidate_key == requested_key,
            "before": previous,
            "after": _assignment(candidate),
        })

    return {
        "source_run_id": source.get("run_id"),
        "source_candidate_version": source.get("candidate_version"),
        "requested_scheduling_demand_id": requested_demand_id,
        "requested_meeting_sequence": requested_meeting_sequence,
        "changed_non_requested_meetings": sum(1 for change in changes if not change["requested"]),
        "changes": changes,
    }


def _add_same_faculty_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    demands: list[dict[str, Any]],
    indexes: CandidateIndexes,
) -> None:
    grouped_assignments: dict[tuple[int, int], list[tuple[int, int]]] = {}

    for demand in demands:
        if not bool(demand.get("same_faculty_required")):
            continue

        demand_id = _int_or_none(demand.get("scheduling_demand_id"))
        term_offering_id = _int_or_none(demand.get("term_offering_id"))
        group_id = _int_or_none(demand.get("section_delivery_group_id"))

        if demand_id is None or term_offering_id is None or group_id is None:
            continue

        grouped_assignments.setdefault((term_offering_id, group_id), []).extend(
            (demand_id, meeting_sequence)
            for meeting_sequence in range(1, (_meeting_count(demand) or 1) + 1)
        )

    for assignment_keys in grouped_assignments.values():
        assignment_keys = sorted(set(assignment_keys))
        if len(assignment_keys) < 2:
            continue

        faculty_ids = {
            candidates[index].faculty_id
            for assignment_key in assignment_keys
            for index in indexes.by_assignment.get(assignment_key, ())
        }

        for left_key, right_key in zip(assignment_keys, assignment_keys[1:]):
            for faculty_id in faculty_ids:
                left_terms = [
                    variables[index]
                    for index in indexes.by_assignment.get(left_key, ())
                    if candidates[index].faculty_id == faculty_id
                ]
                right_terms = [
                    variables[index]
                    for index in indexes.by_assignment.get(right_key, ())
                    if candidates[index].faculty_id == faculty_id
                ]

                model.add(sum(left_terms) == sum(right_terms))


def _add_faculty_load_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    snapshot: dict[str, Any],
) -> dict[int, cp_model.IntVar]:
    max_units = {
        int(row["faculty_id"]): _decimal_scaled(row.get("max_allowed_units"))
        for row in snapshot.get("faculty", [])
        if isinstance(row, dict) and _int_or_none(row.get("faculty_id")) is not None
    }
    grouped: dict[tuple[int, int, int], list[int]] = {}

    for index, candidate in enumerate(candidates):
        grouped.setdefault(
            (candidate.faculty_id, candidate.term_offering_id, candidate.section_delivery_group_id),
            [],
        ).append(index)

    load_terms: dict[int, list[tuple[cp_model.IntVar, int]]] = {}
    for (faculty_id, offering_id, group_id), indexes in grouped.items():
        group_selected = model.new_bool_var(f"load_group_{faculty_id}_{offering_id}_{group_id}")
        for index in indexes:
            model.add(variables[index] <= group_selected)
        model.add(group_selected <= sum(variables[index] for index in indexes))
        units = max(candidates[index].load_units_scaled for index in indexes)
        load_terms.setdefault(faculty_id, []).append((group_selected, units))

    load_variables: dict[int, cp_model.IntVar] = {}
    for faculty_id, terms in load_terms.items():
        upper_bound = sum(units for _, units in terms)
        load = model.new_int_var(0, upper_bound, f"faculty_load_{faculty_id}")
        model.add(load == sum(variable * units for variable, units in terms))
        model.add(load <= max_units[faculty_id])
        load_variables[faculty_id] = load

    for faculty_id in max_units:
        if faculty_id not in load_variables:
            load_variables[faculty_id] = model.new_constant(0)

    return load_variables


def _idle_gap_objective_terms(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    weight: int,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    faculty_days: dict[tuple[int, int], list[int]] = {}

    for index, candidate in enumerate(candidates):
        faculty_days.setdefault((candidate.faculty_id, candidate.day_of_week), []).append(index)

    for (faculty_id, day_of_week), indexes in faculty_days.items():
        if len(indexes) < 2:
            continue

        horizon_start = min(candidates[index].starts_minute for index in indexes)
        horizon_end = max(candidates[index].ends_minute for index in indexes)
        active = model.new_bool_var(f"faculty_day_active_{faculty_id}_{day_of_week}")
        model.add_max_equality(active, [variables[index] for index in indexes])
        effective_starts: list[cp_model.IntVar] = []
        effective_ends: list[cp_model.IntVar] = []

        for index in indexes:
            candidate = candidates[index]
            effective_start = model.new_int_var(
                horizon_start,
                horizon_end,
                f"faculty_day_start_{faculty_id}_{day_of_week}_{index}",
            )
            effective_end = model.new_int_var(
                horizon_start,
                horizon_end,
                f"faculty_day_end_{faculty_id}_{day_of_week}_{index}",
            )
            model.add(effective_start == candidate.starts_minute).only_enforce_if(variables[index])
            model.add(effective_start == horizon_end).only_enforce_if(variables[index].Not())
            model.add(effective_end == candidate.ends_minute).only_enforce_if(variables[index])
            model.add(effective_end == horizon_start).only_enforce_if(variables[index].Not())
            effective_starts.append(effective_start)
            effective_ends.append(effective_end)

        first_start = model.new_int_var(
            horizon_start,
            horizon_end,
            f"faculty_day_first_start_{faculty_id}_{day_of_week}",
        )
        last_end = model.new_int_var(
            horizon_start,
            horizon_end,
            f"faculty_day_last_end_{faculty_id}_{day_of_week}",
        )
        idle_gap = model.new_int_var(
            0,
            horizon_end - horizon_start,
            f"faculty_day_idle_gap_{faculty_id}_{day_of_week}",
        )
        selected_duration = sum(
            candidates[index].duration_minutes * variables[index]
            for index in indexes
        )

        model.add_min_equality(first_start, effective_starts)
        model.add_max_equality(last_end, effective_ends)
        model.add(idle_gap == last_end - first_start - selected_duration).only_enforce_if(active)
        model.add(idle_gap == 0).only_enforce_if(active.Not())
        terms.append(-weight * idle_gap)

    return terms


def _load_balance_objective_terms(
    model: cp_model.CpModel,
    loads: dict[int, cp_model.IntVar],
    weight: int,
) -> list[cp_model.LinearExpr]:
    faculty_ids = sorted(loads)
    terms: list[cp_model.LinearExpr] = []

    for left_index, left_id in enumerate(faculty_ids):
        for right_id in faculty_ids[left_index + 1:]:
            difference = model.new_int_var(0, 100_000, f"load_difference_{left_id}_{right_id}")
            model.add_abs_equality(difference, loads[left_id] - loads[right_id])
            terms.append(-weight * difference)

    return terms


def _assignment(candidate: Candidate) -> dict[str, Any]:
    scores = {
        "time_slot_id": candidate.time_slot_id,
        "priority": candidate.priority,
        "earlier_time_weight": _candidate_weight(candidate),
    }

    return {
        "scheduling_demand_id": candidate.scheduling_demand_id,
        "term_offering_id": candidate.term_offering_id,
        "section_id": candidate.section_id,
        "section_delivery_group_id": candidate.section_delivery_group_id,
        "cohort_or_student_group_id": candidate.cohort_or_student_group_id,
        "cohort_or_student_group_ids": list(candidate.cohort_or_student_group_ids),
        "subject_id": candidate.subject_id,
        "course_component_id": candidate.course_component_id,
        "faculty_id": candidate.faculty_id,
        "faculty_user_id": candidate.faculty_id,
        "room_id": candidate.room_id,
        "day": candidate.day_of_week,
        "day_of_week": candidate.day_of_week,
        "start_time": candidate.starts_at,
        "end_time": candidate.ends_at,
        "starts_at": candidate.starts_at,
        "ends_at": candidate.ends_at,
        "time_slot_id": candidate.time_slot_id,
        "time_block_reference": candidate.time_block_key,
        "time_block_key": candidate.time_block_key,
        "meeting_sequence": candidate.meeting_sequence,
        "meeting_pattern": candidate.meeting_pattern,
        "assignment_status": "ok",
        "violations": [],
        "warnings": [],
        "scores": scores,
        "soft_constraint_scores": scores,
    }


def _conflict_assignment(
    demand: dict[str, Any],
    meeting_sequence: int,
    violations: list[dict[str, str]],
) -> dict[str, Any]:
    return {
        "scheduling_demand_id": _int_or_none(demand.get("scheduling_demand_id")),
        "term_offering_id": _int_or_none(demand.get("term_offering_id")),
        "section_id": _int_or_none(demand.get("section_id")),
        "section_delivery_group_id": _int_or_none(demand.get("section_delivery_group_id")),
        "cohort_or_student_group_id": _int_or_none(demand.get("cohort_or_student_group_id")),
        "subject_id": _int_or_none(demand.get("course_id") or demand.get("subject_id")),
        "course_component_id": _int_or_none(demand.get("course_component_id")),
        "faculty_id": _int_or_none(demand.get("fixed_faculty_user_id")),
        "faculty_user_id": _int_or_none(demand.get("fixed_faculty_user_id")),
        "room_id": _int_or_none(demand.get("fixed_room_id")),
        "day": _int_or_none(demand.get("fixed_day_of_week")),
        "day_of_week": _int_or_none(demand.get("fixed_day_of_week")),
        "start_time": _time_or_none(demand.get("fixed_start_time")),
        "end_time": None,
        "starts_at": _time_or_none(demand.get("fixed_start_time")),
        "ends_at": None,
        "time_slot_id": None,
        "time_block_reference": None,
        "time_block_key": None,
        "meeting_sequence": meeting_sequence,
        "meeting_pattern": _meeting_pattern(demand),
        "assignment_status": "conflict",
        "violations": violations,
        "warnings": [],
        "scores": {},
        "soft_constraint_scores": {},
    }


def _room_required(demand: dict[str, Any]) -> bool:
    modality = str(demand.get("modality") or "").upper()

    return bool(demand.get("room_required")) or modality == "FACE_TO_FACE"


def _duration_minutes(demand: dict[str, Any]) -> int:
    value = demand.get("required_duration_minutes")

    if value in {None, ""}:
        source = demand.get("source_snapshot") if isinstance(demand.get("source_snapshot"), dict) else {}
        value = source.get("weekly_contact_hours")

        try:
            weekly_duration_minutes = max(30, int(float(value) * 60))

            return max(30, weekly_duration_minutes // (_meeting_count(demand) or 1))
        except (TypeError, ValueError):
            return 60

    return max(30, int(value))


def _meeting_count(demand: dict[str, Any]) -> int | None:
    meeting_count = _int_or_none(demand.get("meeting_count"))

    if meeting_count is None or meeting_count < 1 or meeting_count > 3:
        return None

    return meeting_count


def _has_valid_meeting_pattern(demand: dict[str, Any]) -> bool:
    meeting_count = _meeting_count(demand)

    return meeting_count is not None and _duration_minutes(demand) >= 30


def _meeting_pattern(demand: dict[str, Any]) -> str:
    meeting_count = _meeting_count(demand) or 1

    return f"{meeting_count}x{_duration_minutes(demand)}"


def _faculty_priority(demand: dict[str, Any], faculty_id: int) -> int:
    for index, option in enumerate(demand.get("faculty_load_options", []), start=1):
        if not isinstance(option, dict):
            continue

        if _int_or_none(option.get("faculty_user_id")) == faculty_id:
            return index

    return 100


def _inside_faculty_availability(candidate: Candidate, availability: dict[int, list[dict[str, Any]]]) -> bool:
    windows = availability.get(candidate.faculty_id)

    if windows is None:
        return True

    for window in windows:
        day = _int_or_none(window.get("day_of_week"))
        starts = _time_to_minutes(window.get("starts_at"))
        ends = _time_to_minutes(window.get("ends_at"))

        if day == candidate.day_of_week and starts is not None and ends is not None:
            if starts <= candidate.starts_minute and ends >= candidate.ends_minute:
                return True

    return False


def _conflicts_existing(candidate: Candidate, commitments: list[dict[str, Any]]) -> bool:
    for commitment in commitments:
        day = _int_or_none(commitment.get("day_of_week"))
        starts = _time_to_minutes(commitment.get("starts_at"))
        ends = _time_to_minutes(commitment.get("ends_at"))

        if day != candidate.day_of_week or starts is None or ends is None:
            continue

        if candidate.starts_minute >= ends or candidate.ends_minute <= starts:
            continue

        same_delivery_group = _int_or_none(commitment.get("section_delivery_group_id")) == candidate.section_delivery_group_id
        same_faculty = _int_or_none(commitment.get("faculty_id") or commitment.get("faculty_user_id")) == candidate.faculty_id
        same_room = _int_or_none(commitment.get("room_id")) == candidate.room_id

        if same_delivery_group or same_faculty or same_room:
            return True

    return False


def _conflicts_calendar(candidate: Candidate, calendar_blocks: list[dict[str, Any]]) -> bool:
    for block in calendar_blocks:
        room_id = _int_or_none(block.get("room_id"))
        faculty_id = _int_or_none(block.get("faculty_user_id") or block.get("faculty_id"))

        if room_id is not None and room_id != candidate.room_id:
            continue

        if faculty_id is not None and faculty_id != candidate.faculty_id:
            continue

        block_day, block_start, block_end = _block_window(block)

        if block_day != candidate.day_of_week or block_start is None or block_end is None:
            continue

        if candidate.starts_minute < block_end and candidate.ends_minute > block_start:
            return True

    return False


def _block_window(block: dict[str, Any]) -> tuple[int | None, int | None, int | None]:
    if block.get("day_of_week") is not None:
        return (
            _int_or_none(block.get("day_of_week")),
            _time_to_minutes(block.get("starts_at") or block.get("start_time")),
            _time_to_minutes(block.get("ends_at") or block.get("end_time")),
        )

    start_at = _datetime_or_none(block.get("start_at"))
    end_at = _datetime_or_none(block.get("end_at"))

    if start_at is None or end_at is None:
        return None, None, None

    return (
        start_at.isoweekday(),
        (start_at.hour * 60) + start_at.minute,
        (end_at.hour * 60) + end_at.minute,
    )


def _datetime_or_none(value: Any) -> datetime | None:
    if value in {None, ""}:
        return None

    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None


def _overlaps(left: Candidate, right: Candidate) -> bool:
    return (
        left.day_of_week == right.day_of_week
        and left.starts_minute < right.ends_minute
        and left.ends_minute > right.starts_minute
    )


def _candidate_weight(candidate: Candidate) -> int:
    return (
        10_000_000
        - (candidate.day_of_week * 100_000)
        - candidate.starts_minute
        - (candidate.priority * 10)
        - (candidate.faculty_id % 10)
    )


def _earlier_candidate_score(candidate: Candidate) -> int:
    return max(0, 10_000 - ((candidate.day_of_week * 1_000) + candidate.starts_minute))


def _room_efficiency_score(candidate: Candidate) -> int:
    if candidate.room_id is None:
        return 0

    if candidate.expected_count <= 0:
        return max(0, 1_000 - candidate.room_capacity)

    return -max(0, candidate.room_capacity - candidate.expected_count)


def _objective_details(
    selected: list[Candidate],
    weights: dict[str, int],
    snapshot: dict[str, Any],
) -> dict[str, Any]:
    faculty_days: dict[tuple[int, int], list[Candidate]] = {}
    faculty_loads: dict[int, dict[tuple[int, int], int]] = {}

    for candidate in selected:
        faculty_days.setdefault((candidate.faculty_id, candidate.day_of_week), []).append(candidate)
        faculty_loads.setdefault(candidate.faculty_id, {})[
            (candidate.term_offering_id, candidate.section_delivery_group_id)
        ] = candidate.load_units_scaled

    idle_gap_minutes = 0
    for rows in faculty_days.values():
        ordered = sorted(rows, key=lambda candidate: (candidate.starts_minute, candidate.ends_minute))

        if ordered:
            idle_gap_minutes += max(
                0,
                max(candidate.ends_minute for candidate in ordered)
                - min(candidate.starts_minute for candidate in ordered)
                - sum(candidate.duration_minutes for candidate in ordered),
            )

    faculty_ids = {
        int(row["faculty_id"])
        for row in snapshot.get("faculty", [])
        if isinstance(row, dict) and _int_or_none(row.get("faculty_id")) is not None
    }
    loads = [sum(faculty_loads.get(faculty_id, {}).values()) for faculty_id in sorted(faculty_ids)]
    load_imbalance = sum(
        abs(left - right)
        for left_index, left in enumerate(loads)
        for right in loads[left_index + 1:]
    )
    terms = {
        "prefer_earlier_time_blocks": {
            "raw": sum(_earlier_candidate_score(candidate) for candidate in selected),
            "weight": weights["prefer_earlier_time_blocks"],
        },
        "reduce_faculty_idle_gaps": {
            "raw": -idle_gap_minutes,
            "weight": weights["reduce_faculty_idle_gaps"],
        },
        "balance_faculty_load": {
            "raw": -load_imbalance,
            "weight": weights["balance_faculty_load"],
        },
        "use_rooms_efficiently": {
            "raw": sum(_room_efficiency_score(candidate) for candidate in selected),
            "weight": weights["use_rooms_efficiently"],
        },
    }

    for term in terms.values():
        term["weighted"] = term["raw"] * term["weight"]

    return {
        "profile_key": "balanced_v1",
        "profile_version": 1,
        "terms": terms,
        "total": sum(term["weighted"] for term in terms.values()),
    }


def _lexicographic_objective_details(
    snapshot: dict[str, Any],
    selected: list[Candidate],
    completed_levels: list[dict[str, Any]],
) -> dict[str, Any]:
    cohort_days: dict[tuple[int, int], list[Candidate]] = {}
    faculty_days: dict[tuple[int, int], list[Candidate]] = {}
    faculty_loads: dict[int, dict[tuple[int, int], int]] = {}

    for candidate in selected:
        for cohort_id in candidate.cohort_or_student_group_ids:
            cohort_days.setdefault((cohort_id, candidate.day_of_week), []).append(candidate)
        faculty_days.setdefault((candidate.faculty_id, candidate.day_of_week), []).append(candidate)
        faculty_loads.setdefault(candidate.faculty_id, {})[
            (candidate.term_offering_id, candidate.section_delivery_group_id)
        ] = candidate.load_units_scaled

    def idle_minutes(groups: dict[tuple[int, int], list[Candidate]]) -> int:
        total = 0
        for rows in groups.values():
            if rows:
                total += max(candidate.ends_minute for candidate in rows) - min(candidate.starts_minute for candidate in rows) - sum(candidate.duration_minutes for candidate in rows)
        return total

    mode_switches = 0
    for rows in cohort_days.values():
        ordered = sorted(rows, key=lambda candidate: (candidate.starts_minute, candidate.ends_minute))
        mode_switches += sum(
            1 for left, right in zip(ordered, ordered[1:])
            if left.modality != right.modality
        )

    loads = [sum(group_loads.values()) for group_loads in faculty_loads.values()]
    load_imbalance = sum(
        abs(left - right)
        for left_index, left in enumerate(loads)
        for right in loads[left_index + 1:]
    )

    details = {
        "profile_key": "lexicographic_v1",
        "profile_version": 1,
        "objective_hierarchy": list(LEXICOGRAPHIC_TERMS),
        "values": {
            "cohort_mode_switches": mode_switches,
            "cohort_idle_time": idle_minutes(cohort_days),
            "faculty_load_imbalance": load_imbalance,
            "faculty_idle_time": idle_minutes(faculty_days),
            "room_seat_waste": -sum(_room_efficiency_score(candidate) for candidate in selected),
            "stable_earlier_placement": sum(_earlier_candidate_score(candidate) for candidate in selected),
        },
        "completed_levels": completed_levels,
        "scalar_score": None,
    }

    repair = _repair_evidence(snapshot, selected)
    if repair is not None:
        details["repair"] = repair

    return details


def _empty_objective_details(snapshot: dict[str, Any]) -> dict[str, Any]:
    profile = snapshot.get("constraint_profile") if isinstance(snapshot.get("constraint_profile"), dict) else {}
    weights = profile.get("soft_weights") if isinstance(profile.get("soft_weights"), dict) else {}

    if profile.get("key") == "lexicographic_v1":
        return {
            "profile_key": "lexicographic_v1",
            "profile_version": 1,
            "objective_hierarchy": list(LEXICOGRAPHIC_TERMS),
            "values": {term: None for term in LEXICOGRAPHIC_TERMS},
            "completed_levels": [],
            "scalar_score": None,
        }

    return {
        "profile_key": profile.get("key"),
        "profile_version": profile.get("version"),
        "terms": {term: {"raw": None, "weight": int(weights.get(term, 1))} for term in LEGACY_SOFT_TERMS},
    }


def _candidate_sort_key(candidate: Candidate) -> tuple[int, int]:
    return candidate.scheduling_demand_id, candidate.meeting_sequence


def _earlier_time_score(assignments: list[dict[str, Any]]) -> int:
    score = 0

    for assignment in assignments:
        if assignment["assignment_status"] != "ok":
            continue

        day = _int_or_none(assignment.get("day_of_week")) or 0
        starts = _time_to_minutes(assignment.get("starts_at")) or 0
        score += max(0, 10_000 - ((day * 1_000) + starts))

    return score


def _status_name(status: int) -> str:
    if status == cp_model.OPTIMAL:
        return "optimal"

    if status == cp_model.FEASIBLE:
        return "feasible"

    if status == cp_model.INFEASIBLE:
        return "infeasible"

    if status == cp_model.MODEL_INVALID:
        return "model_invalid"

    return "unknown"


def _decimal_scaled(value: Any) -> int:
    try:
        return max(0, int(round(float(value) * 100)))
    except (TypeError, ValueError):
        return 0


def _decimal_or_none(value: Any) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _solver_run_id(snapshot: dict[str, Any]) -> int | None:
    metadata = snapshot.get("run_metadata")

    if not isinstance(metadata, dict):
        return None

    return _int_or_none(metadata.get("solver_run_id") or metadata.get("run_id"))


def _reason(reason_type: str, message: str) -> dict[str, str]:
    return {
        "type": reason_type,
        "message": message,
    }


def _int_or_none(value: Any) -> int | None:
    if value is None or value == "":
        return None

    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _time_to_minutes(value: Any) -> int | None:
    if value is None or value == "":
        return None

    parts = str(value).split(":")

    if len(parts) < 2:
        return None

    try:
        return int(parts[0]) * 60 + int(parts[1])
    except ValueError:
        return None


def _minutes_to_time(value: int) -> str:
    hours = value // 60
    minutes = value % 60

    return f"{hours:02d}:{minutes:02d}:00"


def _time_or_none(value: Any) -> str | None:
    minutes = _time_to_minutes(value)

    return None if minutes is None else _minutes_to_time(minutes)
