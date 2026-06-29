from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path
from typing import Any

from tala_solver.solver import solve_snapshot


class SolveSnapshotTest(unittest.TestCase):
    def test_accepts_tal61_demands_and_emits_laravel_assignment_contract(self) -> None:
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

    def test_unassignable_tal61_demand_returns_conflict_assignment_with_demand_id(self) -> None:
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

    def snapshot(self) -> dict[str, Any]:
        path = Path(__file__).resolve().parents[1] / "samples" / "minimal_snapshot.json"

        return copy.deepcopy(json.loads(path.read_text(encoding="utf-8-sig")))

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
