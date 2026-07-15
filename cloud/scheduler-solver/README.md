# TALA Scheduler Solver

This folder contains the V2 Cloud Run solver container for automatic scheduling.

It is a deterministic Google OR-Tools CP-SAT service. It is not ML and does not train a model.

## Runtime Contract

- `GET /health`: health probe.
- `POST /solve`: accepts the Laravel `tal94-demand-v2` solver snapshot JSON and returns solver result JSON.
- Solver input uses `scheduling_demands` as the schedulable unit.
- Solver output uses `assignments` keyed by `scheduling_demand_id` for TAL-62 candidate ingestion.
- The container listens on the `PORT` environment variable, as required by Cloud Run.
- The container runs Flask behind Gunicorn as a fixed non-root user.
- Default local port is `8080`.
- Default solver timeout is controlled by `SOLVER_TIMEOUT_SECONDS`, capped in code at 300 seconds.

## Local Python Test

From the repo root:

```powershell
$venv = Join-Path $env:TEMP 'tala-scheduler-solver-venv'
if (-not (Test-Path $venv)) { py -m venv $venv }
& "$venv\Scripts\python.exe" -m pip install --upgrade pip
& "$venv\Scripts\python.exe" -m pip install -r 'cloud/scheduler-solver/requirements.txt'
$env:PYTHONPATH = (Resolve-Path 'cloud/scheduler-solver').Path
& "$venv\Scripts\python.exe" -m unittest discover -s 'cloud/scheduler-solver/tests' -v
```

## Local HTTP Test Without Docker

```powershell
$venv = Join-Path $env:TEMP 'tala-scheduler-solver-venv'
$solverRoot = (Resolve-Path 'cloud/scheduler-solver').Path
$env:PYTHONPATH = $solverRoot
$env:PORT = '8787'
& "$venv\Scripts\python.exe" -m tala_solver.server
```

The direct Flask runner is for local development only. The Docker image uses Gunicorn.

In a second terminal:

```powershell
Invoke-RestMethod -Uri 'http://127.0.0.1:8787/health'
$body = Get-Content -LiteralPath 'cloud/scheduler-solver/samples/minimal_snapshot.json' -Raw
Invoke-RestMethod -Uri 'http://127.0.0.1:8787/solve' -Method Post -ContentType 'application/json' -Body $body
```

## Local Docker Test

Start Docker Desktop first. Wait until Docker says the engine is running.

From the repo root:

```powershell
docker info
docker build -t tala-scheduler-solver:tal94e1-local .\cloud\scheduler-solver
docker run --rm --name tala-scheduler-solver-tal94e1 -p 8080:8080 -e PORT=8080 -e SOLVER_TIMEOUT_SECONDS=300 tala-scheduler-solver:tal94e1-local
```

In a second terminal:

```powershell
Invoke-RestMethod -Uri 'http://127.0.0.1:8080/health'
$body = Get-Content -LiteralPath 'cloud/scheduler-solver/samples/minimal_snapshot.json' -Raw
Invoke-RestMethod -Uri 'http://127.0.0.1:8080/solve' -Method Post -ContentType 'application/json' -Body $body
```

Expected sample result:

- `solver_status`: `optimal`
- `assigned_count`: `2`
- `unassigned_count`: `0`
- `assignments`: 2 rows with `assignment_status = ok` and `scheduling_demand_id`

### Local Demo Boundary

The Docker image is a usable local CP-SAT HTTP service, not only a build check. While the container is running, any valid `tal94-demand-v2` snapshot can be submitted directly to `POST http://127.0.0.1:8080/solve`. Use `GET /health` for readiness; the root path `/` is not an application page.

Laravel supports three explicit solver drivers:

- `local_stub`: in-process deterministic test double.
- `local_http`: development/demo CP-SAT over exact loopback HTTP only.
- `cloud_run`: private Cloud Run over HTTPS with a Google ID token.

For native-Windows development, keep Laravel and Docker on the same machine and configure:

