from __future__ import annotations

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

from tala_solver.solver import solve_snapshot


class SolverRequestHandler(BaseHTTPRequestHandler):
    server_version = "TALASchedulerSolver/0.1"

    def do_GET(self) -> None:
        if self.path not in {"/", "/health"}:
            self._json_response(404, {"status": "not_found"})
            return

        self._json_response(200, {"status": "ok", "service": "tala-scheduler-solver"})

    def do_POST(self) -> None:
        if self.path != "/solve":
            self._json_response(404, {"status": "not_found"})
            return

        try:
            snapshot = self._json_body()
        except ValueError as exception:
            self._json_response(400, {"status": "bad_request", "message": str(exception)})
            return

        if not isinstance(snapshot, dict):
            self._json_response(400, {"status": "bad_request", "message": "Snapshot payload must be a JSON object."})
            return

        timeout = int(os.environ.get("SOLVER_TIMEOUT_SECONDS", "300"))
        result = solve_snapshot(snapshot, timeout_seconds=timeout)
        self._json_response(200, result)

    def log_message(self, format: str, *args: Any) -> None:
        return

    def _json_body(self) -> Any:
        content_length = int(self.headers.get("Content-Length") or 0)

        if content_length <= 0:
            raise ValueError("Request body is required.")

        try:
            return json.loads(self.rfile.read(content_length).decode("utf-8"))
        except json.JSONDecodeError as exception:
            raise ValueError("Request body must be valid JSON.") from exception

    def _json_response(self, status: int, payload: dict[str, Any]) -> None:
        encoded = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


def main() -> None:
    port = int(os.environ.get("PORT", "8080"))
    server = ThreadingHTTPServer(("0.0.0.0", port), SolverRequestHandler)
    print(f"TALA scheduler solver listening on 0.0.0.0:{port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
