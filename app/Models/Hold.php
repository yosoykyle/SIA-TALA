<?php

namespace App\Models;

use Database\Factories\HoldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Hold extends Model
{
    /** @use HasFactory<HoldFactory> */
    use HasFactory;

    public const TypeFinancial = 'financial';

    public const TypeDocumentary = 'documentary';

    public const TypeBehavioral = 'behavioral';

    public const TypeDisciplinary = 'disciplinary';

    public const TypeAcademicDeficit = 'academic_deficit';

    public const TypePrerequisite = 'prerequisite';

    public const TypeEnrollment = 'enrollment';

    public const TypeCorDownload = 'cor_download';

    public const TypeClearance = 'clearance';

    public const TypeGraduationEligibility = 'graduation_eligibility';

    public const TypeReactivation = 'reactivation';

    public const TypeTransferOut = 'transfer_out';

    public const TypeRecordRelease = 'record_release';

    public const BlockingEnrollment = 'blocks_enrollment';

    public const BlockingCorPrint = 'blocks_cor_print';

    public const BlockingClearance = 'blocks_clearance';

    public const BlockingRecordRelease = 'blocks_record_release';

    public const BlockingGraduationEligibility = 'blocks_graduation_eligibility';

    public const BlockingReactivation = 'blocks_reactivation';

    public const BlockingAdvisoryOnly = 'advisory_only';

    public const StatusActive = 'active';

    public const StatusResolved = 'resolved';

    public const StatusWaived = 'waived';

    public const StatusExpired = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'hold_type',
        'blocking_level',
        'status',
        'reason',
        'staff_only_reason',
        'student_message',
        'source_type',
        'source_id',
        'created_by',
        'effective_at',
        'expires_at',
        'resolution_requirement',
        'resolved_by',
        'resolved_at',
        'waived_by',
        'waived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function studentFacingMessage(): ?string
    {
        foreach (['student_message', 'resolution_requirement', 'reason'] as $attribute) {
            $message = $this->getAttribute($attribute);

            if (is_string($message) && filled($message)) {
                return $message;
            }
        }

        return null;
    }
}