```dotenv
TALA_SCHEDULING_SOLVER_DRIVER=local_http
TALA_SCHEDULING_SOLVER_URL=http://127.0.0.1:8080
TALA_SCHEDULING_SOLVER_AUDIENCE=
TALA_SCHEDULING_SOLVER_CREDENTIALS=
TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS=300
TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS=10
```

Then clear cached configuration and run the normal Laravel web and queue processes:

```powershell
php artisan config:clear
php artisan serve
php artisan queue:listen --queue=scheduling,default --timeout=360
npm run dev
```

Keep `DB_QUEUE_RETRY_AFTER=420` so the database queue does not make a solver job available again before its 360-second timeout. Do not set a worker-level `--tries` override; the solver dispatch job owns its three-attempt policy and 60/300-second backoff.

The System Super Admin Integration Status page should show `Local CP-SAT` and `Configured`. `local_http` rejects non-loopback hosts and is unavailable outside Laravel's `local` and `testing` environments. It sends no IAM token. Never point the `cloud_run` driver at localhost or weaken its IAM behavior.

## Google Cloud Deploy Path

Deployment is a manual, human-gated TAL-94E3 action. `Primary proceed TAL-94E3a` permits only documentation, local verification, and read-only cloud inspection. Do not run Cloud Build, create or retag an image, change IAM, or deploy a revision until the user explicitly says `Deploy TAL-94E3a`.

Existing development target references from the rescue setup are listed below. TAL-94E3 must re-confirm them against the live Google Cloud project before use; they are not proof of current deployment state.

- Project ID: `tala-dev-ocr-3s`
- Region: `asia-southeast1`
- Cloud Run service: `tala-scheduler-solver`
- Runtime service account: `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com`
- Dedicated caller: `tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com`

Cloud Build runs the complete Python solver and server test suite before it publishes an image.

### Required read-only preflight

The local Google Cloud CLI path is recommended because it gives repeatable revision, traffic, image, and IAM evidence. `gcloud auth login` authenticates the human operator through the browser; it does not replace the dedicated Laravel invoker credential.

Run from the repo root:

```powershell
$project = 'tala-dev-ocr-3s'
$region = 'asia-southeast1'
$service = 'tala-scheduler-solver'
$repository = 'tala-containers'
$runtimeIdentity = 'tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com'
$invokerIdentity = 'tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com'

gcloud auth login
gcloud config set project $project
gcloud config set run/region $region
gcloud config list
gcloud billing projects describe $project
gcloud services list --enabled --project $project
gcloud artifacts repositories describe $repository --location $region --project $project
$beforeState = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$beforeState.status | ConvertTo-Json -Depth 10
gcloud run services get-iam-policy $service --region $region --project $project --format=json
```

Before mutation, confirm all of the following:

- the active account is the intended operator account;
- the project, region, service, repository, and runtime identity match the references above;
- Cloud Run, Artifact Registry, and Cloud Build are enabled and billing is active;
- the current traffic allocation and serving revision are understood and recorded before mutation; treat a prior revision as a rollback target only after proving that it accepts the current application contract;
- neither `allUsers` nor `allAuthenticatedUsers` has `roles/run.invoker`;
- the dedicated caller has service-level `roles/run.invoker` and no unexplained broader project role.

Stop if any item cannot be confirmed. Do not create another service-account key. The existing Laravel credential remains outside Git and is reused only after its identity and least-privilege binding are verified.

### Option A: Local Google Cloud CLI and Cloud Build

This is the preferred path. Docker is not required because Cloud Build runs the tests and builds the container remotely.

After read-only preflight passes and the user says `Deploy TAL-94E3a`:

```powershell
$sourceId = (git rev-parse --short=12 HEAD).Trim().ToLowerInvariant()
$tag = "e3a-$sourceId"
$image = "$region-docker.pkg.dev/$project/$repository/$service`:tal94-demand-v2-$sourceId"

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
  --timeout 300 `
  --memory 1Gi `
  --cpu 1 `
  --set-env-vars SOLVER_TIMEOUT_SECONDS=300
```

