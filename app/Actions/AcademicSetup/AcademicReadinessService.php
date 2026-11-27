<?php

namespace App\Actions\AcademicSetup;

use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;

class AcademicReadinessService
{
    public function __construct(
        private readonly CurriculumVersionLifecycleService $curricula,
        private readonly CourseSpecificationLifecycleService $courseSpecifications,
    ) {}

    public function currentCurriculum(Program $program, ?int $academicYearId = null): ?CurriculumVersion
    {
        $program->loadMissing([
            'curriculumVersions.academicYears',
            'curriculumVersions.entries.courseSpecification.components',
            'curriculumVersions.entries.courseSpecification.course',
        ]);

        if ($academicYearId !== null || $program->curriculumVersionBatches()->exists()) {
            $batchCurriculum = $program->curriculumForAcademicYear($academicYearId);

            if ($batchCurriculum instanceof CurriculumVersion) {
                return $program->curriculumVersions->firstWhere('id', $batchCurriculum->id) ?? $batchCurriculum;
            }
        }

        return $program->curriculumVersions
            ->sort(function (CurriculumVersion $left, CurriculumVersion $right): int {
                $stateComparison = $this->statePriority($left->state) <=> $this->statePriority($right->state);

                if ($stateComparison !== 0) {
                    return $stateComparison;
                }

                $batchYearComparison = ($right->latestBatchStartYear() ?? -1) <=> ($left->latestBatchStartYear() ?? -1);

                if ($batchYearComparison !== 0) {
                    return $batchYearComparison;
                }

                $batchTsComparison = $right->latestBatchStartsOnTimestamp() <=> $left->latestBatchStartsOnTimestamp();

                if ($batchTsComparison !== 0) {
                    return $batchTsComparison;
                }

                $createdComparison = $right->created_at <=> $left->created_at;

                if ($createdComparison !== 0) {
                    return $createdComparison;
                }

                return $right->id <=> $left->id;
            })
            ->first();
    }

    public function successorDraft(Program $program): ?CurriculumVersion
    {
        $program->loadMissing([
            'curriculumVersions.entries.courseSpecification.components',
            'curriculumVersions.entries.courseSpecification.course',
        ]);

        $hasActive = $program->curriculumVersions->contains(
            fn (CurriculumVersion $version): bool => $version->state === CurriculumVersion::StateActive,
        );

        if (! $hasActive) {
            return null;
        }

        return $program->curriculumVersions
            ->filter(fn (CurriculumVersion $version): bool => $version->state === CurriculumVersion::StateDraft)
            ->sort(function (CurriculumVersion $left, CurriculumVersion $right): int {
                $createdComparison = $right->created_at <=> $left->created_at;

                if ($createdComparison !== 0) {
                    return $createdComparison;
                }

                return $right->id <=> $left->id;
            })
            ->first();
    }

    /**
     * Return compact sidebar badge counts for the Catalog & Curricula sub-items.
     *
     * @return array{courses_with_drafts: int, curricula_pending: int}
     */
    public function sidebarBadges(): array
    {
        return [
            'courses_with_drafts' => Course::query()
                ->whereHas('specifications', fn ($query) => $query
                    ->where('state', CourseSpecification::StateDraft)
                    ->whereDoesntHave(
                        'curriculumEntries.curriculumVersion',
                        fn ($curriculumQuery) => $curriculumQuery->where('state', CurriculumVersion::StateDraft),
                    ))
                ->count(),
            'curricula_pending' => CurriculumVersion::query()
                ->where('state', CurriculumVersion::StateDraft)
                ->count(),
        ];
    }

