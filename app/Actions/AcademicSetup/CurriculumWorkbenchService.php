<?php

namespace App\Actions\AcademicSetup;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CurriculumWorkbenchService
{
    public function __construct(
        private readonly AcademicReadinessService $readiness,
    ) {}

    /**
     * Return the upper bound for positive whole-number year levels in this curriculum's program.
     */
    public function yearLevelLimit(CurriculumVersion $curriculumVersion): int
    {
        $curriculumVersion->loadMissing('program');
        $programYears = (int) ($curriculumVersion->program?->duration_years ?: 4);
        $maxExistingYear = (int) $curriculumVersion->entries()->max('year_level');

        return max(1, $programYears, $maxExistingYear);
    }

    /**
     * Update an editable draft curriculum's name, first intake, and optional program length together.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePlanSettings(
        User $actor,
        CurriculumVersion $curriculumVersion,
        array $data,
    ): CurriculumVersion {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'effective_entry_term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'duration_years' => ['nullable', 'integer', 'min:1', 'max:8'],
        ])->validate();

        return DB::transaction(function () use ($actor, $curriculumVersion, $validated): CurriculumVersion {
            $lockedCurriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail($curriculumVersion->id);
            Gate::forUser($actor)->authorize('update', $lockedCurriculum);

            if (filled($validated['duration_years'] ?? null)) {
                $targetYears = (int) $validated['duration_years'];
                $program = Program::query()->lockForUpdate()->findOrFail($lockedCurriculum->program_id);

                if ((int) $program->duration_years !== $targetYears) {
                    $this->applyProgramDurationYears($actor, $lockedCurriculum, $program, $targetYears);
                }
            }

            $lockedCurriculum->update([
                'name' => trim((string) $validated['name']),
                'effective_entry_term_id' => filled($validated['effective_entry_term_id'] ?? null)
                    ? (int) $validated['effective_entry_term_id']
                    : null,
            ]);

            return $lockedCurriculum->fresh(['program', 'effectiveEntryTerm.academicYear']);
        });
    }

    /**
     * Adjust the program's length in years from an editable curriculum study plan.
     */
    public function setProgramDurationYears(
        User $actor,
        CurriculumVersion $curriculumVersion,
        int $years,
    ): Program {
        Validator::make(['duration_years' => $years], [
            'duration_years' => ['required', 'integer', 'min:1', 'max:8'],
        ])->validate();

        return DB::transaction(function () use ($actor, $curriculumVersion, $years): Program {
            $lockedCurriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail($curriculumVersion->id);
            Gate::forUser($actor)->authorize('update', $lockedCurriculum);
            $program = Program::query()->lockForUpdate()->findOrFail($lockedCurriculum->program_id);

            return $this->applyProgramDurationYears($actor, $lockedCurriculum, $program, $years);
        });
    }

    private function applyProgramDurationYears(
        User $actor,
        CurriculumVersion $lockedCurriculum,
        Program $program,
        int $years,
    ): Program {
        Gate::forUser($actor)->authorize('update', $program);

        $maxPlacedYear = (int) $lockedCurriculum->entries()->max('year_level');

        if ($maxPlacedYear > $years) {
            throw ValidationException::withMessages([
                'duration_years' => "Year {$maxPlacedYear} still has subjects in this study plan. Move or remove them before shortening the program.",
            ]);
        }

        $previousYears = $program->duration_years;
        $program->update(['duration_years' => $years]);

        activity()
            ->performedOn($program)
            ->causedBy($actor)
            ->event('program_duration_updated')
            ->withProperties([
                'curriculum_version_id' => $lockedCurriculum->id,
                'previous_duration_years' => $previousYears,
                'duration_years' => $years,
            ])
            ->log('Program length updated from curriculum plan');

        return $program->fresh();
    }

