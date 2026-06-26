# Finalized Codebase Reconciliation Plan: Current Backend vs. Simplified PRD

This document records the finalized design decisions and architectural strategies agreed upon during the `/grill-with-docs` reconciliation session. It serves as the definitive reference for refactoring the TALA codebase to align with the simplified `prd_modules` specifications.

---

## 1. Unified Checklist System (Module 3 & 11)
*   **Strategy:** Implement a single polymorphic `ChecklistItem` model mapped to a `checklist_items` table.
*   **Polymorphic Scope:** Can belong to either `App\Models\ApplicantIntake` or `App\Models\StudentProfile` via `owner_type` and `owner_id`.
*   **Upfront Upload:** `ApplicantIntake` stores the singular digital file upfront in `identity_document_url`. Upon submission, this automatically initializes an `"Identity Document"` checklist item with `status = 'received_digital'`, `evidence_method = 'digital_upload'`, and `blocking_level = 'blocks_handover'`.
*   **Checklist Statuses:** `pending`, `received_physical`, `received_digital`, `accepted`, `rejected`, `waived`, `undertaking_approved`.

---

## 2. Explicit Holds & Promissory Note Bypass (Module 11)
*   **Strategy:** Create a central, transaction-oriented `holds` table containing explicit records of all active and historical student holds.
*   **Columns:** `student_profile_id`, `hold_type`, `blocking_level`, `status` (`active`, `resolved`, `waived`), `reason`, `student_message`, polymorphic `source_type`/`source_id` (linking back to the specific unpaid ledger entry or missing document requirement), and audit stamps.
*   **Promissory Note Update:**
    *   Add `evidence_url` for a single combined parent ID + proof of income upload.
    *   Add dual-boolean review columns: `registrar_approved` and `accounting_head_approved`. When both are true, `status` becomes `active`.
    *   **MySQL Limit Guard:** Enforce "at most one active promissory note per academic year" using a virtual column (`active_year_index INT GENERATED ALWAYS AS (IF(status = 'active', academic_year_id, NULL)) VIRTUAL`) and a unique index.
*   **Dynamic Bypass Logic:** The `Financial Hold` remains active in the database (preserving history), but when checking `hasBlockingHold('Blocks Enrollment')`, the student profile dynamically ignores it if an active promissory note exists.

---

## 3. Official Receipt (OR) Mapping & Ledger (Module 8)
*   **Strategy:** Map the physical cashier-issued OR to the `payments` table (not directly to ledger entry lines) to prevent database redundancy and data synchronization issues.
*   **Columns:** Add `or_number` and `or_attachment_path` directly to the `payments` table.
*   **Split Payments (Lump-Sum):** If one cashier payment covers multiple ledger credits, a single `Payment` record holds the OR number, and multiple `LedgerEntry` rows link to this payment record ID.
*   **Pending Queue:** The Accounting Workspace "Pending OR Mapping" queue queries `payments` where `status = 'confirmed' AND or_number IS NULL`.

---

## 4. Delayed Payment Surcharge (Module 8)
*   **Strategy:** Reuse the existing rule-based `InstallmentPolicyService` engine, but configure it simply to comply with RA 11984.
*   **Default Configuration:** Set `penalty_frequency = 'one_time'` and `penalty_rate = 0.05` (5%) on the active policy.
*   **Labeling:** Update the automated description builder to output `"Late Penalty - [Milestone Name]"` (e.g., `"Late Penalty - Midterm Installment"`).
*   **Compliance:** Verify that no database or service rule automatically blocks exam access or permit downloads due to outstanding late penalties.

---

## 5. Lightweight Schedule Revisions (Module 6)
*   **Strategy:** Avoid cloning the entire schedule (which causes massive database bloat). Section meetings are edited **in-place** directly on the `section_meetings` table.
*   **Versioning:** Increment a `version_number` counter on the master `ScheduleGenerationRun` record for that term.
*   **Revision Log:** Insert a record into `schedule_revision_events` logging the specific change, reason, and a JSON snapshot of the old and new states (`old_snapshot_json` and `new_snapshot_json`).

