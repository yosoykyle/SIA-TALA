from __future__ import annotations

import os
import threading
from collections.abc import Callable
from contextlib import nullcontext
from dataclasses import dataclass
from datetime import datetime, timezone
from time import perf_counter
from typing import Any

from ortools import __version__ as ORTOOLS_VERSION
from ortools.sat.python import cp_model

from tala_solver.runtime import RequestBudgetExceeded, SolverRequestContext


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
DEFAULT_SOLVER_WORKER_COUNT = 1
DEFAULT_SOLVER_RANDOM_SEED = 20_260_718
APPROVED_SOLVER_WORKER_COUNTS = (1, 2, 4, 8)
DAY_STRIDE_MINUTES = 3_000
MODALITY_BUFFER_MINUTES = 30
SLOT_BOOL_OPTION_LIMIT = 1_000
STAGE2_STAGNATION_SECONDS = 2.5
STAGE2_LEVEL_MAX_SECONDS = 15.0
STAGE2_LARGE_DEMAND_THRESHOLD = 20
STAGE2_LARGE_RELATIVE_GAP_LIMIT = 0.01


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
class ParsedSlot:
    day_of_week: int
    starts_minute: int
    ends_minute: int
    starts_at: str
    time_slot_id: int | None
    time_block_key: str


@dataclass(frozen=True)
class MeetingSpec:
    index: int
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
    meeting_sequence: int
    meeting_pattern: str
    duration_minutes: int
    load_units_scaled: int
    expected_count: int
    room_required: bool
    same_faculty_required: bool
    room_type_requirement: str | None
    valid_slots: tuple[ParsedSlot, ...]
    valid_faculty: tuple[int, ...]
    valid_rooms: tuple[int | None, ...]
    faculty_blocked_slot_indices: dict[int, tuple[int, ...]]
    room_blocked_slot_indices: dict[int, tuple[int, ...]]
    faculty_priorities: dict[int, int]


@dataclass
class FactoredModelState:
    meetings: list[MeetingSpec]
    rooms: dict[int, dict[str, Any]]
    start_vars: list[cp_model.IntVar]
    day_vars: list[cp_model.IntVar]
    min_vars: list[cp_model.IntVar]
    slot_vars: list[list[cp_model.IntVar]]
    fac_vars: list[dict[int, cp_model.IntVar]]
    room_vars: list[dict[int, cp_model.IntVar]]
    fac_bools: list[cp_model.IntVar]
    room_bools: list[cp_model.IntVar]
    load_variables: dict[int, cp_model.IntVar]
    candidate_count: int
    on_day_cache: dict[tuple[int, int], cp_model.IntVar]
    extra_bool_hints: list[tuple[cp_model.IntVar, Callable[[list[Candidate]], int]]]


class _Stage2EarlyStopCallback(cp_model.CpSolverSolutionCallback):
    """Track best Stage-2 incumbent and stop search once objective plateaus."""

    def __init__(self) -> None:
        super().__init__()
        self._lock = threading.Lock()
        self.best_objective: int | None = None
        self.last_improvement_at: float | None = None
        self.solution_count = 0

    def on_solution_callback(self) -> None:
        current = int(round(self.ObjectiveValue()))
        now = perf_counter()
        with self._lock:
            if self.best_objective is None or current > self.best_objective:
                self.best_objective = current
                self.last_improvement_at = now
            self.solution_count += 1

    def check_stagnation(self, stagnation_seconds: float) -> bool:
        with self._lock:
            if self.last_improvement_at is None:
                return False
            if (perf_counter() - self.last_improvement_at) >= stagnation_seconds:
                self.stop_search()
                return True
        return False


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
    if contract_version != CONTRACT_VERSION:
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
            warnings=[_reason("invalid_meeting_pattern", "Every Scheduling Demand requires one to six equal-duration meetings.")],
            infeasible_reasons=[_reason("invalid_meeting_pattern", "Every Scheduling Demand requires one to six equal-duration meetings.")],
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

    if any((_int_or_none(demand.get("expected_count")) or 0) <= 0 for demand in demands):
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
        rooms = _rooms(snapshot)
        parsed_slots = _parsed_time_slots(snapshot)
        commitments_by_key = _commitment_indexes(_existing_commitments(snapshot))
        blocks_by_key = _calendar_block_indexes(_calendar_blocks(snapshot))
        meetings, unassignable_reasons, option_count = _prepare_meetings(
            demands=demands,
            rooms=rooms,
            parsed_slots=parsed_slots,
            commitments_by_key=commitments_by_key,
            blocks_by_key=blocks_by_key,
            request_context=request_context,
        )
    request_context.record_metric("candidate_count", option_count)

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
            infeasible_reasons=_dedupe_reasons(
                [item for items in unassignable_reasons.values() for item in items]
            ),
            runtime_configuration=runtime_configuration,
            request_context=request_context,
        )

    # P1: Fast pre-solve feasibility guards (<1s fail-fast on provable impossibilities)
    with request_context.measure("candidate_enumeration"):
        presolve_reasons = _presolve_feasibility_guards(
            snapshot=snapshot,
            meetings=meetings,
            rooms=rooms,
            parsed_slots=parsed_slots,
            commitments_by_key=commitments_by_key,
            blocks_by_key=blocks_by_key,
        )
    if presolve_reasons:
        assignments = [
            _conflict_assignment(demand, meeting_sequence, presolve_reasons)
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
            infeasible_reasons=presolve_reasons,
            runtime_configuration=runtime_configuration,
            request_context=request_context,
        )

    # P0: Standard factored CP-SAT model construction
    with request_context.measure("hard_model_construction"):
        model = cp_model.CpModel()
        state = _build_factored_hard_model(
            model=model,
            snapshot=snapshot,
            meetings=meetings,
            rooms=rooms,
            commitments_by_key=commitments_by_key,
            blocks_by_key=blocks_by_key,
            option_count=option_count,
            request_context=request_context,
        )
        repair_error = _apply_repair_hard_requirement(snapshot, model, state)
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
                request_context=request_context,
            )

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
                candidate_count=state.candidate_count,
                model=model,
                result_solver=feasibility_solver,
                objective_score=None,
                runtime_configuration=runtime_configuration,
                result_source="none",
                feasibility_stage=feasibility_stage,
                optimization_stage=_not_run_stage_statistics(),
            ),
            runtime_configuration=runtime_configuration,
            request_context=request_context,
        )

    feasibility_selected = _selected_candidates(state=state, solver=feasibility_solver)

    return _solve_lexicographic(
        snapshot=snapshot,
        solver_run_id=solver_run_id,
        model=model,
        state=state,
        feasibility_solver=feasibility_solver,
        feasibility_selected=feasibility_selected,
        feasibility_stage=feasibility_stage,
        started_at=started_at,
        request_context=request_context,
        runtime_configuration=runtime_configuration,
    )


def _solve_lexicographic(
    snapshot: dict[str, Any],
    solver_run_id: str,
    model: cp_model.CpModel,
    state: FactoredModelState,
    feasibility_solver: cp_model.CpSolver,
    feasibility_selected: list[Candidate],
    feasibility_stage: dict[str, Any],
    started_at: float,
    request_context: SolverRequestContext,
    runtime_configuration: SolverRuntimeConfiguration,
) -> dict[str, Any]:
    selected = feasibility_selected
    result_solver = feasibility_solver
    completed_levels: list[dict[str, Any]] = []
    latest_stage = _not_run_stage_statistics()
    any_non_optimal = False
    is_large_problem = len(snapshot.get("scheduling_demands", [])) >= STAGE2_LARGE_DEMAND_THRESHOLD

    try:
        with request_context.measure("objective_construction"):
            objectives = _lexicographic_objectives(
                model=model,
                state=state,
                request_context=request_context,
            )
            repair_objective = _repair_change_objective(snapshot, model, state)
            if repair_objective is not None:
                objectives.insert(0, ("changed_non_requested_meetings", lambda expr=repair_objective: expr))
    except RequestBudgetExceeded:
        return _lexicographic_result(
            snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
            "feasible", started_at, runtime_configuration, feasibility_stage,
            latest_stage, completed_levels,
            [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
            request_context,
        )

    for index, (name, expression_builder) in enumerate(objectives):
        remaining = request_context.remaining_search_seconds()

        if remaining <= 0.001:
            return _lexicographic_result(
                snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
                request_context,
            )

        try:
            with request_context.measure("objective_construction"):
                expression = expression_builder()
        except RequestBudgetExceeded:
            return _lexicographic_result(
                snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
                request_context,
            )

        _apply_solver_hints(model, state, result_solver, selected)

        remaining_levels = max(1, len(objectives) - index)
        per_level_budget = remaining / remaining_levels
        if is_large_problem:
            per_level_budget = min(per_level_budget, STAGE2_LEVEL_MAX_SECONDS)

        model.maximize(expression)
        solver = _configured_solver(
            timeout_seconds=max(0.001, per_level_budget),
            runtime_configuration=runtime_configuration,
            relative_gap_limit=STAGE2_LARGE_RELATIVE_GAP_LIMIT if is_large_problem else 0.0,
        )
        callback = _Stage2EarlyStopCallback() if is_large_problem else None
        stop_event = threading.Event()
        watchdog: threading.Thread | None = None

        if callback is not None:
            def _watch_stagnation() -> None:
                while not stop_event.wait(0.25):
                    if callback.check_stagnation(STAGE2_STAGNATION_SECONDS):
                        break

            watchdog = threading.Thread(target=_watch_stagnation, daemon=True)
            watchdog.start()

        phase_started = perf_counter()
        try:
            with request_context.measure(f"lexicographic_search_{name}"):
                if callback is not None:
                    status = solver.solve(model, callback)
                else:
                    status = solver.solve(model)
        except RequestBudgetExceeded:
            return _lexicographic_result(
                snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_budget_exhausted", "The hard-valid timetable was retained when the lexicographic budget expired.")],
                request_context,
            )
        finally:
            stop_event.set()
            if watchdog is not None:
                watchdog.join(timeout=0.5)

        latest_stage = _search_stage_statistics(
            model=model,
            solver=solver,
            solver_status=status,
            measured_wall_time_seconds=perf_counter() - phase_started,
        )

        if status not in {cp_model.OPTIMAL, cp_model.FEASIBLE}:
            return _lexicographic_result(
                snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
                "feasible", started_at, runtime_configuration, feasibility_stage,
                latest_stage, completed_levels,
                [_reason("optimization_limit_reached", f"The hard-valid timetable was retained before completing {name}.")],
                request_context,
            )

        selected = _selected_candidates(state=state, solver=solver)
        result_solver = solver
        value = int(round(solver.objective_value))
        completed_levels.append({
            "name": name,
            "status": _status_name(status),
            "value": value,
        })

        if status != cp_model.OPTIMAL:
            any_non_optimal = True
            model.add(expression >= value)
        else:
            model.add(expression == value)

    if any_non_optimal:
        return _lexicographic_result(
            snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
            "feasible", started_at, runtime_configuration, feasibility_stage,
            latest_stage, completed_levels,
            [_reason("lexicographic_level_feasible", "One or more lexicographic levels reached their time or stagnation cap; achieved quality floors were preserved.")],
            request_context,
        )

    return _lexicographic_result(
        snapshot, solver_run_id, model, state.candidate_count, selected, result_solver,
        "optimal", started_at, runtime_configuration, feasibility_stage,
        latest_stage, completed_levels, [], request_context,
    )


def _apply_solver_hints(
    model: cp_model.CpModel,
    state: FactoredModelState,
    solver: cp_model.CpSolver,
    selected: list[Candidate],
) -> None:
    model.clear_hints()
    if state.slot_vars:
        for vars_m in state.slot_vars:
            for variable in vars_m:
                model.add_hint(variable, int(solver.boolean_value(variable)))
        for variable in state.fac_bools:
            model.add_hint(variable, int(solver.boolean_value(variable)))
        for variable in state.room_bools:
            model.add_hint(variable, int(solver.boolean_value(variable)))
        return

    for idx, candidate in enumerate(selected):
        ws = candidate.day_of_week * DAY_STRIDE_MINUTES + candidate.starts_minute
        model.add_hint(state.start_vars[idx], ws)
        model.add_hint(state.day_vars[idx], candidate.day_of_week)
        model.add_hint(state.min_vars[idx], candidate.starts_minute)

    for variable in state.fac_bools:
        model.add_hint(variable, int(solver.boolean_value(variable)))
    for variable in state.room_bools:
        model.add_hint(variable, int(solver.boolean_value(variable)))
    for (m_idx, day), on_day_var in state.on_day_cache.items():
        if m_idx < len(selected):
            model.add_hint(on_day_var, int(selected[m_idx].day_of_week == day))
    for var, value_fn in state.extra_bool_hints:
        model.add_hint(var, value_fn(selected))


def _lexicographic_result(
    snapshot: dict[str, Any],
    solver_run_id: int | None,
    model: cp_model.CpModel,
    candidate_count: int,
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
            candidate_count=candidate_count,
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
    *,
    relative_gap_limit: float = 0.0,
) -> cp_model.CpSolver:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.001, timeout_seconds)
    solver.parameters.num_workers = runtime_configuration.worker_count
    solver.parameters.random_seed = runtime_configuration.random_seed
    if relative_gap_limit > 0.0:
        solver.parameters.relative_gap_limit = relative_gap_limit

    return solver


