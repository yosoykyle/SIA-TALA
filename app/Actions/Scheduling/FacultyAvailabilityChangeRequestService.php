<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FacultyAvailabilityChangeRequestService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function requestChange(User $faculty, FacultyAvailabilitySubmission $submission, array $data): FacultyAvailabilityChangeRequest
    {
        if (! $faculty->can('submit-faculty-availability')) {
            throw new AuthorizationException('Only faculty users can request availability changes.');
        }

        $payload = [
            'submission_id' => $submission->id,
            'reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
            'requested_windows' => $this->windowsPayload($data['requested_windows'] ?? $data['windows'] ?? []),
        ];

        $validator = Validator::make($payload, [
            'submission_id' => ['required', 'integer', 'exists:faculty_availability_submissions,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'requested_windows' => ['required', 'array', 'min:1'],
            'requested_windows.*.day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'requested_windows.*.starts_at' => ['required', 'date_format:H:i:s'],
            'requested_windows.*.ends_at' => ['required', 'date_format:H:i:s'],
            'requested_windows.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($faculty, $submission, $payload): void {
            $freshSubmission = FacultyAvailabilitySubmission::query()
                ->with('windows')
                ->find($submission->id);

            if (! $freshSubmission instanceof FacultyAvailabilitySubmission) {
                return;
            }

            if ((int) $freshSubmission->faculty_id !== $faculty->id) {
                $validator->errors()->add('submission_id', 'Faculty may request changes only for their own availability.');
            }

            if (! in_array($freshSubmission->status, [FacultyAvailabilitySubmission::StatusSubmitted, FacultyAvailabilitySubmission::StatusLocked], true)) {
                $validator->errors()->add('submission_id', 'Only submitted or locked availability can receive a change request.');
            }

            if (! $this->isLatestSubmissionVersion($freshSubmission)) {
                $validator->errors()->add('submission_id', 'Availability change requests must target the latest faculty availability version.');
            }

            if (FacultyAvailabilityChangeRequest::query()
                ->where('submission_id', $freshSubmission->id)
                ->where('status', FacultyAvailabilityChangeRequest::StatusPending)
                ->exists()) {
                $validator->errors()->add('submission_id', 'A pending availability change request already exists for this submission.');
            }

            $this->appendWindowTimeErrors($validator, $payload['requested_windows']);
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($faculty, $submission, $payload): FacultyAvailabilityChangeRequest {
            /** @var FacultyAvailabilitySubmission $lockedSubmission */
            $lockedSubmission = FacultyAvailabilitySubmission::query()
                ->with('windows')
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $sourceWindows = $this->windowsSnapshot($lockedSubmission);

            return FacultyAvailabilityChangeRequest::query()->create([
                'term_id' => $lockedSubmission->term_id,
                'faculty_id' => $lockedSubmission->faculty_id,
                'submission_id' => $lockedSubmission->id,
                'status' => FacultyAvailabilityChangeRequest::StatusPending,
                'reason' => $payload['reason'],
                'source_windows' => $sourceWindows,
                'requested_windows' => $payload['requested_windows'],
                'requested_by' => $faculty->id,
            ])->fresh(['term', 'faculty', 'submission', 'requester']);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approve(FacultyAvailabilityChangeRequest $request, User $registrar, ?string $reviewNote = null): FacultyAvailabilityChangeRequest
    {
        $this->authorizeAvailabilityManager($registrar);

        return DB::transaction(function () use ($request, $registrar, $reviewNote): FacultyAvailabilityChangeRequest {
            /** @var FacultyAvailabilityChangeRequest $lockedRequest */
            $lockedRequest = FacultyAvailabilityChangeRequest::query()
                ->with('submission')
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->assertPending($lockedRequest);

            $sourceSubmission = FacultyAvailabilitySubmission::query()
                ->lockForUpdate()
                ->findOrFail($lockedRequest->submission_id);

            if (! $this->isLatestSubmissionVersion($sourceSubmission)) {
                throw ValidationException::withMessages([
                    'submission_id' => 'This request is stale because a newer availability version already exists.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $nextVersion = ((int) FacultyAvailabilitySubmission::query()
                ->where('term_id', $sourceSubmission->term_id)
                ->where('faculty_id', $sourceSubmission->faculty_id)
                ->max('version')) + 1;

            $revision = FacultyAvailabilitySubmission::query()->create([
                'term_id' => $sourceSubmission->term_id,
                'availability_period_id' => $sourceSubmission->availability_period_id,
                'faculty_id' => $sourceSubmission->faculty_id,
                'status' => FacultyAvailabilitySubmission::StatusLocked,
                'version' => $nextVersion,
                'submitted_at' => $timestamp,
                'locked_at' => $timestamp,
                'parent_submission_id' => $sourceSubmission->id,
                'change_reason' => $lockedRequest->reason,
                'approved_by' => $registrar->id,
                'approved_at' => $timestamp,
            ]);

            foreach ($lockedRequest->requested_windows ?? [] as $window) {
                $revision->windows()->create($this->windowAttributes($window));
            }

            $lockedRequest->forceFill([
                'status' => FacultyAvailabilityChangeRequest::StatusApproved,
                'reviewed_by' => $registrar->id,
                'reviewed_at' => $timestamp,
                'review_note' => $this->nullableNote($reviewNote),
                'creates_submission_id' => $revision->id,
            ])->save();

            $this->recordActivity($lockedRequest, $registrar, 'faculty_availability_change_approved', $timestamp);

            return $lockedRequest->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reject(FacultyAvailabilityChangeRequest $request, User $registrar, ?string $reviewNote = null): FacultyAvailabilityChangeRequest
    {
        $this->authorizeAvailabilityManager($registrar);

        return DB::transaction(function () use ($request, $registrar, $reviewNote): FacultyAvailabilityChangeRequest {
            /** @var FacultyAvailabilityChangeRequest $lockedRequest */
            $lockedRequest = FacultyAvailabilityChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->assertPending($lockedRequest);

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $lockedRequest->forceFill([
                'status' => FacultyAvailabilityChangeRequest::StatusRejected,
                'reviewed_by' => $registrar->id,
                'reviewed_at' => $timestamp,
                'review_note' => $this->nullableNote($reviewNote),
            ])->save();

            $this->recordActivity($lockedRequest, $registrar, 'faculty_availability_change_rejected', $timestamp);

            return $lockedRequest->refresh();
        });
    }

    private function authorizeAvailabilityManager(User $registrar): void
    {
        if ($registrar->can('review-lock-faculty-availability')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can review faculty availability change requests.');
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(FacultyAvailabilityChangeRequest $request): void
    {
        if ($request->status === FacultyAvailabilityChangeRequest::StatusPending) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Only pending availability change requests can be reviewed.',
        ]);
    }

    private function isLatestSubmissionVersion(FacultyAvailabilitySubmission $submission): bool
    {
        $latestVersion = FacultyAvailabilitySubmission::query()
            ->where('term_id', $submission->term_id)
            ->where('faculty_id', $submission->faculty_id)
            ->max('version');

        return (int) $latestVersion === (int) $submission->version;
    }

    /**
     * @return list<array{day_of_week:int|null, starts_at:string|null, ends_at:string|null, notes:string|null}>
     */
    private function windowsPayload(mixed $windows): array
    {
        if (! is_array($windows)) {
            return [];
        }

        return collect($windows)
            ->map(fn (mixed $window): array => is_array($window) ? [
                'day_of_week' => $this->integerValue($window['day_of_week'] ?? null),
                'starts_at' => $this->timeValue($window['starts_at'] ?? null),
                'ends_at' => $this->timeValue($window['ends_at'] ?? null),
                'notes' => filled($window['notes'] ?? null) ? trim((string) $window['notes']) : null,
            ] : [
                'day_of_week' => null,
                'starts_at' => null,
                'ends_at' => null,
                'notes' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{day_of_week:int|null, starts_at:string|null, ends_at:string|null, notes:string|null}>  $windows
     */
    private function appendWindowTimeErrors($validator, array $windows): void
    {
        foreach ($windows as $index => $window) {
            if ($window['starts_at'] !== null
                && $window['ends_at'] !== null
                && $window['starts_at'] >= $window['ends_at']) {
                $validator->errors()->add("requested_windows.{$index}.ends_at", 'Availability window end time must be after the start time.');
            }
        }

        foreach ($windows as $leftIndex => $left) {
            foreach ($windows as $rightIndex => $right) {
                if ($rightIndex <= $leftIndex) {
                    continue;
                }

                if ($left['day_of_week'] === null
                    || $right['day_of_week'] === null
                    || $left['starts_at'] === null
                    || $left['ends_at'] === null
                    || $right['starts_at'] === null
                    || $right['ends_at'] === null) {
                    continue;
                }

                if ($left['day_of_week'] === $right['day_of_week']
                    && $left['starts_at'] < $right['ends_at']
                    && $left['ends_at'] > $right['starts_at']) {
                    $validator->errors()->add("requested_windows.{$rightIndex}.starts_at", 'Availability windows cannot overlap on the same day.');
                }
            }
        }
    }

    /**
     * @return list<array{day_of_week:int, starts_at:string, ends_at:string, notes:?string}>
     */
    private function windowsSnapshot(FacultyAvailabilitySubmission $submission): array
    {
        return $submission->windows
            ->sortBy([
                ['day_of_week', 'asc'],
                ['starts_at', 'asc'],
            ])
            ->map(fn ($window): array => [
                'day_of_week' => (int) $window->day_of_week,
                'starts_at' => $this->timeValue($window->starts_at) ?? (string) $window->starts_at,
                'ends_at' => $this->timeValue($window->ends_at) ?? (string) $window->ends_at,
                'notes' => $window->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array{day_of_week:int, starts_at:string, ends_at:string, notes:?string}
     */
    private function windowAttributes(array $window): array
    {
        return [
            'day_of_week' => (int) $window['day_of_week'],
            'starts_at' => (string) $window['starts_at'],
            'ends_at' => (string) $window['ends_at'],
            'notes' => filled($window['notes'] ?? null) ? (string) $window['notes'] : null,
        ];
    }

    private function nullableNote(?string $note): ?string
    {
        return filled($note) ? trim((string) $note) : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function recordActivity(
        FacultyAvailabilityChangeRequest $request,
        User $actor,
        string $event,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Faculty availability change request reviewed.',
            'subject_type' => FacultyAvailabilityChangeRequest::class,
            'subject_id' => $request->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'status_after' => $request->status,
                'term_id' => $request->term_id,
                'faculty_id' => $request->faculty_id,
                'creates_submission_id' => $request->creates_submission_id,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
