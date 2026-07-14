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
- the current traffic allocation and serving revision are understood and retained as the rollback target;
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

TAL-94E3a stops with the validated candidate at zero default traffic. Do not point persistent Laravel configuration at the tag URL and do not promote the candidate. TAL-94E3b owns the queued Laravel end-to-end acceptance, controlled traffic promotion, and rollback exercise.

## Current V2 Limitations

- The V2 solver schedules each `Scheduling Demand` as one contiguous block using `required_duration_minutes`, with `source_snapshot.weekly_contact_hours` only as a fallback.
- It does not yet split lectures or laboratories across multiple weekly meetings.
- It enforces room type, required feature keys, capacity, fixed room IDs, faculty load, exact demand coverage, and the other V2 hard constraints.
- It returns only native outcome states (`optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`); infeasible runs include conflict rows for diagnostics and are never reported as partial success.
- Laravel remains the final validator, review surface, commit authority, and publish authority.
