<?php

namespace App\Models;

use Database\Factories\ApplicantIntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ApplicantIntake extends Model
{
    /** @use HasFactory<ApplicantIntakeFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusPending = 'pending';

    public const StatusActionRequired = 'action_required';

    public const StatusForEvaluation = 'for_evaluation';

    public const StatusApproved = 'approved';

    public const DuplicateStatusClear = 'clear';

    public const DuplicateStatusBlocked = 'blocked';

    public const AdmissionCategoryFirstTimeCollege = 'FIRST_TIME_COLLEGE';

    public const AdmissionCategoryTransfer = 'TRANSFER';

    public const AdmissionCategoryReturning = 'RETURNING';

    public const CredentialBasisSeniorHighSchool = 'SENIOR_HIGH_SCHOOL';

    public const CredentialBasisTransferCredentials = 'TRANSFER_CREDENTIALS';

    public const CredentialBasisPriorStudentRecord = 'PRIOR_STUDENT_RECORD';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'term_id',
        'program_id',
        'admission_category',
        'credential_basis',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'email',
        'phone',
        'prior_school',
        'identity_evidence_reference',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'handed_over_at',
        'handed_over_by',
        'archived_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::StatusDraft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function handoverActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    protected static function booted(): void
    {
        static::updating(function (ApplicantIntake $intake): void {
            if ($intake->getOriginal('handed_over_at') !== null) {
                throw new LogicException('A handed-over applicant intake is immutable.');
            }
        });
    }
}
