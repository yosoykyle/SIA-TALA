from __future__ import annotations

import hashlib
import json
import os
import re
from uuid import uuid4

from flask import Flask, Response, jsonify, request
from werkzeug.exceptions import BadRequest, HTTPException

from tala_solver.solver import (
    CONTRACT_VERSION,
    SOLVER_VERSION,
    solve_snapshot,
    solver_runtime_configuration,
)
from tala_solver.runtime import RequestBudgetExceeded, SolverRequestContext


REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9:._-]{1,160}$")
SNAPSHOT_HASH_PATTERN = re.compile(r"^[a-f0-9]{64}$")


def create_app() -> Flask:
    solver_runtime_configuration()
    app = Flask(__name__)

    @app.get("/health")
    def health() -> Response:
        return jsonify(
            status="ok",
            service="tala-scheduler-solver",
            contract_version=CONTRACT_VERSION,
            solver_version=SOLVER_VERSION,
        )

    @app.post("/solve")
    def solve() -> tuple[Response, int] | Response:
        request_context = SolverRequestContext(
            budget_seconds=_solver_request_budget_seconds(),
            response_reserve_seconds=15,
            request_id=_request_id(),
            snapshot_sha256=_snapshot_hash_header(),
        )

        if not request.is_json:
            return _error(
                400,
                "bad_request",
                "json_required",
                "Request body must be a JSON object.",
            )

        try:
            with request_context.measure("parsing"):
                raw_request = request.get_data(cache=True)
                snapshot = request.get_json()
        except BadRequest:
            return _error(
                400,
                "bad_request",
                "invalid_json",
                "Request body must contain valid JSON.",
            )

        if not isinstance(snapshot, dict):
            return _error(
                400,
                "bad_request",
                "object_required",
                "Snapshot payload must be a JSON object.",
            )

        try:
            supplied_hash = request_context.snapshot_sha256
            actual_hash = hashlib.sha256(raw_request).hexdigest()
            if supplied_hash is not None and supplied_hash != actual_hash:
                return _error(
                    400,
                    "bad_request",
                    "snapshot_hash_mismatch",
                    "Snapshot integrity validation failed.",
                )

            result = solve_snapshot(snapshot, request_context=request_context)
            with request_context.measure("serialization", reserve_response=False):
                response_body = app.json.dumps(result)
            request_context.checkpoint_total("serialization")
        except RequestBudgetExceeded as exception:
            app.logger.warning(
                "Scheduling solver request budget exhausted.",
                extra=_safe_log_context(request_context, exception.phase),
            )

            response, status_code = _error(
                503,
                "error",
                "solver_request_budget_exhausted",
                "The scheduling solver exhausted its bounded request budget.",
            )
            response.headers["X-TALA-Provider-Request-ID"] = request_context.request_id or ""
            response.headers["X-TALA-Solver-Phase-Timings"] = _phase_timings_header(request_context)

            return response, status_code
        except Exception:
            app.logger.exception("Scheduling solver request failed.")

            return _error(
                500,
                "error",
                "internal_error",
                "The scheduling solver could not process the request.",
            )

        response = app.response_class(response_body, mimetype="application/json")

        response.headers["X-TALA-Provider-Request-ID"] = request_context.request_id or ""
        response.headers["X-TALA-Solver-Phase-Timings"] = _phase_timings_header(request_context)
        app.logger.info(
            "Scheduling solver request completed.",
            extra=_safe_log_context(request_context, "completed"),
        )

        return response

    @app.errorhandler(404)
    def not_found(_: HTTPException) -> tuple[Response, int]:
        return _error(404, "error", "not_found", "The requested endpoint does not exist.")

    @app.errorhandler(405)
    def method_not_allowed(_: HTTPException) -> tuple[Response, int]:
        return _error(405, "error", "method_not_allowed", "The HTTP method is not allowed for this endpoint.")

    return app


def _solver_request_budget_seconds() -> int:
    try:
        configured = int(os.environ.get("SOLVER_REQUEST_BUDGET_SECONDS", "300"))
    except ValueError:
        configured = 300

    return max(1, min(configured, 300))


def _request_id() -> str:
    supplied = request.headers.get("X-TALA-Solver-Request-ID", "")

    return supplied if REQUEST_ID_PATTERN.fullmatch(supplied) else str(uuid4())


def _snapshot_hash_header() -> str | None:
    supplied = request.headers.get("X-TALA-Snapshot-SHA256", "").lower()

    return supplied if SNAPSHOT_HASH_PATTERN.fullmatch(supplied) else None


def _safe_log_context(context: SolverRequestContext, phase: str) -> dict[str, object]:
    return {
        "request_id": context.request_id,
        "snapshot_sha256": context.snapshot_sha256,
        "phase": phase,
        "phase_timings_ms": context.phase_timings_ms(),
        "metrics": context.metrics(),
        "elapsed_ms": round(context.elapsed_seconds() * 1000),
    }


def _phase_timings_header(context: SolverRequestContext) -> str:
    return json.dumps(
        context.phase_timings_ms(),
        separators=(",", ":"),
        sort_keys=True,
    )


def _error(status_code: int, status: str, code: str, message: str) -> tuple[Response, int]:
    return jsonify(status=status, code=code, message=message), status_code


app = create_app()


def main() -> None:
    port = int(os.environ.get("PORT", "8080"))
    app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False)


if __name__ == "__main__":
    main()