def _selected_candidates(
    state: FactoredModelState,
    solver: cp_model.CpSolver,
) -> list[Candidate]:
    selected: list[Candidate] = []
    for meeting in state.meetings:
        chosen_slot: ParsedSlot | None = None
        if state.slot_vars:
            for s_idx, slot_var in enumerate(state.slot_vars[meeting.index]):
                if solver.boolean_value(slot_var):
                    chosen_slot = meeting.valid_slots[s_idx]
                    break
        else:
            ws = int(solver.value(state.start_vars[meeting.index]))
            target_day = ws // DAY_STRIDE_MINUTES
            target_minute = ws % DAY_STRIDE_MINUTES
            for slot in meeting.valid_slots:
                if slot.day_of_week == target_day and slot.starts_minute == target_minute:
                    chosen_slot = slot
                    break

        if chosen_slot is None:
            chosen_slot = meeting.valid_slots[0]

        if len(meeting.valid_faculty) == 1:
            chosen_faculty = meeting.valid_faculty[0]
        else:
            fac_vars_m = state.fac_vars[meeting.index]
            chosen_faculty = next(
                (fid for fid, var in fac_vars_m.items() if solver.boolean_value(var)),
                meeting.valid_faculty[0],
            )

        if len(meeting.valid_rooms) == 1:
            chosen_room = meeting.valid_rooms[0]
        else:
            room_vars_m = state.room_vars[meeting.index]
            chosen_room = next(
                (rid for rid, var in room_vars_m.items() if solver.boolean_value(var)),
                meeting.valid_rooms[0],
            )

        room_data = state.rooms.get(chosen_room, {}) if chosen_room is not None else {}
        starts_minute = chosen_slot.starts_minute
        ends_minute = starts_minute + meeting.duration_minutes
        selected.append(
            Candidate(
                scheduling_demand_id=meeting.scheduling_demand_id,
                demand_key=meeting.demand_key,
                term_offering_id=meeting.term_offering_id,
                section_id=meeting.section_id,
                section_delivery_group_id=meeting.section_delivery_group_id,
                cohort_or_student_group_id=meeting.cohort_or_student_group_id,
                cohort_or_student_group_ids=meeting.cohort_or_student_group_ids,
                modality=meeting.modality,
                subject_id=meeting.subject_id,
                course_component_id=meeting.course_component_id,
                faculty_id=chosen_faculty,
                room_id=chosen_room,
                day_of_week=chosen_slot.day_of_week,
                starts_at=chosen_slot.starts_at,
                ends_at=_minutes_to_time(ends_minute),
                starts_minute=starts_minute,
                ends_minute=ends_minute,
                time_slot_id=chosen_slot.time_slot_id,
                time_block_key=chosen_slot.time_block_key,
                meeting_sequence=meeting.meeting_sequence,
                meeting_pattern=meeting.meeting_pattern,
                priority=meeting.faculty_priorities.get(chosen_faculty, 100),
                duration_minutes=meeting.duration_minutes,
                load_units_scaled=meeting.load_units_scaled,
                room_capacity=_int_or_none(room_data.get("capacity")) or 0,
                expected_count=meeting.expected_count,
            )
        )

    return selected


def _lexicographic_objectives(
    model: cp_model.CpModel,
    state: FactoredModelState,
    request_context: SolverRequestContext,
) -> list[tuple[str, Callable[[], cp_model.LinearExpr]]]:
    return [
        (
            "cohort_mode_switches",
            lambda: sum(_cohort_mode_switch_objective_terms(model, state, request_context)),
        ),
        (
            "cohort_idle_time",
            lambda: sum(_cohort_idle_gap_objective_terms(model, state)),
        ),
        (
            "faculty_load_imbalance",
            lambda: sum(_load_balance_objective_terms(model, state.load_variables, 1)),
        ),
        (
            "faculty_idle_time",
            lambda: sum(_faculty_idle_gap_objective_terms(model, state)),
        ),
        (
            "room_seat_waste",
            lambda: sum(_room_efficiency_objective_terms(state)),
        ),
        (
            "stable_earlier_placement",
            lambda: sum(_earlier_placement_objective_terms(model, state)),
        ),
    ]


def _on_day_bool(
    model: cp_model.CpModel,
    state: FactoredModelState,
    meeting: MeetingSpec,
    day: int,
    slots_d: list[tuple[int, ParsedSlot]],
) -> cp_model.IntVar:
    cached = state.on_day_cache.get((meeting.index, day))
    if cached is not None:
        return cached

    if state.slot_vars:
        vars_d = [state.slot_vars[meeting.index][s_idx] for s_idx, _ in slots_d]
        if len(vars_d) == 1:
            on_day = vars_d[0]
        else:
            on_day = model.new_bool_var(f"on_day_{meeting.index}_{day}")
            model.add(on_day == sum(vars_d))
    else:
        available_days = {sl.day_of_week for sl in meeting.valid_slots}
        if len(available_days) == 1 and day in available_days:
            on_day = model.new_constant(1)
        else:
            on_day = model.new_bool_var(f"on_day_{meeting.index}_{day}")
            model.add(state.day_vars[meeting.index] == day).only_enforce_if(on_day)
            model.add(state.day_vars[meeting.index] != day).only_enforce_if(on_day.Not())

    state.on_day_cache[(meeting.index, day)] = on_day
    return on_day


