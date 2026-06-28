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

    public const StandingGood = 'GOOD_STANDING';

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

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

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
}
