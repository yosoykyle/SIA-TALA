# TAL-53 Backend Schema Reconciliation Inventory

## Scope and authority

This is the working artifact for **TAL-53 - Backend Schema Reconciliation Inventory**. It is inventory and planning evidence only. It does not authorize backend refactoring, migration edits, UI work, test deletion, dependency changes, tracker updates, or broad repair.

Authority was applied in this order:

1. `AGENTS.md`.
2. `00_Project_Documents/TALA-Rescue-Next-Steps.md` and `00_Project_Documents/TALA-Local-Linear-Sync-Tracker.md`.
3. `00_Project_Documents/prd_modules/README.md` and PRD modules `01` through `13`.
4. `00_Project_Documents/prd_modules/_working/TAL-50-backend-salvage-ledger.md`.
5. `00_Project_Documents/prd_modules/_working/TAL-51-clean-mvp-erd-and-schema-contract.md`.
6. Current migration source from TAL-52.
7. Existing PHP, Filament, factories, and tests as salvage inventory only.

Laravel Boost was available. `application_info` reported PHP 8.2, Laravel 12.62.0, Filament 5.6.7, Livewire 4.3.1, PHPUnit 11.5.55, MySQL, and the expected package set. `search_docs` was run before this artifact was written. Boost `database_schema(summary=true)` still reflected the pre-TAL-52 live development database, so it was used only as contrast evidence; the compatibility target for this inventory is the TAL-52 migration source.

## Current backend compatibility summary

The TAL-52 migration source contains exactly **18 migration files** and creates **74 tables**: **57 application-owned tables** and **17 platform/foundation tables**. The table names match the TAL-51 clean MVP inventory.

Static backend compatibility is currently **low** outside the accepted Foundation/Auth and new schema-conformance surface. The migration baseline is clean, but most legacy domain PHP still targets removed tables, renamed models, or materially changed columns. The app should be treated as follows:

| Area | Compatibility with TAL-52 baseline | Disposition |
| --- | --- | --- |
| Laravel/Fortify auth, Spatie RBAC, panels, users, roles, permission package tables | Mostly compatible with platform/foundation tables. | **Keep as-is**, then adapt only permission catalog and page/action authorization when domain slices resume. |
| New schema-conformance test surface | Compatible with TAL-52 source. `payment_allocations.prior_balance_ledger_entry_id` was verified in the migration source after parser output initially missed `unsignedBigInteger`. | **Keep as-is** as TAL-52 proof surface. |
| Applicant intake shell and accepted auth/applicant tests | Partially compatible. Existing intake code still uses removed/changed applicant fields and legacy document tables. | **Adapt** under an admissions/student-master slice. |
| Most legacy models, services, Filament resources, factories, and tests | Not compatible. Many default Eloquent table names no longer exist after TAL-52. | **Delete/defer or adapt**, depending on PRD value and upstream dependency order. |

Inventory scale:

- Candidate backend/test/factory/admin files inspected by path inventory: **523**.
- Files matched by stale table/column scan: **177**.
- Current migration-source table count: **74**.
- Tests were not run for TAL-53; this task is static inventory and no repair was performed.

## TAL-52 migration baseline used as target

Application-owned TAL-52 tables:

`academic_years`, `admission_requirement_policies`, `applicant_intakes`, `assessment_lines`, `assessments`, `calendar_events`, `candidate_schedule_rows`, `checklist_items`, `course_components`, `course_enrollments`, `course_requirements`, `course_specifications`, `courses`, `curriculum_entries`, `curriculum_versions`, `document_evidence`, `duplicate_profile_resolutions`, `enrollment_exceptions`, `enrollment_gate_results`, `enrollment_seat_reservations`, `enrollments`, `faculty_qualifications`, `faculty_term_load_overrides`, `fee_rules`, `financial_accommodations`, `grade_outcome_events`, `grade_roster_rows`, `grade_rosters`, `graduation_review_batches`, `graduation_review_members`, `graduation_snapshots`, `holds`, `import_batches`, `late_grade_authorizations`, `ledger_entries`, `operational_events`, `output_access_logs`, `payment_allocations`, `payment_attempts`, `payment_schedule_rows`, `payments`, `program_shift_credit_entries`, `programs`, `room_features`, `rooms`, `schedule_revision_events`, `schedule_runs`, `scheduling_demands`, `section_delivery_groups`, `section_meetings`, `sections`, `student_lifecycle_changes`, `student_profiles`, `student_schedule_bindings`, `system_settings`, `term_offerings`, `terms`.

