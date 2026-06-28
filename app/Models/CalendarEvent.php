<?php

namespace App\Models;

use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    public const TypeWindow = 'WINDOW';

    public const TypeHoliday = 'HOLIDAY';

    public const TypeNoClass = 'NO_CLASS';

    public const TypeMakeUp = 'MAKE_UP';

    public const TypeBreak = 'BREAK';

    public const TypeExam = 'EXAM';

    public const ScopeInstitution = 'INSTITUTION';

    public const ScopeRoom = 'ROOM';

    public const ScopeFaculty = 'FACULTY';

    public const StateActive = 'ACTIVE';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'event_type',
        'scope_type',
        'room_id',
        'faculty_user_id',
        'process_key',
        'start_at',
        'end_at',
        'day_of_week',
        'starts_at',
        'ends_at',
        'blocks_scheduling',
        'state',
        'authority',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'starts_at' => 'datetime:H:i:s',
            'ends_at' => 'datetime:H:i:s',
            'blocks_scheduling' => 'boolean',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
