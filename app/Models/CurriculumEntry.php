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

    /**
     * @return array<string, string>
     */
    public static function requirementGroupOptions(): array
    {
        return [
            self::RequirementGroupRequired => 'Required',
            self::RequirementGroupElective => 'Elective',
        ];
    }

    public static function parseYearLevelNumber(mixed $yearLevel): int
    {
        if (is_int($yearLevel) || is_numeric($yearLevel)) {
            return max(0, (int) $yearLevel);
        }

        $normalized = strtolower(trim((string) $yearLevel));

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            str_contains($normalized, 'sixth') => 6,
            str_contains($normalized, 'seventh') => 7,
            str_contains($normalized, 'eighth') => 8,
            default => 0,
        };
    }

    public static function termTypeSemesterNumber(?string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 0,
        };
    }
}
