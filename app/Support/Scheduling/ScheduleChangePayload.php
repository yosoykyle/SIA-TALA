<?php

namespace App\Support\Scheduling;

use App\Models\SectionMeeting;
use Illuminate\Support\Arr;

class ScheduleChangePayload
{
    /**
     * @return array{faculty_id:int|null, room:string|null, day_of_week:int|null, starts_at:string|null, ends_at:string|null, modality:string|null}
     */
    public static function fromSectionMeeting(?SectionMeeting $sectionMeeting): array
    {
        if (! $sectionMeeting instanceof SectionMeeting) {
            return [
                'faculty_id' => null,
                'room' => null,
                'day_of_week' => null,
                'starts_at' => null,
                'ends_at' => null,
                'modality' => null,
            ];
        }

        return [
            'faculty_id' => $sectionMeeting->faculty_id,
            'room' => $sectionMeeting->room,
            'day_of_week' => $sectionMeeting->day_of_week,
            'starts_at' => self::timeValue($sectionMeeting->starts_at),
            'ends_at' => self::timeValue($sectionMeeting->ends_at),
            'modality' => $sectionMeeting->modality,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{faculty_id:int|null, room:string|null, day_of_week:int|null, starts_at:string|null, ends_at:string|null, modality:string|null}
     */
    public static function fromFormData(array $data): array
    {
        return [
            'faculty_id' => filled($data['new_faculty_id'] ?? null) ? (int) $data['new_faculty_id'] : null,
            'room' => filled($data['new_room'] ?? null) ? (string) $data['new_room'] : null,
            'day_of_week' => filled($data['new_day_of_week'] ?? null) ? (int) $data['new_day_of_week'] : null,
            'starts_at' => filled($data['new_starts_at'] ?? null) ? self::timeValue($data['new_starts_at']) : null,
            'ends_at' => filled($data['new_ends_at'] ?? null) ? self::timeValue($data['new_ends_at']) : null,
            'modality' => filled($data['new_modality'] ?? null) ? (string) $data['new_modality'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{faculty_id:int|null, room:string|null, day_of_week:int|null, starts_at:string|null, ends_at:string|null, modality:string|null}
     */
    public static function normalize(array $payload): array
    {
        $payload = Arr::only($payload, ['faculty_id', 'room', 'day_of_week', 'starts_at', 'ends_at', 'modality']);

        return [
            'faculty_id' => filled($payload['faculty_id'] ?? null) ? (int) $payload['faculty_id'] : null,
            'room' => filled($payload['room'] ?? null) ? (string) $payload['room'] : null,
            'day_of_week' => filled($payload['day_of_week'] ?? null) ? (int) $payload['day_of_week'] : null,
            'starts_at' => filled($payload['starts_at'] ?? null) ? self::timeValue($payload['starts_at']) : null,
            'ends_at' => filled($payload['ends_at'] ?? null) ? self::timeValue($payload['ends_at']) : null,
            'modality' => filled($payload['modality'] ?? null) ? (string) $payload['modality'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripFormOnlyFields(array $data): array
    {
        return Arr::except($data, [
            'new_faculty_id',
            'new_room',
            'new_day_of_week',
            'new_starts_at',
            'new_ends_at',
            'new_modality',
        ]);
    }

    private static function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }
}
