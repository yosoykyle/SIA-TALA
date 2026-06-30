<?php

namespace App\Models;

use Database\Factories\GradeRosterRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $released_at
 */
class GradeRosterRow extends Model
{
    /** @use HasFactory<GradeRosterRowFactory> */
    use HasFactory;

    public const CategoryPassing = 'Passing';

    public const CategoryFailed = 'Failed';

    public const CategoryIncomplete = 'Incomplete';

    public const CategoryPending = 'Pending Grade';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade_roster_id',
        'course_enrollment_id',
        'prelim_equivalent',
        'midterm_equivalent',
        'final_equivalent',
        'computed_average',
        'current_outcome_code',
        'current_outcome_category',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prelim_equivalent' => 'decimal:4',
            'midterm_equivalent' => 'decimal:4',
            'final_equivalent' => 'decimal:4',
            'computed_average' => 'decimal:4',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeRoster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(GradeRoster::class, 'grade_roster_id');
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return HasMany<GradeOutcomeEvent, $this> */
    public function outcomeEvents(): HasMany
    {
        return $this->hasMany(GradeOutcomeEvent::class);
    }
}
