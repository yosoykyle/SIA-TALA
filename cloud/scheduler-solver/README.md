# TALA Scheduler Solver

> **Implementation evidence — not product or architecture authority.** This README describes the current solver code and runtime contract for bounded reconciliation. PRD 03 and the TALA Architecture Specification govern desired behavior and deployment boundaries.

This directory contains TALA's private Python scheduling service. It uses Google OR-Tools Constraint Programming–Satisfiability (CP-SAT) to produce candidate schedules from Laravel's versioned `tala-timetable-v2` snapshot. It is deterministic constraint optimization, not machine learning, and it never publishes an official schedule. The older `tal94-demand-v2` interface remains readable only for historical replay and compatibility evidence.

Laravel remains authoritative: it captures the immutable input snapshot, queues dispatch, treats the response as untrusted integration output, revalidates every assignment, stores candidate rows, and requires Registrar review and publication before creating official `section_meetings`.

## Runtime Contract

- Runtime image: Python 3.12 slim, Flask, Gunicorn, and OR-Tools 9.15.6755.
- `GET /health`: returns service, contract, and solver-version metadata.
- `POST /solve`: accepts one `tala-timetable-v2` JavaScript Object Notation (JSON) snapshot and returns structured solver results.
- Input unit: `scheduling_demands`.
- Output unit: `assignments` keyed by the pair `scheduling_demand_id` and `meeting_sequence`.
- Container port: the Cloud Run `PORT` environment variable; local default `8080`.
- Solver budget: `SOLVER_TIMEOUT_SECONDS`, clamped to 1-300 seconds by the service.
- Current promoted Cloud Run request timeout: 360 seconds. The solver budget remains capped at 300 seconds so response serialization can complete within that provider limit.
- Current promoted solver budget: 300 seconds.
- Search configuration: fixed random seed `20260718`; the runtime allowlist is one, two, four, or eight CP-SAT workers. The source default is one worker. Provider statements later in this document describe the still-deployed historical service and must not be read as activation evidence for this source contract.
- Response evidence: one strict `solver_statistics` object containing allowlisted input/model/search counts, best bound, relative gap, deterministic time, wall time, worker count, seed, `result_source`, and separate feasibility/optimization stage telemetry. Raw solver logs are never part of the response contract.

The solver may return `optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`. A feasible result is a valid candidate, not proof of mathematical optimality and not an instruction to publish.

## Scalable CP-SAT Formulation

Each recurring Course Specification produces Scheduling Demands carrying one approved meeting pattern such as `1x180`, `2x90`, or `3x60`. A Course Specification explicitly classified as `ExternallyArranged` produces no recurring master-timetable demand; Laravel preserves that authority rather than inventing a meeting. The service expands each received recurring pattern into stable meeting-sequence requirements. Each feasible sequence/faculty/room/time combination has a Boolean selection variable and an optional fixed-size interval, and exactly one candidate is selected for every required sequence. All sequences of one demand keep the same Faculty when the snapshot requires it.

Hard overlap rules use CP-SAT `NoOverlap` groups by day for:

- shared logical cohort, mapped from course-specific delivery groups;
- faculty; and
- physical room.

Every assignment retains its course-specific `section_delivery_group_id` for reconciliation and also carries `cohort_or_student_group_id`. The solver rejects an inconsistent `student_cohort_groups` mapping. Different subjects attended by the same cohort therefore enter one cohort/day `NoOverlap` bucket even though their delivery-group source records differ.

This replaces candidate-pair conflict construction. Model growth is therefore bounded by candidate choices and resource/day groups instead of growing quadratically with every candidate pair.

The `lexicographic_v1` profile fixes this hierarchy without editable weights:

1. minimize cohort mode switches;
2. minimize cohort idle time;
3. minimize Faculty load imbalance;
4. minimize Faculty idle time;
5. minimize room-seat waste; and
6. preserve stable earlier placement.

