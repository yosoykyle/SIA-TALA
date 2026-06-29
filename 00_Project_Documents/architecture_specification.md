# TALA System Architecture Specification

## 1. Executive Summary & System Title

**T.A.L.A.** (Timetable-Integrated Academic Lifecycle Administration) is the College-focused school information system designed for Servitech Institute Asia (SIA). The title "TALA" (Filipino for _Star/Guide_) reflects the system's role as the central source of truth for academic lifecycle records.

TALA replaces fragmented legacy processes with a unified digital command center for staff (Registrar, Accounting, Faculty, Academic Head, and System Super Admin) and role-scoped authenticated workspaces for applicants and students. It supports operations from applicant intake through student handover, curriculum assignment, scheduling, enrollment, finance evidence, COR/SOA output, grade release, Student Hub visibility, reports, and audit.

---

## 2. System Architecture: Monolithic Modular

TALA is built as a **Monolithic Modular** Laravel web application. Under this architecture, all functional modules (Admissions, Academic Setup, Term Offerings, Scheduling, Enrollment, Finance/Ledger, COR, Grades, Student Hub, Admin Reports) share a single codebase, a single configuration surface, and a single database instance.

### System Architecture Diagram
The diagram below illustrates the system structure, clearly separating the core internal components of the TALA monolith from the external integrations and infrastructure:

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                     TALA SYSTEM AS A WHOLE                             │
├────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │                 PRIMARY TALA MONOLITH (Laravel Application Stack)              │   │
│   ├────────────────────────────────────────────────────────────────────────────────┤   │
│   │                                                                                │   │
│   │   ┌───────────────────────────┐                ┌───────────────────────────┐   │   │
│   │   │   Public Landing Page     │                │ Authenticated Workspaces  │   │   │
│   │   │ Blade / Livewire / Vite   │                │   FilamentPHP Panels      │   │   │
│   │   └─────────────┬─────────────┘                └─────────────┬─────────────┘   │   │
│   │                 │                                            │                 │   │
│   │                 └──────────────────────┬─────────────────────┘                 │   │
│   │                                        │                                       │   │
│   │                        ┌───────────────▼───────────────┐                       │   │
│   │                        │          Livewire 4           │                       │   │
│   │                        │     (Reactive Frontend)       │                       │   │
│   │                        └───────────────┬───────────────┘                       │   │
│   │                                        │                                       │   │
│   │                        ┌───────────────▼───────────────┐                       │   │
│   │                        │   Laravel 12 Business Core    │                       │   │
│   │                        │ (Models, Policies, Jobs)      │                       │   │
│   │                        └───────────────┬───────────────┘                       │   │
│   │                                        │                                       │   │
│   │                 ┌──────────────────────┴─────────────────────┐                 │   │
│   │                 ▼                                            ▼                 │   │
│   │   ┌───────────────────────────┐                ┌───────────────────────────┐   │   │
│   │   │    Local MySQL Database   │                │ Laravel Queue/Cache Store │   │   │
│   │   │       (Data Memory)       │                │  Database current config  │   │   │
│   │   └─────────────┬─────────────┘                └───────────────────────────┘   │   │
│   └─────────────────┼──────────────────────────────────────────────────────────────┘   │
│                     │                                                                  │
│                     ▼ (Secure REST HTTPS Calls)                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │             DEDICATED ISOLATED INFRASTRUCTURE (Google Cloud Run)               │   │
│   ├────────────────────────────────────────────────────────────────────────────────┤   │
│   │                                                                                │   │
│   │                         ┌─────────────────────────────┐                        │   │
│   │                         │   CP-SAT Scheduler Engine   │                        │   │
│   │                         │  (Isolated Solver Container)│                        │   │
│   │                         └─────────────────────────────┘                        │   │
│   └────────────────────────────────────────────────────────────────────────────────┘   │
│                                                                                        │
│                                            ▲                                           │
│                                            │ (Secure Webhook Calls & API Requests)     │
│                                            ▼                                           │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │                        EXTERNAL INTEGRATIONS (Outside SaaS/APIs)               │   │
│   ├────────────────────────────────────────────────────────────────────────────────┤   │
│   │                                                                                │   │
│   │   ┌───────────────────────────┐                ┌───────────────────────────┐   │   │
│   │   │         PayMongo          │                │        SMTP Server        │   │   │
│   │   │   (Configurable Driver)   │                │   (Transactional Mail)    │   │   │
│   │   └───────────────────────────┘                └───────────────────────────┘   │   │
│   └────────────────────────────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### Architectural Component Divisions
1. **Part of the System as a Whole (Laravel Application Stack):**
   * **Public Landing Page and Authenticated Workspaces:** The public route is a Blade/Vite surface. Authenticated staff, applicant, and student workspaces are Filament panel shells backed by Laravel authorization.
   * **Laravel 12 Business Core:** The PHP codebase containing models, policies, service classes, queued jobs, imports, outputs, and integration clients.
   * **Finance Rule Evaluation:** One fee-rule model supplies Accounting's editable configuration surface. The service ranks exact Program-and-Term scope before broader ordinary-charge scopes, then effective date and record ID. Assessment activation separately requires an active exact Program-and-Term downpayment rule.
   * **Local Database, Queue, and Cache Stores:** The relational storage is MySQL in the current environment. The current Laravel queue and cache defaults are database-backed. Redis and Laravel Horizon are not installed in the current dependency set and must be treated as future deployment decisions, not active baseline dependencies.