If preflight proves that the exact dedicated invoker binding is missing, the same explicit deployment authorization permits only this narrow IAM correction:

```powershell
gcloud run services add-iam-policy-binding $service `
  --region $region `
  --project $project `
  --member "serviceAccount:$invokerIdentity" `
  --role roles/run.invoker
```

Do not grant the invoker a project-wide basic role and do not add a public member.

### Option B: Cloud Shell and Cloud Build

Use Cloud Shell when the local machine does not have Google Cloud CLI. Cloud Shell supplies `gcloud`, but the same human login, project confirmation, read-only preflight, explicit `Deploy TAL-94E3a` authorization, and stop rules still apply.

From the local repo root, print the source ID and create the upload package:

```powershell
$sourceId = (git rev-parse --short=12 HEAD).Trim().ToLowerInvariant()
Write-Output "Cloud Shell SOURCE_ID=$sourceId"
Compress-Archive -Path '.\cloud\scheduler-solver' -DestinationPath '.\cloud\scheduler-solver.zip' -Force
```

In Google Cloud Console:

1. Select project `tala-dev-ocr-3s`.
2. Open Cloud Shell.
3. Remove stale upload files so Cloud Shell does not silently rename the new upload:
   `rm -rf scheduler-solver scheduler-solver.zip`
4. Upload `cloud/scheduler-solver.zip` through the Cloud Shell upload menu.
5. Extract it and run the same read-only preflight shown above before requesting deployment authorization.

```bash
unzip scheduler-solver.zip
cd scheduler-solver

PROJECT='tala-dev-ocr-3s'
REGION='asia-southeast1'
SERVICE='tala-scheduler-solver'
REPOSITORY='tala-containers'
RUNTIME_IDENTITY='tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com'

gcloud config set project tala-dev-ocr-3s
gcloud config set run/region asia-southeast1
gcloud config list
gcloud billing projects describe "$PROJECT"
gcloud services list --enabled --project "$PROJECT"
gcloud artifacts repositories describe "$REPOSITORY" --location "$REGION" --project "$PROJECT"
gcloud run services describe "$SERVICE" --region "$REGION" --project "$PROJECT" --format=json
gcloud run services get-iam-policy "$SERVICE" --region "$REGION" --project "$PROJECT" --format=json
```

After the user says `Deploy TAL-94E3a`, paste the source ID printed locally and stage the revision:

```bash
SOURCE_ID='<paste-the-12-character-source-id>'
TAG="e3a-${SOURCE_ID}"
IMAGE="${REGION}-docker.pkg.dev/${PROJECT}/${REPOSITORY}/${SERVICE}:tal94-demand-v2-${SOURCE_ID}"

gcloud builds submit \
  --config cloudbuild.yaml \
  --substitutions _IMAGE="$IMAGE" \
  --project "$PROJECT" \
  .

gcloud run deploy "$SERVICE" \
  --image "$IMAGE" \
  --region "$REGION" \
  --project "$PROJECT" \
  --revision-suffix "$TAG" \
  --tag "$TAG" \
  --no-traffic \
  --no-allow-unauthenticated \
  --service-account "$RUNTIME_IDENTITY" \
  --timeout 300 \
  --memory 1Gi \
  --cpu 1 \
  --set-env-vars SOLVER_TIMEOUT_SECONDS=300
```

Delete the local zip after the upload is complete; it is not a repository artifact:

```powershell
Remove-Item -LiteralPath '.\cloud\scheduler-solver.zip' -ErrorAction SilentlyContinue
```

### Verify the private zero-traffic candidate

Run these checks from the local repo after either deployment option. They use the dedicated Laravel invoker and process-only endpoint overrides; they do not edit `.env`.

