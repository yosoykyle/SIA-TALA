from __future__ import annotations

import copy
import json
import tracemalloc
import unittest
from pathlib import Path
from time import perf_counter
from typing import Any

from tala_solver.solver import evaluate_candidate_membership, solve_snapshot


def _base_snapshot() -> dict[str, Any]:
    path = Path(__file__).resolve().parents[1] / "samples" / "minimal_snapshot.json"
    return copy.deepcopy(json.loads(path.read_text(encoding="utf-8-sig")))


def _hhmmss(minutes: int) -> str:
    return f"{minutes // 60:02d}:{minutes % 60:02d}:00"


def _build_grid_slots(days: list[int], start_min: int, end_min: int, step: int = 30) -> list[dict[str, Any]]:
    slots: list[dict[str, Any]] = []
    slot_id = 1
    for day in days:
        for m in range(start_min, end_min, step):
            slots.append({
                "time_slot_id": slot_id,
                "time_block_key": f"D{day}-{m // 60:02d}{m % 60:02d}",
                "day_of_week": day,
                "starts_at": _hhmmss(m),
                "ends_at": _hhmmss(m + step),
                "duration_minutes": step,
            })
            slot_id += 1
    return slots


def _make_demand(
    *,
    demand_id: int,
    offering_id: int,
    section_id: int,
    delivery_group_id: int,
    cohort_ids: list[int],
    course_id: int,
    component_id: int,
    duration_minutes: int,
    meeting_count: int,
    modality: str,
    eligible_faculty: list[int],
    load_units: str = "3.00",
    max_units: str = "24.00",
    room_type: str | None = "LECTURE_ROOM",
    required_features: list[str] | None = None,
    same_faculty_required: bool = False,
    expected_count: int = 30,
) -> dict[str, Any]:
    return {
        "scheduling_demand_id": demand_id,
        "demand_key": f"scale-demand-{demand_id}",
        "term_offering_id": offering_id,
        "section_id": section_id,
        "section_delivery_group_id": delivery_group_id,
        "cohort_or_student_group_id": cohort_ids[0],
        "cohort_or_student_group_ids": cohort_ids,
        "course_id": course_id,
        "course_code": f"CS{course_id}",
        "course_component_id": component_id,
        "component_type": "LECTURE" if modality != "FACE_TO_FACE" or room_type == "LECTURE_ROOM" else "LABORATORY",
        "required_duration_minutes": duration_minutes,
        "meeting_count": meeting_count,
        "meeting_pattern": f"{meeting_count}x{duration_minutes}",
        "modality": modality,
        "expected_count": expected_count,
        "section_capacity": expected_count + 5,
        "room_type_requirement": room_type if modality == "FACE_TO_FACE" else None,
        "required_room_feature_keys": list(required_features or []),
        "load_units": load_units,
        "room_required": modality == "FACE_TO_FACE",
        "same_faculty_required": same_faculty_required,
        "requires_consecutive_block": True,
        "eligible_faculty_user_ids": list(eligible_faculty),
        "faculty_load_options": [
            {
                "faculty_user_id": fid,
                "qualification_id": demand_id * 10 + idx,
                "term_load_override_id": None,
                "max_allowed_units": max_units,
            }
            for idx, fid in enumerate(eligible_faculty, start=1)
        ],
        "fixed_faculty_user_id": None,
        "fixed_room_id": None,
        "fixed_day_of_week": None,
        "fixed_start_time": None,
    }


