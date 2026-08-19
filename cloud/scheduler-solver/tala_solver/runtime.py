from __future__ import annotations

from collections.abc import Callable, Iterator
from contextlib import contextmanager
from time import perf_counter


class RequestBudgetExceeded(RuntimeError):
    def __init__(self, phase: str) -> None:
        super().__init__(f"Solver request budget exhausted during {phase}.")
        self.phase = phase


class SolverRequestContext:
    def __init__(
        self,
        budget_seconds: int | float,
        response_reserve_seconds: int | float = 15,
        *,
        clock: Callable[[], float] = perf_counter,
        request_id: str | None = None,
        snapshot_sha256: str | None = None,
    ) -> None:
        self.budget_seconds = float(max(1, min(int(budget_seconds), 300)))
        self.response_reserve_seconds = float(max(0, response_reserve_seconds))
        self.request_id = request_id
        self.snapshot_sha256 = snapshot_sha256
        self._clock = clock
        self._started_at = clock()
        self._phase_seconds: dict[str, float] = {}
        self._metrics: dict[str, int | float | str | None] = {}

    def elapsed_seconds(self) -> float:
        return max(0.0, self._clock() - self._started_at)

    def remaining_total_seconds(self) -> float:
        return max(0.0, self.budget_seconds - self.elapsed_seconds())

    def remaining_search_seconds(self) -> float:
        return max(
            0.0,
            self.budget_seconds
            - self.response_reserve_seconds
            - self.elapsed_seconds(),
        )

    def checkpoint(self, phase: str) -> None:
        if self.remaining_search_seconds() <= 0.0:
            raise RequestBudgetExceeded(phase)

    def checkpoint_total(self, phase: str) -> None:
        if self.remaining_total_seconds() <= 0.0:
            raise RequestBudgetExceeded(phase)

    @contextmanager
    def measure(self, phase: str, *, reserve_response: bool = True) -> Iterator[None]:
        if reserve_response:
            self.checkpoint(phase)

        started_at = self._clock()
        try:
            yield
        finally:
            self._phase_seconds[phase] = self._phase_seconds.get(phase, 0.0) + max(
                0.0,
                self._clock() - started_at,
            )

    def phase_timings_ms(self) -> dict[str, int]:
        return {
            phase: round(seconds * 1000)
            for phase, seconds in self._phase_seconds.items()
        }

    def record_metric(self, name: str, value: int | float | str | None) -> None:
        self._metrics[name] = value

    def metrics(self) -> dict[str, int | float | str | None]:
        return dict(self._metrics)