```powershell
$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$canonicalUrl = $state.status.url
$candidate = $state.status.traffic | Where-Object { $_.tag -eq $tag }
$tagUrl = $candidate.url
$candidateRevision = $candidate.revisionName

if (-not $tagUrl -or -not $candidateRevision) { throw 'Tagged candidate revision was not discoverable.' }
if ([int] $candidate.percent -ne 0) { throw "Candidate unexpectedly receives $($candidate.percent)% traffic." }

$anonymousHealth = Invoke-WebRequest -Uri "$tagUrl/health" -SkipHttpErrorCheck
$anonymousSolve = Invoke-WebRequest -Uri "$tagUrl/solve" -Method Post -ContentType 'application/json' -Body '{}' -SkipHttpErrorCheck
if ($anonymousHealth.StatusCode -ne 403 -or $anonymousSolve.StatusCode -ne 403) {
  throw "Expected anonymous HTTP 403; health=$($anonymousHealth.StatusCode), solve=$($anonymousSolve.StatusCode)."
}

$env:TALA_SCHEDULING_SOLVER_DRIVER = 'cloud_run'
$env:TALA_SCHEDULING_SOLVER_URL = $tagUrl
$env:TALA_SCHEDULING_SOLVER_AUDIENCE = $canonicalUrl
php artisan config:clear
php artisan tinker --execute 'dump(app(App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient::class)->probe());'
php artisan tinker --execute '$snapshot = json_decode(file_get_contents(base_path("cloud/scheduler-solver/samples/minimal_snapshot.json")), true, 512, JSON_THROW_ON_ERROR); dump(app(App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient::class)->solve($snapshot));'

gcloud run revisions describe $candidateRevision --region $region --project $project --format=json
gcloud run services describe $service --region $region --project $project --format='yaml(status.url,status.traffic,status.latestCreatedRevisionName,status.latestReadyRevisionName)'
gcloud run services get-iam-policy $service --region $region --project $project --format=json
gcloud run services logs read $service --region $region --project $project --limit=50

Remove-Item Env:TALA_SCHEDULING_SOLVER_DRIVER -ErrorAction SilentlyContinue
Remove-Item Env:TALA_SCHEDULING_SOLVER_URL -ErrorAction SilentlyContinue
Remove-Item Env:TALA_SCHEDULING_SOLVER_AUDIENCE -ErrorAction SilentlyContinue
php artisan config:clear
```

Acceptance requires:

- authenticated `/health` reports `contract_version = tal94-demand-v2` and the expected solver version;
- the minimal sample returns a native solver status, two assignments, zero unassigned demands, and zero hard violations;
- the candidate revision resolves to the built image digest and remains tagged at zero default traffic;
- the previously serving revision and traffic allocation remain unchanged;
- IAM has no public invoker and the logs expose no secret or credential content.

Do not edit persistent Laravel configuration during TAL-94E3a. TAL-94E3b reconciles it to the re-confirmed canonical service URL, never the tag URL. The expected form is:

```dotenv
TALA_SCHEDULING_SOLVER_DRIVER=cloud_run
TALA_SCHEDULING_SOLVER_URL=https://<canonical-service-url>
TALA_SCHEDULING_SOLVER_AUDIENCE=https://<canonical-service-url>
TALA_SCHEDULING_SOLVER_CREDENTIALS=C:\path\outside\git\or\storage\app\private\credentials\scheduler-invoker.json
TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS=300
TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS=10
```

TAL-94E3a stops with the validated candidate at zero default traffic. Do not point persistent Laravel configuration at the tag URL and do not promote the candidate. TAL-94E3b owns the queued Laravel end-to-end acceptance, controlled traffic promotion, and recovery validation.

### Run the TAL-94E3b1 tagged Laravel acceptance

This opt-in test is the only E3b1 path that calls the private Cloud Run service. It uses `test_tala_db`, a real database queue worker, the staged tag URL, and the canonical service URL as the ID-token audience. It never changes `.env`, Cloud Run traffic, IAM, or service configuration.

Confirm the tagged revision is still private and receives zero default traffic, then set process-only values in the same PowerShell session:

