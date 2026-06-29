from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from time import perf_counter
from typing import Any

from ortools.sat.python import cp_model


CONTRACT_VERSION = "tal61-demand-v1"
SOLVER_VERSION = "cloud-cp-sat-tal61-demand-v1"


@dataclass(frozen=True)
class Candidate:
    scheduling_demand_id: int
    demand_key: str
    term_offering_id: int
    section_id: int
    section_delivery_group_id: int
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


def solve_snapshot(snapshot: dict[str, Any], timeout_seconds: int = 300) -> dict[str, Any]:
    started_at = perf_counter()
    timeout_seconds = max(1, min(int(timeout_seconds), 300))
    solver_run_id = _solver_run_id(snapshot)

    if snapshot.get("contract_version") != CONTRACT_VERSION:
        return _result(
            snapshot=snapshot,
            solver_run_id=solver_run_id,
            solver_status="infeasible",
            assignments=[],
            objective_score=None,
            timeout=False,
            started_at=started_at,
            warnings=[_reason("unsupported_contract_version", "Solver requires tal61-demand-v1 snapshots.")],
            infeasible_reasons=[_reason("unsupported_contract_version", "Solver requires tal61-demand-v1 snapshots.")],
        )

    demands = _demands(snapshot)
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
                    candidate = _candidate(demand, faculty_id, room_id, slot)

                    if candidate is None:
                        continue

                    if not _inside_faculty_availability(candidate, availability):
                        continue

                    if _conflicts_existing(candidate, existing_commitments):
                        continue

                    if _conflicts_calendar(candidate, calendar_blocks):
                        continue

                    candidates.append(candidate)

    model = cp_model.CpModel()
    variables = [model.new_bool_var(f"candidate_{index}") for index, _ in enumerate(candidates)]

    for demand_id in {candidate.scheduling_demand_id for candidate in candidates}:
        model.add(
            sum(
                variables[index]
                for index, candidate in enumerate(candidates)
                if candidate.scheduling_demand_id == demand_id
            )
            <= 1
        )

    _add_no_overlap_constraints(model, variables, candidates)
    _add_same_faculty_constraints(model, variables, candidates, demands)

    model.maximize(
        sum(_candidate_weight(candidate) * variables[index] for index, candidate in enumerate(candidates))
    )

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(timeout_seconds)
    solver.parameters.num_search_workers = 4

    status = solver.solve(model)
    selected = [
        candidate
        for index, candidate in enumerate(candidates)
        if status in {cp_model.OPTIMAL, cp_model.FEASIBLE} and solver.boolean_value(variables[index])
    ]
    selected_ids = {candidate.scheduling_demand_id for candidate in selected}
    assignments = [_assignment(candidate) for candidate in sorted(selected, key=_candidate_sort_key)]

    for demand in demands:
        demand_id = _int_or_none(demand.get("scheduling_demand_id"))

        if demand_id is None or demand_id in selected_ids:
            continue

        assignments.append(_conflict_assignment(
            demand,
            unassignable_reasons.get(demand_id) or [
                _reason("solver_unassigned", "No conflict-free candidate was selected for this Scheduling Demand."),
            ],
        ))

    assigned_count = len(selected)
    unassigned_count = max(0, len(demands) - assigned_count)
    solver_status = _status_name(status, assigned_count, unassigned_count, len(demands))
    objective_score = int(solver.objective_value) if status in {cp_model.OPTIMAL, cp_model.FEASIBLE} else None

    return _result(
        snapshot=snapshot,
        solver_run_id=solver_run_id,
        solver_status=solver_status,
        assignments=assignments,
        objective_score=objective_score,
        timeout=status == cp_model.UNKNOWN,
        started_at=started_at,
        warnings=[],
        infeasible_reasons=[
            item
            for assignment in assignments
            if assignment["assignment_status"] == "conflict"
            for item in assignment["violations"]
        ],
    )


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
) -> dict[str, Any]:
    conflict_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "conflict")
    warning_count = sum(1 for assignment in assignments if assignment["assignment_status"] == "warning")

    return {
        "solver_run_id": solver_run_id,
        "solver_status": solver_status,
        "candidate_schedule_id": f"cp-sat-{solver_run_id or 'unknown'}",
        "assignments": assignments,
        "hard_constraint_violations": infeasible_reasons,
        "hard_violation_count": conflict_count,
        "soft_constraint_scores": {
            "assigned_count": len(assignments) - conflict_count,
            "conflict_count": conflict_count,
            "prefer_earlier_time_blocks": _earlier_time_score(assignments),
        },
        "infeasible_reasons": infeasible_reasons,
        "warnings": warnings,
        "runtime_seconds": round(perf_counter() - started_at, 6),
        "objective_score": objective_score,
        "solver_version": SOLVER_VERSION,
        "model_version": str(snapshot.get("contract_version") or CONTRACT_VERSION),
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "assigned_count": len(assignments) - conflict_count,
        "unassigned_count": conflict_count,
        "warning_count": warning_count,
        "timeout": timeout,
    }


def _demands(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        demand
        for demand in snapshot.get("scheduling_demands", [])
        if isinstance(demand, dict) and demand.get("scheduling_demand_id") is not None
    ]


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

    if room_type not in {None, ""} and room.get("room_type") != room_type:
        return False

    return (_int_or_none(room.get("capacity")) or 0) >= expected_count


def _slots_for_demand(demand: dict[str, Any], time_slots: list[dict[str, Any]]) -> list[dict[str, Any]]:
    fixed_day = _int_or_none(demand.get("fixed_day_of_week"))
    fixed_start = _time_to_minutes(demand.get("fixed_start_time"))

    if fixed_day is not None and fixed_start is not None:
        return [{
            "time_slot_id": None,
            "time_block_key": f"fixed-{int(demand['scheduling_demand_id'])}",
            "day_of_week": fixed_day,
            "starts_at": _minutes_to_time(fixed_start),
        }]

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
    )


def _add_no_overlap_constraints(
    model: cp_model.CpModel,
    variables: list[cp_model.IntVar],
    candidates: list[Candidate],
) -> None:
    for left_index, left in enumerate(candidates):
        for right_index in range(left_index + 1, len(candidates)):
            right = candidates[right_index]

            if not _overlaps(left, right):
                continue

            same_delivery_group = left.section_delivery_group_id == right.section_delivery_group_id
            same_faculty = left.faculty_id == right.faculty_id
            same_room = left.room_id is not None and left.room_id == right.room_id

            if same_delivery_group or same_faculty or same_room:
                model.add(variables[left_index] + variables[right_index] <= 1)


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


def _status_name(status: int, assigned_count: int, unassigned_count: int, demand_count: int) -> str:
    if demand_count == 0:
        return "optimal"

    if assigned_count == 0 and unassigned_count > 0:
        return "infeasible"

    if unassigned_count > 0:
        return "partial"

    if status == cp_model.OPTIMAL:
        return "optimal"

    if status == cp_model.FEASIBLE:
        return "feasible"

    if status == cp_model.INFEASIBLE:
        return "infeasible"

    return "partial"


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