Faculty idle time is calculated per faculty/day as the span between the first start and last end minus selected teaching duration. This counts only actual gaps between meetings and avoids double-counting non-adjacent meeting pairs.

### Feasibility-first lexicographic search

The service first finds one complete hard-valid timetable, then optimizes each hierarchy level in order:

1. **Feasibility stage:** build the candidate variables and every approved hard constraint, but do not add the soft objective. If this stage returns `UNKNOWN`, the response contains no assignments or conflict placeholders and makes no infeasibility claim.
2. **Lexicographic levels:** optimize the current level, fix its proved optimum before entering the next level, and divide only the remaining 300-second budget among unfinished levels. A merely `FEASIBLE` level stops lower-priority optimization, so a lower priority can never worsen that incumbent. If a level returns `UNKNOWN`, the last complete hard-valid timetable is retained as `FEASIBLE` with explicit incomplete-level evidence.

`objective_details` reports the ordered vector and completed levels, not a weighted total or accuracy score. Laravel independently checks the contract/profile identity, exact hierarchy, coverage, hard rules, and typed evidence before retaining a candidate. Temporary direct Python, stub, or loopback execution is verification only; operational runtime remains the private Cloud Run boundary and the new source is not active there until separately deployed and validated.

### Slice 3 local compatibility screen

On 19 August 2026, the current `tala-timetable-v2` source completed the minimal snapshot as `optimal` with 2/2 assignments, 6 candidates, 48 model variables, and 94 model constraints in about 85 ms. A deterministic 54-demand/120-slot scaled source screen using one worker and a 10-second solver budget returned `feasible` with 54/54 assignments, 6,480 candidates, 32,675 variables, and 65,241 constraints in 16.71 seconds process elapsed time. Windows process-tree sampling during that exact local screen observed a 123.85 MiB peak working set. This is native local-process evidence, not container or Cloud telemetry, and therefore cannot qualify Cloud capacity or promotion by itself.

The current model size, complete 54-demand result, local runtime, and local peak working set show no source-level trigger to shrink or enlarge the preserved private 8-vCPU/16-GiB candidate envelope. Only the separately authorized tagged Cloud activation can qualify container memory, Cloud runtime, and promotion for this exact source revision. Historical `tal94-demand-v2` measurements remain historical and are not silently reinterpreted.

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

The Python suite includes contract, hard-constraint, objective, HTTP, bounded model-growth, adjacent idle-gap, deterministic search-configuration, staged-search fallback, truthful `UNKNOWN`, and typed-statistics regressions.

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

A read-only provider check on **August 11, 2026** confirmed the following live state. Reconfirm it before every mutation because Cloud state can change independently of Git.

Operational terms: a **CP-SAT worker** is one solver search thread inside a request; **Cloud Run concurrency** is the number of HTTP requests one instance may process simultaneously. They are independent settings. **vCPU** is allocated virtual processor capacity, **GiB** is gibibytes of memory, **traffic** is the share of service requests routed to a revision, and a **zero-traffic revision** is retained without receiving normal requests. **IAM** means Identity and Access Management; the dedicated invoker may call the private service while no public invoker is allowed.

- Project: `tala-dev-ocr-3s`
- Region: `asia-southeast1`
- Service: `tala-scheduler-solver`
- Serving revision: `tala-scheduler-solver-d5dstage2-665963443cc0`
- Serving image digest: `sha256:229172013cd0e82a7d4d9c74e259618470a92b01465ba10f1fd4e8c5fa8b9b27`
- Traffic: 100% to the serving revision
- Runtime identity: `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com`
- Dedicated invoker: `tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com`
- Public invoker: none
- Resources: 8 vCPU, 16 GiB memory, concurrency 1, request timeout 360 seconds, solver budget 300 seconds, eight CP-SAT workers, deterministic seed `20260718`
- Scaling: minimum instances 0, maximum instances 2
- Artifact Registry repository: `tala-containers`

### Historical TAL-96B2 recovery evidence

