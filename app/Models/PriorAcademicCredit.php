<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $student_profile_id
 * @property int $course_specification_id
 * @property int|null $curriculum_entry_id
 * @property int|null $term_id
 * @property string|null $academic_year_label
 * @property string|null $term_label
 * @property int|null $year_level
 * @property string $units
 * @property string|null $final_grade
 * @property string $result_status
 * @property string|null $source_school
 * @property bool $is_external_transfer
 * @property string $state
 * @property int|null $recorded_by
 * @property Carbon|null $recorded_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 */
class PriorAcademicCredit extends Model
{
    public const StateActive = 'ACTIVE';

    public const StateRevoked = 'REVOKED';

    public const ResultPassed = 'passed';

    public const ResultCredited = 'credited';

    public const ResultFailed = 'failed';

    public const ResultInc = 'inc';

    public const ResultDropped = 'dropped';

    /** @var list<string> */
    protected $fillable = [
        'student_profile_id',
        'course_specification_id',
        'curriculum_entry_id',
        'term_id',
        'academic_year_label',
        'term_label',
        'year_level',
        'units',
        'final_grade',
        'result_status',
        'source_school',
        'is_external_transfer',
        'state',
        'recorded_by',
        'recorded_at',
        'revoked_by',
        'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'units' => 'decimal:2',
            'is_external_transfer' => 'boolean',
            'recorded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExternalTransfer(): bool
    {
        return (bool) $this->is_external_transfer;
    }

    /** @return list<string> */
    public static function resultStatuses(): array
    {
        return [
            self::ResultPassed,
            self::ResultCredited,
            self::ResultFailed,
            self::ResultInc,
            self::ResultDropped,
        ];
    }

    /** @return array<string, string> */
    public static function resultStatusOptions(): array
    {
        return [
            self::ResultPassed => 'Passed',
            self::ResultCredited => 'Credited',
            self::ResultFailed => 'Failed',
            self::ResultInc => 'Incomplete (INC)',
            self::ResultDropped => 'Dropped (DRP)',
        ];
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::StateActive);
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<CourseSpecification, $this> */
    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }

    /** @return BelongsTo<CurriculumEntry, $this> */
    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function effectiveResultCode(): string
    {
        if ($this->isExternalTransfer()
            && ! in_array($this->result_status, [self::ResultFailed, self::ResultInc, self::ResultDropped], true)) {
            $grade = trim((string) ($this->final_grade ?? ''));
            if ($grade === '' || ! is_numeric($grade) || (float) $grade <= 4.00) {
                return 'TC';
            }
        }

        $grade = trim((string) ($this->final_grade ?? ''));

        if ($grade !== '') {
            $upper = strtoupper($grade);
            if (in_array($upper, ['CREDIT', 'TC', 'INC', 'DRP', 'W'], true)) {
                return $upper;
            }

            if (is_numeric($grade)) {
                return number_format((float) $grade, 2, '.', '');
            }
        }

        return match ($this->result_status) {
            self::ResultCredited => $this->isExternalTransfer() ? 'TC' : 'CREDIT',
            self::ResultFailed => '5.00',
            self::ResultInc => 'INC',
            self::ResultDropped => 'DRP',
            default => $this->isExternalTransfer() ? 'TC' : 'CREDIT',
        };
    }

    public function isPassing(): bool
    {
        if (in_array($this->result_status, [self::ResultFailed, self::ResultInc, self::ResultDropped], true)) {
            return false;
        }

        $code = $this->effectiveResultCode();

        return in_array($code, ['CREDIT', 'TC'], true)
            || (is_numeric($code) && (float) $code <= 4.00);
    }

    public function periodKey(): string
    {
        if ($this->term_id !== null) {
            return 'term:'.$this->term_id;
        }

        $ay = trim((string) ($this->academic_year_label ?? '')) ?: 'Prior Institutional Record';
        $term = trim((string) ($this->term_label ?? '')) ?: 'Prior Term';
        $year = (int) ($this->year_level ?? 0);

        return 'prior:'.$ay.'|'.$term.'|'.$year;
    }

    public function displayAcademicYearLabel(): string
    {
        if ($this->term?->academicYear?->label) {
            return (string) $this->term->academicYear->label;
        }

        return trim((string) ($this->academic_year_label ?? '')) ?: 'Prior Institutional Record';
    }

    public function displayTermLabel(): string
    {
        if ($this->term?->label) {
            return (string) $this->term->label;
        }

        return trim((string) ($this->term_label ?? '')) ?: 'Prior Term';
    }

    public function periodSortKey(): string
    {
        if ($this->term instanceof Term) {
            $startYear = $this->term->academicYear instanceof AcademicYear
                ? (CurriculumVersion::extractAcademicYearStart($this->term->academicYear) ?? (int) ($this->term->starts_on?->year ?? 9999))
                : (int) ($this->term->starts_on?->year ?? 9999);
            $termOrder = match ($this->term->type) {
                Term::TypeFirstSemester => 1,
                Term::TypeSecondSemester => 2,
                Term::TypeSpecialTerm => 3,
                default => 4,
            };

            return sprintf(
                '%04d|%d|%s|%010d',
                $startYear,
                $termOrder,
                $this->term->starts_on?->toDateString() ?? '9999-12-31',
                (int) $this->term->id,
            );
        }

        $ay = $this->displayAcademicYearLabel();
        preg_match('/(19|20)\d{2}/', $ay, $matches);
        $yearPart = isset($matches[0]) ? (int) $matches[0] : 0;
        $termLower = strtolower($this->displayTermLabel());
        $termOrder = match (true) {
            str_contains($termLower, '1st'), str_contains($termLower, 'first') => 1,
            str_contains($termLower, '2nd'), str_contains($termLower, 'second') => 2,
            str_contains($termLower, 'sum'), str_contains($termLower, 'special'), str_contains($termLower, 'mid') => 3,
            default => 4,
        };
        $yearLevel = sprintf('%02d', (int) ($this->year_level ?? 0));

        return sprintf('%04d|%d|0000-00-00|%s|%s|%s', $yearPart, $termOrder, $yearLevel, $ay, $this->displayTermLabel());
    }
}
