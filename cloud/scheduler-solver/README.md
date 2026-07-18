# TALA Scheduler Solver

This directory contains TALA's private Python scheduling service. It uses Google OR-Tools CP-SAT to produce candidate schedules from Laravel's versioned `tal94-demand-v2` snapshot. It is deterministic constraint optimization, not machine learning, and it never publishes an official schedule.

Laravel remains authoritative: it captures the immutable input snapshot, queues dispatch, treats the response as untrusted integration output, revalidates every assignment, stores candidate rows, and requires Registrar review and publication before creating official `section_meetings`.

## Runtime Contract

- Runtime image: Python 3.12 slim, Flask, Gunicorn, and OR-Tools 9.15.6755.
- `GET /health`: returns service, contract, and solver-version metadata.
- `POST /solve`: accepts one `tal94-demand-v2` JSON snapshot and returns structured solver results.
- Input unit: `scheduling_demands`.
- Output unit: `assignments` keyed by `scheduling_demand_id`.
- Container port: the Cloud Run `PORT` environment variable; local default `8080`.
- Solver budget: `SOLVER_TIMEOUT_SECONDS`, clamped to 1-300 seconds by the service.
- HTTP request limit: 300 seconds in the approved Cloud Run and Laravel transport contract.
- Recommended B1 solver budget: 30 seconds, leaving response and network headroom inside the 300-second HTTP limit.
- Search configuration: one CP-SAT worker and fixed random seed `20260718`.
- Response evidence: one strict `solver_statistics` object containing allowlisted input/model/search counts, best bound, relative gap, deterministic time, wall time, worker count, and seed. Raw solver logs are never part of the response contract.

The solver may return `optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`. A feasible result is a valid candidate, not proof of mathematical optimality and not an instruction to publish.

## Scalable CP-SAT Formulation

Each feasible demand/faculty/room/time combination has a Boolean selection variable and an optional fixed-size interval. Exactly one candidate is selected for every ready demand.

Hard overlap rules use CP-SAT `NoOverlap` groups by day for:

- section delivery group;
- faculty; and
- physical room.

This replaces candidate-pair conflict construction. Model growth is therefore bounded by candidate choices and resource/day groups instead of growing quadratically with every candidate pair.

The `balanced_v1` profile remains unchanged:

- prefer earlier time blocks;
- reduce faculty idle gaps;
- balance faculty load; and
- use rooms efficiently.

Faculty idle time is calculated per faculty/day as the span between the first start and last end minus selected teaching duration. This counts only actual gaps between meetings and avoids double-counting non-adjacent meeting pairs.

## Local Python Tests

Docker is optional for local development. A temporary virtual environment can run the solver directly:

```powershell
$venv = Join-Path $env:TEMP 'tala-scheduler-solver-venv'
if (-not (Test-Path -LiteralPath "$venv\Scripts\python.exe")) {
    py -m venv $venv
}

& "$venv\Scripts\python.exe" -m pip install --upgrade pip
& "$venv\Scripts\python.exe" -m pip install -r 'cloud/scheduler-solver/requirements.txt'
$env:PYTHONPATH = (Resolve-Path 'cloud/scheduler-solver').Path
& "$venv\Scripts\python.exe" -m unittest discover -s 'cloud/scheduler-solver/tests' -v
```

The Python suite includes contract, hard-constraint, objective, HTTP, bounded model-growth, adjacent idle-gap, deterministic search-configuration, and typed-statistics regressions.

## Local HTTP Service Without Docker

From the repository root:

```powershell
$venv = Join-Path $env:TEMP 'tala-scheduler-solver-venv'
$env:PYTHONPATH = (Resolve-Path 'cloud/scheduler-solver').Path
$env:PORT = '8080'
$env:SOLVER_TIMEOUT_SECONDS = '30'
& "$venv\Scripts\python.exe" -m tala_solver.server
```

The direct Flask runner is for local development only. In another terminal:

```powershell
Invoke-RestMethod 'http://127.0.0.1:8080/health'
$body = Get-Content -LiteralPath 'cloud/scheduler-solver/samples/minimal_snapshot.json' -Raw
Invoke-RestMethod 'http://127.0.0.1:8080/solve' -Method Post -ContentType 'application/json' -Body $body
```

Expected sample evidence is an `optimal` or `feasible` status, two assignments, zero unassigned demands, and zero hard violations.

## Optional Local Docker Parity

