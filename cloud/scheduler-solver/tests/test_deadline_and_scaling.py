from __future__ import annotations

import unittest

from tala_solver.runtime import RequestBudgetExceeded, SolverRequestContext


class FakeClock:
    def __init__(self) -> None:
        self.value = 100.0

    def __call__(self) -> float:
        return self.value

    def advance(self, seconds: float) -> None:
        self.value += seconds


class SolverRequestContextTest(unittest.TestCase):
    def test_one_deadline_reserves_response_time_across_every_phase(self) -> None:
        clock = FakeClock()
        context = SolverRequestContext(
            budget_seconds=300,
            response_reserve_seconds=15,
            clock=clock,
        )

        self.assertEqual(285.0, context.remaining_search_seconds())

        clock.advance(120.0)
        self.assertEqual(165.0, context.remaining_search_seconds())

        clock.advance(164.5)
        self.assertAlmostEqual(0.5, context.remaining_search_seconds())

        clock.advance(0.5)
        with self.assertRaises(RequestBudgetExceeded):
            context.checkpoint("objective_construction")

        self.assertEqual(15.0, context.remaining_total_seconds())
        context.checkpoint_total("result_construction")

        clock.advance(15.0)
        with self.assertRaises(RequestBudgetExceeded):
            context.checkpoint_total("serialization")

    def test_phase_timings_are_measured_by_the_same_monotonic_clock(self) -> None:
        clock = FakeClock()
        context = SolverRequestContext(300, 15, clock=clock)

        with context.measure("candidate_enumeration"):
            clock.advance(12.3456)

        self.assertEqual(
            12_346,
            context.phase_timings_ms()["candidate_enumeration"],
        )


if __name__ == "__main__":
    unittest.main()
