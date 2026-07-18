# TAL-96B2 Scheduling Demo Runbook

This runbook demonstrates the scheduling integration with the guarded TAL-96B1 acceptance baseline. It uses only `test_tala_db`; it must never target `tala_db` or `tala_test_codex`.

## Demo outcome

The audience should see one complete, explainable path:

1. The Registrar confirms that all 54 scheduling demands are ready.
2. Laravel captures an immutable demand snapshot and queues a solver run.
3. The Constraint Programming–Satisfiability (CP-SAT) service returns a complete candidate schedule.
4. Laravel records and revalidates every candidate row before publication.
5. The Registrar publishes the accepted schedule.
6. The Faculty user sees only their official assigned meetings.

The solver proposes assignments; Laravel remains the authority that validates, records, publishes, and exposes them to users.

For the presentation, a **Scheduling Demand** is one course component that must be assigned; a **snapshot** is the unchanged copy of all inputs sent for one run; a **candidate schedule** is the solver's proposal; and an **official schedule** is the version Laravel publishes only after validation and Registrar approval. `feasible` means the proposal satisfies every hard rule but the 30-second search did not prove it was the best-ranked possible result. `optimal` means CP-SAT also proved that no better objective value exists for that tested input.

The corrected 54-demand Cloud acceptance is recorded in [`TAL-96B2-Representative-Solver-Evidence.md`](TAL-96B2-Representative-Solver-Evidence.md). The replacement Cloud Run profile comparison, proportional-growth boundary, resource use, and cost evidence are recorded in [`TAL-96B3-Cloud-Run-Capacity-Benchmark.md`](TAL-96B3-Cloud-Run-Capacity-Benchmark.md). Do not describe the synthetic scheduling resources as the client's actual personnel or facilities, a universal maximum, or an accuracy benchmark.

## Test accounts

All accounts use the password `password` and are test-only.

| Role | Email | Demo purpose |
| --- | --- | --- |
| Registrar | `registrar.demo@example.test` | Dispatch, review, and publish the schedule |
| Faculty | `faculty.demo@example.test` | View the published assigned schedule |
| Academic Head | `academic-head.demo@example.test` | Read-only review of scheduling evidence |
| System Super Admin | `system-admin.demo@example.test` | Optional integration-status and operational-event inspection |

The seeded term is **AY 2025-2026 / Second Semester**. The baseline contains 54 ready scheduling demands and 47 students in six logical program-year cohorts. Although each course has its own traceable delivery-group record, all courses attended by one cohort share a conflict identity, so the solver and Laravel prevent cross-course timetable overlap for those students.

## One-time guarded setup

Open PowerShell in the repository root. Keep the same environment values in every terminal that runs Artisan.

```powershell
$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:CACHE_STORE = 'database'
$env:QUEUE_CONNECTION = 'database'
$env:DB_QUEUE_RETRY_AFTER = '420'
$env:MAIL_MAILER = 'array'
$env:TALA_SCHEDULING_SOLVER_DRIVER = 'cloud_run'
$env:TALA_SCHEDULING_SOLVER_URL = 'https://tala-scheduler-solver-5ylv3rnyfq-as.a.run.app'
$env:TALA_SCHEDULING_SOLVER_AUDIENCE = $env:TALA_SCHEDULING_SOLVER_URL
$env:TALA_SCHEDULING_SOLVER_CREDENTIALS = (Resolve-Path -LiteralPath '.\storage\app\private\credentials\tala-dev-ocr-3s-aba571363fdf.json').Path
$env:TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS = '300'
$env:TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS = '10'

php artisan config:clear
php artisan acceptance:seed-client-baseline --no-interaction
```

The credential path must resolve to the existing dedicated scheduler-invoker key. Do not replace it with the OCR credential, print its contents, or copy it into documentation or source control.

The environment block has four purposes: it proves the isolated testing database; configures database-backed cache, queue, and safe array mail; selects the private Cloud Run scheduling driver and service URL; and supplies the dedicated identity and request timeouts. The 300-second request timeout is the Laravel-to-Cloud transport limit, while the production CP-SAT search budget is 30 seconds. Cloud Run **concurrency one** means an instance handles one solver request at a time; the current production request itself uses two CP-SAT workers on profile B (2 virtual CPUs, abbreviated vCPUs, and 4 gibibytes, abbreviated GiB).

Expected result:

```text
TAL-96B1 client acceptance baseline ready.
term=AY 2025-2026 / Second Semester
students=47
scheduling_demands=54
readiness=PASS
```

If the command reports `already_present`, continue. If it reports partial or conflicting operational data, stop. A reset is destructive and is allowed only after confirming the target is exactly `test_tala_db`.

## Start the demo services

Use three PowerShell terminals. The private CP-SAT service already runs on Cloud Run; do not start Docker or a local Python solver for this demo.

### Terminal 1 — Laravel application

Load the guarded environment block above, then run:

```powershell
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

### Terminal 2 — scheduling queue worker

Load the guarded environment block above, then run:

```powershell
php artisan queue:work database --queue=scheduling,default --timeout=360 --sleep=1 --no-interaction
```

Keep this terminal visible during dispatch. It should show `ScheduleSolverDispatchJob` running and then completing.

### Terminal 3 — frontend assets

```powershell
npm run dev
```

Open `http://127.0.0.1:8001/admin/login` only after all three terminals are ready.

## Presenter walkthrough