    /**
     * @return array{
     *     status:string,
     *     color:string,
     *     blocker:string,
     *     next_action:string,
     *     entries:int,
     *     successor_draft:?array{
     *         id:int,
     *         name:string,
     *         status:string,
     *         color:string,
     *         blocker:string,
     *         next_action:string,
     *         entries:int
     *     }
     * }
     */
    public function programReadiness(Program $program): array
    {
        $curriculum = $this->currentCurriculum($program);

        if (! $curriculum instanceof CurriculumVersion) {
            return [
                'status' => 'No curriculum yet',
                'color' => 'danger',
                'blocker' => 'No curriculum has been recorded for this program.',
                'next_action' => 'Create a curriculum',
                'entries' => 0,
                'successor_draft' => null,
            ];
        }

        $primary = $this->curriculumReadiness($curriculum);
        $successor = $this->successorDraft($program);

        return [
            ...$primary,
            'successor_draft' => $successor instanceof CurriculumVersion
                ? [
                    'id' => (int) $successor->id,
                    'name' => $successor->name,
                    ...$this->curriculumReadiness($successor),
                ]
                : null,
        ];
    }

    /**
     * @return array{status:string, color:string, blocker:string, next_action:string, entries:int}
     */
    public function curriculumReadiness(CurriculumVersion $curriculum): array
    {
        $curriculum->loadMissing([
            'entries.courseSpecification.components',
            'entries.courseSpecification.course',
        ]);

        $entries = $curriculum->entries->count();

        if ($curriculum->state === CurriculumVersion::StateActive) {
            return [
                'status' => 'Active',
                'color' => 'success',
                'blocker' => 'No curriculum blocker is recorded.',
                'next_action' => 'Review curriculum',
                'entries' => $entries,
            ];
        }

        if ($curriculum->state !== CurriculumVersion::StateDraft) {
            return [
                'status' => 'No active curriculum',
                'color' => 'warning',
                'blocker' => 'Only historical curricula are recorded for this program.',
                'next_action' => 'Create a curriculum',
                'entries' => $entries,
            ];
        }

        if ($entries === 0) {
            return [
                'status' => 'Draft needs subjects',
                'color' => 'warning',
                'blocker' => 'The draft has no subjects yet.',
                'next_action' => 'Add subjects',
                'entries' => 0,
            ];
        }

        $readinessErrors = $this->curricula->readinessErrors($curriculum);

        if ($readinessErrors !== []) {
            return [
                'status' => 'Needs correction',
                'color' => 'warning',
                'blocker' => $readinessErrors[0],
                'next_action' => 'Review curriculum',
                'entries' => $entries,
            ];
        }

        return [
            'status' => 'Ready to activate',
            'color' => 'info',
            'blocker' => 'All subjects are complete.',
            'next_action' => 'Activate curriculum',
            'entries' => $entries,
        ];
    }

    /**
     * @return array{
     *     status:string,
     *     color:string,
     *     blocker:string,
     *     next_action:string
     * }
     */
    public function entryReadiness(CurriculumEntry $entry): array
    {
        $entry->loadMissing('courseSpecification.components');
        $specification = $entry->courseSpecification;

        if (! $specification instanceof CourseSpecification) {
            return [
                'status' => 'Specification missing',
                'color' => 'danger',
                'blocker' => 'This curriculum row has no Course Specification.',
                'next_action' => 'Select a Course Specification',
            ];
        }

        $errors = $this->courseSpecifications->readinessErrors($specification);

        if (! in_array($specification->state, [CourseSpecification::StateActive, CourseSpecification::StateDraft], true)) {
            array_unshift($errors, 'Select an active or draft Course Specification before curriculum activation.');
        }

        $errors = array_values(array_unique($errors));

        if ($errors !== []) {
            return [
                'status' => 'Specification incomplete',
                'color' => 'warning',
                'blocker' => implode(' ', $errors),
                'next_action' => 'Complete specification',
            ];
        }

        return [
            'status' => 'Specification ready',
            'color' => 'success',
            'blocker' => $specification->state === CourseSpecification::StateDraft
                ? 'Ready draft specification; activates automatically with the curriculum.'
                : 'No specification blocker is recorded.',
            'next_action' => 'No correction required',
        ];
    }

    private function statePriority(string $state): int
    {
        return match ($state) {
            CurriculumVersion::StateActive => 1,
            CurriculumVersion::StateDraft => 2,
            CurriculumVersion::StateSuperseded => 3,
            CurriculumVersion::StateArchived => 4,
            default => 5,
        };
    }
}
