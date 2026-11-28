<?php

namespace App\Models;

use Database\Factories\TermCohortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** @property Pivot $pivot */
class TermCohort extends Model
{
    /** @use HasFactory<TermCohortFactory> */
    use HasFactory;

    protected $fillable = [
        'term_id', 'program_id', 'curriculum_version_id', 'entry_academic_year_id', 'reference', 'year_level', 'source',
        'forecast_count', 'confirmed_count', 'state', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'forecast_count' => 'integer',
            'confirmed_count' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
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

    /** @return BelongsTo<AcademicYear, $this> */
    public function entryAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'entry_academic_year_id');
    }

    /** @return BelongsToMany<Section, $this> */
    public function classOfferings(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)->withPivot('expected_count')->withTimestamps();
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_section_id');
    }

    /**
     * Resolve the numeric year level (1..8) for this student section.
     */
    public function resolvedYearLevel(): ?int
    {
        if ($this->year_level !== null && (int) $this->year_level > 0) {
            return (int) $this->year_level;
        }

        $offerings = $this->relationLoaded('classOfferings')
            ? $this->classOfferings
            : $this->classOfferings()->with('termOffering.curriculumEntry')->get();

        $counts = [];

        foreach ($offerings as $section) {
            $rawLevel = $section->termOffering?->curriculumEntry?->year_level;
            $parsed = self::parseYearLevelValue($rawLevel);

            if ($parsed !== null) {
                $counts[$parsed] = ($counts[$parsed] ?? 0) + 1;
            }
        }

        if ($counts !== []) {
            arsort($counts);

            return (int) array_key_first($counts);
        }

        return self::parseYearLevelFromReference($this->reference);
    }

    public static function parseYearLevelValue(mixed $raw): ?int
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
            $value = (int) $raw;

            return ($value >= 1 && $value <= 8) ? $value : null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $normalized = strtolower(trim($raw));

        if (preg_match('/\b([1-8])(?:st|nd|rd|th)?\b/', $normalized, $matches) === 1) {
            return (int) $matches[1];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            str_contains($normalized, 'sixth') => 6,
            default => null,
        };
    }

    private static function parseYearLevelFromReference(?string $reference): ?int
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        if (preg_match('/(?:^|[\s_-])([1-8])(?:[A-Za-z]|[-_\s]|$)/', trim($reference), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/([1-8])/', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