The image uses the same non-root Gunicorn runtime used by Cloud Run:

```powershell
docker info
docker build -t tala-scheduler-solver:tal96b2-local .\cloud\scheduler-solver
docker run --rm --name tala-scheduler-solver-tal96b2 `
    -p 8080:8080 `
    -e PORT=8080 `
    -e SOLVER_TIMEOUT_SECONDS=30 `
    tala-scheduler-solver:tal96b2-local
```

Docker is not required for the Laravel loopback acceptance or for deployment because Cloud Build builds the image remotely.

## Guarded B1 Local Acceptance

The acceptance test is skipped during the normal suite. It uses only `testing`, MySQL `test_tala_db`, process-only settings, the accepted TAL-96B1 baseline, and exact loopback HTTP. It rolls its database changes back.

Start the local solver with `SOLVER_TIMEOUT_SECONDS=30`, then run in a second PowerShell terminal:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:APP_DEBUG = 'false'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'database'
$env:MAIL_MAILER = 'array'
$env:TALA_96B2_ACCEPTANCE_MODE = 'local_http'
$env:TALA_96B2_SOLVER_URL = 'http://127.0.0.1:8080'
$env:TALA_96B2_REPORT_EVIDENCE = '1' # optional: prints the bounded JSON evidence record

php artisan test --compact tests/Feature/TAL96B2RealLoopbackSchedulingDemoReadinessTest.php
```

Acceptance requires:

- environment `testing`, driver `mysql`, and database exactly `test_tala_db`;
- healthy `tal94-demand-v2` solver metadata;
- the exact same immutable B1 snapshot submitted three times with a 30-second budget, one worker, and fixed seed;
- all 54 accepted B1 demands represented exactly once in every response;
- zero unassigned demands and zero solver- or Laravel-detected hard violations in every response;
- strict typed statistics persisted under `diagnostics.solver_result.solver_statistics`;
- a processed dispatch operational event;
- Registrar publication into active official meetings; and
- a Faculty Schedule projection containing the assigned meetings.

The test proves dispatch intent and executes the real job handler inside the guarded transaction. Queue-worker retry, timeout, and terminal-failure behavior remain covered by the focused queue-operations tests.

For the interactive application path, run the canonical database worker without overriding the job's retry policy:

```powershell
php artisan queue:work database --queue=scheduling,default --timeout=360 --sleep=1 --no-interaction
```

Clear process-only variables when finished:

```powershell
Remove-Item Env:TALA_96B2_ACCEPTANCE_MODE, Env:TALA_96B2_SOLVER_URL, Env:TALA_96B2_REPORT_EVIDENCE -ErrorAction SilentlyContinue
Remove-Item Env:APP_ENV, Env:DB_CONNECTION, Env:DB_DATABASE, Env:APP_DEBUG -ErrorAction SilentlyContinue
Remove-Item Env:CACHE_STORE, Env:QUEUE_CONNECTION, Env:MAIL_MAILER -ErrorAction SilentlyContinue
```

## Experimental Runtime Content Identifier

Representative local experiments identify the exact executable solver content independently of documentation, samples, tests, and deployment descriptors. The canonical runtime manifest consists of the pinned requirements plus every Python package file loaded by the service:

```powershell
$runtimeFiles = @(
    'cloud/scheduler-solver/requirements.txt'
    'cloud/scheduler-solver/tala_solver/__init__.py'
    'cloud/scheduler-solver/tala_solver/server.py'
    'cloud/scheduler-solver/tala_solver/solver.py'
) | Sort-Object

$runtimeManifest = $runtimeFiles | ForEach-Object {
    "$(git hash-object -- $_) $_"
}
$sha = [Security.Cryptography.SHA256]::Create()
$runtimeDigestBytes = $sha.ComputeHash(
    [Text.Encoding]::UTF8.GetBytes($runtimeManifest -join "`n")
)
$runtimeContentId = ([BitConverter]::ToString($runtimeDigestBytes)).Replace('-', '').ToLowerInvariant()
$sha.Dispose()

