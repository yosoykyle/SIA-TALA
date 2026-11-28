<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PrepareTermClassProposal
{
    /** @return array<string, mixed> */
    public function preview(Term $term): array
    {
        $term->loadMissing('academicYear');
        $termStartYear = $term->academicYear instanceof AcademicYear
            ? CurriculumVersion::extractAcademicYearStart($term->academicYear)
            : $term->starts_on?->year;

        $entries = CurriculumEntry::query()
            ->whereHas('curriculumVersion', fn ($query) => $query->whereIn('state', [
                CurriculumVersion::StateActive,
                CurriculumVersion::StateSuperseded,
            ]))
            ->with(['curriculumVersion.program', 'curriculumVersion.academicYears', 'courseSpecification.course', 'courseSpecification.components'])
            ->orderBy('curriculum_version_id')->orderBy('year_level')->orderBy('sequence')->get();
        $classes = Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->with(['termOffering', 'cohorts.entryAcademicYear', 'deliveryGroups'])->orderBy('id')->get();
        $hasPublishedVersion = PublishedTimetableVersion::query()->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)->exists();
        $publishedSectionIds = PublishedTimetableMeeting::query()->whereIn('section_id', $classes->modelKeys())
            ->distinct()->orderBy('section_id')->pluck('section_id')
            ->mapWithKeys(fn ($sectionId): array => [(int) $sectionId => true])->all();
        $cohorts = TermCohort::query()
            ->where('term_id', $term->id)
            ->with(['entryAcademicYear', 'program', 'curriculumVersion.academicYears'])
            ->orderBy('id')
            ->get();
        $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();
        $catalog = $entries->map(function (CurriculumEntry $entry) use ($term, $termStartYear, $classes): array {
            $curriculum = $entry->curriculumVersion;
            $entryYearInt = TermCohort::parseYearLevelValue($entry->year_level);
            $matchedBatch = null;

            if ($termStartYear !== null && $entryYearInt !== null) {
                $targetIntakeYear = $termStartYear - ($entryYearInt - 1);
                $matchedBatch = $curriculum->academicYears->first(
                    fn (AcademicYear $academicYear): bool => CurriculumVersion::extractAcademicYearStart($academicYear) === $targetIntakeYear,
                );
            }

            if (! $matchedBatch instanceof AcademicYear && $curriculum->academicYears->count() === 1) {
                $matchedBatch = $curriculum->academicYears->first();
            }

            $curriculumDisplay = $matchedBatch instanceof AcademicYear
                ? $curriculum->formatBatchOptionLabel($matchedBatch, includeProgram: false)
                : $curriculum->batchLabel();
            $batchShort = $matchedBatch instanceof AcademicYear
                ? 'Batch '.CurriculumVersion::formatBatchShort($matchedBatch)
                : null;

            return [
                'id' => $entry->id,
                'course_title' => $entry->courseSpecification->title,
                'course_code' => $entry->courseSpecification->course->code,
                'program' => $curriculum->program->name,
                'program_code' => $curriculum->program->code,
                'year_level' => $entry->year_level,
                'curriculum_version' => $curriculumDisplay,
                'batch_label' => $batchShort,
                'entry_academic_year_id' => $matchedBatch?->id,
                'label' => $curriculum->program->name.' · '.$entry->year_level.' · '.$entry->courseSpecification->title.' ('.$entry->courseSpecification->course->code.') · '.$curriculumDisplay.($entry->term_type !== $term->type ? ' · Additional only in this Term' : ''),
                'curriculum_id' => $entry->curriculum_version_id,
                'course_id' => $entry->courseSpecification->course_id,
                'term_type' => $entry->term_type,
                'modes' => $entry->courseSpecification->allowed_modalities ?? [],
                'meetings' => $entry->courseSpecification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                    ? 'Externally arranged (no recurring meetings)'
                    : $entry->courseSpecification->components->map(fn (CourseComponent $component): ?string => CourseComponent::meetingPatternOptions()[$component->meeting_pattern] ?? $component->meeting_pattern)->filter()->implode(' + '),
                'existing' => $classes->filter(fn (Section $class): bool => (int) $class->termOffering->curriculum_entry_id === $entry->id && $class->state !== Section::StateCancelled)->count(),
            ];
        })->all();
        $rows = $classes->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])->map(fn (Section $class): array => [
            'section_id' => $class->id, 'entry_id' => $class->termOffering->curriculum_entry_id, 'is_catalog' => true,
            'is_confirmed' => $class->confirmed_at !== null,
            'action' => $class->confirmed_at ? 'keep' : 'confirm', 'code' => $class->code,
            'capacity' => $class->capacity, 'source' => $class->source,
            'modality' => $class->delivery_mode ?: $class->termOffering->modality,
            'authority_reference' => $class->authority_reference,
            'requires_timetable_revision' => $hasPublishedVersion || isset($publishedSectionIds[$class->id]),
            'cohorts' => $class->cohorts->map(fn (TermCohort $cohort): array => ['id' => $cohort->id, 'count' => $cohort->pivot->expected_count])->all(),
        ])->values()->all();
        foreach ($entries as $entry) {
            if ($entry->term_type !== $term->type || $classes->contains(fn (Section $class): bool => (int) $class->termOffering->curriculum_entry_id === $entry->id && $class->state !== Section::StateCancelled)) {
                continue;
            }
            $modes = $entry->courseSpecification->allowed_modalities ?? [];
            $rows[] = ['section_id' => null, 'entry_id' => $entry->id, 'is_catalog' => true, 'is_confirmed' => false,
                'action' => 'skip', 'code' => '', 'capacity' => null, 'source' => null,
                'modality' => count($modes) === 1 ? $modes[0] : null, 'authority_reference' => null, 'cohorts' => []];
        }

        $demandEvidence = $this->demandEvidence($term);

        return ['catalog' => $catalog, 'classes' => $rows, 'cohorts' => $cohorts, 'demand_evidence' => $demandEvidence,
            'token' => hash('sha256', json_encode([
                $term->fresh()->only(['id', 'type', 'state', 'academic_year_id']), $package?->concurrencyToken(),
                $entries->toArray(), $classes->toArray(), $cohorts->toArray(), $hasPublishedVersion, $publishedSectionIds,
                $demandEvidence['ready_applicants'], $demandEvidence['unmet'],
            ], JSON_THROW_ON_ERROR))];
    }

    /** @return array{ready_applicants:int,continuing_profiles:int,unmet:list<array<string,mixed>>} */
    public function demandEvidence(
        Term $term,
        ?int $programId = null,
        ?int $curriculumVersionId = null,
        ?int $entryAcademicYearId = null,
        ?int $intakeStartYear = null,
        ?TermCohort $cohort = null,
    ): array {
        if ($cohort instanceof TermCohort) {
            $programId ??= $cohort->program_id !== null ? (int) $cohort->program_id : null;
            $curriculumVersionId ??= $cohort->curriculum_version_id !== null ? (int) $cohort->curriculum_version_id : null;
            $entryAcademicYearId ??= $cohort->entry_academic_year_id !== null ? (int) $cohort->entry_academic_year_id : null;

            if ($entryAcademicYearId === null && $intakeStartYear === null) {
                $term->loadMissing('academicYear');
                $termStartYear = $term->academicYear instanceof AcademicYear
                    ? CurriculumVersion::extractAcademicYearStart($term->academicYear)
                    : $term->starts_on?->year;
                $cohortYearLevel = $cohort->resolvedYearLevel();

                if ($termStartYear !== null && $cohortYearLevel !== null) {
                    $intakeStartYear = $termStartYear - ($cohortYearLevel - 1);
                }
            }
        }

        if ($programId === null && $curriculumVersionId !== null) {
            $programId = CurriculumVersion::query()->whereKey($curriculumVersionId)->value('program_id');
            $programId = $programId !== null ? (int) $programId : null;
        }

        $readyQuery = AdmissionApplication::query()
            ->where('term_id', $term->id)
            ->where('application_state', AdmissionApplication::StateAdmitted);

        if ($programId !== null) {
            $readyQuery->where('program_id', $programId);
        }

        $ready = $readyQuery->get()
            ->filter(fn (AdmissionApplication $application): bool => app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready'])
            ->count();
        $unmet = Enrollment::query()->where('term_id', $term->id)->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->whereNotNull('current_proposal_version_id')->get()->flatMap(fn (Enrollment $case): array => app(RegistrationShortageProjection::class)->for($case))->all();

        $continuingQuery = StudentProfile::query()
            ->where('lifecycle_status', StudentProfile::LifecycleActive)
            ->whereNull('archived_at')
            ->whereNull('merged_into_id')
            ->where(fn (Builder $query): Builder => $query->whereNull('entry_term_id')->orWhere('entry_term_id', '!=', $term->id));

        if ($programId !== null) {
            $continuingQuery->where('program_id', $programId);
        }

        if ($curriculumVersionId !== null) {
            $continuingQuery->where('curriculum_version_id', $curriculumVersionId);
        }

        $resolvedStartYear = $intakeStartYear;
        if ($resolvedStartYear === null && $entryAcademicYearId !== null) {
            $entryAy = AcademicYear::query()->find($entryAcademicYearId);
            if ($entryAy instanceof AcademicYear) {
                $resolvedStartYear = CurriculumVersion::extractAcademicYearStart($entryAy);
            }
        }

        if ($resolvedStartYear !== null || $entryAcademicYearId !== null) {
            $matchingAyIds = $resolvedStartYear !== null
                ? self::matchingAcademicYearIdsForStartYear($resolvedStartYear)
                : [$entryAcademicYearId];

            if ($entryAcademicYearId !== null && ! in_array($entryAcademicYearId, $matchingAyIds, true)) {
                $matchingAyIds[] = $entryAcademicYearId;
            }

            $includeUndatedLegacy = false;
            if ($curriculumVersionId !== null) {
                $curriculum = CurriculumVersion::query()->with('academicYears')->find($curriculumVersionId);
                if ($curriculum instanceof CurriculumVersion) {
                    $includeUndatedLegacy = $curriculum->academicYears->count() <= 1;
                }
            }

            self::applyIntakeBatchScope($continuingQuery, $matchingAyIds, $includeUndatedLegacy);
        }

        return [
            'ready_applicants' => $ready,
            'continuing_profiles' => $continuingQuery->count(),
            'unmet' => $unmet,
        ];
    }

    /**
     * @return list<int>
     */
    public static function matchingAcademicYearIdsForStartYear(int $startYear): array
    {
        return AcademicYear::query()
            ->get()
            ->filter(fn (AcademicYear $year): bool => CurriculumVersion::extractAcademicYearStart($year) === $startYear)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<StudentProfile>  $query
     * @param  list<int>  $matchingAyIds
     */
    public static function applyIntakeBatchScope(Builder $query, array $matchingAyIds, bool $includeUndatedLegacyFallback): void
    {
        if ($matchingAyIds === []) {
            if ($includeUndatedLegacyFallback) {
                $query->whereNull('entry_academic_year_id')->whereNull('entry_term_id');
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $query->where(function (Builder $batchQuery) use ($matchingAyIds, $includeUndatedLegacyFallback): void {
            $batchQuery->whereIn('entry_academic_year_id', $matchingAyIds)
                ->orWhere(function (Builder $termFallback) use ($matchingAyIds, $includeUndatedLegacyFallback): void {
                    $termFallback->whereNull('entry_academic_year_id')
                        ->where(function (Builder $inner) use ($matchingAyIds, $includeUndatedLegacyFallback): void {
                            $inner->whereHas('entryTerm', fn (Builder $termQuery): Builder => $termQuery->whereIn('academic_year_id', $matchingAyIds));

                            if ($includeUndatedLegacyFallback) {
                                $inner->orWhereNull('entry_term_id');
                            }
                        });
                });
        });
    }

    /** @param list<array<string,mixed>> $rows
     * @return array{confirmed:int,cancelled:int}
     */
    public function confirm(Term $term, User $actor, array $rows, string $expectedToken): array
    {
        Gate::forUser($actor)->authorize('create', TermOffering::class);

        return DB::transaction(function () use ($term, $actor, $rows, $expectedToken): array {
            $term = Term::query()->lockForUpdate()->findOrFail($term->id);
            $preview = $this->preview($term);
            if ($term->state === Term::StateClosed || ! hash_equals($preview['token'], $expectedToken)) {
                throw ValidationException::withMessages(['proposal' => 'The Term, calendar, curriculum or class demand changed. Reload the proposal and review it again. Nothing was saved.']);
            }
            $existing = collect($preview['classes'])->keyBy('section_id');
            $cohorts = $preview['cohorts']->keyBy('id');
            $seen = [];
            $allocatedCodes = [];
            $items = [];
            $cancelled = 0;
            foreach ($rows as $index => $row) {
                $action = $row['action'] ?? 'skip';
                if (! in_array($action, ['skip', 'keep', 'confirm', 'cancel'], true)) {
                    throw ValidationException::withMessages(["classes.{$index}.action" => 'Choose a valid class action.']);
                }
                $id = (int) ($row['section_id'] ?? 0);
                if ($id !== 0 && (! $existing->has($id) || isset($seen[$id]))) {
                    throw ValidationException::withMessages(['classes' => 'Each existing class must belong to this exact Term and appear once.']);
                }
                if ($id !== 0) {
                    $seen[$id] = true;
                }
                if ($action === 'skip' || $action === 'keep') {
                    continue;
                }
                if ($action === 'cancel') {
                    Validator::make($row, ['cancellation_reason' => ['required', 'string', 'max:2000']])->validate();
                    $class = Section::query()->lockForUpdate()->findOrFail($id);
                    Gate::forUser($actor)->authorize('update', $class);
                    if (PublishedTimetableMeeting::query()->where('section_id', $id)->exists()
                        || PublishedTimetableVersion::query()->where('term_id', $term->id)->where('state', PublishedTimetableVersion::StatePublished)->exists()
                        || CourseEnrollment::query()->where('section_id', $id)->where('is_current', true)->exists()
                        || EnrollmentSeatReservation::query()->where('section_id', $id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->exists()) {
                        throw ValidationException::withMessages(['classes' => 'Cancellation requires a reason. A published class must use the controlled timetable revision and enrollment-impact path.']);
                    }
                    $class->update(['state' => Section::StateCancelled]);
                    $groupIds = $class->deliveryGroups()->pluck('id')->all();
                    if ($groupIds !== []) {
                        SectionDeliveryGroup::query()
                            ->whereIn('id', $groupIds)
                            ->whereNotIn('state', [SectionDeliveryGroup::StateCancelled, SectionDeliveryGroup::StateClosed])
                            ->update(['state' => SectionDeliveryGroup::StateCancelled, 'updated_at' => now()]);

                        SchedulingDemand::query()
                            ->whereIn('section_delivery_group_id', $groupIds)
                            ->whereDoesntHave('sectionMeetings')
                            ->whereDoesntHave('candidateRows')
                            ->delete();
                    }
                    activity()->causedBy($actor)->performedOn($class)->withProperties(['term_id' => $term->id, 'reason' => $row['cancellation_reason']])->log('class_cancelled');
                    $cancelled++;

                    continue;
                }
                $data = Validator::make($row, [
                    'entry_id' => ['required', 'integer', Rule::in(collect($preview['catalog'])->pluck('id')->all())],
                    'code' => ['nullable', 'string', 'max:64'], 'capacity' => ['required', 'integer', 'min:1'],
                    'source' => ['required', Rule::in(array_keys(Section::confirmableSourceOptions()))],
                    'modality' => ['required', Rule::in(array_keys(TermOffering::modalityOptions()))],
                    'authority_reference' => ['nullable', 'string', 'max:255', 'required_if:source,'.Section::SourceAdditional],
                    'cohorts' => ['required', 'array', 'min:1'], 'cohorts.*.id' => ['required', 'integer', 'distinct'],
                    'cohorts.*.count' => ['required', 'integer', 'min:1'],
                ])->validate();
                $entry = CurriculumEntry::query()->with(['curriculumVersion', 'courseSpecification.course', 'courseSpecification.components'])->findOrFail($data['entry_id']);
                $revision = $entry->courseSpecification;
                if ($entry->term_type !== $term->type && $data['source'] !== Section::SourceAdditional) {
                    throw ValidationException::withMessages(['classes' => 'A course outside this Term’s curriculum period requires an approved Additional class.']);
                }
                if ($revision->state !== CourseSpecification::StateActive || ! in_array($data['modality'], $revision->allowed_modalities ?? [], true)) {
                    throw ValidationException::withMessages(['classes' => 'Choose an Active Course Revision and one of its approved delivery modes.']);
                }
                if ($revision->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged
                    && ($revision->components->isEmpty() || $revision->components->contains(function (CourseComponent $component): bool {
                        $pattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

                        return $pattern === null || (int) round((float) $component->weekly_contact_hours * 60) !== $pattern['count'] * $pattern['duration_minutes'];
                    }))) {
                    throw ValidationException::withMessages(['classes' => 'The approved recurring meeting definition is incomplete. Correct the Course Revision before confirmation.']);
                }
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $cohort): array => [(int) $cohort['id'] => (int) $cohort['count']])->all();
                foreach ($counts as $cohortId => $count) {
                    if (! $cohorts->has($cohortId) || $cohorts[$cohortId]->confirmed_at === null || $count > $cohorts[$cohortId]->confirmed_count) {
                        throw ValidationException::withMessages(['classes' => 'Link confirmed student sections from this Term and use their attributable learner counts.']);
                    }
                }
                $class = $id ? Section::query()->lockForUpdate()->findOrFail($id) : null;
                if ($class && ($class->confirmed_at || (int) $class->termOffering->curriculum_entry_id !== $entry->id || $class->capacity !== (int) $data['capacity'])) {
                    throw ValidationException::withMessages(['classes' => 'Retain confirmed classes unchanged. Correct a Draft capacity at its source, then reload the proposal.']);
                }

                $firstCohortId = (int) array_key_first($counts);
                $firstCohort = $cohorts->get($firstCohortId);
                $providedCode = trim((string) ($data['code'] ?? ''));

                if ($providedCode !== '') {
                    $code = $providedCode;
                } elseif ($class instanceof Section && filled($class->class_reference ?: $class->code)) {
                    $code = trim((string) ($class->class_reference ?: $class->code));
                } else {
                    $code = PrepareClassOffering::generateUniqueReference(
                        $term,
                        $revision->course?->code,
                        $firstCohort?->reference,
                        $allocatedCodes,
                        $class?->id,
                    );
                }

                if (! $class) {
                    $duplicateInBatch = in_array(strtoupper($code), array_map('strtoupper', $allocatedCodes), true);
                    if ($duplicateInBatch || Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                        ->where(fn ($query) => $query->where('code', $code)->orWhere('class_reference', $code))->exists()) {
                        throw ValidationException::withMessages(['classes' => 'Every class needs its own reference in this Term.']);
                    }
                    $offering = TermOffering::query()->firstOrCreate(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id, 'delivery_variant' => TermOffering::ArrangementNormalClass], [
                        'category' => TermOffering::CategoryRegular, 'modality' => $data['modality'], 'expected_count' => array_sum($counts), 'state' => TermOffering::StatePendingScheduling,
                    ]);
                    if ($offering->state !== TermOffering::StatePendingScheduling) {
                        throw ValidationException::withMessages(['classes' => 'This course source is already published or cancelled. Use its controlled revision path to change the class set.']);
                    }
                    $class = Section::query()->create(['term_offering_id' => $offering->id, 'code' => $code, 'capacity' => $data['capacity'], 'state' => Section::StatePlanned]);
                    $class->deliveryGroups()->create(['name' => $code, 'expected_count' => array_sum($counts), 'modality' => $data['modality'], 'state' => SectionDeliveryGroup::StateReady]);
                }
                $allocatedCodes[] = $code;
                $class = app(PrepareClassOffering::class)->execute($term, $class, $actor, [
                    'class_reference' => $code, 'source' => $data['source'], 'delivery_mode' => $data['modality'], 'authority_reference' => $data['authority_reference'] ?? null,
                ]);
                $items[] = ['section' => $class, 'cohort_counts' => $counts, 'authority_reference' => $data['authority_reference'] ?? null];
            }
            app(ConfirmClassOffering::class)->batch($actor, $items, $term->id);
            $countsByCourse = [];
            foreach (Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])->whereNotNull('confirmed_at')->with(['courseRevision', 'cohorts'])->get() as $class) {
                foreach ($class->cohorts as $cohort) {
                    $key = $class->courseRevision->course_id.':'.$cohort->id;
                    $countsByCourse[$key] = ($countsByCourse[$key] ?? 0) + (int) $cohort->pivot->expected_count;
                    if ($countsByCourse[$key] > (int) $cohort->confirmed_count) {
                        throw ValidationException::withMessages(['classes' => 'Shared and split classes cannot count the same student-section learners twice for one course. Reconcile the counts before confirmation.']);
                    }
                }
            }

            return ['confirmed' => count($items), 'cancelled' => $cancelled];
        }, attempts: 5);
    }
}
