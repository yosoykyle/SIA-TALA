<?php

namespace App\Actions\Scheduling;

use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\User;
use Illuminate\Support\Collection;
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
            $locked->loadMissing(['calendarPackage.term', 'termOffering', 'courseRevision.course', 'cohorts']);

            if (in_array($locked->state, [Section::StateClosed, Section::StateCancelled], true)
                || $locked->calendarPackage?->term?->state === Term::StateClosed
                || ($locked->termOffering !== null && $locked->calendarPackage !== null
                    && (int) $locked->termOffering->term_id !== (int) $locked->calendarPackage->term_id)) {
                throw ValidationException::withMessages(['section' => 'Confirmation requires an available class in its exact open Term.']);
            }

            $active = $locked->calendarPackage ? TermCalendarPackage::query()->where('term_id', $locked->calendarPackage->term_id)->where('state', TermCalendarPackage::StateActive)->lockForUpdate()->first() : null;
            if (! $active || ! $locked->calendarPackage->isActivatedCoreSource() || $locked->calendarPackage->coreSignature() !== $active->coreSignature()) {
                throw ValidationException::withMessages(['calendar_package' => 'Class confirmation requires the active package for this exact Term.']);
            }

            if ($locked->courseRevision?->state !== CourseSpecification::StateActive) {
                throw ValidationException::withMessages(['course_revision' => 'Class confirmation requires an active Course Revision.']);
            }

            if (! array_key_exists((string) $locked->source, Section::confirmableSourceOptions())) {
                throw ValidationException::withMessages(['source' => 'A new Class Offering must be Regular or authority-backed Additional. Sharing is recorded through its cohort relationships.']);
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

            if ($locked->confirmed_at !== null) {
                $currentCounts = $locked->cohorts->pluck('pivot.expected_count', 'id')->all();
                if ($currentCounts != $cohortExpectedCounts
                    || ($additionalAuthorityReference !== null && $additionalAuthorityReference !== $locked->authority_reference)) {
                    throw ValidationException::withMessages(['cohorts' => 'Confirmed class facts have changed. Refresh the class before continuing.']);
                }

                return $locked;
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

    /**
     * Confirm a batch of class offerings in a single transaction.
     *
     * @param  list<array{section: Section, cohort_counts: array<int, int>, authority_reference?: string|null}>  $items
     * @return Collection<int, Section>
     *
     * @throws ValidationException
     */
    public function batch(User $actor, array $items, ?int $expectedTermId = null): Collection
    {
        if ($items === []) {
            return collect();
        }

        return DB::transaction(function () use ($actor, $items, $expectedTermId): Collection {
            $seenReferences = [];
            $seenIds = [];
            $batchTermId = $expectedTermId;
            $lockedItems = [];

            foreach ($items as $index => $item) {
                $section = $item['section'] ?? null;
                if (! $section instanceof Section || ! $section->exists) {
                    throw ValidationException::withMessages(["items.{$index}.section" => 'An existing Class Offering is required.']);
                }
                $locked = Section::query()->whereKey($section)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $locked);
                $locked->load(['calendarPackage', 'termOffering']);
                $sectionTermId = $locked->calendarPackage?->term_id;
                if ($sectionTermId === null || ($batchTermId !== null && (int) $sectionTermId !== (int) $batchTermId)) {
                    throw ValidationException::withMessages(["items.{$index}.section" => 'All classes must belong to the selected exact Term.']);
                }
                $batchTermId = (int) $sectionTermId;
                $reference = $locked->class_reference ?: $locked->code;
                if (in_array($reference, $seenReferences, true) || in_array($locked->id, $seenIds, true)) {
                    throw ValidationException::withMessages(["items.{$index}.class_reference" => 'Each Class Offering must appear only once in the batch.']);
                }
                $seenReferences[] = $reference;
                $seenIds[] = $locked->id;
                $lockedItems[] = [...$item, 'section' => $locked];
            }

            return collect($lockedItems)->map(fn (array $item): Section => $this->execute(
                $item['section'], $actor, $item['cohort_counts'] ?? [], $item['authority_reference'] ?? null,
            ));
        }, attempts: 5);
    }
}
