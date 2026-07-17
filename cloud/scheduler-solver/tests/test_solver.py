from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path
from typing import Any
from unittest.mock import patch

from ortools.sat.python import cp_model

from tala_solver.solver import solve_snapshot


class SolveSnapshotTest(unittest.TestCase):
    def test_accepts_v2_demands_and_emits_laravel_assignment_contract(self) -> None:
        snapshot = self.snapshot()
        demand_count = len(snapshot["scheduling_demands"])

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertNotIn("draft_rows", result)
        self.assertEqual(demand_count, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertEqual(demand_count, len(result["assignments"]))

        demand_ids = {demand["scheduling_demand_id"] for demand in snapshot["scheduling_demands"]}
        assignments = result["assignments"]

        self.assertEqual(demand_ids, {row["scheduling_demand_id"] for row in assignments})

        for row in assignments:
            self.assert_laravel_assignment_contract(row)
            self.assertEqual("ok", row["assignment_status"])
            self.assertEqual([], row["violations"])

        self.assertEqual([], self.hard_constraint_violations(assignments))

    def test_unassignable_v2_demand_returns_conflict_assignment_with_demand_id(self) -> None:
        snapshot = self.snapshot()

        for demand in snapshot["scheduling_demands"]:
            demand["eligible_faculty_user_ids"] = []
            demand["faculty_load_options"] = []

        snapshot["faculty"] = []
        snapshot["faculty_qualifications"] = []

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])
        self.assertEqual(2, len(result["assignments"]))
        self.assertTrue(all(row["assignment_status"] == "conflict" for row in result["assignments"]))
        self.assertEqual(
            "missing_faculty",
            result["assignments"][0]["violations"][0]["type"],
        )
        self.assertEqual(
            {5001, 5002},
            {row["scheduling_demand_id"] for row in result["assignments"]},
        )

    def test_room_required_demand_returns_conflict_when_no_suitable_room_exists(self) -> None:
        snapshot = self.snapshot()
        snapshot["rooms"] = []

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])
        self.assertTrue(all(row["assignment_status"] == "conflict" for row in result["assignments"]))
        self.assertEqual("missing_room", result["assignments"][0]["violations"][0]["type"])

    def test_fixed_faculty_room_and_time_are_respected(self) -> None:
        snapshot = self.snapshot()
        demand = snapshot["scheduling_demands"][0]
        demand["fixed_faculty_user_id"] = 200
        demand["fixed_room_id"] = 301
        demand["fixed_day_of_week"] = 2
        demand["fixed_start_time"] = "10:00:00"

        result = solve_snapshot(snapshot, timeout_seconds=10)
        assignment = next(
            row for row in result["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )

        self.assertEqual("ok", assignment["assignment_status"])
        self.assertEqual(200, assignment["faculty_id"])
        self.assertEqual(301, assignment["room_id"])
        self.assertEqual(2, assignment["day_of_week"])
        self.assertEqual("10:00:00", assignment["starts_at"])
        self.assertEqual("11:00:00", assignment["ends_at"])
        self.assertEqual("fixed-5001", assignment["time_block_key"])

    def test_same_section_group_and_room_assignments_do_not_overlap(self) -> None:
        snapshot = self.snapshot()

        result = solve_snapshot(snapshot, timeout_seconds=10)
        rows = result["assignments"]

        self.assertEqual(2, len(rows))
        self.assertNotEqual(
            (rows[0]["starts_at"], rows[0]["ends_at"]),
            (rows[1]["starts_at"], rows[1]["ends_at"]),
        )
        self.assertEqual([], self.hard_constraint_violations(rows))

    def test_recurring_calendar_block_excludes_overlapping_fixed_assignment(self) -> None:
        snapshot = self.snapshot()
        demand = snapshot["scheduling_demands"][0]
        demand["fixed_faculty_user_id"] = 200
        demand["fixed_room_id"] = 301
        demand["fixed_day_of_week"] = 1
        demand["fixed_start_time"] = "08:00:00"

        baseline = solve_snapshot(snapshot, timeout_seconds=10)
        baseline_assignment = next(
            row for row in baseline["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )
        self.assertEqual("ok", baseline_assignment["assignment_status"])

        snapshot["calendar_blocks"] = [
            {
                "calendar_event_id": 9001,
                "event_type": "UNAVAILABLE",
                "scope_type": "FACULTY",
                "faculty_user_id": 200,
                "room_id": None,
                "authority": "Faculty",
                "day_of_week": 1,
                "starts_at": "08:00:00",
                "ends_at": "09:00:00",
            }
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)
        assignment = next(
            row for row in result["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )

        self.assertEqual("conflict", assignment["assignment_status"])
        self.assertEqual("solver_unassigned", assignment["violations"][0]["type"])

    def test_v2_profile_reports_all_balanced_objective_terms(self) -> None:
        result = solve_snapshot(self.snapshot(), timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        details = result["objective_details"]
        self.assertEqual("balanced_v1", details["profile_key"])
        self.assertEqual(
            {
                "prefer_earlier_time_blocks",
                "reduce_faculty_idle_gaps",
                "balance_faculty_load",
                "use_rooms_efficiently",
            },
            set(details["terms"]),
        )
        self.assertEqual(result["objective_score"], details["total"])

    def test_solver_statistics_are_typed_allowlisted_and_reproducible(self) -> None:
        snapshot = self.snapshot()

        result = solve_snapshot(snapshot, timeout_seconds=10)

        statistics = result["solver_statistics"]
        self.assertEqual(
            {
                "ortools_version",
                "input_demand_count",
                "input_faculty_count",
                "input_room_count",
                "input_time_slot_count",
                "candidate_count",
                "model_variable_count",
                "model_constraint_count",
                "no_overlap_constraint_count",
                "best_objective_bound",
                "relative_optimality_gap",
                "boolean_variable_count",
                "branch_count",
                "conflict_count",
                "deterministic_time_seconds",
                "wall_time_seconds",
                "worker_count",
                "random_seed",
            },
            set(statistics),
        )
        self.assertEqual("9.15.6755", statistics["ortools_version"])
        self.assertEqual(len(snapshot["scheduling_demands"]), statistics["input_demand_count"])
        self.assertEqual(len(snapshot["faculty"]), statistics["input_faculty_count"])
        self.assertEqual(len(snapshot["rooms"]), statistics["input_room_count"])
        self.assertEqual(len(snapshot["time_slots"]), statistics["input_time_slot_count"])
        self.assertGreater(statistics["candidate_count"], 0)
        self.assertGreater(statistics["model_variable_count"], 0)
        self.assertGreater(statistics["model_constraint_count"], 0)
        self.assertGreater(statistics["no_overlap_constraint_count"], 0)
        self.assertIsInstance(statistics["best_objective_bound"], float)
        self.assertEqual(0.0, statistics["relative_optimality_gap"])
        self.assertIsInstance(statistics["boolean_variable_count"], int)
        self.assertIsInstance(statistics["branch_count"], int)
        self.assertIsInstance(statistics["conflict_count"], int)
        self.assertIsInstance(statistics["deterministic_time_seconds"], float)
        self.assertIsInstance(statistics["wall_time_seconds"], float)
        self.assertEqual(1, statistics["worker_count"])
        self.assertEqual(20_260_718, statistics["random_seed"])

    def test_model_growth_uses_resource_no_overlap_instead_of_candidate_pair_constraints(self) -> None:
        snapshot = self.scaled_snapshot(demand_count=6, slot_count=20)

        with patch.object(cp_model.CpSolver, "solve", return_value=cp_model.UNKNOWN) as solve:
            solve_snapshot(snapshot, timeout_seconds=1)

        model = solve.call_args.args[0]
        candidate_count = sum(
            1
            for variable in model.proto.variables
            if variable.name.startswith("candidate_")
        )
        no_overlap_count = sum(
            1
            for constraint in model.proto.constraints
            if constraint.has_no_overlap()
        )

        self.assertGreater(candidate_count, 100)
        self.assertGreaterEqual(no_overlap_count, 2)
        self.assertLess(len(model.proto.constraints), candidate_count * 20)

    def test_faculty_idle_gap_counts_only_time_between_adjacent_meetings(self) -> None:
        snapshot = self.snapshot()
        base_demand = snapshot["scheduling_demands"][0]
        starts = ["08:00:00", "10:00:00", "12:00:00"]
        demands: list[dict[str, Any]] = []

        for index, starts_at in enumerate(starts, start=1):
            demand = copy.deepcopy(base_demand)
            demand.update({
                "scheduling_demand_id": 6000 + index,
                "demand_key": f"fixed-gap-{index}",
                "term_offering_id": 7000 + index,
                "section_id": 8000 + index,
                "section_delivery_group_id": 9000 + index,
                "course_id": 1000 + index,
                "course_component_id": 2000 + index,
                "load_units": "1.00",
                "eligible_faculty_user_ids": [200],
                "faculty_load_options": [{
                    "faculty_user_id": 200,
                    "qualification_id": 3000 + index,
                    "term_load_override_id": None,
                    "max_allowed_units": "100.00",
                }],
                "fixed_faculty_user_id": 200,
                "fixed_room_id": 301,
                "fixed_day_of_week": 1,
                "fixed_start_time": starts_at,
            })
            demands.append(demand)

        snapshot["scheduling_demands"] = demands
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "100.00"}]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(
            -120,
            result["objective_details"]["terms"]["reduce_faculty_idle_gaps"]["raw"],
        )

    def test_exact_coverage_makes_conflicting_fixed_demands_infeasible(self) -> None:
        snapshot = self.snapshot()

        for demand in snapshot["scheduling_demands"]:
            demand["eligible_faculty_user_ids"] = [200]
            demand["fixed_faculty_user_id"] = 200
            demand["fixed_room_id"] = 301
            demand["fixed_day_of_week"] = 1
            demand["fixed_start_time"] = "08:00:00"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])

    def test_required_room_features_are_hard_constraints(self) -> None:
        snapshot = self.snapshot()
        snapshot["scheduling_demands"][0]["required_room_feature_keys"] = ["PROJECTOR"]
        snapshot["rooms"][0]["feature_keys"] = []

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual("missing_room", result["assignments"][0]["violations"][0]["type"])

    def test_faculty_load_counts_each_offering_group_once(self) -> None:
        snapshot = self.snapshot()
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "3.00"}]

        for demand in snapshot["scheduling_demands"]:
            demand["eligible_faculty_user_ids"] = [200]
            demand["faculty_load_options"] = [{"faculty_user_id": 200, "max_allowed_units": "3.00"}]
            demand["load_units"] = "3.00"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])

    def test_linked_components_do_not_double_count_faculty_load(self) -> None:
        snapshot = self.snapshot()
        first = snapshot["scheduling_demands"][0]

        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "3.00"}]
        for demand in snapshot["scheduling_demands"]:
            demand["term_offering_id"] = first["term_offering_id"]
            demand["section_delivery_group_id"] = first["section_delivery_group_id"]
            demand["eligible_faculty_user_ids"] = [200]
            demand["faculty_load_options"] = [{"faculty_user_id": 200, "max_allowed_units": "3.00"}]
            demand["load_units"] = "3.00"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(2, result["assigned_count"])

    def test_unsupported_meeting_count_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["scheduling_demands"][0]["meeting_count"] = 2

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("model_invalid", result["solver_status"])

    def test_unsupported_contract_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["contract_version"] = "unknown"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("model_invalid", result["solver_status"])

    def test_tampered_profile_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["constraint_profile"]["soft_weights"]["balance_faculty_load"] = 99

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("model_invalid", result["solver_status"])

    def snapshot(self) -> dict[str, Any]:
        path = Path(__file__).resolve().parents[1] / "samples" / "minimal_snapshot.json"

        return copy.deepcopy(json.loads(path.read_text(encoding="utf-8-sig")))

    def scaled_snapshot(self, demand_count: int, slot_count: int) -> dict[str, Any]:
        snapshot = self.snapshot()
        base_demand = snapshot["scheduling_demands"][0]
        demands: list[dict[str, Any]] = []

        for index in range(demand_count):
            demand = copy.deepcopy(base_demand)
            demand.update({
                "scheduling_demand_id": 10_000 + index,
                "demand_key": f"scaled-{index}",
                "term_offering_id": 20_000 + index,
                "section_id": 30_000 + index,
                "section_delivery_group_id": 40_000 + index,
                "course_id": 50_000 + index,
                "course_component_id": 60_000 + index,
                "required_duration_minutes": 30,
                "load_units": "1.00",
                "eligible_faculty_user_ids": [200],
                "faculty_load_options": [{
                    "faculty_user_id": 200,
                    "qualification_id": 70_000 + index,
                    "term_load_override_id": None,
                    "max_allowed_units": "100.00",
                }],
            })
            demands.append(demand)

        snapshot["scheduling_demands"] = demands
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "100.00"}]
        snapshot["time_slots"] = [
            {
                "time_slot_id": index + 1,
                "time_block_key": f"D1-{(420 + (index * 30)):04d}",
                "day_of_week": 1,
                "starts_at": self.time_from_minutes(420 + (index * 30)),
                "ends_at": self.time_from_minutes(450 + (index * 30)),
                "duration_minutes": 30,
            }
            for index in range(slot_count)
        ]

        return snapshot

    def time_from_minutes(self, value: int) -> str:
        return f"{value // 60:02d}:{value % 60:02d}:00"

    def assert_laravel_assignment_contract(self, row: dict[str, Any]) -> None:
        required_keys = {
            "scheduling_demand_id",
            "term_offering_id",
            "section_id",
            "section_delivery_group_id",
            "subject_id",
            "course_component_id",
            "faculty_id",
            "faculty_user_id",
            "room_id",
            "day_of_week",
            "starts_at",
            "ends_at",
            "time_block_key",
            "meeting_sequence",
            "assignment_status",
            "violations",
            "warnings",
            "scores",
        }

        self.assertLessEqual(required_keys, set(row.keys()))
        self.assertIsInstance(row["scheduling_demand_id"], int)
        self.assertIsInstance(row["faculty_id"], int)
        self.assertTrue(row["room_id"] is None or isinstance(row["room_id"], int))
        self.assertIsInstance(row["day_of_week"], int)
        self.assertRegex(row["starts_at"], r"^\d{2}:\d{2}:\d{2}$")
        self.assertRegex(row["ends_at"], r"^\d{2}:\d{2}:\d{2}$")
        self.assertIsInstance(row["time_block_key"], str)
        self.assertGreaterEqual(row["meeting_sequence"], 1)
        self.assertIn(row["assignment_status"], {"ok", "warning", "conflict"})
        self.assertIsInstance(row["violations"], list)
        self.assertIsInstance(row["warnings"], list)
        self.assertIsInstance(row["scores"], dict)

    def hard_constraint_violations(self, rows: list[dict[str, Any]]) -> list[str]:
        violations: list[str] = []

        for left_index, left in enumerate(rows):
            for right_index in range(left_index + 1, len(rows)):
                right = rows[right_index]

                if not self.overlaps(left, right):
                    continue

                if left["section_delivery_group_id"] == right["section_delivery_group_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one delivery group")

                if left["faculty_id"] == right["faculty_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one faculty")

                if left["room_id"] is not None and left["room_id"] == right["room_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one room")

        return violations

    def overlaps(self, left: dict[str, Any], right: dict[str, Any]) -> bool:
        return (
            int(left["day_of_week"]) == int(right["day_of_week"])
            and self.minutes(left["starts_at"]) < self.minutes(right["ends_at"])
            and self.minutes(left["ends_at"]) > self.minutes(right["starts_at"])
        )

    def minutes(self, value: str) -> int:
        hours, minutes, *_ = value.split(":")

        return (int(hours) * 60) + int(minutes)


if __name__ == "__main__":
    unittest.main()
