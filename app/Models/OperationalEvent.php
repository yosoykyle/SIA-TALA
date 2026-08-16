<?php

namespace App\Models;

use Database\Factories\OperationalEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $diagnostics
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $recipient_snapshot
 */
class OperationalEvent extends Model
{
    /** @use HasFactory<OperationalEventFactory> */
    use HasFactory;

    public $timestamps = false;

    public const DomainIntegration = 'INTEGRATION';

    public const DomainNotifications = 'notifications';

    public const IntegrationSchedulingSolver = 'SCHEDULING_SOLVER';

    public const IntegrationPayMongo = 'PAYMONGO';

    public const IntegrationMail = 'mail';

    public const ChannelEmail = 'email';

    public const ChannelWebhook = 'webhook';

    public const ChannelProviderApi = 'provider_api';

    public const DirectionOutbound = 'OUTBOUND';

    public const DirectionInbound = 'INBOUND';

    public const TypeSolverDispatchAttempt = 'solver_dispatch_attempt';

    public const TypeScheduleRevisionEmail = 'schedule_revision_email';

    public const TypeScheduleReleasedEmail = 'schedule_released_email';

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
