<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ScheduleChange extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'section_meeting_id',
        'status',
        'old_payload',
        'new_payload',
        'reason',
        'requested_by',
        'approved_by',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_payload' => 'array',
            'new_payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateTargetMeetingData(array $data): array
    {
        $termId = self::integerFormId($data['term_id'] ?? null);
        $sectionMeetingId = self::integerFormId($data['section_meeting_id'] ?? null);
        $errors = [];

        if ($termId === null || ! Term::query()->whereKey($termId)->exists()) {
            $errors['term_id'] = 'Choose a valid term.';
        }

        $sectionMeeting = null;

        if ($sectionMeetingId === null) {
            $errors['section_meeting_id'] = 'Choose a valid official schedule.';
        } else {
            $sectionMeeting = SectionMeeting::query()
                ->select(['id', 'term_id'])
                ->whereKey($sectionMeetingId)
                ->first();

            if (! $sectionMeeting instanceof SectionMeeting) {
                $errors['section_meeting_id'] = 'Choose a valid official schedule.';
            }
        }

        if ($errors === [] && $sectionMeeting instanceof SectionMeeting && $sectionMeeting->term_id !== $termId) {
            $errors['section_meeting_id'] = 'Choose an official schedule from the selected term.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['term_id'] = $termId;
        $data['section_meeting_id'] = $sectionMeetingId;

        return $data;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function sectionMeeting(): BelongsTo
    {
        return $this->belongsTo(SectionMeeting::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
}
