from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path

from tala_solver.solver import solve_snapshot


class SolveSnapshotTest(unittest.TestCase):
    def test_assigns_feasible_curriculum_demands_without_overlaps(self) -> None:
        snapshot = self.snapshot()

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(2, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(2, len(result["draft_rows"]))
        self.assertTrue(all(row["status"] == "ok" for row in result["draft_rows"]))

        rows = result["draft_rows"]
        self.assertNotEqual((rows[0]["starts_at"], rows[0]["ends_at"]), (rows[1]["starts_at"], rows[1]["ends_at"]))

    def test_unassignable_demand_returns_conflict_row(self) -> None:
        snapshot = self.snapshot()
        snapshot["faculty_eligibility"] = []

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("partial", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])
        self.assertEqual(2, len(result["draft_rows"]))
        self.assertTrue(all(row["status"] == "conflict" for row in result["draft_rows"]))
        self.assertEqual("missing_faculty_subject_eligibility", result["draft_rows"][0]["conflict_payload"]["items"][0]["type"])

    def test_existing_commitments_are_excluded_from_candidates(self) -> None:
        snapshot = self.snapshot()
        snapshot["existing_commitments"] = [
            {
                "section_meeting_id": 99,
                "section_id": 999,
                "subject_id": 999,
                "faculty_id": 200,
                "room": "R-999",
                "day_of_week": 1,
                "starts_at": "08:00:00",
                "ends_at": "12:00:00",
                "modality": "on_site",
            }
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        assigned_faculty = {row["faculty_id"] for row in result["draft_rows"] if row["status"] == "ok"}
        self.assertNotIn(200, assigned_faculty)
        self.assertEqual(1, result["assigned_count"])
        self.assertEqual(1, result["unassigned_count"])

    def snapshot(self) -> dict:
        path = Path(__file__).resolve().parents[1] / "samples" / "minimal_snapshot.json"
        return copy.deepcopy(json.loads(path.read_text(encoding="utf-8-sig")))


if __name__ == "__main__":
    unittest.main()