2. **Dedicated Isolated Infrastructure:**
   * **CP-SAT Scheduler Engine (Google Cloud Run):** The deterministic Google OR-Tools CP-SAT scheduling solver, packaged as a lightweight Python Docker container. To prevent server resources from starving, it is hosted externally on Google Cloud Run and invoked securely via authenticated HTTPS requests.
3. **Outside Integrations (External SaaS/APIs):**
   * **PayMongo:** Processes configured online payment checkout flows when the PayMongo driver is enabled. It communicates with TALA asynchronously using signed HTTP webhooks.
   * **SMTP Server:** An external transactional mail carrier such as Brevo may deliver system emails when configured with an authenticated sender domain.

### 2.2 Detailed Architectural Justifications & Counterpart Comparisons

To establish a solid theoretical and practical foundation for TALA, each core architectural choice is justified below against its primary industry alternative:

#### 1. Core Architecture Pattern: Monolithic Modular vs. Microservices
* **Selected:** Monolithic Modular (Nginx + PHP-FPM + Laravel + MySQL, with Laravel-managed queue/cache stores).
* **Counterpart:** Microservices (separate containers for Admissions, Scheduling, Finance, etc.).
* **Comparative Justification:**
  * **Consistency vs. Complexity:** TALA manages high-stakes student registrations and financial ledgers. Relational consistency (ACID) is trivial in a monolith. In a microservices architecture, keeping the Accounting database in sync with the Registrar database requires complex distributed consensus patterns (e.g., Saga or two-phase commit), increasing failure points.
  * **Resource Overhead:** Microservices require separate host containers, API gateways, service discovery, and database instances. Running microservices would exceed the intended low-cost deployment profile and increase operational risk. Monolithic modularity maintains logical separation in PHP namespaces while sharing one database connection and runtime.

#### 2. Database System: Relational (MySQL 8.0) vs. Document-Store NoSQL (MongoDB)
* **Selected:** MySQL 8.0 (Relational Database Service).
* **Counterpart:** MongoDB (NoSQL Document Store).
* **Comparative Justification:**
  * **Strict Data Integrity:** School registration, course prereqs, and student ledgers are deeply relational. MySQL enforces strict foreign key constraints and schema rules (e.g., preventing a student from enrolling in a subject without the required curriculum binding).
  * **ACID Transactions:** Financial ledger postings (debits/credits) require strict atomicity. MongoDB's document model lacks native relational integrity constraints and can suffer from data anomalies (e.g., orphan records, inconsistent accounting balances) if schema validations are bypassed at the application level.
  * **Exact Monetary Storage:** Fee-rule fixed amounts and per-unit PHP rates use `DECIMAL(12,2)`, matching assessment lines and ledger monetary values. Units and percentage rates retain their separate non-monetary precision.

#### 3. Queue & Cache Store: Database-Backed Baseline vs. Redis / Horizon
* **Selected rescue baseline:** Laravel database queue and database cache, matching the current application configuration.
* **Counterpart / future optimization:** Redis queues/cache plus Laravel Horizon for higher queue throughput and queue-dashboard operations.
* **Comparative Justification:**
  * **Current-Code Accuracy:** `config/queue.php` and the live configuration resolve the default queue connection to `database`. `config/cache.php` and the live configuration resolve the default cache store to `database`. The code already dispatches queued jobs for PayMongo webhooks and schedule solver runs through Laravel's queue contracts.
  * **Dependency Discipline:** `laravel/horizon` is not present in `composer.json` or `composer.lock`. Reintroducing Horizon would be a dependency change and must be approved separately.
  * **Upgrade Path:** Redis remains a valid production optimization if queue volume, cache load, or operational monitoring needs justify it. Until then, the database queue/cache baseline keeps the rescue scope aligned with installed packages.


