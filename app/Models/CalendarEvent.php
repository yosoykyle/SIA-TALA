<?php

namespace App\Models;

use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, LogsActivity;

    public const TypeWindow = 'WINDOW';

    public const TypeHoliday = 'HOLIDAY';

    public const TypeNoClass = 'NO_CLASS';

    public const TypeMakeUp = 'MAKE_UP';

    public const TypeBreak = 'BREAK';

    public const TypeExam = 'EXAM';

    public const TypeUnavailable = 'UNAVAILABLE';

    public const ScopeInstitution = 'INSTITUTION';

    public const ScopeRoom = 'ROOM';

    public const ScopeFaculty = 'FACULTY';

    public const StateActive = 'ACTIVE';

    public const StateInactive = 'INACTIVE';

    public const ProcessMasterSchedule = 'master_schedule';

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
            'day_of_week' => 'integer',
            'blocks_scheduling' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function recurringBlockTypeOptions(): array
    {
        return [
            self::TypeUnavailable => 'Unavailable',
            self::TypeBreak => 'Break',
            self::TypeNoClass => 'No Class',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function recurringBlockScopeOptions(): array
    {
        return [
            self::ScopeInstitution => 'Institution',
            self::ScopeRoom => 'Room',
            self::ScopeFaculty => 'Faculty',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StateActive => 'Active',
            self::StateInactive => 'Inactive',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function dayOptions(): array
    {
        return SectionMeeting::dayOptions();
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeRecurringSchedulingBlocks(Builder $query): Builder
    {
        return $query
            ->where('process_key', self::ProcessMasterSchedule)
            ->where('blocks_scheduling', true)
            ->whereNotNull('day_of_week')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at');
    }

    public function isFacultyOwnedBy(User $user): bool
    {
        return $this->state === self::StateActive
            && $this->event_type === self::TypeUnavailable
            && $this->scope_type === self::ScopeFaculty
            && (int) $this->faculty_user_id === (int) $user->id;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('scheduling')
            ->logOnly([
                'term_id',
                'event_type',
                'scope_type',
                'room_id',
                'faculty_user_id',
                'process_key',
                'day_of_week',
                'starts_at',
                'ends_at',
                'blocks_scheduling',
                'state',
                'authority',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
