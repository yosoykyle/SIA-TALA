<?php

namespace App\Actions\Scheduling;

use App\Models\FacultySubjectEligibility;
use App\Models\SectionMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SectionMeetingAssignmentService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepareForCreate(array $data, User $registrar, ?CarbonImmutable $committedAt = null): array
    {
        $payload = $this->normalizeAssignmentData($data);

        $this->assertNoConflicts($payload);

        return [
            ...$payload,
            'schedule_generation_run_id' => null,
            'committed_by' => $registrar->id,
            'committed_at' => $committedAt ?? CarbonImmutable::now(config('app.timezone')),
        ];
    }

    /**
     * @param  array<string, mixed>  $newPayload
     * @return array{faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string}
     *
     * @throws ValidationException
     */
    public function prepareForScheduleChange(SectionMeeting $sectionMeeting, array $newPayload): array
    {
        $payload = [
            'term_id' => $sectionMeeting->term_id,
            'section_id' => $sectionMeeting->section_id,
            'subject_id' => $sectionMeeting->subject_id,
            'faculty_id' => array_key_exists('faculty_id', $newPayload)
                ? $newPayload['faculty_id']
                : $sectionMeeting->faculty_id,
            'room' => $newPayload['room'] ?? null,
            'day_of_week' => $newPayload['day_of_week'] ?? null,
            'starts_at' => $newPayload['starts_at'] ?? null,
            'ends_at' => $newPayload['ends_at'] ?? null,
            'modality' => $newPayload['modality'] ?? null,
        ];

        $normalized = $this->normalizeAssignmentData($payload);

        $this->assertNoConflicts($normalized, $sectionMeeting->id);

        return [
            'faculty_id' => $normalized['faculty_id'],
            'room' => $normalized['room'],
            'day_of_week' => $normalized['day_of_week'],
            'starts_at' => $normalized['starts_at'],
            'ends_at' => $normalized['ends_at'],
            'modality' => $normalized['modality'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{term_id:int, section_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string}
     *
     * @throws ValidationException
     */
    private function normalizeAssignmentData(array $data): array
    {
        $payload = [
            'term_id' => $this->integerValue($data['term_id'] ?? null),
            'section_id' => $this->integerValue($data['section_id'] ?? null),
            'subject_id' => $this->integerValue($data['subject_id'] ?? null),
            'faculty_id' => $this->integerValue($data['faculty_id'] ?? null),
            'room' => $this->stringValue($data['room'] ?? null),
            'day_of_week' => $this->integerValue($data['day_of_week'] ?? null),
            'starts_at' => $this->timeValue($data['starts_at'] ?? null),
            'ends_at' => $this->timeValue($data['ends_at'] ?? null),
            'modality' => $this->stringValue($data['modality'] ?? null),
        ];

        $this->assertRequired($payload, ['term_id', 'section_id', 'subject_id', 'faculty_id', 'day_of_week', 'starts_at', 'ends_at', 'modality']);

        if ($payload['day_of_week'] < 1 || $payload['day_of_week'] > 7) {
            throw ValidationException::withMessages([
                'day_of_week' => 'Schedule day must be from Monday to Sunday.',
            ]);
        }

        if (! in_array($payload['modality'], ['on_site', 'online', 'modular', 'blended'], true)) {
            throw ValidationException::withMessages([
                'modality' => 'Unsupported schedule modality.',
            ]);
        }

        if ($payload['starts_at'] >= $payload['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'End time must be after the start time.',
            ]);
        }

        if ($this->requiresRoom($payload['modality']) && $payload['room'] === null) {
            throw ValidationException::withMessages([
                'room' => 'A room is required for on-site or blended meetings.',
            ]);
        }

        $this->assertFacultyIsEligible($payload);

        return $payload;
    }

    /**
     * @param  array{term_id:int, section_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string}  $payload
     *
     * @throws ValidationException
     */
    private function assertNoConflicts(array $payload, ?int $exceptSectionMeetingId = null): void
    {
        $overlappingMeetings = SectionMeeting::query()
            ->where('term_id', $payload['term_id'])
            ->where('day_of_week', $payload['day_of_week'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->when($exceptSectionMeetingId !== null, fn ($query) => $query->whereKeyNot($exceptSectionMeetingId));

        if ((clone $overlappingMeetings)->where('faculty_id', $payload['faculty_id'])->exists()) {
            throw ValidationException::withMessages([
                'faculty_id' => 'The selected faculty already has a committed meeting during this time.',
            ]);
        }

        if ($payload['room'] !== null && $this->requiresRoom($payload['modality']) && (clone $overlappingMeetings)->where('room', $payload['room'])->exists()) {
            throw ValidationException::withMessages([
                'room' => 'The selected room already has a committed meeting during this time.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     *
     * @throws ValidationException
     */
    private function assertRequired(array $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if ($payload[$field] === null) {
                throw ValidationException::withMessages([
                    $field => 'This field is required.',
                ]);
            }
        }
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }

    private function requiresRoom(string $modality): bool
    {
        return in_array($modality, ['on_site', 'blended'], true);
    }

    /**
     * @param  array{term_id:int, section_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string}  $payload
     *
     * @throws ValidationException
     */
    private function assertFacultyIsEligible(array $payload): void
    {
        if (FacultySubjectEligibility::isActiveFor(
            facultyId: $payload['faculty_id'],
            subjectId: $payload['subject_id'],
            termId: $payload['term_id'],
        )) {
            return;
        }

        throw ValidationException::withMessages([
            'faculty_id' => 'The selected faculty is not approved to teach this subject for the selected term.',
        ]);
    }
}
