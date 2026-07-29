<?php

namespace App\Actions\AcademicSetup;

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
     * @return array{
     *     status:string,
     *     color:string,
     *     blocker:string,
     *     next_action:string,
     *     entries:int
     * }
     */
    public function programReadiness(Program $program): array
    {
        $curriculum = $this->currentCurriculum($program);

        if (! $curriculum instanceof CurriculumVersion) {
            return [
                'status' => 'Not configured',
                'color' => 'danger',
                'blocker' => 'No curriculum has been recorded for this program.',
                'next_action' => 'Create a curriculum draft',
                'entries' => 0,
            ];
        }

        $entries = $curriculum->entries->count();

        if ($entries === 0) {
            return [
                'status' => 'Draft needs completion',
                'color' => 'warning',
                'blocker' => 'The draft has no curriculum rows.',
                'next_action' => 'Add curriculum rows',
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

        return match ($curriculum->state) {
            CurriculumVersion::StateActive => [
                'status' => 'Ready for offerings',
                'color' => 'success',
                'blocker' => 'No curriculum blocker is recorded.',
                'next_action' => 'Review curriculum',
                'entries' => $entries,
            ],
            CurriculumVersion::StateRecordedApproved => [
                'status' => 'Approved; activation required',
                'color' => 'warning',
                'blocker' => 'The complete approved curriculum is not active yet.',
                'next_action' => 'Review activation impact',
                'entries' => $entries,
            ],
            default => [
                'status' => 'Draft awaiting approval',
                'color' => 'info',
                'blocker' => 'External approval evidence has not been recorded.',
                'next_action' => 'Record external approval',
                'entries' => $entries,
            ],
        };
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

        if ($specification->state !== CourseSpecification::StateActive) {
            array_unshift($errors, 'Activate the completed Course Specification before curriculum activation.');
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
            'blocker' => 'No specification blocker is recorded.',
            'next_action' => 'No correction required',
        ];
    }

    private function statePriority(string $state): int
    {
        return match ($state) {
            CurriculumVersion::StateRecordedApproved => 1,
            CurriculumVersion::StateDraft => 2,
            CurriculumVersion::StateActive => 3,
            CurriculumVersion::StateSuperseded => 4,
            CurriculumVersion::StateArchived => 5,
            default => 6,
        };
    }
}
