# Developer Guide: CP-SAT Scheduling Solver & Google Cloud Run Setup

This guide provides the complete architecture overview, developer setup, Google Cloud CLI installation, and terminal verification commands for the **TALA CP-SAT Scheduling Solver** running on **Google Cloud Run**.

---

## 1. The Core Architecture Distinction

There are **two distinct tiers** of access:

| Tier | Purpose | What is Used? | Do they need GCP Login? |
| :--- | :--- | :--- | :---: |
| **Tier 1: Application Runtime (Local Laravel)** | Running timetable generation and solver tests inside Laravel. | **Service Account JSON Key** (`tala-dev-ocr-3s-aba571363fdf.json`) placed in `storage/app/private/credentials/`. | **NO.** The app authenticates machine-to-machine. |
| **Tier 2: Cloud Admin & Deployment** | Deploying Python solver updates, viewing real-time Cloud Run logs, and inspecting GCP metrics. | **Personal Google Accounts** via Google Cloud CLI (`gcloud`) or the GCP Web Console. | **YES.** Collaborators log in with their own Gmail. |

> [!IMPORTANT]
> **To simply code, run, and test Laravel locally, teammates ONLY need the `.json` key file and the `.env` settings.** They do not need to install `gcloud` or log in to Google Cloud unless they are modifying the Python solver container or inspecting server logs.

---

## 2. Project Access & Collaborators Ledger

The Cloud Run solver is hosted under the Google Cloud Project **`tala-dev-ocr-3s`** in region **`asia-southeast1`**.

### Active Team Access:
* **`kylebaluyot2018@gmail.com`** — **Project Owner** (`roles/owner`)
* **`bolachrisjherico@gmail.com`** — **Project Editor** (`roles/editor` — Full operational/deployment access)
* **`leonesbibiano@gmail.com`** — **Project Editor** (`roles/editor` — Full operational/deployment access)

*All collaborator accounts are active immediately. Developers can access the project simply by logging in to the [Google Cloud Console](https://console.cloud.google.com) or running `gcloud auth login` with their accounts.*

---

## 3. Tier 1: Local Developer Setup (Running the App)

Follow these steps on any developer machine to connect local Laravel to the live Cloud Run solver:

### Step 1: Obtain the Service Account Key
Because `storage/app/private/` is git-ignored for security reasons, the credential file is not committed to GitHub.

> **Access Request:**  
> Contact **`kylefbaluyot@iskolarngbayan.pup.edu.ph`** to securely obtain the authorized service account key:
> ```text
> tala-dev-ocr-3s-aba571363fdf.json
> ```

Save the obtained file directly into your local project at:
```text
storage/app/private/credentials/tala-dev-ocr-3s-aba571363fdf.json
```

### Step 2: Configure Environment Variables (`.env`)
Ensure the following variables are present in your `.env` file:

```env
TALA_SCHEDULING_SOLVER_DRIVER=cloud_run
TALA_SCHEDULING_SOLVER_AUTH=iam_private
TALA_SCHEDULING_SOLVER_URL=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_AUDIENCE=https://tala-scheduler-solver-783866300038.asia-southeast1.run.app
TALA_SCHEDULING_SOLVER_CREDENTIALS="storage/app/private/credentials/tala-dev-ocr-3s-aba571363fdf.json"
TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS=330
TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS=10
```

### Step 3: Clear Laravel Configuration Cache
```powershell
php artisan config:clear
```

---

## 4. Tier 2: Google Cloud CLI (`gcloud`) Installation & Setup

For developers who need to deploy Python solver updates or inspect live Cloud Run logs:

### A. Install Google Cloud CLI
1. Download the official installer: [Google Cloud SDK Installer](https://dl.google.com/dl/cloudsdk/channels/rapid/GoogleCloudSDKInstaller.exe).
2. Run `GoogleCloudSDKInstaller.exe` and follow the on-screen prompts.
3. Keep the default settings checked (ensure *Bundled Python* and *Command line tools* are selected).
4. Official documentation reference: [https://cloud.google.com/sdk/docs/install#windows](https://cloud.google.com/sdk/docs/install#windows).

---

### B. Authenticate and Configure gcloud

Once installed, open a new PowerShell window and run:

1. **Log in with your invited Gmail account:**
   ```powershell
   gcloud auth login
   ```
   *(A browser window opens. Sign in using your authorized Google account).*

2. **Set the active project:**
   ```powershell
   gcloud config set project tala-dev-ocr-3s
   ```

3. **Set the default Cloud Run region:**
   ```powershell
   gcloud config set run/region asia-southeast1
   ```

4. **Verify your active configuration:**
   ```powershell
   gcloud config list
   ```

---

## 5. Terminal-Based Verification Suite

### Test 1: Verify Service Account Token Minting (Laravel Tinker)
Verifies that Laravel can read the local `.json` file and successfully receive an OIDC ID token from Google:

```powershell
php artisan tinker --execute 'app(\App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider::class)->tokenFor(config("tala_integrations.scheduling_solver.audience")); dump("Token minted successfully!");'
```
* **Expected Result:** `"Token minted successfully!"`

---

### Test 2: Ping Cloud Run `/health` via Laravel (End-to-End Test)
Mints an ID token, attaches it as a Bearer authorization token, and calls the private Cloud Run `/health` endpoint:

```powershell
php artisan tinker --execute '$token = app(\App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider::class)->tokenFor(config("tala_integrations.scheduling_solver.audience")); $response = Http::withToken($token)->get(config("tala_integrations.scheduling_solver.url") . "/health"); dump($response->status(), $response->json());'
```
* **Expected Result:**
  ```text
  200
  array:4 [
    "contract_version" => "tala-timetable-v2"
    "service" => "tala-scheduler-solver"
    "solver_version" => "cloud-cp-sat-tala-timetable-v2-lexicographic-v1-deadline-v2"
    "status" => "ok"
  ]
  ```

---

### Test 3: Inspect Service via gcloud CLI
Verifies that your Google Cloud account has read/admin access to the Cloud Run service:

```powershell
gcloud run services describe tala-scheduler-solver --region asia-southeast1
```
* **Expected Result:** Displays the service URL, latest revision, and resource configuration (8 vCPU, 16 GiB).

---

## 6. Troubleshooting & Common Pitfalls

| Issue | Cause | Fix |
| :--- | :--- | :--- |
| **`Scheduling solver credentials file is not readable`** | The JSON file is missing or the path in `.env` is incorrect. | Ensure `tala-dev-ocr-3s-aba571363fdf.json` exists in `storage/app/private/credentials/`. Contact `kylefbaluyot@iskolarngbayan.pup.edu.ph` if missing. |
| **`401 Unauthorized` / `403 Forbidden`** | Audience mismatch or invalid token. | Verify that `TALA_SCHEDULING_SOLVER_AUDIENCE` in `.env` matches the exact base URL of the service. |
| **`gcloud: The term 'gcloud' is not recognized`** | Cloud SDK was just installed but terminal wasn't restarted. | Close all PowerShell windows and open a fresh one so the system `PATH` reloads. |
| **`Config changes not taking effect`** | Laravel has cached old configuration. | Run `php artisan config:clear`. |
| **Timeout Hierarchy** | Cloud Run solver request budget order. | Ensure timeout limits remain ordered: Python request budget (300s) < Laravel HTTP timeout (330s) < Queue Job timeout (360s). |
