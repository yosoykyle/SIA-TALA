from __future__ import annotations

import os

from flask import Flask, Response, jsonify, request
from werkzeug.exceptions import BadRequest, HTTPException

from tala_solver.solver import CONTRACT_VERSION, SOLVER_VERSION, solve_snapshot


def create_app() -> Flask:
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
        if not request.is_json:
            return _error(
                400,
                "bad_request",
                "json_required",
                "Request body must be a JSON object.",
            )

        try:
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
            result = solve_snapshot(snapshot, timeout_seconds=_solver_timeout_seconds())
        except Exception:
            app.logger.exception("Scheduling solver request failed.")

            return _error(
                500,
                "error",
                "internal_error",
                "The scheduling solver could not process the request.",
            )

        return jsonify(result)

    @app.errorhandler(404)
    def not_found(_: HTTPException) -> tuple[Response, int]:
        return _error(404, "error", "not_found", "The requested endpoint does not exist.")

    @app.errorhandler(405)
    def method_not_allowed(_: HTTPException) -> tuple[Response, int]:
        return _error(405, "error", "method_not_allowed", "The HTTP method is not allowed for this endpoint.")

    return app


def _solver_timeout_seconds() -> int:
    try:
        configured = int(os.environ.get("SOLVER_TIMEOUT_SECONDS", "300"))
    except ValueError:
        configured = 300

    return max(1, min(configured, 300))


def _error(status_code: int, status: str, code: str, message: str) -> tuple[Response, int]:
    return jsonify(status=status, code=code, message=message), status_code


app = create_app()


def main() -> None:
    port = int(os.environ.get("PORT", "8080"))
    app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False)


if __name__ == "__main__":
    main()
