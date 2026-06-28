<?php

namespace App\Models;

use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use HasFactory;

    public const OwnerApplicant = 'APPLICANT';

    public const OwnerStudent = 'STUDENT';

    public const StatusPending = 'PENDING';

    public const StatusReceivedPhysical = 'RECEIVED_PHYSICAL';

    public const StatusReceivedDigital = 'RECEIVED_DIGITAL';

    public const StatusAccepted = 'ACCEPTED';

    public const StatusRejected = 'REJECTED';

    public const StatusWaived = 'WAIVED';

    public const StatusUndertakingApproved = 'UNDERTAKING_APPROVED';

    public const VerificationNotReviewed = 'NOT_REVIEWED';

    public const VerificationVerified = 'VERIFIED';

    public const VerificationRejected = 'REJECTED';

    public const BlockingHandover = 'BLOCKS_HANDOVER';

    public const BlockingEnrollment = 'BLOCKS_ENROLLMENT';

    public const BlockingCorPrint = 'BLOCKS_COR_PRINT';

    public const BlockingRecordRelease = 'BLOCKS_RECORD_RELEASE';

    public const BlockingRetentionOnly = 'RETENTION_ONLY';

    public const BlockingAdvisoryOnly = 'ADVISORY_ONLY';

    /** @var list<string> */
    protected $fillable = [
        'owner_type',
        'applicant_intake_id',
        'student_profile_id',
        'source_policy_id',
        'requirement_type',
        'status',
        'blocking_level',
        'evidence_method',
        'verification_status',
        'deadline',
        'reviewed_by',
        'reviewed_at',
        'waiver_reason',
        'undertaking_terms',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [
            self::StatusAccepted,
            self::StatusWaived,
            self::StatusUndertakingApproved,
        ], true) || $this->verification_status === self::VerificationVerified;
    }

    public function remainsRelevantAfterHandover(): bool
    {
        return $this->blocking_level !== self::BlockingHandover;
    }

    public function applicantIntake(): BelongsTo
    {
        return $this->belongsTo(ApplicantIntake::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function sourcePolicy(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementPolicy::class, 'source_policy_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documentEvidence(): HasMany
    {
        return $this->hasMany(DocumentEvidence::class);
    }

    protected static function booted(): void
    {
        static::saving(function (ChecklistItem $item): void {
            $isApplicant = $item->owner_type === self::OwnerApplicant
                && $item->applicant_intake_id !== null
                && $item->student_profile_id === null;
            $isStudent = $item->owner_type === self::OwnerStudent
                && $item->student_profile_id !== null
                && $item->applicant_intake_id === null;

            if (! $isApplicant && ! $isStudent) {
                throw new InvalidArgumentException('Checklist items must have exactly one owner matching owner_type.');
            }
        });
    }
}
