# T.A.L.A. (Total Academic Lifecycle Automation) System

![TALA Hero Banner](.github/assets/tala_hero_banner.jpg)

Total Academic Lifecycle Automation (T.A.L.A.) is the unified academic, financial, and administrative management platform for **Servitech Institute Asia (SIA)**.

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
*   **Queue Worker:** Processes background OCR files and webhooks
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
*   **Student Hub:** Navigate to `http://127.0.0.1:8000` to verify the Livewire/TallStackUI frontend and PWA service workers.
