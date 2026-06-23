<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionDeliveryGroup;
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

        $this->assertTermIsNotPublished($payload['term_id']);
        $this->assertNoConflicts($payload);

        $timestamp = $committedAt ?? CarbonImmutable::now(config('app.timezone'));
        $overrideAttributes = $this->availabilityOverrideAttributes(
            payload: $payload,
            registrar: $registrar,
            timestamp: $timestamp,
            includeNulls: false,
        );
        unset($payload['availability_override_reason']);

        return [
            ...$payload,
            ...$overrideAttributes,
            'schedule_generation_run_id' => null,
            'committed_by' => $registrar->id,
            'committed_at' => $timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, availability_override_reason:?string}
     *
     * @throws ValidationException
     */
    private function normalizeAssignmentData(array $data): array
    {
        $payload = [
            'term_id' => $this->integerValue($data['term_id'] ?? null),
            'section_id' => $this->integerValue($data['section_id'] ?? null),
            'section_delivery_group_id' => $this->integerValue($data['section_delivery_group_id'] ?? null),
            'subject_id' => $this->integerValue($data['subject_id'] ?? null),
            'faculty_id' => $this->integerValue($data['faculty_id'] ?? null),
            'room' => $this->stringValue($data['room'] ?? null),
            'day_of_week' => $this->integerValue($data['day_of_week'] ?? null),
            'starts_at' => $this->timeValue($data['starts_at'] ?? null),
            'ends_at' => $this->timeValue($data['ends_at'] ?? null),
            'modality' => $this->stringValue($data['modality'] ?? null),
            'availability_override_reason' => $this->stringValue($data['availability_override_reason'] ?? null),
        ];

        $this->assertRequired($payload, ['term_id', 'section_id', 'section_delivery_group_id', 'subject_id', 'faculty_id', 'day_of_week', 'starts_at', 'ends_at']);

        $deliveryGroup = $this->deliveryGroupFor($payload);
        $payload['modality'] ??= $deliveryGroup->modality;

        $this->assertRequired($payload, ['modality']);

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

        if ($payload['modality'] !== $deliveryGroup->modality) {
            throw ValidationException::withMessages([
                'modality' => 'Schedule modality must match the selected section delivery group.',
            ]);
        }

        if ($payload['starts_at'] >= $payload['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'End time must be after the start time.',
            ]);
        }

        if ($payload['availability_override_reason'] !== null && mb_strlen($payload['availability_override_reason']) > 1000) {
            throw ValidationException::withMessages([
                'availability_override_reason' => 'Review reason may not be greater than 1000 characters.',
            ]);
        }

        if ($payload['room'] === null && filled($deliveryGroup->room)) {
            $payload['room'] = (string) $deliveryGroup->room;
        }

        if (filled($deliveryGroup->room) && $payload['room'] !== (string) $deliveryGroup->room) {
            throw ValidationException::withMessages([
                'room' => 'Schedule room must match the selected section delivery group fixed room.',
            ]);
        }

        if (((bool) $deliveryGroup->room_required || $this->requiresRoom($payload['modality'])) && $payload['room'] === null) {
            throw ValidationException::withMessages([
                'room' => 'A room is required for on-site or blended meetings.',
            ]);
        }

        $this->assertFacultyIsEligible($payload);

        return $payload;
    }

    /**
     * @param  array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, availability_override_reason?:?string}  $payload
     *
     * @throws ValidationException
     */
    private function assertNoConflicts(array $payload, ?int $exceptSectionMeetingId = null): void
    {
        $overlappingMeetings = SectionMeeting::query()
            ->activeOfficial()
            ->where('term_id', $payload['term_id'])
            ->where('day_of_week', $payload['day_of_week'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->when($exceptSectionMeetingId !== null, fn ($query) => $query->whereKeyNot($exceptSectionMeetingId));

        if ((clone $overlappingMeetings)->where('section_delivery_group_id', $payload['section_delivery_group_id'])->exists()) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'The selected section delivery group already has a committed meeting during this time.',
            ]);
        }

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
     * @param  array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, availability_override_reason?:?string}  $payload
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

    /**
     * @param  array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, availability_override_reason?:?string}  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function availabilityOverrideAttributes(
        array $payload,
        ?User $registrar,
        CarbonImmutable $timestamp,
        bool $includeNulls,
        array $context = [],
    ): array {
        $issue = $this->facultyAvailabilityIssue($payload);

        if ($issue === null) {
            return $includeNulls
                ? [
                    'availability_override_reason' => null,
                    'availability_override_by' => null,
                    'availability_override_at' => null,
                    'availability_override_payload' => null,
                ]
                : [];
        }

        $reason = $payload['availability_override_reason'] ?? null;

        throw ValidationException::withMessages([
            'faculty_id' => $this->availabilityHardBlockMessage($issue['type'], $reason),
        ]);
    }

    /**
     * @param  array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:string, availability_override_reason?:?string}  $payload
     * @return array<string, mixed>|null
     */
    private function facultyAvailabilityIssue(array $payload): ?array
    {
        $submission = FacultyAvailabilitySubmission::query()
            ->with('windows')
            ->where('term_id', $payload['term_id'])
            ->where('faculty_id', $payload['faculty_id'])
            ->whereIn('status', [
                FacultyAvailabilitySubmission::StatusSubmitted,
                FacultyAvailabilitySubmission::StatusLocked,
            ])
            ->orderByDesc('version')
            ->first();

        if (! $submission instanceof FacultyAvailabilitySubmission || $submission->windows->isEmpty()) {
            return [
                'type' => 'missing_submitted_or_locked_availability',
                'message' => 'The selected faculty has no submitted or locked availability window for this term.',
                'submission_id' => $submission?->id,
                'submission_status' => $submission?->status,
                'submission_version' => $submission?->version,
                'available_windows' => [],
            ];
        }

        $windows = $submission->windows
            ->map(fn (FacultyAvailabilityWindow $window): array => [
                'day_of_week' => (int) $window->day_of_week,
                'starts_at' => $this->timeValue($window->starts_at),
                'ends_at' => $this->timeValue($window->ends_at),
                'notes' => $window->notes,
            ])
            ->values()
            ->all();

        $insideWindow = collect($windows)->contains(fn (array $window): bool => $window['day_of_week'] === $payload['day_of_week']
            && $window['starts_at'] <= $payload['starts_at']
            && $window['ends_at'] >= $payload['ends_at']);

        if ($insideWindow) {
            return null;
        }

        return [
            'type' => 'outside_availability_window',
            'message' => 'The proposed meeting is outside the selected faculty submitted or locked availability windows.',
            'submission_id' => (int) $submission->id,
            'submission_status' => $submission->status,
            'submission_version' => (int) $submission->version,
            'available_windows' => $windows,
        ];
    }

    private function availabilityHardBlockMessage(string $issueType, ?string $reason): string
    {
        $suffix = $reason !== null ? ' Review notes do not override this hard scheduling constraint.' : '';

        return match ($issueType) {
            'missing_submitted_or_locked_availability' => 'The selected faculty has no submitted or locked availability for this term.'.$suffix,
            default => 'The proposed meeting is outside the selected faculty availability.'.$suffix,
        };
    }

    /**
     * @throws ValidationException
     */
    private function assertTermIsNotPublished(int $termId): void
    {
        if (! ScheduleGenerationRun::query()
            ->where('term_id', $termId)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'term_id' => 'This term already has a published schedule. Publish a superseding schedule run for post-publication corrections.',
        ]);
    }

    /**
     * @param  array{term_id:int, section_id:int, section_delivery_group_id:int, subject_id:int, faculty_id:int, room:string|null, day_of_week:int, starts_at:string, ends_at:string, modality:?string, availability_override_reason:?string}  $payload
     *
     * @throws ValidationException
     */
    private function deliveryGroupFor(array $payload): SectionDeliveryGroup
    {
        $deliveryGroup = SectionDeliveryGroup::query()
            ->with('section')
            ->find($payload['section_delivery_group_id']);

        if (! $deliveryGroup instanceof SectionDeliveryGroup) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'Choose a valid section delivery group.',
            ]);
        }

        if ((int) $deliveryGroup->section_id !== $payload['section_id']) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'The selected delivery group does not belong to the selected section.',
            ]);
        }

        if ((int) $deliveryGroup->section?->term_id !== $payload['term_id']) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'The selected delivery group does not belong to the selected term.',
            ]);
        }

        if ($deliveryGroup->status !== SectionDeliveryGroup::StatusActive) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'Only active section delivery groups can be scheduled.',
            ]);
        }

        if ((int) $deliveryGroup->assigned_count > (int) $deliveryGroup->capacity) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'The selected section delivery group is already over assigned capacity.',
            ]);
        }

        return $deliveryGroup;
    }
}