The older revision `tala-scheduler-solver-e3a-4d17a03ccf1c`, the former Profile B revisions, and the prior 1 GiB TAL-96B2 candidate remain historical evidence, not the current baseline. This document does not designate a current rollback target without fresh provider and operational approval. The obsolete 1 GiB zero-traffic candidate was removed during TAL-96B3 cleanup:

- Build: `d55537e6-a64a-453f-b1bf-f3c451af1e93` (`SUCCESS`)
- Candidate revision: `tala-scheduler-solver-b2-38cad156ab94`
- Candidate image digest: `sha256:126b214c95b6e552088a4caeb766d140e17ed85e84c56713b0188fbb167de774`
- Candidate tag: `b2-38cad156ab94`
- Cleanup disposition: deleted after its evidence was retained
- Candidate resources: 1 CPU, 1 GiB memory, concurrency 1, request timeout 300 seconds, solver budget 30 seconds
- Tagged B1 acceptance: passed with the original serving revision unchanged at 100%

One GiB is not an accepted production baseline: the July 17 promotion gate showed memory termination at both 1,045 MiB and 1,154 MiB. The scheduling queue was recovered and later resumed after the TAL-96B3 promotion passed.

The July 17, 2026 promotion gate did not pass. Canonical B1 acceptance caused Cloud Run to terminate the 1 GiB candidate at 1,045 MiB; the restored 1 GiB revision later terminated at 1,154 MiB under the same acceptance. This is retained to explain why TAL-96B3 evaluated larger private profiles.

### Historical TAL-96B4 corrected selection and capacity evidence

TAL-96B4 reran the approved profile comparison after correcting shared-cohort conflict identity. All results used the same corrected immutable image:

- profile A: 1 CPU, 2 GiB, one worker;
- selected profile B: 2 CPU, 4 GiB, two workers; and
- research profile C: 4 CPU, 8 GiB, four workers.

Profile A accepted 4/10 client-representative runs; profiles B and C accepted 10/10 with full coverage, zero hard violations, and complete telemetry. **Telemetry** is the typed model, search, and runtime evidence collected for a run. **p95** is a 95th-percentile tail indicator. **Relative gap** measures the remaining distance between the best valid solution found and CP-SAT's bound; it is not accuracy. B had the stronger p95 gap and faster median/p95 runtime than C while using half its CPU and memory, so B was selected at that study boundary. At proportional 2× and 120 seconds, B accepted 2/3 before one 4-GiB OOM/503, while C accepted 3/3. All three proportional 4× attempts on C at 240 seconds exceeded 8 GiB and were terminated; this is an observed historical resource boundary rather than a supported maximum. Full methods and measurements are in the archived [`TAL-96B3-Cloud-Run-Capacity-Benchmark.md`](../../00_Project_Documents/archive/project-progress/TAL-96B3-Cloud-Run-Capacity-Benchmark.md).

The corrected 2-vCPU/4-GiB Profile B revision passed tagged acceptance and two authenticated post-promotion representative solves plus Laravel validation, ingestion, publication, and Registrar, Faculty, and Student projections. It received 100% canonical traffic at that historical promotion boundary. It is no longer the serving revision; the August 11, 2026 provider check found 100% of normal traffic on the 8-vCPU/16-GiB revision documented above.

### TAL-96D5D private operating-envelope candidates

At the time of the TAL-96D5D study, population-capacity candidates were staged from the unchanged corrected immutable image without changing normal service traffic. `TARGET-CFG-01` revision `tala-scheduler-solver-d5d-c1-3b46df2a7129` used 4 CPU, 8 GiB, four workers, concurrency 1, a 120-second solver limit, and a 300-second HTTP timeout. Its exact MIDDLE and MIN fixtures each produced three accepted feasible runs with complete coverage and zero solver or Laravel hard violations, while the MAX screen returned `unknown_timed_out` with stable memory and no OOM or infrastructure failure. `TARGET-CFG-01` was a verified MIDDLE scaling candidate but was not promoted at that study boundary.