```powershell
$project = 'tala-dev-ocr-3s'
$region = 'asia-southeast1'
$service = 'tala-scheduler-solver'
$tag = 'e3a-4d17a03ccf1c'
$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$candidate = $state.status.traffic | Where-Object { $_.tag -eq $tag }

if (-not $candidate.url -or [int] $candidate.percent -ne 0) {
    throw 'The approved zero-traffic candidate is not available.'
}

$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:TALA_E3B1_ACCEPTANCE = '1'
$env:TALA_E3B1_TAG_URL = $candidate.url
$env:TALA_E3B1_CANONICAL_URL = $state.status.url
$env:TALA_E3B1_CREDENTIALS = 'C:\path\outside\git\scheduler-invoker.json'
```

Reset only the automatic-test database, run the stateful acceptance, and leave its deterministic fixture in place briefly for the rendered browser checks:

```powershell
php artisan config:clear
php artisan migrate:fresh --seed --force
php artisan test --compact tests/Feature/TAL94E3b1TaggedRealServiceAcceptanceTest.php
```

The test proves that `ScheduleGenerationService` commits one `scheduling` queue job, one bounded database worker calls the tagged V2 service, Laravel accepts exactly two assignments with zero unassigned demands and zero hard violations, publication creates two official meetings, Registrar placement creates two active student bindings, and release-mail intent is recorded without sending email. It also makes one rejected-audience call against the same tag and requires a permanent, redacted failure with no candidate or publication records.

The test fixture provides these temporary accounts, all with password `password`:

| Surface | Account | Expected evidence |
| --- | --- | --- |
| Integration Status | `e3b1.admin@example.test` | Scheduler is configured and the private endpoint is reachable. |
| Schedule Generation Run and Section Meetings | `e3b1.registrar@example.test` | Published V2 run and two active official meetings render without raw diagnostics or errors. |
| Faculty Schedule | `e3b1.faculty@example.test` | Only the two assigned official meetings render. |
| Student Schedule | `e3b1.student@example.test` | Only the two active Registrar-placement bindings render. |

Use `/admin/login` for the three staff accounts and `/student/login` for the student account. Sign out between roles. Review recent browser console and server logs across all four surfaces before classifying any finding. A production defect outside the approved E3b1 test and README boundary requires a revised plan; do not patch it opportunistically.

Immediately after the browser checks, or after any interruption, remove all synthetic records and queued/failed jobs by resetting only `test_tala_db`:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
php artisan migrate:fresh --force

Remove-Item Env:TALA_E3B1_ACCEPTANCE -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_TAG_URL -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_CANONICAL_URL -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_CREDENTIALS -ErrorAction SilentlyContinue
php artisan config:clear
```

Re-prove that `schedule_runs`, `jobs`, and `failed_jobs` are empty before ending E3b1. TAL-94E3b2, not this acceptance, owns canonical-URL confirmation, controlled traffic promotion, and recovery validation.

### Run the TAL-94E3b2 canonical V2 cutover

TAL-94E3b2 moves the already accepted V2 revision onto the normal canonical service URL. The prior V1 revision returns the obsolete `tal61-demand-v1` contract, so it is not an application-compatible fallback. Do not use mixed traffic and do not route traffic back to V1. If V2 cannot be accepted, keep automated generation paused; already published schedules remain authoritative and authorized staff use the documented controlled manual-scheduling continuity until V2 is restored.

`Primary proceed TAL-94E3b2` permits the read-only checks and runbook preparation below. Do not pause the queue or change Cloud Run traffic until the user explicitly says `Cutover TAL-94E3b2`.

#### Reconfirm the immutable cutover inputs

Run from the repository root. Do not continue if any asserted value differs.

```powershell
$project = 'tala-dev-ocr-3s'
$region = 'asia-southeast1'
$service = 'tala-scheduler-solver'
$v1Revision = 'tala-scheduler-solver-00006-sfk'
$v2Revision = 'tala-scheduler-solver-e3a-4d17a03ccf1c'
$v2Tag = 'e3a-4d17a03ccf1c'
$v2Digest = 'sha256:73a7fe91448460da7d704f9275d7d86cacdf2e9e4524b551664080d52cd5952e'