---

## 6. Scoped Gate Overrides (Module 7)
*   **Strategy:** Create a `gate_overrides` table mapped at the **student-term-gate** level.
*   **Evaluation:** If a student fails an enrollment gate check, the gate service runs a lookup for an active override for that specific `gate_type` and `term_id`. If found and valid, the gate resolves as `Overridden`.

---

## 7. Migration & Verification Roadmap
*   [ ] Fix the 5 existing failing tests to resolve the deprecated shipping holds and routing conflicts.
*   [ ] Create database migrations for the new tables: `checklist_items`, `holds`, `duplicate_profile_resolutions`, `gate_overrides`, `schedule_revision_events`, `personal_data_corrections`.
*   [ ] Refactor the models: `ApplicantIntake`, `PromissoryNote`, `Payment`, `LedgerEntry`, `SectionMeeting`, `StudentProfile`.
*   [ ] Implement/refactor the service layers: `StudentEnrollmentService`, `InstallmentPolicyService`, `FacultyClassListService`.
*   [ ] Run the full test suite to verify 100% compliance.

---

## 8. Legacy Code Cleanup & Deprecation

To ensure the backend is not "dirty" with orphaned or duplicate logic, the following legacy components are slated for **removal or heavy refactoring** based on the PRD reconciliation:

### 8.1. Document Logic (Hybrid Dictionary Approach)
*   **Models to Delete:** `RetentionDocumentUndertaking`, `ApplicantDocumentRequirement`.
*   **Models to Keep:** `DocumentRequirementItem`, `AdmissionRequirementPolicy` (as admin dictionaries).
*   **Services to Refactor/Gut:** `ApplicantIntakeService`, `DocumentUploadReviewService`, `AdmissionFinanceReadinessGateService`, `RetentionDocumentUndertakingService` (Delete).
*   **Replacement:** A single polymorphic `checklist_items` table that links directly to the `Applicant` or `StudentProfile`.
*   **Rationale:** We retain the mature pattern of dynamic document configuration via dictionary tables, but replace the complex, duplicate tracking tables with the PRD's unified, polymorphic `ChecklistItem` system.

### 8.2. Scheduling Logic (Hybrid Solver Sandbox & In-Place Live)
*   **Models to Keep/Refactor:** `ScheduleDraftRow` (Rename to `CandidateScheduleRow`).
*   **Phase 1: The Solver Sandbox (Pre-Enrollment):** Because we are using an automated CP-SAT solver, we *cannot* push AI-generated schedules directly to live. The solver must output to `CandidateScheduleRow`. The Registrar reviews this in a "Solver Dashboard" (similar to mature systems like UniTime). Once approved, it is pushed to the live `section_meetings`.
*   **Phase 2: Absolute Simplicity (Post-Publication):** Once published and the Enrollment Calendar starts, the schedule is locked. Any mid-term emergency changes (e.g., room flooded) are done as in-place updates directly on `section_meetings`, backed by the `schedule_revision_events` log. No drafts needed mid-term.

### 8.3. Hardcoded Holds (Replaced by Central Holds Table)
*   **Services to Refactor:** `FacultyClassListService` (remove `hasActiveFinancialHold` and `hasPromissoryHold`) and `StudentDashboardService`.
*   **Services to Delete:** `ExamAccessAccommodationService` (Completely obsolete under RA 11984; exams are never blocked by finances, so no "accommodation" workflow is needed).
*   **Rationale:** Hardcoded checks must be replaced by a single query against the new `holds` table, integrating the dynamic promissory note bypass logic. 

### 8.4. Legacy Data Migration (The Clean Slate)
*   **Decision:** The system will adopt a **Clean Slate** approach for legacy data related to deprecated tables (e.g., `RetentionDocumentUndertaking`, old financial holds). 
*   **Rationale:** Because this is an unfinished system being rescued and rewritten for a new version, there is no value in writing complex mathematical data migration scripts to preserve old state. The system will start fresh for the upcoming term. Staff will handle any carry-over delinquency manually using the new PRD features.