That MAX evidence triggered the study's single permitted adjacent branch. The earlier time-only `infeasible` observation is retained as a superseded pre-correction fixture diagnostic and must not be attributed to the corrected MAX fixture. The corrected-MAX `TARGET-CFG-01-TIME` request ended `unknown_timed_out` without an incumbent; it therefore proves neither infeasibility nor a valid timetable. At that study boundary the adjacent revision remained private and at zero traffic, normal service traffic was unchanged, and `test_tala_db` was restored to the deterministic MIDDLE demonstration fixture with no schedule run, candidate row, official meeting, or queued job.

The replacement final configuration study preserves those reports as exploratory evidence and does not reinterpret them as final selection evidence. Retired candidate `FINAL-CFG-01` used 8 vCPU, 8 GiB, eight workers, concurrency one, a 300-second solver cap, and a 360-second experimental transport limit. Its private immutable revision remains under zero-traffic tag `d5d-final-cfg-01` with service- and revision-level maximum instances both set to two. Its one authorized corrected-MAX screen passed the health probe but returned HTTP 503 after approximately 200.20 seconds because Cloud Run terminated the instance at 8208 MiB against the 8192-MiB limit. The report is therefore an infrastructure-memory failure with no CP-SAT status, incumbent, objective, gap, assignment set, or timetable. This result shows that the exact 8-GiB/eight-worker MAX request exceeded memory; it does not change the mathematical formulation, prove the fixture infeasible, or establish an absolute ceiling.

`FINAL-CFG-02-MEM` retained 8 vCPU, eight workers, concurrency one, the 300-second solver cap, the 360-second experimental transport limit, seed `20260718`, zero minimum instances, and service- and revision-level maximum instances two, while changing memory only from 8 GiB to 16 GiB. Approved gates built immutable digest `sha256:86d4f2936480cb2685cddc0586ae43bd1c6d303e90de9fde2390b9767170b66a` and staged private zero-traffic revision `tala-scheduler-solver-d5dmem2-8036c05dd5f5`. Exactly one authenticated health probe returned HTTP 200, and one corrected-MAX request returned HTTP 200 with CP-SAT status `UNKNOWN`, `timeout=true`, no incumbent, no objective, and no relative gap after the unchanged solver limit. Its 178 conflict placeholder rows are not assignments or a timetable. One-minute p99-aligned monitoring peaked at approximately 92.98% CPU and 70.98% memory with no OOM event, so 16 GiB removed the prior memory termination but did not establish an accepted MAX configuration. Recalculation using the disclosed request-based rate class gives US$0.0378624112 for the probe and request before free tier and exclusions; the immutable report's embedded US$0.11208928 field is retained only as superseded evidence. At that evidence boundary the revision remained private at zero traffic, Profile B remained at 100%, and MIDDLE was restored with no official scheduling writes.

The equation-preserving staged-search image reused the same `FINAL-CFG-02-MEM` resource envelope and limits but changed search control: find a complete hard-feasible assignment first, then use it as a hint for the unchanged objective. Approved gates built digest `sha256:229172013cd0e82a7d4d9c74e259618470a92b01465ba10f1fd4e8c5fa8b9b27` and staged private zero-traffic revision `tala-scheduler-solver-d5dstage2-665963443cc0`. One authorized corrected-MAX request returned `FEASIBLE` with 178/178 assignments, zero unassigned demands, zero Python or Laravel hard-constraint violations, objective 1,115,910, best bound 0, relative gap 1.0, 307.819849 seconds reported runtime, and 314.471862 seconds client elapsed time. The result was accepted for that bounded study but does not prove optimality or repeatability. At the time of the report the revision remained private at zero traffic, Profile B remained at 100%, and `test_tala_db` was restored to MIDDLE with no official scheduling writes. The later provider check documented in **Current Cloud Baseline** found this staged-search revision promoted to 100% of normal service traffic.

