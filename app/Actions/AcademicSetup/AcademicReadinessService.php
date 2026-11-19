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

    public function currentCurriculum(Program $program): ?CurriculumVersion
    {
        $program->loadMissing([
            'curriculumVersions.entries.courseSpecification.components',
            'curriculumVersions.entries.courseSpecification.course',
        ]);

        return $program->curriculumVersions
            ->sort(function (CurriculumVersion $left, CurriculumVersion $right): int {
                $stateComparison = $this->statePriority($left->state) <=> $this->statePriority($right->state);

                if ($stateComparison !== 0) {
                    return $stateComparison;
                }

                return $right->created_at <=> $left->created_at;
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
                ->whereHas('specifications', fn ($query) => $query->where('state', CourseSpecification::StateDraft))
                ->count(),
            'curricula_pending' => CurriculumVersion::query()
                ->where('state', CurriculumVersion::StateDraft)
                ->count(),
        ];
    }

    /**
     * @return array{status:string, color:string, blocker:string, next_action:string, entries:int}
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
            ];
        }

        $entries = $curriculum->entries->count();

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

        return $curriculum->state === CurriculumVersion::StateActive
            ? [
                'status' => 'Active',
                'color' => 'success',
                'blocker' => 'No curriculum blocker is recorded.',
                'next_action' => 'Review curriculum',
                'entries' => $entries,
            ]
            : [
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
            CurriculumVersion::StateDraft => 2,
            CurriculumVersion::StateActive => 3,
            CurriculumVersion::StateSuperseded => 4,
            CurriculumVersion::StateArchived => 5,
            default => 6,
        };
    }
}
