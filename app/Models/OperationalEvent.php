<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\OperationalEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $diagnostics
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $recipient_snapshot
 * @property Carbon $occurred_at
 */
class OperationalEvent extends Model
{
    /** @use HasFactory<OperationalEventFactory> */
    use HasFactory;

    public $timestamps = false;

    public const DomainIntegration = 'INTEGRATION';

    public const DomainNotifications = 'notifications';

    public const DomainOperations = 'OPERATIONS';

    public const IntegrationSchedulingSolver = 'SCHEDULING_SOLVER';

    public const IntegrationPayMongo = 'PAYMONGO';

    public const IntegrationMail = 'mail';

    public const IntegrationBackup = 'BACKUP';

    public const IntegrationRestore = 'RESTORE';

    public const IntegrationAcademicRecords = 'academic_records';

    public const ChannelEmail = 'email';

    public const ChannelWebhook = 'webhook';

    public const ChannelProviderApi = 'provider_api';

    public const ChannelEvidenceIngest = 'evidence_ingest';

    public const DirectionOutbound = 'OUTBOUND';

    public const DirectionInbound = 'INBOUND';

    public const TypeSolverDispatchAttempt = 'solver_dispatch_attempt';

    public const TypeScheduleRevisionEmail = 'schedule_revision_email';

    public const TypeScheduleReleasedEmail = 'schedule_released_email';

    public const TypeFacultyAvailabilityRequestedEmail = 'faculty_availability_requested_email';

    public const TypePaymentPostedEmail = 'payment_posted_email';

    public const TypeApplicantActionRequiredEmail = 'applicant_action_required_email';

    public const TypeApplicantApprovedEmail = 'applicant_approved_email';

    public const TypeAdmissionApplicationSubmitted = 'admission_application_submitted';

    public const TypeAdmissionApplicationResubmitted = 'admission_application_resubmitted';

    public const TypeAdmissionCorrectionRequested = 'admission_correction_requested';

    public const TypeAdmissionApplicationAdmitted = 'admission_application_admitted';

    public const TypeAdmissionApplicationNotAdmitted = 'admission_application_not_admitted';

    public const TypeAdmissionReadyForEnrollment = 'admission_ready_for_enrollment';

    public const TypeAdmissionApplicationWithdrawn = 'admission_application_withdrawn';

    public const TypeOfficialEnrollmentEmail = 'official_enrollment_email';

    public const TypeEnrollmentWindowEmail = 'enrollment_window_email';

    public const TypeRegistrationProposalEmail = 'registration_proposal_email';

    public const TypeRegistrationPaymentActionEmail = 'registration_payment_action_email';

    public const TypeRegistrationCaseExpiryEmail = 'registration_case_expiry_email';

    public const TypeRegistrationAdjustmentEmail = 'registration_adjustment_email';

    public const TypeCourseDropEmail = 'course_drop_email';

    /** @return list<string> */
    public static function registrationNotificationTypes(): array
    {
        return [
            self::TypeEnrollmentWindowEmail,
            self::TypeRegistrationProposalEmail,
            self::TypeRegistrationPaymentActionEmail,
            self::TypeOfficialEnrollmentEmail,
            self::TypeRegistrationCaseExpiryEmail,
            self::TypeRegistrationAdjustmentEmail,
            self::TypeCourseDropEmail,
        ];
    }

    public const TypeAcademicRecordUpdatedEmail = 'academic_record_updated_email';

    public const TypeGradeSubmissionRequiredEmail = 'grade_submission_required_email';

    public const TypeGradeRosterReturnedEmail = 'grade_roster_returned_email';

    public const TypeGradeRosterReleasedEmail = 'grade_roster_released_email';

    public const TypeIncReleasedEmail = 'inc_released_email';

    public const TypeIncDeadlineAmendedEmail = 'inc_deadline_amended_email';

    public const TypeIncResolvedEmail = 'inc_resolved_email';

    public const TypeGradeCorrectionReleasedEmail = 'grade_correction_released_email';

    public const TypeAcademicProgressLifecycleEmail = 'academic_progress_lifecycle_email';

    public const TypeCompletionRequiresActionEmail = 'completion_requires_action_email';

    public const TypeConferralRecordedEmail = 'conferral_recorded_email';

    public const TypeLifecycleAccountingReview = 'lifecycle_accounting_review';

    /** @return list<string> */
    public static function academicNotificationTypes(): array
    {
        return [
            self::TypeAcademicRecordUpdatedEmail,
            self::TypeGradeSubmissionRequiredEmail,
            self::TypeGradeRosterReturnedEmail,
            self::TypeGradeRosterReleasedEmail,
            self::TypeIncReleasedEmail,
            self::TypeIncDeadlineAmendedEmail,
            self::TypeIncResolvedEmail,
            self::TypeGradeCorrectionReleasedEmail,
            self::TypeAcademicProgressLifecycleEmail,
            self::TypeCompletionRequiresActionEmail,
            self::TypeConferralRecordedEmail,
        ];
    }

    public const StatusPending = 'PENDING';

    public const StatusProcessed = 'PROCESSED';

    public const StatusFailed = 'FAILED';

    public const StatusReviewRequired = 'REVIEW_REQUIRED';

    public const StatusIgnored = 'IGNORED';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipient_snapshot' => 'array',
            'diagnostics' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ScheduleGenerationRun, $this> */
    public function scheduleGenerationRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'related_record_id');
    }
}
