# TALA scheduler solver

This directory contains TALA's private Python scheduling service. It uses Google OR-Tools CP-SAT to generate candidate whole-Term timetables from Laravel's immutable `tala-timetable-v2` snapshot. It is deterministic constraint optimization, not machine learning, and it never publishes an official timetable. Laravel independently validates every returned assignment; the Registrar reviews and publishes the authoritative version.

## Current contract

- `GET /health`: unauthenticated container health probe.
- `POST /solve`: accepts one `tala-timetable-v2` JSON snapshot and returns a typed solver outcome.
- Contract: `tala-timetable-v2`.
- Objective profile: `lexicographic_v1`.
- Source solver identity: `cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2`.
- Deterministic seed: `20260718`.
- CP-SAT workers: eight in the deployed resource profile; one in deterministic local tests unless a test states otherwise.

The current journey uses one **Canonical TALA Scheduling Dataset**: 47 Students, six exact-Term cohorts, nine Faculty, ten rooms, and 54 scheduling demands. There are no active MIN, MIDDLE, or MAX selectors. Their completed research results remain in the technical formulation and repository history; the executable generators, benchmark commands, replay helper, and operating-envelope machinery were retired because they are not part of the product journey.

## Deadline chain

One monotonic request deadline starts at the first line of the Flask handler. Parsing, normalization, candidate generation, model construction, CP-SAT feasibility and lexicographic optimization, result construction, and serialization all consume the same 300-second request budget. The service reserves the final 15 seconds for a typed response and returns HTTP 503 with `solver_request_budget_exhausted` before the provider ceiling when the remaining safe budget is exhausted.

The coordinated limits are:

| Boundary | Limit |
| --- | ---: |
| Python request budget | 300 seconds |
| Python response reserve | 15 seconds |
| Laravel HTTP client | 330 seconds |
| Gunicorn worker | 330 seconds |
| Cloud Run request | 360 seconds |
| Laravel queue job | 360 seconds |
| Database queue `retry_after` | 420 seconds |

The ordering prevents a fresh CP-SAT budget after preprocessing and prevents Laravel or Gunicorn from abandoning an otherwise reportable Python result. The queue job remains strictly below `retry_after`; queue attempts, not nested HTTP retries, own retry behavior.

## Correlation and safe telemetry

Laravel sends one request identifier and the SHA-256 of the exact JSON body. The service validates the digest and returns the provider request identifier when available. Both sides record only allowlisted timing and model counters:

- Laravel snapshot, authentication, transport, decode, validation, persistence, and total attempt time;
- Python parsing, normalization, candidate generation, model construction, feasibility search, each completed objective level, result construction, and serialization;
- candidate, variable, and constraint counts.

Logs and operational evidence must not contain credentials, identity tokens, private service URLs, raw snapshots, or arbitrary response bodies. A malformed request, transport failure, provider timeout, exhausted service budget, invalid contract, and stale/duplicate queue attempt remain distinct outcomes.

## Search behavior

The solver first seeks a complete hard-valid timetable. It then optimizes the fixed lexicographic hierarchy without editable weights:

1. cohort modality switches;
2. cohort idle time;
3. Faculty load imbalance;
4. Faculty idle time;
5. room-seat waste;
6. stable earlier placement.

Each completed level is fixed before the next begins. A lower-priority level cannot worsen a completed higher-priority optimum. If the shared deadline expires after a complete timetable exists, the best hard-valid incumbent is returned as `feasible` with explicit incomplete-level evidence. `optimal` is returned only when every required level is proved.

Candidate construction and constraints use grouped indexes by demand sequence, cohort/day, Faculty/day, room/day, placement, and offering group. Deterministic differential tests compare the indexed implementation with a small reference evaluator so performance changes cannot silently alter hard constraints or objective values.

## Local verification

From this directory, with the pinned dependencies installed in an isolated environment:

```powershell
$env:PYTHONPATH='.'
python -m unittest discover -s tests -v
```

Laravel focused tests fake HTTP and verify the same contract without calling Cloud Run. Local or fake execution is verification evidence only; it does not prove the source is deployed or receiving traffic.

From the Laravel repository root (not this Python directory), the application worker keeps solver attempts on the dedicated scheduling queue while continuing to process ordinary work. `composer dev` already supplies this worker for normal local development; use the standalone command only when a separate worker is needed:

```powershell
php artisan queue:work database --queue=scheduling,default --timeout=360 --sleep=1 --no-interaction
```

## Container and deployment boundary

`Dockerfile` runs one non-root Gunicorn process with one worker, two threads, and a 330-second worker timeout. `cloudbuild.yaml` runs the Python suite before building the image.

Building, deploying, changing traffic, IAM, resources, or Cloud Run settings requires separate authorization. A source commit, local test, or configured solver identity is not active-deployment evidence. The System integration view must distinguish:

- expected source identity;
- last identity observed in an application result;
- separately verified active Cloud revision and traffic evidence.

The accepted resource envelope remains 8 vCPU, 16 GiB, concurrency one, maximum two instances, and a 360-second Cloud Run request limit. This is dated configuration evidence, not a guarantee of optimality, capacity, or unchanged provider state. Promotion requires an immutable image digest, a zero-traffic candidate, health and canonical-journey evidence, explicit traffic authorization, and a retained rollback revision.

## Historical evidence

Completed MIN/MIDDLE/MAX experiments and earlier `tal94-demand-v2`/`balanced_v1` results are research history. Their recorded inputs, statuses, timings, resource observations, limitations, and immutable revision/digest references remain in `00_Project_Documents/research paper/TALA_CP-SAT_Technical_Formulation.md`, archived evidence, Git history, and Issue history. They are not active product datasets, commands, seeders, runtime configuration, or promotion gates.

The compact `minimal_snapshot.json` remains a contract smoke fixture. It does not replace the Canonical TALA Scheduling Dataset or constitute capacity evidence.

## References

- [OR-Tools CP-SAT solver](https://developers.google.com/optimization/cp/cp_solver)
- [Cloud Run request timeout](https://cloud.google.com/run/docs/configuring/request-timeout)
- [Cloud Run container contract](https://cloud.google.com/run/docs/container-contract)
- [Gunicorn settings](https://docs.gunicorn.org/en/stable/settings.html)