### 1. Establish readiness as Registrar

Sign in with `registrar.demo@example.test` / `password`.

1. Open **Registrar > Scheduling Demands**.
2. Filter or inspect the seeded term **AY 2025-2026 / Second Semester**.
3. Point out that the 54 demand rows are `READY_FOR_REVIEW`.

Say: "These rows are Laravel's validated scheduling inputs: term offering, section delivery, course component, faculty eligibility, room needs, duration, and meeting count. The solver does not invent academic data."

Expected evidence: the term is present and its demands do not require correction.

### 2. Dispatch the solver run

1. Open **Registrar > Solver Runs**.
2. Click **Dispatch Solver Run**.
3. Select **AY 2025-2026 / Second Semester**.
4. Click **Dispatch** once.

Expected evidence:

- A success notification identifies the queued run.
- The newest table row starts as `QUEUED` or `PROCESSING`.
- The list refreshes automatically every five seconds; do not repeatedly dispatch or refresh.
- The queue terminal shows the scheduling job.
- Within about one minute, the run becomes `UNDER_REVIEW` and shows candidate rows, solver version, and runtime.

If the row remains `PROCESSING` beyond 90 seconds, use the recovery section rather than dispatching another run.

### 3. Explain and review the candidate

Open the newest solver run.

1. Review its status, contract/model version, solver version, runtime, validation diagnostics, and candidate-row count. Use the linked TAL-96B2 and TAL-96B3 evidence—not an invented UI accuracy score—for typed model/search statistics, optimality gap, tested capacity boundary, and resource interpretation.
2. Open **Candidate Rows** and show the course, section, component, faculty, day/time, room, modality, and row status.
3. Confirm there are no hard conflicts or violations. Advisory warnings, if any, must be explained before publication.

Say: "CP-SAT enforces non-overlap and assignment feasibility while optimizing the approved soft preferences. The status is feasible when the candidate is valid but the 30-second run has not proved optimality; the best bound and relative gap report that remaining optimization evidence. Laravel then performs its own full validation. A solver response cannot become an official schedule by itself."

Do not use **Manual Schedule Override** during the normal demo. It is a controlled recovery path, not the primary workflow.

### 4. Publish as Registrar

1. Click **Publish Schedule**.
2. Read the confirmation summary aloud: assignment count, warnings, conflicts, changed rows, and affected faculty.
3. If there are zero warnings, leave the lower-quality toggle off and publish.
4. If advisory warnings exist, explain them, provide a publication note, and accept lower soft quality only when the hard-conflict count remains zero.

Expected evidence:

- A `Schedule published` notification appears.
- The run status becomes `PUBLISHED`.
- Official section meetings are created for the selected term.

### 5. Show the affected Faculty view

Sign out, then sign in with `faculty.demo@example.test` / `password`.

1. Open **Faculty > Assigned Schedule**.
2. Show the meetings grouped by day.
3. Point out the term, course, description, section, component, time, room, and modality.

Say: "Faculty do not see unapproved solver candidates here. This screen reads only active official meetings produced after Registrar publication."

### 6. Optional audit view

For a technical audience, sign in as `system-admin.demo@example.test` / `password` and open **Integration Status** or **Operational Events**. Show the successful solver-dispatch event and recorded contract/solver versions. Do not expose credentials or environment secrets.

## Recovery

| Symptom | Check | Action |
| --- | --- | --- |
| Run stays `QUEUED` | Queue terminal has no scheduling job | Confirm the worker uses `test_tala_db` and `--queue=scheduling,default`, then restart that worker. |
| Run stays `PROCESSING` beyond 90 seconds | Run diagnostics, Operational Events, queue output, and private Cloud Run revision logs | Confirm the dedicated credential and canonical solver URL, correct the recorded dependency, then use **Retry Solver Run** on the existing run. Do not create another run. |
| Run becomes `FAILED` or `BLOCKED` | Run diagnostics and Operational Events | Explain the recorded failure, correct the demonstrated dependency, then use **Retry Solver Run**. |
| Dispatch is blocked | Some demands are not ready | Return to **Scheduling Demands** and correct the identified rows; the system is intentionally preventing an incomplete snapshot. |
| Publish is blocked | Candidate warnings/conflicts or active-binding protection | Follow the message shown by Laravel. Do not bypass hard constraints or replace an active official schedule during the demo. |
| Faculty schedule is empty | Run was not published or the selected faculty has no official rows | Confirm `PUBLISHED` status and use the seeded faculty account. |

## Shutdown and cleanup

Stop the app, queue, and asset terminals with `Ctrl+C`. The Cloud Run service remains deployed. Then clear only the current PowerShell process variables if they were set there:

```powershell
Remove-Item Env:APP_ENV, Env:APP_DEBUG, Env:DB_CONNECTION, Env:DB_DATABASE, Env:CACHE_STORE, Env:QUEUE_CONNECTION, Env:DB_QUEUE_RETRY_AFTER, Env:MAIL_MAILER, Env:TALA_SCHEDULING_SOLVER_DRIVER, Env:TALA_SCHEDULING_SOLVER_URL, Env:TALA_SCHEDULING_SOLVER_AUDIENCE, Env:TALA_SCHEDULING_SOLVER_CREDENTIALS, Env:TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS, Env:TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS -ErrorAction SilentlyContinue
```

The seeded records are synthetic and restricted to `test_tala_db`. Do not copy them into a production database.