$runtimeContentId
```

This identifier changes when a pinned dependency or executable Python source file changes. A Cloud Run release also records the broader build-context identifier and immutable Artifact Registry image digest because Docker, tests, and deployment inputs matter to the built artifact.

## Current Cloud Baseline

Deployment and post-promotion verification on July 18, 2026 confirmed this live state. Reconfirm it before every mutation because Cloud state can change independently of Git.

- Project: `tala-dev-ocr-3s`
- Region: `asia-southeast1`
- Service: `tala-scheduler-solver`
- Serving revision: `tala-scheduler-solver-b3b-e427393e2d06`
- Serving image digest: `sha256:beedcfb41067aa028b4c793ab6c9dc2283b7f4adb082124530b41afa4d1cebb0`
- Traffic: 100% to the serving revision
- Runtime identity: `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com`
- Dedicated invoker: `tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com`
- Public invoker: none
- Resources: 2 CPU, 4 GiB memory, concurrency 1, request timeout 300 seconds, solver budget 30 seconds, two CP-SAT workers
- Scaling: minimum instances 0, maximum instances 3
- Zero-traffic service template: `tala-scheduler-solver-b3base2-e427393e2d06`, matching the serving digest and exact profile-B configuration so future service updates do not inherit an experimental 240-second profile
- Artifact Registry repository: `tala-containers`

The previous serving revision `tala-scheduler-solver-e3a-4d17a03ccf1c` is retained at zero traffic as the immediate rollback target. The prior 1 GiB TAL-96B2 candidate is historical recovery evidence; its obsolete zero-traffic revision was removed during TAL-96B3 cleanup:

- Build: `d55537e6-a64a-453f-b1bf-f3c451af1e93` (`SUCCESS`)
- Candidate revision: `tala-scheduler-solver-b2-38cad156ab94`
- Candidate image digest: `sha256:126b214c95b6e552088a4caeb766d140e17ed85e84c56713b0188fbb167de774`
- Candidate tag: `b2-38cad156ab94`
- Cleanup disposition: deleted after its evidence was retained
- Candidate resources: 1 CPU, 1 GiB memory, concurrency 1, request timeout 300 seconds, solver budget 30 seconds
- Tagged B1 acceptance: passed with the original serving revision unchanged at 100%

One GiB is not an accepted production baseline: the July 17 promotion gate showed memory termination at both 1,045 MiB and 1,154 MiB. The scheduling queue was recovered and later resumed after the TAL-96B3 promotion passed.

The July 17, 2026 promotion gate did not pass. Canonical B1 acceptance caused Cloud Run to terminate the 1 GiB candidate at 1,045 MiB; the restored 1 GiB revision later terminated at 1,154 MiB under the same acceptance. This is retained to explain why TAL-96B3 evaluated larger private profiles.

TAL-96B3 compared three profiles from the same immutable image:

- profile A: 1 CPU, 2 GiB, one worker;
- selected profile B: 2 CPU, 4 GiB, two workers; and
- research profile C: 4 CPU, 8 GiB, four workers.

All profiles passed 10/10 client-representative runs with complete coverage, zero hard violations, and complete telemetry. Profile B had the smallest median and p95 relative gap and the fastest median and p95 runtime, so it was selected before cost. Proportional 2× was accepted 3/3 on B and 3/3 on C with a 120-second research limit. Proportional 4× produced two feasible observations on C at 240 seconds, but the third run used 8,197 MiB and was terminated; it is an observed resource boundary rather than a supported maximum. Full methods and measurements are in [`TAL-96B3-Cloud-Run-Capacity-Benchmark.md`](../../00_Project_Documents/TAL-96B3-Cloud-Run-Capacity-Benchmark.md).

Profile B passed two authenticated post-promotion representative solves plus Laravel validation, ingestion, publication, and Faculty projection. It now receives 100% canonical traffic. Private IAM is unchanged, the queue is resumed, and no benchmark scheduling record or queued job survived.

## Explicit Cloud Gates

Cloud mutations are never implied by local implementation, cleanup, or verification.

- `Primary proceed TAL-96B3 remediation`: completed the guarded harness correction.
- `Deploy TAL-96B3 remediation`: completed the immutable private profile experiment.
- `Promote TAL-96B3`: moved selected profile B to canonical traffic and passed post-promotion acceptance.

There is no unattended Cloud Build trigger. “Automatic deployment” means the agent can execute this documented workflow after the user gives the exact gate command.

## Immutable Build and Zero-Traffic Staging Reference

This section records the guarded build-and-stage pattern used by the completed TAL-96B3 deploy gate. It is not authorization to rerun the build, create another candidate, or change traffic.

### 1. Reconfirm target, traffic, image, and IAM

```powershell
$project = 'tala-dev-ocr-3s'
$region = 'asia-southeast1'
$service = 'tala-scheduler-solver'
$repository = 'tala-containers'
$runtimeIdentity = 'tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com'
$expectedInvoker = 'serviceAccount:tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com'

