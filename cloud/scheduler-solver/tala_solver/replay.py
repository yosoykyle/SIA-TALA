from __future__ import annotations

import argparse
import hashlib
import hmac
import json
from pathlib import Path
from typing import Any

from tala_solver.solver import evaluate_candidate_membership


def _normalized_hash(value: Any) -> str:
    encoded = json.dumps(
        value,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")

    return hashlib.sha256(encoded).hexdigest()


def replay_artifact(artifact: dict[str, Any]) -> dict[str, Any]:
    payload = {
        "snapshot": artifact.get("snapshot", {}),
        "assignments": artifact.get("assignments", []),
    }
    expected_hash = str(artifact.get("payload_sha256") or "")
    actual_hash = _normalized_hash(payload)

    if not expected_hash or not hmac.compare_digest(expected_hash, actual_hash):
        raise ValueError("The parity artifact payload hash does not match its contents.")

    replay = evaluate_candidate_membership(
        payload["snapshot"],
        payload["assignments"],
    )

    return {
        "evidence_version": artifact.get("evidence_version"),
        "scenario": artifact.get("scenario"),
        "payload_sha256": actual_hash,
        **replay,
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Replay private TAL-96D5D witness rows through candidate generation without invoking CP-SAT.",
    )
    parser.add_argument("artifact", type=Path)
    arguments = parser.parse_args()
    artifact = json.loads(arguments.artifact.read_text(encoding="utf-8"))
    result = replay_artifact(artifact)
    print(json.dumps(result, indent=2, sort_keys=True))

    return 0 if result["all_admissible"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