---

## 3. UI Framework Selections & Justifications

TALA implements a public Blade/Livewire surface plus authenticated Filament workspaces. The current code has a public `/` route, a staff-facing `admin` Filament panel with many resources, and `applicant` and `student` Filament panel shells that currently expose dashboard and logout routes only.

### 3.1 Staff Workspace: FilamentPHP v5 vs. Custom React/Vue Admin Templates
* **Selected:** FilamentPHP v5 (Tailwind, Alpine, Livewire, Laravel - TALL Stack).
* **Counterpart:** Custom SPA Dashboard (e.g., React/Vue admin templates communicating via REST/GraphQL APIs).
* **Comparative Justification:**
  * **Development Velocity:** Staff workspaces require dozens of tables, filters, multi-select forms, and audit trails. Building these manually in React/Vue requires writing separate frontend components, state management, API controllers, and validation rules. FilamentPHP handles this programmatically in PHP, reducing administrative UI development time by ~80%.
  * **Security and Session Consistency:** Because Filament is server-rendered, auth sessions are bound directly to secure HTTP-only cookies. SPA dashboards often rely on storing tokens (like JWTs) in local storage, which are vulnerable to Cross-Site Scripting (XSS) attacks.

### 3.2 Student Hub and Applicant Workspace: Filament Panels vs. Separate SPA
* **Selected:** FilamentPHP panels/pages backed by Laravel, Livewire, and Tailwind CSS v4.
* **Counterpart:** Client-Side Single Page Application (e.g., React/Vite/Inertia).
* **Comparative Justification:**
  * **No API Duplication:** With Livewire, database logic and template variables are bound directly in PHP. We do not have to write separate REST APIs or manage state synchronization between the browser and server.
  * **Mobile Performance & Bundle Size:** SPA frameworks require the client to download large JavaScript bundles before the first page render, slowing down load times on lower-end mobile phones. Filament and Livewire render server-driven interfaces with smaller client payloads.
  * **Workflow Consistency:** Applicant, student, faculty, registrar, accounting, academic head, and system administration surfaces can share the same Laravel authorization, audit, and service-layer boundaries.
  * **Current Rescue Boundary:** Applicant and Student Hub workflows are not yet implemented inside `app/Filament/Applicant/*` or `app/Filament/Student/*`. The workspace gate and applicant-to-student handover must be proven before expanding applicant/student UI.

---

## 4. Codebase Dependencies

TALA's architecture relies on the following verified dependencies declared in the current codebase:

### 4.1 PHP Packages (composer.json)
* `laravel/framework: ^12.0` (Core Laravel 12 framework)
* `php: ^8.2` (PHP 8.2 runtime engine)
* `filament/filament: ^5.1` (Filament v5 Admin Panel builder)
* `livewire/livewire: ^4.0` (Livewire v4 reactive components)
* `laravel/fortify: ^1.37` (Secure login and password verification backend)
* `laravel/mcp: ^0.8` (Laravel MCP integration support)
* `spatie/laravel-permission: ^6.24` (Role-based access control [RBAC])
* `spatie/laravel-model-states: ^2.8` (Available state-machine support)
* `spatie/laravel-activitylog: ^4.8` & `pxlrbt/filament-activity-log: ^2.2` (Audit trail logs for overrides)
* `chillerlan/php-qrcode: ^5.0` (Installed QR capability; public COR verification and QR artifact lookup are not part of the MVP output path unless a later approved policy activates them)
* `maatwebsite/excel: ^3.1` (Excel importing for curricula and exporting for grade reports)
* `luigel/laravel-paymongo: ^2.6` & `spatie/laravel-webhook-client: ^3.5` (PayMongo and webhook support packages)
* `google/auth: ~1.52` (Google service-account authentication for invoking the private Cloud Run CP-SAT solver)
* `tallstackui/tallstackui: 3.0.0` (Premium UI layout components)
* `laravel/tinker: ^2.10` (Local application-context inspection)

Not currently installed: `laravel/horizon`. Redis support is available through Laravel's standard configuration if the runtime provides the Redis PHP extension or a compatible client, but Redis/Horizon are not the active queue baseline.

