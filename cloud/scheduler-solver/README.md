# TALA Scheduler Solver POC

This folder contains the rescue-scope Cloud Run solver container for automatic scheduling.

It is a deterministic Google OR-Tools CP-SAT service. It is not ML and does not train a model.

## Runtime Contract

- `GET /health`: health probe.
- `POST /solve`: accepts the Laravel solver snapshot JSON and returns solver result JSON.
- The container listens on the `PORT` environment variable, as required by Cloud Run.
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
python -m tala_solver.server
```

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
docker build -t tala-scheduler-solver:local .\cloud\scheduler-solver
docker run --rm -p 8080:8080 -e PORT=8080 -e SOLVER_TIMEOUT_SECONDS=300 tala-scheduler-solver:local
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
- `draft_rows`: 2 rows with `status = ok`

## Google Cloud Deploy Path

Current project values from the rescue setup:

- Project ID: `tala-dev-ocr-3s`
- Region: `asia-southeast1`
- Cloud Run service: `tala-scheduler-solver`
- Runtime service account: `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com`

Local machine currently does not have `gcloud` on PATH. Install Google Cloud CLI locally or use Cloud Shell after the solver code is available there.

### Deploy with Cloud Shell from local zip

Use this path when the local machine should not install Google Cloud CLI.

From the local repo root, create the upload package:

```powershell
Compress-Archive -Path '.\cloud\scheduler-solver' -DestinationPath '.\cloud\scheduler-solver.zip' -Force
```

In Google Cloud Console:

1. Select project `tala-dev-ocr-3s`.
2. Open Cloud Shell.
3. Upload `cloud/scheduler-solver.zip` through the Cloud Shell upload menu.

Then run in Cloud Shell:

```bash
rm -rf scheduler-solver
unzip scheduler-solver.zip
cd scheduler-solver

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

IMAGE="asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:rescued-poc"

gcloud builds submit \
  --tag "$IMAGE" \
  --project=tala-dev-ocr-3s

gcloud run deploy tala-scheduler-solver \
  --image "$IMAGE" \
  --region asia-southeast1 \
  --project tala-dev-ocr-3s \
  --service-account tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com \
  --no-allow-unauthenticated \
  --timeout 300 \
  --memory 1Gi \
  --cpu 1
```

Test from Cloud Shell with an identity token:

```bash
SERVICE_URL="https://tala-scheduler-solver-783866300038.asia-southeast1.run.app"

curl -s -H "Authorization: Bearer $(gcloud auth print-identity-token --audiences=$SERVICE_URL)" \
  "$SERVICE_URL/health"

curl -s -H "Authorization: Bearer $(gcloud auth print-identity-token --audiences=$SERVICE_URL)" \
  -H "Content-Type: application/json" \
  --data-binary @samples/minimal_snapshot.json \
  "$SERVICE_URL/solve"
```

### Deploy with local Docker + gcloud

```powershell
gcloud auth login
gcloud config set project tala-dev-ocr-3s
gcloud services enable run.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com

gcloud artifacts repositories create tala-containers `
  --repository-format=docker `
  --location=asia-southeast1 `
  --description="TALA container images"

gcloud auth configure-docker asia-southeast1-docker.pkg.dev

$image = 'asia-southeast1-docker.pkg.dev/tala-dev-ocr-3s/tala-containers/tala-scheduler-solver:rescued-poc'
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
TALA_SCHEDULING_SOLVER_AUTH=iam_private
TALA_SCHEDULING_SOLVER_URL=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_AUDIENCE=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_CREDENTIALS=C:\path\outside\git\or\storage\app\private\credentials\scheduler-invoker.json
TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS=300
TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS=10
```

Do not switch Laravel from `local_stub` to `cloud_run` until the local Docker test passes and the deployed Cloud Run `/health` probe succeeds.

## Current Rescue Limitations

- This POC schedules each section-delivery-group subject demand as one contiguous block using `weekly_contact_hours`, with legacy `lec_hours` or `units` only as fallback duration inputs.
- It does not yet split lectures or laboratories across multiple weekly meetings.
- It uses `section_delivery_groups.fixed_room` / `room` as the rescue fixed-room catalog for room-required delivery groups.
- It emits unassigned curriculum demand as `conflict` draft rows so Laravel can store/review them safely.
- Laravel remains the final validator, review surface, commit authority, and publish authority.
