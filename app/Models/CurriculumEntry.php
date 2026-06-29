<?php

namespace App\Models;

use Database\Factories\CurriculumEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumEntry extends Model
{
    /** @use HasFactory<CurriculumEntryFactory> */
    use HasFactory;

    public const RequirementGroupRequired = 'required';

    public const RequirementGroupElective = 'elective';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'curriculum_version_id',
        'course_specification_id',
        'year_level',
        'term_label',
        'term_type',
        'sequence',
        'requirement_group',
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

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return BelongsTo<CourseSpecification, $this> */
    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }

    /** @return HasMany<TermOffering, $this> */
    public function termOfferings(): HasMany
    {
        return $this->hasMany(TermOffering::class);
    }
}
