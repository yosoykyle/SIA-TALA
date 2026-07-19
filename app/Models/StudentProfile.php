<?php

namespace App\Models;

use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory;

    public const LifecycleActive = 'ACTIVE';

    public const LifecycleArchived = 'ARCHIVED';

    public const StandingRegular = 'Regular';

    public const StandingIrregular = 'Irregular';

    public const StandingProbationary = 'Probationary';

    public const StandingDeficient = 'Deficient';

    public const StandingBlockedByPrerequisite = 'Blocked by Prerequisite';

    public const StandingMustRepeatYear = 'Must Repeat Year Level';

    public const StandingCompletionCandidate = 'Completion Candidate';

    public const StandingGraduationCandidate = 'Graduation Candidate';

    public const StandingNotYetEvaluated = 'Not Yet Evaluated';

    public const LifecycleLeaveOfAbsence = 'LEAVE_OF_ABSENCE';

    public const LifecycleWithdrawn = 'WITHDRAWN';

    public const LifecycleTransferredOut = 'TRANSFERRED_OUT';

    public const LifecycleInactive = 'INACTIVE';

    /** @return list<string> */
    public static function enrollmentBlockingLifecycleStatuses(): array
    {
        return [
            self::LifecycleLeaveOfAbsence,
            self::LifecycleWithdrawn,
            self::LifecycleTransferredOut,
            self::LifecycleInactive,
            self::LifecycleArchived,
        ];
    }

    /** @return list<string> */
    public static function corBlockingLifecycleStatuses(): array
    {
        return self::enrollmentBlockingLifecycleStatuses();
    }

    public static function lifecycleStatusLabel(?string $status): string
    {
        return match ($status) {
            self::LifecycleActive => 'Active',
            self::LifecycleLeaveOfAbsence => 'Leave of Absence',
            self::LifecycleWithdrawn => 'Withdrawn',
            self::LifecycleTransferredOut => 'Transferred Out',
            self::LifecycleInactive => 'Inactive',
            self::LifecycleArchived => 'Archived',
            default => filled($status) ? str((string) $status)->headline()->toString() : 'Not recorded',
        };
    }

    public function hasActiveLifecycleStatus(): bool
    {
        return $this->lifecycle_status === self::LifecycleActive
            && $this->archived_at === null
            && $this->merged_into_id === null;
    }

    public function blocksEnrollmentByLifecycle(): bool
    {
        return ! $this->hasActiveLifecycleStatus()
            || in_array($this->lifecycle_status, self::enrollmentBlockingLifecycleStatuses(), true);
    }

    public function blocksCurrentCorByLifecycle(): bool
    {
        return ! $this->hasActiveLifecycleStatus()
            || in_array($this->lifecycle_status, self::corBlockingLifecycleStatuses(), true);
    }

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'applicant_intake_id',
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'prior_identifier',
        'program_id',
        'curriculum_version_id',
        'lifecycle_status',
        'academic_standing',
        'email',
        'phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'archived_at',
        'merged_into_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applicantIntake(): BelongsTo
    {
        return $this->belongsTo(ApplicantIntake::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'merged_into_id');
    }

    public function mergedDuplicates(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'merged_into_id');
    }

    public function duplicateResolutionsAsDuplicate(): HasMany
    {
        return $this->hasMany(DuplicateProfileResolution::class, 'duplicate_student_profile_id');
    }

    public function duplicateResolutionsAsPrimary(): HasMany
    {
        return $this->hasMany(DuplicateProfileResolution::class, 'primary_student_profile_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->whereNull('merged_into_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function enrollmentExceptions(): HasMany
    {
        return $this->hasMany(EnrollmentException::class);
    }

    public function lifecycleChanges(): HasMany
    {
        return $this->hasMany(StudentLifecycleChange::class);
    }
}