$account = (gcloud auth list --filter=status:ACTIVE --format='value(account)').Trim()
$configuredProject = (gcloud config get-value project 2>$null).Trim()
$configuredRegion = (gcloud config get-value run/region 2>$null).Trim()
if (-not $account) { throw 'No active Google Cloud operator account.' }
if ($configuredProject -ne $project -or $configuredRegion -ne $region) {
    throw "Wrong Google Cloud target: project=$configuredProject region=$configuredRegion"
}

$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$v1Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v1Revision }
$v2Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v2Revision }
$v1Percent = if ($null -eq $v1Traffic.percent) { 0 } else { [int] $v1Traffic.percent }
$v2Percent = if ($null -eq $v2Traffic.percent) { 0 } else { [int] $v2Traffic.percent }
if ($v1Percent -ne 100 -or $v2Percent -ne 0 -or $v2Traffic.tag -ne $v2Tag) {
    throw "Unexpected starting traffic: V1=$v1Percent V2=$v2Percent tag=$($v2Traffic.tag)"
}

$v2 = gcloud run revisions describe $v2Revision --region $region --project $project --format=json | ConvertFrom-Json
if (($v2.status.imageDigest -notlike "*$v2Digest") -or
    (@($v2.status.conditions | Where-Object { $_.type -eq 'Ready' })[0].status -ne 'True')) {
    throw 'The accepted V2 revision or image digest changed.'
}

$iam = gcloud run services get-iam-policy $service --region $region --project $project --format=json | ConvertFrom-Json
$iamText = $iam | ConvertTo-Json -Depth 10
if ($iamText -match 'allUsers|allAuthenticatedUsers') { throw 'Public Cloud Run invocation detected.' }
$expectedInvoker = 'serviceAccount:tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com'
$invokerBinding = $iam.bindings | Where-Object { $_.role -eq 'roles/run.invoker' }
$invokerMembers = @($invokerBinding.members)
if ($invokerMembers.Count -ne 1 -or $invokerMembers[0] -ne $expectedInvoker) {
    throw 'The dedicated private invoker binding is missing.'
}

$projectNumber = (gcloud projects describe $project --format='value(projectNumber)').Trim()
$laravelCanonicalUrl = "https://$service-$projectNumber.$region.run.app"
Write-Output "operator=$account"
Write-Output "canonical=$laravelCanonicalUrl"
Write-Output "V1=$v1Percent% V2=$v2Percent%"
```

Confirm Laravel is already using that stable canonical URL and audience. This proof intentionally does not print the credential path.

```powershell
$env:TALA_E3B2_CANONICAL_URL = $laravelCanonicalUrl
php artisan tinker --execute 'dump([
    "env" => app()->environment(),
    "database" => DB::connection()->getDatabaseName(),
    "driver" => config("tala_integrations.scheduling_solver.driver"),
    "canonical_url_matches" => config("tala_integrations.scheduling_solver.url") === getenv("TALA_E3B2_CANONICAL_URL"),
    "audience_matches" => config("tala_integrations.scheduling_solver.audience") === getenv("TALA_E3B2_CANONICAL_URL"),
    "credentials_readable" => is_readable((string) config("tala_integrations.scheduling_solver.credentials_path")),
    "timeouts" => [config("tala_integrations.scheduling_solver.timeout_seconds"), 360, config("queue.connections.database.retry_after")],
    "queue" => config("queue.default"),
    "cache" => config("cache.default"),
    "pausable" => Illuminate\Queue\Worker::$pausable,
]);'
Remove-Item Env:TALA_E3B2_CANONICAL_URL -ErrorAction SilentlyContinue
```

Require `local`, `tala_db`, `cloud_run`, both URL checks `true`, readable credentials, `300/360/420`, database queue/cache, and `pausable = true`. No persistent `.env` change or queue-worker restart is needed when these values already match.

#### Human-gated atomic cutover

Run this block only after the explicit command `Cutover TAL-94E3b2`.

```powershell
php artisan queue:pause database:scheduling --no-interaction

