<?php

namespace App\Actions\Enrollment;

use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdmissionReadinessDashboardService
{
    public function __construct(private readonly TermSchedulingReadinessService $termSchedulingReadiness) {}

    /**
     * @return array{
     *     generated_at: string,
     *     selected_term_id: int|null,
     *     terms: list<array{id:int, label:string}>,
     *     summary: array{total_offerings:int, ready_offerings:int, blocked_offerings:int, blocker_count:int},
     *     offerings: list<array<string, mixed>>
     * }
     */
    public function evaluate(?int $termId = null, ?CarbonImmutable $timestamp = null): array
    {
        $timestamp ??= CarbonImmutable::now(config('app.timezone'));
        $terms = $this->terms();
        $selectedTerm = $this->selectedTerm($terms, $termId);

        if (! $selectedTerm instanceof Term) {
            return [
                'generated_at' => $timestamp->toDateTimeString(),
                'selected_term_id' => null,
                'terms' => [],
                'summary' => [
                    'total_offerings' => 0,
                    'ready_offerings' => 0,
                    'blocked_offerings' => 0,
                    'blocker_count' => 0,
                ],
                'offerings' => [],
            ];
        }

        $termReadiness = $this->termSchedulingReadiness->evaluateTerm($selectedTerm);
        $hasPublishedSchedule = $this->hasPublishedSchedule($selectedTerm);

        $offerings = AdmissionOffering::query()
            ->with(['program', 'requirementPolicies.documentRequirementItems'])
            ->where('term_id', $selectedTerm->id)
            ->orderBy('program_id')
            ->orderBy('year_level')
            ->orderBy('name')
            ->get()
            ->map(fn (AdmissionOffering $offering): array => $this->offeringReadiness(
                $offering,
                $selectedTerm,
                $termReadiness,
                $hasPublishedSchedule,
                $timestamp,
            ))
            ->values()
            ->all();

        $blockerCount = collect($offerings)
            ->sum(fn (array $offering): int => count($offering['blockers']));

        return [
            'generated_at' => $timestamp->toDateTimeString(),
            'selected_term_id' => (int) $selectedTerm->id,
            'terms' => $terms
                ->map(fn (Term $term): array => [
                    'id' => (int) $term->id,
                    'label' => (string) $term->term_name,
                ])
                ->values()
                ->all(),
            'summary' => [
                'total_offerings' => count($offerings),
                'ready_offerings' => collect($offerings)->where('is_ready', true)->count(),
                'blocked_offerings' => collect($offerings)->where('is_ready', false)->count(),
                'blocker_count' => (int) $blockerCount,
            ],
            'offerings' => $offerings,
        ];
    }

    /**
     * @return Collection<int, Term>
     */
    private function terms(): Collection
    {
        return Term::query()
            ->orderByDesc('is_active')
            ->orderByDesc('term_start_date')
            ->orderByDesc('id')
            ->get(['id', 'term_name', 'is_active', 'term_start_date', 'term_end_date', 'enrollment_starts_at', 'enrollment_ends_at', 'payment_deadline', 'scheduling_starts_at']);
    }

    /**
     * @param  Collection<int, Term>  $terms
     */
    private function selectedTerm(Collection $terms, ?int $termId): ?Term
    {
        if ($termId !== null) {
            $term = $terms->firstWhere('id', $termId);

            if ($term instanceof Term) {
                return $term;
            }
        }

        return $terms->first();
    }

    /**
     * @param  array<string, mixed>  $termReadiness
     * @return array<string, mixed>
     */
    private function offeringReadiness(
        AdmissionOffering $offering,
        Term $term,
        array $termReadiness,
        bool $hasPublishedSchedule,
        CarbonImmutable $timestamp,
    ): array {
        $blockers = [
            ...$this->offeringBlockers($offering),
            ...$this->policyBlockers($offering, $timestamp),
            ...$this->calendarBlockers($term, $timestamp),
            ...$this->capacityBlockers($offering, $timestamp),
            ...$this->schedulingBlockers($termReadiness),
            ...$this->publishedScheduleBlockers($hasPublishedSchedule),
        ];

        return [
            'id' => (int) $offering->id,
            'label' => $offering->displayLabel(),
            'program' => $offering->program?->code ?? 'All programs',
            'year_level' => $offering->year_level ?? 'All years',
            'status' => $offering->status,
            'is_ready' => $blockers === [],
            'blockers' => $blockers,
            'capacity_plans' => $this->capacityPlanSummaries($offering, $timestamp),
            'active_policy_count' => $this->activePolicies($offering, $timestamp)->count(),
            'document_item_count' => $this->activePolicies($offering, $timestamp)
                ->sum(fn (AdmissionRequirementPolicy $policy): int => $policy->documentRequirementItems->count()),
        ];
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function offeringBlockers(AdmissionOffering $offering): array
    {
        if ($offering->status === AdmissionOffering::StatusPublished && filled($offering->published_at)) {
            return [];
        }

        return [[
            'category' => 'Admission setup',
            'message' => 'Admission offering is not published.',
        ]];
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function policyBlockers(AdmissionOffering $offering, CarbonImmutable $timestamp): array
    {
        $policies = $this->activePolicies($offering, $timestamp);

        if ($policies->isEmpty()) {
            return [[
                'category' => 'Requirement policy',
                'message' => 'No active requirement policy is effective for this offering.',
            ]];
        }

        if ($policies->sum(fn (AdmissionRequirementPolicy $policy): int => $policy->documentRequirementItems->count()) < 1) {
            return [[
                'category' => 'Requirement policy',
                'message' => 'Active requirement policy has no document requirement items.',
            ]];
        }

        return [];
    }

    /**
     * @return Collection<int, AdmissionRequirementPolicy>
     */
    private function activePolicies(AdmissionOffering $offering, CarbonImmutable $timestamp): Collection
    {
        return $offering->requirementPolicies
            ->filter(fn (AdmissionRequirementPolicy $policy): bool => $policy->status === AdmissionRequirementPolicy::StatusActive
                && ($policy->effective_from === null || CarbonImmutable::parse($policy->effective_from)->lte($timestamp))
                && ($policy->effective_until === null || CarbonImmutable::parse($policy->effective_until)->gte($timestamp)))
            ->values();
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function calendarBlockers(Term $term, CarbonImmutable $timestamp): array
    {
        $missingFields = collect(['enrollment_starts_at', 'enrollment_ends_at', 'payment_deadline'])
            ->filter(fn (string $field): bool => blank($term->{$field}))
            ->values()
            ->all();

        if ($missingFields !== []) {
            return [[
                'category' => 'Calendar',
                'message' => 'Enrollment and payment calendar fields are incomplete: '.implode(', ', $missingFields).'.',
            ]];
        }

        if ($timestamp->lt(CarbonImmutable::parse($term->enrollment_starts_at, config('app.timezone')))) {
            return [[
                'category' => 'Calendar',
                'message' => 'Enrollment has not opened for this term.',
            ]];
        }

        if ($timestamp->gt(CarbonImmutable::parse($term->payment_deadline, config('app.timezone')))) {
            return [[
                'category' => 'Calendar',
                'message' => 'Payment deadline has already passed for this term.',
            ]];
        }

        return [];
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function capacityBlockers(AdmissionOffering $offering, CarbonImmutable $timestamp): array
    {
        $plans = $this->matchingCapacityPlans($offering, $timestamp);

        if ($plans->isEmpty()) {
            return [[
                'category' => 'Capacity',
                'message' => 'No approved admission capacity plan matches this offering.',
            ]];
        }

        if ($plans->contains(fn (AdmissionCapacityPlan $plan): bool => (int) $plan->reserved_count >= (int) $plan->capacity_limit)) {
            return [[
                'category' => 'Capacity',
                'message' => 'One or more matching capacity plans are already full.',
            ]];
        }

        return [];
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function schedulingBlockers(array $termReadiness): array
    {
        if (($termReadiness['is_ready'] ?? false) === true) {
            return [];
        }

        $counts = [
            'term fields' => count($termReadiness['missing_term_fields'] ?? []),
            'section issues' => count($termReadiness['section_issues'] ?? []),
            'delivery group issues' => count($termReadiness['delivery_group_issues'] ?? []),
            'faculty input issues' => count($termReadiness['faculty_input_issues'] ?? []),
        ];

        $detail = collect($counts)
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $label): string => "{$count} {$label}")
            ->implode(', ');

        return [[
            'category' => 'Scheduling readiness',
            'message' => filled($detail)
                ? "Term scheduling setup is not ready ({$detail})."
                : 'Term scheduling setup is not ready.',
        ]];
    }

    /**
     * @return list<array{category:string, message:string}>
     */
    private function publishedScheduleBlockers(bool $hasPublishedSchedule): array
    {
        if ($hasPublishedSchedule) {
            return [];
        }

        return [[
            'category' => 'Published schedule',
            'message' => 'No published schedule with official section meetings exists for this term.',
        ]];
    }

    /**
     * @return list<array{label:string, remaining:int, capacity:int, status:string}>
     */
    private function capacityPlanSummaries(AdmissionOffering $offering, CarbonImmutable $timestamp): array
    {
        return $this->matchingCapacityPlans($offering, $timestamp)
            ->map(fn (AdmissionCapacityPlan $plan): array => [
                'label' => $plan->displayLabel(),
                'remaining' => max(0, (int) $plan->capacity_limit - (int) $plan->reserved_count),
                'capacity' => (int) $plan->capacity_limit,
                'status' => ((int) $plan->reserved_count >= (int) $plan->capacity_limit) ? 'full' : 'available',
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, AdmissionCapacityPlan>
     */
    private function matchingCapacityPlans(AdmissionOffering $offering, CarbonImmutable $timestamp): Collection
    {
        return AdmissionCapacityPlan::query()
            ->with(['term', 'program'])
            ->where('term_id', $offering->term_id)
            ->where('status', AdmissionCapacityPlan::StatusApproved)
            ->where(function (Builder $builder) use ($timestamp): void {
                $builder->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $timestamp);
            })
            ->where(function (Builder $builder) use ($timestamp): void {
                $builder->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $timestamp);
            })
            ->where(function (Builder $builder) use ($offering): void {
                $builder->whereNull('program_id');

                if ($offering->program_id !== null) {
                    $builder->orWhere('program_id', $offering->program_id);
                }
            })
            ->where(function (Builder $builder) use ($offering): void {
                $builder->whereNull('year_level');

                if ($offering->year_level !== null) {
                    $builder->orWhere('year_level', $offering->year_level);
                }
            })
            ->orderByRaw('CASE scope_type WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 WHEN ? THEN 4 ELSE 9 END', [
                AdmissionCapacityPlan::ScopeCampus,
                AdmissionCapacityPlan::ScopeProgram,
                AdmissionCapacityPlan::ScopeYearLevel,
                AdmissionCapacityPlan::ScopeDeliverySetup,
            ])
            ->orderBy('id')
            ->get();
    }

    private function hasPublishedSchedule(Term $term): bool
    {
        return ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->whereNotNull('published_at')
            ->whereHas('sectionMeetings')
            ->exists();
    }
}
