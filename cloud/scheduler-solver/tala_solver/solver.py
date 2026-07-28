from __future__ import annotations

import os
from dataclasses import dataclass
from datetime import datetime, timezone
from time import perf_counter
from typing import Any

from ortools import __version__ as ORTOOLS_VERSION
from ortools.sat.python import cp_model


CONTRACT_VERSION = "tal94-demand-v2"
SOLVER_VERSION = "cloud-cp-sat-tal94-demand-v2-staged-search-v1"
SOFT_TERMS = (
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
BALANCED_V1_WEIGHTS = {term: 1 for term in SOFT_TERMS}
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
    priority: int
    duration_minutes: int
    load_units_scaled: int
    room_capacity: int


@dataclass(frozen=True)
class SolverRuntimeConfiguration:
    worker_count: int
    random_seed: int


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


def solve_snapshot(snapshot: dict[str, Any], timeout_seconds: int = 300) -> dict[str, Any]:
    started_at = perf_counter()
    timeout_seconds = max(1, min(int(timeout_seconds), 300))
    solver_run_id = _solver_run_id(snapshot)
    runtime_configuration = solver_runtime_configuration()

    if snapshot.get("contract_version") != CONTRACT_VERSION:
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
    if (
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
            warnings=[_reason("unsupported_constraint_profile", "Solver requires the unchanged balanced_v1 profile at version 1.")],
            infeasible_reasons=[_reason("unsupported_constraint_profile", "Solver requires the unchanged balanced_v1 profile at version 1.")],
            runtime_configuration=runtime_configuration,
        )

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

    demands = [
        {
            **demand,
            "cohort_or_student_group_id": cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])],
        }
        for demand in demands
    ]
    unsupported_demands = [
        demand for demand in demands
        if _int_or_none(demand.get("meeting_count")) != 1
    ]
    if unsupported_demands:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="model_invalid",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("unsupported_meeting_count", "The V2 model requires one generated Scheduling Demand per meeting block.")],
            infeasible_reasons=[_reason("unsupported_meeting_count", "The V2 model requires one generated Scheduling Demand per meeting block.")],
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
    candidates, unassignable_reasons = _enumerate_candidates(snapshot, demands)

    if unassignable_reasons:
        assignments = [
            _conflict_assignment(
                demand,
                unassignable_reasons.get(int(demand["scheduling_demand_id"])) or [
                    _reason("solver_unassigned", "No conflict-free candidate exists for this Scheduling Demand."),
                ],
            )
            for demand in demands
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

    model = cp_model.CpModel()
    variables = [model.new_bool_var(f"candidate_{index}") for index, _ in enumerate(candidates)]

    for demand_id in {candidate.scheduling_demand_id for candidate in candidates}:
        model.add(
            sum(
                variables[index]
                for index, candidate in enumerate(candidates)
                if candidate.scheduling_demand_id == demand_id
            )
            == 1
        )

    _add_no_overlap_constraints(model, variables, candidates)
    _add_same_faculty_constraints(model, variables, candidates, demands)
    load_variables = _add_faculty_load_constraints(model, variables, candidates, snapshot)

    feasibility_solver = _configured_solver(
        timeout_seconds=float(timeout_seconds),
        runtime_configuration=runtime_configuration,
    )
    feasibility_started_at = perf_counter()
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
                    [_reason("solver_infeasible", "CP-SAT proved that no assignment satisfies every hard constraint.")],
                )
                for demand in demands
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

    for variable in variables:
        model.add_hint(variable, int(feasibility_solver.boolean_value(variable)))

    _add_soft_objective(
        model=model,
        variables=variables,
        candidates=candidates,
        load_variables=load_variables,
    )

    remaining_seconds = max(
        0.0,
        float(timeout_seconds) - float(feasibility_stage["wall_time_seconds"] or 0.0),
    )

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
            "cohort_or_student_group_id": cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])],
        }
        for demand in demands
    ]
    candidates, _ = _enumerate_candidates(snapshot, demands)
    candidate_keys = {_candidate_membership_key(candidate) for candidate in candidates}
    results = [
        {
            "scheduling_demand_id": _int_or_none(assignment.get("scheduling_demand_id")),
            "admissible": _assignment_membership_key(assignment) in candidate_keys,
        }
        for assignment in assignments
        if isinstance(assignment, dict)
    ]
    admissible_count = sum(1 for result in results if result["admissible"])
    expected_demand_ids = {
        int(demand["scheduling_demand_id"])
        for demand in demands
    }
    assigned_demand_ids = [
        result["scheduling_demand_id"]
        for result in results
    ]
    complete_demand_coverage = (
        len(assigned_demand_ids) == len(expected_demand_ids)
        and len(set(assigned_demand_ids)) == len(assigned_demand_ids)
        and set(assigned_demand_ids) == expected_demand_ids
    )

    return {
        "expected_demand_count": len(expected_demand_ids),
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
) -> dict[str, Any]:
    runtime_configuration = runtime_configuration or solver_runtime_configuration()
    conflict_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "conflict")
    warning_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "warning")
    assigned_count = len(assignments) - conflict_count
    expected_demand_count = _list_count(snapshot.get("scheduling_demands"))

    return {
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
        "unassigned_count": max(0, expected_demand_count - assigned_count),
        "warning_count": warning_count,
        "timeout": timeout,
    }


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
) -> dict[int, int] | None:
    declared: dict[int, int] = {}

    for row in snapshot.get("student_cohort_groups", []):
        if not isinstance(row, dict):
            return None

        delivery_group_id = _int_or_none(row.get("section_delivery_group_id"))
        cohort_id = _int_or_none(row.get("cohort_or_student_group_id"))

        if delivery_group_id is None or cohort_id is None:
            return None

        if delivery_group_id in declared and declared[delivery_group_id] != cohort_id:
            return None

        declared[delivery_group_id] = cohort_id

    resolved: dict[int, int] = {}

    for demand in demands:
        delivery_group_id = _int_or_none(demand.get("section_delivery_group_id"))
        explicit_cohort_id = _int_or_none(demand.get("cohort_or_student_group_id"))

        if delivery_group_id is None:
            return None

        declared_cohort_id = declared.get(delivery_group_id)

        if (
            explicit_cohort_id is not None
            and declared_cohort_id is not None
            and explicit_cohort_id != declared_cohort_id
        ):
            return None

        cohort_id = explicit_cohort_id or declared_cohort_id

        if cohort_id is None:
            return None

        if delivery_group_id in resolved and resolved[delivery_group_id] != cohort_id:
            return None

        resolved[delivery_group_id] = cohort_id

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
) -> tuple[list[Candidate], dict[int, list[dict[str, str]]]]:
    rooms = _rooms(snapshot)
    time_slots = _time_slots(snapshot)
    availability = _faculty_availability(snapshot)
    existing_commitments = _existing_commitments(snapshot)
    calendar_blocks = _calendar_blocks(snapshot)
    candidates: list[Candidate] = []
    unassignable_reasons: dict[int, list[dict[str, str]]] = {}

    for demand in demands:
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

        for faculty_id in faculty_ids:
            for room_id in room_ids:
                for slot in slots:
                    candidate = _candidate(demand, faculty_id, room_id, slot, rooms.get(room_id, {}))

                    if candidate is None:
                        continue

                    if not _inside_faculty_availability(candidate, availability):
                        continue

                    if _conflicts_existing(candidate, existing_commitments):
                        continue

                    if _conflicts_calendar(candidate, calendar_blocks):
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