php artisan tinker --execute 'dump([
    "active_runs" => App\Models\ScheduleGenerationRun::query()->whereIn("status", [
        App\Models\ScheduleGenerationRun::StatusQueued,
        App\Models\ScheduleGenerationRun::StatusDispatching,
    ])->count(),
    "scheduling_jobs" => DB::table("jobs")->where("queue", "scheduling")->count(),
    "failed_scheduling_jobs" => DB::table("failed_jobs")->where("payload", "like", "%ScheduleSolverDispatchJob%")->count(),
]);'
```

All three counts must be zero. `queue:pause` prevents a worker from taking another scheduling job but allows an in-flight job to finish, so wait and recheck rather than changing traffic around active work.

```powershell
gcloud run services update-traffic $service `
    --to-revisions "$v2Revision=100" `
    --region $region `
    --project $project

$deadline = (Get-Date).AddMinutes(5)
do {
    $state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
    $v1Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v1Revision }
    $v2Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v2Revision }
    $v1Percent = if ($null -eq $v1Traffic.percent) { 0 } else { [int] $v1Traffic.percent }
    $v2Percent = if ($null -eq $v2Traffic.percent) { 0 } else { [int] $v2Traffic.percent }
    if ($v1Percent -eq 0 -and $v2Percent -eq 100) { break }
    Start-Sleep -Seconds 5
} while ((Get-Date) -lt $deadline)

if ($v1Percent -ne 0 -or $v2Percent -ne 100) {
    throw "Traffic did not settle: V1=$v1Percent V2=$v2Percent"
}
```

Do not resume scheduling yet. Prove privacy and the canonical V2 contract first:

```powershell
$anonymousHealth = Invoke-WebRequest -Uri "$laravelCanonicalUrl/health" -SkipHttpErrorCheck
if ($anonymousHealth.StatusCode -ne 403) { throw "Expected anonymous HTTP 403, got $($anonymousHealth.StatusCode)." }

php artisan tinker --execute '$probe = app(App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient::class)->probe(); $body = json_decode($probe["body"], true, 512, JSON_THROW_ON_ERROR); dump(["status" => $probe["status"], "contract_version" => $body["contract_version"] ?? null, "solver_version" => $body["solver_version"] ?? null]);'
php artisan tinker --execute '$snapshot = json_decode(file_get_contents(base_path("cloud/scheduler-solver/samples/minimal_snapshot.json")), true, 512, JSON_THROW_ON_ERROR); $result = app(App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient::class)->solve($snapshot); dump(["solver_status" => $result["solver_status"] ?? null, "solver_version" => $result["solver_version"] ?? null, "assigned_count" => $result["assigned_count"] ?? null, "unassigned_count" => $result["unassigned_count"] ?? null, "hard_violation_count" => $result["hard_violation_count"] ?? null]);'
```

Require authenticated health `tal94-demand-v2` / `cloud-cp-sat-tal94-demand-v2`, an `optimal` or `feasible` solve, two assignments, zero unassigned demands, and zero hard violations.

#### Canonical queued Laravel acceptance

Reuse the accepted E3b1 harness with its request URL set to the canonical URL. The variable retains its E3b1 name because the test is reused unchanged; it no longer points to the tag during this step.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
$env:TALA_E3B1_ACCEPTANCE = '1'
$env:TALA_E3B1_TAG_URL = $laravelCanonicalUrl
$env:TALA_E3B1_CANONICAL_URL = $laravelCanonicalUrl
$env:TALA_E3B1_CREDENTIALS = 'C:\path\outside\git\scheduler-invoker.json'

php artisan migrate:fresh --seed --force
php artisan test --compact tests/Feature/TAL94E3b1TaggedRealServiceAcceptanceTest.php
```

The test must prove the queued V2 workflow, exact candidate coverage, publication, projections, release-mail intent, rendered role surfaces, rejected-audience handling, and secret redaction. Then remove its synthetic records and process-only settings before the local regression suite:

```powershell
php artisan migrate:fresh --force
Remove-Item Env:TALA_E3B1_ACCEPTANCE -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_TAG_URL -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_CANONICAL_URL -ErrorAction SilentlyContinue
Remove-Item Env:TALA_E3B1_CREDENTIALS -ErrorAction SilentlyContinue

