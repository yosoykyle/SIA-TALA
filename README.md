# T.A.L.A. (Tertiary Academic Lifecycle Administration)

TALA is the college information-system project for Servitech Institute Asia. Product behavior is governed by the [TALA documentation authority registry](00_Project_Documents/README.md), not by this setup guide, archived plans, demonstrations, code, tests, or task records.

## Prerequisites

- PHP 8.2+
- Composer 2.6+
- Node.js 20.x+ and npm
- MySQL 8.0+
- Git

## Local setup

```powershell
git clone <repository_url>
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

Then install dependencies, generate the application key, migrate `tala_db`, and build the frontend assets:

```powershell
composer setup
```

Start the local development processes with:

```powershell
composer dev
```

The command starts Laravel, the canonical `scheduling,default` queue listener, and Vite together in one terminal on native Windows or a supported Linux environment. Laravel continues writing application logs normally without a required live-log process. When active log inspection is useful on Windows, run `Get-Content storage\logs\laravel.log -Wait -Tail 80` separately.

## Developer verification

`phpunit.xml` forces automated tests to use `APP_ENV=testing`, MySQL, and `test_tala_db`. Keep that database disposable and never place development, demonstration, or institutional records in it. Do not point the normal local `.env` at `test_tala_db`.

```powershell
php artisan test --compact
```

## Optional development tools

The application requires only the prerequisites and setup above. MCP servers, Laravel Boost tools, Codex, Claude, Gemini, and other AI-development integrations are optional developer aids and are not required to install, run, or test TALA. If an AI agent is used, it must read [`AGENTS.md`](AGENTS.md) and follow the tracked project guidance. Keep machine-specific MCP configuration, credentials, and secrets in ignored local files such as `.mcp.json` and `.env`.

Historical fixture-building, provider rehearsal, demonstration, and acceptance instructions are preserved as non-authoritative evidence under `00_Project_Documents/archive/` and must not be used as current product or execution authority.
