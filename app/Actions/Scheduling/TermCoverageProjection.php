<?php

namespace App\Actions\Scheduling;

use App\Models\AcademicYear;
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
        $term->loadMissing('academicYear');
        $termStartYear = $term->academicYear instanceof AcademicYear
            ? CurriculumVersion::extractAcademicYearStart($term->academicYear)
            : $term->starts_on?->year;

        $curricula = CurriculumVersion::query()
            ->where(fn ($query) => $query
                ->where('state', CurriculumVersion::StateActive)
                ->orWhere(fn ($superseded) => $superseded
                    ->where('state', CurriculumVersion::StateSuperseded)
                    ->where(fn ($usedInTerm) => $usedInTerm
                        ->whereHas('termCohorts', fn ($cohortQuery) => $cohortQuery->where('term_id', $term->id))
                        ->orWhereHas('entries.termOfferings', fn ($offeringQuery) => $offeringQuery
                            ->where('term_id', $term->id)
                            ->where('state', '!=', TermOffering::StateCancelled)))))
            ->with(['program', 'academicYears', 'entries' => fn ($query) => $query->where('term_type', $term->type)->orderBy('sequence'), 'entries.courseSpecification'])->get()->keyBy('id');
        $offerings = TermOffering::query()->where('term_id', $term->id)
            ->where('state', '!=', TermOffering::StateCancelled)
            ->with(['curriculumEntry.curriculumVersion.program', 'curriculumEntry.curriculumVersion.academicYears', 'curriculumEntry.courseSpecification.course',
                'sections' => fn ($query) => $query->whereNotIn('state', [Section::StateCancelled, Section::StateClosed]),
                'sections.cohorts.entryAcademicYear',
                'sections.courseRevision',
                'sections.deliveryGroups' => fn ($query) => $query->whereNotIn('state', [SectionDeliveryGroup::StateCancelled, SectionDeliveryGroup::StateClosed]),
                'sections.deliveryGroups.schedulingDemands',
            ])->get();
        $cohorts = TermCohort::query()->where('term_id', $term->id)->with(['program', 'entryAcademicYear', 'curriculumVersion.academicYears'])->get();
        $classes = $offerings->flatMap(fn (TermOffering $offering) => $offering->sections)->unique('id');

        /** @var array<int, AcademicYear> $academicYearsById */
        $academicYearsById = [];
        foreach ($curricula as $curriculum) {
            foreach ($curriculum->academicYears as $ay) {
                $academicYearsById[(int) $ay->id] = $ay;
            }
        }
        foreach ($cohorts as $cohort) {
            if ($cohort->entryAcademicYear instanceof AcademicYear) {
                $academicYearsById[(int) $cohort->entryAcademicYear->id] = $cohort->entryAcademicYear;
            }
        }

        $observedYears = [];
        $servedRows = [];
        $servedBatchIdsByCurriculumYear = [];
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
                        $yearStr = (string) $year;
                        $observedYears[$cohort->id][] = $yearStr;
                        $batchAy = $this->resolveBatchAcademicYear($curriculum, $yearStr, $termStartYear, $cohort);
                        $batchId = $batchAy?->id;
                        if ($batchAy instanceof AcademicYear) {
                            $academicYearsById[(int) $batchAy->id] = $batchAy;
                        }
                        $servedBatchIdsByCurriculumYear[$curriculum->id][$yearStr][$batchId ?? 'default'] = $batchId;
                        $servedRows[$this->key($curriculum->id, $batchId, $yearStr)][] = ['offering' => $offering, 'class' => $class];
                    }
                }
            }
        }

        $groups = collect();
        foreach ($offerings as $offering) {
            $offeringCurriculum = $offering->curriculumEntry?->curriculumVersion;
            $offeringYear = $offering->curriculumEntry?->year_level !== null ? (string) $offering->curriculumEntry->year_level : null;
            $additionalOffering = $offering->category === TermOffering::CategorySpecial
                || $offering->curriculumEntry?->term_type !== $term->type;
            $defaultBatchAy = $this->resolveBatchAcademicYear($offeringCurriculum, $offeringYear, $termStartYear);
            if ($defaultBatchAy instanceof AcademicYear) {
                $academicYearsById[(int) $defaultBatchAy->id] = $defaultBatchAy;
            }

            if ($offering->sections->isEmpty()) {
                $groupKey = ($additionalOffering ? 'additional-' : '').$this->key($offeringCurriculum?->id, $defaultBatchAy?->id, $offeringYear);
                $groups->put($groupKey, $groups->get($groupKey, collect())->push($offering));
                if (! $additionalOffering && $offeringCurriculum instanceof CurriculumVersion && $offeringYear !== null) {
                    $servedBatchIdsByCurriculumYear[$offeringCurriculum->id][$offeringYear][$defaultBatchAy?->id ?? 'default'] = $defaultBatchAy?->id;
                }

                continue;
            }

            foreach ($offering->sections as $class) {
                $prefix = ($additionalOffering || $class->source === Section::SourceAdditional) ? 'additional-' : '';
                $matchingCohorts = $class->cohorts->where('term_id', $term->id);
                $batchIdsForSection = [];

                if ($offeringCurriculum instanceof CurriculumVersion && $matchingCohorts->isNotEmpty()) {
                    foreach ($matchingCohorts as $cohort) {
                        if ((int) $cohort->curriculum_version_id !== (int) $offeringCurriculum->id) {
                            continue;
                        }
                        $resolvedBatch = $this->resolveBatchAcademicYear($offeringCurriculum, $offeringYear, $termStartYear, $cohort);
                        if ($resolvedBatch instanceof AcademicYear) {
                            $academicYearsById[(int) $resolvedBatch->id] = $resolvedBatch;
                        }
                        $batchIdsForSection[$resolvedBatch?->id ?? 'default'] = $resolvedBatch?->id;
                    }
                }

                if ($batchIdsForSection === []) {
                    $batchIdsForSection[$defaultBatchAy?->id ?? 'default'] = $defaultBatchAy?->id;
                }

                foreach ($batchIdsForSection as $batchId) {
                    $groupKey = $prefix.$this->key($offeringCurriculum?->id, $batchId, $offeringYear);
                    $source = clone $offering;
                    $source->setRelation('sections', collect([$class]));
                    $groups->put($groupKey, $groups->get($groupKey, collect())->push($source));
                    if ($prefix === '' && $offeringCurriculum instanceof CurriculumVersion && $offeringYear !== null) {
                        $servedBatchIdsByCurriculumYear[$offeringCurriculum->id][$offeringYear][$batchId ?? 'default'] = $batchId;
                    }
                }
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
                $yearStr = (string) $year;
                $batchBucket = $servedBatchIdsByCurriculumYear[$curriculum->id][$yearStr] ?? [];

                if ($batchBucket === []) {
                    $defaultBatchAy = $this->resolveBatchAcademicYear($curriculum, $yearStr, $termStartYear);
                    if ($defaultBatchAy instanceof AcademicYear) {
                        $academicYearsById[(int) $defaultBatchAy->id] = $defaultBatchAy;
                    }
                    $batchBucket[$defaultBatchAy?->id ?? 'default'] = $defaultBatchAy?->id;
                }

                foreach ($batchBucket as $batchId) {
                    $batchAy = $batchId !== null ? ($academicYearsById[(int) $batchId] ?? AcademicYear::query()->find($batchId)) : null;
                    $key = $this->key($curriculum->id, $batchId, $yearStr);
                    $row = $this->row($key, $curriculum, $yearStr, $groups->get($key, collect()), $observedYears, $term, true, $batchAy);
                    if ($row['class_count'] === 0 && $cohorts->where('curriculum_version_id', $curriculum->id)->whereIn('id', $unresolvedCohortIds)->isNotEmpty()) {
                        $row['status'] = 'Association unresolved';
                        $row['reason'] = 'A linked class exists for a student section in this curriculum, but its course cannot be associated with a recorded year for this Term type. Review the source association.';
                    }
                    $rows[] = $row;
                    $groups->forget($key);
                }
            }
        }

        $additional = [];
        foreach ($groups as $key => $group) {
            $entry = $group->first()->curriculumEntry;
            $entryCurriculum = $entry?->curriculumVersion;
            $entryYear = $entry?->year_level !== null ? (string) $entry->year_level : null;
            $batchAy = $this->extractBatchAcademicYearFromKey($key, $academicYearsById)
                ?? $this->resolveBatchAcademicYear($entryCurriculum, $entryYear, $termStartYear);
            $additional[] = $this->row($key, $entryCurriculum, $entryYear, $group, $observedYears, $term, false, $batchAy);
        }

        $linkedCohortIds = $classes->flatMap(fn (Section $class) => $class->cohorts)->pluck('id')->unique();
        foreach ($cohorts->filter(fn (TermCohort $cohort): bool => ! $linkedCohortIds->contains($cohort->id) || in_array($cohort->id, $unresolvedCohortIds, true)) as $cohort) {
            $cohort->curriculumVersion?->setRelation('program', $cohort->program);
            $sources = collect($cohortSources[$cohort->id] ?? [])->map(function (array $source): TermOffering {
                $offering = clone $source['offering'];

                return $offering->setRelation('sections', collect([$source['class']]));
            });
            $cohortYearLevel = $cohort->resolvedYearLevel();
            $cohortBatchAy = $this->resolveBatchAcademicYear(
                $cohort->curriculumVersion,
                $cohortYearLevel !== null ? (string) $cohortYearLevel : null,
                $termStartYear,
                $cohort,
            );
            $row = $this->row('cohort-'.$cohort->id, $cohort->curriculumVersion, null, $sources, $observedYears, $term, false, $cohortBatchAy);
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

    private function key(?int $curriculumId, ?int $entryAcademicYearId, ?string $year): string
    {
        return 'curriculum-'.($curriculumId ?? 'unmapped')
            .'-batch-'.($entryAcademicYearId ?? 'default')
            .'-year-'.($year ?? 'unmapped');
    }

    private function resolveBatchAcademicYear(
        ?CurriculumVersion $curriculum,
        ?string $year,
        ?int $termStartYear,
        ?TermCohort $cohort = null,
    ): ?AcademicYear {
        if ($cohort instanceof TermCohort && $cohort->entry_academic_year_id !== null) {
            if ($cohort->relationLoaded('entryAcademicYear') && $cohort->entryAcademicYear instanceof AcademicYear) {
                return $cohort->entryAcademicYear;
            }

            if ($curriculum instanceof CurriculumVersion) {
                $fromCurriculum = $curriculum->academicYears->firstWhere('id', (int) $cohort->entry_academic_year_id);
                if ($fromCurriculum instanceof AcademicYear) {
                    return $fromCurriculum;
                }
            }

            return AcademicYear::query()->find((int) $cohort->entry_academic_year_id);
        }

        if (! $curriculum instanceof CurriculumVersion) {
            return null;
        }

        $curriculum->loadMissing('academicYears');
        $parsedYear = $year !== null ? TermCohort::parseYearLevelValue($year) : $cohort?->resolvedYearLevel();

        if ($termStartYear !== null && $parsedYear !== null) {
            $targetIntakeYear = $termStartYear - ($parsedYear - 1);
            $matched = $curriculum->academicYears->first(
                fn (AcademicYear $academicYear): bool => CurriculumVersion::extractAcademicYearStart($academicYear) === $targetIntakeYear,
            );

            if ($matched instanceof AcademicYear) {
                return $matched;
            }
        }

        if ($curriculum->academicYears->count() === 1) {
            return $curriculum->academicYears->first();
        }

        return null;
    }

    /** @param array<int, AcademicYear> $academicYearsById */
    private function extractBatchAcademicYearFromKey(string $key, array $academicYearsById): ?AcademicYear
    {
        if (preg_match('/-batch-(\d+)-year-/', $key, $matches) === 1) {
            $batchId = (int) $matches[1];

            return $academicYearsById[$batchId] ?? AcademicYear::query()->find($batchId);
        }

        return null;
    }

    /** @param Collection<int,TermOffering> $offerings @param array<int,list<string>> $observedYears @return array<string,mixed> */
    private function row(
        string $id,
        ?CurriculumVersion $curriculum,
        ?string $year,
        Collection $offerings,
        array $observedYears,
        Term $term,
        bool $associatedSectionsOnly = true,
        ?AcademicYear $batchAcademicYear = null,
    ): array {
        $classes = $offerings->flatMap(fn (TermOffering $offering) => $offering->sections)->unique('id');
        $cohorts = $classes->flatMap(fn (Section $class) => $class->cohorts)->where('term_id', $term->id)->unique('id');
        if ($associatedSectionsOnly) {
            $cohorts = $cohorts->filter(function (TermCohort $cohort) use ($curriculum, $year, $observedYears, $batchAcademicYear): bool {
                if ((int) $cohort->curriculum_version_id !== (int) $curriculum?->id || ! in_array($year, $observedYears[$cohort->id] ?? [], true)) {
                    return false;
                }

                if ($batchAcademicYear instanceof AcademicYear && $cohort->entry_academic_year_id !== null) {
                    return (int) $cohort->entry_academic_year_id === (int) $batchAcademicYear->id;
                }

                return true;
            });
        }
        $demands = $classes->flatMap(fn (Section $class) => $class->deliveryGroups)
            ->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands)->unique('id');
        $findings = $demands->flatMap(fn (SchedulingDemand $demand): array => $demand->readinessFindings())->values()->all();
        $incomplete = $classes->contains(fn (Section $class): bool => $class->confirmed_at === null
            || $class->cohorts->where('term_id', $term->id)->isEmpty()
            || $class->deliveryGroups->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands)->isEmpty());
        $requiresReview = $incomplete || $findings !== [] || $demands->contains(fn (SchedulingDemand $demand): bool => $demand->validation_state !== SchedulingDemand::ValidationReadyForReview);
        $ambiguous = $cohorts->contains(fn (TermCohort $cohort): bool => count(array_unique($observedYears[$cohort->id] ?? [])) > 1);

        $curriculumLabel = match (true) {
            $curriculum instanceof CurriculumVersion && $batchAcademicYear instanceof AcademicYear => $curriculum->formatBatchOptionLabel($batchAcademicYear, includeProgram: false),
            $curriculum instanceof CurriculumVersion => $curriculum->batchLabel(),
            default => 'Unmapped curriculum',
        };
        $batchLabel = $batchAcademicYear instanceof AcademicYear
            ? 'Batch '.CurriculumVersion::formatBatchShort($batchAcademicYear)
            : null;

        return [
            'id' => $id,
            'program_id' => $curriculum?->program_id,
            'program_code' => $curriculum?->program?->code,
            'program_name' => $curriculum?->program?->name ?? 'Unmapped program',
            'curriculum_version_id' => $curriculum?->id,
            'entry_academic_year_id' => $batchAcademicYear?->id,
            'batch_label' => $batchLabel,
            'curriculum_label' => $curriculumLabel,
            'curriculum_version_code' => $curriculum?->version_code,
            'curriculum_state' => $curriculum?->state,
            'curriculum_state_label' => match ($curriculum?->state) {
                CurriculumVersion::StateActive => 'Active',
                CurriculumVersion::StateDraft => 'Draft',
                CurriculumVersion::StateSuperseded, CurriculumVersion::StateArchived => 'Inactive',
                default => null,
            },
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
            'entry_academic_year_id' => $cohort->entry_academic_year_id,
            'observed_year_levels' => $years, 'year_association' => count($years) > 1 ? 'Cross-year association' : (count($years) === 1 ? 'Observed through linked classes' : 'Year not recorded'),
            'student_count' => (int) ($cohort->confirmed_count ?? $cohort->forecast_count)];
    }
}
