<?php

use App\Enums\RetentionCategory;

/**
 * TAL-92E: retention category reference map.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7.1–13.7.3.
 * This is read-only reference data (not a database table) mapping each PRD
 * record type to its `App\Enums\RetentionCategory`. Exact retention
 * *periods* remain institution-configured policy values per §13.7.4 rule 6
 * and are out of scope for this file and this slice.
 *
 * @return array{
 *     record_types: array<string, string>,
 * }
 */
return [
    'record_types' => [
        // §13.7.1 Permanent or Long-Term Records
        'student_profile' => RetentionCategory::Permanent->value,
        'student_number' => RetentionCategory::Permanent->value,
        'enrollment_records' => RetentionCategory::Permanent->value,
        'cor_print_logs' => RetentionCategory::Permanent->value,
        'final_grades' => RetentionCategory::Permanent->value,
        'grade_correction_history' => RetentionCategory::Permanent->value,
        'academic_history' => RetentionCategory::Permanent->value,
        'curriculum_assignment' => RetentionCategory::Permanent->value,
        'graduation_completion_eligibility_snapshots' => RetentionCategory::Permanent->value,
        'ledger_summary_and_official_finance_history' => RetentionCategory::Permanent->value,
        'student_status_transitions' => RetentionCategory::Permanent->value,
        'student_lifecycle_change_results' => RetentionCategory::Permanent->value,

        // §13.7.2 Archive After Active Use Plus Institutional Review Period
        'applicant_records' => RetentionCategory::ArchiveAfterReview->value,
        'admission_evidence' => RetentionCategory::ArchiveAfterReview->value,
        'retention_document_tracking' => RetentionCategory::ArchiveAfterReview->value,
        'holds' => RetentionCategory::ArchiveAfterReview->value,
        'payment_evidence' => RetentionCategory::ArchiveAfterReview->value,
        'financial_accommodation_records' => RetentionCategory::ArchiveAfterReview->value,
        'soa_payment_acknowledgement_logs' => RetentionCategory::ArchiveAfterReview->value,
        'irregular_scheduling_notes' => RetentionCategory::ArchiveAfterReview->value,

        // §13.7.3 Shorter Operational Retention
        'login_session_logs' => RetentionCategory::ShortOperational->value,
        'raw_webhook_payloads' => RetentionCategory::ShortOperational->value,
        'temporary_uploads' => RetentionCategory::ShortOperational->value,
        'failed_import_files' => RetentionCategory::ShortOperational->value,
        'draft_curriculum_uploads' => RetentionCategory::ShortOperational->value,
        'rejected_duplicate_files' => RetentionCategory::ShortOperational->value,
        'solver_temporary_payloads' => RetentionCategory::ShortOperational->value,
    ],
];
