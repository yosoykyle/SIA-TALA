# TALA System Architecture Specification

## 1. Executive Summary & System Title

**TALA** (Total Academic Lifecycle Automation System) is the unified school information and administration system designed for Servitech Institute Asia (SIA). The title "TALA" (Filipino for _Star/Guide_) reflects the system's role as the central source of truth for academic management.

TALA replaces fragmented legacy processes with a unified digital command center for staff (Registrar, Accounting, Faculty) and role-scoped authenticated workspaces for applicants and students, ensuring streamlined operations from applicant intake to final grade release.

---

## 2. System Architecture: Monolithic Modular

TALA is built as a **Monolithic Modular** web application. Under this architecture, all functional modules (Admissions, Academic Setup, Term Offerings, Scheduling, Enrollment, Finance/Ledger, COR, Grades, Student Hub, Admin Reports) share a single codebase, a single configuration, and a single database instance.

### System Architecture Diagram
The diagram below illustrates the system structure, clearly separating the core internal components of the TALA monolith from the external integrations and infrastructure:

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                     TALA SYSTEM AS A WHOLE                             │
├────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                        │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │                 PRIMARY TALA MONOLITH (Internal Droplet Stack)                 │   │
│   ├────────────────────────────────────────────────────────────────────────────────┤   │
│   │                                                                                │   │
│   │   ┌───────────────────────────┐                ┌───────────────────────────┐   │   │
│   │   │     Student Hub UI        │                │      Admin Nexus UI       │   │   │
│   │   │    FilamentPHP Panels     │                │    (FilamentPHP Panels)   │   │   │
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
│   │                        │ (Models, State Machines, Jobs)│                       │   │
│   │                        └───────────────┬───────────────┘                       │   │
│   │                                        │                                       │   │
│   │                 ┌──────────────────────┴─────────────────────┐                 │   │
│   │                 ▼                                            ▼                 │   │
│   │   ┌───────────────────────────┐                ┌───────────────────────────┐   │   │
│   │   │    Local MySQL Database   │                │   Local Redis Caching     │   │   │
│   │   │       (Data Memory)       │                │   & Horizon Queue Runner  │   │   │
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
│   │   │     (Payment Gateway)     │                │       (Brevo Email)       │   │   │
│   │   └───────────────────────────┘                └───────────────────────────┘   │   │
│   └────────────────────────────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### Architectural Component Divisions
1. **Part of the System as a Whole (Internal Droplet Stack):**
   * **Student Hub & Admin Nexus UIs:** The frontend user interfaces, compiled via Vite, running on the server.
   * **Laravel 12 Business Core:** The PHP codebase containing model definitions, Spatie state machines, and business logic.
   * **Local Database (MySQL 8.0) & Cache (Redis):** The relational storage and queue/session caching systems.
2. **Dedicated Isolated Infrastructure:**
   * **CP-SAT Scheduler Engine (Google Cloud Run):** The deterministic Google OR-Tools CP-SAT scheduling solver, packaged as a lightweight Python Docker container. To prevent server resources from starving, it is hosted externally on Google Cloud Run and invoked securely via authenticated HTTPS requests.
3. **Outside Integrations (External SaaS/APIs):**
   * **PayMongo:** Processes credit cards, GCash, and Maya checkout screens. It communicates with TALA asynchronously using signed HTTP webhooks.
   * **SMTP Server:** An external transactional mail carrier (like Brevo) that delivers system emails (requires a custom sender domain with SPF, DKIM, and DMARC authentication).

### 2.2 Detailed Architectural Justifications & Counterpart Comparisons

To establish a solid theoretical and practical foundation for TALA, each core architectural choice is justified below against its primary industry alternative:

#### 1. Core Architecture Pattern: Monolithic Modular vs. Microservices
* **Selected:** Monolithic Modular (Nginx + PHP + MySQL + Redis on a single node).
* **Counterpart:** Microservices (separate containers for Admissions, Scheduling, Finance, etc.).
* **Comparative Justification:**
  * **Consistency vs. Complexity:** TALA manages high-stakes student registrations and financial ledgers. Relational consistency (ACID) is trivial in a monolith. In a microservices architecture, keeping the Accounting database in sync with the Registrar database requires complex distributed consensus patterns (e.g., Saga or two-phase commit), increasing failure points.
  * **Resource Overhead:** Microservices require separate host containers, API gateways, service discovery, and database instances. Running microservices would easily exceed the resource capacity of a 2GB RAM node and drive up hosting costs. Monolithic modularity maintains logical separation (module boundaries in PHP namespaces) while sharing a single database connection and RAM pool, keeping running costs under ₱1,000/mo.