    /**
     * Project a CurriculumVersion summary and external competency requirements.
     *
     * @return array{
     *     curriculum_version_id: int,
     *     is_editable: bool,
     *     is_historically_used: bool,
     *     external_competencies: list<array<string, mixed>>,
     *     summary: array{
     *         total_entries: int,
     *         total_units: string,
     *         ready_entries: int,
     *         blocked_entries: int,
     *         externally_arranged_count: int,
     *         external_competency_count: int,
     *         is_editable: bool,
     *         is_historically_used: bool
     *     }
     * }
     */
    public function groupedSheet(CurriculumVersion $curriculumVersion): array
    {
        $curriculumVersion->loadMissing([
            'program',
            'entries.courseSpecification.components',
            'externalCompetencyRequirements.curriculumEntry.courseSpecification.course',
        ]);

        $totalUnits = 0.0;
        $readyEntries = 0;
        $blockedEntries = 0;
        $externallyArrangedCount = 0;

        foreach ($curriculumVersion->entries as $entry) {
            $spec = $entry->courseSpecification;
            $totalUnits += (float) ($spec?->credit_units ?? 0);

            $entryReadiness = $this->readiness->entryReadiness($entry);
            if ($entryReadiness['status'] === 'Specification ready') {
                $readyEntries++;
            } else {
                $blockedEntries++;
            }

            if ($spec?->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
                $externallyArrangedCount++;
            }
        }

        $externalCompetencies = $curriculumVersion->externalCompetencyRequirements
            ->sortBy('id')
            ->map(fn (ExternalCompetencyRequirement $requirement): array => [
                'id' => (int) $requirement->id,
                'requirement_code' => (string) $requirement->requirement_code,
                'qualification_label' => (string) $requirement->qualification_label,
                'qualification_level' => (string) $requirement->qualification_level,
                'treatment' => (string) $requirement->treatment,
                'treatment_label' => $requirement->treatment === ExternalCompetencyRequirement::TreatmentCompletionRequired
                    ? 'CompletionRequired'
                    : 'TrackedOnly',
                'state' => (string) $requirement->state,
                'curriculum_entry_id' => $requirement->curriculum_entry_id,
                'linked_course_code' => $requirement->curriculumEntry?->courseSpecification?->course?->code,
            ])
            ->values()
            ->all();

        $isHistoricallyUsed = $curriculumVersion->isHistoricallyUsed();
        $isEditable = $curriculumVersion->isEditableDraft();

        return [
            'curriculum_version_id' => (int) $curriculumVersion->id,
            'is_editable' => $isEditable,
            'is_historically_used' => $isHistoricallyUsed,
            'external_competencies' => $externalCompetencies,
            'summary' => [
                'total_entries' => $curriculumVersion->entries->count(),
                'total_units' => number_format($totalUnits, 2, '.', ''),
                'ready_entries' => $readyEntries,
                'blocked_entries' => $blockedEntries,
                'externally_arranged_count' => $externallyArrangedCount,
                'external_competency_count' => count($externalCompetencies),
                'is_editable' => $isEditable,
                'is_historically_used' => $isHistoricallyUsed,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEntry(
        User $actor,
        CurriculumVersion $curriculumVersion,
        array $data,
    ): CurriculumEntry {
        return DB::transaction(function () use ($actor, $curriculumVersion, $data): CurriculumEntry {
            $lockedCurriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail($curriculumVersion->id);
            Gate::forUser($actor)->authorize('update', $lockedCurriculum);
            $placement = $data;
            $placement['term_label'] ??= Term::typeOptions()[$data['term_type'] ?? ''] ?? '';
            $placement['sequence'] ??= 1 + (int) $lockedCurriculum->entries()
                ->where('year_level', (string) ($data['year_level'] ?? ''))
                ->where('term_type', $data['term_type'] ?? '')->max('sequence');
            $placement['requirement_group'] ??= CurriculumEntry::RequirementGroupRequired;
            $attributes = $this->validatedPlacement($placement, $lockedCurriculum, requireSpecification: true);

            $specification = CourseSpecification::query()
                ->lockForUpdate()
                ->findOrFail($attributes['course_specification_id']);

            $this->ensureUniquePlacement(
                curriculumVersion: $lockedCurriculum,
                courseSpecification: $specification,
                yearLevel: $attributes['year_level'],
                termLabel: $attributes['term_label'],
            );

            $entry = $lockedCurriculum->entries()->create($attributes);

            activity()
                ->performedOn($entry)
                ->causedBy($actor)
                ->event('curriculum_workbench_row_created')
                ->withProperties([
                    'curriculum_version_id' => $lockedCurriculum->id,
                    'course_specification_id' => $specification->id,
                    'year_level' => $entry->year_level,
                    'term_label' => $entry->term_label,
                    'sequence' => $entry->sequence,
                ])
                ->log('Curriculum row added');

            return $entry->fresh(['courseSpecification.course']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createDraftSubject(User $actor, array $data): CourseSpecification
    {
        Gate::forUser($actor)->authorize('create', Course::class);
        Gate::forUser($actor)->authorize('create', CourseSpecification::class);

        return DB::transaction(function () use ($actor, $data): CourseSpecification {
            $identity = Validator::make([
                ...$data, 'code' => strtoupper(trim((string) ($data['code'] ?? ''))),
            ], [
                'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('courses', 'code')],
                'academic_classification' => ['required', Rule::in(array_keys(CourseSpecification::academicClassificationOptions()))],
                'prerequisite_ids' => ['array'], 'prerequisite_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
                'corequisite_ids' => ['array'], 'corequisite_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
                'equivalent_ids' => ['array'], 'equivalent_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
            ])->validate();
            $definition = $this->validatedSpecification($data);
            $components = $definition['components'];
            unset($definition['components']);
            $course = Course::query()->create(['code' => $identity['code'], 'state' => Course::StateActive]);
            $subject = $course->specifications()->create([
                ...$definition, 'revision_code' => 'DRAFT-'.Str::ulid(),
                'academic_classification' => $identity['academic_classification'],
                'state' => CourseSpecification::StateDraft,
            ]);
            foreach ($components as $component) {
                $subject->components()->create($component);
            }
            foreach ([
                'prerequisite_ids' => CourseRequirement::TypePrerequisite,
                'corequisite_ids' => CourseRequirement::TypeCorequisite,
                'equivalent_ids' => CourseRequirement::TypeEquivalency,
            ] as $field => $type) {
                foreach ($identity[$field] ?? [] as $index => $relatedId) {
                    $subject->requirements()->create([
                        'rule_type' => $type, 'group_key' => $type.'-'.$relatedId,
                        'related_course_id' => $relatedId,
                        'state' => CourseRequirement::StateActive, 'sequence' => $index + 1,
                    ]);
                }
            }
            activity()->performedOn($subject)->causedBy($actor)->event('course_specification_draft_created')->log('Draft subject prepared');

            return $subject;
        });
    }

    /**
     * Create a new draft subject and optionally place it into chosen active programs' editable draft curricula.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     specification: CourseSpecification,
     *     course: Course,
     *     added_curricula: int,
     *     skipped_programs: list<string>
     * }
     */
    public function createDraftSubjectAndAssignToCurricula(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $subject = $this->createDraftSubject($actor, $data);
            $placementResult = $this->assignSpecificationToCurricula($actor, $subject, $data);

            return [
                'specification' => $subject,
                'course' => $subject->course,
                'added_curricula' => $placementResult['added_curricula'],
                'skipped_programs' => $placementResult['skipped_programs'],
            ];
        });
    }

    /**
     * Place an existing subject specification into active programs' editable draft curricula in one operation.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     added_curricula: int,
     *     skipped_programs: list<string>
     * }
     */
    public function assignSpecificationToCurricula(User $actor, CourseSpecification $subject, array $data): array
    {
        return DB::transaction(function () use ($actor, $subject, $data): array {
            $applyTo = (string) ($data['apply_to'] ?? 'none');

            if ($applyTo === '' || $applyTo === 'none') {
                return [
                    'added_curricula' => 0,
                    'skipped_programs' => [],
                ];
            }

            $placements = $applyTo === 'custom'
                ? $this->customSubjectPlacements($data)
                : $this->uniformSubjectPlacements($applyTo, $data);

            $addedCurricula = 0;
            $skippedPrograms = [];

            foreach ($placements as $placement) {
                $program = $placement['program'];
                $targetYear = $placement['year_level'];
                $targetTermType = $placement['term_type'];

                $draft = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->where('state', CurriculumVersion::StateDraft)
                    ->orderByDesc('id')
                    ->get()
                    ->first(fn (CurriculumVersion $version): bool => $version->isEditableDraft());

                if (! $draft instanceof CurriculumVersion) {
                    $skippedPrograms[] = "{$program->code} (no editable draft curriculum)";

                    continue;
                }

                $yearLimit = $this->yearLevelLimit($draft);

                if ($targetYear > $yearLimit) {
                    $skippedPrograms[] = "{$program->code} (program length is {$yearLimit} ".Str::plural('year', $yearLimit).')';

                    continue;
                }

                $alreadyPlaced = CurriculumEntry::query()
                    ->where('curriculum_version_id', $draft->id)
                    ->whereHas('courseSpecification', fn ($query) => $query->where('course_id', $subject->course_id))
                    ->exists();

                if ($alreadyPlaced) {
                    continue;
                }

                $this->createEntry($actor, $draft, [
                    'course_specification_id' => $subject->id,
                    'year_level' => $targetYear,
                    'term_type' => $targetTermType,
                    'requirement_group' => CurriculumEntry::RequirementGroupRequired,
                ]);

                $addedCurricula++;
            }

            return [
                'added_curricula' => $addedCurricula,
                'skipped_programs' => $skippedPrograms,
            ];
        });
    }

    /**
     * Same year and term for every active program or for the selected programs.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{program: Program, year_level: int, term_type: string}>
     */
    private function uniformSubjectPlacements(string $applyTo, array $data): array
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make([
            'apply_to' => $applyTo,
            'program_ids' => $data['program_ids'] ?? [],
            'year_level' => $data['placement_year_level'] ?? ($data['year_level'] ?? null),
            'term_type' => $data['placement_term_type'] ?? ($data['term_type'] ?? null),
        ], [
            'apply_to' => ['required', Rule::in(['all_active', 'selected'])],
            'program_ids' => [Rule::requiredIf($applyTo === 'selected'), 'array'],
            'program_ids.*' => ['integer', 'distinct', 'exists:programs,id'],
            'year_level' => ['required', 'integer', 'min:1', 'max:8'],
            'term_type' => ['required', Rule::in(array_keys(Term::typeOptions()))],
        ])->validate();

        $programs = $applyTo === 'all_active'
            ? Program::query()->where('is_active', true)->orderBy('code')->get()
            : Program::query()->whereIn('id', $validated['program_ids'])->orderBy('code')->get();

        return $programs
            ->map(fn (Program $program): array => [
                'program' => $program,
                'year_level' => (int) $validated['year_level'],
                'term_type' => (string) $validated['term_type'],
            ])
            ->values()
            ->all();
    }

    /**
     * One placement row per program, each with its own year and term.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{program: Program, year_level: int, term_type: string}>
     */
    private function customSubjectPlacements(array $data): array
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make([
            'placements' => array_values((array) ($data['placements'] ?? [])),
        ], [
            'placements' => ['required', 'array', 'min:1'],
            'placements.*.program_id' => ['required', 'integer', 'distinct', 'exists:programs,id'],
            'placements.*.year_level' => ['required', 'integer', 'min:1', 'max:8'],
            'placements.*.term_type' => ['required', Rule::in(array_keys(Term::typeOptions()))],
        ])->validate();

        $programs = Program::query()
            ->whereIn('id', array_column($validated['placements'], 'program_id'))
            ->get()
            ->keyBy('id');

        return collect($validated['placements'])
            ->map(fn (array $row): array => [
                'program' => $programs->get((int) $row['program_id']),
                'year_level' => (int) $row['year_level'],
                'term_type' => (string) $row['term_type'],
            ])
            ->filter(fn (array $row): bool => $row['program'] instanceof Program)
            ->sortBy(fn (array $row): string => $row['program']->code)
            ->values()
            ->all();
    }

    /**
     * Prepare a new subject and its placement as one guarded Draft operation.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSubjectEntry(User $actor, CurriculumVersion $curriculumVersion, array $data): CurriculumEntry
    {
        return DB::transaction(function () use ($actor, $curriculumVersion, $data): CurriculumEntry {
            $curriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail($curriculumVersion->id);
            Gate::forUser($actor)->authorize('update', $curriculum);
            $subject = $this->createDraftSubject($actor, $data);
            $entry = $this->createEntry($actor, $curriculum, [...$data, 'course_specification_id' => $subject->id]);
            activity()->performedOn($subject)->causedBy($actor)->event('curriculum_subject_created')
                ->withProperties(['curriculum_version_id' => $curriculum->id, 'curriculum_entry_id' => $entry->id])
                ->log('Draft subject prepared in its curriculum');

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePlacement(
        User $actor,
        CurriculumEntry $entry,
        array $data,
    ): CurriculumEntry {
        $entry->loadMissing('curriculumVersion.program');
        $attributes = $this->validatedPlacement($data, $entry->curriculumVersion);

        return DB::transaction(function () use ($actor, $entry, $attributes): CurriculumEntry {
            $lockedEntry = CurriculumEntry::query()
                ->with(['curriculumVersion', 'courseSpecification'])
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $this->ensureUniquePlacement(
                curriculumVersion: $lockedEntry->curriculumVersion,
                courseSpecification: $lockedEntry->courseSpecification,
                yearLevel: $attributes['year_level'],
                termLabel: $attributes['term_label'],
                ignoreEntry: $lockedEntry,
            );

            $lockedEntry->fill($attributes)->save();

            activity()
                ->performedOn($lockedEntry)
                ->causedBy($actor)
                ->event('curriculum_workbench_placement_updated')
                ->withProperties([
                    'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                    'course_specification_id' => $lockedEntry->course_specification_id,
                    'year_level' => $lockedEntry->year_level,
                    'term_label' => $lockedEntry->term_label,
                    'sequence' => $lockedEntry->sequence,
                    'requirement_group' => $lockedEntry->requirement_group,
                ])
                ->log('Curriculum placement updated');

            return $lockedEntry->fresh(['courseSpecification.course']);
        });
    }

    /**
     * Remove a CurriculumEntry from an editable Draft CurriculumVersion.
     */
    public function removeEntry(User $actor, CurriculumEntry $entry): void
    {
        DB::transaction(function () use ($actor, $entry): void {
            $lockedEntry = CurriculumEntry::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            ExternalCompetencyRequirement::query()
                ->where('curriculum_entry_id', $lockedEntry->id)
                ->update(['curriculum_entry_id' => null]);

            $lockedEntry->delete();

            activity()
                ->performedOn($lockedEntry->curriculumVersion)
                ->causedBy($actor)
                ->event('curriculum_workbench_row_removed')
                ->withProperties([
                    'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                    'curriculum_entry_id' => $lockedEntry->id,
                ])
                ->log('Curriculum row removed from Draft');
        });
    }

    /**
     * Reorder a subject one step up or down within its Year and Semester.
     */
    public function moveEntrySequence(User $actor, CurriculumEntry $entry, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        DB::transaction(function () use ($actor, $entry, $direction): void {
            $lockedEntry = CurriculumEntry::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $siblings = CurriculumEntry::query()
                ->where('curriculum_version_id', $lockedEntry->curriculum_version_id)
                ->where('year_level', $lockedEntry->year_level)
                ->where('term_type', $lockedEntry->term_type)
                ->orderBy('sequence')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $currentIndex = $siblings->search(fn (CurriculumEntry $item): bool => $item->id === $lockedEntry->id);

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;

            if ($targetIndex < 0 || $targetIndex >= $siblings->count()) {
                return;
            }

            $ordered = $siblings->all();
            [$ordered[$currentIndex], $ordered[$targetIndex]] = [$ordered[$targetIndex], $ordered[$currentIndex]];

            foreach ($ordered as $position => $item) {
                $newSequence = $position + 1;

                if ($item->sequence !== $newSequence) {
                    $item->update(['sequence' => $newSequence]);
                }
            }
        });
    }

    /**
     * Reorder a subject to a zero-based index within its Year and Semester.
     */
    public function reorderEntrySequence(User $actor, CurriculumEntry $entry, int $position): void
    {
        DB::transaction(function () use ($actor, $entry, $position): void {
            $lockedEntry = CurriculumEntry::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $siblings = CurriculumEntry::query()
                ->where('curriculum_version_id', $lockedEntry->curriculum_version_id)
                ->where('year_level', $lockedEntry->year_level)
                ->where('term_type', $lockedEntry->term_type)
                ->orderBy('sequence')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $currentIndex = $siblings->search(fn (CurriculumEntry $item): bool => $item->id === $lockedEntry->id);

            if ($currentIndex === false || $siblings->count() <= 1) {
                return;
            }

            $targetIndex = max(0, min($siblings->count() - 1, $position));

            if ($targetIndex === $currentIndex) {
                return;
            }

            $ordered = $siblings->all();
            $moved = array_splice($ordered, $currentIndex, 1)[0];
            array_splice($ordered, $targetIndex, 0, [$moved]);

            foreach ($ordered as $index => $item) {
                $newSequence = $index + 1;

                if ($item->sequence !== $newSequence) {
                    $item->update(['sequence' => $newSequence]);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addExternalCompetencyRequirement(
        User $actor,
        CurriculumVersion $curriculumVersion,
        array $data,
    ): ExternalCompetencyRequirement {
        $attributes = $this->validatedExternalCompetency($data, $curriculumVersion);

        return DB::transaction(function () use ($actor, $curriculumVersion, $attributes): ExternalCompetencyRequirement {
            $lockedCurriculum = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            Gate::forUser($actor)->authorize('update', $lockedCurriculum);

            if ($lockedCurriculum->externalCompetencyRequirements()
                ->where('requirement_code', $attributes['requirement_code'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'requirement_code' => 'That requirement code already exists in this curriculum version.',
                ]);
            }

            /** @var ExternalCompetencyRequirement $requirement */
            $requirement = $lockedCurriculum->externalCompetencyRequirements()->create([
                ...$attributes,
                'state' => 'Draft',
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            activity()
                ->performedOn($requirement)
                ->causedBy($actor)
                ->event('external_competency_requirement_added')
                ->withProperties([
                    'curriculum_version_id' => $lockedCurriculum->id,
                    'requirement_code' => $requirement->requirement_code,
                    'treatment' => $requirement->treatment,
                ])
                ->log('External competency requirement added to Draft curriculum');

            return $requirement->fresh(['curriculumEntry.courseSpecification.course']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateExternalCompetencyRequirement(
        User $actor,
        ExternalCompetencyRequirement $requirement,
        array $data,
    ): ExternalCompetencyRequirement {
        $requirement->loadMissing('curriculumVersion');
        $attributes = $this->validatedExternalCompetency($data, $requirement->curriculumVersion);

        return DB::transaction(function () use ($actor, $requirement, $attributes): ExternalCompetencyRequirement {
            $lockedRequirement = ExternalCompetencyRequirement::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($requirement->id);

            Gate::forUser($actor)->authorize('update', $lockedRequirement->curriculumVersion);

            if ($lockedRequirement->curriculumVersion->externalCompetencyRequirements()
                ->where('requirement_code', $attributes['requirement_code'])
                ->whereKeyNot($lockedRequirement->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'requirement_code' => 'That requirement code already exists in this curriculum version.',
                ]);
            }

            $lockedRequirement->fill([
                ...$attributes,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ])->save();

            return $lockedRequirement->fresh(['curriculumEntry.courseSpecification.course']);
        });
    }

    public function removeExternalCompetencyRequirement(User $actor, ExternalCompetencyRequirement $requirement): void
    {
        DB::transaction(function () use ($actor, $requirement): void {
            $lockedRequirement = ExternalCompetencyRequirement::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($requirement->id);

            Gate::forUser($actor)->authorize('update', $lockedRequirement->curriculumVersion);

            $lockedRequirement->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSpecification(
        User $actor,
        CurriculumEntry $entry,
        array $data,
    ): CourseSpecification {
        $attributes = $this->validatedSpecification($data);
        $components = $attributes['components'];
        unset($attributes['components']);

        return DB::transaction(function () use ($actor, $entry, $attributes, $components): CourseSpecification {
            $lockedEntry = CurriculumEntry::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $specification = CourseSpecification::query()
                ->lockForUpdate()
                ->findOrFail($lockedEntry->course_specification_id);

            Gate::forUser($actor)->authorize('update', $specification);

            $specification->fill($attributes)->save();
            $specification->components()->delete();

            foreach ($components as $component) {
                $specification->components()->create($component);
            }

            activity()
                ->performedOn($specification)
                ->causedBy($actor)
                ->event('curriculum_workbench_specification_updated')
                ->withProperties([
                    'curriculum_entry_id' => $lockedEntry->id,
                    'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                    'component_count' => count($components),
                    'allowed_modalities' => $specification->allowed_modalities,
                ])
                ->log('Course Specification updated');

            return $specification->fresh(['course', 'components', 'requirements']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     course_specification_id?: int,
     *     year_level: string,
     *     term_label: string,
     *     term_type: string,
     *     sequence: int,
     *     requirement_group: string
     * }
     */
    private function validatedPlacement(array $data, CurriculumVersion $curriculumVersion, bool $requireSpecification = false): array
    {
        $maxYear = $this->yearLevelLimit($curriculumVersion);

        $rules = [
            'year_level' => ['required', 'integer', 'min:1', 'max:'.$maxYear],
            'term_label' => ['required', 'string', 'max:255'],
            'term_type' => ['required', Rule::in(array_keys(Term::typeOptions()))],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
            'requirement_group' => ['required', Rule::in(array_keys(CurriculumEntry::requirementGroupOptions()))],
        ];

        if ($requireSpecification) {
            $rules['course_specification_id'] = ['required', 'integer', 'exists:course_specifications,id'];
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, $rules)->validate();

        $attributes = [
            'year_level' => (string) $validated['year_level'],
            'term_label' => trim((string) $validated['term_label']),
            'term_type' => (string) $validated['term_type'],
            'sequence' => (int) $validated['sequence'],
            'requirement_group' => (string) $validated['requirement_group'],
        ];

        if ($requireSpecification) {
            $attributes['course_specification_id'] = (int) $validated['course_specification_id'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedExternalCompetency(array $data, CurriculumVersion $curriculumVersion): array
    {
        $generatedCode = filled($data['requirement_code'] ?? null)
            ? strtoupper(trim((string) $data['requirement_code']))
            : 'EXT-'.strtoupper(Str::substr((string) Str::ulid(), -6));

        /** @var array<string, mixed> $validated */
        $validated = Validator::make([
            ...$data,
            'requirement_code' => $generatedCode,
        ], [
            'curriculum_entry_id' => [
                'nullable',
                'integer',
                Rule::exists('curriculum_entries', 'id')->where('curriculum_version_id', $curriculumVersion->id),
            ],
            'requirement_code' => ['required', 'string', 'max:64'],
            'qualification_label' => ['required', 'string', 'max:255'],
            'qualification_level' => ['nullable', 'string', 'max:128'],
            'treatment' => ['required', Rule::in([
                ExternalCompetencyRequirement::TreatmentTrackedOnly,
                ExternalCompetencyRequirement::TreatmentCompletionRequired,
            ])],
        ])->validate();

        return [
            'curriculum_entry_id' => filled($validated['curriculum_entry_id'] ?? null)
                ? (int) $validated['curriculum_entry_id']
                : null,
            'requirement_code' => strtoupper(trim((string) $validated['requirement_code'])),
            'qualification_label' => trim((string) $validated['qualification_label']),
            'qualification_level' => filled($validated['qualification_level'] ?? null) ? trim((string) $validated['qualification_level']) : null,
            'treatment' => (string) $validated['treatment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     title: string,
     *     credit_units: float|int|string,
     *     grading_profile_key: string,
     *     grading_profile_version: int,
     *     scheduling_treatment: string,
     *     allowed_modalities: list<string>,
     *     same_faculty_default: bool,
     *     components: list<array{
     *         component_type: string,
     *         weekly_contact_hours: float|int|string,
     *         meeting_pattern: string,
     *         room_type_default: string|null,
     *         required_room_feature_keys: list<string>,
     *         modality_restriction: string|null,
     *         requires_consecutive_block: bool,
     *         same_faculty: bool,
     *         sequence: int
     *     }>
     * }
     */
    private function validatedSpecification(array $data): array
    {
        $sameFacultyDefault = (bool) ($data['same_faculty_default'] ?? true);
        $normalizedComponents = isset($data['components']) && is_array($data['components'])
            ? collect($data['components'])
                ->values()
                ->map(function (mixed $component, int $index) use ($sameFacultyDefault): mixed {
                    if (! is_array($component)) {
                        return $component;
                    }

                    $pattern = (string) ($component['meeting_pattern'] ?? '');
                    $parsed = CourseComponent::parseMeetingPattern($pattern);
                    $derivedHours = $parsed !== null
                        ? round(($parsed['count'] * $parsed['duration_minutes']) / 60, 2)
                        : null;

                    return [
                        ...$component,
                        'weekly_contact_hours' => filled($component['weekly_contact_hours'] ?? null)
                            ? $component['weekly_contact_hours']
                            : $derivedHours,
                        'requires_consecutive_block' => (bool) ($component['requires_consecutive_block'] ?? false),
                        'same_faculty' => (bool) ($component['same_faculty'] ?? $sameFacultyDefault),
                        'sequence' => (int) ($component['sequence'] ?? ($index + 1)),
                    ];
                })
                ->all()
            : ($data['components'] ?? null);

        $payload = [
            ...$data,
            'same_faculty_default' => $sameFacultyDefault,
            'grading_profile_key' => filled($data['grading_profile_key'] ?? null)
                ? $data['grading_profile_key']
                : CourseSpecification::GradingProfileServitechV1,
            'grading_profile_version' => $data['grading_profile_version'] ?? 1,
        ];

        if ($normalizedComponents !== null) {
            $payload['components'] = $normalizedComponents;
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'],
            'credit_units' => ['required', 'numeric', 'gt:0'],
            'grading_profile_key' => ['required', Rule::in(array_keys(CourseSpecification::gradingProfileOptions()))],
            'grading_profile_version' => ['required', 'integer', 'min:1'],
            'scheduling_treatment' => ['required', Rule::in(array_keys(CourseSpecification::schedulingTreatmentOptions()))],
            'allowed_modalities' => ['required', 'array', 'min:1'],
            'allowed_modalities.*' => ['required', Rule::in(array_keys(CourseSpecification::modalityOptions()))],
            'same_faculty_default' => ['required', 'boolean'],
            'components' => [
                'array',
                Rule::requiredIf(($data['scheduling_treatment'] ?? null) === CourseSpecification::SchedulingRecurring),
                Rule::prohibitedIf(($data['scheduling_treatment'] ?? null) === CourseSpecification::SchedulingExternallyArranged),
            ],
            'components.*.component_type' => ['required', Rule::in(array_keys(CourseComponent::typeOptions()))],
            'components.*.weekly_contact_hours' => ['required', 'numeric', 'gt:0'],
            'components.*.meeting_pattern' => ['required', Rule::in(array_keys(CourseComponent::meetingPatternOptions()))],
            'components.*.room_type_default' => ['nullable', Rule::in(array_keys(CourseComponent::roomTypeOptions()))],
            'components.*.required_room_feature_keys' => ['nullable', 'array'],
            'components.*.required_room_feature_keys.*' => ['string', 'max:255'],
            'components.*.modality_restriction' => ['nullable', Rule::in(array_keys(CourseSpecification::modalityOptions()))],
            'components.*.requires_consecutive_block' => ['required', 'boolean'],
            'components.*.same_faculty' => ['required', 'boolean'],
            'components.*.sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ])->validate();

        return [
            'title' => trim((string) $validated['title']),
            'credit_units' => $validated['credit_units'],
            'grading_profile_key' => (string) $validated['grading_profile_key'],
            'grading_profile_version' => (int) $validated['grading_profile_version'],
            'scheduling_treatment' => (string) $validated['scheduling_treatment'],
            'allowed_modalities' => array_values($validated['allowed_modalities']),
            'same_faculty_default' => (bool) $validated['same_faculty_default'],
            'components' => collect($validated['components'] ?? [])
                ->map(fn (array $component): array => [
                    'component_type' => (string) $component['component_type'],
                    'weekly_contact_hours' => $component['weekly_contact_hours'],
                    'meeting_pattern' => (string) $component['meeting_pattern'],
                    'room_type_default' => filled($component['room_type_default'] ?? null)
                        ? (string) $component['room_type_default']
                        : null,
                    'required_room_feature_keys' => array_values($component['required_room_feature_keys'] ?? []),
                    'modality_restriction' => filled($component['modality_restriction'] ?? null)
                        ? (string) $component['modality_restriction']
                        : null,
                    'requires_consecutive_block' => (bool) $component['requires_consecutive_block'],
                    'same_faculty' => (bool) $component['same_faculty'],
                    'sequence' => (int) $component['sequence'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function ensureUniquePlacement(
        CurriculumVersion $curriculumVersion,
        CourseSpecification $courseSpecification,
        string $yearLevel,
        string $termLabel,
        ?CurriculumEntry $ignoreEntry = null,
    ): void {
        $duplicate = CurriculumEntry::query()
            ->whereBelongsTo($curriculumVersion)
            ->whereBelongsTo($courseSpecification)
            ->where('year_level', $yearLevel)
            ->where('term_label', $termLabel)
            ->when(
                $ignoreEntry instanceof CurriculumEntry,
                fn ($query) => $query->whereKeyNot($ignoreEntry->id),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'term_label' => 'This Course Specification already exists in that year level and term.',
            ]);
        }
    }
}
