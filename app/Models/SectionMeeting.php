<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionMeeting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'room',
        'day_of_week',
        'starts_at',
        'ends_at',
        'modality',
        'schedule_generation_run_id',
        'committed_by',
        'committed_at',
        'availability_override_reason',
        'availability_override_by',
        'availability_override_at',
        'availability_override_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'availability_override_at' => 'datetime',
            'availability_override_payload' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function dayOptions(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return [
            'on_site' => 'On-site',
            'online' => 'Online',
            'modular' => 'Modular',
            'blended' => 'Blended',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function scheduleChangeOptionsFor(mixed $termId): array
    {
        $termId = self::integerFormId($termId);

        if ($termId === null) {
            return [];
        }

        return self::query()
            ->with(['section', 'subject', 'faculty'])
            ->where('term_id', $termId)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (SectionMeeting $sectionMeeting): array => [
                $sectionMeeting->getKey() => self::scheduleChangeOptionLabel($sectionMeeting),
            ])
            ->all();
    }

    public static function scheduleChangeOptionLabel(SectionMeeting $sectionMeeting): string
    {
        $sectionMeeting->loadMissing(['section', 'subject', 'faculty']);

        $day = self::dayOptions()[$sectionMeeting->day_of_week] ?? 'Unscheduled';
        $time = trim(implode('-', array_filter([
            self::timeLabel($sectionMeeting->starts_at),
            self::timeLabel($sectionMeeting->ends_at),
        ])));

        return collect([
            $sectionMeeting->section?->name,
            $sectionMeeting->subject?->code,
            $sectionMeeting->faculty?->name,
            $day,
            $time !== '' ? $time : null,
            $sectionMeeting->room,
        ])->filter()->implode(' | ');
    }

    private static function integerFormId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function timeLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function availabilityOverrideAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'availability_override_by');
    }

    public function scheduleGenerationRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class);
    }
}