$account = (gcloud auth list --filter=status:ACTIVE --format='value(account)').Trim()
$configuredProject = (gcloud config get-value project 2>$null).Trim()
if (-not $account -or $configuredProject -ne $project) {
    throw "Wrong Google Cloud operator or project: account=$account project=$configuredProject"
}

$beforeState = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$beforeIam = gcloud run services get-iam-policy $service --region $region --project $project --format=json | ConvertFrom-Json
$beforeIamText = $beforeIam | ConvertTo-Json -Depth 10
if ($beforeIamText -match 'allUsers|allAuthenticatedUsers') { throw 'Public invoker detected.' }

$invokerMembers = @(
    ($beforeIam.bindings | Where-Object role -eq 'roles/run.invoker').members
)
if ($invokerMembers.Count -ne 1 -or $invokerMembers[0] -ne $expectedInvoker) {
    throw 'Dedicated invoker binding differs from the approved baseline.'
}

$servingTraffic = @($beforeState.status.traffic | Where-Object { [int]$_.percent -eq 100 })
if ($servingTraffic.Count -ne 1) { throw 'Expected one revision at 100 percent before staging.' }
$previousRevision = $servingTraffic[0].revisionName
```

Stop on any unexpected target, public binding, invoker drift, traffic split, active build, or unexplained revision. Do not create a service-account key or broaden IAM.

### 2. Derive a deployment build-context identifier

Cleanup and commit happen later, so do not identify the candidate from the current Git commit. Hash every tracked file in the solver build context instead. This deployment identifier is intentionally broader than the experimental runtime content ID above; the immutable image digest remains the final built-artifact identity.

```powershell
$manifest = git ls-files 'cloud/scheduler-solver' |
    Sort-Object |
    ForEach-Object { "$(git hash-object -- $_) $_" }
$sha = [Security.Cryptography.SHA256]::Create()
$digestBytes = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($manifest -join "`n"))
$sourceId = ([BitConverter]::ToString($digestBytes)).Replace('-', '').ToLowerInvariant().Substring(0, 12)
$tag = "b2-$sourceId"
$image = "$region-docker.pkg.dev/$project/$repository/$service`:tal96b2-$sourceId"
```

### 3. Build remotely and deploy privately at zero traffic

```powershell
gcloud builds submit `
    --config cloud/scheduler-solver/cloudbuild.yaml `
    --substitutions "_IMAGE=$image" `
    --project $project `
    cloud/scheduler-solver

gcloud run deploy $service `
    --image $image `
    --region $region `
    --project $project `
    --revision-suffix $tag `
    --tag $tag `
    --no-traffic `
    --no-allow-unauthenticated `
    --service-account $runtimeIdentity `
    --cpu 1 `
    --memory 2Gi `
    --concurrency 1 `
    --timeout 300 `
    --set-env-vars SOLVER_TIMEOUT_SECONDS=30
```

Cloud Build runs the complete Python solver/server suite before pushing the image. The Dockerfile retains its non-root user and Gunicorn process boundary.

### 4. Prove zero traffic, privacy, and tagged B1 acceptance

```powershell
$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$candidate = $state.status.traffic | Where-Object tag -eq $tag
if (-not $candidate.url -or [int]$candidate.percent -ne 0) { throw 'Candidate is not a zero-traffic tag.' }

$currentServing = @($state.status.traffic | Where-Object { [int]$_.percent -eq 100 })
if ($currentServing.Count -ne 1 -or $currentServing[0].revisionName -ne $previousRevision) {
    throw 'Serving traffic changed during staging.'
}

$anonymousHealth = Invoke-WebRequest -Uri "$($candidate.url)/health" -SkipHttpErrorCheck
if ($anonymousHealth.StatusCode -ne 403) { throw 'Tagged revision is publicly invokable.' }

$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:APP_DEBUG = 'false'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'database'
$env:MAIL_MAILER = 'array'
$env:TALA_96B2_ACCEPTANCE_MODE = 'cloud_run'
$env:TALA_96B2_SOLVER_URL = $candidate.url
$env:TALA_96B2_SOLVER_AUDIENCE = $state.status.url
$env:TALA_96B2_SOLVER_CREDENTIALS = 'C:\path\outside\git\scheduler-invoker.json'

php artisan test --compact tests/Feature/TAL96B2RealLoopbackSchedulingDemoReadinessTest.php
php artisan test --compact tests/Feature/TAL94E2aSolverQueueOperationsTest.php
```

