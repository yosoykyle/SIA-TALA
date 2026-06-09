<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FacultyAvailabilityService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function preparePeriodData(array $data, User $registrar, ?FacultyAvailabilityPeriod $period = null): array
    {
        $this->authorizeAvailabilityManager($registrar);

        if ($period?->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Locked faculty availability periods cannot be edited.',
            ]);
        }

        $payload = [
            'term_id' => $this->integerValue($data['term_id'] ?? $period?->term_id),
            'opens_at' => $this->dateTimeValue($data['opens_at'] ?? $period?->opens_at),
            'closes_at' => $this->dateTimeValue($data['closes_at'] ?? $period?->closes_at),
        ];

        $validator = Validator::make($payload, [
            'term_id' => [
                'required',
                'integer',
                'exists:terms,id',
                Rule::unique('faculty_availability_periods', 'term_id')->ignore($period?->id),
            ],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $term = Term::query()->find($payload['term_id']);

            if (! $term instanceof Term) {
                return;
            }

            foreach (['term_name', 'term_start_date', 'term_end_date', 'scheduling_starts_at'] as $field) {
                if (blank($term->{$field})) {
                    $validator->errors()->add('term_id', "Term is missing {$field}.");
                }
            }

            if ($term->scheduling_starts_at !== null && CarbonImmutable::parse($payload['closes_at'])->greaterThan($term->scheduling_starts_at)) {
                $validator->errors()->add('closes_at', 'Availability period must close on or before scheduling starts.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return [
            ...$payload,
            'status' => $period?->status ?? FacultyAvailabilityPeriod::StatusOpen,
            'created_by' => $period?->created_by ?? $registrar->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function submitAvailability(array $data, User $faculty): FacultyAvailabilitySubmission
    {
        if (! $faculty->can('submit-faculty-availability')) {
            throw new AuthorizationException('Only faculty users can submit availability.');
        }

        $payload = [
            'availability_period_id' => $this->integerValue($data['availability_period_id'] ?? null),
            'windows' => $this->windowsPayload($data['windows'] ?? []),
        ];

        $validator = Validator::make($payload, [
            'availability_period_id' => ['required', 'integer', 'exists:faculty_availability_periods,id'],
            'windows' => ['required', 'array', 'min:1'],
            'windows.*.day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'windows.*.starts_at' => ['required', 'date_format:H:i:s'],
            'windows.*.ends_at' => ['required', 'date_format:H:i:s'],
            'windows.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($payload, $faculty): void {
            $period = FacultyAvailabilityPeriod::query()
                ->with('term')
                ->find($payload['availability_period_id']);

            if (! $period instanceof FacultyAvailabilityPeriod) {
                return;
            }

            if ($period->status !== FacultyAvailabilityPeriod::StatusOpen) {
                $validator->errors()->add('availability_period_id', 'Availability period is not open.');
            }

            $now = CarbonImmutable::now(config('app.timezone'));

            if ($now->lessThan($period->opens_at) || $now->greaterThan($period->closes_at)) {
                $validator->errors()->add('availability_period_id', 'Faculty availability can only be submitted during the open period.');
            }

            if (FacultyAvailabilitySubmission::query()
                ->where('term_id', $period->term_id)
                ->where('faculty_id', $faculty->id)
                ->whereIn('status', [FacultyAvailabilitySubmission::StatusSubmitted, FacultyAvailabilitySubmission::StatusLocked])
                ->exists()) {
                $validator->errors()->add('availability_period_id', 'Faculty already submitted availability for this term.');
            }

            $this->appendWindowTimeErrors($validator, $payload['windows']);
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($payload, $faculty): FacultyAvailabilitySubmission {
            /** @var FacultyAvailabilityPeriod $period */
            $period = FacultyAvailabilityPeriod::query()->lockForUpdate()->findOrFail($payload['availability_period_id']);
            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $submission = FacultyAvailabilitySubmission::query()->create([
                'term_id' => $period->term_id,
                'availability_period_id' => $period->id,
                'faculty_id' => $faculty->id,
                'status' => FacultyAvailabilitySubmission::StatusSubmitted,
                'version' => 1,
                'submitted_at' => $timestamp,
            ]);

            foreach ($payload['windows'] as $window) {
                $submission->windows()->create($window);
            }

            return $submission->fresh(['windows', 'term', 'availabilityPeriod']);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function lockSubmission(FacultyAvailabilitySubmission $submission, User $registrar): FacultyAvailabilitySubmission
    {
        $this->authorizeAvailabilityManager($registrar);

        return DB::transaction(function () use ($submission, $registrar): FacultyAvailabilitySubmission {
            /** @var FacultyAvailabilitySubmission $lockedSubmission */
            $lockedSubmission = FacultyAvailabilitySubmission::query()
                ->withCount('windows')
                ->lockForUpdate()
                ->findOrFail($submission->id);

            if ($lockedSubmission->status !== FacultyAvailabilitySubmission::StatusSubmitted) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted availability can be locked.',
                ]);
            }

            if ($lockedSubmission->windows_count < 1) {
                throw ValidationException::withMessages([
                    'windows' => 'Availability must have at least one window before locking.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $lockedSubmission->forceFill([
                'status' => FacultyAvailabilitySubmission::StatusLocked,
                'locked_at' => $timestamp,
                'approved_by' => $registrar->id,
                'approved_at' => $timestamp,
            ])->save();

            return $lockedSubmission->fresh(['windows', 'approver']);
        });
    }

    private function authorizeAvailabilityManager(User $registrar): void
    {
        if ($registrar->can('review-lock-faculty-availability')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can manage faculty availability.');
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
                $validator->errors()->add("windows.{$index}.ends_at", 'Availability window end time must be after the start time.');
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
                    $validator->errors()->add("windows.{$rightIndex}.starts_at", 'Availability windows cannot overlap on the same day.');
                }
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

    private function dateTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateTimeString();
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
