<?php

namespace App\Models;

use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