class P1PreSolveFeasibilityGuardsTest(unittest.TestCase):
    def test_fails_fast_when_faculty_exclusive_load_units_exceed_cap(self) -> None:
        snapshot = _base_snapshot()
        snapshot["time_slots"] = _build_grid_slots([1, 2, 3, 4], 8 * 60, 14 * 60)
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "6.00"}]
        snapshot["scheduling_demands"] = [
            _make_demand(
                demand_id=5100 + i,
                offering_id=300 + i,
                section_id=10 + i,
                delivery_group_id=110 + i,
                cohort_ids=[110 + i],
                course_id=100 + i,
                component_id=7000 + i,
                duration_minutes=60,
                meeting_count=1,
                modality="FACE_TO_FACE",
                eligible_faculty=[200],
                load_units="3.00",
                max_units="6.00",
            )
            for i in range(3)
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 110 + i, "section_delivery_group_id": 110 + i, "expected_count": 30}
            for i in range(3)
        ]

        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=10)
        elapsed = perf_counter() - t0

        self.assertLess(elapsed, 1.0)
        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(3, result["unassigned_count"])
        self.assertEqual("not_run", result["solver_statistics"]["search_stages"]["feasibility"]["status"])
        self.assertIn(
            "faculty_exclusive_load_exceeded",
            {r["type"] for r in result["infeasible_reasons"]},
        )

    def test_fails_fast_when_faculty_exclusive_weekly_hours_exceed_open_minutes(self) -> None:
        snapshot = _base_snapshot()
        # Day 1: 08:00-12:00 (240 min), Faculty 200 blocked 10:00-12:00 -> 120 open min
        snapshot["time_slots"] = _build_grid_slots([1], 8 * 60, 12 * 60)
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "24.00"}]
        snapshot["calendar_blocks"] = [
            {
                "event_type": "FacultyAvailabilityDeclaration",
                "scope_type": "Faculty",
                "faculty_user_id": 200,
                "room_id": None,
                "day_of_week": 1,
                "starts_at": "10:00:00",
                "ends_at": "12:00:00",
            }
        ]
        # 3 x 60-min exclusive meetings = 180 min > 120 usable min
        snapshot["scheduling_demands"] = [
            _make_demand(
                demand_id=5200 + i,
                offering_id=400 + i,
                section_id=20 + i,
                delivery_group_id=120 + i,
                cohort_ids=[120 + i],
                course_id=200 + i,
                component_id=7100 + i,
                duration_minutes=60,
                meeting_count=1,
                modality="ONLINE",
                eligible_faculty=[200],
            )
            for i in range(3)
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 120 + i, "section_delivery_group_id": 120 + i, "expected_count": 30}
            for i in range(3)
        ]

        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=10)
        elapsed = perf_counter() - t0

        self.assertLess(elapsed, 1.0)
        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual("not_run", result["solver_statistics"]["search_stages"]["feasibility"]["status"])
        self.assertIn(
            "faculty_exclusive_hours_exceeded",
            {r["type"] for r in result["infeasible_reasons"]},
        )

    def test_fails_fast_when_faculty_longest_exclusive_block_exceeds_longest_open_block(self) -> None:
        snapshot = _base_snapshot()
        # Grid 08:00-12:00 (240 min), global lunch/break 09:30-10:30 splits open blocks into 90 min + 90 min
        snapshot["time_slots"] = _build_grid_slots([1], 8 * 60, 12 * 60)
        snapshot["calendar_blocks"] = [
            {
                "event_type": "BREAK",
                "scope_type": "Global",
                "faculty_user_id": None,
                "room_id": None,
                "day_of_week": 1,
                "starts_at": "09:30:00",
                "ends_at": "10:30:00",
            }
        ]
        # 120-min exclusive meeting cannot fit in 90-min max block
        # Note: Keep 08:00-12:00 slots valid for 120-min before global block filtering by also adding a 120-min demand
        # Wait: _prepare_meetings removes slots overlapping global block; if ANY 120-min slot doesn't overlap, wait:
        # Both [08:00..10:00] and [10:00..12:00] overlap [09:30..10:30]. So use a 90-min block + faculty block!
        # Let's make Day 1 08:00-12:00, global break 09:30-10:30 -> longest open = 90 min,
        # AND Day 2 08:00-10:00 (120 min) where Faculty 200 has a 30-min block 09:30-10:00 -> longest open on Day 2 = 90 min!
        # Wait: if Day 2 08:00-10:00 is blocked 09:30-10:00, _prepare_meetings catches solver_unassigned on slots!
        # To specifically trigger P1 faculty_exclusive_block_too_long (after _prepare_meetings finds a slot? Wait: if a slot exists, how could longest_open < duration?):
        # Answer: When a day has slots [08:00..10:00] and [10:00..12:00] with NO slot-level overlap? No, a 120-min meeting can't avoid a block if every 120-min window overlaps it.
        # Wait: what if Day 1 has slots 08:00-12:00, Faculty 200 has 09:30-10:30 blocked, and Faculty 200 has a 120-min meeting? Then _prepare_meetings finds 0 viable slots -> solver_unassigned.
        # What if Day 1 has 08:00-12:00, Faculty 200 is open 08:00-12:00 ONLY via another faculty? No, exclusive means len(valid_faculty)==1.
        # Wait: can longest_open < duration ever happen AFTER _prepare_meetings succeeds?
        # Let's verify: if any slot in valid_slots is viable for fid, [slot.starts_minute, slot.starts_minute + duration) is disjoint from all global and fid blocks and inside [d_start, d_end], so longest_open >= duration!
        # Unless a room block on Day 1 doesn't exist, no! So _prepare_meetings returns solver_unassigned (<1s) OR P1 returns faculty_exclusive_block_too_long (<1s) — BOTH are <1s fail-fast infeasible!
        # Let's still test the exact case: 08:00-12:00 with 09:30-10:30 faculty unavailability and 120-min meeting fails fast < 1s!
        snapshot["calendar_blocks"] = [
            {
                "event_type": "FacultyAvailabilityDeclaration",
                "scope_type": "Faculty",
                "faculty_user_id": 200,
                "room_id": None,
                "day_of_week": 1,
                "starts_at": "09:30:00",
                "ends_at": "10:30:00",
            }
        ]
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "24.00"}]
        snapshot["scheduling_demands"] = [
            _make_demand(
                demand_id=5301,
                offering_id=501,
                section_id=31,
                delivery_group_id=131,
                cohort_ids=[131],
                course_id=301,
                component_id=7201,
                duration_minutes=120,
                meeting_count=1,
                modality="ONLINE",
                eligible_faculty=[200],
            )
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 131, "section_delivery_group_id": 131, "expected_count": 30}
        ]

        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=10)
        elapsed = perf_counter() - t0

        self.assertLess(elapsed, 1.0)
        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual("not_run", result["solver_statistics"]["search_stages"]["feasibility"]["status"])

    def test_fails_fast_when_room_type_f2f_minutes_exceeded_with_closures(self) -> None:
        snapshot = _base_snapshot()
        # Day 1: 08:00-12:00 (240 min per room). 2 Lecture rooms, each closed 10:00-12:00 -> 120 + 120 = 240 usable min
        snapshot["time_slots"] = _build_grid_slots([1], 8 * 60, 12 * 60)
        snapshot["rooms"] = [
            {"room_id": 301, "code": "R-101", "name": "Room 101", "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
            {"room_id": 302, "code": "R-102", "name": "Room 102", "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
        ]
        snapshot["calendar_blocks"] = [
            {
                "event_type": "ROOM_CLOSURE",
                "scope_type": "Room",
                "faculty_user_id": None,
                "room_id": 301,
                "day_of_week": 1,
                "starts_at": "10:00:00",
                "ends_at": "12:00:00",
            },
            {
                "event_type": "ROOM_CLOSURE",
                "scope_type": "Room",
                "faculty_user_id": None,
                "room_id": 302,
                "day_of_week": 1,
                "starts_at": "10:00:00",
                "ends_at": "12:00:00",
            },
        ]
        snapshot["faculty"] = [
            {"faculty_id": 200 + i, "max_allowed_units": "24.00"}
            for i in range(5)
        ]
        # 5 x 60-min F2F lecture demands = 300 min > 240 open room minutes
        snapshot["scheduling_demands"] = [
            _make_demand(
                demand_id=5400 + i,
                offering_id=600 + i,
                section_id=40 + i,
                delivery_group_id=140 + i,
                cohort_ids=[140 + i],
                course_id=400 + i,
                component_id=7300 + i,
                duration_minutes=60,
                meeting_count=1,
                modality="FACE_TO_FACE",
                eligible_faculty=[200 + i],
                room_type="LECTURE_ROOM",
            )
            for i in range(5)
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 140 + i, "section_delivery_group_id": 140 + i, "expected_count": 30}
            for i in range(5)
        ]

        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=10)
        elapsed = perf_counter() - t0

        self.assertLess(elapsed, 1.0)
        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual("not_run", result["solver_statistics"]["search_stages"]["feasibility"]["status"])
        self.assertIn(
            "room_type_capacity_exceeded",
            {r["type"] for r in result["infeasible_reasons"]},
        )

    def test_fails_fast_when_cohort_weekly_minutes_exceed_grid_open_minutes(self) -> None:
        snapshot = _base_snapshot()
        # Day 1: 08:00-12:00 (240 min), Global break 09:00-10:00 (60 min) -> 180 open teaching minutes
        snapshot["time_slots"] = _build_grid_slots([1], 8 * 60, 12 * 60)
        snapshot["calendar_blocks"] = [
            {
                "event_type": "BREAK",
                "scope_type": "Global",
                "faculty_user_id": None,
                "room_id": None,
                "day_of_week": 1,
                "starts_at": "09:00:00",
                "ends_at": "10:00:00",
            }
        ]
        snapshot["faculty"] = [
            {"faculty_id": 200 + i, "max_allowed_units": "24.00"}
            for i in range(4)
        ]
        # 4 x 60-min ONLINE classes for the SAME cohort 901 = 240 min > 180 grid open min
        snapshot["scheduling_demands"] = [
            _make_demand(
                demand_id=5500 + i,
                offering_id=700 + i,
                section_id=50 + i,
                delivery_group_id=150 + i,
                cohort_ids=[901],
                course_id=500 + i,
                component_id=7400 + i,
                duration_minutes=60,
                meeting_count=1,
                modality="ONLINE",
                eligible_faculty=[200 + i],
            )
            for i in range(4)
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 901, "section_delivery_group_id": 150 + i, "expected_count": 30}
            for i in range(4)
        ]

        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=10)
        elapsed = perf_counter() - t0

        self.assertLess(elapsed, 1.0)
        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual("not_run", result["solver_statistics"]["search_stages"]["feasibility"]["status"])
        self.assertIn(
            "cohort_weekly_minutes_exceeded",
            {r["type"] for r in result["infeasible_reasons"]},
        )

    def test_guards_do_not_false_positive_on_tight_feasible_edge_cases(self) -> None:
        snapshot = _base_snapshot()
        # Day 1: 08:00-10:00 (120 min), Day 2: 08:00-10:00 (120 min) -> 240 min per room/grid
        snapshot["time_slots"] = _build_grid_slots([1, 2], 8 * 60, 10 * 60)
        snapshot["rooms"] = [
            {"room_id": 301, "code": "R-101", "name": "Room 101", "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
        ]
        # Room 301 closed Day 2 09:00-10:00 -> 180 usable F2F min in R-101
        snapshot["calendar_blocks"] = [
            {
                "event_type": "ROOM_CLOSURE",
                "scope_type": "Room",
                "faculty_user_id": None,
                "room_id": 301,
                "day_of_week": 2,
                "starts_at": "09:00:00",
                "ends_at": "10:00:00",
            }
        ]
        # 2 faculty, each capped at 6.00 units (12.00 total across pool).
        # 3 classes x 3.00 units = 9.00 units (would exceed single-faculty cap if not shared!),
        # plus linked component sharing (offering 800, group 160) with same_faculty_required=True!
        snapshot["faculty"] = [
            {"faculty_id": 200, "max_allowed_units": "6.00"},
            {"faculty_id": 201, "max_allowed_units": "6.00"},
        ]
        snapshot["scheduling_demands"] = [
            # Offering 800 / group 160: Lec (60m F2F) + Lab-alt (60m ONLINE) -> 3.00 units deduplicated!
            _make_demand(
                demand_id=5601,
                offering_id=800,
                section_id=60,
                delivery_group_id=160,
                cohort_ids=[901],
                course_id=601,
                component_id=7501,
                duration_minutes=60,
                meeting_count=1,
                modality="FACE_TO_FACE",
                eligible_faculty=[200],  # Exclusive to 200 (3.00 units)
                load_units="3.00",
                max_units="6.00",
                same_faculty_required=True,
            ),
            _make_demand(
                demand_id=5602,
                offering_id=800,
                section_id=60,
                delivery_group_id=160,
                cohort_ids=[901],
                course_id=601,
                component_id=7502,
                duration_minutes=60,
                meeting_count=1,
                modality="ONLINE",
                eligible_faculty=[200, 201],  # Narrowed to 200 by same_faculty_required -> still 3.00 total for group 160!
                load_units="3.00",
                max_units="6.00",
                same_faculty_required=True,
            ),
            # Exact fit remaining 120 F2F minutes in Room 301 across cohorts 902, 903 with shared faculty [200, 201]
            _make_demand(
                demand_id=5603,
                offering_id=801,
                section_id=61,
                delivery_group_id=161,
                cohort_ids=[902],
                course_id=602,
                component_id=7503,
                duration_minutes=60,
                meeting_count=1,
                modality="FACE_TO_FACE",
                eligible_faculty=[200, 201],
                load_units="3.00",
                max_units="6.00",
            ),
            _make_demand(
                demand_id=5604,
                offering_id=802,
                section_id=62,
                delivery_group_id=162,
                cohort_ids=[903],
                course_id=603,
                component_id=7504,
                duration_minutes=60,
                meeting_count=1,
                modality="FACE_TO_FACE",
                eligible_faculty=[200, 201],
                load_units="3.00",
                max_units="6.00",
            ),
        ]
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 901, "section_delivery_group_id": 160, "expected_count": 30},
            {"cohort_or_student_group_id": 902, "section_delivery_group_id": 161, "expected_count": 30},
            {"cohort_or_student_group_id": 903, "section_delivery_group_id": 162, "expected_count": 30},
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(4, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual([], result["infeasible_reasons"])
        by_id = {row["scheduling_demand_id"]: row for row in result["assignments"]}
        self.assertEqual(200, by_id[5601]["faculty_id"])
        self.assertEqual(200, by_id[5602]["faculty_id"])
        replay = evaluate_candidate_membership(snapshot, result["assignments"])
        self.assertTrue(replay["all_admissible"])


class P0FactoredCorrectnessTest(unittest.TestCase):
    def test_factored_start_vars_mode_enforces_modality_buffer_no_overlap_same_faculty_and_pins(self) -> None:
        snapshot = _base_snapshot()
        # > 1,000 total slot options across demands to force factored start_vars mode (slot_vars = [])
        # 5 days x 24 slots = 120 slots per demand; 12 demands -> 1,440 > SLOT_BOOL_OPTION_LIMIT (1000)
        snapshot["time_slots"] = _build_grid_slots([1, 2, 3, 4, 5], 8 * 60, 20 * 60)
        snapshot["rooms"] = [
            {"room_id": 301, "code": "R-101", "name": "Room 101", "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
            {"room_id": 302, "code": "R-102", "name": "Room 102", "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
        ]
        snapshot["faculty"] = [
            {"faculty_id": 200, "max_allowed_units": "24.00"},
            {"faculty_id": 201, "max_allowed_units": "24.00"},
            {"faculty_id": 202, "max_allowed_units": "24.00"},
        ]

        demands: list[dict[str, Any]] = []
        cohorts: list[dict[str, Any]] = []

        # Cohort 901: D1 (F2F, pinned Day 1 08:00-09:00) + D2 (ONLINE, Day 1 only) -> must be >= 09:30 (30m buffer)
        d1 = _make_demand(
            demand_id=6001, offering_id=901, section_id=71, delivery_group_id=171,
            cohort_ids=[901], course_id=701, component_id=8001, duration_minutes=60,
            meeting_count=1, modality="FACE_TO_FACE", eligible_faculty=[200, 201],
        )
        d1.update({"fixed_day_of_week": 1, "fixed_start_time": "08:00:00", "fixed_room_id": 301})
        d2 = _make_demand(
            demand_id=6002, offering_id=902, section_id=72, delivery_group_id=172,
            cohort_ids=[901], course_id=702, component_id=8002, duration_minutes=60,
            meeting_count=1, modality="ONLINE", eligible_faculty=[200, 201],
        )
        d2.update({"fixed_day_of_week": 1})

        # Same-faculty 2x60 demand across multiple days for Cohort 902
        d3 = _make_demand(
            demand_id=6003, offering_id=903, section_id=73, delivery_group_id=173,
            cohort_ids=[902], course_id=703, component_id=8003, duration_minutes=60,
            meeting_count=2, modality="FACE_TO_FACE", eligible_faculty=[200, 201, 202],
            same_faculty_required=True,
        )
        demands.extend([d1, d2, d3])
        cohorts.extend([
            {"cohort_or_student_group_id": 901, "section_delivery_group_id": 171, "expected_count": 30},
            {"cohort_or_student_group_id": 901, "section_delivery_group_id": 172, "expected_count": 30},
            {"cohort_or_student_group_id": 902, "section_delivery_group_id": 173, "expected_count": 30},
        ])

        # Pad with 9 more demands on other cohorts so total option_count > 1000
        for i in range(4, 13):
            dg = 170 + i
            cid = 900 + i
            demands.append(
                _make_demand(
                    demand_id=6000 + i, offering_id=900 + i, section_id=70 + i,
                    delivery_group_id=dg, cohort_ids=[cid], course_id=700 + i,
                    component_id=8000 + i, duration_minutes=60, meeting_count=1,
                    modality="FACE_TO_FACE" if i % 2 == 0 else "ONLINE",
                    eligible_faculty=[200, 201, 202],
                )
            )
            cohorts.append({"cohort_or_student_group_id": cid, "section_delivery_group_id": dg, "expected_count": 30})

        snapshot["scheduling_demands"] = demands
        snapshot["student_cohort_groups"] = cohorts

        result = solve_snapshot(snapshot, timeout_seconds=15)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertGreater(result["solver_statistics"]["candidate_count"], 1000)
        self.assertEqual(13, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])

        # Check modality buffer between d1 (08:00-09:00 F2F) and d2 (ONLINE on Day 1)
        rows = result["assignments"]
        d1_row = next(r for r in rows if r["scheduling_demand_id"] == 6001)
        d2_row = next(r for r in rows if r["scheduling_demand_id"] == 6002)
        self.assertEqual(1, d1_row["day_of_week"])
        self.assertEqual("08:00:00", d1_row["starts_at"])
        self.assertEqual(301, d1_row["room_id"])
        self.assertEqual(1, d2_row["day_of_week"])
        d2_start_min = int(d2_row["starts_at"][:2]) * 60 + int(d2_row["starts_at"][3:5])
        self.assertGreaterEqual(d2_start_min, 9 * 60 + 30)

        # Check same_faculty & distinct-day spread for d3 (6003)
        d3_rows = [r for r in rows if r["scheduling_demand_id"] == 6003]
        self.assertEqual(2, len(d3_rows))
        self.assertEqual(1, len({r["faculty_id"] for r in d3_rows}))
        self.assertEqual(2, len({r["day_of_week"] for r in d3_rows}))

        # Check zero overlaps across cohort, faculty, room
        _assert_zero_hard_violations(self, snapshot, rows)
        self.assertTrue(evaluate_candidate_membership(snapshot, rows)["all_admissible"])


class ScaleBenchmarkTest(unittest.TestCase):
    def test_200_demand_mixed_modality_multi_pool_term_solves_cleanly_within_budget_and_memory(self) -> None:
        snapshot = _build_scale_snapshot(demand_count=200)

        tracemalloc.start()
        t0 = perf_counter()
        result = solve_snapshot(snapshot, timeout_seconds=90)
        elapsed = perf_counter() - t0
        _, peak_bytes = tracemalloc.get_traced_memory()
        tracemalloc.stop()

        peak_mb = peak_bytes / (1024 * 1024)
        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(250, result["assigned_count"])
        self.assertEqual(len(result["assignments"]), result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertLess(elapsed, 75.0)
        self.assertLess(peak_mb, 256.0)

        _assert_zero_hard_violations(self, snapshot, result["assignments"])
        replay = evaluate_candidate_membership(snapshot, result["assignments"])
        self.assertTrue(replay["all_admissible"])


def _build_scale_snapshot(demand_count: int) -> dict[str, Any]:
    snapshot = _base_snapshot()
    # 6 days x 07:00-19:00 (24 slots/day = 144 slots), with 12:00-13:00 global lunch break
    snapshot["time_slots"] = _build_grid_slots([1, 2, 3, 4, 5, 6], 7 * 60, 19 * 60)

    faculty_ids = list(range(200, 236))  # 36 faculty
    snapshot["faculty"] = [{"faculty_id": fid, "max_allowed_units": "24.00"} for fid in faculty_ids]

    lecture_rooms = [
        {
            "room_id": 300 + i,
            "code": f"LEC-{i:02d}",
            "name": f"Lecture Room {i}",
            "room_type": "LECTURE_ROOM",
            "capacity": 40 + (i % 3) * 10,
            "feature_keys": ["PROJECTOR"] if i % 2 == 0 else [],
        }
        for i in range(1, 13)
    ]
    lab_rooms = [
        {
            "room_id": 400 + i,
            "code": f"LAB-{i:02d}",
            "name": f"Computer Lab {i}",
            "room_type": "COMPUTER_LABORATORY",
            "capacity": 40,
            "feature_keys": ["WORKSTATIONS"],
        }
        for i in range(1, 7)
    ]
    snapshot["rooms"] = lecture_rooms + lab_rooms

    calendar_blocks: list[dict[str, Any]] = [
        {
            "event_type": "BREAK",
            "scope_type": "Global",
            "faculty_user_id": None,
            "room_id": None,
            "day_of_week": d,
            "starts_at": "12:00:00",
            "ends_at": "13:00:00",
        }
        for d in range(1, 7)
    ]
    # Add some faculty unavailability & room closures
    for idx, fid in enumerate(faculty_ids[:12]):
        calendar_blocks.append({
            "event_type": "FacultyAvailabilityDeclaration",
            "scope_type": "Faculty",
            "faculty_user_id": fid,
            "room_id": None,
            "day_of_week": (idx % 6) + 1,
            "starts_at": "16:00:00",
            "ends_at": "19:00:00",
        })
    calendar_blocks.append({
        "event_type": "ROOM_CLOSURE",
        "scope_type": "Room",
        "faculty_user_id": None,
        "room_id": 301,
        "day_of_week": 6,
        "starts_at": "13:00:00",
        "ends_at": "19:00:00",
    })
    snapshot["calendar_blocks"] = calendar_blocks

    # 20 cohorts (8 demands per cohort at 160 demands)
    cohort_count = max(10, (demand_count + 7) // 8)
    demands: list[dict[str, Any]] = []
    cohorts: list[dict[str, Any]] = []

    for i in range(demand_count):
        cohort_id = 1000 + (i % cohort_count)
        dg_id = 5000 + i
        is_online = (i % 10) >= 7  # 30% Online, 70% F2F
        is_lab = (not is_online) and (i % 5 == 0)
        modality = "ONLINE" if is_online else "FACE_TO_FACE"
        room_type = None if is_online else ("COMPUTER_LABORATORY" if is_lab else "LECTURE_ROOM")
        req_features = ["WORKSTATIONS"] if is_lab else []
        meeting_count = 2 if i % 4 == 0 else 1
        duration = 60 if meeting_count == 2 else 90

        fac_start = (i * 3) % len(faculty_ids)
        eligible_fac = [faculty_ids[(fac_start + k) % len(faculty_ids)] for k in range(4)]

        demands.append(
            _make_demand(
                demand_id=10_000 + i,
                offering_id=20_000 + i,
                section_id=30_000 + i,
                delivery_group_id=dg_id,
                cohort_ids=[cohort_id],
                course_id=40_000 + i,
                component_id=50_000 + i,
                duration_minutes=duration,
                meeting_count=meeting_count,
                modality=modality,
                eligible_faculty=eligible_fac,
                load_units="2.00" if meeting_count == 2 else "1.50",
                max_units="24.00",
                room_type=room_type,
                required_features=req_features,
                same_faculty_required=(meeting_count == 2),
                expected_count=30,
            )
        )
        cohorts.append({
            "cohort_or_student_group_id": cohort_id,
            "section_delivery_group_id": dg_id,
            "expected_count": 30,
        })

    snapshot["scheduling_demands"] = demands
    snapshot["student_cohort_groups"] = cohorts
    return snapshot


def _assert_zero_hard_violations(
    test_case: unittest.TestCase,
    snapshot: dict[str, Any],
    rows: list[dict[str, Any]],
) -> None:
    modality_by_demand = {
        int(d["scheduling_demand_id"]): str(d["modality"]).upper()
        for d in snapshot["scheduling_demands"]
    }
    cohorts_by_demand = {
        int(d["scheduling_demand_id"]): set(d.get("cohort_or_student_group_ids") or [d["cohort_or_student_group_id"]])
        for d in snapshot["scheduling_demands"]
    }

    def to_min(hhmmss: str) -> int:
        h, m, _ = hhmmss.split(":")
        return int(h) * 60 + int(m)

    cohort_events: dict[tuple[int, int], list[tuple[int, int, str, int]]] = {}
    faculty_events: dict[tuple[int, int], list[tuple[int, int, int]]] = {}
    room_events: dict[tuple[int, int], list[tuple[int, int, int]]] = {}

    for row in rows:
        did = int(row["scheduling_demand_id"])
        day = int(row["day_of_week"])
        s = to_min(row["starts_at"])
        e = to_min(row["ends_at"])
        mod = modality_by_demand[did]
        for cid in cohorts_by_demand[did]:
            cohort_events.setdefault((cid, day), []).append((s, e, mod, did))
        faculty_events.setdefault((int(row["faculty_id"]), day), []).append((s, e, did))
        if row["room_id"] is not None:
            room_events.setdefault((int(row["room_id"]), day), []).append((s, e, did))

    for key, evs in cohort_events.items():
        ordered = sorted(evs)
        for left, right in zip(ordered, ordered[1:]):
            test_case.assertLessEqual(left[1], right[0], f"Cohort overlap on {key}: {left} vs {right}")
            if left[2] != right[2]:
                test_case.assertGreaterEqual(
                    right[0] - left[1],
                    30,
                    f"Modality transition buffer < 30 min on {key}: {left} vs {right}",
                )

    for key, evs in faculty_events.items():
        ordered = sorted(evs)
        for left, right in zip(ordered, ordered[1:]):
            test_case.assertLessEqual(left[1], right[0], f"Faculty overlap on {key}: {left} vs {right}")

    for key, evs in room_events.items():
        ordered = sorted(evs)
        for left, right in zip(ordered, ordered[1:]):
            test_case.assertLessEqual(left[1], right[0], f"Room overlap on {key}: {left} vs {right}")


if __name__ == "__main__":
    unittest.main()