The immutable accepted report predates the bounded Laravel evidence-runner correction that retains nested `result_source` and `search_stages` fields, so per-stage timing must not be inferred from that file. Future reports retain those validated typed fields. This persistence correction does not change the solver response, equations, fixtures, or the accepted assignment rows.

Cost is an explicitly labelled request-based proxy, not a Cloud invoice or monthly forecast. The corrected 27 July 2026 Singapore rate inputs are US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, US$0.40 per million requests, and 100-millisecond rounding of client elapsed time. The three MIN requests total US$0.0201717512, the three MIDDLE requests total US$0.0211756160, and the two MAX diagnostics total US$0.0210600184; all eight exploratory requests total US$0.0624073856 before free tier, discounts, networking, logging, registry, build, tax, and unrelated charges. Later probe-plus-request proxies are US$0.0203565448 for the 8-GiB infrastructure failure, US$0.0378624112 for the earlier 16-GiB `UNKNOWN` result, and US$0.03593148 for the accepted staged-search result. These values supersede only the cost fields embedded in earlier private D5D JSON reports that used the wrong rate class. Solver statuses, timings, model counts, hashes, and validation evidence remain authoritative.

### Private parity replay

`php artisan scheduling:capture-parity-evidence {MIN|MIDDLE|MAX}` is a local-only evidence command. It is restricted to `APP_ENV=testing`, MySQL `test_tala_db`, the exact loaded scenario manifest, and a database with no schedule run, candidate row, official meeting, or queued job. It creates a private ignored `tal96d5d-parity-v2` artifact from a structural allowlist and deterministic witness. The assignment allowlist retains the non-sensitive `assignment_status` required by Laravel validation. Before reporting success, the command validates the allowlisted payload, writes it, reads it back, verifies the payload hash, and independently validates the exact decoded stored snapshot and assignments. It never calls Cloud Run, invokes CP-SAT optimization, or publishes a schedule.

`python -m tala_solver.replay <private-artifact.json>` verifies the payload hash and replays every witness row through the solver's candidate enumeration without invoking CP-SAT optimization. A successful replay proves candidate admissibility for that exact artifact; it does not prove optimality.

Operational scaling must be triggered by demand/candidate/variable/constraint growth, repeated status and acceptance, duration, relative gap, memory/OOM, transport health, and queue pressure—not student count alone. Workload approaching the 80-demand MIDDLE scale should trigger review of the verified private 4-vCPU/8-GiB candidate. Workload approaching the disclosed 178-demand MAX model scale, or repeated non-acceptance on the smaller candidate, should trigger review of the private 8-vCPU/16-GiB staged-search configuration. Neither configuration is automatically promoted. A new rule, operating grid, cohort structure, room/faculty set, or qualification pattern requires fresh evidence even at a previously tested population.

## Explicit Cloud Gates

Cloud mutations are never implied by local implementation, cleanup, or verification.

- `Primary proceed TAL-96B3 remediation`: completed the guarded harness correction.
- `Deploy TAL-96B3 remediation`: completed the immutable private profile experiment.
- `Promote TAL-96B3`: moved selected profile B to canonical traffic and passed post-promotion acceptance.
- `Approve revised TAL-96 split adding TAL-96B4 ... Proceed, Deploy, Promote, Verify, and Cleanup TAL-96B4`: authorized the corrected shared-cohort implementation, replacement benchmark, controlled promotion, verification, and one bounded cleanup commit.

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

## Historical Promotion Record: Controlled 100% Traffic

This records the completed historical Profile B promotion pattern; it is not authorization to rerun it and is not the current Cloud baseline. The candidate at that boundary was Profile B revision `tala-scheduler-solver-b4f-ad9177e472f8`. Its exact digest, zero-traffic state, private IAM, resources, shared-cohort acceptance, and clean workload state were re-proved immediately before that promotion.

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
