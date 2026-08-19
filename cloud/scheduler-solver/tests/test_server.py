from __future__ import annotations

import copy
import json
import os
import unittest
from pathlib import Path
from unittest.mock import patch

from tala_solver.server import create_app


class SolverServerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.app = create_app()
        self.app.config.update(TESTING=True)
        self.client = self.app.test_client()

    def test_health_returns_service_and_version_metadata(self) -> None:
        response = self.client.get("/health")

        self.assertEqual(200, response.status_code)
        self.assertEqual(
            {
                "status": "ok",
                "service": "tala-scheduler-solver",
                "contract_version": "tala-timetable-v2",
                "solver_version": "cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2",
            },
            response.get_json(),
        )

    def test_valid_v2_snapshot_is_solved_through_the_http_boundary(self) -> None:
        response = self.client.post("/solve", json=self.snapshot())
        payload = response.get_json()

        self.assertEqual(200, response.status_code)
        self.assertIn(payload["solver_status"], {"optimal", "feasible"})
        self.assertEqual(2, payload["assigned_count"])
        self.assertEqual(0, payload["unassigned_count"])
        self.assertEqual("tala-timetable-v2", payload["model_version"])
        phase_timings = json.loads(response.headers["X-TALA-Solver-Phase-Timings"])
        self.assertIn("parsing", phase_timings)
        self.assertIn("candidate_enumeration", phase_timings)
        self.assertIn("hard_model_construction", phase_timings)
        self.assertIn("result_construction", phase_timings)
        self.assertIn("serialization", phase_timings)
        self.assertTrue(all(isinstance(value, int) and value >= 0 for value in phase_timings.values()))

    def test_solver_budget_comes_from_the_bounded_environment_setting(self) -> None:
        with (
            patch.dict(os.environ, {"SOLVER_REQUEST_BUDGET_SECONDS": "45"}),
            patch("tala_solver.server.solve_snapshot") as solve,
        ):
            solve.return_value = {"solver_status": "optimal", "assignments": []}

            response = self.client.post("/solve", json={"contract_version": "tal94-demand-v2"})

        self.assertEqual(200, response.status_code)
        context = solve.call_args.kwargs["request_context"]
        self.assertEqual(45.0, context.budget_seconds)

    def test_solver_budget_is_clamped_to_the_approved_300_second_request_cap(self) -> None:
        with (
            patch.dict(os.environ, {"SOLVER_REQUEST_BUDGET_SECONDS": "360"}),
            patch("tala_solver.server.solve_snapshot") as solve,
        ):
            solve.return_value = {"solver_status": "optimal", "assignments": []}

            response = self.client.post("/solve", json={"contract_version": "tal94-demand-v2"})

        self.assertEqual(200, response.status_code)
        context = solve.call_args.kwargs["request_context"]
        self.assertEqual(300.0, context.budget_seconds)

    def test_budget_exhaustion_returns_a_bounded_retryable_technical_failure(self) -> None:
        from tala_solver.runtime import RequestBudgetExceeded

        with patch(
            "tala_solver.server.solve_snapshot",
            side_effect=RequestBudgetExceeded("candidate_enumeration"),
        ):
            response = self.client.post(
                "/solve",
                json={"contract_version": "tala-timetable-v2"},
                headers={
                    "X-TALA-Solver-Request-ID": "schedule-solver:run:1:cycle:1:attempt:1",
                },
            )

        self.assertEqual(503, response.status_code)
        self.assertEqual("solver_request_budget_exhausted", response.get_json()["code"])
        self.assertIsInstance(
            json.loads(response.headers["X-TALA-Solver-Phase-Timings"]),
            dict,
        )

    def test_app_startup_rejects_an_invalid_solver_runtime_configuration(self) -> None:
        with patch.dict(os.environ, {"SOLVER_WORKER_COUNT": "3"}):
            with self.assertRaisesRegex(RuntimeError, "SOLVER_WORKER_COUNT"):
                create_app()

    def test_missing_or_malformed_json_returns_structured_bad_request(self) -> None:
        cases = [
            self.client.post("/solve"),
            self.client.post("/solve", data="{", content_type="application/json"),
            self.client.post("/solve", json=["not", "an", "object"]),
        ]

        for response in cases:
            with self.subTest(response=response):
                payload = response.get_json()
                self.assertEqual(400, response.status_code)
                self.assertEqual("bad_request", payload["status"])
                self.assertIsInstance(payload["code"], str)
                self.assertIsInstance(payload["message"], str)

    def test_wrong_routes_and_methods_return_structured_errors(self) -> None:
        cases = [
            (self.client.get("/missing"), 404, "not_found"),
            (self.client.get("/solve"), 405, "method_not_allowed"),
            (self.client.post("/health", json={}), 405, "method_not_allowed"),
        ]

        for response, expected_status, expected_code in cases:
            with self.subTest(expected_code=expected_code):
                payload = response.get_json()
                self.assertEqual(expected_status, response.status_code)
                self.assertEqual("error", payload["status"])
                self.assertEqual(expected_code, payload["code"])

    def test_internal_solver_failure_is_logged_but_not_returned_to_the_caller(self) -> None:
        with (
            patch("tala_solver.server.solve_snapshot", side_effect=RuntimeError("private solver detail")),
            self.assertLogs("tala_solver.server", level="ERROR") as captured,
        ):
            response = self.client.post("/solve", json={"contract_version": "tal94-demand-v2"})

        payload = response.get_json()
        self.assertEqual(500, response.status_code)
        self.assertEqual("error", payload["status"])
        self.assertEqual("internal_error", payload["code"])
        self.assertNotIn("private solver detail", response.get_data(as_text=True))
        self.assertIn("private solver detail", "\n".join(captured.output))

    def snapshot(self) -> dict[str, object]:
        path = Path(__file__).resolve().parents[1] / "samples" / "minimal_snapshot.json"

        return copy.deepcopy(json.loads(path.read_text(encoding="utf-8-sig")))


if __name__ == "__main__":
    unittest.main()
