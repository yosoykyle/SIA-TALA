<?php

namespace App\Models;

use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property-read AcademicYear|null $academicYear
 */
class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    public const TypeFirstSemester = 'FIRST_SEMESTER';

    public const TypeSecondSemester = 'SECOND_SEMESTER';

    public const TypeSpecialTerm = 'SUMMER';

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateClosed = 'CLOSED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'academic_year_id',
        'type',
        'label',
        'starts_on',
        'ends_on',
        'state',
        'scheduling_slot_minutes',
        'scheduling_days',
        'scheduling_day_starts_at',
        'scheduling_day_ends_at',
        'default_max_units',
        'registration_applicability',
        'registration_applicability_authority',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'scheduling_days' => 'array',
            'default_max_units' => 'decimal:2',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function termOfferings(): HasMany
    {
        return $this->hasMany(TermOffering::class);
    }

    /** @return HasMany<TermCalendarPackage, $this> */
    public function calendarPackages(): HasMany
    {
        return $this->hasMany(TermCalendarPackage::class);
    }

    public function hasActivatedCalendar(): bool
    {
        return $this->calendarPackages()->where(fn ($query) => $query->where('state', TermCalendarPackage::StateActive)->orWhereNotNull('activated_at')->orWhereNotNull('closed_at'))->exists();
    }

    /** @return HasMany<PublishedTimetableVersion, $this> */
    public function timetableVersions(): HasMany
    {
        return $this->hasMany(PublishedTimetableVersion::class);
    }

    /** @return HasMany<ScheduleGenerationRun, $this> */
    public function scheduleGenerationRuns(): HasMany
    {
        return $this->hasMany(ScheduleGenerationRun::class);
    }

    public function facultyTermLoadOverrides(): HasMany
    {
        return $this->hasMany(FacultyTermLoadOverride::class);
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TypeFirstSemester => 'First Semester',
            self::TypeSecondSemester => 'Second Semester',
            self::TypeSpecialTerm => 'Special Term',
        ];
    }

    /**
     * Return term types not yet used in the given Academic Year (ignoring an optional Term record on edit).
     *
     * @return array<string, string>
     */
    public static function availableTypeOptionsForAcademicYear(?int $academicYearId, ?int $ignoreTermId = null): array
    {
        $options = self::typeOptions();

        if ($academicYearId === null || $academicYearId <= 0) {
            return $options;
        }

        $usedTypes = self::query()
            ->where('academic_year_id', $academicYearId)
            ->when($ignoreTermId !== null, fn ($query) => $query->whereKeyNot($ignoreTermId))
            ->pluck('type')
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->all();

        return array_diff_key($options, array_flip($usedTypes));
    }

    public static function duplicateTypeError(int $academicYearId, string $type, ?int $ignoreTermId = null): ?string
    {
        if ($academicYearId <= 0 || trim($type) === '') {
            return null;
        }

        $existing = self::query()
            ->where('academic_year_id', $academicYearId)
            ->where('type', $type)
            ->when($ignoreTermId !== null, fn ($query) => $query->whereKeyNot($ignoreTermId))
            ->first(['id', 'label']);

        if (! $existing instanceof self) {
            return null;
        }

        return sprintf(
            'This academic year already has a %s (%s). Switch to the existing term or choose a different one.',
            self::typeOptions()[$type] ?? $type,
            $existing->label,
        );
    }

    public function typeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? $this->label;
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateClosed => 'Closed',
        ];
    }

    public function stateColor(): string
    {
        return match ($this->state) {
            self::StateActive => 'success',
            self::StateClosed => 'warning',
            default => 'gray',
        };
    }
}
