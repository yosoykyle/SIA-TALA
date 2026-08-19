<?php

namespace App\Actions\Scheduling;

use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ConfirmClassOffering
{
    /**
     * @param  array<int, int>  $cohortExpectedCounts
     */
    public function execute(
        Section $section,
        User $actor,
        array $cohortExpectedCounts,
        ?string $additionalAuthorityReference = null,
    ): Section {
        Gate::forUser($actor)->authorize('update', $section);

        return DB::transaction(function () use ($section, $actor, $cohortExpectedCounts, $additionalAuthorityReference): Section {
            $locked = Section::query()->whereKey($section)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $locked->loadMissing(['calendarPackage', 'courseRevision.course', 'cohorts']);

            if ($locked->calendarPackage?->state !== TermCalendarPackage::StateActive) {
                throw ValidationException::withMessages(['calendar_package' => 'Class confirmation requires the active package for this exact Term.']);
            }

            if ($locked->courseRevision?->state !== CourseSpecification::StateActive) {
                throw ValidationException::withMessages(['course_revision' => 'Class confirmation requires an active Course Revision.']);
            }

            if (! array_key_exists((string) $locked->source, Section::sourceOptions())) {
                throw ValidationException::withMessages(['source' => 'Class confirmation requires a Regular, Shared, or Additional source.']);
            }

            if ($cohortExpectedCounts === []
                || collect($cohortExpectedCounts)->contains(fn (int $count): bool => $count < 1)
                || array_sum($cohortExpectedCounts) > (int) $locked->capacity) {
                throw ValidationException::withMessages(['cohorts' => 'Confirmed cohort demand must be present and must not exceed the Class Offering capacity.']);
            }

            $cohortIds = collect(array_keys($cohortExpectedCounts))->map(fn (int|string $id): int => (int) $id)->values();
            $cohorts = TermCohort::query()
                ->with(['curriculumVersion.entries.courseSpecification'])
                ->whereKey($cohortIds)
                ->lockForUpdate()
                ->get();

            if ($cohorts->count() !== $cohortIds->unique()->count()) {
                throw ValidationException::withMessages(['cohorts' => 'Every confirmed cohort must be an existing cohort for this exact Term.']);
            }

            foreach ($cohorts as $cohort) {
                $curriculum = $cohort->curriculumVersion;
                $courseRevision = $locked->courseRevision;
                $belongsToExactTerm = (int) $cohort->term_id === (int) $locked->calendarPackage->term_id;
                $hasConsistentProgram = (int) $curriculum->program_id === (int) $cohort->program_id;
                $containsCourse = $curriculum->entries->contains(function ($entry) use ($courseRevision): bool {
                    $entrySpecification = $entry->courseSpecification;

                    return (int) $entry->course_specification_id === (int) $courseRevision->id
                        || ($entrySpecification instanceof CourseSpecification
                            && (int) $entrySpecification->course_id === (int) $courseRevision->course_id);
                });

                if (! $belongsToExactTerm
                    || ! $hasConsistentProgram
                    || $curriculum->state !== CurriculumVersion::StateActive
                    || ! $containsCourse) {
                    throw ValidationException::withMessages(['cohorts' => 'Each cohort must belong to this exact Term and an active, program-consistent curriculum containing the offered course.']);
                }
            }

            if ($locked->source === Section::SourceAdditional && blank($additionalAuthorityReference ?? $locked->authority_reference)) {
                throw ValidationException::withMessages(['authority_reference' => 'An Additional Class Offering requires its external authority reference.']);
            }

            $locked->cohorts()->sync(collect($cohortExpectedCounts)->mapWithKeys(
                fn (int $expected, int|string $cohortId): array => [(int) $cohortId => ['expected_count' => $expected]],
            )->all());
            $locked->forceFill([
                'authority_reference' => $additionalAuthorityReference ?? $locked->authority_reference,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            return $locked->fresh(['cohorts', 'calendarPackage', 'courseRevision']);
        }, attempts: 5);
    }
}
