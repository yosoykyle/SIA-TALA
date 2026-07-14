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

Deployment is a manual, human-gated TAL-94E3 action. Do not run these commands until the user explicitly authorizes deployment.

Existing development target references from the rescue setup are listed below. TAL-94E3 must re-confirm them against the live Google Cloud project before use; they are not proof of current deployment state.

- Project ID: `tala-dev-ocr-3s`
- Region: `asia-southeast1`
- Cloud Run service: `tala-scheduler-solver`
- Runtime service account: `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com`

Cloud Build runs the complete Python solver and server test suite before it publishes an image.

### Deploy with Cloud Shell from local zip

Use this path when the local machine should not install Google Cloud CLI.

From the local repo root, create the upload package:

```powershell
Compress-Archive -Path '.\cloud\scheduler-solver' -DestinationPath '.\cloud\scheduler-solver.zip' -Force
```

In Google Cloud Console:

1. Select project `tala-dev-ocr-3s`.
2. Open Cloud Shell.
3. If replacing a previous deployment, remove the old files first to prevent Cloud Shell from renaming your upload:
   `rm -rf scheduler-solver scheduler-solver.zip`
4. Upload `cloud/scheduler-solver.zip` through the Cloud Shell upload menu.

5. **Extract the uploaded file:**

```bash
unzip scheduler-solver.zip
cd scheduler-solver
```

6. **First-time setup** (Skip if you've already deployed this project before):

```bash
gcloud config set project tala-dev-ocr-3s
gcloud services enable run.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com

gcloud artifacts repositories describe tala-containers \
  --location=asia-southeast1 \
  --project=tala-dev-ocr-3s \
  || gcloud artifacts repositories create tala-containers \
    --repository-format=docker \
    --location=asia-southeast1 \
    --description="TALA container images" \
    --project=tala-dev-ocr-3s
```

7. **Build and Deploy** (Run this every time you update the code):

```bash
gcloud config set project tala-dev-ocr-3s
IMAGE="asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:tal94-demand-v2-$(date +%Y%m%d-%H%M)"

gcloud builds submit \
  --config cloudbuild.yaml \
  --substitutions _IMAGE="$IMAGE" \
  --project=tala-dev-ocr-3s \
  .

gcloud run deploy tala-scheduler-solver \
  --image "$IMAGE" \
  --region asia-southeast1 \
  --project tala-dev-ocr-3s \
  --service-account tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com \
  --no-allow-unauthenticated \
  --timeout 300 \
  --memory 1Gi \
  --cpu 1 \
  --set-env-vars SOLVER_TIMEOUT_SECONDS=300
```

Test from Cloud Shell with an identity token:

```bash
SERVICE_URL="$(gcloud run services describe tala-scheduler-solver \
  --region asia-southeast1 \
  --project tala-dev-ocr-3s \
  --format='value(status.url)')"

curl -s -H "Authorization: Bearer $(gcloud auth print-identity-token --audiences=$SERVICE_URL)" \
  "$SERVICE_URL/health"

curl -s -H "Authorization: Bearer $(gcloud auth print-identity-token --audiences=$SERVICE_URL)" \
  -H "Content-Type: application/json" \
  --data-binary @samples/minimal_snapshot.json \
  "$SERVICE_URL/solve" \
  | python3 -m json.tool
```

### Deploy with local Docker + gcloud

1. **First-time setup** (Skip if you've already deployed this project before):

```powershell
gcloud auth login
gcloud config set project tala-dev-ocr-3s
gcloud services enable run.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com

gcloud artifacts repositories create tala-containers `
  --repository-format=docker `
  --location=asia-southeast1 `
  --description="TALA container images"

gcloud auth configure-docker asia-southeast1-docker.pkg.dev
```

2. **Build and Deploy** (Run this every time you update the code):

```powershell
gcloud config set project tala-dev-ocr-3s
$image = 'asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:tal94-demand-v2'
docker build -t $image .\cloud\scheduler-solver
docker push $image

gcloud run deploy tala-scheduler-solver `
  --image $image `
  --region asia-southeast1 `
  --service-account tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com `
  --no-allow-unauthenticated `
  --timeout 300 `
  --memory 1Gi `
  --cpu 1
```

After deployment, keep Laravel `.env` pointed at the Cloud Run service URL:

```dotenv
TALA_SCHEDULING_SOLVER_DRIVER=cloud_run
TALA_SCHEDULING_SOLVER_URL=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_AUDIENCE=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_CREDENTIALS=C:\path\outside\git\or\storage\app\private\credentials\scheduler-invoker.json
TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS=300
TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS=10
```

Do not switch Laravel to `cloud_run` until TAL-94E3 proves the deployed V2 revision, private IAM invocation, `/health`, and `/solve` from the intended Laravel runtime.

## Current V2 Limitations

- The V2 solver schedules each `Scheduling Demand` as one contiguous block using `required_duration_minutes`, with `source_snapshot.weekly_contact_hours` only as a fallback.
- It does not yet split lectures or laboratories across multiple weekly meetings.
- It enforces room type, required feature keys, capacity, fixed room IDs, faculty load, exact demand coverage, and the other V2 hard constraints.
- It returns only native outcome states (`optimal`, `feasible`, `infeasible`, `model_invalid`, or `unknown`); infeasible runs include conflict rows for diagnostics and are never reported as partial success.
- Laravel remains the final validator, review surface, commit authority, and publish authority.