php artisan test --compact tests/Unit/TAL65CloudRunSchedulingSolverClientTest.php
php artisan test --compact tests/Unit/TAL94E1SchedulingSolverTransportTest.php
php artisan test --compact tests/Feature/TAL94E2aSolverQueueOperationsTest.php
php artisan test --compact tests/Feature/TAL92DIntegrationMonitoringTest.php
php artisan test --compact
```

Finish by re-proving the cloud and database state, then resume scheduling:

```powershell
$state = gcloud run services describe $service --region $region --project $project --format=json | ConvertFrom-Json
$v1Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v1Revision }
$v2Traffic = $state.status.traffic | Where-Object { $_.revisionName -eq $v2Revision }
$v1Percent = if ($null -eq $v1Traffic.percent) { 0 } else { [int] $v1Traffic.percent }
$v2Percent = if ($null -eq $v2Traffic.percent) { 0 } else { [int] $v2Traffic.percent }
if ($v1Percent -ne 0 -or $v2Percent -ne 100) { throw "Final traffic drift: V1=$v1Percent V2=$v2Percent" }

$iam = gcloud run services get-iam-policy $service --region $region --project $project --format=json | ConvertFrom-Json
$iamText = $iam | ConvertTo-Json -Depth 10
if ($iamText -match 'allUsers|allAuthenticatedUsers') { throw 'Public Cloud Run invocation detected.' }
$invokerBinding = $iam.bindings | Where-Object { $_.role -eq 'roles/run.invoker' }
$invokerMembers = @($invokerBinding.members)
if ($invokerMembers.Count -ne 1 -or $invokerMembers[0] -ne $expectedInvoker) { throw 'Private invoker IAM drift detected.' }

$logs = gcloud run services logs read $service --region $region --project $project --limit=50
if (($logs -join "`n") -match 'BEGIN PRIVATE KEY|private_key_id|scheduler-invoker\.json') {
    throw 'Possible credential material detected in Cloud Run logs.'
}
$logs

$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'test_tala_db'
php artisan migrate:fresh --force
php artisan tinker --execute 'dump(["database" => DB::connection()->getDatabaseName(), "schedule_runs" => DB::table("schedule_runs")->count(), "scheduling_jobs" => DB::table("jobs")->where("queue", "scheduling")->count(), "failed_scheduling_jobs" => DB::table("failed_jobs")->where("payload", "like", "%ScheduleSolverDispatchJob%")->count()]);'
Remove-Item -Path Env:APP_ENV, Env:DB_CONNECTION, Env:DB_DATABASE -ErrorAction SilentlyContinue

php artisan queue:resume database:scheduling --no-interaction
```

The final state is V2 at 100%, V1 at 0%, private IAM unchanged, clean test records and queues, and scheduling resumed. Retain V1 at 0% temporarily; do not delete it in TAL-94E3b2.

#### Recovery without V1 traffic

If any canonical V2 or queued-acceptance check fails, leave `database:scheduling` paused and do not route traffic to V1. Re-assert V2 at 100% if traffic is unsettled, inspect the revision and Cloud Run logs, and correct or redeploy V2 only through a separately approved plan. Published schedules remain available because Laravel owns official records. Resume scheduling only after the canonical V2 health, solve, queued acceptance, private IAM, and cleanup checks all pass.

## Current V2 Limitations

- The V2 solver schedules each `Scheduling Demand` as one contiguous block using `required_duration_minutes`, with `source_snapshot.weekly_contact_hours` only as a fallback.
- It does not yet split lectures or laboratories across multiple weekly meetings.
- It enforces room type, required feature keys, capacity, fixed room IDs, faculty load, exact demand coverage, and the other V2 hard constraints.
- It returns only native outcome states (`optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`); infeasible runs include conflict rows for diagnostics and are never reported as partial success.
- Laravel remains the final validator, review surface, commit authority, and publish authority.
