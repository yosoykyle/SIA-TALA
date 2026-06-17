<?php

namespace App\Actions\AcademicFoundation;

use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurriculumScopeReadinessService
{
    public function scopeFor(Curriculum $curriculum, string $yearLevel, string $curriculumPeriod): CurriculumReadinessScope
    {
        return CurriculumReadinessScope::query()->firstOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => $yearLevel,
                'curriculum_period' => $curriculumPeriod,
            ],
            [
                'status' => CurriculumReadinessScope::StatusNeedsReview,
            ],
        );
    }

    public function findScope(int $curriculumId, string $yearLevel, string $curriculumPeriod): ?CurriculumReadinessScope
    {
        return CurriculumReadinessScope::query()
            ->where('curriculum_id', $curriculumId)
            ->where('year_level', $yearLevel)
            ->where('curriculum_period', $curriculumPeriod)
            ->first();
    }

    public function refreshStatus(CurriculumReadinessScope $scope): CurriculumReadinessScope
    {
        $blockers = $this->hardBlockersForScope($scope);

        if ($blockers === []) {
            return $scope->refresh();
        }

        $blockerHash = $this->blockerHash($blockers);

        if ($scope->status === CurriculumReadinessScope::StatusBlocked && $scope->last_blocker_hash === $blockerHash) {
            return $scope->refresh();
        }

        $this->applyTransition(
            scope: $scope,
            status: CurriculumReadinessScope::StatusBlocked,
            actor: null,
            reason: 'System detected blocking curriculum readiness issues.',
            blockers: $blockers,
            event: 'curriculum_scope_blocked',
        );

        return $scope->refresh();
    }

    public function markNeedsReview(
        CurriculumReadinessScope $scope,
        ?User $actor = null,
        ?string $reason = null,
    ): CurriculumReadinessScope {
        $blockers = $this->hardBlockersForScope($scope);

        $this->applyTransition(
            scope: $scope,
            status: $blockers === []
                ? CurriculumReadinessScope::StatusNeedsReview
                : CurriculumReadinessScope::StatusBlocked,
            actor: $actor,
            reason: $reason,
            blockers: $blockers,
            event: 'curriculum_scope_needs_review',
        );

        return $scope->refresh();
    }

    public function markNeedsReviewForValues(
        int $curriculumId,
        string $yearLevel,
        string $curriculumPeriod,
        ?User $actor = null,
        ?string $reason = null,
    ): ?CurriculumReadinessScope {
        $curriculum = Curriculum::query()->find($curriculumId);

        if (! $curriculum instanceof Curriculum) {
            return null;
        }

        return $this->markNeedsReview(
            scope: $this->scopeFor($curriculum, $yearLevel, $curriculumPeriod),
            actor: $actor,
            reason: $reason,
        );
    }

    public function markNeedsReviewForCurriculumSubject(
        CurriculumSubject $curriculumSubject,
        ?User $actor = null,
        ?string $reason = null,
    ): ?CurriculumReadinessScope {
        return $this->markNeedsReviewForValues(
            curriculumId: (int) $curriculumSubject->curriculum_id,
            yearLevel: (string) $curriculumSubject->year_level,
            curriculumPeriod: (string) $curriculumSubject->semester,
            actor: $actor,
            reason: $reason,
        );
    }

    public function markReady(
        CurriculumReadinessScope $scope,
        User $actor,
        ?string $reason = null,
    ): CurriculumReadinessScope {
        $blockers = $this->readyTransitionBlockers($scope, $reason);

        if ($blockers !== []) {
            $this->applyTransition(
                scope: $scope,
                status: CurriculumReadinessScope::StatusBlocked,
                actor: $actor,
                reason: $reason,
                blockers: $blockers,
                event: 'curriculum_scope_ready_blocked',
            );

            throw ValidationException::withMessages([
                'readiness' => implode(' ', $blockers),
            ]);
        }

        $this->applyTransition(
            scope: $scope,
            status: CurriculumReadinessScope::StatusReadyForScheduling,
            actor: $actor,
            reason: $reason,
            blockers: [],
            event: 'curriculum_scope_ready_for_scheduling',
        );

        return $scope->refresh();
    }

    /**
     * @return list<string>
     */
    public function hardBlockersForScope(CurriculumReadinessScope $scope): array
    {
        $curriculumSubjects = $this->subjectsForScope($scope);

        if ($curriculumSubjects->isEmpty()) {
            return ['curriculum_scope_has_no_subject_demand'];
        }

        return $curriculumSubjects
            ->flatMap(fn (CurriculumSubject $curriculumSubject): array => $this->subjectBlockers($curriculumSubject))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @return list<array<string, mixed>>
     */
    public function evidenceForSections(Collection $sections): array
    {
        return $sections
            ->filter(fn (Section $section): bool => $section->curriculum_id !== null
                && filled($section->year_level)
                && filled($section->curriculum_period))
            ->map(fn (Section $section): array => [
                'curriculum_id' => (int) $section->curriculum_id,
                'year_level' => (string) $section->year_level,
                'curriculum_period' => (string) $section->curriculum_period,
            ])
            ->unique(fn (array $scope): string => implode('|', $scope))
            ->map(function (array $scope): array {
                $readinessScope = $this->findScope(
                    (int) $scope['curriculum_id'],
                    (string) $scope['year_level'],
                    (string) $scope['curriculum_period'],
                );

                if (! $readinessScope instanceof CurriculumReadinessScope) {
                    return [
                        ...$scope,
                        'scope_id' => null,
                        'status' => 'missing',
                        'is_ready' => false,
                        'blockers' => ['curriculum_readiness_scope_missing'],
                        'last_transition_at' => null,
                        'last_transition_by' => null,
                    ];
                }

                $readinessScope = $this->refreshStatus($readinessScope);

                return [
                    ...$scope,
                    'scope_id' => (int) $readinessScope->id,
                    'status' => $readinessScope->status,
                    'is_ready' => $readinessScope->isReadyForScheduling(),
                    'blockers' => $readinessScope->last_blockers ?? [],
                    'last_transition_at' => $readinessScope->last_transition_at?->toIso8601String(),
                    'last_transition_by' => $readinessScope->last_transition_by !== null
                        ? (int) $readinessScope->last_transition_by
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function readyTransitionBlockers(CurriculumReadinessScope $scope, ?string $reason): array
    {
        $blockers = $this->hardBlockersForScope($scope);

        if ($blockers !== []) {
            return $blockers;
        }

        if ($this->allSubjectsExcludedFromAutoSchedule($scope) && blank($reason)) {
            $blockers[] = 'all_subjects_excluded_from_auto_schedule_requires_reviewer_reason';
        }

        return $blockers;
    }

    /**
     * @return EloquentCollection<int, CurriculumSubject>
     */
    private function subjectsForScope(CurriculumReadinessScope $scope): EloquentCollection
    {
        return CurriculumSubject::query()
            ->with('subject:id,code,description')
            ->where('curriculum_id', $scope->curriculum_id)
            ->where('year_level', $scope->year_level)
            ->where('semester', $scope->curriculum_period)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function subjectBlockers(CurriculumSubject $curriculumSubject): array
    {
        $blockers = [];
        $label = $curriculumSubject->subject?->code ?? 'curriculum_subject_'.$curriculumSubject->id;

        if (blank($curriculumSubject->academic_subject_type)) {
            $blockers[] = "{$label}:academic_subject_type_required";
        } elseif (! in_array($curriculumSubject->academic_subject_type, CurriculumSubject::academicSubjectTypeValues(), true)) {
            $blockers[] = "{$label}:academic_subject_type_invalid";
        }

        if (blank($curriculumSubject->scheduling_group)) {
            $blockers[] = "{$label}:scheduling_group_required";
        } elseif (! in_array($curriculumSubject->scheduling_group, CurriculumSubject::schedulingGroupValues(), true)) {
            $blockers[] = "{$label}:scheduling_group_invalid";
        }

        if (filled($curriculumSubject->delivery_rule_override)
            && ! in_array($curriculumSubject->delivery_rule_override, CurriculumSubject::deliveryRuleOverrideValues(), true)) {
            $blockers[] = "{$label}:delivery_rule_override_invalid";
        }

        if ($curriculumSubject->scheduling_group === CurriculumSubject::SchedulingGroupModular) {
            if ($curriculumSubject->weekly_contact_hours !== null && (float) $curriculumSubject->weekly_contact_hours < 0) {
                $blockers[] = "{$label}:weekly_contact_hours_cannot_be_negative";
            }

            return $blockers;
        }

        if ($curriculumSubject->weekly_contact_hours === null || (float) $curriculumSubject->weekly_contact_hours <= 0) {
            $blockers[] = "{$label}:weekly_contact_hours_required";
        }

        return $blockers;
    }

    private function allSubjectsExcludedFromAutoSchedule(CurriculumReadinessScope $scope): bool
    {
        $curriculumSubjects = $this->subjectsForScope($scope);

        return $curriculumSubjects->isNotEmpty()
            && $curriculumSubjects->every(
                fn (CurriculumSubject $curriculumSubject): bool => $curriculumSubject->delivery_rule_override
                    === CurriculumSubject::DeliveryOverrideExcludeFromAutoSchedule,
            );
    }

    /**
     * @param  list<string>  $blockers
     */
    private function applyTransition(
        CurriculumReadinessScope $scope,
        string $status,
        ?User $actor,
        ?string $reason,
        array $blockers,
        string $event,
    ): void {
        $timestamp = CarbonImmutable::now(config('app.timezone'));
        $blockerHash = $this->blockerHash($blockers);

        $scope->forceFill([
            'status' => $status,
            'last_transition_by' => $actor?->id,
            'last_transition_at' => $timestamp,
            'last_blockers' => $blockers,
            'last_blocker_hash' => $blockerHash,
            'last_transition_reason' => filled($reason) ? trim((string) $reason) : null,
        ])->save();

        DB::table('activity_log')->insert([
            'log_name' => 'academic_foundation',
            'description' => 'Curriculum readiness scope state changed.',
            'subject_type' => CurriculumReadinessScope::class,
            'subject_id' => $scope->id,
            'event' => $event,
            'causer_type' => $actor instanceof User ? User::class : null,
            'causer_id' => $actor?->id,
            'properties' => json_encode([
                'curriculum_readiness_scope_id' => $scope->id,
                'curriculum_id' => $scope->curriculum_id,
                'year_level' => $scope->year_level,
                'curriculum_period' => $scope->curriculum_period,
                'status_after' => $status,
                'blockers' => $blockers,
                'reason' => filled($reason) ? trim((string) $reason) : null,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    /**
     * @param  list<string>  $blockers
     */
    private function blockerHash(array $blockers): ?string
    {
        return $blockers === []
            ? null
            : hash('sha256', json_encode($blockers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
