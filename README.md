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
composer setup
```

`composer setup` installs PHP and Node dependencies, creates `.env` when needed, generates the application key, runs migrations, and builds frontend assets.

Configure the development database in `.env` before running the application:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tala_db
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Start the local development processes with:

```powershell
composer dev
```

The command starts Laravel, the queue worker, Vite, and Laravel Pail according to the repository's Composer configuration.

## Developer verification

Use the protected testing configuration described by `AGENTS.md` and the TALA Orchestrator Protocol. DB-backed tests must target `test_tala_db`, never the development database.

```powershell
php artisan test --compact
```

Historical fixture-building, provider rehearsal, demonstration, and acceptance instructions are preserved as non-authoritative evidence under `00_Project_Documents/archive/` and must not be used as current product or execution authority.
