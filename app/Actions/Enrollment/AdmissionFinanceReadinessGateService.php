<?php

namespace App\Actions\Enrollment;

use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\Enrollment;
use App\Models\ScheduleGenerationRun;
use App\Models\StudentProfile;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdmissionFinanceReadinessGateService
{
    public function __construct(
        private readonly AdmissionCapacityReservationService $capacityReservations,
        private readonly TermSchedulingReadinessService $termSchedulingReadiness,
    ) {}

    /**
     * @throws ValidationException
     */
    public function assertReadyForFinanceClearance(
        Enrollment $enrollment,
        StudentProfile $studentProfile,
        CarbonImmutable $timestamp,
    ): void {
        $intake = $this->admissionIntakeFor($enrollment, $studentProfile);

        if (! $intake instanceof ApplicantIntake || ! $intake->checklistItems()->exists()) {
            return;
        }

        $messages = [];
        $this->mergeMessages($messages, $this->calendarMessages($enrollment, $timestamp));
        $this->mergeMessages($messages, $this->capacityMessages($enrollment, $studentProfile));
        $this->mergeMessages($messages, $this->schedulingMessages($enrollment));
        $this->mergeMessages($messages, $this->publishedScheduleMessages($enrollment));

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function admissionIntakeFor(Enrollment $enrollment, StudentProfile $studentProfile): ?ApplicantIntake
    {
        if ($studentProfile->user_id === null || $enrollment->term_id === null) {
            return null;
        }

        return ApplicantIntake::query()
            ->where('user_id', $studentProfile->user_id)
            ->where('term_id', $enrollment->term_id)
            ->where('status', ApplicantIntake::StatusApproved)
            ->where(function (Builder $query) use ($studentProfile): void {
                $query->whereNull('program_id');

                if ($studentProfile->program_id !== null) {
                    $query->orWhere('program_id', $studentProfile->program_id);
                }
            })
            ->latest('approved_at')
            ->latest('id')
            ->first();
    }



    /**
     * @param  Collection<int, int>  $offeringIds
     */
    private function publishedOfferingCount(Collection $offeringIds): int
    {
        return AdmissionOffering::query()
            ->whereIn('id', $offeringIds->all())
            ->where('status', AdmissionOffering::StatusPublished)
            ->whereNotNull('published_at')
            ->count();
    }

    /**
     * @param  Collection<int, int>  $policyIds
     */
    private function activePolicyCount(Collection $policyIds, CarbonImmutable $timestamp): int
    {
        return AdmissionRequirementPolicy::query()
            ->whereIn('id', $policyIds->all())
            ->where('status', AdmissionRequirementPolicy::StatusActive)
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $timestamp);
            })
            ->where(function (Builder $query) use ($timestamp): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $timestamp);
            })
            ->count();
    }

    /**
     * @return array<string, list<string>>
     */
    private function calendarMessages(Enrollment $enrollment, CarbonImmutable $timestamp): array
    {
        $term = $enrollment->term;

        if (! $term instanceof Term) {
            return [
                'admission_calendar' => ['Enrollment term must exist before finance clearance.'],
            ];
        }

        foreach (['enrollment_starts_at', 'enrollment_ends_at', 'payment_deadline'] as $field) {
            if (blank($term->{$field})) {
                return [
                    'admission_calendar' => ['Enrollment and payment calendar fields must be configured before finance clearance.'],
                ];
            }
        }

        if ($timestamp->lt(CarbonImmutable::parse($term->enrollment_starts_at, config('app.timezone')))) {
            return [
                'admission_calendar' => ['Enrollment has not opened for this term.'],
            ];
        }

        if ($timestamp->gt(CarbonImmutable::parse($term->payment_deadline, config('app.timezone')))) {
            return [
                'admission_calendar' => ['Payment deadline has already passed for this term.'],
            ];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function capacityMessages(Enrollment $enrollment, StudentProfile $studentProfile): array
    {
        $plans = $this->capacityReservations->matchingApprovedPlans($enrollment, $studentProfile);

        if ($plans->isEmpty()) {
            return [
                'admission_capacity' => ['An approved admission capacity plan is required before finance clearance.'],
            ];
        }

        if ($plans->contains(fn (AdmissionCapacityPlan $plan): bool => (int) $plan->reserved_count >= (int) $plan->capacity_limit)) {
            return [
                'admission_capacity' => ['Admission capacity is already full for one or more matching capacity plans.'],
            ];
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function schedulingMessages(Enrollment $enrollment): array
    {
        $term = $enrollment->term;

        if (! $term instanceof Term) {
            return [];
        }

        $readiness = $this->termSchedulingReadiness->evaluateTerm($term);

        if ($readiness['is_ready'] === true) {
            return [];
        }

        return [
            'scheduling_readiness' => ['Term scheduling setup must be ready before finance clearance.'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function publishedScheduleMessages(Enrollment $enrollment): array
    {
        $hasPublishedSchedule = ScheduleGenerationRun::query()
            ->where('term_id', $enrollment->term_id)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->whereNotNull('published_at')
            ->whereHas('sectionMeetings')
            ->exists();

        if ($hasPublishedSchedule) {
            return [];
        }

        return [
            'schedule_publish' => ['A published class schedule is required before finance clearance.'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $target
     * @param  array<string, list<string>>  $source
     */
    private function mergeMessages(array &$target, array $source): void
    {
        foreach ($source as $key => $messages) {
            $target[$key] = [
                ...($target[$key] ?? []),
                ...$messages,
            ];
        }
    }
}