Tagged acceptance must pass with the old revision still at 100%. Inspect candidate readiness, image digest, IAM, and recent logs. Stop before promotion on any timeout, memory termination, hard violation, missing assignment, public access, identity drift, or secret-like log content.

## Promotion Record: Controlled 100% Traffic

This records the completed TAL-96B3 promotion pattern; it is not authorization to rerun it. The selected candidate was profile B revision `tala-scheduler-solver-b3b-e427393e2d06`. Its exact digest, zero-traffic state, private IAM, resources, and clean workload state were re-proved immediately before promotion.

```powershell
php artisan queue:pause database:scheduling --no-interaction

$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
php artisan tinker --execute 'dump([
    "active_runs" => App\Models\ScheduleGenerationRun::query()->whereIn("status", ["queued", "dispatching"])->count(),
    "queued_jobs" => DB::table("jobs")->where("queue", "scheduling")->count(),
    "failed_jobs" => DB::table("failed_jobs")->where("payload", "like", "%ScheduleSolverDispatchJob%")->count(),
]);'
```

All three counts must be zero before traffic changes. Then:

```powershell
gcloud run services update-traffic $service `
    --to-revisions "$($candidate.revisionName)=100" `
    --region $region `
    --project $project

$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
if ([int](($state.status.traffic | Where-Object revisionName -eq $candidate.revisionName).percent) -ne 100) {
    throw 'Candidate did not reach 100 percent traffic.'
}

$env:TALA_96B2_SOLVER_URL = $state.status.url
$env:TALA_96B2_SOLVER_AUDIENCE = $state.status.url
$env:TALA_96B2_ACCEPTANCE_MODE = 'cloud_run'
$env:TALA_96B2_SOLVER_CREDENTIALS = '<existing dedicated scheduler-invoker credential path>'
$env:TALA_96B2_ACCEPTANCE_REPETITIONS = '2'
$env:TALA_96B2_EXPECTED_WORKER_COUNT = '2'
php artisan test --compact tests/Feature/TAL96B2RealLoopbackSchedulingDemoReadinessTest.php
```

After canonical acceptance, confirm private IAM, candidate image digest, clean logs, clean test records/queues, and expected Registrar/Faculty rendered surfaces. Resume scheduling only after every check passes:

```powershell
php artisan queue:resume database:scheduling --no-interaction
```

## Recovery

If build or tagged acceptance fails, leave `$previousRevision` at 100% and do not promote. Delete only the rejected zero-traffic tag/revision after its evidence has been retained and cleanup is explicitly authorized.

If promotion or canonical acceptance fails:

1. keep `database:scheduling` paused;
2. route 100% traffic back to `$previousRevision`;
3. prove authenticated canonical health and the `tal94-demand-v2` contract;
4. inspect Cloud Run logs without exposing credentials;
5. rerun the guarded acceptance against the restored canonical service; and
6. resume scheduling only after recovery passes.

```powershell
gcloud run services update-traffic $service `
    --to-revisions "$previousRevision=100" `
    --region $region `
    --project $project
```

Already published schedules remain authoritative during solver downtime. The Registrar retains the validated manual-scheduling continuity path documented in PRD Module 06.

## Current V2 Limitations

- Each generated Scheduling Demand represents one contiguous meeting block.
- Laravel must decompose valid course components before optimization.
- The solver does not repair incomplete curricula, rooms, qualifications, loads, or calendar inputs.
- It does not perform exam timetabling, event management, or individual student sectioning.
- `feasible` is valid but not necessarily optimal.
- `unknown` means the configured limit ended without a feasible or proven-infeasible result.
- The solver cannot publish, bypass Laravel revalidation, or override Registrar authority.

## Primary References

- [Google OR-Tools CP-SAT](https://developers.google.com/optimization/cp/cp_solver)
- [OR-Tools Python `CpModel` scheduling constraints](https://or-tools.github.io/docs/python/classortools_1_1sat_1_1python_1_1cp__model_1_1CpModel.html)
- [Cloud Run deployment and traffic](https://docs.cloud.google.com/run/docs/deploying)
- [Cloud Run memory configuration](https://docs.cloud.google.com/run/docs/configuring/services/memory-limits)
- [Cloud Build container builds](https://docs.cloud.google.com/build/docs/building/build-containers)