def _assignment_membership_key(assignment: dict[str, Any]) -> tuple[Any, ...]:
    return (
        _int_or_none(assignment.get("scheduling_demand_id")),
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
        meeting_sequence=1,
        priority=_faculty_priority(demand, faculty_id),
        duration_minutes=duration,
        load_units_scaled=_decimal_scaled(demand.get("load_units")),
        room_capacity=_int_or_none(room.get("capacity")) or 0,
    )


def _add_no_overlap_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
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
    cohort_days: dict[tuple[int, int], list[cp_model.IntervalVar]] = {}
    faculty_days: dict[tuple[int, int], list[cp_model.IntervalVar]] = {}
    room_days: dict[tuple[int, int], list[cp_model.IntervalVar]] = {}

    for index, candidate in enumerate(candidates):
        interval = intervals[index]
        cohort_days.setdefault(
            (candidate.cohort_or_student_group_id, candidate.day_of_week),
            [],
        ).append(interval)
        faculty_days.setdefault((candidate.faculty_id, candidate.day_of_week), []).append(interval)

        if candidate.room_id is not None:
            room_days.setdefault((candidate.room_id, candidate.day_of_week), []).append(interval)

    for grouped_intervals in (cohort_days, faculty_days, room_days):
        for resource_intervals in grouped_intervals.values():
            if len(resource_intervals) > 1:
                model.add_no_overlap(resource_intervals)


def _add_same_faculty_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
    demands: list[dict[str, Any]],
) -> None:
    grouped_demands: dict[tuple[int, int], list[int]] = {}

    for demand in demands:
        if not bool(demand.get("same_faculty_required")):
            continue

        demand_id = _int_or_none(demand.get("scheduling_demand_id"))
        term_offering_id = _int_or_none(demand.get("term_offering_id"))
        group_id = _int_or_none(demand.get("section_delivery_group_id"))

        if demand_id is None or term_offering_id is None or group_id is None:
            continue

        grouped_demands.setdefault((term_offering_id, group_id), []).append(demand_id)

    for demand_ids in grouped_demands.values():
        if len(demand_ids) < 2:
            continue

        faculty_ids = {
            candidate.faculty_id
            for candidate in candidates
            if candidate.scheduling_demand_id in demand_ids
        }

        for left_demand_id in demand_ids:
            for right_demand_id in demand_ids:
                if left_demand_id >= right_demand_id:
                    continue

                for faculty_id in faculty_ids:
                    left_terms = [
                        variables[index]
                        for index, candidate in enumerate(candidates)
                        if candidate.scheduling_demand_id == left_demand_id and candidate.faculty_id == faculty_id
                    ]
                    right_terms = [
                        variables[index]
                        for index, candidate in enumerate(candidates)
                        if candidate.scheduling_demand_id == right_demand_id and candidate.faculty_id == faculty_id
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
        "meeting_pattern": "single_block",
        "assignment_status": "ok",
        "violations": [],
        "warnings": [],
        "scores": scores,
        "soft_constraint_scores": scores,
    }


def _conflict_assignment(demand: dict[str, Any], violations: list[dict[str, str]]) -> dict[str, Any]:
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
        "meeting_sequence": 1,
        "meeting_pattern": "single_block",
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
            return max(30, int(float(value) * 60))
        except (TypeError, ValueError):
            return 60

    return max(30, int(value))


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
    if candidate.room_id is None or candidate.room_capacity <= 0:
        return 100

    return max(0, 1_000 - candidate.room_capacity)


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


def _empty_objective_details(snapshot: dict[str, Any]) -> dict[str, Any]:
    profile = snapshot.get("constraint_profile") if isinstance(snapshot.get("constraint_profile"), dict) else {}
    weights = profile.get("soft_weights") if isinstance(profile.get("soft_weights"), dict) else {}

    return {
        "profile_key": profile.get("key"),
        "profile_version": profile.get("version"),
        "terms": {term: {"raw": None, "weight": int(weights.get(term, 1))} for term in SOFT_TERMS},
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
