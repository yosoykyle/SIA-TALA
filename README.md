# T.A.L.A. (Tertiary Academic Lifecycle Administration) System

![TALA Hero Banner](.github/assets/tala_hero_banner.jpg)

Tertiary Academic Lifecycle Administration (T.A.L.A.) is the unified academic, financial, and administrative management platform for **Servitech Institute Asia (SIA)**.

> The name also draws on the Filipino *tala/talâ*, meaning both "star" and "a record or register," reflecting the system's role as the institution's single trusted academic record.

---

## Academic Context & Project Team

This project is developed as part of the requirements for the courses **COMP 015: Fundamentals of Research** and **INTE 303: Capstone Project 1** for the 3rd Year College Bachelor of Science in Information Technology (BSIT) program at:

**Republic of the Philippines**  
**POLYTECHNIC UNIVERSITY OF THE PHILIPPINES**  
*San Pedro Campus*

### Project Group: Cognitres

| Name | Student ID | Role |
| :--- | :---: | :--- |
| [**Baluyot, Kyle F.**](https://www.facebook.com/bkyle.2005) | 2023-00354-SP-0 | Developer |
| [**Diaz, Warien M.**](https://www.facebook.com/warien.diaz) | 2023-00386-SP-0 | Project Manager |
| [**Maniquiz, Stephanie C.**](https://www.facebook.com/stephany.cruz.733094) | 2023-00374-SP-0 | Documentation |

---

## 1. System Prerequisites
Ensure your local machine has the following tools installed:
*   **PHP 8.2+** (Verify: `php -v`)
*   **Composer 2.6+** (Verify: `composer -V`)
*   **Node.js 20.x+** & **npm** (Verify: `node -v`)
*   **MySQL 8.0+** (Verify: `mysql --version`)
*   **Git** (Verify: `git --version`)

---

## 2. Quick Start Setup (Spin Up)

Run the automated setup script to install all dependencies and configure the workspace:

```bash
# 1. Clone the repository and navigate into the folder
git clone <repository_url>
cd SIA-TALA

# 2. Run the automated installation script
composer setup
```

The `composer setup` script automatically:
*   Installs PHP dependencies via Composer.
*   Copies `.env.example` to `.env` (if not already present).
*   Generates the encryption key (`key:generate`).
*   Runs database migrations (`migrate`).
*   Installs Node dependencies via npm.
*   Builds the frontend assets for production.

---

## 3. Core Environment Settings

Open the newly created `.env` file and verify your MySQL database connection:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tala_db
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

*(Note: Create the database in MySQL before running migrations if you didn't run the installer: `CREATE DATABASE tala_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`)*

---

## 4. Running the Application

To start the local development services concurrently:

```bash
composer dev
```

The `composer dev` command launches:
*   **Laravel Server:** Runs at `http://127.0.0.1:8000`
*   **Queue Worker:** Runs `php artisan queue:listen --queue=scheduling,default --timeout=360` so solver dispatches and default background work are both processed. The database queue's `retry_after` must remain greater than the solver job timeout.
*   **Vite Server:** Handles hot module reloading (HMR) for frontend styles
*   **Laravel Pail:** Streams backend logs directly to your terminal screen

---

## 5. Testing & Verification

Ensure your setup is working correctly:

```bash
# Run the automated test suite
php artisan test --compact
```

*   **Filament Admin Dashboard:** Navigate to `http://127.0.0.1:8000/admin` (create a user via `php artisan make:filament-user`).
*   **Student Hub:** Navigate to `http://127.0.0.1:8000/student` to verify the Livewire/TallStackUI frontend and PWA service workers.

---

## 6. First-Time System Exploration (Testing Only)

TAL-96D5E1 provides a deterministic exploration environment for learning the system before the defense rehearsal. It uses the verified `MIDDLE` fixture and adds synthetic applicant, student, staff, enrollment, finance, grade, and lifecycle states. It is not a production seeder and must never target `tala_db`.

In PowerShell, set and prove the isolated runtime:

```powershell
$env:APP_ENV="testing"
$env:DB_CONNECTION="mysql"
$env:DB_DATABASE="test_tala_db"

php artisan config:clear
php artisan tinker --execute 'echo app()->environment()."|".DB::connection()->getDriverName()."|".DB::connection()->getDatabaseName();'
```

The proof must print exactly:

```text
testing|mysql|test_tala_db
```

Before the D5E1 overlay exists, the MIDDLE scenario check must report `scenario_state=complete`, `readiness=PASS`, and the expected 270-student / 9-cohort / 77-offering / 77-demand / 14-faculty fingerprint after the separately approved corrected-fixture rebuild:

```powershell
php artisan acceptance:seed-scheduling-scenario MIDDLE --check --no-interaction
```

Once the D5E1 overlay exists, use the D5E1 command as the authoritative read-only check:

```powershell
php artisan acceptance:seed-tal96d5e1-exploration --check --no-interaction
```

It must report that same fingerprint and `coverage_state=PASS`. The older pristine-scenario checker deliberately reports a conflict after the exploration overlay adds applicant, enrollment, finance, grade, and lifecycle records; do not interpret that expected fail-closed result as a changed scheduling fingerprint. The earlier 80-demand MIDDLE fixture belongs to the completed TAL-96D5D study and remains historical synthetic V1 evidence; it is not the current curriculum authority. If the D5E1 check fails, stop and do not reseed over an unknown database.

Only when `test_tala_db` is a newly migrated, empty database, or after a separately approved snapshot-and-rebuild gate, prepare it in this order:

```powershell
php artisan acceptance:seed-scheduling-scenario MIDDLE --no-interaction
php artisan acceptance:seed-scheduling-scenario MIDDLE --check --no-interaction
php artisan acceptance:seed-tal96d5e1-exploration --no-interaction
php artisan acceptance:seed-tal96d5e1-exploration --check --no-interaction
```

Start the application from the same PowerShell session so every child process inherits the testing database:

```powershell
composer dev
```

Open `http://127.0.0.1:8000/`, then use `/applicant`, `/student`, or `/admin` for the appropriate workspace. All committed exploration accounts use synthetic `@example.test` addresses and the local password `password`. The complete persona catalogue, journey order, expected results, and observation table are in `00_Project_Documents/TALA-System-Operations-and-Defense-Guide.md`.

The exploration command never runs CP-SAT, creates an official timetable, calls SMTP, or contacts PayMongo. Those activities remain separately authorized human gates.

---

## 7. PayMongo Test-Mode Rehearsal

The PayMongo sandbox commands fail closed unless the runtime is `testing`, the database is MySQL `test_tala_db`, the payment driver is `paymongo`, live mode is disabled, and the required test-mode configuration is present. Store test keys and the webhook signing secret only in an untracked local environment; never commit credentials, dashboard identifiers, or temporary public endpoint URLs.

Before the human-gated rehearsal, set these placeholders locally:

```env
TALA_PAYMENT_GATEWAY_DRIVER=paymongo
PAYMONGO_BASE_URL=https://api.paymongo.com
PAYMONGO_PUBLIC_KEY=<test-mode-public-key>
PAYMONGO_SECRET_KEY=<test-mode-secret-key>
PAYMONGO_WEBHOOK_SIG=<test-mode-webhook-signing-secret>
PAYMONGO_LIVEMODE=false
PAYMONGO_WEBHOOK_MAX_AGE_SECONDS=300
```

In PowerShell, prove and retain the isolated test runtime for the command sequence:

```powershell
$env:APP_ENV="testing"
$env:DB_CONNECTION="mysql"
$env:DB_DATABASE="test_tala_db"

php artisan config:clear
php artisan integrations:paymongo-sandbox-checkout --assessment-id=<active-assessment-id>
php artisan integrations:paymongo-sandbox-webhook-smoke --attempt-id=<payment-attempt-id> --process-pending
php artisan integrations:paymongo-sandbox-expire --attempt-id=<pending-payment-attempt-id>
```

The checkout command creates or reuses one active attempt. After a successful test payment and signed webhook delivery, the smoke command verifies the Payment, payment-sourced ledger entry, processed integration event, Finance Gate effect, and notification evidence. Use the expiry command only for a still-pending declined or cancelled test attempt; it records local expiry only after PayMongo confirms the Checkout Session is expired.

Creating a public HTTPS endpoint, registering it in the PayMongo dashboard, entering credentials, completing the hosted checkout, and tearing down the endpoint are human-only rehearsal steps. They are intentionally outside automated tests and must target test mode and `test_tala_db` only.