Platform/foundation tables:

`activity_log`, `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `model_has_permissions`, `model_has_roles`, `passkeys`, `password_reset_tokens`, `permissions`, `role_has_permissions`, `roles`, `sessions`, `users`, `webhook_calls`.

## Exact legacy references found and disposition

### Admissions and student handover

Legacy table/model references found:

- `admission_offerings`: `app/Models/AdmissionOffering.php`, `app/Actions/Applicants/AdmissionRequirementResolver.php`, `app/Actions/Applicants/AdmissionRequirementResolution.php`, `app/Actions/Enrollment/AdmissionFinanceReadinessGateService.php`, `app/Filament/Resources/AdmissionOfferings/AdmissionOfferingResource.php`, `database/factories/AdmissionOfferingFactory.php`, `tests/Feature/ApplicantIntakeSubmissionTest.php`.
- `admission_capacity_plans`, `admission_capacity_reservations`: `app/Models/AdmissionCapacityPlan.php`, `app/Models/AdmissionCapacityReservation.php`, `app/Actions/Enrollment/AdmissionCapacityReservationService.php`, `app/Actions/Enrollment/AdmissionReadinessDashboardService.php`, `app/Actions/Enrollment/AdmissionFinanceReadinessGateService.php`, `app/Filament/Resources/AdmissionCapacityPlans/AdmissionCapacityPlanResource.php`, `database/factories/AdmissionCapacityPlanFactory.php`, `database/factories/AdmissionCapacityReservationFactory.php`.
- `document_requirement_items`, `document_uploads`: `app/Models/DocumentRequirementItem.php`, `app/Models/DocumentUpload.php`, `app/Actions/Applicants/ApplicantIntakeService.php`, `app/Actions/Registrar/DocumentUploadReviewService.php`, `app/Actions/Enrollment/StudentEnrollmentService.php`, `app/Filament/Resources/DocumentRequirementItems/DocumentRequirementItemResource.php`, `app/Filament/Resources/DocumentUploads/DocumentUploadResource.php`, `tests/Feature/ApplicantWorkspaceTest.php`.

Legacy/changed columns found:

- `applicant_intakes.lrn`, `year_level`, `preferred_modality`, `orientation_modality_acknowledged_at`, `identity_document_url`: `app/Models/ApplicantIntake.php`, `app/Actions/Applicants/ApplicantIntakeService.php`, `database/factories/ApplicantIntakeFactory.php`.
- `student_profiles.lrn`, `student_id`, `year_level`: `app/Actions/Enrollment/StudentEnrollmentService.php`, `tests/Feature/StudentHandoverChecklistHoldTest.php`.

Disposition: **Adapt** applicant intake and handover around TAL-52 `applicant_intakes`, `student_profiles`, `checklist_items`, `document_evidence`, and `duplicate_profile_resolutions`. **Delete/defer** admission offering/capacity CRUD and old document requirement/upload resources until the clean admission policy and student-master slice is implemented. Do not repair the dirty `PersonalDataCorrectionRequest` workflow in this slice; TAL-51 rejected student-initiated locked legal identity correction for MVP.

### Academic setup, courses, curriculum, and imports

Legacy table/model references found:

- `curriculums`, `curriculum_subjects`, `curriculum_readiness_scopes`: `app/Models/Curriculum.php`, `app/Models/CurriculumSubject.php`, `app/Models/CurriculumReadinessScope.php`, `app/Actions/AcademicFoundation/CurriculumScopeReadinessService.php`, `app/Actions/Imports/CurriculumImportService.php`, `app/Actions/Enrollment/SubjectSuggestionService.php`, `app/Observers/CurriculumSubjectObserver.php`, `app/Filament/Resources/Curriculums/CurriculumResource.php`, `database/factories/CurriculumFactory.php`, `database/factories/CurriculumSubjectFactory.php`, `database/factories/CurriculumReadinessScopeFactory.php`.
- `subjects`, `prerequisites`: `app/Models/Subject.php`, `app/Actions/Enrollment/SubjectSuggestionService.php`, `app/Actions/Imports/CurriculumImportService.php`, `app/Filament/Resources/Subjects/SubjectResource.php`, `database/factories/SubjectFactory.php`.

Legacy/changed columns found:

- `curriculum_id`, `subject_id`, `year_level`, `semester`, `weekly_contact_hours`, `academic_subject_type`, `scheduling_group`, `delivery_rule_override`: `app/Actions/Enrollment/SubjectSuggestionService.php`, `app/Observers/CurriculumSubjectObserver.php`, `app/Actions/Imports/CurriculumImportService.php`, `app/Filament/Resources/Curriculums/Schemas/CurriculumForm.php`.
- `curriculums.effective_year`, `version_name`, `is_active`, `activated_at`: `app/Actions/Imports/CurriculumImportService.php`, `app/Filament/Resources/Curriculums/Tables/CurriculumsTable.php`.

Disposition: **Replace/adapt**. Retain useful import validation and readiness concepts only as salvage. Rebuild against `courses`, `course_specifications`, `course_components`, `course_requirements`, `curriculum_versions`, `curriculum_entries`, and `import_batches`. Defer old Subject/Curriculum CRUD repair; it should be replaced by the clean Academic/Curriculum foundation slice.

### Term offerings, resources, and scheduling

Legacy table/model references found:

- `delivery_patterns`: `app/Models/DeliveryPattern.php`, `app/Actions/Scheduling/DeliveryPatternService.php`, `app/Filament/Resources/DeliveryPatterns/DeliveryPatternResource.php`, `database/factories/DeliveryPatternFactory.php`.
- `faculty_subject_eligibilities`: `app/Models/FacultySubjectEligibility.php`, `app/Actions/Scheduling/ScheduleSolverSnapshotService.php`, `app/Actions/Scheduling/TermSchedulingReadinessService.php`, `app/Actions/Scheduling/SectionMeetingAssignmentService.php`, `app/Filament/Resources/FacultySubjectEligibilities/FacultySubjectEligibilityResource.php`, `database/factories/FacultySubjectEligibilityFactory.php`.
- `faculty_availability_submissions`, `faculty_availability_windows`, `faculty_availability_periods`, `faculty_availability_change_requests`: `app/Models/FacultyAvailabilitySubmission.php`, `app/Models/FacultyAvailabilityWindow.php`, `app/Models/FacultyAvailabilityPeriod.php`, `app/Models/FacultyAvailabilityChangeRequest.php`, `app/Actions/Scheduling/FacultyAvailabilityService.php`, `app/Actions/Scheduling/FacultyAvailabilityChangeRequestService.php`, `app/Actions/Scheduling/ScheduleSolverSnapshotService.php`, corresponding factories and Filament resources.
- `schedule_generation_runs`: `app/Models/ScheduleGenerationRun.php`, `app/Jobs/ScheduleSolverDispatchJob.php`, `app/Actions/Scheduling/ScheduleGenerationService.php`, `app/Actions/Scheduling/ScheduleSolverSnapshotService.php`, `app/Actions/Scheduling/ScheduleCloudResultIngestor.php`, `app/Actions/Scheduling/SchedulePublishService.php`, `app/Filament/Resources/ScheduleGenerationRuns/ScheduleGenerationRunResource.php`.
- `section_teacher`: `app/Actions/Scheduling/SchedulePublishService.php`, `app/Actions/Grades/GradeSubmissionPackageService.php`, `app/Actions/Grades/GradeFinalizationService.php`, `app/Policies/SectionMeetingPolicy.php`, `app/Models/EnrollmentSubject.php`.

Legacy/changed columns found:

- `candidate_schedule_rows.generation_run_id`, `section_id`, `section_delivery_group_id`, `subject_id`, `faculty_id`, string `room`, `day_of_week`, `starts_at`, `ends_at`: `app/Models/CandidateScheduleRow.php`, `app/Actions/Scheduling/CandidateScheduleRowReviewService.php`, `app/Filament/Resources/ScheduleGenerationRuns/RelationManagers/CandidateRowsRelationManager.php`.
- `section_meetings.section_id`, `section_delivery_group_id`, `subject_id`, `faculty_id`, string `room`, `schedule_generation_run_id`: `app/Models/SectionMeeting.php`, `app/Actions/Scheduling/SectionMeetingAssignmentService.php`, `app/Actions/Scheduling/ScheduleSolverSnapshotService.php`, `app/Filament/Resources/SectionMeetings/*`.
- `sections.curriculum_id`, `year_level`, `curriculum_period`, `room`, `max_seats`, `enrolled_count`, `modality`: `app/Models/Section.php`, `app/Actions/Scheduling/SectionPlanningService.php`, `app/Filament/Resources/Sections/*`, `database/factories/SectionFactory.php`.

Disposition: **Adapt after Academic/Curriculum foundation and Term Offering source records exist**. Retain the Cloud Run client seam and queue dispatch concept. Replace payload generation around `term_offerings`, `scheduling_demands`, `schedule_runs`, `candidate_schedule_rows.schedule_run_id`, `section_meetings.schedule_run_id`, `room_id`, and stable demand IDs. Delete/defer old availability submission/change-request CRUD and `delivery_patterns`.

### Enrollment, gates, seat reservations, and faculty rosters

Legacy table/model references found:

- `enrollment_subjects`: `app/Models/EnrollmentSubject.php`, `app/Actions/Faculty/FacultyClassListService.php`, `app/Actions/Grades/GradeEncodingService.php`, `app/Actions/Grades/GradeFinalizationService.php`, `app/Actions/Grades/GradeSubmissionPackageService.php`, `app/Policies/EnrollmentSubjectPolicy.php`, `app/Filament/Resources/EnrollmentSubjects/EnrollmentSubjectResource.php`.
- Capacity/payment coupling through `admission_capacity_reservations.payment_id` and `ledger_entry_id`: `app/Actions/Enrollment/AdmissionCapacityReservationService.php`, `database/factories/AdmissionCapacityReservationFactory.php`.

Legacy/changed columns found:

- `enrollments.section_id`, `section_delivery_group_id`, `year_level`, `modality`: `app/Models/Enrollment.php`, `app/Actions/Enrollment/EnrollmentSectioningService.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Filament/Resources/Enrollments/*`, `database/factories/EnrollmentFactory.php`.
- `student_profiles.hard_copy_received`: `app/Actions/Enrollment/EnrollmentHardCopyReceiptService.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `database/factories/StudentProfileFactory.php`.

Disposition: **Replace/adapt**. Existing transaction boundaries and service names may be salvage, but backend logic must move to `enrollments`, `course_enrollments`, `student_schedule_bindings`, `enrollment_seat_reservations`, `enrollment_gate_results`, and `enrollment_exceptions`. Do not repair old `EnrollmentSubjectResource` or faculty roster queries before course enrollment and schedule binding sources are implemented.

### Finance, payment, ledger, and accommodations

Legacy table/model references found:

- `fee_templates`: `app/Models/FeeTemplate.php`, `app/Actions/Enrollment/EnrollmentAssessmentService.php`, `app/Actions/Finance/EnrollmentFinanceClearanceService.php`, `app/Filament/Resources/FeeTemplates/FeeTemplateResource.php`, `database/factories/FeeTemplateFactory.php`.
- `installment_policies`, `installment_policy_milestones`: `app/Models/InstallmentPolicy.php`, `app/Models/InstallmentPolicyMilestone.php`, `app/Actions/Finance/InstallmentPolicyService.php`, `app/Filament/Resources/InstallmentPolicies/*`, `app/Filament/Resources/InstallmentPolicyMilestones/*`.
- `promissory_notes`: `app/Models/PromissoryNote.php`, `app/Actions/Finance/PromissoryNoteLifecycleService.php`, `app/Actions/StudentLifecycle/HoldEvaluationService.php`, `app/Filament/Resources/PromissoryNotes/*`.
- `accounting_adjustments`: `app/Models/AccountingAdjustment.php`, `app/Actions/Finance/AccountingAdjustmentService.php`, `app/Filament/Resources/AccountingAdjustments/*`, `database/factories/AccountingAdjustmentFactory.php`.

Legacy/changed columns found:

- `student_profiles.current_balance`: `app/Actions/Finance/AccountingAdjustmentService.php`, `app/Actions/Finance/InstallmentPolicyService.php`, `app/Actions/Finance/PaymentConfirmationService.php`, `app/Actions/Finance/PromissoryNoteLifecycleService.php`, `app/Actions/Integrations/Payments/PayMongoWebhookProcessor.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Models/StudentProfile.php`, `database/factories/StudentProfileFactory.php`.
- `ledger_entries.entry_type`, `reference_type`, `reference_id`, `running_balance`: `app/Models/LedgerEntry.php`, `app/Actions/Finance/AccountingAdjustmentService.php`, `app/Actions/Finance/InstallmentPolicyService.php`, `app/Actions/Finance/PaymentConfirmationService.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Filament/Resources/LedgerEntries/*`, `database/factories/LedgerEntryFactory.php`.
- `payment_attempts.ledger_entry_id`, `provider_checkout_session_id`, `provider_payment_id`; `payments.ledger_entry_id`, `or_attachment_path`: `app/Models/PaymentAttempt.php`, `app/Models/Payment.php`, `app/Actions/Integrations/Payments/CreatePaymentCheckoutSession.php`, `app/Actions/Integrations/Payments/PayMongoWebhookProcessor.php`, `app/Console/Commands/CreatePayMongoSandboxCheckout.php`, `app/Console/Commands/VerifyPayMongoSandboxWebhookSmoke.php`, `app/Filament/Resources/PaymentAttempts/*`, `app/Filament/Resources/Payments/*`.

Disposition: **Adapt payment integration seam; replace legacy finance domain**. Keep `PaymentGateway`, PayMongo signature verification, webhook evidence, and queued processor as salvage. Rework posting around `assessments`, `assessment_lines`, `payment_attempts.assessment_id`, `payments.evidence_status/provider_reference`, `payment_allocations`, append-only `ledger_entries.direction/category/source_type/source_id`, and `financial_accommodations`. Delete/defer old fee template, installment policy, promissory note, and accounting adjustment CRUD until the finance slice lands.

### COR and official outputs

Legacy table/model references found:

- `cor_verifications`: `app/Models/CorVerification.php`, `app/Actions/Registrar/CorVerificationLifecycleService.php`, `app/Http/Controllers/CorVerificationController.php`, `app/Filament/Resources/CorVerifications/CorVerificationResource.php`, `app/Policies/CorVerificationPolicy.php`.

Disposition: **Delete/defer public verification**. TAL-51 excludes public token/QR COR verification from MVP. Retain only authenticated generated output concepts for a later output slice using `output_access_logs`.

### Grades and grade outputs

Legacy table/model references found:

- `grades`: `app/Models/Grade.php`, `app/Actions/Grades/GradeEncodingService.php`, `app/Actions/Grades/GradeFinalizationService.php`, `app/Actions/Grades/GradeCorrectionService.php`, `app/Actions/Grades/GradeSubmissionPackageService.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Filament/Resources/Grades/GradeResource.php`, `app/Filament/Student/Pages/GradesView.php`, `database/factories/GradeFactory.php`.
- `grade_corrections`: `app/Models/GradeCorrection.php`, `app/Actions/Grades/GradeCorrectionService.php`, `app/Filament/Resources/GradeCorrections/GradeCorrectionResource.php`, `database/factories/GradeCorrectionFactory.php`.
- `grade_submission_packages`, `grade_submission_package_items`: `app/Models/GradeSubmissionPackage.php`, `app/Models/GradeSubmissionPackageItem.php`, `app/Actions/Grades/GradeSubmissionPackageService.php`, `app/Filament/Resources/GradeSubmissionPackages/GradeSubmissionPackageResource.php`, factories.
- `section_teacher` and `enrollment_subjects` are also used by grade assignment and roster services.

Legacy/changed columns found:

- `grades.enrollment_subject_id`, `subject_id`, `prelim_grade`, `midterm_grade`, `final_grade`, `grade`, `is_inc`, `inc_expires_at`, `is_finalized`: `app/Models/Grade.php`, `app/Actions/Grades/GradeEncodingService.php`, `app/Actions/Grades/GradeFinalizationService.php`, `app/Actions/Grades/GradeCorrectionService.php`, `app/Actions/Grades/GradeSubmissionPackageService.php`, `app/Filament/Resources/Grades/Tables/GradesTable.php`.
- `grade_corrections.grade_id`, `subject_id`, `academic_head_review_*`: `app/Models/GradeCorrection.php`, `app/Actions/Grades/GradeCorrectionService.php`, `app/Filament/Resources/GradeCorrections/*`.

Disposition: **Replace/adapt later**. Grade calculation logic may be salvage, but persistence and UI must move to `grade_rosters`, `grade_roster_rows`, `grade_outcome_events`, and `late_grade_authorizations`. Do not repair old grade correction/package resources before clean enrollment and course-enrollment sources exist.

### Student lifecycle, holds, duplicate resolution, and Student Hub

Legacy/changed references found:

- `student_profiles.student_id`, `lrn`, `year_level`, `operational_status`, `status_reason`, `modality`, `current_balance`, `hard_copy_received`, `merged_into_student_id`: `app/Models/StudentProfile.php`, `app/Models/Scopes/WithoutDuplicatesScope.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Filament/Student/Widgets/StudentProfileOverviewWidget.php`, `app/Filament/Student/Pages/Profile.php`, `database/factories/StudentProfileFactory.php`, `tests/Feature/StudentHubTest.php`, `tests/Feature/StudentHandoverChecklistHoldTest.php`.
- Dirty duplicate/correction surface uses `duplicate_student_id`, `primary_student_id`, `merged_into_student_id`, and legal-field correction semantics: `app/Actions/Enrollment/DuplicateProfileResolver.php`, `app/Actions/Enrollment/PersonalDataCorrectionService.php`, `app/Models/DuplicateProfileResolution.php`, `app/Models/PersonalDataCorrectionRequest.php`, `app/Filament/Resources/DuplicateProfileResolutionResource.php`, `app/Filament/Resources/PersonalDataCorrectionRequestResource.php`, `tests/Feature/DuplicateProfileResolutionTest.php`, `tests/Feature/PersonalDataCorrectionWorkflowTest.php`.
- `faq_entries` and `notifications`: `app/Models/FaqEntry.php`, `app/Actions/StudentHub/StudentDashboardService.php`, `app/Filament/Resources/FaqEntries/FaqEntryResource.php`.

Disposition: **Adapt central holds and duplicate resolution; delete/defer personal data correction requests and FAQ/in-app notification product surface**. Student Hub pages should remain shell/read-only salvage until upstream source tables exist. Rewrite profile/contact data against clean `student_profiles.student_number`, `curriculum_version_id`, `lifecycle_status`, `academic_standing`, contact/address/emergency fields. Keep legal identity edits Registrar-controlled with audit.

### System admin, audit, seeders, and platform

Compatible or mostly compatible references:

- `users`, Spatie role/permission tables, `activity_log`, queue/cache/session/passkey tables, and `webhook_calls` remain platform/foundation.
- `database/seeders/DatabaseSeeder.php` is already in dirty TAL-52 scope and seeds only the seven canonical roles per schema-conformance expectations.

Changed references:

- `system_settings.value` is now typed/effective via `scope_type`, `value_type`, `effective_from`, `effective_until`, `version`, and `status`; existing `app/Models/SystemSetting.php` and `app/Filament/Resources/SystemSettings/*` should be reviewed before use.
- `import_batches.import_type/status/error_log` legacy assumptions appear in `app/Models/ImportBatch.php`, `app/Actions/Imports/ImportBatchLifecycleService.php`, `app/Actions/Imports/CurriculumImportService.php`, and `app/Filament/Resources/ImportBatches/*`.

Disposition: **Keep platform; adapt admin configuration/imports** after Academic/Curriculum foundation is selected. Do not repair legacy import flows horizontally.

## Filament-specific compatibility notes

The following resource families target removed tables and should not be repaired one by one before their owning slices:

`AccountingAdjustments`, `AdmissionCapacityPlans`, `AdmissionOfferings`, `CorVerifications`, `Curriculums`, `DeliveryPatterns`, `DocumentRequirementItems`, `DocumentUploads`, `EnrollmentSubjects`, `FacultyAvailabilityChangeRequests`, `FacultyAvailabilityPeriods`, `FacultyAvailabilitySubmissions`, `FacultySubjectEligibilities`, `FaqEntries`, `FeeTemplates`, `GradeCorrections`, `Grades`, `GradeSubmissionPackages`, `InstallmentPolicies`, `InstallmentPolicyMilestones`, `PromissoryNotes`, `ScheduleGenerationRuns`, `Subjects`.

The new or retained resource families that may be salvaged sooner are:

`Users`, `Roles`, `Activities`, `AcademicYears`, `Programs`, `Rooms`, `Terms`, `ApplicantIntakes`, `StudentProfiles`, `Enrollments`, `LedgerEntries`, `PaymentAttempts`, `Payments`, and `SystemSettings`, but most still have changed columns and should be adapted only inside a bounded vertical slice.

Filament v5 namespace guardrail remains active: later repairs must use `Filament\Actions\*`, `Filament\Schemas\Components\*` for layout, and keep business logic out of Resources.

## Factories and tests that will fail against TAL-52

Factory families that target removed tables or old columns:

- Removed-table factories: `AccountingAdjustmentFactory.php`, `AdmissionCapacityPlanFactory.php`, `AdmissionCapacityReservationFactory.php`, `AdmissionOfferingFactory.php`, `CurriculumFactory.php`, `CurriculumReadinessScopeFactory.php`, `CurriculumSubjectFactory.php`, `DeliveryPatternFactory.php`, `DocumentRequirementItemFactory.php`, `FacultyAvailabilityChangeRequestFactory.php`, `FacultyAvailabilityPeriodFactory.php`, `FacultyAvailabilitySubmissionFactory.php`, `FacultyAvailabilityWindowFactory.php`, `FacultySubjectEligibilityFactory.php`, `FeeTemplateFactory.php`, `GradeCorrectionFactory.php`, `GradeFactory.php`, `GradeSubmissionPackageFactory.php`, `GradeSubmissionPackageItemFactory.php`, `PersonalDataCorrectionRequestFactory.php`, `SubjectFactory.php`.
- Changed-column factories: `ApplicantIntakeFactory.php`, `EnrollmentFactory.php`, `LedgerEntryFactory.php`, `PaymentFactory.php`, `SectionFactory.php`, `SectionDeliveryGroupFactory.php`, `StudentProfileFactory.php`, `TermFactory.php`, `AcademicYearFactory.php`, `SystemSetting` usage through tests/resources.

Tests that are salvage inventory but not TAL-52-compatible without adaptation:

- `tests/Feature/ApplicantWorkspaceTest.php`
- `tests/Feature/ApplicantIntakeSubmissionTest.php`
- `tests/Feature/RegistrarApplicantIntakeQueueTest.php`
- `tests/Feature/AdmissionsStudentHandoverUiTest.php`
- `tests/Feature/StudentHandoverChecklistHoldTest.php`
- `tests/Feature/StudentHubTest.php`
- `tests/Feature/DuplicateProfileResolutionTest.php`
- `tests/Feature/PersonalDataCorrectionWorkflowTest.php`

Tests likely keep/adapt:

- Auth and panel-boundary tests under `tests/Feature/Auth/` should remain close to valid because they target platform/auth behavior.
- `tests/Feature/Database/SchemaConformanceTest.php` should remain TAL-52 proof surface.

No tests should be deleted during TAL-53. Future slices should update or replace affected tests as part of their specific implementation.

## Highest-risk broken backend areas

1. **Filament resource registration for deleted models/tables** - many resources still point at model classes whose default tables were removed by TAL-52. Admin navigation or page access can fail before any business action runs.
2. **Finance and PayMongo posting** - webhook and payment confirmation still write old ledger/payment/current-balance fields. Running these against TAL-52 would fail or create invalid accounting state.
3. **Grades and faculty rosters** - grade services depend on `enrollment_subjects`, `grades`, `grade_submission_*`, and `section_teacher`, none of which exist in the clean baseline.
4. **Scheduling** - solver run/candidate/meeting services still use old names and payload ownership (`schedule_generation_runs`, `generation_run_id`, `subject_id`, string `room`) instead of demand-keyed TAL-52 tables.
5. **Applicant handover/student master** - student number/LRN/year-level/current-balance fields conflict with clean `student_number`, `curriculum_version_id`, lifecycle/standing, and contact ownership.

## Recommended next implementation slices

1. **TAL-54 - Backend Boot and Filament Registration Stabilization**: prevent removed-table resources/models from being active production/admin routes while keeping accepted auth/panel shells and schema-conformance tests intact. Scope should be narrow: route/resource registration and smoke compatibility only, not domain repair.
2. **TAL-55 - Academic/Course/Curriculum Foundation Adaptation**: implement/adapt models, factories, and minimal admin surfaces for `courses`, `course_specifications`, `course_components`, `course_requirements`, `curriculum_versions`, `curriculum_entries`, `import_batches`.
3. **TAL-56 - Admissions to Student Master Adaptation**: adapt applicant intake, checklist, document evidence, handover, student profile, and duplicate resolution to TAL-52 tables.
4. **TAL-57 - Term Offerings and Scheduling Demand Skeleton**: implement `term_offerings`, `sections`, `section_delivery_groups`, `scheduling_demands`, `schedule_runs`, candidate rows, and retained Cloud Run seam.
5. **TAL-58 - Enrollment Gate and Seat Reservation Skeleton**: adapt enrollment headers, course enrollments, schedule bindings, gate results, exceptions, and payment-independent reservations.
6. **TAL-59 - Finance Assessment to Ledger Skeleton**: adapt fee rules, assessments, payment attempts/evidence, allocations, immutable ledger, accommodations, and PayMongo webhook posting.
7. Later: **Grades**, **COR/output access**, **Student Hub projections**, **lifecycle/graduation**, and **reports/audit exports** after their upstream source records are stable.

## Out-of-scope areas that must not be repaired yet

- Do not edit TAL-52 migrations during backend reconciliation unless a verified schema-contract defect is found and approved.
- Do not repair all Filament resources horizontally.
- Do not build new UI layouts or pages.
- Do not delete tests or code.
- Do not revive `cor_verifications`, OCR extraction, generic service/document requests, in-app notification center, old FAQ CRUD, or public QR/token COR verification.
- Do not repair fee templates, installment policies, promissory notes, grade corrections, grade packages, or scheduling availability requests independently of their clean source-record slices.
- Do not run destructive database commands or reset `tala_db`.
- Do not update PRD modules, architecture docs, route/config files, dependency files, or tracker docs as part of TAL-53.

## Risky ambiguities needing primary-thread/user approval

1. **TAL-54 strategy**: whether to temporarily unregister/defer deleted-table Filament resources to stabilize backend boot, or leave them registered but known-broken until each domain slice adapts them. Recommendation: unregister/defer by resource family in TAL-54, with tests proving accepted auth/applicant/student shells still boot.
2. **Dirty duplicate/profile work**: `DuplicateProfileResolution` exists in clean TAL-52, but dirty code uses `merged_into_student_id` and a global scope. Recommendation: adapt duplicate resolution later with history-preserving FKs and no broad global scope. `PersonalDataCorrectionRequest` should be deleted/deferred unless the primary thread overturns TAL-51.
3. **Student number naming**: legacy tests/code expect `student_id`; TAL-52 uses `student_number`. Recommendation: migrate backend language to `student_number` and update tests in the admissions/student-master slice.
4. **Finance accommodation vs promissory note**: old promissory-note services are large and may contain useful lifecycle ideas, but TAL-52 replaced them with `financial_accommodations`. Recommendation: salvage validation/audit ideas only, not the table/resource.
5. **FAQ and notifications**: legacy Student Hub includes FAQ and database notifications. TAL-51 moved FAQ-like content/settings and product notifications away from separate MVP surfaces. Recommendation: defer.

## Commands and tools run

- `git status --short`
- Laravel Boost `application_info`
- Laravel Boost `search_docs` for database/schema/factory/test/Filament conventions
- Laravel Boost `database_schema(summary=true)`; used only to confirm live DB is pre-TAL-52
- Serena `initial_instructions` and project activation
- `Get-Content -Raw AGENTS.md`
- `Get-Content -Raw 00_Project_Documents/TALA-Rescue-Next-Steps.md`
- `Get-Content -Raw 00_Project_Documents/TALA-Local-Linear-Sync-Tracker.md`
- `Get-Content -Raw 00_Project_Documents/prd_modules/README.md`
- `Get-Content -Raw 00_Project_Documents/prd_modules/_working/TAL-50-backend-salvage-ledger.md`
- `Get-Content -Raw 00_Project_Documents/prd_modules/_working/TAL-51-clean-mvp-erd-and-schema-contract.md`
- `Get-ChildItem database/migrations -File -Filter *.php`
- Read-only PowerShell migration-source table/column extraction
- `rg` stale table/column scans across `app`, `database/factories`, and `tests`
- `rg` targeted scans for DB table joins, model imports, old finance/grade/scheduling/admission/student-profile fields
- `rg --files` path inventory for app models/actions/Filament/policies/observers/console/jobs/controllers, factories, and tests

## TAL-53 write-scope confirmation

Only this file was written for TAL-53:

`00_Project_Documents/prd_modules/_working/TAL-53-backend-schema-reconciliation-inventory.md`

No migrations, PHP code, tests, dependencies, routes, config, PRD modules, architecture docs, or tracker docs were changed by this inventory task.
