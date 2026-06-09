from __future__ import annotations

from dataclasses import dataclass
from time import perf_counter
from typing import Any

from ortools.sat.python import cp_model


@dataclass(frozen=True)
class Candidate:
    demand_key: str
    section_id: int
    subject_id: int
    faculty_id: int
    room: str | None
    day_of_week: int
    starts_at: str
    ends_at: str
    starts_minute: int
    ends_minute: int
    modality: str
    priority: int


def solve_snapshot(snapshot: dict[str, Any], timeout_seconds: int = 300) -> dict[str, Any]:
    started_at = perf_counter()
    timeout_seconds = max(1, min(int(timeout_seconds), 300))

    sections = _sections(snapshot)
    demands = _demands(snapshot)
    eligibility = _eligibility(snapshot)
    availability = _availability(snapshot)
    existing_commitments = _existing_commitments(snapshot)
    granularity = int(snapshot.get("policy_constraints", {}).get("slot_granularity_minutes") or 30)

    candidates: list[Candidate] = []
    unassignable_reasons: dict[str, list[dict[str, Any]]] = {}

    for demand in demands:
        section_id = _int_or_none(demand.get("section_id"))
        subject_id = _int_or_none(demand.get("subject_id"))

        if section_id is None or subject_id is None:
            continue

        section = sections.get(section_id)
        demand_key = f"{section_id}:{subject_id}"
        reasons: list[dict[str, Any]] = []

        if section is None:
            unassignable_reasons[demand_key] = [_reason("section_missing", "Section is missing from solver snapshot.")]
            continue

        if int(section.get("max_seats") or 0) > 30 or int(section.get("enrolled_count") or 0) > int(section.get("max_seats") or 0):
            reasons.append(_reason("section_capacity_contract_violation", "Section capacity violates the rescue contract."))

        duration = _duration_minutes(demand)
        modality = str(section.get("modality") or "on_site")
        room = section.get("fixed_room") if _requires_room(modality) else None

        if _requires_room(modality) and not room:
            reasons.append(_reason("missing_required_room", "Fixed rescue room is required for on-site or blended scheduling."))

        eligible_faculty = eligibility.get(subject_id, [])

        if not eligible_faculty:
            reasons.append(_reason("missing_faculty_subject_eligibility", "No active faculty eligibility exists for this subject."))

        for faculty in eligible_faculty:
            faculty_id = faculty["faculty_id"]
            windows = availability.get(faculty_id, [])

            if not windows:
                continue

            for window in windows:
                day = _int_or_none(window.get("day_of_week"))
                window_start = _time_to_minutes(window.get("starts_at"))
                window_end = _time_to_minutes(window.get("ends_at"))

                if day is None or window_start is None or window_end is None:
                    continue

                latest_start = window_end - duration

                for starts_minute in range(window_start, latest_start + 1, granularity):
                    ends_minute = starts_minute + duration
                    candidate = Candidate(
                        demand_key=demand_key,
                        section_id=section_id,
                        subject_id=subject_id,
                        faculty_id=faculty_id,
                        room=str(room) if room else None,
                        day_of_week=day,
                        starts_at=_minutes_to_time(starts_minute),
                        ends_at=_minutes_to_time(ends_minute),
                        starts_minute=starts_minute,
                        ends_minute=ends_minute,
                        modality=modality,
                        priority=int(faculty.get("priority") or 100),
                    )

                    if _conflicts_existing(candidate, existing_commitments):
                        continue

                    candidates.append(candidate)

        if reasons:
            unassignable_reasons[demand_key] = reasons

    model = cp_model.CpModel()
    variables = [model.new_bool_var(f"candidate_{index}") for index, _ in enumerate(candidates)]

    for demand_key in {candidate.demand_key for candidate in candidates}:
        model.add(sum(variables[index] for index, candidate in enumerate(candidates) if candidate.demand_key == demand_key) <= 1)

    for left_index, left in enumerate(candidates):
        for right_index in range(left_index + 1, len(candidates)):
            right = candidates[right_index]

            if not _overlaps(left, right):
                continue

            same_section = left.section_id == right.section_id
            same_faculty = left.faculty_id == right.faculty_id
            same_room = left.room is not None and left.room == right.room

            if same_section or same_faculty or same_room:
                model.add(variables[left_index] + variables[right_index] <= 1)

    model.maximize(sum(_candidate_weight(candidate) * variables[index] for index, candidate in enumerate(candidates)))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(timeout_seconds)
    solver.parameters.num_search_workers = 4

    status = solver.solve(model)
    selected = [candidate for index, candidate in enumerate(candidates) if solver.boolean_value(variables[index])]
    selected_keys = {candidate.demand_key for candidate in selected}
    draft_rows = [_draft_row(candidate) for candidate in selected]

    for demand in demands:
        section_id = _int_or_none(demand.get("section_id"))
        subject_id = _int_or_none(demand.get("subject_id"))

        if section_id is None or subject_id is None:
            continue

        demand_key = f"{section_id}:{subject_id}"

        if demand_key in selected_keys:
            continue

        section = sections.get(section_id, {})
        reasons = unassignable_reasons.get(demand_key) or [
            _reason("solver_unassigned", "No conflict-free candidate was selected for this curriculum demand."),
        ]
        draft_rows.append({
            "section_id": section_id,
            "subject_id": subject_id,
            "faculty_id": None,
            "room": section.get("fixed_room"),
            "day_of_week": None,
            "starts_at": None,
            "ends_at": None,
            "modality": section.get("modality") or "on_site",
            "status": "conflict",
            "conflict_payload": {
                "source": "or_tools_cp_sat_solver",
                "items": reasons,
            },
        })

    assigned_count = len(selected)
    unassigned_count = max(0, len(demands) - assigned_count)
    status_name = _status_name(status, unassigned_count)

    return {
        "solver_status": status_name,
        "assigned_count": assigned_count,
        "unassigned_count": unassigned_count,
        "hard_violation_count": unassigned_count,
        "warning_count": 0,
        "timeout": status in {cp_model.UNKNOWN, cp_model.MODEL_INVALID},
        "objective_score": int(solver.objective_value) if status in {cp_model.OPTIMAL, cp_model.FEASIBLE} else None,
        "solve_time_ms": int((perf_counter() - started_at) * 1000),
        "draft_rows": draft_rows,
    }


