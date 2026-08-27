# T.A.L.A. (Tertiary Academic Lifecycle Administration)

TALA is the college information-system project for Servitech Institute Asia. Product behavior is governed by the [TALA documentation authority registry](00_Project_Documents/README.md), not by this setup guide, archived plans, demonstrations, code, tests, or task records.

## Prerequisites

- PHP 8.2+
- Composer 2.6+
- Node.js 20.19+ on the 20.x line, or 22.12+ (required by the installed Vite 7), and npm
- MySQL 8.0+
- Git

Make `php`, `composer`, `node`, `npm`, `mysql`, and `git` available on your terminal's `PATH`. Native Windows setup uses PowerShell; Sail/Docker and the optional Pail log viewer are not required.

## Local setup

```powershell
git clone https://github.com/yosoykyle/SIA-TALA.git
Set-Location SIA-TALA
Copy-Item .env.example .env
```

Create separate development and automated-test databases before running setup:

```powershell
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS tala_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS test_tala_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Configure only the development database in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tala_db
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Replace the username/password examples with your local MySQL credentials. Keep `APP_ENV=local`.

Then install dependencies, generate the application key, migrate `tala_db`, and build the frontend assets:

```powershell
composer setup
```

Use `composer setup` for a fresh clone: it regenerates `APP_KEY`. For an existing installation, keep its key and use the maintenance steps below instead of repeating setup.

If a slow first install exceeds Composer's default 300-second process timeout, retry the fresh-clone setup with its [supported timeout option](https://getcomposer.org/doc/03-cli.md#run-script-run):

```powershell
composer run-script --timeout=1800 setup
```

Check required PHP extensions and seed the fresh local database's fixed roles/permissions, default admission policies, and FAQ content:

```powershell
composer check-platform-reqs
php artisan db:seed --no-interaction
```

This creates no staff account or complete academic dataset. Any Issue needing those must include an authorized local fixture/account setup; there are no default administrator credentials. Review seeding before using it on an existing database because it synchronizes permissions.

Start the local development processes with:

```powershell
composer dev
```

The command starts Laravel, the canonical `scheduling,default` queue listener, and Vite together in one terminal on native Windows or a supported Linux environment. Laravel continues writing application logs normally without a required live-log process. When active log inspection is useful on Windows, run `Get-Content storage\logs\laravel.log -Wait -Tail 80` separately.

Use the local addresses printed by the processes; stop them together with `Ctrl+C` and confirm `Y` if Windows asks to terminate the batch job. The example environment uses mock payments/OCR, the local solver stub, and email written to the application log. Real provider connectivity and scheduled work require their own task-specific setup and verification; starting the application alone does not prove them ready.

## Developer verification

`phpunit.xml` forces automated tests to use `APP_ENV=testing`, MySQL, and `test_tala_db`. Keep that database disposable and never place development, demonstration, or institutional records in it. Do not point the normal local `.env` at `test_tala_db`.

`tala_db` holds the records used while browsing the local application; `test_tala_db` holds disposable automated-test records. Both schemas come from the same committed migration files, but their data stays separate. A passing local test suite or GitHub CI run does not update or prove the readiness of your local `tala_db`.

### Keep both local schemas current

After pulling dependency changes into an existing installation, refresh the locked dependencies and assets without regenerating its application key:

```powershell
composer install
npm ci
npm run build
```

On a fresh clone and after pulling schema changes, check each database separately. In your normal development terminal:

```powershell
php artisan config:show app.env
php artisan db:show --database=mysql
php artisan migrate:status --no-interaction
```

Confirm the environment is `local` and the actual connection targets your local `tala_db`. Review any pending migrations, then apply them when authorized:

```powershell
php artisan migrate --no-interaction
php artisan migrate:status --no-interaction
```

For the test database, open a separate PowerShell terminal at the repository root:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='test_tala_db'
php artisan db:show --database=mysql
php artisan migrate:status --no-interaction
```

Only after the actual connection names the local disposable `test_tala_db`, apply its reviewed pending migrations when authorized:

```powershell
php artisan migrate --no-interaction
```

Close that separate terminal afterward so test-only environment overrides cannot affect normal development. Run verification from your normal terminal:

```powershell
php artisan test --compact
```

If migration history says a change ran but required tables or columns are missing, investigate the schema mismatch rather than assuming it is current. For the disposable test database only, reopen the separate testing terminal and verify its target as above before an explicitly approved rebuild:

```powershell
php artisan migrate:fresh --force --no-interaction
```

`migrate:fresh` deletes that database's current tables and data. Never use it as routine maintenance for `tala_db`. Close the testing terminal after the rebuild as well.

## Contributor onboarding

Before taking a tracked Issue or preparing another developer's machine, follow [`CONTRIBUTING.md`](CONTRIBUTING.md). It separates application prerequisites from AI-only capabilities, provides the fresh-clone readiness prompt, and explains which tools are required, conditional, or replaceable.

## Optional development tools

The application requires only the prerequisites and setup above. MCP servers, Codex, Claude, Gemini, and other AI-development integrations are not required to install, run, or test TALA. AI-assisted repository work has additional contribution requirements in [`CONTRIBUTING.md`](CONTRIBUTING.md); every agent must also read [`AGENTS.md`](AGENTS.md) and follow the tracked project guidance. Keep machine-specific MCP configuration, credentials, and secrets in ignored local files such as `.mcp.json` and `.env`.

Historical fixture-building, provider rehearsal, demonstration, and acceptance instructions are preserved as non-authoritative evidence under `00_Project_Documents/archive/` and must not be used as current product or execution authority.