### 4.2 JavaScript Packages (package.json)
* `tailwindcss: ^4.0.0` & `@tailwindcss/vite: ^4.0.0` (Tailwind CSS v4 compiler)
* `vite: ^7.0.7` & `laravel-vite-plugin: ^2.0.0` (Frontend asset bundling)
* `alpinejs: ^3.15.10` (Client-side toggles, modals, and animations)
* `driver.js: ^1.4.0` (Interactive onboarding guides)
* `heroicons: ^2.2.0` (Frontend iconography)
* `xlsx: ^0.18.5` (Client-side spreadsheet validation)
* `axios: ^1.11.0` (HTTP client utility)

---

## 5. System Integrations & Technicalities

### 5.1 PayMongo Payment Gateway
* **Technical Flow:** The code supports a configurable payment gateway. The current live configuration uses the `mock` driver, while the PayMongo driver is available through `config/tala_integrations.php` and `PayMongoPaymentGateway`.
* **Callback:** The active webhook route is `POST /api/webhooks/paymongo`. `PayMongoWebhookController` verifies the PayMongo signature, stores the raw webhook payload in `webhook_calls`, and dispatches `ProcessPayMongoWebhookCall` for asynchronous processing.

### 5.2 CP-SAT Timetabling Solver (Google Cloud Run Container)
* **Technical Flow:** When the scheduling run is initiated, TALA aggregates `Scheduling Demand` records, rooms, and faculty schedules into an immutable JSON payload.
* **Execution:** TALA dispatches the request to the dedicated `tala-scheduler-solver` container deployed on Google Cloud Run. The connection is authenticated securely using Google service account IAM private keys (`scheduler-invoker.json`). The solver executes the integer-optimization model and returns candidate section-meeting patterns as a JSON payload back to TALA for review.
* **Laravel Authentication Dependency:** The Laravel monolith uses the official `google/auth` Composer package to mint Google identity tokens from the configured service-account credential file before calling the private Cloud Run solver. This package authenticates the HTTP request only; Google OR-Tools remains packaged inside the isolated Python Cloud Run solver container and is not installed into the Laravel application.

#### Comparative Justification: Why CP-SAT over Machine Learning or Genetic Algorithms?
* **Selected Solver:** Google OR-Tools CP-SAT (Constraint Programming - Satisfiability).
* **Counterparts:** Machine Learning (ML/Deep Learning) models or Genetic Algorithms (GA).
* **Detailed Comparison:**

  | Evaluation Criteria | Constraint Programming (CP-SAT) | Machine Learning (ML) | Genetic Algorithms (GA) |
  | :--- | :--- | :--- | :--- |
  | **Constraint Guarantee** | **Guaranteed.** Hard constraints (no teacher double-bookings, physical room cap) are absolute mathematical boundaries. The solver will never output an invalid schedule. | **Probabilistic.** ML models output predictions. They cannot guarantee that a teacher won't be assigned to two classes simultaneously or that room capacities won't overlap. | **Heuristic.** GAs improve schedules over generations, but they can get stuck in local optima and output a schedule that still violates minor hard constraints. |
  | **Training Data Requirement** | **None.** CP-SAT executes purely on mathematical logic and constraint boundaries defined at runtime. It requires zero historical schedule training datasets. | **Massive.** Requires thousands of past high-quality schedule records to train a model. SIA, being a local school, does not possess this quantity of data. | **None.** Operates on fitness functions and chromosome mutations, but requires extensive parameter tuning (crossover/mutation rates) to work. |
  | **Adaptability to Rule Changes** | **Instant.** If a new rule is added (e.g., "Faculty can only teach up to 6 hours consecutive"), it is added as a single code constraint line. The solver adapts immediately. | **Requires Retraining.** Any changes to scheduling constraints require rebuilding, training, and redeploying the ML model. | **Requires Fitness Rewriting.** The entire fitness evaluation function must be manually re-engineered and re-tested. |
  | **Proof of Optimality** | **Yes.** CP-SAT can prove that no better schedule exists under the given constraints, or prove that a schedule is mathematically impossible (infeasible). | **No.** ML outputs a single generated recommendation with no measure of mathematical optimality or feasibility. | **No.** GAs stop after a set number of generations, with no proof of whether a better schedule exists. |

### 5.3 Brevo SMTP Transactional Mail
* **Technical Flow:** TALA uses Laravel's mail configuration. The live configuration currently resolves `mail.default` to `smtp`. Mail or notification jobs use the configured Laravel queue connection, which is database-backed in the current baseline.
* **Authentication & DNS Requirements:** If Brevo or another SMTP carrier is used, the client-owned sender domain must be authenticated with SPF, DKIM, and DMARC DNS records before production mail is trusted.