def _sections(snapshot: dict[str, Any]) -> dict[int, dict[str, Any]]:
    return {
        int(section["section_id"]): section
        for section in snapshot.get("sections", [])
        if isinstance(section, dict) and section.get("section_id") is not None
    }


def _demands(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        demand
        for demand in snapshot.get("curriculum_subject_demand", [])
        if isinstance(demand, dict)
    ]


def _eligibility(snapshot: dict[str, Any]) -> dict[int, list[dict[str, Any]]]:
    grouped: dict[int, list[dict[str, Any]]] = {}

    for row in snapshot.get("faculty_eligibility", []):
        if not isinstance(row, dict):
            continue

        subject_id = _int_or_none(row.get("subject_id"))
        faculty_id = _int_or_none(row.get("faculty_id"))

        if subject_id is None or faculty_id is None:
            continue

        grouped.setdefault(subject_id, []).append({
            "faculty_id": faculty_id,
            "priority": _int_or_none(row.get("priority")) or 100,
        })

    for rows in grouped.values():
        rows.sort(key=lambda item: (item["priority"], item["faculty_id"]))

    return grouped


def _availability(snapshot: dict[str, Any]) -> dict[int, list[dict[str, Any]]]:
    grouped: dict[int, list[dict[str, Any]]] = {}

    for submission in snapshot.get("faculty_availability", []):
        if not isinstance(submission, dict):
            continue

        faculty_id = _int_or_none(submission.get("faculty_id"))

        if faculty_id is None:
            continue

        windows = [window for window in submission.get("windows", []) if isinstance(window, dict)]
        grouped[faculty_id] = windows

    return grouped


def _existing_commitments(snapshot: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        commitment
        for commitment in snapshot.get("existing_commitments", [])
        if isinstance(commitment, dict)
    ]


def _duration_minutes(demand: dict[str, Any]) -> int:
    value = demand.get("lec_hours") or demand.get("units") or 1

    try:
        hours = float(value)
    except (TypeError, ValueError):
        hours = 1.0

    return max(30, int(hours * 60))


def _conflicts_existing(candidate: Candidate, commitments: list[dict[str, Any]]) -> bool:
    for commitment in commitments:
        day = _int_or_none(commitment.get("day_of_week"))
        starts = _time_to_minutes(commitment.get("starts_at"))
        ends = _time_to_minutes(commitment.get("ends_at"))

        if day != candidate.day_of_week or starts is None or ends is None:
            continue

        if candidate.starts_minute >= ends or candidate.ends_minute <= starts:
            continue

        same_section = _int_or_none(commitment.get("section_id")) == candidate.section_id
        same_faculty = _int_or_none(commitment.get("faculty_id")) == candidate.faculty_id
        same_room = candidate.room is not None and commitment.get("room") == candidate.room

        if same_section or same_faculty or same_room:
            return True

    return False


def _overlaps(left: Candidate, right: Candidate) -> bool:
    return (
        left.day_of_week == right.day_of_week
        and left.starts_minute < right.ends_minute
        and left.ends_minute > right.starts_minute
    )


def _candidate_weight(candidate: Candidate) -> int:
    return 10_000 + max(0, 500 - candidate.priority)


def _draft_row(candidate: Candidate) -> dict[str, Any]:
    return {
        "section_id": candidate.section_id,
        "subject_id": candidate.subject_id,
        "faculty_id": candidate.faculty_id,
        "room": candidate.room,
        "day_of_week": candidate.day_of_week,
        "starts_at": candidate.starts_at,
        "ends_at": candidate.ends_at,
        "modality": candidate.modality,
        "status": "ok",
    }


def _status_name(status: int, unassigned_count: int) -> str:
    if status == cp_model.OPTIMAL:
        return "optimal" if unassigned_count == 0 else "partial"

    if status == cp_model.FEASIBLE:
        return "feasible" if unassigned_count == 0 else "partial"

    if status == cp_model.INFEASIBLE:
        return "infeasible"

    if status == cp_model.MODEL_INVALID:
        return "model_invalid"

    return "unknown"


def _reason(reason_type: str, message: str) -> dict[str, str]:
    return {
        "type": reason_type,
        "message": message,
    }


def _requires_room(modality: str) -> bool:
    return modality in {"on_site", "blended"}


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
