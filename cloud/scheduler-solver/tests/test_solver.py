from __future__ import annotations

import copy
import json
import os
import unittest
from pathlib import Path
from typing import Any
from unittest.mock import patch

from ortools.sat.python import cp_model

from tala_solver.solver import (
    evaluate_candidate_membership,
    solve_snapshot,
    solver_runtime_configuration,
)
from tala_solver.runtime import RequestBudgetExceeded


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
        self.assertIn(
            result["solver_statistics"]["search_stages"]["feasibility"]["status"],
            {"optimal", "feasible"},
        )
        self.assertIn(
            result["solver_statistics"]["search_stages"]["optimization"]["status"],
            {"optimal", "feasible"},
        )
        self.assertEqual(
            "lexicographic",
            result["solver_statistics"]["result_source"],
        )

    def test_request_budget_exhaustion_after_feasibility_keeps_the_complete_timetable(self) -> None:
        with patch(
            "tala_solver.solver._lexicographic_objectives",
            side_effect=RequestBudgetExceeded("objective_construction"),
        ):
            result = solve_snapshot(self.snapshot(), timeout_seconds=10)

        self.assertEqual("feasible", result["solver_status"])
        self.assertEqual(2, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertEqual(
            "optimization_budget_exhausted",
            result["warnings"][0]["type"],
        )

    def test_unknown_feasibility_search_returns_no_false_conflict_timetable(self) -> None:
        snapshot = self.snapshot()

        with patch.object(cp_model.CpSolver, "solve", return_value=cp_model.UNKNOWN):
            result = solve_snapshot(snapshot, timeout_seconds=1)

        self.assertEqual("unknown", result["solver_status"])
        self.assertTrue(result["timeout"])
        self.assertEqual([], result["assignments"])
        self.assertEqual([], result["infeasible_reasons"])
        self.assertEqual([], result["hard_constraint_violations"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertEqual(
            "search_limit",
            result["warnings"][0]["type"],
        )
        self.assertEqual(
            "unknown",
            result["solver_statistics"]["search_stages"]["feasibility"]["status"],
        )
        self.assertEqual(
            "not_run",
            result["solver_statistics"]["search_stages"]["optimization"]["status"],
        )
        self.assertEqual(
            "none",
            result["solver_statistics"]["result_source"],
        )

    def test_unknown_optimization_keeps_the_complete_feasibility_assignment(self) -> None:
        snapshot = self.snapshot()
        snapshot["scheduling_demands"][0].update({
            "fixed_faculty_user_id": 200,
            "fixed_room_id": 301,
            "fixed_day_of_week": 1,
            "fixed_start_time": "08:00:00",
        })
        snapshot["scheduling_demands"][1].update({
            "fixed_faculty_user_id": 201,
            "fixed_room_id": 301,
            "fixed_day_of_week": 1,
            "fixed_start_time": "09:00:00",
        })

        with (
            patch.object(
                cp_model.CpSolver,
                "solve",
                side_effect=[cp_model.OPTIMAL, cp_model.UNKNOWN],
            ),
            patch.object(
                cp_model.CpSolver,
                "boolean_value",
                side_effect=lambda variable: variable.name in {"candidate_0", "candidate_1"},
            ),
        ):
            result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("feasible", result["solver_status"])
        self.assertFalse(result["timeout"])
        self.assertEqual(2, result["assigned_count"])
        self.assertEqual(0, result["unassigned_count"])
        self.assertEqual(0, result["hard_violation_count"])
        self.assertEqual([], result["infeasible_reasons"])
        self.assertEqual(
            "optimization_limit_reached",
            result["warnings"][0]["type"],
        )
        self.assertEqual(
            "unknown",
            result["solver_statistics"]["search_stages"]["optimization"]["status"],
        )
        self.assertEqual(
            "lexicographic",
            result["solver_statistics"]["result_source"],
        )

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
        demand["fixed_day_of_week"] = 1
        demand["fixed_start_time"] = "08:00:00"

        result = solve_snapshot(snapshot, timeout_seconds=10)
        assignment = next(
            row for row in result["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )

        self.assertEqual("ok", assignment["assignment_status"])
        self.assertEqual(200, assignment["faculty_id"])
        self.assertEqual(301, assignment["room_id"])
        self.assertEqual(1, assignment["day_of_week"])
        self.assertEqual("08:00:00", assignment["starts_at"])
        self.assertEqual("09:00:00", assignment["ends_at"])
        self.assertEqual(1, assignment["time_slot_id"])
        self.assertEqual("D1-0800", assignment["time_block_key"])

    def test_off_grid_fixed_time_has_no_admissible_candidate(self) -> None:
        snapshot = self.snapshot()
        demand = snapshot["scheduling_demands"][0]
        demand["fixed_faculty_user_id"] = 200
        demand["fixed_room_id"] = 301
        demand["fixed_day_of_week"] = 1
        demand["fixed_start_time"] = "08:13:00"

        result = solve_snapshot(snapshot, timeout_seconds=10)
        assignment = next(
            row for row in result["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )

        self.assertEqual("conflict", assignment["assignment_status"])
        self.assertEqual("missing_time_slot", assignment["violations"][0]["type"])

    def test_candidate_membership_replay_accepts_valid_rows_and_rejects_tampering(self) -> None:
        snapshot = self.snapshot()
        result = solve_snapshot(snapshot, timeout_seconds=10)

        replay = evaluate_candidate_membership(snapshot, result["assignments"])

        self.assertTrue(replay["all_admissible"])
        self.assertTrue(replay["complete_demand_coverage"])
        self.assertEqual(2, replay["admissible_count"])

        tampered = copy.deepcopy(result["assignments"])
        tampered[0]["starts_at"] = "08:13:00"
        tampered[0]["start_time"] = "08:13:00"
        tampered[0]["ends_at"] = "09:13:00"
        tampered[0]["end_time"] = "09:13:00"
        tampered[0]["time_slot_id"] = None
        tampered[0]["time_block_key"] = "fixed-off-grid"
        tampered[0]["time_block_reference"] = "fixed-off-grid"

        rejected = evaluate_candidate_membership(snapshot, tampered)

        self.assertFalse(rejected["all_admissible"])
        self.assertEqual(1, rejected["admissible_count"])

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

    def test_shared_cohort_identity_prevents_overlap_across_course_specific_delivery_groups(self) -> None:
        snapshot = self.snapshot()
        first_demand, second_demand = snapshot["scheduling_demands"]

        first_demand.update({
            "cohort_or_student_group_id": 110,
            "fixed_faculty_user_id": 200,
            "fixed_room_id": 301,
            "fixed_day_of_week": 1,
            "fixed_start_time": "08:00:00",
        })
        second_demand.update({
            "section_delivery_group_id": 111,
            "cohort_or_student_group_id": 110,
            "fixed_faculty_user_id": 201,
            "fixed_room_id": 302,
            "fixed_day_of_week": 1,
            "fixed_start_time": "08:00:00",
        })
        snapshot["rooms"].append({
            "room_id": 302,
            "code": "R-102",
            "name": "Room 102",
            "room_type": "LECTURE_ROOM",
            "capacity": 40,
            "feature_keys": [],
        })
        snapshot["student_cohort_groups"] = [
            {
                "cohort_or_student_group_id": 110,
                "section_delivery_group_id": 110,
                "expected_count": 30,
            },
            {
                "cohort_or_student_group_id": 110,
                "section_delivery_group_id": 111,
                "expected_count": 30,
            },
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])
        self.assertEqual(2, result["unassigned_count"])

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

    def test_v2_faculty_declaration_uses_calendar_blocks_not_legacy_windows(self) -> None:
        snapshot = self.snapshot()
        demand = snapshot["scheduling_demands"][0]
        demand["fixed_faculty_user_id"] = 200
        demand["fixed_room_id"] = 301
        demand["fixed_day_of_week"] = 1
        demand["fixed_start_time"] = "08:00:00"
        snapshot["faculty_availability"] = [
            {
                "faculty_user_id": 200,
                "declaration_version": 2,
                "declaration": "Available",
                "hard_unavailability": [
                    {
                        "day_of_week": 1,
                        "starts_at": "08:00:00",
                        "ends_at": "09:00:00",
                    }
                ],
            }
        ]

        without_projected_block = solve_snapshot(snapshot, timeout_seconds=10)
        assignment = next(
            row for row in without_projected_block["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )
        self.assertEqual("ok", assignment["assignment_status"])

        snapshot["calendar_blocks"] = [
            {
                "faculty_availability_declaration_id": 42,
                "declaration_version": 2,
                "event_type": "FacultyAvailabilityDeclaration",
                "scope_type": "Faculty",
                "faculty_user_id": 200,
                "room_id": None,
                "authority": "Faculty declaration v2",
                "day_of_week": 1,
                "starts_at": "08:00:00",
                "ends_at": "09:00:00",
            }
        ]

        with_projected_block = solve_snapshot(snapshot, timeout_seconds=10)
        blocked = next(
            row for row in with_projected_block["assignments"]
            if row["scheduling_demand_id"] == demand["scheduling_demand_id"]
        )
        self.assertEqual("conflict", blocked["assignment_status"])

    def test_v2_profile_reports_the_fixed_lexicographic_hierarchy_without_weights(self) -> None:
        result = solve_snapshot(self.snapshot(), timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        details = result["objective_details"]
        self.assertEqual("lexicographic_v1", details["profile_key"])
        self.assertEqual(
            [
                "cohort_mode_switches",
                "cohort_idle_time",
                "faculty_load_imbalance",
                "faculty_idle_time",
                "room_seat_waste",
                "stable_earlier_placement",
            ],
            details["objective_hierarchy"],
        )
        self.assertIsNone(result["objective_score"])
        self.assertIsNone(details["scalar_score"])

    def test_indexed_solver_matches_a_small_reference_hard_rule_and_objective_evaluator(self) -> None:
        snapshot = self.snapshot()
        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual([], self.hard_constraint_violations(result["assignments"]))
        self.assertEqual(
            self.reference_objective_values(snapshot, result["assignments"]),
            result["objective_details"]["values"],
        )

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
                "result_source",
                "search_stages",
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
        self.assertIsNone(statistics["relative_optimality_gap"])
        self.assertIsInstance(statistics["boolean_variable_count"], int)
        self.assertIsInstance(statistics["branch_count"], int)
        self.assertIsInstance(statistics["conflict_count"], int)
        self.assertIsInstance(statistics["deterministic_time_seconds"], float)
        self.assertIsInstance(statistics["wall_time_seconds"], float)
        self.assertEqual(1, statistics["worker_count"])
        self.assertEqual(20_260_718, statistics["random_seed"])
        self.assertEqual(
            {
                "feasibility",
                "optimization",
            },
            set(statistics["search_stages"]),
        )
        self.assertLess(
            statistics["search_stages"]["feasibility"]["model_variable_count"],
            statistics["search_stages"]["optimization"]["model_variable_count"],
        )

    def test_solver_runtime_configuration_accepts_the_approved_worker_profiles(self) -> None:
        for worker_count in (1, 2, 4, 8):
            with self.subTest(worker_count=worker_count), patch.dict(
                os.environ,
                {
                    "SOLVER_WORKER_COUNT": str(worker_count),
                    "SOLVER_RANDOM_SEED": "20260718",
                },
            ):
                configuration = solver_runtime_configuration()
                result = solve_snapshot(self.snapshot(), timeout_seconds=10)

            self.assertEqual(worker_count, configuration.worker_count)
            self.assertEqual(worker_count, result["solver_statistics"]["worker_count"])
            self.assertEqual(20_260_718, result["solver_statistics"]["random_seed"])

    def test_solver_runtime_configuration_rejects_unapproved_values(self) -> None:
        cases = [
            ({"SOLVER_WORKER_COUNT": "3"}, "SOLVER_WORKER_COUNT"),
            ({"SOLVER_RANDOM_SEED": "17"}, "SOLVER_RANDOM_SEED"),
            ({"SOLVER_WORKER_COUNT": "not-an-integer"}, "SOLVER_WORKER_COUNT"),
        ]

        for environment, expected_message in cases:
            with self.subTest(environment=environment), patch.dict(os.environ, environment):
                with self.assertRaisesRegex(RuntimeError, expected_message):
                    solver_runtime_configuration()

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
                "cohort_or_student_group_id": 9000 + index,
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
        snapshot["student_cohort_groups"] = [
            {
                "cohort_or_student_group_id": demand["cohort_or_student_group_id"],
                "section_delivery_group_id": demand["section_delivery_group_id"],
                "expected_count": demand["expected_count"],
            }
            for demand in demands
        ]
        snapshot["faculty"] = [{"faculty_id": 200, "max_allowed_units": "100.00"}]
        snapshot["time_slots"] = [
            {
                "time_slot_id": index + 1,
                "time_block_key": f"D1-{minute // 60:02d}{minute % 60:02d}",
                "day_of_week": 1,
                "starts_at": f"{minute // 60:02d}:{minute % 60:02d}:00",
                "ends_at": f"{(minute + 30) // 60:02d}:{(minute + 30) % 60:02d}:00",
                "duration_minutes": 30,
            }
            for index, minute in enumerate(range(8 * 60, 13 * 60 + 1, 30))
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(
            120,
            result["objective_details"]["values"]["faculty_idle_time"],
        )

    def test_cohort_mode_switches_count_only_adjacent_selected_meetings(self) -> None:
        snapshot = self.scaled_snapshot(demand_count=4, slot_count=9)
        modalities = ["FACE_TO_FACE", "ONLINE", "ONLINE", "FACE_TO_FACE"]

        for index, demand in enumerate(snapshot["scheduling_demands"]):
            demand.update({
                "section_delivery_group_id": 41_000 + index,
                "cohort_or_student_group_id": 999,
                "modality": modalities[index],
                "room_required": modalities[index] == "FACE_TO_FACE",
                "fixed_room_id": 301 if modalities[index] == "FACE_TO_FACE" else None,
                "fixed_day_of_week": 1,
                "fixed_start_time": self.time_from_minutes(7 * 60 + (index * 60)),
                "required_duration_minutes": 30,
            })

        snapshot["student_cohort_groups"] = [
            {
                "cohort_or_student_group_id": 999,
                "section_delivery_group_id": demand["section_delivery_group_id"],
                "expected_count": demand["expected_count"],
            }
            for demand in snapshot["scheduling_demands"]
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(2, result["objective_details"]["values"]["cohort_mode_switches"])

    def test_cross_modality_transition_requires_a_thirty_minute_cohort_buffer(self) -> None:
        snapshot = self.scaled_snapshot(demand_count=2, slot_count=4)
        first, second = snapshot["scheduling_demands"]

        for demand in (first, second):
            demand.update({
                "cohort_or_student_group_id": 999,
                "fixed_day_of_week": 1,
                "required_duration_minutes": 30,
            })
        first.update({"modality": "FACE_TO_FACE", "room_required": True, "fixed_room_id": 301, "fixed_start_time": "07:00:00"})
        second.update({"modality": "ONLINE", "room_required": False, "fixed_room_id": None, "fixed_start_time": "07:30:00"})
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 999, "section_delivery_group_id": demand["section_delivery_group_id"], "expected_count": 30}
            for demand in (first, second)
        ]

        blocked = solve_snapshot(snapshot, timeout_seconds=10)
        second["fixed_start_time"] = "08:00:00"
        accepted = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", blocked["solver_status"])
        self.assertIn(accepted["solver_status"], {"optimal", "feasible"})

    def test_room_seat_waste_uses_confirmed_expected_count(self) -> None:
        snapshot = self.scaled_snapshot(demand_count=1, slot_count=2)
        snapshot["rooms"] = [
            {"room_id": 301, "room_type": "LECTURE_ROOM", "capacity": 60, "feature_keys": []},
            {"room_id": 302, "room_type": "LECTURE_ROOM", "capacity": 40, "feature_keys": []},
        ]

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(302, result["assignments"][0]["room_id"])
        self.assertEqual(10, result["objective_details"]["values"]["room_seat_waste"])

    def test_repair_fixes_the_request_and_minimizes_changed_other_meetings_first(self) -> None:
        snapshot = self.snapshot()
        baseline_result = solve_snapshot(snapshot, timeout_seconds=10)
        self.assertIn(baseline_result["solver_status"], {"optimal", "feasible"})

        baseline = baseline_result["assignments"]
        requested = copy.deepcopy(baseline[0])
        occupied = baseline[1]
        requested.update({
            "day_of_week": occupied["day_of_week"],
            "starts_at": occupied["starts_at"],
            "ends_at": occupied["ends_at"],
        })
        snapshot["operation"] = {
            "kind": "repair",
            "source_candidate": {
                "run_id": 1,
                "candidate_version": 1,
                "assignments": baseline,
            },
            "requested_assignment": requested,
        }

        repaired = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(repaired["solver_status"], {"optimal", "feasible"})
        repaired_requested = next(
            row for row in repaired["assignments"]
            if row["scheduling_demand_id"] == requested["scheduling_demand_id"]
        )
        self.assertEqual(requested["starts_at"], repaired_requested["starts_at"])
        self.assertEqual(1, repaired["objective_details"]["repair"]["changed_non_requested_meetings"])
        self.assertEqual("changed_non_requested_meetings", repaired["objective_details"]["completed_levels"][0]["name"])

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

    def test_two_ninety_minute_meetings_are_expanded_without_overlap_for_one_faculty(self) -> None:
        snapshot = self.meeting_pattern_snapshot(meeting_count=2, meeting_duration_minutes=90)

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(2, result["assigned_count"])
        self.assertEqual([1, 2], [row["meeting_sequence"] for row in result["assignments"]])
        self.assertEqual({"2x90"}, {row["meeting_pattern"] for row in result["assignments"]})
        self.assertEqual(1, len({row["faculty_id"] for row in result["assignments"]}))
        self.assertEqual([], self.hard_constraint_violations(result["assignments"]))

    def test_three_sixty_minute_meetings_are_expanded_without_overlap_for_one_faculty(self) -> None:
        snapshot = self.meeting_pattern_snapshot(meeting_count=3, meeting_duration_minutes=60)

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertIn(result["solver_status"], {"optimal", "feasible"})
        self.assertEqual(3, result["assigned_count"])
        self.assertEqual([1, 2, 3], [row["meeting_sequence"] for row in result["assignments"]])
        self.assertEqual({"3x60"}, {row["meeting_pattern"] for row in result["assignments"]})
        self.assertEqual(1, len({row["faculty_id"] for row in result["assignments"]}))
        self.assertEqual([], self.hard_constraint_violations(result["assignments"]))

    def test_inconsistent_shared_cohort_mapping_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["scheduling_demands"][0]["cohort_or_student_group_id"] = 110
        snapshot["student_cohort_groups"][0]["cohort_or_student_group_id"] = 999

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("model_invalid", result["solver_status"])
        self.assertEqual(
            "invalid_student_cohort_mapping",
            result["infeasible_reasons"][0]["type"],
        )

    def test_shared_class_membership_blocks_overlap_for_every_attached_cohort(self) -> None:
        snapshot = self.snapshot()
        first, second = snapshot["scheduling_demands"]
        first["cohort_or_student_group_id"] = 110
        first["cohort_or_student_group_ids"] = [110, 999]
        second["section_delivery_group_id"] = 111
        second["cohort_or_student_group_id"] = 999
        second["cohort_or_student_group_ids"] = [999]
        snapshot["section_delivery_groups"].append({
            "section_delivery_group_id": 111,
            "section_id": second["section_id"],
            "expected_count": second["expected_count"],
            "modality": second["modality"],
        })
        snapshot["student_cohort_groups"] = [
            {"cohort_or_student_group_id": 110, "section_delivery_group_id": 110, "expected_count": 15},
            {"cohort_or_student_group_id": 999, "section_delivery_group_id": 110, "expected_count": 15},
            {"cohort_or_student_group_id": 999, "section_delivery_group_id": 111, "expected_count": 30},
        ]
        snapshot["rooms"].append({
            **snapshot["rooms"][0],
            "room_id": 302,
            "code": "R-102",
            "name": "Room 102",
        })
        for demand, room_id in ((first, 301), (second, 302)):
            demand["fixed_room_id"] = room_id
            demand["fixed_day_of_week"] = 1
            demand["fixed_start_time"] = "08:00:00"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("infeasible", result["solver_status"])
        self.assertEqual(0, result["assigned_count"])

    def test_unsupported_contract_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["contract_version"] = "unknown"

        result = solve_snapshot(snapshot, timeout_seconds=10)

        self.assertEqual("model_invalid", result["solver_status"])

    def test_tampered_profile_is_model_invalid(self) -> None:
        snapshot = self.snapshot()
        snapshot["constraint_profile"]["objective_hierarchy"].reverse()

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
                "cohort_or_student_group_id": 40_000 + index,
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
        snapshot["student_cohort_groups"] = [
            {
                "cohort_or_student_group_id": demand["cohort_or_student_group_id"],
                "section_delivery_group_id": demand["section_delivery_group_id"],
                "expected_count": demand["expected_count"],
            }
            for demand in demands
        ]
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

    def meeting_pattern_snapshot(
        self,
        meeting_count: int,
        meeting_duration_minutes: int,
    ) -> dict[str, Any]:
        snapshot = self.snapshot()
        demand = snapshot["scheduling_demands"][0]
        demand["meeting_count"] = meeting_count
        demand["required_duration_minutes"] = meeting_duration_minutes
        demand["same_faculty_required"] = True
        demand["eligible_faculty_user_ids"] = [200, 201]
        demand["faculty_load_options"] = [
            {"faculty_user_id": faculty_id, "max_allowed_units": "24.00"}
            for faculty_id in (200, 201)
        ]
        snapshot["scheduling_demands"] = [demand]
        snapshot["time_slots"] = [
            {
                "time_slot_id": (day * 100) + index,
                "time_block_key": f"D{day}-{start_minute}",
                "day_of_week": day,
                "starts_at": self.time_from_minutes(start_minute),
                "ends_at": self.time_from_minutes(start_minute + 30),
                "duration_minutes": 30,
            }
            for day in range(1, meeting_count + 1)
            for index, start_minute in enumerate(range(480, 720, 30), start=1)
        ]

        return snapshot

    def assert_laravel_assignment_contract(self, row: dict[str, Any]) -> None:
        required_keys = {
            "scheduling_demand_id",
            "term_offering_id",
            "section_id",
            "section_delivery_group_id",
            "cohort_or_student_group_id",
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
            "meeting_pattern",
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
        self.assertRegex(row["meeting_pattern"], r"^[1-3]x\d+$")
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

                if left["cohort_or_student_group_id"] == right["cohort_or_student_group_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one cohort")

                if left["faculty_id"] == right["faculty_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one faculty")

                if left["room_id"] is not None and left["room_id"] == right["room_id"]:
                    violations.append(f"rows {left_index} and {right_index} overlap for one room")

        return violations

    def reference_objective_values(
        self,
        snapshot: dict[str, Any],
        rows: list[dict[str, Any]],
    ) -> dict[str, int]:
        demands = {
            int(demand["scheduling_demand_id"]): demand
            for demand in snapshot["scheduling_demands"]
        }
        rooms = {
            int(room["room_id"]): room
            for room in snapshot["rooms"]
        }
        cohort_ids_by_delivery_group: dict[int, list[int]] = {}

        for group in snapshot["student_cohort_groups"]:
            cohort_ids_by_delivery_group.setdefault(
                int(group["section_delivery_group_id"]),
                [],
            ).append(int(group["cohort_or_student_group_id"]))
        cohort_days: dict[tuple[int, int], list[tuple[int, int, str]]] = {}
        faculty_days: dict[tuple[int, int], list[tuple[int, int]]] = {}
        faculty_loads: dict[int, dict[tuple[int, int], int]] = {}
        room_seat_waste = 0
        stable_earlier_placement = 0

        for row in rows:
            demand = demands[int(row["scheduling_demand_id"])]
            starts = self.minutes(row["starts_at"])
            ends = self.minutes(row["ends_at"])
            day = int(row["day_of_week"])
            faculty_id = int(row["faculty_id"])
            cohort_ids = demand.get("cohort_or_student_group_ids") or [
                *cohort_ids_by_delivery_group[int(demand["section_delivery_group_id"])]
            ]

            for cohort_id in cohort_ids:
                cohort_days.setdefault((int(cohort_id), day), []).append(
                    (starts, ends, str(demand["modality"]))
                )

            faculty_days.setdefault((faculty_id, day), []).append((starts, ends))
            faculty_loads.setdefault(faculty_id, {})[
                (int(demand["term_offering_id"]), int(demand["section_delivery_group_id"]))
            ] = round(float(demand["load_units"]) * 100)

            if row["room_id"] is not None:
                room = rooms[int(row["room_id"])]
                room_seat_waste += max(
                    0,
                    int(room["capacity"]) - int(demand["expected_count"]),
                )

            stable_earlier_placement += max(0, 10_000 - ((day * 1_000) + starts))

        def idle_minutes(groups: dict[tuple[int, int], list[tuple[int, int] | tuple[int, int, str]]]) -> int:
            return sum(
                max(row[1] for row in group)
                - min(row[0] for row in group)
                - sum(row[1] - row[0] for row in group)
                for group in groups.values()
            )

        mode_switches = sum(
            sum(
                1
                for left, right in zip(ordered, ordered[1:])
                if left[2] != right[2]
            )
            for group in cohort_days.values()
            for ordered in [sorted(group)]
        )
        loads = [sum(group_loads.values()) for group_loads in faculty_loads.values()]
        load_imbalance = sum(
            abs(left - right)
            for left_index, left in enumerate(loads)
            for right in loads[left_index + 1:]
        )

        return {
            "cohort_mode_switches": mode_switches,
            "cohort_idle_time": idle_minutes(cohort_days),
            "faculty_load_imbalance": load_imbalance,
            "faculty_idle_time": idle_minutes(faculty_days),
            "room_seat_waste": room_seat_waste,
            "stable_earlier_placement": stable_earlier_placement,
        }

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