---

## 6. Deployment Architecture & Hosting Cost Estimates

To minimize monthly operating costs while securing system stability under heavy computation, the candidate production deployment uses a **Hybrid Deployment Architecture** spanning a primary VPS and a dedicated serverless worker on **Google Cloud Run**. This section is a deployment target, not proof that every listed service is active in the current development environment.

### The Deployed State (Hybrid Deployment Diagram)

```text
┌─────────────────────────────────────────────────────────────┐
│                 DIGITALOCEAN DROPLET (VPS)                  │
│             Standard 2GB RAM / 1 vCPU / 50GB SSD            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   ┌──────────────────────┐       ┌──────────────────────┐   │
│   │    Web Server        │       │ Laravel Queue/Cache  │   │
│   │   (Nginx + PHP-FPM)  │       │  Store (DB current)  │   │
│   └──────────┬───────────┘       └──────────┬───────────┘   │
│              │                              │               │
│              ▼                              ▼               │
│   ┌──────────────────────┐       ┌──────────────────────┐   │
│   │    Laravel 12 App    │◄─────►│  MySQL 8.0 Database  │   │
│   │  (TALA Monolith Code)│       │  (Local Data Storage)│   │
│   └──────────┬───────────┘       └──────────────────────┘   │
│              │                                              │
└──────────────┼──────────────────────────────────────────────┘
               │
               │ (Authenticated REST HTTPS over TLS)
               ▼
┌─────────────────────────────────────────────────────────────┐
│             GOOGLE CLOUD RUN (Serverless Node)              │
│               1 GiB RAM / 1 vCPU / Capped 300s              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   ┌─────────────────────────────────────────────────────┐   │
│   │             CP-SAT Scheduler Engine                 │   │
│   │       (Python Docker Container: OR-Tools)           │   │
│   └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 6.2 Deployment & Hosting Justifications

The hybrid structure is selected based on a strict balance of cost, resource isolation, and operational simplicity:

#### 1. Primary Web Host: Single DigitalOcean Droplet vs. Multi-Server Managed Cloud (e.g., AWS Elastic Beanstalk + RDS + ElastiCache)
* **Selected candidate:** A single standard 2GB RAM DigitalOcean Droplet hosting the web server, Laravel app, and MySQL database, with queue/cache storage matched to the approved deployment configuration.
* **Counterpart:** Fully managed multi-server AWS/GCP stack (EC2 for web, RDS for database, ElastiCache for Redis).
* **Comparative Justification:**
  * **Cost Efficiency:** For a local school like SIA (with class section sizes capped at 100 students), a multi-server setup is likely over-provisioned for v1. The single-node candidate keeps the deployment simple while the rescue baseline proves the SIS lifecycle.
  * **Network Latency:** In a multi-server setup, database and cache calls cross the internal network. Co-locating the app and database for v1 reduces moving parts and request latency. Redis can be added later only if the approved deployment requires it.
  * **Backups and Recovery:** Instead of paying for expensive managed database replica instances, TALA utilizes automated daily database snapshots backed up to **DigitalOcean Spaces** (₱292.50/month), ensuring a point-in-time recovery strategy at a fraction of the cost.

#### 2. Solver Hosting: Google Cloud Run vs. Local Docker Engine or AWS Lambda
* **Selected:** Google Cloud Run (Serverless Container).
* **Counterpart:** Local Docker Engine (running the solver container on the same Droplet) or AWS Lambda (Serverless Functions).
* **Comparative Justification:**
  * **Protection Against Out-of-Memory (OOM) Crashes:** As discussed in Section 2, the CP-SAT scheduling solver is highly CPU/RAM intensive. If run locally on the 2GB RAM Droplet, a single scheduling optimization run would starve the Nginx and MySQL processes, causing them to crash. Cloud Run isolates this compute load completely.
  * **Container Support vs. AWS Lambda:** AWS Lambda has rigid runtime limitations and limits deployment packages to 250MB (unzipped). Packaging Python, NumPy, Flask, and the C++-compiled Google OR-Tools binary is extremely difficult under these constraints. Google Cloud Run executes standard Docker containers up to 32GB RAM, allowing us to package the OR-Tools Python server cleanly with zero platform restrictions.
  * **Execution Limits:** AWS Lambda has a hard timeout limit of 15 minutes, whereas Google Cloud Run supports request timeouts up to 60 minutes. While TALA caps solver timeout in the configuration at 300 seconds (5 minutes), Cloud Run provides the necessary execution runway for complex timetabling constraints.


### Estimated Operating Costs (in Philippine Peso - PHP)
*Exchange Rate Reference: 1.00 USD = 58.50 PHP*

| Cost Item | Cloud Service / Integration | Configuration / Specs | Cost (PHP / Monthly) | Source / Reference |
| :--- | :--- | :--- | :--- | :--- |
| **Primary Web Host** | DigitalOcean Droplet | Standard Node (1 vCPU, 2 GiB RAM, 50 GiB SSD) | **₱702.00** ($12/mo) | [DigitalOcean Droplet pricing](https://www.digitalocean.com/pricing/droplets) |
| **Queue / Cache Store** | Laravel database store baseline | Uses application database unless Redis is approved later | **₱0.00** incremental | Hosted in application stack |
| **Local Database** | MySQL 8.0 (Local Service) | Runs inside the Droplet with automated daily backups | **₱0.00** | Hosted locally |
| **Isolated Solver** | Google Cloud Run | Serverless Container (1 vCPU, 1 GiB RAM, scales to zero) | **₱0.00** (Free Tier) | [Cloud Run pricing](https://cloud.google.com/run/pricing) |
| **Backups Storage** | DigitalOcean Spaces | 250 GiB backup storage for database snapshots | **₱292.50** ($5/mo) | [DigitalOcean Spaces pricing](https://www.digitalocean.com/pricing/spaces) |
| **Email Delivery** | Brevo SMTP | Free Tier (300 emails/day, 9,000/month) | **₱0.00** (Free) | [Brevo pricing](https://www.brevo.com/pricing/) |
| **GCash Payments** | PayMongo Gateway | Pay-as-you-go (2.23% transaction fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Maya Payments** | PayMongo Gateway | Pay-as-you-go (1.79% transaction fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Card Payments** | PayMongo Gateway | Pay-as-you-go (3.125% + ₱13.39 card fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Custom Domain** | PHNET Registry | Official `.edu.ph` domain for educational institutions | **₱33.33** (₱400/year) | [PHNET Registry](https://services.ph.net) |
| **TOTAL BASE COST** | | **Hosting, Backups, Compute, & Domain** | **₱1,027.83 / month** (₱994.50/mo host + ₱400.00/yr domain) | |

Cost figures are planning placeholders from the architecture draft and must be refreshed from current vendor pricing before procurement or deployment approval.


---

### How the Client Saves Money (The Value Proposition)

Traditional academic system deployments often cost schools ₱15,000 to ₱30,000 per month due to over-provisioned managed databases, separate queue servers, and multi-cloud container hosting. TALA targets a low fixed base cost while preserving core reliability through three strategies:

1. **The Single-Droplet Stack:**
   SIA is a local school with a small student ceiling (defaults to 100 active enrollees per section). A modern 2 GiB RAM VPS can host the Laravel app and MySQL for the initial deployment target. The database-backed queue/cache baseline avoids a separate cache server until workload evidence justifies Redis.
2. **Serverless Solver Offloading:**
   The CP-SAT solver is only used during pre-semester academic setup. Offloading this compute-heavy task to Google Cloud Run avoids paying for an idle, high-resource server or risking Droplet OOM crashes. Thanks to Google Cloud Run's generous monthly free tier (2 million requests, 180,000 vCPU-seconds), the school's periodic timetabling runs fall well within the free allocation, resulting in a base cost of exactly ₱0.00.
3. **Generous Free-Tier Management:**
   By selecting integrations like Brevo SMTP (free 300 emails/day) for communications, the school pays ₱0.00 for normal operational volumes. PayMongo gateway costs are variable and only deducted from actual student tuition transactions, ensuring no fixed overhead.

---

## 7. Sources and References

1. **Codebase Package Declarations:**
   * `/composer.json` (PHP package dependencies)
   * `/package.json` (Vite, Tailwind, Alpine, and frontend package dependencies)
2. **Payment Gateway Pricing:**
   * PayMongo Transaction Fee Schedules (GCash, Maya, and Visa/Mastercard rates)
3. **Cloud Infrastructure Pricing:**
   * DigitalOcean VPS Droplet and Object Storage (Spaces) official monthly pricing sheets
4. **SaaS API & Domain Allowance Schedules:**
   * Brevo SMTP standard daily transactional email quotas
   * PHNET Registry official `.edu.ph` domain registration guidelines and fee schedules
