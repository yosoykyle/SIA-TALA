<?php

namespace App\Actions\Scheduling;

use App\Models\CurriculumVersion;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use Illuminate\Support\Collection;

final class TermCoverageProjection
{
    /** @return array{rows:list<array<string,mixed>>,additional:list<array<string,mixed>>,summary:array<string,int>} */
    public function forTerm(Term $term): array
    {
        $curricula = CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)
            ->with(['program', 'entries' => fn ($query) => $query->where('term_type', $term->type)->orderBy('sequence'), 'entries.courseSpecification'])->get()->keyBy('id');
        $offerings = TermOffering::query()->where('term_id', $term->id)
            ->where('state', '!=', TermOffering::StateCancelled)
            ->with(['curriculumEntry.curriculumVersion.program', 'curriculumEntry.courseSpecification.course',
                'sections' => fn ($query) => $query->whereNotIn('state', [Section::StateCancelled, Section::StateClosed]),
                'sections.cohorts',
                'sections.courseRevision',
                'sections.deliveryGroups' => fn ($query) => $query->whereNotIn('state', [SectionDeliveryGroup::StateCancelled, SectionDeliveryGroup::StateClosed]),
                'sections.deliveryGroups.schedulingDemands',
            ])->get();
        $cohorts = TermCohort::query()->where('term_id', $term->id)->with(['program', 'curriculumVersion'])->get();
        $classes = $offerings->flatMap(fn (TermOffering $offering) => $offering->sections)->unique('id');
        $observedYears = [];
        $servedRows = [];
        $cohortSources = [];
        $unresolvedCohortIds = [];
        foreach ($offerings as $offering) {
            foreach ($offering->sections as $class) {
                foreach ($class->cohorts as $cohort) {
                    if ((int) $cohort->term_id !== (int) $term->id) {
                        continue;
                    }
                    $courseId = $class->courseRevision?->course_id ?? $offering->curriculumEntry?->courseSpecification?->course_id;
                    $curriculum = $curricula->get($cohort->curriculum_version_id);
                    $cohortSources[$cohort->id][] = ['offering' => $offering, 'class' => $class];
                    $matchingEntries = $curriculum && (int) $curriculum->program_id === (int) $cohort->program_id
                        ? $curriculum->entries->filter(fn ($entry): bool => $courseId !== null && (int) $entry->courseSpecification?->course_id === (int) $courseId)
                        : collect();
                    if ($matchingEntries->isEmpty()) {
                        $unresolvedCohortIds[] = $cohort->id;
                    }
                    foreach ($matchingEntries->pluck('year_level')->unique() as $year) {
                        $observedYears[$cohort->id][] = (string) $year;
                        $servedRows[$this->key($curriculum->id, $year)][] = ['offering' => $offering, 'class' => $class];
                    }
                }
            }
        }
        $groups = collect();
        foreach ($offerings as $offering) {
            $key = $this->key($offering->curriculumEntry?->curriculum_version_id, $offering->curriculumEntry?->year_level);
            $additionalOffering = $offering->category === TermOffering::CategorySpecial
                || $offering->curriculumEntry?->term_type !== $term->type;
            foreach ($offering->sections->groupBy(fn (Section $class): string => $additionalOffering || $class->source === Section::SourceAdditional ? 'additional-' : '') as $prefix => $sections) {
                $source = clone $offering;
                $source->setRelation('sections', $sections);
                $groups->put($prefix.$key, $groups->get($prefix.$key, collect())->push($source));
            }
            if ($offering->sections->isEmpty()) {
                $groupKey = ($additionalOffering ? 'additional-' : '').$key;
                $groups->put($groupKey, $groups->get($groupKey, collect())->push($offering));
            }
        }
        foreach ($servedRows as $key => $sources) {
            foreach ($sources as $source) {
                $offering = clone $source['offering'];
                $offering->setRelation('sections', collect([$source['class']]));
                if ($offering->category !== TermOffering::CategorySpecial && $source['class']->source !== Section::SourceAdditional) {
                    $groups->put($key, $groups->get($key, collect())->push($offering));
                }
            }
        }
        $rows = [];
        foreach ($curricula as $curriculum) {
            foreach ($curriculum->entries->pluck('year_level')->unique() as $year) {
                $key = $this->key($curriculum->id, $year);
                $row = $this->row($key, $curriculum, (string) $year, $groups->get($key, collect()), $observedYears, $term);
                if ($row['class_count'] === 0 && $cohorts->where('curriculum_version_id', $curriculum->id)->whereIn('id', $unresolvedCohortIds)->isNotEmpty()) {
                    $row['status'] = 'Association unresolved';
                    $row['reason'] = 'A linked class exists for a student section in this curriculum, but its course cannot be associated with a recorded year for this Term type. Review the source association.';
                }
                $rows[] = $row;
                $groups->forget($key);
            }
        }
        $additional = [];
        foreach ($groups as $key => $group) {
            $entry = $group->first()->curriculumEntry;
            $additional[] = $this->row($key, $entry?->curriculumVersion, $entry?->year_level, $group, $observedYears, $term, false);
        }
        $linkedCohortIds = $classes->flatMap(fn (Section $class) => $class->cohorts)->pluck('id')->unique();
        foreach ($cohorts->filter(fn (TermCohort $cohort): bool => ! $linkedCohortIds->contains($cohort->id) || in_array($cohort->id, $unresolvedCohortIds, true)) as $cohort) {
            $cohort->curriculumVersion?->setRelation('program', $cohort->program);
            $sources = collect($cohortSources[$cohort->id] ?? [])->map(function (array $source): TermOffering {
                $offering = clone $source['offering'];

                return $offering->setRelation('sections', collect([$source['class']]));
            });
            $row = $this->row('cohort-'.$cohort->id, $cohort->curriculumVersion, null, $sources, $observedYears, $term, false);
            $row['sections'] = [$this->cohort($cohort, array_values(array_unique($observedYears[$cohort->id] ?? [])))];
            $row['section_references'] = [(string) $cohort->reference];
            $row['section_count'] = 1;
            $row['student_count'] = (int) ($cohort->confirmed_count ?? $cohort->forecast_count);
            $row['status'] = 'Needs association';
            $row['reason'] = $linkedCohortIds->contains($cohort->id)
                ? 'An active class is linked, but at least one course has no matching year in this section’s active curriculum for this Term type. Review the curriculum or approved-equivalency association; no year is guessed.'
                : 'This student section has no active linked class. Its year level is not recorded.';
            $additional[] = $row;
        }
        $demands = $classes->flatMap(fn (Section $class) => $class->deliveryGroups)
            ->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands)->unique('id');

        return ['rows' => $rows, 'additional' => $additional, 'summary' => [
            'class_count' => $classes->count(), 'section_count' => $cohorts->count(),
            'meeting_count' => (int) $demands->sum('meeting_count'),
            'program_count' => $offerings->map(fn (TermOffering $offering) => $offering->curriculumEntry?->curriculumVersion?->program_id)
                ->merge($cohorts->whereIn('id', $linkedCohortIds)->pluck('program_id'))->filter()->unique()->count(),
            'expected_year_count' => count($rows),
            'prepared_year_count' => collect($rows)->where('status', 'Prepared')->count(),
        ]];
    }

    private function key(?int $curriculumId, ?string $year): string
    {
        return 'curriculum-'.($curriculumId ?? 'unmapped').'-year-'.($year ?? 'unmapped');
    }

    /** @param Collection<int,TermOffering> $offerings @param array<int,list<string>> $observedYears @return array<string,mixed> */
    private function row(string $id, ?CurriculumVersion $curriculum, ?string $year, Collection $offerings, array $observedYears, Term $term, bool $associatedSectionsOnly = true): array
    {
        $classes = $offerings->flatMap(fn (TermOffering $offering) => $offering->sections)->unique('id');
        $cohorts = $classes->flatMap(fn (Section $class) => $class->cohorts)->where('term_id', $term->id)->unique('id');
        if ($associatedSectionsOnly) {
            $cohorts = $cohorts->filter(fn (TermCohort $cohort): bool => (int) $cohort->curriculum_version_id === (int) $curriculum?->id && in_array($year, $observedYears[$cohort->id] ?? [], true));
        }
        $demands = $classes->flatMap(fn (Section $class) => $class->deliveryGroups)
            ->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands)->unique('id');
        $findings = $demands->flatMap(fn (SchedulingDemand $demand): array => $demand->readinessFindings())->values()->all();
        $incomplete = $classes->contains(fn (Section $class): bool => $class->confirmed_at === null
            || $class->cohorts->where('term_id', $term->id)->isEmpty()
            || $class->deliveryGroups->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands)->isEmpty());
        $requiresReview = $incomplete || $findings !== [] || $demands->contains(fn (SchedulingDemand $demand): bool => $demand->validation_state !== SchedulingDemand::ValidationReadyForReview);
        $ambiguous = $cohorts->contains(fn (TermCohort $cohort): bool => count(array_unique($observedYears[$cohort->id] ?? [])) > 1);

        return [
            'id' => $id, 'program_id' => $curriculum?->program_id,
            'program_name' => $curriculum?->program?->name ?? 'Unmapped program',
            'curriculum_version_id' => $curriculum?->id,
            'curriculum_label' => $curriculum?->version_code ?? 'Unmapped curriculum',
            'year_level' => $year, 'offering_count' => $offerings->unique('id')->count(),
            'sections' => $cohorts->map(fn (TermCohort $cohort): array => $this->cohort($cohort, array_values(array_unique($observedYears[$cohort->id] ?? []))))->values()->all(),
            'section_references' => $cohorts->pluck('reference')->values()->all(),
            'section_count' => $cohorts->count(),
            'student_count' => (int) $cohorts->sum(fn (TermCohort $cohort): int => (int) ($cohort->confirmed_count ?? $cohort->forecast_count)),
            'classes' => $classes->map(fn (Section $class): array => ['id' => $class->id, 'reference' => $class->class_reference ?: $class->code, 'confirmed' => $class->confirmed_at !== null])->values()->all(),
            'class_count' => $classes->count(), 'meeting_count' => (int) $demands->sum('meeting_count'),
            'findings' => $findings, 'has_cross_year_sections' => $ambiguous,
            'status' => $classes->isEmpty() ? 'Not set up' : ($requiresReview ? 'Needs preparation' : 'Prepared'),
            'reason' => $classes->isEmpty()
                ? 'No active classes are recorded for this curriculum year. This is advisory; whether it runs this Term is a school decision.'
                : ($requiresReview ? 'Review class confirmation, linked student sections, meeting requirements and recorded findings.' : 'Classes are confirmed and meeting requirements are recorded. Generation still checks calendar, teachers, rooms and source readiness.'),
        ];
    }

    /** @param list<string> $years @return array<string,mixed> */
    private function cohort(TermCohort $cohort, array $years): array
    {
        return ['id' => $cohort->id, 'reference' => (string) $cohort->reference,
            'program_id' => $cohort->program_id, 'curriculum_version_id' => $cohort->curriculum_version_id,
            'observed_year_levels' => $years, 'year_association' => count($years) > 1 ? 'Cross-year association' : (count($years) === 1 ? 'Observed through linked classes' : 'Year not recorded'),
            'student_count' => (int) ($cohort->confirmed_count ?? $cohort->forecast_count)];
    }
}
