<?php

namespace App\Models;

use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 */
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

    public const ProcessTermPlanning = 'term_planning';

    public const ProcessRegularOfferingPreparation = 'regular_offering_preparation';

    public const ProcessSpecialOfferingRequest = 'special_offering_request';

    public const ProcessSpecialOfferingApproval = 'special_offering_approval';

    public const ProcessScheduling = 'scheduling';

    public const ProcessScheduleReviewPublication = 'schedule_review_publication';

    public const ProcessEnrollment = 'enrollment';

    public const ProcessAddDropAdjustment = 'add_drop_adjustment';

    public const ProcessClasses = 'classes';

    public const ProcessExaminations = 'examinations';

    public const ProcessGradeEncoding = 'grade_encoding';

    public const ProcessLateGradeEncodingAuthorization = 'late_grade_encoding_authorization';

    public const ProcessGradeFinalization = 'grade_finalization';

    public const ProcessIncCompletionRemoval = 'inc_completion_removal';

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
    public static function academicCalendarWindowProcessOptions(): array
    {
        return [
            self::ProcessTermPlanning => 'Term Planning',
            self::ProcessRegularOfferingPreparation => 'Regular Offering Preparation',
            self::ProcessSpecialOfferingRequest => 'Special Offering Request',
            self::ProcessSpecialOfferingApproval => 'Special Offering Approval',
            self::ProcessScheduling => 'Scheduling',
            self::ProcessScheduleReviewPublication => 'Schedule Review and Publication',
            self::ProcessEnrollment => 'Enrollment',
            self::ProcessAddDropAdjustment => 'Add / Drop / Adjustment',
            self::ProcessClasses => 'Classes',
            self::ProcessExaminations => 'Examination Periods',
            self::ProcessGradeEncoding => 'Grade Encoding',
            self::ProcessLateGradeEncodingAuthorization => 'Late Grade Encoding Authorization',
            self::ProcessGradeFinalization => 'Grade Finalization',
            self::ProcessIncCompletionRemoval => 'INC Completion / Removal',
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

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeAcademicCalendarWindows(Builder $query): Builder
    {
        return $query
            ->where('event_type', self::TypeWindow)
            ->where('scope_type', self::ScopeInstitution)
            ->where('blocks_scheduling', false)
            ->whereNotNull('process_key')
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->whereNull('day_of_week')
            ->whereNull('starts_at')
            ->whereNull('ends_at');
    }

    public function isAcademicCalendarWindow(): bool
    {
        return $this->event_type === self::TypeWindow
            && $this->scope_type === self::ScopeInstitution
            && ! $this->blocks_scheduling
            && $this->process_key !== null
            && $this->start_at !== null
            && $this->end_at !== null
            && $this->day_of_week === null
            && $this->starts_at === null
            && $this->ends_at === null;
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
                'start_at',
                'end_at',
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
