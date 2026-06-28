<?php

namespace App\Models;

use Database\Factories\CourseRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseRequirement extends Model
{
    /** @use HasFactory<CourseRequirementFactory> */
    use HasFactory;

    public const TypePrerequisite = 'PREREQUISITE';

    public const TypeCorequisite = 'COREQUISITE';

    public const TypeEquivalency = 'EQUIVALENCY';

    public const StateActive = 'ACTIVE';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_specification_id',
        'rule_type',
        'group_key',
        'related_course_id',
        'direction',
        'equivalency_scope',
        'required_outcome',
        'minimum_grade',
        'accepts_transfer_credit',
        'effective_from',
        'effective_until',
        'authority',
        'state',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_grade' => 'decimal:4',
            'accepts_transfer_credit' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'sequence' => 'integer',
        ];
    }

    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }

    public function relatedCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'related_course_id');
    }
}