#### 2. Database System: Relational (MySQL 8.0) vs. Document-Store NoSQL (MongoDB)
* **Selected:** MySQL 8.0 (Relational Database Service).
* **Counterpart:** MongoDB (NoSQL Document Store).
* **Comparative Justification:**
  * **Strict Data Integrity:** School registration, course prereqs, and student ledgers are deeply relational. MySQL enforces strict foreign key constraints and schema rules (e.g., preventing a student from enrolling in a subject without the required curriculum binding).
  * **ACID Transactions:** Financial ledger postings (debits/credits) require strict atomicity. MongoDB's document model lacks native relational integrity constraints and can suffer from data anomalies (e.g., orphan records, inconsistent accounting balances) if schema validations are bypassed at the application level.

#### 3. Queue & Cache Store: In-Memory Redis vs. Database-Driven Queues
* **Selected:** Local Redis + Laravel Horizon.
* **Counterpart:** Database Queue Driver (using the primary MySQL database for queue tasks).
* **Comparative Justification:**
  * **Database Disk I/O Starvation:** In a 2GB RAM Droplet, disk read/write bandwidth is limited. If the system uses a database queue driver, checking for pending jobs (such as email alerts or webhook processing) results in continuous read/write polling queries. This starves the database of disk I/O, slowing down active student checkouts.
  * **High-Concurrency Queue Performance:** Redis operates entirely in RAM, handling thousands of queue operations per second with zero disk latency. Paired with Laravel Horizon, it offers real-time queue dashboards and automated worker scaling out-of-the-box.


---

## 3. UI Framework Selections & Justifications

TALA implements a dual-stack UI approach tailored to administrative and student requirements. The selections are justified below against their alternatives:

### 3.1 Admin Nexus (Staff Workspace): FilamentPHP v5 vs. Custom React/Vue Admin Templates
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

---

## 4. Codebase Dependencies

TALA’s architecture relies on the following verified dependencies already configured in the codebase:

### 4.1 PHP Packages (composer.json)
* `laravel/framework: ^12.0` (Core Laravel 12 framework)
* `php: ^8.2` (PHP 8.2 runtime engine)
* `filament/filament: ^5.1` (Filament v5 Admin Panel builder)
* `livewire/livewire: ^4.0` (Livewire v4 reactive components)
* `laravel/fortify: ^1.37` (Secure login and password verification backend)
* `spatie/laravel-permission: ^6.24` (Role-based access control [RBAC])
* `spatie/laravel-model-states: ^2.8` (Checklist item and enrollment state transitions)
* `spatie/laravel-activitylog: ^4.8` & `pxlrbt/filament-activity-log: ^2.2` (Audit trail logs for overrides)
* `laravel/horizon: ^5.46` (Horizon async queue management)
* `chillerlan/php-qrcode: ^5.0` (Security-hashed QR codes for COR verification)
* `maatwebsite/excel: ^3.1` (Excel importing for curricula and exporting for grade reports)
* `luigel/laravel-paymongo: ^2.6` & `spatie/laravel-webhook-client: ^3.5` (PayMongo gateway wrapper & webhook listeners)
* `tallstackui/tallstackui: 3.0.0` (Premium UI layout components)

### 4.2 JavaScript Packages (package.json)
* `tailwindcss: ^4.0.0` & `@tailwindcss/vite: ^4.0.0` (Tailwind CSS v4 compiler)
* `alpinejs: ^3.15.10` (Client-side toggles, modals, and animations)
* `driver.js: ^1.4.0` (Interactive onboarding guides)
* `heroicons: ^2.2.0` (Frontend iconography)
* `xlsx: ^0.18.5` (Client-side spreadsheet validation)

---

## 5. System Integrations & Technicalities

### 5.1 PayMongo Payment Gateway
* **Technical Flow:** When a student initiates checkout (e.g. paying the enrollment downpayment), TALA calls the PayMongo API to create a checkout session. The student is redirected to PayMongo's secure GCash or Maya screen.
* **Callback:** Once payment completes, PayMongo sends an encrypted webhook payload to `spatie/laravel-webhook-client` on TALA. The system verifies the signature, posts the transaction, updates the ledger, and transitions the student's status to "Officially Enrolled".

