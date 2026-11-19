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
        'state',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<CourseSpecification, $this> */
    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function relatedCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'related_course_id');
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TypePrerequisite => 'Prerequisite',
            self::TypeCorequisite => 'Corequisite',
            self::TypeEquivalency => 'Equivalent Course',
        ];
    }
}
