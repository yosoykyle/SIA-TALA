<?php

namespace App\Models;

use Database\Factories\CourseSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSpecification extends Model
{
    /** @use HasFactory<CourseSpecificationFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateRetired = 'RETIRED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'revision_code',
        'title',
        'description',
        'credit_units',
        'grading_profile_key',
        'grading_profile_version',
        'allowed_modalities',
        'same_faculty_default',
        'effective_term_id',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_units' => 'decimal:2',
            'grading_profile_version' => 'integer',
            'allowed_modalities' => 'array',
            'same_faculty_default' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function effectiveTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'effective_term_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(CourseComponent::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(CourseRequirement::class);
    }

    public function curriculumEntries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }

    public function totalWeeklyContactHours(): float
    {
        return (float) $this->components()->sum('weekly_contact_hours');
    }
}