### 5.2 CP-SAT Timetabling Solver (Google Cloud Run Container)
* **Technical Flow:** When the scheduling run is initiated, TALA aggregates `Scheduling Demand` records, rooms, and faculty schedules into an immutable JSON payload.
* **Execution:** TALA dispatches the request to the dedicated `tala-scheduler-solver` container deployed on Google Cloud Run. The connection is authenticated securely using Google service account IAM private keys (`scheduler-invoker.json`). The solver executes the integer-optimization model and returns candidate section-meeting patterns as a JSON payload back to TALA for review.

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
* **Technical Flow:** When system events occur (e.g. registration success, downpayment verified, COR issued, grades encoded), Laravel dispatches an email job to the Horizon Redis queue.
* **Authentication & DNS Requirements:** Brevo requires a **custom sender domain** owned by the client (e.g., `@servitech.edu.ph`). Free email addresses (like Gmail or Yahoo) are not allowed as sender addresses. To satisfy modern security requirements (specifically Google and Yahoo's February 2024 bulk sender guidelines), the client's custom domain must be authenticated with **SPF, DKIM, and DMARC** DNS TXT records.

---

## 6. Deployment Architecture & Hosting Cost Estimates

To minimize monthly operating costs while securing system stability under heavy computation, TALA uses a **Hybrid Deployment Architecture** spanning a primary **DigitalOcean Droplet** and a dedicated serverless worker on **Google Cloud Run**.

### The Deployed State (Hybrid Deployment Diagram)

```text
┌─────────────────────────────────────────────────────────────┐
│                 DIGITALOCEAN DROPLET (VPS)                  │
│             Standard 2GB RAM / 1 vCPU / 50GB SSD            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   ┌──────────────────────┐       ┌──────────────────────┐   │
│   │    Web Server        │       │   Redis Caching      │   │
│   │   (Nginx + PHP-FPM)  │       │  & Horizon Queues    │   │
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
* **Selected:** A single standard 2GB RAM DigitalOcean Droplet hosting the web server, MySQL database, and Redis cache.
* **Counterpart:** Fully managed multi-server AWS/GCP stack (EC2 for web, RDS for database, ElastiCache for Redis).
* **Comparative Justification:**
  * **Cost Efficiency:** Managed databases alone (like AWS RDS or DigitalOcean Managed DB) start at ~₱900/month, and ElastiCache adds another ~₱900/month. For a local school like SIA (with class section sizes capped at 100 students), a multi-server setup is severely over-provisioned. The single Droplet handles Nginx, PHP-FPM, MySQL, and Redis comfortably for **₱702.00/month**.
  * **Network Latency:** In a multi-server setup, every database query and cache lookup must cross the internal network between servers, introducing 1–3ms of network latency per call. By hosting MySQL and Redis locally on the same Droplet, queries connect via Unix sockets/localhost, achieving **zero network latency** and rendering Filament and Livewire pages much faster.
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
| **Local Cache** | Redis (Local Service) | Runs inside the Droplet (0-latency RAM allocation) | **₱0.00** | Hosted locally |
| **Local Database** | MySQL 8.0 (Local Service) | Runs inside the Droplet with automated daily backups | **₱0.00** | Hosted locally |
| **Isolated Solver** | Google Cloud Run | Serverless Container (1 vCPU, 1 GiB RAM, scales to zero) | **₱0.00** (Free Tier) | [Cloud Run pricing](https://cloud.google.com/run/pricing) |
| **Backups Storage** | DigitalOcean Spaces | 250 GiB backup storage for database snapshots | **₱292.50** ($5/mo) | [DigitalOcean Spaces pricing](https://www.digitalocean.com/pricing/spaces) |
| **Email Delivery** | Brevo SMTP | Free Tier (300 emails/day, 9,000/month) | **₱0.00** (Free) | [Brevo pricing](https://www.brevo.com/pricing/) |
| **GCash Payments** | PayMongo Gateway | Pay-as-you-go (2.23% transaction fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Maya Payments** | PayMongo Gateway | Pay-as-you-go (1.79% transaction fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Card Payments** | PayMongo Gateway | Pay-as-you-go (3.125% + ₱13.39 card fee) | **Variable** (₱0.00 base) | [PayMongo rates](https://www.paymongo.com/pricing) |
| **Custom Domain** | PHNET Registry | Official `.edu.ph` domain for educational institutions | **₱33.33** (₱400/year) | [PHNET Registry](https://services.ph.net) |
| **TOTAL BASE COST** | | **Hosting, Backups, Compute, & Domain** | **₱1,027.83 / month** (₱994.50/mo host + ₱400.00/yr domain) | |


---

### How the Client Saves Money (The Value Proposition)

Traditional academic system deployments often cost schools ₱15,000 to ₱30,000 per month due to over-provisioned "managed" cloud databases, separate queue servers, and multi-cloud container hosting. TALA reduces this cost to **under ₱1,000 per month** while preserving enterprise-grade reliability through three strategies:

1. **The Single-Droplet Stack:**
   SIA is a local school with a small student ceiling (defaults to 100 active enrollees per section). A modern 2 GiB RAM DigitalOcean server can comfortably handle several hundred concurrent web request transactions. Hosting Nginx, PHP, MySQL, and Redis on the same Droplet removes all cross-network latency, making page loads incredibly fast while eliminating separate DB/Cache server bills.
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