def _cohort_mode_switch_objective_terms(
    model: cp_model.CpModel,
    state: FactoredModelState,
    request_context: SolverRequestContext,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    by_cohort_day_start: dict[tuple[int, int], dict[int, list[tuple[MeetingSpec, int]]]] = {}

    for meeting in state.meetings:
        for s_idx, slot in enumerate(meeting.valid_slots):
            for cohort_id in meeting.cohort_or_student_group_ids:
                by_cohort_day_start.setdefault(
                    (cohort_id, slot.day_of_week),
                    {},
                ).setdefault(slot.starts_minute, []).append((meeting, s_idx))

    for (cohort_id, day), positions_by_start in by_cohort_day_start.items():
        modalities = sorted({
            meeting.modality
            for items in positions_by_start.values()
            for meeting, _ in items
        })
        if len(modalities) < 2 or len(positions_by_start) < 2:
            continue

        modality_codes = {modality: idx for idx, modality in enumerate(modalities, start=1)}
        maximum_mode = max(modality_codes.values(), default=1)
        previous_mode: cp_model.IntVar | None = None

        for position, (starts_minute, items) in enumerate(sorted(positions_by_start.items())):
            request_context.checkpoint("objective_construction")
            unique_items = list({meeting.index: (meeting, s_idx) for meeting, s_idx in items}.values())
            at_vars: list[tuple[MeetingSpec, cp_model.IntVar]] = []
            ws = day * DAY_STRIDE_MINUTES + starts_minute

            for meeting, s_idx in unique_items:
                if state.slot_vars:
                    at_vars.append((meeting, state.slot_vars[meeting.index][s_idx]))
                else:
                    at_var = model.new_bool_var(f"at_{cohort_id}_{day}_{starts_minute}_{meeting.index}")
                    model.add(state.start_vars[meeting.index] == ws).only_enforce_if(at_var)
                    model.add(state.start_vars[meeting.index] != ws).only_enforce_if(at_var.Not())
                    m_idx = meeting.index
                    state.extra_bool_hints.append(
                        (
                            at_var,
                            lambda sel, idx=m_idx, target_d=day, target_m=starts_minute: int(
                                sel[idx].day_of_week == target_d and sel[idx].starts_minute == target_m
                            ),
                        )
                    )
                    at_vars.append((meeting, at_var))

            starts_here = model.new_bool_var(f"cohort_starts_{cohort_id}_{day}_{position}")
            model.add(starts_here == sum(var for _, var in at_vars))
            current_mode = model.new_int_var(0, maximum_mode, f"cohort_mode_{cohort_id}_{day}_{position}")
            model.add(
                current_mode
                == sum(modality_codes[meeting.modality] * var for meeting, var in at_vars)
            )
            last_mode = model.new_int_var(0, maximum_mode, f"cohort_last_mode_{cohort_id}_{day}_{position}")

            if previous_mode is None:
                model.add(last_mode == current_mode)
                previous_mode = last_mode
                continue

            model.add(last_mode == current_mode).only_enforce_if(starts_here)
            model.add(last_mode == previous_mode).only_enforce_if(starts_here.Not())
            has_previous = model.new_bool_var(f"cohort_has_previous_{cohort_id}_{day}_{position}")
            model.add(previous_mode != 0).only_enforce_if(has_previous)
            model.add(previous_mode == 0).only_enforce_if(has_previous.Not())
            changed = model.new_bool_var(f"cohort_mode_changed_{cohort_id}_{day}_{position}")
            model.add(current_mode != previous_mode).only_enforce_if(changed)
            model.add(current_mode == previous_mode).only_enforce_if(changed.Not())
            switch = model.new_bool_var(f"cohort_mode_switch_{cohort_id}_{day}_{position}")
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
    state: FactoredModelState,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    cohort_days: dict[tuple[int, int], list[tuple[MeetingSpec, list[tuple[int, ParsedSlot]]]]] = {}

    for meeting in state.meetings:
        slots_by_day: dict[int, list[tuple[int, ParsedSlot]]] = {}
        for s_idx, slot in enumerate(meeting.valid_slots):
            slots_by_day.setdefault(slot.day_of_week, []).append((s_idx, slot))
        for day, slots_d in slots_by_day.items():
            for cohort_id in meeting.cohort_or_student_group_ids:
                cohort_days.setdefault((cohort_id, day), []).append((meeting, slots_d))

    for (cohort_id, day), meeting_entries in cohort_days.items():
        if len(meeting_entries) < 2:
            continue

        horizon_start = min(slot.starts_minute for _, slots_d in meeting_entries for _, slot in slots_d)
        horizon_end = max(
            slot.starts_minute + meeting.duration_minutes
            for meeting, slots_d in meeting_entries
            for _, slot in slots_d
        )
        day_actives: list[cp_model.IntVar] = []
        effective_starts: list[cp_model.IntVar] = []
        effective_ends: list[cp_model.IntVar] = []
        duration_terms: list[cp_model.LinearExpr] = []

        for meeting, slots_d in meeting_entries:
            on_day = _on_day_bool(model, state, meeting, day, slots_d)
            m_start: cp_model.LinearExpr
            if state.slot_vars:
                m_start = sum(slot.starts_minute * state.slot_vars[meeting.index][s_idx] for s_idx, slot in slots_d)
            else:
                m_start = state.min_vars[meeting.index]

            eff_start = model.new_int_var(
                horizon_start,
                horizon_end,
                f"cohort_day_start_{cohort_id}_{day}_{meeting.index}",
            )
            eff_end = model.new_int_var(
                horizon_start,
                horizon_end,
                f"cohort_day_end_{cohort_id}_{day}_{meeting.index}",
            )
            model.add(eff_start == m_start).only_enforce_if(on_day)
            model.add(eff_start == horizon_end).only_enforce_if(on_day.Not())
            model.add(eff_end == m_start + meeting.duration_minutes).only_enforce_if(on_day)
            model.add(eff_end == horizon_start).only_enforce_if(on_day.Not())

            day_actives.append(on_day)
            effective_starts.append(eff_start)
            effective_ends.append(eff_end)
            duration_terms.append(meeting.duration_minutes * on_day)

        active = model.new_bool_var(f"cohort_day_active_{cohort_id}_{day}")
        model.add_max_equality(active, day_actives)
        first_start = model.new_int_var(horizon_start, horizon_end, f"cohort_first_{cohort_id}_{day}")
        last_end = model.new_int_var(horizon_start, horizon_end, f"cohort_last_{cohort_id}_{day}")
        idle = model.new_int_var(0, horizon_end - horizon_start, f"cohort_idle_{cohort_id}_{day}")
        model.add_min_equality(first_start, effective_starts)
        model.add_max_equality(last_end, effective_ends)
        model.add(idle == last_end - first_start - sum(duration_terms)).only_enforce_if(active)
        model.add(idle == 0).only_enforce_if(active.Not())
        terms.append(-idle)

    return terms


def _faculty_idle_gap_objective_terms(
    model: cp_model.CpModel,
    state: FactoredModelState,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    faculty_days: dict[tuple[int, int], list[tuple[MeetingSpec, list[tuple[int, ParsedSlot]]]]] = {}

    for meeting in state.meetings:
        for faculty_id in meeting.valid_faculty:
            blocked = set(meeting.faculty_blocked_slot_indices.get(faculty_id, ()))
            slots_by_day: dict[int, list[tuple[int, ParsedSlot]]] = {}
            for s_idx, slot in enumerate(meeting.valid_slots):
                if s_idx in blocked:
                    continue
                slots_by_day.setdefault(slot.day_of_week, []).append((s_idx, slot))
            for day, slots_d in slots_by_day.items():
                faculty_days.setdefault((faculty_id, day), []).append((meeting, slots_d))

    for (faculty_id, day), meeting_entries in faculty_days.items():
        if len(meeting_entries) < 2:
            continue

        horizon_start = min(slot.starts_minute for _, slots_d in meeting_entries for _, slot in slots_d)
        horizon_end = max(
            slot.starts_minute + meeting.duration_minutes
            for meeting, slots_d in meeting_entries
            for _, slot in slots_d
        )
        day_actives: list[cp_model.IntVar] = []
        effective_starts: list[cp_model.IntVar] = []
        effective_ends: list[cp_model.IntVar] = []
        duration_terms: list[cp_model.LinearExpr] = []

        for meeting, slots_d in meeting_entries:
            if state.slot_vars:
                on_day_vars = [state.slot_vars[meeting.index][s_idx] for s_idx, _ in slots_d]
                if len(on_day_vars) == 1:
                    on_day = on_day_vars[0]
                else:
                    on_day = model.new_bool_var(f"fac_on_day_{faculty_id}_{day}_{meeting.index}")
                    model.add(on_day == sum(on_day_vars))
                m_start: cp_model.LinearExpr = sum(
                    slot.starts_minute * state.slot_vars[meeting.index][s_idx] for s_idx, slot in slots_d
                )
            else:
                all_slots_d = [
                    (s_idx, sl)
                    for s_idx, sl in enumerate(meeting.valid_slots)
                    if sl.day_of_week == day
                ]
                on_day = _on_day_bool(model, state, meeting, day, all_slots_d)
                m_start = state.min_vars[meeting.index]

            if len(meeting.valid_faculty) == 1:
                active_m_f_d = on_day
            else:
                fac_var = state.fac_vars[meeting.index][faculty_id]
                active_m_f_d = model.new_bool_var(f"fac_active_{faculty_id}_{day}_{meeting.index}")
                model.add_bool_and([on_day, fac_var]).only_enforce_if(active_m_f_d)
                model.add_bool_or([on_day.Not(), fac_var.Not(), active_m_f_d])
                if not state.slot_vars:
                    m_idx = meeting.index
                    state.extra_bool_hints.append(
                        (
                            active_m_f_d,
                            lambda sel, idx=m_idx, target_d=day, target_f=faculty_id: int(
                                sel[idx].day_of_week == target_d and sel[idx].faculty_id == target_f
                            ),
                        )
                    )

            eff_start = model.new_int_var(
                horizon_start,
                horizon_end,
                f"faculty_day_start_{faculty_id}_{day}_{meeting.index}",
            )
            eff_end = model.new_int_var(
                horizon_start,
                horizon_end,
                f"faculty_day_end_{faculty_id}_{day}_{meeting.index}",
            )
            model.add(eff_start == m_start).only_enforce_if(active_m_f_d)
            model.add(eff_start == horizon_end).only_enforce_if(active_m_f_d.Not())
            model.add(eff_end == m_start + meeting.duration_minutes).only_enforce_if(active_m_f_d)
            model.add(eff_end == horizon_start).only_enforce_if(active_m_f_d.Not())

            day_actives.append(active_m_f_d)
            effective_starts.append(eff_start)
            effective_ends.append(eff_end)
            duration_terms.append(meeting.duration_minutes * active_m_f_d)

        active = model.new_bool_var(f"faculty_day_active_{faculty_id}_{day}")
        model.add_max_equality(active, day_actives)
        first_start = model.new_int_var(horizon_start, horizon_end, f"faculty_day_first_start_{faculty_id}_{day}")
        last_end = model.new_int_var(horizon_start, horizon_end, f"faculty_day_last_end_{faculty_id}_{day}")
        idle_gap = model.new_int_var(0, horizon_end - horizon_start, f"faculty_day_idle_gap_{faculty_id}_{day}")
        model.add_min_equality(first_start, effective_starts)
        model.add_max_equality(last_end, effective_ends)
        model.add(idle_gap == last_end - first_start - sum(duration_terms)).only_enforce_if(active)
        model.add(idle_gap == 0).only_enforce_if(active.Not())
        terms.append(-idle_gap)

    return terms


def _room_efficiency_objective_terms(state: FactoredModelState) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    for meeting in state.meetings:
        if not meeting.room_required:
            continue
        if len(meeting.valid_rooms) == 1:
            room_id = meeting.valid_rooms[0]
            if room_id is not None:
                terms.append(_room_efficiency_value(state.rooms.get(room_id, {}), meeting.expected_count))
            continue
        for room_id, room_var in state.room_vars[meeting.index].items():
            score = _room_efficiency_value(state.rooms.get(room_id, {}), meeting.expected_count)
            if score != 0:
                terms.append(score * room_var)
    return terms


def _earlier_placement_objective_terms(
    model: cp_model.CpModel,
    state: FactoredModelState,
) -> list[cp_model.LinearExpr]:
    terms: list[cp_model.LinearExpr] = []
    if state.slot_vars:
        for meeting in state.meetings:
            for s_idx, slot in enumerate(meeting.valid_slots):
                score = _slot_earlier_score(slot.day_of_week, slot.starts_minute)
                if score != 0:
                    terms.append(score * state.slot_vars[meeting.index][s_idx])
        return terms

    for meeting in state.meetings:
        max_slot_cost = max((sl.day_of_week * 1_000) + sl.starts_minute for sl in meeting.valid_slots)
        if max_slot_cost <= 10_000:
            terms.append(10_000 - (state.day_vars[meeting.index] * 1_000 + state.min_vars[meeting.index]))
        else:
            clamped = model.new_int_var(0, 10_000, f"earlier_{meeting.index}")
            model.add_max_equality(
                clamped,
                [
                    model.new_constant(0),
                    10_000 - (state.day_vars[meeting.index] * 1_000 + state.min_vars[meeting.index]),
                ],
            )
            terms.append(clamped)
    return terms


def evaluate_candidate_membership(
    snapshot: dict[str, Any],
    assignments: list[dict[str, Any]],
) -> dict[str, Any]:
    """Replay assignments through factored admissibility checks without Cartesian enumeration."""
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
    rooms = _rooms(snapshot)
    parsed_slots = _parsed_time_slots(snapshot)
    day_ends = _parsed_day_ends(parsed_slots)
    commitments_by_key = _commitment_indexes(_existing_commitments(snapshot))
    blocks_by_key = _calendar_block_indexes(_calendar_blocks(snapshot))

    demands_by_id = {int(demand["scheduling_demand_id"]): demand for demand in demands}
    results: list[dict[str, Any]] = []

    for assignment in assignments:
        if not isinstance(assignment, dict):
            continue
        demand_id = _int_or_none(assignment.get("scheduling_demand_id"))
        meeting_sequence = _int_or_none(assignment.get("meeting_sequence")) or 1
        demand = demands_by_id.get(demand_id) if demand_id is not None else None
        admissible = (
            demand is not None
            and _assignment_is_admissible(
                demand=demand,
                meeting_sequence=meeting_sequence,
                assignment=assignment,
                rooms=rooms,
                parsed_slots=parsed_slots,
                day_ends=day_ends,
                commitments_by_key=commitments_by_key,
                blocks_by_key=blocks_by_key,
            )
        )
        results.append({
            "scheduling_demand_id": demand_id,
            "meeting_sequence": meeting_sequence,
            "admissible": admissible,
        })

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


def _assignment_is_admissible(
    *,
    demand: dict[str, Any],
    meeting_sequence: int,
    assignment: dict[str, Any],
    rooms: dict[int, dict[str, Any]],
    parsed_slots: list[ParsedSlot],
    day_ends: dict[int, int],
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> bool:
    meeting_count = _meeting_count(demand) or 1
    if meeting_sequence < 1 or meeting_sequence > meeting_count:
        return False

    if (
        (_int_or_none(assignment.get("term_offering_id")) or 0) != (_int_or_none(demand.get("term_offering_id")) or 0)
        or (_int_or_none(assignment.get("section_id")) or 0) != (_int_or_none(demand.get("section_id")) or 0)
        or (_int_or_none(assignment.get("section_delivery_group_id")) or 0) != (_int_or_none(demand.get("section_delivery_group_id")) or 0)
        or (_int_or_none(assignment.get("cohort_or_student_group_id")) or 0) != (_int_or_none(demand.get("cohort_or_student_group_id")) or 0)
        or _int_or_none(assignment.get("subject_id") or assignment.get("course_id")) != _int_or_none(demand.get("course_id") or demand.get("subject_id"))
        or _int_or_none(assignment.get("course_component_id")) != _int_or_none(demand.get("course_component_id"))
    ):
        return False

    faculty_id = _int_or_none(assignment.get("faculty_user_id") or assignment.get("faculty_id"))
    if faculty_id is None or faculty_id not in _faculty_ids(demand, meeting_sequence=meeting_sequence):
        return False

    room_id = _int_or_none(assignment.get("room_id"))
    if room_id not in _room_ids(demand, rooms, meeting_sequence=meeting_sequence):
        return False

    raw_fixed_sequence = demand.get("fixed_meeting_sequence")
    fixed_sequence = _int_or_none(raw_fixed_sequence)
    has_fixed_time_pin = (
        _int_or_none(demand.get("fixed_day_of_week")) is not None
        or _time_to_minutes(demand.get("fixed_start_time")) is not None
    )
    if (
        has_fixed_time_pin
        and raw_fixed_sequence not in {None, ""}
        and (fixed_sequence is None or fixed_sequence < 1 or fixed_sequence > meeting_count)
    ):
        return False

    duration = _duration_minutes(demand)
    day = _int_or_none(assignment.get("day_of_week") or assignment.get("day"))
    starts_at = _time_or_none(assignment.get("starts_at") or assignment.get("start_time"))
    ends_at = _time_or_none(assignment.get("ends_at") or assignment.get("end_time"))
    time_slot_id = _int_or_none(assignment.get("time_slot_id"))
    time_block_key = str(assignment.get("time_block_key") or assignment.get("time_block_reference") or "")
    starts_minute = _time_to_minutes(starts_at)

    if day is None or starts_minute is None or ends_at != _minutes_to_time(starts_minute + duration):
        return False

    slots = _slots_for_demand_parsed(demand, parsed_slots, day_ends, meeting_sequence=meeting_sequence)
    matching_slot = next(
        (
            slot
            for slot in slots
            if slot.day_of_week == day
            and slot.starts_at == starts_at
            and slot.time_slot_id == time_slot_id
            and slot.time_block_key == time_block_key
        ),
        None,
    )
    if matching_slot is None:
        return False

    ends_minute = starts_minute + duration
    dg_id = _int_or_none(demand.get("section_delivery_group_id")) or 0
    if _window_conflicts_existing(
        day=day,
        starts_minute=starts_minute,
        ends_minute=ends_minute,
        delivery_group_id=dg_id,
        faculty_id=faculty_id,
        room_id=room_id,
        indexes=commitments_by_key,
    ):
        return False

    if _window_conflicts_calendar(
        day=day,
        starts_minute=starts_minute,
        ends_minute=ends_minute,
        faculty_id=faculty_id,
        room_id=room_id,
        indexes=blocks_by_key,
    ):
        return False

    return True


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
    candidate_count: int,
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

    if objective_score is not None and best_objective_bound is not None:
        relative_optimality_gap = abs(float(objective_score) - best_objective_bound) / max(
            1.0,
            abs(float(objective_score)),
        )

    stages = [feasibility_stage, optimization_stage]
    statistics.update({
        "candidate_count": candidate_count,
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


def _parsed_time_slots(snapshot: dict[str, Any]) -> list[ParsedSlot]:
    raw_slots = sorted(
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
    parsed: list[ParsedSlot] = []
    for slot in raw_slots:
        day = _int_or_none(slot.get("day_of_week"))
        starts_minute = _time_to_minutes(slot.get("starts_at"))
        if day is None or starts_minute is None:
            continue
        ends_minute = _time_to_minutes(slot.get("ends_at"))
        if ends_minute is None:
            ends_minute = starts_minute + (_int_or_none(slot.get("duration_minutes")) or 30)
        parsed.append(
            ParsedSlot(
                day_of_week=day,
                starts_minute=starts_minute,
                ends_minute=ends_minute,
                starts_at=_minutes_to_time(starts_minute),
                time_slot_id=_int_or_none(slot.get("time_slot_id")),
                time_block_key=str(slot.get("time_block_key") or f"D{day}-{starts_minute}"),
            )
        )
    return parsed


def _parsed_day_ends(parsed_slots: list[ParsedSlot]) -> dict[int, int]:
    ends: dict[int, int] = {}
    for slot in parsed_slots:
        ends[slot.day_of_week] = max(ends.get(slot.day_of_week, slot.ends_minute), slot.ends_minute)
    return ends


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


def _fixed_meetings_by_sequence(demand: dict[str, Any]) -> dict[int, dict[str, Any]]:
    raw = demand.get("fixed_meetings")
    if not isinstance(raw, list):
        return {}

    by_sequence: dict[int, dict[str, Any]] = {}
    for item in raw:
        if not isinstance(item, dict):
            continue
        seq = _int_or_none(item.get("meeting_sequence"))
        if seq is not None:
            by_sequence[seq] = item

    return by_sequence


def _faculty_ids(demand: dict[str, Any], meeting_sequence: int | None = None) -> list[int]:
    fixed_meetings = _fixed_meetings_by_sequence(demand)
    if meeting_sequence is not None and fixed_meetings:
        pin = fixed_meetings.get(meeting_sequence)
        fixed_faculty_id = (
            _int_or_none(pin.get("fixed_faculty_user_id"))
            if pin is not None
            else _int_or_none(demand.get("unscoped_fixed_faculty_user_id"))
        )
    else:
        fixed_faculty_id = _int_or_none(demand.get("fixed_faculty_user_id"))

    eligible = [
        int(faculty_id)
        for faculty_id in demand.get("eligible_faculty_user_ids", [])
        if _int_or_none(faculty_id) is not None
    ]

    if fixed_faculty_id is not None:
        return [fixed_faculty_id] if fixed_faculty_id in eligible else []

    return sorted(set(eligible))


def _room_ids(
    demand: dict[str, Any],
    rooms: dict[int, dict[str, Any]],
    meeting_sequence: int | None = None,
) -> list[int | None]:
    if not _room_required(demand):
        return [None]

    fixed_meetings = _fixed_meetings_by_sequence(demand)
    if meeting_sequence is not None and fixed_meetings:
        pin = fixed_meetings.get(meeting_sequence)
        fixed_room_id = (
            _int_or_none(pin.get("fixed_room_id"))
            if pin is not None
            else _int_or_none(demand.get("unscoped_fixed_room_id"))
        )
    else:
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


def _prepare_meetings(
    *,
    demands: list[dict[str, Any]],
    rooms: dict[int, dict[str, Any]],
    parsed_slots: list[ParsedSlot],
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
    request_context: SolverRequestContext,
) -> tuple[list[MeetingSpec], dict[int, list[dict[str, str]]], int]:
    day_ends = _parsed_day_ends(parsed_slots)
    raw_meetings: list[MeetingSpec] = []
    unassignable_reasons: dict[int, list[dict[str, str]]] = {}

    for demand in demands:
        request_context.checkpoint("candidate_enumeration")
        demand_id = _int_or_none(demand.get("scheduling_demand_id"))
        if demand_id is None:
            continue

        reasons: list[dict[str, str]] = []
        meeting_count = _meeting_count(demand) or 1
        duration = _duration_minutes(demand)
        room_req = _room_required(demand)
        same_fac_req = bool(demand.get("same_faculty_required"))
        sequence_faculty_ids = {
            seq: _faculty_ids(demand, meeting_sequence=seq)
            for seq in range(1, meeting_count + 1)
        }
        sequence_room_ids = {
            seq: _room_ids(demand, rooms, meeting_sequence=seq)
            for seq in range(1, meeting_count + 1)
        }

        raw_fixed_sequence = demand.get("fixed_meeting_sequence")
        fixed_sequence = _int_or_none(raw_fixed_sequence)
        has_fixed_time_pin = (
            _int_or_none(demand.get("fixed_day_of_week")) is not None
            or _time_to_minutes(demand.get("fixed_start_time")) is not None
        )
        sequence_slots: dict[int, list[ParsedSlot]] = {}
        missing_time_slot = False

        if (
            has_fixed_time_pin
            and raw_fixed_sequence not in {None, ""}
            and (fixed_sequence is None or fixed_sequence < 1 or fixed_sequence > meeting_count)
        ):
            missing_time_slot = True
        else:
            for seq in range(1, meeting_count + 1):
                slots = _slots_for_demand_parsed(demand, parsed_slots, day_ends, meeting_sequence=seq)
                sequence_slots[seq] = slots
                if not slots:
                    missing_time_slot = True

        if any(not f_ids for f_ids in sequence_faculty_ids.values()):
            reasons.append(_reason("missing_faculty", "No eligible faculty was available in the Scheduling Demand snapshot."))

        if room_req and any(not r_ids for r_ids in sequence_room_ids.values()):
            reasons.append(_reason("missing_room", "No active room matched the Scheduling Demand room requirement."))

        if missing_time_slot:
            reasons.append(_reason("missing_time_slot", "No usable time slot matched the Scheduling Demand duration or fixed time."))

        if reasons:
            unassignable_reasons[demand_id] = reasons
            continue

        dg_id = _int_or_none(demand.get("section_delivery_group_id")) or 0
        cohort_ids = tuple(
            sorted({
                cid
                for cid in (_int_or_none(v) for v in demand.get("cohort_or_student_group_ids", []))
                if cid is not None
            })
        ) or (_int_or_none(demand.get("cohort_or_student_group_id")) or 0,)
        priorities = {
            fid: _faculty_priority(demand, fid)
            for seq in range(1, meeting_count + 1)
            for fid in sequence_faculty_ids[seq]
        }

        for seq in range(1, meeting_count + 1):
            slots_seq = sequence_slots[seq]
            facs_seq = sequence_faculty_ids[seq]
            rooms_seq = sequence_room_ids[seq]

            base_slots = [
                slot
                for slot in slots_seq
                if not _slot_conflicts_global_or_dg(
                    slot=slot,
                    duration=duration,
                    delivery_group_id=dg_id,
                    commitments_by_key=commitments_by_key,
                    blocks_by_key=blocks_by_key,
                )
            ]

            viable_slots, viable_facs, viable_rooms, fac_blocked, room_blocked = _filter_viable_options(
                slots=base_slots,
                duration=duration,
                faculty_ids=facs_seq,
                room_ids=rooms_seq,
                commitments_by_key=commitments_by_key,
                blocks_by_key=blocks_by_key,
            )

            if not viable_slots or not viable_facs or not viable_rooms:
                unassignable_reasons[demand_id] = [
                    _reason("solver_unassigned", "No candidate remains after availability and commitment constraints."),
                ]
                continue

            raw_meetings.append(
                MeetingSpec(
                    index=len(raw_meetings),
                    scheduling_demand_id=demand_id,
                    demand_key=str(demand.get("demand_key") or demand_id),
                    term_offering_id=_int_or_none(demand.get("term_offering_id")) or 0,
                    section_id=_int_or_none(demand.get("section_id")) or 0,
                    section_delivery_group_id=dg_id,
                    cohort_or_student_group_id=cohort_ids[0],
                    cohort_or_student_group_ids=cohort_ids,
                    modality=str(demand.get("modality") or "").upper(),
                    subject_id=_int_or_none(demand.get("course_id") or demand.get("subject_id")),
                    course_component_id=_int_or_none(demand.get("course_component_id")),
                    meeting_sequence=seq,
                    meeting_pattern=_meeting_pattern(demand),
                    duration_minutes=duration,
                    load_units_scaled=_decimal_scaled(demand.get("load_units")),
                    expected_count=_int_or_none(demand.get("expected_count")) or 0,
                    room_required=room_req,
                    same_faculty_required=same_fac_req,
                    room_type_requirement=(
                        str(demand.get("room_type_requirement"))
                        if demand.get("room_type_requirement") not in {None, ""}
                        else None
                    ),
                    valid_slots=tuple(viable_slots),
                    valid_faculty=tuple(viable_facs),
                    valid_rooms=tuple(viable_rooms),
                    faculty_blocked_slot_indices=fac_blocked,
                    room_blocked_slot_indices=room_blocked,
                    faculty_priorities=priorities,
                )
            )

    if unassignable_reasons:
        return raw_meetings, unassignable_reasons, sum(len(m.valid_slots) for m in raw_meetings)

    # Propagate same_faculty_required across linked demands in (term_offering_id, section_delivery_group_id)
    same_fac_groups: dict[tuple[int, int], list[int]] = {}
    for m in raw_meetings:
        if m.same_faculty_required:
            same_fac_groups.setdefault((m.term_offering_id, m.section_delivery_group_id), []).append(m.index)

    for m_indices in same_fac_groups.values():
        if len(m_indices) < 2:
            continue
        common_fac = set(raw_meetings[m_indices[0]].valid_faculty)
        for idx in m_indices[1:]:
            common_fac &= set(raw_meetings[idx].valid_faculty)
        if not common_fac:
            for idx in m_indices:
                d_id = raw_meetings[idx].scheduling_demand_id
                unassignable_reasons[d_id] = [
                    _reason("solver_unassigned", "No shared eligible faculty remains for linked same-faculty meetings."),
                ]
            continue

        sorted_common = sorted(common_fac)
        for idx in m_indices:
            m = raw_meetings[idx]
            if list(m.valid_faculty) == sorted_common:
                continue
            viable_slots, viable_facs, viable_rooms, fac_blocked, room_blocked = _filter_viable_options(
                slots=list(m.valid_slots),
                duration=m.duration_minutes,
                faculty_ids=sorted_common,
                room_ids=list(m.valid_rooms),
                commitments_by_key=commitments_by_key,
                blocks_by_key=blocks_by_key,
            )
            if not viable_slots or not viable_facs or not viable_rooms:
                unassignable_reasons[m.scheduling_demand_id] = [
                    _reason("solver_unassigned", "No candidate remains after same-faculty availability constraints."),
                ]
                continue
            raw_meetings[idx] = MeetingSpec(
                **{
                    **m.__dict__,
                    "valid_slots": tuple(viable_slots),
                    "valid_faculty": tuple(viable_facs),
                    "valid_rooms": tuple(viable_rooms),
                    "faculty_blocked_slot_indices": fac_blocked,
                    "room_blocked_slot_indices": room_blocked,
                }
            )

    option_count = sum(len(m.valid_slots) for m in raw_meetings)
    return raw_meetings, unassignable_reasons, option_count


def _filter_viable_options(
    *,
    slots: list[ParsedSlot],
    duration: int,
    faculty_ids: list[int],
    room_ids: list[int | None],
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> tuple[
    list[ParsedSlot],
    list[int],
    list[int | None],
    dict[int, tuple[int, ...]],
    dict[int, tuple[int, ...]],
]:
    if not slots or not faculty_ids or not room_ids:
        return [], [], [], {}, {}

    fac_ok_slots: dict[int, set[int]] = {}
    for fid in faculty_ids:
        ok: set[int] = set()
        for s_idx, slot in enumerate(slots):
            if not _resource_conflicts_slot("faculty", fid, slot, duration, commitments_by_key, blocks_by_key):
                ok.add(s_idx)
        if ok:
            fac_ok_slots[fid] = ok

    room_ok_slots: dict[int | None, set[int]] = {}
    for rid in room_ids:
        if rid is None:
            room_ok_slots[None] = set(range(len(slots)))
        else:
            ok_r: set[int] = set()
            for s_idx, slot in enumerate(slots):
                if not _resource_conflicts_slot("room", rid, slot, duration, commitments_by_key, blocks_by_key):
                    ok_r.add(s_idx)
            if ok_r:
                room_ok_slots[rid] = ok_r

    if not fac_ok_slots or not room_ok_slots:
        return [], [], [], {}, {}

    any_fac_slots = set().union(*fac_ok_slots.values())
    any_room_slots = set().union(*room_ok_slots.values())
    viable_old_indices = sorted(any_fac_slots & any_room_slots)

    if not viable_old_indices:
        return [], [], [], {}, {}

    remap = {old_idx: new_idx for new_idx, old_idx in enumerate(viable_old_indices)}
    viable_slots = [slots[old_idx] for old_idx in viable_old_indices]

    viable_facs = [fid for fid in faculty_ids if fac_ok_slots.get(fid, set()) & set(viable_old_indices)]
    viable_rooms = [rid for rid in room_ids if room_ok_slots.get(rid, set()) & set(viable_old_indices)]

    if not viable_facs or not viable_rooms:
        return [], [], [], {}, {}

    fac_blocked: dict[int, tuple[int, ...]] = {}
    for fid in viable_facs:
        ok_new = {remap[old] for old in fac_ok_slots[fid] if old in remap}
        blocked = tuple(i for i in range(len(viable_slots)) if i not in ok_new)
        if blocked:
            fac_blocked[fid] = blocked

    room_blocked: dict[int, tuple[int, ...]] = {}
    for rid in viable_rooms:
        if rid is None:
            continue
        ok_new = {remap[old] for old in room_ok_slots[rid] if old in remap}
        blocked = tuple(i for i in range(len(viable_slots)) if i not in ok_new)
        if blocked:
            room_blocked[rid] = blocked

    return viable_slots, viable_facs, viable_rooms, fac_blocked, room_blocked


def _presolve_feasibility_guards(
    *,
    snapshot: dict[str, Any],
    meetings: list[MeetingSpec],
    rooms: dict[int, dict[str, Any]],
    parsed_slots: list[ParsedSlot],
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> list[dict[str, str]]:
    if not meetings or not parsed_slots:
        return []

    day_bounds: dict[int, tuple[int, int]] = {}
    for slot in parsed_slots:
        if slot.day_of_week not in day_bounds:
            day_bounds[slot.day_of_week] = (slot.starts_minute, slot.ends_minute)
        else:
            lo, hi = day_bounds[slot.day_of_week]
            day_bounds[slot.day_of_week] = (min(lo, slot.starts_minute), max(hi, slot.ends_minute))

    global_blocks_by_day: dict[int, list[tuple[int, int]]] = {}
    for (kind, _, day), blocks in blocks_by_key.items():
        if kind != "global":
            continue
        for block in blocks:
            _, bs, be = _block_window(block)
            if bs is not None and be is not None and be > bs:
                global_blocks_by_day.setdefault(day, []).append((bs, be))

    def _open_intervals_for_resource(kind: str | None, resource_id: int | None) -> dict[int, list[tuple[int, int]]]:
        result: dict[int, list[tuple[int, int]]] = {}
        for day, (d_start, d_end) in day_bounds.items():
            blocked: list[tuple[int, int]] = list(global_blocks_by_day.get(day, ()))
            if kind is not None and resource_id is not None:
                for block in blocks_by_key.get((kind, resource_id, day), ()):
                    _, bs, be = _block_window(block)
                    if bs is not None and be is not None and be > bs:
                        blocked.append((bs, be))
                for comm in commitments_by_key.get((kind, resource_id, day), ()):
                    cs = _time_to_minutes(comm.get("starts_at"))
                    ce = _time_to_minutes(comm.get("ends_at"))
                    if cs is not None and ce is not None and ce > cs:
                        blocked.append((cs, ce))
            result[day] = _subtract_intervals([(d_start, d_end)], blocked)
        return result

    reasons: list[dict[str, str]] = []

    # 1. Faculty exclusive load units, weekly minutes, and longest uninterrupted block
    max_units = {
        int(row["faculty_id"]): _decimal_scaled(row.get("max_allowed_units"))
        for row in snapshot.get("faculty", [])
        if isinstance(row, dict) and _int_or_none(row.get("faculty_id")) is not None
    }
    exclusive_load_groups: dict[int, dict[tuple[int, int], int]] = {}
    exclusive_minutes: dict[int, int] = {}
    exclusive_longest: dict[int, int] = {}

    for m in meetings:
        if len(m.valid_faculty) == 1:
            fid = m.valid_faculty[0]
            group_key = (m.term_offering_id, m.section_delivery_group_id)
            fac_groups = exclusive_load_groups.setdefault(fid, {})
            fac_groups[group_key] = max(fac_groups.get(group_key, 0), m.load_units_scaled)
            exclusive_minutes[fid] = exclusive_minutes.get(fid, 0) + m.duration_minutes
            exclusive_longest[fid] = max(exclusive_longest.get(fid, 0), m.duration_minutes)

    for fid in sorted(exclusive_load_groups):
        if fid in max_units:
            total_scaled = sum(exclusive_load_groups[fid].values())
            if total_scaled > max_units[fid]:
                reasons.append(
                    _reason(
                        "faculty_exclusive_load_exceeded",
                        (
                            f"Faculty {fid} has {total_scaled / 100:.2f} exclusive load units, "
                            f"exceeding their maximum allowed {max_units[fid] / 100:.2f} units."
                        ),
                    )
                )

        ivs = _open_intervals_for_resource("faculty", fid)
        usable_minutes = sum(e - s for day_ivs in ivs.values() for s, e in day_ivs)
        longest_open = max((e - s for day_ivs in ivs.values() for s, e in day_ivs), default=0)

        if exclusive_minutes.get(fid, 0) > usable_minutes:
            reasons.append(
                _reason(
                    "faculty_exclusive_hours_exceeded",
                    (
                        f"Faculty {fid} requires {exclusive_minutes[fid]} exclusive weekly minutes, "
                        f"exceeding their {usable_minutes} usable open minutes."
                    ),
                )
            )
        if exclusive_longest.get(fid, 0) > longest_open:
            reasons.append(
                _reason(
                    "faculty_exclusive_block_too_long",
                    (
                        f"Faculty {fid} has an exclusive {exclusive_longest[fid]}-minute meeting, "
                        f"exceeding their longest uninterrupted open block of {longest_open} minutes."
                    ),
                )
            )

    # 2. Room-type & room-pool F2F capacity check (subset-sound, zero false positives)
    room_usable_minutes: dict[int, int] = {}
    for rid in rooms:
        ivs = _open_intervals_for_resource("room", rid)
        room_usable_minutes[rid] = sum(e - s for day_ivs in ivs.values() for s, e in day_ivs)

    f2f_meetings = [m for m in meetings if m.room_required]
    if f2f_meetings:
        candidate_room_sets: dict[frozenset[int], str] = {}
        by_room_type: dict[str, set[int]] = {}
        for rid, rdata in rooms.items():
            rtype = str(rdata.get("room_type") or "")
            if rtype:
                by_room_type.setdefault(rtype, set()).add(rid)

        for rtype, rids in by_room_type.items():
            candidate_room_sets[frozenset(rids)] = f"room type {rtype}"

        for m in f2f_meetings:
            rset = frozenset(rid for rid in m.valid_rooms if rid is not None)
            if rset not in candidate_room_sets:
                label = (
                    f"room type {m.room_type_requirement}"
                    if m.room_type_requirement
                    else "eligible room pool"
                )
                candidate_room_sets[rset] = label

        all_rooms_set = frozenset(rooms.keys())
        if all_rooms_set not in candidate_room_sets:
            candidate_room_sets[all_rooms_set] = "all active rooms"

        meeting_room_sets = [
            (m, frozenset(rid for rid in m.valid_rooms if rid is not None))
            for m in f2f_meetings
        ]
        for target_set, label in sorted(candidate_room_sets.items(), key=lambda item: (len(item[0]), item[1])):
            demanded_minutes = sum(
                m.duration_minutes
                for m, m_set in meeting_room_sets
                if m_set.issubset(target_set)
            )
            available_minutes = sum(room_usable_minutes.get(rid, 0) for rid in target_set)
            if demanded_minutes > available_minutes:
                reasons.append(
                    _reason(
                        "room_type_capacity_exceeded",
                        (
                            f"Demanded on-site minutes ({demanded_minutes} min) for {label} "
                            f"exceed total usable open room minutes ({available_minutes} min)."
                        ),
                    )
                )

    # 3. Cohort weekly minutes vs school grid open minutes
    school_ivs = _open_intervals_for_resource(None, None)
    school_weekly_minutes = sum(e - s for day_ivs in school_ivs.values() for s, e in day_ivs)
    cohort_minutes: dict[int, int] = {}
    for m in meetings:
        for cohort_id in m.cohort_or_student_group_ids:
            cohort_minutes[cohort_id] = cohort_minutes.get(cohort_id, 0) + m.duration_minutes

    for cohort_id, total_min in sorted(cohort_minutes.items()):
        if total_min > school_weekly_minutes:
            reasons.append(
                _reason(
                    "cohort_weekly_minutes_exceeded",
                    (
                        f"Student cohort {cohort_id} has {total_min} demanded weekly minutes, "
                        f"exceeding the {school_weekly_minutes} open teaching-grid minutes."
                    ),
                )
            )

    return _dedupe_reasons(reasons)


def _subtract_intervals(
    base_intervals: list[tuple[int, int]],
    blocked_intervals: list[tuple[int, int]],
) -> list[tuple[int, int]]:
    if not base_intervals:
        return []
    if not blocked_intervals:
        return sorted(base_intervals)

    sorted_blocked = sorted(blocked_intervals)
    merged_blocked: list[tuple[int, int]] = []
    for bs, be in sorted_blocked:
        if be <= bs:
            continue
        if not merged_blocked or bs > merged_blocked[-1][1]:
            merged_blocked.append((bs, be))
        else:
            merged_blocked[-1] = (merged_blocked[-1][0], max(merged_blocked[-1][1], be))

    remaining = list(base_intervals)
    for bs, be in merged_blocked:
        next_remaining: list[tuple[int, int]] = []
        for s, e in remaining:
            if be <= s or bs >= e:
                next_remaining.append((s, e))
            else:
                if bs > s:
                    next_remaining.append((s, bs))
                if be < e:
                    next_remaining.append((be, e))
        remaining = next_remaining

    return remaining


def _build_factored_hard_model(
    *,
    model: cp_model.CpModel,
    snapshot: dict[str, Any],
    meetings: list[MeetingSpec],
    rooms: dict[int, dict[str, Any]],
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
    option_count: int,
    request_context: SolverRequestContext,
) -> FactoredModelState:
    use_slot_bools = option_count <= SLOT_BOOL_OPTION_LIMIT
    start_vars: list[cp_model.IntVar] = []
    day_vars: list[cp_model.IntVar] = []
    min_vars: list[cp_model.IntVar] = []
    slot_vars: list[list[cp_model.IntVar]] = []
    fac_vars: list[dict[int, cp_model.IntVar]] = []
    room_vars: list[dict[int, cp_model.IntVar]] = []
    fac_bools: list[cp_model.IntVar] = []
    room_bools: list[cp_model.IntVar] = []

    cohort_intervals: dict[int, list[cp_model.IntervalVar]] = {}
    faculty_intervals: dict[int, list[cp_model.IntervalVar]] = {}
    room_intervals: dict[int, list[cp_model.IntervalVar]] = {}

    # Add resource-level calendar blocks & commitments as fixed intervals in NoOverlap
    for (kind, resource_id, day), blocks in blocks_by_key.items():
        if kind not in {"faculty", "room"} or resource_id is None:
            continue
        target_map = faculty_intervals if kind == "faculty" else room_intervals
        for bi, block in enumerate(blocks):
            _, bs, be = _block_window(block)
            if bs is not None and be is not None and be > bs:
                target_map.setdefault(resource_id, []).append(
                    model.new_fixed_size_interval_var(
                        day * DAY_STRIDE_MINUTES + bs,
                        be - bs,
                        f"{kind}_blk_{resource_id}_{day}_{bi}",
                    )
                )

    for (kind, resource_id, day), commitments in commitments_by_key.items():
        if kind not in {"faculty", "room"}:
            continue
        target_map = faculty_intervals if kind == "faculty" else room_intervals
        for ci, comm in enumerate(commitments):
            cs = _time_to_minutes(comm.get("starts_at"))
            ce = _time_to_minutes(comm.get("ends_at"))
            if cs is not None and ce is not None and ce > cs:
                target_map.setdefault(resource_id, []).append(
                    model.new_fixed_size_interval_var(
                        day * DAY_STRIDE_MINUTES + cs,
                        ce - cs,
                        f"{kind}_comm_{resource_id}_{day}_{ci}",
                    )
                )

    shared_fac_vars: dict[tuple[int, int], dict[int, cp_model.IntVar]] = {}
    global_slot_idx = 0

    for meeting in meetings:
        if meeting.index % 50 == 0:
            request_context.checkpoint("hard_model_construction")

        week_starts = [
            slot.day_of_week * DAY_STRIDE_MINUTES + slot.starts_minute
            for slot in meeting.valid_slots
        ]
        unique_ws = sorted(set(week_starts))
        unique_days = sorted({slot.day_of_week for slot in meeting.valid_slots})
        unique_mins = sorted({slot.starts_minute for slot in meeting.valid_slots})

        if len(unique_ws) == 1:
            start_var = model.new_constant(unique_ws[0])
            day_var = model.new_constant(unique_days[0])
            min_var = model.new_constant(unique_mins[0])
        else:
            start_var = model.new_int_var_from_domain(
                cp_model.Domain.from_values(unique_ws),
                f"start_{meeting.index}",
            )
            if len(unique_days) == 1:
                day_var = model.new_constant(unique_days[0])
            else:
                day_var = model.new_int_var_from_domain(
                    cp_model.Domain.from_values(unique_days),
                    f"day_{meeting.index}",
                )
                model.add_division_equality(day_var, start_var, DAY_STRIDE_MINUTES)
            if len(unique_mins) == 1:
                min_var = model.new_constant(unique_mins[0])
            else:
                min_var = model.new_int_var_from_domain(
                    cp_model.Domain.from_values(unique_mins),
                    f"min_{meeting.index}",
                )
                model.add(min_var == start_var - day_var * DAY_STRIDE_MINUTES)

        start_vars.append(start_var)
        day_vars.append(day_var)
        min_vars.append(min_var)

        if use_slot_bools:
            m_slot_vars = [
                model.new_bool_var(f"candidate_{global_slot_idx + offset}")
                for offset in range(len(meeting.valid_slots))
            ]
            global_slot_idx += len(meeting.valid_slots)
            slot_vars.append(m_slot_vars)
            if len(m_slot_vars) == 1:
                model.add(m_slot_vars[0] == 1)
            else:
                model.add_exactly_one(m_slot_vars)
                model.add(start_var == sum(ws * sv for ws, sv in zip(week_starts, m_slot_vars)))

        # Factored faculty assignment variables
        if len(meeting.valid_faculty) == 1:
            m_fac_vars: dict[int, cp_model.IntVar] = {}
        elif meeting.same_faculty_required:
            group_key = (meeting.term_offering_id, meeting.section_delivery_group_id)
            if group_key not in shared_fac_vars:
                shared_fac_vars[group_key] = {
                    fid: model.new_bool_var(f"fac_grp_{group_key[0]}_{group_key[1]}_{fid}")
                    for fid in meeting.valid_faculty
                }
                model.add_exactly_one(list(shared_fac_vars[group_key].values()))
                fac_bools.extend(shared_fac_vars[group_key].values())
            m_fac_vars = shared_fac_vars[group_key]
        else:
            m_fac_vars = {
                fid: model.new_bool_var(f"fac_{meeting.index}_{fid}")
                for fid in meeting.valid_faculty
            }
            model.add_exactly_one(list(m_fac_vars.values()))
            fac_bools.extend(m_fac_vars.values())

        fac_vars.append(m_fac_vars)

        # Factored room assignment variables
        if len(meeting.valid_rooms) == 1:
            m_room_vars: dict[int, cp_model.IntVar] = {}
        else:
            m_room_vars = {
                rid: model.new_bool_var(f"room_{meeting.index}_{rid}")
                for rid in meeting.valid_rooms
                if rid is not None
            }
            model.add_exactly_one(list(m_room_vars.values()))
            room_bools.extend(m_room_vars.values())

        room_vars.append(m_room_vars)

        # Native Intervals for Cohort, Faculty, and Room NoOverlap
        cohort_iv = model.new_fixed_size_interval_var(
            start_var,
            meeting.duration_minutes,
            f"cohort_iv_{meeting.index}",
        )
        for cohort_id in meeting.cohort_or_student_group_ids:
            cohort_intervals.setdefault(cohort_id, []).append(cohort_iv)

        if len(meeting.valid_faculty) == 1:
            faculty_intervals.setdefault(meeting.valid_faculty[0], []).append(cohort_iv)
        else:
            for fid, f_var in m_fac_vars.items():
                fac_iv = model.new_optional_fixed_size_interval_var(
                    start_var,
                    meeting.duration_minutes,
                    f_var,
                    f"fac_iv_{meeting.index}_{fid}",
                )
                faculty_intervals.setdefault(fid, []).append(fac_iv)

        if meeting.room_required:
            if len(meeting.valid_rooms) == 1:
                rid = meeting.valid_rooms[0]
                if rid is not None:
                    room_intervals.setdefault(rid, []).append(cohort_iv)
            else:
                for rid, r_var in m_room_vars.items():
                    room_iv = model.new_optional_fixed_size_interval_var(
                        start_var,
                        meeting.duration_minutes,
                        r_var,
                        f"room_iv_{meeting.index}_{rid}",
                    )
                    room_intervals.setdefault(rid, []).append(room_iv)

    for iv_map in (cohort_intervals, faculty_intervals, room_intervals):
        for ivs in iv_map.values():
            if len(ivs) > 1:
                model.add_no_overlap(ivs)

    # Factored Modality Transition Buffer (30-min gap on opposing-modality pairs in same cohort)
    _add_factored_modality_transition_buffer(model, meetings, start_vars, request_context)

    # Distinct-day spread for multi-meeting demands
    _add_factored_distinct_day_spread(model, meetings, day_vars)

    # Deduplicated faculty load limits
    load_variables = _add_factored_faculty_load_constraints(model, meetings, fac_vars, snapshot)

    return FactoredModelState(
        meetings=meetings,
        rooms=rooms,
        start_vars=start_vars,
        day_vars=day_vars,
        min_vars=min_vars,
        slot_vars=slot_vars,
        fac_vars=fac_vars,
        room_vars=room_vars,
        fac_bools=fac_bools,
        room_bools=room_bools,
        load_variables=load_variables,
        candidate_count=global_slot_idx if use_slot_bools else option_count,
        on_day_cache={},
        extra_bool_hints=[],
    )


def _add_factored_modality_transition_buffer(
    model: cp_model.CpModel,
    meetings: list[MeetingSpec],
    start_vars: list[cp_model.IntVar],
    request_context: SolverRequestContext,
) -> None:
    by_cohort_modality: dict[int, dict[str, list[MeetingSpec]]] = {}
    for meeting in meetings:
        for cohort_id in meeting.cohort_or_student_group_ids:
            by_cohort_modality.setdefault(cohort_id, {}).setdefault(meeting.modality, []).append(meeting)

    seen_pairs: set[tuple[int, int]] = set()
    buffered_intervals: dict[int, cp_model.IntervalVar] = {}

    for mods_map in by_cohort_modality.values():
        request_context.checkpoint("hard_model_construction")
        if len(mods_map) < 2:
            continue
        mod_keys = sorted(mods_map.keys())
        for left_i in range(len(mod_keys)):
            for right_i in range(left_i + 1, len(mod_keys)):
                for m1 in mods_map[mod_keys[left_i]]:
                    for m2 in mods_map[mod_keys[right_i]]:
                        if m1.scheduling_demand_id == m2.scheduling_demand_id:
                            continue
                        pair = (min(m1.index, m2.index), max(m1.index, m2.index))
                        if pair in seen_pairs:
                            continue
                        seen_pairs.add(pair)
                        iv1 = buffered_intervals.get(m1.index)
                        if iv1 is None:
                            iv1 = model.new_fixed_size_interval_var(
                                start_vars[m1.index],
                                m1.duration_minutes + MODALITY_BUFFER_MINUTES,
                                f"mod_buf_{m1.index}",
                            )
                            buffered_intervals[m1.index] = iv1
                        iv2 = buffered_intervals.get(m2.index)
                        if iv2 is None:
                            iv2 = model.new_fixed_size_interval_var(
                                start_vars[m2.index],
                                m2.duration_minutes + MODALITY_BUFFER_MINUTES,
                                f"mod_buf_{m2.index}",
                            )
                            buffered_intervals[m2.index] = iv2
                        model.add_no_overlap([iv1, iv2])


def _add_factored_distinct_day_spread(
    model: cp_model.CpModel,
    meetings: list[MeetingSpec],
    day_vars: list[cp_model.IntVar],
) -> None:
    meetings_by_demand: dict[int, list[MeetingSpec]] = {}
    for meeting in meetings:
        meetings_by_demand.setdefault(meeting.scheduling_demand_id, []).append(meeting)

    for demand_meetings in meetings_by_demand.values():
        if len(demand_meetings) <= 1:
            continue

        available_days = {
            slot.day_of_week
            for meeting in demand_meetings
            for slot in meeting.valid_slots
        }
        if len(available_days) < len(demand_meetings):
            continue

        model.add_all_different([day_vars[meeting.index] for meeting in demand_meetings])


def _add_factored_faculty_load_constraints(
    model: cp_model.CpModel,
    meetings: list[MeetingSpec],
    fac_vars: list[dict[int, cp_model.IntVar]],
    snapshot: dict[str, Any],
) -> dict[int, cp_model.IntVar]:
    max_units = {
        int(row["faculty_id"]): _decimal_scaled(row.get("max_allowed_units"))
        for row in snapshot.get("faculty", [])
        if isinstance(row, dict) and _int_or_none(row.get("faculty_id")) is not None
    }

    grouped: dict[tuple[int, int, int], list[tuple[MeetingSpec, cp_model.IntVar | None]]] = {}
    for meeting in meetings:
        if len(meeting.valid_faculty) == 1:
            fid = meeting.valid_faculty[0]
            grouped.setdefault((fid, meeting.term_offering_id, meeting.section_delivery_group_id), []).append(
                (meeting, None)
            )
        else:
            for fid, f_var in fac_vars[meeting.index].items():
                grouped.setdefault((fid, meeting.term_offering_id, meeting.section_delivery_group_id), []).append(
                    (meeting, f_var)
                )

    load_terms: dict[int, list[tuple[cp_model.IntVar, int]]] = {}
    for (fid, offering_id, group_id), entries in grouped.items():
        units = max(m.load_units_scaled for m, _ in entries)
        if any(var is None for _, var in entries):
            group_selected = model.new_constant(1)
        else:
            unique_vars_by_idx: dict[int, cp_model.IntVar] = {}
            for _, var in entries:
                if var is not None:
                    unique_vars_by_idx[var.index] = var
            unique_vars = list(unique_vars_by_idx.values())
            if len(unique_vars) == 1:
                group_selected = unique_vars[0]
            else:
                group_selected = model.new_bool_var(f"load_group_{fid}_{offering_id}_{group_id}")
                for var in unique_vars:
                    model.add(var <= group_selected)
                model.add(group_selected <= sum(unique_vars))
        load_terms.setdefault(fid, []).append((group_selected, units))

    load_variables: dict[int, cp_model.IntVar] = {}
    for fid, terms in load_terms.items():
        upper_bound = sum(units for _, units in terms)
        load = model.new_int_var(0, upper_bound, f"faculty_load_{fid}")
        model.add(load == sum(var * units for var, units in terms))
        if fid in max_units:
            model.add(load <= max_units[fid])
        load_variables[fid] = load

    for fid in max_units:
        if fid not in load_variables:
            load_variables[fid] = model.new_constant(0)

    return load_variables


def _apply_repair_hard_requirement(
    snapshot: dict[str, Any],
    model: cp_model.CpModel,
    state: FactoredModelState,
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

    assignment_keys = {(m.scheduling_demand_id, m.meeting_sequence) for m in state.meetings}
    baseline = _repair_baseline(operation)
    if set(baseline) != assignment_keys:
        return _reason("invalid_repair_coverage", "Repair source assignments must cover the complete exact-Term candidate.")

    requested_demand_id = _int_or_none(requested.get("scheduling_demand_id"))
    requested_meeting_sequence = _int_or_none(requested.get("meeting_sequence")) or 1
    requested_key = (requested_demand_id, requested_meeting_sequence)
    if requested_demand_id is None or requested_key not in assignment_keys:
        return _reason("invalid_repair_request", "Repair requires one requested meeting from the source candidate.")

    target_meeting = next(
        m for m in state.meetings if (m.scheduling_demand_id, m.meeting_sequence) == requested_key
    )
    matched = _matching_assignment_spec(target_meeting, requested, state)
    if matched is None:
        return _reason("repair_request_unavailable", "No hard-valid candidate matches the requested repair assignment.")

    s_idx, slot, fac_var, room_var = matched
    if state.slot_vars:
        model.add(state.slot_vars[target_meeting.index][s_idx] == 1)
    else:
        model.add(
            state.start_vars[target_meeting.index] == slot.day_of_week * DAY_STRIDE_MINUTES + slot.starts_minute
        )
    if fac_var is not None:
        model.add(fac_var == 1)
    if room_var is not None:
        model.add(room_var == 1)

    return None


def _repair_change_objective(
    snapshot: dict[str, Any],
    model: cp_model.CpModel,
    state: FactoredModelState,
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

    changed_terms: list[cp_model.LinearExpr] = []
    for meeting in state.meetings:
        key = (meeting.scheduling_demand_id, meeting.meeting_sequence)
        if key == requested_key or key not in baseline:
            continue

        matched = _matching_assignment_spec(meeting, baseline[key], state)
        if matched is None:
            changed_terms.append(1)
            continue

        s_idx, slot, fac_var, room_var = matched
        if state.slot_vars:
            slot_match = state.slot_vars[meeting.index][s_idx]
        else:
            ws = slot.day_of_week * DAY_STRIDE_MINUTES + slot.starts_minute
            slot_match = model.new_bool_var(f"repair_slot_match_{meeting.index}")
            model.add(state.start_vars[meeting.index] == ws).only_enforce_if(slot_match)
            model.add(state.start_vars[meeting.index] != ws).only_enforce_if(slot_match.Not())

        components = [v for v in (slot_match, fac_var, room_var) if v is not None]
        if len(components) == 1:
            changed_terms.append(1 - components[0])
        else:
            unchanged = model.new_bool_var(f"repair_unchanged_{meeting.index}")
            model.add_bool_and(components).only_enforce_if(unchanged)
            model.add_bool_or([v.Not() for v in components] + [unchanged])
            changed_terms.append(1 - unchanged)

    return -sum(changed_terms)


def _matching_assignment_spec(
    meeting: MeetingSpec,
    assignment: dict[str, Any],
    state: FactoredModelState,
) -> tuple[int, ParsedSlot, cp_model.IntVar | None, cp_model.IntVar | None] | None:
    if meeting.meeting_sequence != (_int_or_none(assignment.get("meeting_sequence")) or 1):
        return None

    req_day = _int_or_none(assignment.get("day_of_week") or assignment.get("day"))
    req_start = _time_or_none(assignment.get("starts_at") or assignment.get("start_time"))
    req_end = _time_or_none(assignment.get("ends_at") or assignment.get("end_time"))
    req_faculty = _int_or_none(assignment.get("faculty_user_id") or assignment.get("faculty_id"))
    req_room = _int_or_none(assignment.get("room_id"))

    if req_faculty not in meeting.valid_faculty:
        return None
    if req_room not in meeting.valid_rooms:
        return None

    matched_s_idx: int | None = None
    matched_slot: ParsedSlot | None = None
    for s_idx, slot in enumerate(meeting.valid_slots):
        if (
            slot.day_of_week == req_day
            and slot.starts_at == req_start
            and _minutes_to_time(slot.starts_minute + meeting.duration_minutes) == req_end
        ):
            if s_idx in meeting.faculty_blocked_slot_indices.get(req_faculty, ()):
                return None
            if req_room is not None and s_idx in meeting.room_blocked_slot_indices.get(req_room, ()):
                return None
            matched_s_idx = s_idx
            matched_slot = slot
            break

    if matched_s_idx is None or matched_slot is None:
        return None

    fac_var = state.fac_vars[meeting.index].get(req_faculty) if len(meeting.valid_faculty) > 1 else None
    room_var = (
        state.room_vars[meeting.index].get(req_room)
        if req_room is not None and len(meeting.valid_rooms) > 1
        else None
    )
    return matched_s_idx, matched_slot, fac_var, room_var


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
        candidate_key = (candidate.scheduling_demand_id, candidate.meeting_sequence)
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


def _candidate_matches_assignment(candidate: Candidate, assignment: dict[str, Any]) -> bool:
    return (
        candidate.meeting_sequence == (_int_or_none(assignment.get("meeting_sequence")) or 1)
        and candidate.day_of_week == _int_or_none(assignment.get("day_of_week") or assignment.get("day"))
        and candidate.starts_at == _time_or_none(assignment.get("starts_at") or assignment.get("start_time"))
        and candidate.ends_at == _time_or_none(assignment.get("ends_at") or assignment.get("end_time"))
        and candidate.faculty_id == _int_or_none(assignment.get("faculty_user_id") or assignment.get("faculty_id"))
        and candidate.room_id == _int_or_none(assignment.get("room_id"))
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


def _slot_conflicts_global_or_dg(
    *,
    slot: ParsedSlot,
    duration: int,
    delivery_group_id: int,
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> bool:
    starts_minute = slot.starts_minute
    ends_minute = starts_minute + duration
    day = slot.day_of_week

    for comm in commitments_by_key.get(("delivery_group", delivery_group_id, day), ()):
        cs = _time_to_minutes(comm.get("starts_at"))
        ce = _time_to_minutes(comm.get("ends_at"))
        if cs is not None and ce is not None and starts_minute < ce and ends_minute > cs:
            return True

    for block in blocks_by_key.get(("global", None, day), ()):
        _, bs, be = _block_window(block)
        if bs is not None and be is not None and starts_minute < be and ends_minute > bs:
            return True

    return False


def _resource_conflicts_slot(
    resource_kind: str,
    resource_id: int,
    slot: ParsedSlot,
    duration: int,
    commitments_by_key: dict[tuple[str, int, int], list[dict[str, Any]]],
    blocks_by_key: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> bool:
    starts_minute = slot.starts_minute
    ends_minute = starts_minute + duration
    day = slot.day_of_week

    for comm in commitments_by_key.get((resource_kind, resource_id, day), ()):
        cs = _time_to_minutes(comm.get("starts_at"))
        ce = _time_to_minutes(comm.get("ends_at"))
        if cs is not None and ce is not None and starts_minute < ce and ends_minute > cs:
            return True

    for block in blocks_by_key.get((resource_kind, resource_id, day), ()):
        _, bs, be = _block_window(block)
        if bs is not None and be is not None and starts_minute < be and ends_minute > bs:
            return True

    return False


def _window_conflicts_existing(
    *,
    day: int,
    starts_minute: int,
    ends_minute: int,
    delivery_group_id: int,
    faculty_id: int,
    room_id: int | None,
    indexes: dict[tuple[str, int, int], list[dict[str, Any]]],
) -> bool:
    keys = [
        ("delivery_group", delivery_group_id, day),
        ("faculty", faculty_id, day),
    ]
    if room_id is not None:
        keys.append(("room", room_id, day))

    for key in keys:
        for commitment in indexes.get(key, ()):
            cs = _time_to_minutes(commitment.get("starts_at"))
            ce = _time_to_minutes(commitment.get("ends_at"))
            if cs is not None and ce is not None and starts_minute < ce and ends_minute > cs:
                return True
    return False


def _window_conflicts_calendar(
    *,
    day: int,
    starts_minute: int,
    ends_minute: int,
    faculty_id: int,
    room_id: int | None,
    indexes: dict[tuple[str, int | None, int], list[dict[str, Any]]],
) -> bool:
    keys: list[tuple[str, int | None, int]] = [
        ("global", None, day),
        ("faculty", faculty_id, day),
    ]
    if room_id is not None:
        keys.append(("room", room_id, day))

    for key in keys:
        for block in indexes.get(key, ()):
            _, bs, be = _block_window(block)
            if bs is not None and be is not None and starts_minute < be and ends_minute > bs:
                return True
    return False


def _slots_for_demand_parsed(
    demand: dict[str, Any],
    parsed_slots: list[ParsedSlot],
    day_ends: dict[int, int],
    meeting_sequence: int | None = None,
) -> list[ParsedSlot]:
    fixed_meetings = _fixed_meetings_by_sequence(demand)
    if meeting_sequence is not None and fixed_meetings:
        pin = fixed_meetings.get(meeting_sequence)
        fixed_day = _int_or_none(pin.get("fixed_day_of_week")) if pin is not None else None
        fixed_start = _time_to_minutes(pin.get("fixed_start_time")) if pin is not None else None
    else:
        target_sequence = _int_or_none(demand.get("fixed_meeting_sequence")) or 1
        apply_fixed_time = meeting_sequence is None or meeting_sequence == target_sequence
        fixed_day = _int_or_none(demand.get("fixed_day_of_week")) if apply_fixed_time else None
        fixed_start = _time_to_minutes(demand.get("fixed_start_time")) if apply_fixed_time else None

    duration = _duration_minutes(demand)
    slots: list[ParsedSlot] = []

    for slot in parsed_slots:
        if fixed_day is not None and slot.day_of_week != fixed_day:
            continue
        if fixed_start is not None and slot.starts_minute != fixed_start:
            continue
        if slot.starts_minute + duration > day_ends.get(slot.day_of_week, slot.starts_minute):
            continue
        slots.append(slot)

    return slots


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
    target_sequence = _int_or_none(demand.get("fixed_meeting_sequence")) or 1
    apply_fixed_time = meeting_sequence == target_sequence
    fixed_day = _int_or_none(demand.get("fixed_day_of_week")) if apply_fixed_time else None
    fixed_start = _time_or_none(demand.get("fixed_start_time")) if apply_fixed_time else None

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
        "day": fixed_day,
        "day_of_week": fixed_day,
        "start_time": fixed_start,
        "end_time": None,
        "starts_at": fixed_start,
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


def _dedupe_reasons(reasons: list[dict[str, str]]) -> list[dict[str, str]]:
    seen: set[tuple[str, str]] = set()
    unique: list[dict[str, str]] = []
    for reason in reasons:
        key = (reason.get("type", ""), reason.get("message", ""))
        if key not in seen:
            seen.add(key)
            unique.append(reason)
    return unique


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

    if meeting_count is None or meeting_count < 1 or meeting_count > 6:
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


def _candidate_weight(candidate: Candidate) -> int:
    return (
        10_000_000
        - (candidate.day_of_week * 100_000)
        - candidate.starts_minute
        - (candidate.priority * 10)
        - (candidate.faculty_id % 10)
    )


def _slot_earlier_score(day_of_week: int, starts_minute: int) -> int:
    return max(0, 10_000 - ((day_of_week * 1_000) + starts_minute))


def _room_efficiency_value(room: dict[str, Any], expected_count: int) -> int:
    room_capacity = _int_or_none(room.get("capacity")) or 0
    if expected_count <= 0:
        return max(0, 1_000 - room_capacity)
    return -max(0, room_capacity - expected_count)


def _room_efficiency_score(candidate: Candidate) -> int:
    if candidate.room_id is None:
        return 0

    if candidate.expected_count <= 0:
        return max(0, 1_000 - candidate.room_capacity)

    return -max(0, candidate.room_capacity - candidate.expected_count)


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
                total += (
                    max(candidate.ends_minute for candidate in rows)
                    - min(candidate.starts_minute for candidate in rows)
                    - sum(candidate.duration_minutes for candidate in rows)
                )
        return total

    mode_switches = 0
    for rows in cohort_days.values():
        ordered = sorted(rows, key=lambda candidate: (candidate.starts_minute, candidate.ends_minute))
        mode_switches += sum(
            1 for left, right in zip(ordered, ordered[1:])
            if left.modality != right.modality
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
            "stable_earlier_placement": sum(
                _slot_earlier_score(candidate.day_of_week, candidate.starts_minute)
                for candidate in selected
            ),
        },
        "completed_levels": completed_levels,
        "scalar_score": None,
    }

    repair = _repair_evidence(snapshot, selected)
    if repair is not None:
        details["repair"] = repair

    return details


def _empty_objective_details(snapshot: dict[str, Any]) -> dict[str, Any]:
    return {
        "profile_key": "lexicographic_v1",
        "profile_version": 1,
        "objective_hierarchy": list(LEXICOGRAPHIC_TERMS),
        "values": {term: None for term in LEXICOGRAPHIC_TERMS},
        "completed_levels": [],
        "scalar_score": None,
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
