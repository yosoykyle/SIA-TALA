# TALA System Architecture Diagrams

> **Archived duplicate architecture presentation — not current architecture authority.** The live Architecture Specification owns system and integration boundaries.

This document contains Mermaid diagrams illustrating the system architecture based on the Product Requirements Document (PRD) modules and technical dependencies, with a special focus on external integrations as requested.

## 1. Overall System Architecture
This diagram demonstrates how the TALA monolithic application connects to its external, separated integrations. As your project manager noted, external services like PayMongo and Google Cloud Run must be depicted as distinct entities outside your main server infrastructure.

```mermaid
flowchart TB
    subgraph "External Integrations (SaaS / APIs)"
        PM["PayMongo\nPayment Gateway"]
        SMTP["Brevo / SMTP\nMail Server"]
    end

    subgraph "Dedicated Infrastructure (Google Cloud Run)"
        CPSAT["CP-SAT Scheduler Engine\nPython Docker Container"]
    end

    subgraph "TALA Primary Monolith (DigitalOcean VPS)"
        subgraph "Application Layer"
            UI["Blade / Livewire / Filament Panels\n(Public & Authenticated Workspaces)"]
            Core["Laravel 12 Business Core\n(Models, Jobs, Policies, TALA Modules)"]
            UI <--> Core
        end

        subgraph "Data Layer"
            DB[("MySQL 8.0\nDatabase")]
            Cache[("Queue / Cache\nDatabase Store")]
            Core <--> DB
            Core <--> Cache
        end
    end

    %% Connections
    Core -- "HTTPS REST API\n(Checkout)" --> PM
    PM -- "Signed Webhooks\n(Async Updates)" --> Core

    Core -- "Authenticated HTTPS\n(Google IAM Auth)" --> CPSAT
    CPSAT -- "JSON Schedule Payload" --> Core

    Core -- "SMTP" --> SMTP
```

---

## 2. CP-SAT Integration (Google Cloud Run)
This sequence diagram shows exactly how the TALA system communicates with the isolated CP-SAT solver. Because it is highly resource-intensive, it runs in a separate Google Cloud Run container and is invoked via secure HTTP requests.

```mermaid
sequenceDiagram
    autonumber
    participant T as TALA Monolith (Laravel)
    participant IAM as Google IAM
    participant CR as Google Cloud Run (CP-SAT)

    T->>T: Aggregate Scheduling Demand (Rooms, Faculty, Sections) into JSON
    Note over T, IAM: Authentication Phase
    T->>IAM: Request OAuth Token using Service Account Key
    IAM-->>T: Return Signed JWT Token
    Note over T, CR: Execution Phase
    T->>CR: POST /solve (with JSON Payload & JWT Bearer Token)
    activate CR
    CR->>CR: Verify Token & Execute Integer Optimization Model
    CR-->>T: Return Candidate Schedule (JSON)
    deactivate CR
    T->>T: Parse Schedule & Prompt User for Review/Approval
```

---

## 3. PayMongo Payment Gateway Integration
This sequence diagram illustrates the lifecycle of a payment. It emphasizes the two-part nature of modern payment gateways: the synchronous redirect for the user, and the asynchronous webhook that actually updates the accounting ledger.

```mermaid
sequenceDiagram
    autonumber
    participant S as Student / Payer
    participant T as TALA Monolith (Laravel)
    participant PM as PayMongo Gateway

    Note over S, PM: Synchronous Checkout Flow
    S->>T: Click "Pay Now" on Assessment in Student Hub
    T->>T: Evaluate Finance Rules & Calculate Amount Due
    T->>PM: Create Checkout Session (API Request)
    PM-->>T: Return Checkout URL & Session ID
    T-->>S: Redirect Browser to PayMongo Checkout
    S->>PM: Enter Payment Details (GCash, Maya, Card)
    PM-->>S: Redirect back to TALA Success/Informational Page

    Note over T, PM: Asynchronous Webhook Flow (Source of Truth)
    PM->>T: POST /api/webhooks/paymongo (e.g., payment.paid event)
    T->>T: Verify PayMongo Webhook Signature (Security Check)
    T->>T: Dispatch Laravel Background Job
    T->>T: Post Idempotent Ledger Entry (Credit to Student Account)
```
