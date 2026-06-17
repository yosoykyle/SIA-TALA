from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path
from typing import Any

from tala_solver.solver import solve_snapshot


class SolveSnapshotTest(unittest.TestCase):
    def test_feasible_input_auto_assignment_proof_exceeds_98_percent_without_hard_constraint_violations(self) -> None:
        snapshot = self.feasible_snapshot(section_count=20, subjects_per_section=5)
        demand_count = len(snapshot["curriculum_subject_demand"])

        result = solve_snapshot(snapshot, timeout_seconds=30)

        coverage = result["assigned_count"] / demand_count
        ok_rows = [row for row in result["draft_rows"] if row["status"] == "ok"]

        self.assertGreaterEqual(demand_count, 100)
        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertGreater(coverage, 0.98)
        self.assertEqual(demand_count, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertEqual(demand_count, len(ok_rows))
        self.assertEqual([], self.hard_constraint_violations(snapshot, ok_rows))

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

    def feasible_snapshot(self, section_count: int, subjects_per_section: int) -> dict[str, Any]:
        subject_ids = [2000 + index for index in range(subjects_per_section)]
        subject_slots = [
            ("08:00:00", "09:00:00"),
            ("09:00:00", "10:00:00"),
            ("10:00:00", "11:00:00"),
            ("11:00:00", "12:00:00"),
            ("13:00:00", "14:00:00"),
        ]
        sections: list[dict[str, Any]] = []
        demands: list[dict[str, Any]] = []
        eligibility: list[dict[str, Any]] = []
        availability: list[dict[str, Any]] = []
        existing_commitments: list[dict[str, Any]] = []
        rooms_catalog: list[dict[str, Any]] = []

        for section_index in range(section_count):
            section_id = 1000 + section_index
            room = f"R-{section_index + 1:03d}"
            sections.append({
                "section_id": section_id,
                "section_name": f"BSIT 1-{section_index + 1:02d}",
                "program_id": 1,
                "program_code": "BSIT",
                "curriculum_id": 1,
                "year_level": "1st Year",
                "curriculum_period": "1st Semester",
                "modality": "on_site",
                "max_seats": 30,
                "enrolled_count": 25,
                "available_seats": 5,
                "fixed_room": room,
            })
            rooms_catalog.append({
                "room_code": room,
                "source": "sections.room",
                "section_ids": [section_id],
                "max_section_capacity": 30,
                "modalities": ["on_site"],
            })

            for subject_id in subject_ids:
                demands.append({
                    "section_id": section_id,
                    "subject_id": subject_id,
                    "subject_code": f"SUBJ{subject_id}",
                    "units": "3.00",
                    "lec_hours": "1.00",
                })

        for subject_index, subject_id in enumerate(subject_ids):
            starts_at, ends_at = subject_slots[subject_index]
            blocked_faculty_id = 3000 + (subject_index * 100)

            existing_commitments.append({
                "section_meeting_id": 9000 + subject_index,
                "section_id": 9900 + subject_index,
                "subject_id": subject_id,
                "faculty_id": blocked_faculty_id,
                "room": f"BLOCK-{subject_index + 1}",
                "day_of_week": 1,
                "starts_at": starts_at,
                "ends_at": ends_at,
                "modality": "on_site",
            })

            for faculty_index in range(section_count + 1):
                faculty_id = 3000 + (subject_index * 100) + faculty_index
                eligibility.append({
                    "faculty_id": faculty_id,
                    "subject_id": subject_id,
                    "scope": "default",
                    "priority": faculty_index + 1,
                })
                availability.append({
                    "faculty_id": faculty_id,
                    "status": "locked",
                    "version": 1,
                    "windows": [{
                        "day_of_week": 1,
                        "starts_at": starts_at,
                        "ends_at": ends_at,
                    }],
                })

        return {
            "schema_version": 1,
            "run_metadata": {
                "run_id": 98,
                "term_id": 1,
                "timezone": "Asia/Manila",
            },
            "sections": sections,
            "curriculum_subject_demand": demands,
            "faculty_eligibility": eligibility,
            "faculty_availability": availability,
            "rooms_catalog": rooms_catalog,
            "existing_commitments": existing_commitments,
            "policy_constraints": {
                "timezone": "Asia/Manila",
                "slot_granularity_minutes": 30,
                "mandatory_faculty_assignment": True,
                "max_section_seats": 30,
                "section_capacity_mode": "editable_bounded_max_30_not_below_enrolled_count",
                "room_catalog_mode": "sections.room fixed-room rescue catalog",
            },
        }

    def hard_constraint_violations(self, snapshot: dict[str, Any], rows: list[dict[str, Any]]) -> list[str]:
        sections = {int(section["section_id"]): section for section in snapshot["sections"]}
        demand_keys = {
            f"{int(demand['section_id'])}:{int(demand['subject_id'])}"
            for demand in snapshot["curriculum_subject_demand"]
        }
        eligibility: dict[int, set[int]] = {}
        availability: dict[int, list[dict[str, Any]]] = {}

        for row in snapshot["faculty_eligibility"]:
            eligibility.setdefault(int(row["subject_id"]), set()).add(int(row["faculty_id"]))

        for submission in snapshot["faculty_availability"]:
            availability[int(submission["faculty_id"])] = submission["windows"]

        violations: list[str] = []

        for index, row in enumerate(rows):
            section_id = int(row["section_id"])
            subject_id = int(row["subject_id"])
            faculty_id = int(row["faculty_id"])
            section = sections.get(section_id)
            label = f"row {index} ({section_id}:{subject_id})"

            if row["status"] != "ok":
                violations.append(f"{label} is not ok")

            if section is None:
                violations.append(f"{label} section is missing from snapshot")
                continue

            if f"{section_id}:{subject_id}" not in demand_keys:
                violations.append(f"{label} demand is missing from snapshot")

            if faculty_id not in eligibility.get(subject_id, set()):
                violations.append(f"{label} faculty is not eligible for subject")

            if int(section["max_seats"]) > 30 or int(section["enrolled_count"]) > int(section["max_seats"]):
                violations.append(f"{label} section capacity violates the rescue contract")

            starts = self.minutes(row["starts_at"])
            ends = self.minutes(row["ends_at"])

            if starts >= ends:
                violations.append(f"{label} time range is invalid")

            if row["modality"] in {"on_site", "blended"}:
                if row["room"] is None:
                    violations.append(f"{label} requires a room")
                elif row["room"] != section["fixed_room"]:
                    violations.append(f"{label} room does not match the fixed section room")

            if not self.inside_any_window(row, availability.get(faculty_id, [])):
                violations.append(f"{label} is outside faculty availability")

            if self.conflicts_existing(row, snapshot["existing_commitments"]):
                violations.append(f"{label} conflicts with existing commitments")

        for left_index, left in enumerate(rows):
            for right_index in range(left_index + 1, len(rows)):
                right = rows[right_index]

                if not self.overlaps(left, right):
                    continue

                if left["section_id"] == right["section_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one section")

                if left["faculty_id"] == right["faculty_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one faculty")

                if left["room"] is not None and left["room"] == right["room"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one room")

        return violations

    def inside_any_window(self, row: dict[str, Any], windows: list[dict[str, Any]]) -> bool:
        starts = self.minutes(row["starts_at"])
        ends = self.minutes(row["ends_at"])

        for window in windows:
            if int(window["day_of_week"]) != int(row["day_of_week"]):
                continue

            if self.minutes(window["starts_at"]) <= starts and self.minutes(window["ends_at"]) >= ends:
                return True

        return False

    def conflicts_existing(self, row: dict[str, Any], commitments: list[dict[str, Any]]) -> bool:
        for commitment in commitments:
            if int(commitment["day_of_week"]) != int(row["day_of_week"]):
                continue

            if not self.time_ranges_overlap(row, commitment):
                continue

            if int(commitment["section_id"]) == int(row["section_id"]):
                return True

            if int(commitment["faculty_id"]) == int(row["faculty_id"]):
                return True

            if row["room"] is not None and commitment["room"] == row["room"]:
                return True

        return False

    def overlaps(self, left: dict[str, Any], right: dict[str, Any]) -> bool:
        return int(left["day_of_week"]) == int(right["day_of_week"]) and self.time_ranges_overlap(left, right)

    def time_ranges_overlap(self, left: dict[str, Any], right: dict[str, Any]) -> bool:
        return self.minutes(left["starts_at"]) < self.minutes(right["ends_at"]) and self.minutes(left["ends_at"]) > self.minutes(right["starts_at"])

    def minutes(self, value: str) -> int:
        hours, minutes, *_ = value.split(":")

        return (int(hours) * 60) + int(minutes)


if __name__ == "__main__":
    unittest.main()
