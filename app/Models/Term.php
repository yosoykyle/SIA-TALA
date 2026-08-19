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

    public const TypeSummer = 'SUMMER';

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

    /** @return HasMany<PublishedTimetableVersion, $this> */
    public function timetableVersions(): HasMany
    {
        return $this->hasMany(PublishedTimetableVersion::class);
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
            self::TypeSummer => 'Summer / Special Term',
        ];
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
}
