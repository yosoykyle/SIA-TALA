<?php

namespace App\Actions\AcademicSetup;

use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CurriculumVersionLifecycleService
{
    public function __construct(
        private readonly CourseSpecificationLifecycleService $courseSpecifications,
    ) {}

    /**
     * Create a new blank Draft CurriculumVersion, optionally assigned to an initial starting batch.
     */
    public function createDraft(
        User $actor,
        Program $program,
        string $versionCode,
        ?string $name = null,
        ?int $effectiveEntryTermId = null,
        ?AcademicYear $startingBatch = null,
        bool $activateProgramIfInactive = false,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('create', CurriculumVersion::class);

        $normalizedCode = trim($versionCode);

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'version_code' => 'Enter a curriculum version code for the new Draft.',
            ]);
        }

        $normalizedName = $name !== null ? trim($name) : '';

        return DB::transaction(function () use (
            $actor,
            $program,
            $normalizedCode,
            $normalizedName,
            $effectiveEntryTermId,
            $startingBatch,
            $activateProgramIfInactive,
        ): CurriculumVersion {
            $lockedProgram = Program::query()->lockForUpdate()->findOrFail($program->id);

            $this->ensureProgramActiveForBatchCurriculum($actor, $lockedProgram, $startingBatch, $activateProgramIfInactive);

            if (CurriculumVersion::query()
                ->where('program_id', $lockedProgram->id)
                ->where('version_code', $normalizedCode)
                ->exists()) {
                throw ValidationException::withMessages([
                    'version_code' => 'That curriculum version code already exists for this program.',
                ]);
            }

            if ($startingBatch instanceof AcademicYear) {
                $this->ensureBatchUnassignedForProgram($lockedProgram, $startingBatch);
            }

            $resolvedName = $normalizedName !== ''
                ? $normalizedName
                : ($startingBatch instanceof AcademicYear
                    ? 'Batch '.CurriculumVersion::formatBatchShort($startingBatch)
                    : $normalizedCode);

            $draft = CurriculumVersion::query()->create([
                'program_id' => $lockedProgram->id,
                'copied_from_curriculum_version_id' => null,
                'version_code' => $normalizedCode,
                'name' => $resolvedName,
                'effective_entry_term_id' => $effectiveEntryTermId,
                'state' => CurriculumVersion::StateDraft,
                'content_hash' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            if ($startingBatch instanceof AcademicYear) {
                CurriculumVersionBatch::query()->create([
                    'program_id' => $lockedProgram->id,
                    'curriculum_version_id' => $draft->id,
                    'academic_year_id' => $startingBatch->id,
                    'added_by' => $actor->id,
                ]);
            }

            activity()
                ->performedOn($draft)
                ->causedBy($actor)
                ->event('curriculum_version_draft_created')
                ->withProperties([
                    'program_id' => $lockedProgram->id,
                    'version_code' => $draft->version_code,
                    'starting_academic_year_id' => $startingBatch?->id,
                ])
                ->log('Curriculum Version draft created');

            return $draft->fresh(['program', 'batches.academicYear', 'entries', 'externalCompetencyRequirements']);
        });
    }

    /**
     * Copy an Active, Superseded, or Draft CurriculumVersion into a new independent Draft successor.
     */
    public function copyToDraft(
        User $actor,
        CurriculumVersion $source,
        string $versionCode,
        ?string $name = null,
        ?int $effectiveEntryTermId = null,
        ?AcademicYear $startingBatch = null,
        bool $activateProgramIfInactive = false,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('copy', $source);

        $normalizedCode = trim($versionCode);

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'version_code' => 'Enter a curriculum version code for the new Draft.',
            ]);
        }

        $normalizedName = $name !== null ? trim($name) : '';

        return DB::transaction(function () use (
            $actor,
            $source,
            $normalizedCode,
            $normalizedName,
            $effectiveEntryTermId,
            $startingBatch,
            $activateProgramIfInactive,
        ): CurriculumVersion {
            $lockedSource = CurriculumVersion::query()
                ->with(['entries', 'externalCompetencyRequirements'])
                ->lockForUpdate()
                ->findOrFail($source->id);

            $lockedProgram = Program::query()->lockForUpdate()->findOrFail($lockedSource->program_id);
            $this->ensureProgramActiveForBatchCurriculum($actor, $lockedProgram, $startingBatch, $activateProgramIfInactive);

            if (CurriculumVersion::query()
                ->where('program_id', $lockedSource->program_id)
                ->where('version_code', $normalizedCode)
                ->exists()) {
                throw ValidationException::withMessages([
                    'version_code' => 'That curriculum version code already exists for this program.',
                ]);
            }

            if ($startingBatch instanceof AcademicYear) {
                $this->ensureBatchUnassignedForProgram($lockedProgram, $startingBatch);
            }

            $resolvedName = $normalizedName !== ''
                ? $normalizedName
                : ($startingBatch instanceof AcademicYear
                    ? 'Batch '.CurriculumVersion::formatBatchShort($startingBatch)
                    : "{$lockedSource->name} (Draft)");

            $draft = CurriculumVersion::query()->create([
                'program_id' => $lockedSource->program_id,
                'copied_from_curriculum_version_id' => $lockedSource->id,
                'version_code' => $normalizedCode,
                'name' => $resolvedName,
                'effective_entry_term_id' => $effectiveEntryTermId,
                'state' => CurriculumVersion::StateDraft,
                'content_hash' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            if ($startingBatch instanceof AcademicYear) {
                CurriculumVersionBatch::query()->create([
                    'program_id' => $lockedProgram->id,
                    'curriculum_version_id' => $draft->id,
                    'academic_year_id' => $startingBatch->id,
                    'added_by' => $actor->id,
                ]);
            }

            $entryMap = [];

            foreach ($lockedSource->entries as $entry) {
                /** @var CurriculumEntry $copiedEntry */
                $copiedEntry = $draft->entries()->create([
                    'course_specification_id' => $entry->course_specification_id,
                    'year_level' => $entry->year_level,
                    'term_label' => $entry->term_label,
                    'term_type' => $entry->term_type,
                    'sequence' => $entry->sequence,
                    'requirement_group' => $entry->requirement_group,
                ]);
                $entryMap[$entry->id] = $copiedEntry->id;
            }

            foreach ($lockedSource->externalCompetencyRequirements as $requirement) {
                $draft->externalCompetencyRequirements()->create([
                    'curriculum_entry_id' => $requirement->curriculum_entry_id !== null
                        ? ($entryMap[$requirement->curriculum_entry_id] ?? null)
                        : null,
                    'requirement_code' => $requirement->requirement_code,
                    'qualification_label' => $requirement->qualification_label,
                    'qualification_level' => $requirement->qualification_level,
                    'treatment' => $requirement->treatment,
                    'state' => 'Draft',
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            }

            activity()
                ->performedOn($draft)
                ->causedBy($actor)
                ->event('curriculum_version_draft_copied')
                ->withProperties([
                    'source_curriculum_version_id' => $lockedSource->id,
                    'source_version_code' => $lockedSource->version_code,
                    'new_version_code' => $draft->version_code,
                    'starting_academic_year_id' => $startingBatch?->id,
                    'entries_copied' => count($entryMap),
                ])
                ->log('Curriculum Version copied to Draft');

            return $draft->fresh(['program', 'copiedFrom', 'batches.academicYear', 'entries', 'externalCompetencyRequirements']);
        });
    }

    /**
     * Summarize what activating a CurriculumVersion changes and what still blocks it.
     *
     * @return array{
     *     active_version_code:?string,
     *     active_version_name:?string,
     *     entries:int,
     *     existing_student_locks:int,
     *     has_active_term_setup:bool,
     *     draft_specifications_to_activate:list<string>,
     *     readiness_errors:list<string>
     * }
     */
    public function activationImpact(CurriculumVersion $curriculumVersion): array
    {
        $activeVersion = CurriculumVersion::query()
            ->where('program_id', $curriculumVersion->program_id)
            ->where('state', CurriculumVersion::StateActive)
            ->whereKeyNot($curriculumVersion->id)
            ->first(['id', 'version_code', 'name']);

        $hasActiveTermSetup = $activeVersion instanceof CurriculumVersion
            && (TermOffering::query()
                ->where('state', '!=', TermOffering::StateCancelled)
                ->whereHas('term', fn ($query) => $query->where('state', Term::StateActive))
                ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $activeVersion->id))
                ->exists()
                || TermCohort::query()
                    ->where('curriculum_version_id', $activeVersion->id)
                    ->whereHas('term', fn ($query) => $query->where('state', Term::StateActive))
                    ->exists());

        return [
            'active_version_code' => $activeVersion?->version_code,
            'active_version_name' => $activeVersion?->name,
            'entries' => $curriculumVersion->entries()->count(),
            'existing_student_locks' => StudentProfile::query()
                ->where('program_id', $curriculumVersion->program_id)
                ->where('curriculum_version_id', '!=', $curriculumVersion->id)
                ->count(),
            'has_active_term_setup' => $hasActiveTermSetup,
            'draft_specifications_to_activate' => $this->readyDraftSpecificationLabels($curriculumVersion),
            'readiness_errors' => $this->readinessErrors($curriculumVersion),
        ];
    }

    /**
     * Make a complete Draft the Program's active curriculum for new students.
     *
     * Complete draft subjects in the plan become active with it; the previous active
     * curriculum is kept as history, and students already assigned keep their plan.
     */
    public function activate(
        User $actor,
        CurriculumVersion $curriculumVersion,
        ?int $effectiveEntryTermId = null,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('activate', $curriculumVersion);

        return DB::transaction(function () use ($actor, $curriculumVersion, $effectiveEntryTermId): CurriculumVersion {
            Program::query()->lockForUpdate()->findOrFail($curriculumVersion->program_id);
            $locked = CurriculumVersion::query()
                ->with(['entries.courseSpecification.components', 'externalCompetencyRequirements'])
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            if ($locked->state !== CurriculumVersion::StateDraft) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'Only a draft curriculum can be activated.',
                ]);
            }

            if ($effectiveEntryTermId !== null && $effectiveEntryTermId !== $locked->effective_entry_term_id) {
                if (! Term::query()->whereKey($effectiveEntryTermId)->exists()) {
                    throw ValidationException::withMessages([
                        'effective_entry_term_id' => 'Choose an existing student intake.',
                    ]);
                }

                $locked->update(['effective_entry_term_id' => $effectiveEntryTermId]);
            }

            $errors = $this->readinessErrors($locked);

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'curriculum_version' => $errors,
                ]);
            }

            foreach ($locked->entries as $entry) {
                $specification = $entry->courseSpecification;

                if ($specification instanceof CourseSpecification && $specification->state === CourseSpecification::StateDraft) {
                    $this->courseSpecifications->activate($actor, $specification);
                }
            }

            $hasBatches = $locked->batches()->exists();
            $supersededIds = [];

            if (! $hasBatches) {
                $supersededIds = CurriculumVersion::query()
                    ->where('program_id', $locked->program_id)
                    ->where('state', CurriculumVersion::StateActive)
                    ->whereDoesntHave('batches')
                    ->whereKeyNot($locked->id)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if ($supersededIds !== []) {
                    CurriculumVersion::query()
                        ->whereKey($supersededIds)
                        ->update(['state' => CurriculumVersion::StateSuperseded]);
                }
            }

            $locked->externalCompetencyRequirements()->update(['state' => 'ACTIVE']);

            $locked->update([
                'state' => CurriculumVersion::StateActive,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'content_hash' => $locked->content_hash ?: $this->computeContentHash($locked),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_activated')
                ->withProperties([
                    'program_id' => $locked->program_id,
                    'has_batches' => $hasBatches,
                    'superseded_curriculum_version_ids' => $supersededIds,
                    'existing_student_locks_preserved' => StudentProfile::query()
                        ->where('program_id', $locked->program_id)
                        ->where('curriculum_version_id', '!=', $locked->id)
                        ->count(),
                ])
                ->log('Curriculum Version activated');

            return $locked->fresh();
        });
    }

    /**
     * Retire an Active curriculum (ACTIVE -> SUPERSEDED) so no new batches can be added while existing batches continue.
     */
    public function retire(User $actor, CurriculumVersion $curriculum): CurriculumVersion
    {
        Gate::forUser($actor)->authorize('copy', $curriculum);

        return DB::transaction(function () use ($actor, $curriculum): CurriculumVersion {
            $locked = CurriculumVersion::query()
                ->with('batches.academicYear')
                ->lockForUpdate()
                ->findOrFail($curriculum->id);

            if ($locked->state !== CurriculumVersion::StateActive) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'Only an Active curriculum can be retired.',
                ]);
            }

            $locked->update([
                'state' => CurriculumVersion::StateSuperseded,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_version_retired')
                ->withProperties([
                    'program_id' => $locked->program_id,
                    'version_code' => $locked->version_code,
                    'batch_label' => $locked->batchLabel(),
                ])
                ->log('Curriculum Version retired');

            return $locked->fresh(['program', 'batches.academicYear']);
        });
    }

    /**
     * Assign an Academic Year batch to a Draft or Active curriculum without duplicating subjects.
     */
    public function addBatch(
        User $actor,
        CurriculumVersion $curriculum,
        AcademicYear $academicYear,
        bool $activateProgramIfInactive = false,
    ): CurriculumVersionBatch {
        Gate::forUser($actor)->authorize('copy', $curriculum);

        return DB::transaction(function () use ($actor, $curriculum, $academicYear, $activateProgramIfInactive): CurriculumVersionBatch {
            $lockedProgram = Program::query()->lockForUpdate()->findOrFail($curriculum->program_id);
            $locked = CurriculumVersion::query()
                ->with('batches.academicYear')
                ->lockForUpdate()
                ->findOrFail($curriculum->id);

            if (! in_array($locked->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true)) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'Only a Draft or Active curriculum can accept new batches.',
                ]);
            }

            $this->ensureProgramActiveForBatchCurriculum($actor, $lockedProgram, $academicYear, $activateProgramIfInactive);
            $this->ensureBatchUnassignedForProgram($lockedProgram, $academicYear);

            $batch = CurriculumVersionBatch::query()->create([
                'program_id' => $lockedProgram->id,
                'curriculum_version_id' => $locked->id,
                'academic_year_id' => $academicYear->id,
                'added_by' => $actor->id,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_version_batch_added')
                ->withProperties([
                    'program_id' => $lockedProgram->id,
                    'academic_year_id' => $academicYear->id,
                    'academic_year_label' => $academicYear->label,
                    'batch_label' => 'Batch '.CurriculumVersion::formatBatchShort($academicYear),
                ])
                ->log('Batch assigned to Curriculum Version');

            return $batch->fresh(['program', 'curriculumVersion', 'academicYear', 'addedBy']);
        });
    }

    /**
     * Remove an unused Academic Year batch assignment from a curriculum.
     */
    public function removeBatch(User $actor, CurriculumVersion $curriculum, AcademicYear $academicYear): void
    {
        Gate::forUser($actor)->authorize('copy', $curriculum);

        DB::transaction(function () use ($actor, $curriculum, $academicYear): void {
            $locked = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculum->id);

            $batch = CurriculumVersionBatch::query()
                ->where('curriculum_version_id', $locked->id)
                ->where('academic_year_id', $academicYear->id)
                ->lockForUpdate()
                ->first();

            if (! $batch instanceof CurriculumVersionBatch) {
                throw ValidationException::withMessages([
                    'academic_year_id' => 'That batch is not assigned to this curriculum.',
                ]);
            }

            $hasStudents = StudentProfile::query()
                ->where('program_id', $locked->program_id)
                ->whereNull('merged_into_id')
                ->where(function ($query) use ($academicYear): void {
                    $query->where('entry_academic_year_id', $academicYear->id)
                        ->orWhereHas('entryTerm', fn ($termQuery) => $termQuery->where('academic_year_id', $academicYear->id));
                })
                ->exists();

            $hasCohorts = TermCohort::query()
                ->where('program_id', $locked->program_id)
                ->where('curriculum_version_id', $locked->id)
                ->whereHas('term', fn ($query) => $query->where('academic_year_id', $academicYear->id))
                ->exists();

            $hasUnresolvedApplicants = AdmissionApplication::query()
                ->where('program_id', $locked->program_id)
                ->whereNotIn('application_state', [
                    AdmissionApplication::StateWithdrawn,
                    AdmissionApplication::StateNotAdmitted,
                ])
                ->whereHas('term', fn ($query) => $query->where('academic_year_id', $academicYear->id))
                ->exists();

            if ($hasStudents || $hasCohorts || $hasUnresolvedApplicants) {
                $short = CurriculumVersion::formatBatchShort($academicYear);

                throw ValidationException::withMessages([
                    'academic_year_id' => "Batch {$short} cannot be removed because students, student sections, or active applicants already use it.",
                ]);
            }

            $batch->delete();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_version_batch_removed')
                ->withProperties([
                    'program_id' => $locked->program_id,
                    'academic_year_id' => $academicYear->id,
                    'academic_year_label' => $academicYear->label,
                ])
                ->log('Batch removed from Curriculum Version');
        });
    }

    /**
     * Return all non-archived AcademicYears not yet assigned to any curriculum of the given program.
     *
     * @return Collection<int, AcademicYear>
     */
    public function availableAcademicYearsForProgram(Program $program, ?CurriculumVersion $exceptDraft = null): Collection
    {
        $assignedIds = CurriculumVersionBatch::query()
            ->where('program_id', $program->id)
            ->when(
                $exceptDraft instanceof CurriculumVersion && $exceptDraft->state === CurriculumVersion::StateDraft,
                fn ($query) => $query->where('curriculum_version_id', '!=', $exceptDraft->id),
            )
            ->pluck('academic_year_id')
            ->all();

        return AcademicYear::query()
            ->where('state', '!=', AcademicYear::StateArchived)
            ->when($assignedIds !== [], fn ($query) => $query->whereNotIn('id', $assignedIds))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Suggest the next chronological unassigned AcademicYear for the given program.
     */
    public function suggestedNextAcademicYearForProgram(Program $program): ?AcademicYear
    {
        $available = $this->availableAcademicYearsForProgram($program);

        if ($available->isEmpty()) {
            return null;
        }

        $latestAssigned = AcademicYear::query()
            ->whereIn(
                'id',
                CurriculumVersionBatch::query()
                    ->where('program_id', $program->id)
                    ->pluck('academic_year_id'),
            )
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($latestAssigned instanceof AcademicYear && $latestAssigned->starts_on !== null) {
            $nextChronological = $available->first(
                fn (AcademicYear $year): bool => $year->starts_on !== null && $year->starts_on->gt($latestAssigned->starts_on),
            );

            if ($nextChronological instanceof AcademicYear) {
                return $nextChronological;
            }
        }

        return $available->first(
            fn (AcademicYear $year): bool => in_array($year->state, [AcademicYear::StateActive, AcademicYear::StateDraft], true),
        ) ?? $available->first();
    }

    public function canDiscardDraft(CurriculumVersion $curriculum): bool
    {
        if ($curriculum->state !== CurriculumVersion::StateDraft || $curriculum->isHistoricallyUsed()) {
            return false;
        }

        return StudentLifecycleChange::query()->where('target_curriculum_version_id', $curriculum->id)->doesntExist()
            && RegistrationCreditEvaluation::query()->where('curriculum_version_id', $curriculum->id)->doesntExist()
            && ProgramShiftCreditEntry::query()
                ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $curriculum->id))
                ->doesntExist();
    }

    /**
     * Permanently remove an unreferenced Draft curriculum and its draft placements.
     */
    public function discardDraft(CurriculumVersion $curriculum, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $curriculum);

        DB::transaction(function () use ($curriculum, $actor): void {
            $locked = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculum->id);

            Gate::forUser($actor)->authorize('update', $locked);

            if (! $this->canDiscardDraft($locked)) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'This draft curriculum is already referenced by student, cohort, or term records and cannot be discarded.',
                ]);
            }

            $entriesDeleted = $locked->entries()->count();
            $properties = [
                'program_id' => $locked->program_id,
                'version_code' => $locked->version_code,
                'name' => $locked->name,
                'entries_deleted' => $entriesDeleted,
            ];

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_version_draft_discarded')
                ->withProperties($properties)
                ->log('Draft curriculum discarded');

            $candidateDraftSpecIds = $locked->entries()
                ->pluck('course_specification_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $locked->batches()->delete();
            $locked->externalCompetencyRequirements()->delete();
            $locked->entries()->delete();
            $locked->delete();

            if ($candidateDraftSpecIds !== []) {
                $orphanForks = CourseSpecification::query()
                    ->whereIn('id', $candidateDraftSpecIds)
                    ->where('state', CourseSpecification::StateDraft)
                    ->where('revision_code', 'like', 'DRAFT-%')
                    ->lockForUpdate()
                    ->get();

                foreach ($orphanForks as $spec) {
                    $hasOtherSpec = CourseSpecification::query()
                        ->where('course_id', $spec->course_id)
                        ->whereKeyNot($spec->id)
                        ->exists();

                    if (! $hasOtherSpec) {
                        continue;
                    }

                    if ($spec->curriculumEntries()->exists()
                        || Section::query()->where('course_specification_id', $spec->id)->exists()
                        || SchedulingDemand::query()->whereHas('courseComponent', fn ($q) => $q->where('course_specification_id', $spec->id))->exists()) {
                        continue;
                    }

                    $spec->components()->delete();
                    $spec->requirements()->delete();
                    $spec->delete();
                }
            }
        });
    }

    public function readinessErrors(CurriculumVersion $curriculumVersion): array
    {
        $entries = $curriculumVersion->entries()
            ->with(['courseSpecification.course', 'courseSpecification.components'])
            ->get();
        $errors = [];

        if ($entries->isEmpty()) {
            return ['Add at least one subject to this curriculum before activating it.'];
        }

        foreach ($entries as $entry) {
            $specification = $entry->courseSpecification;

            if (! $specification instanceof CourseSpecification) {
                $errors[] = 'One of the curriculum rows is missing its subject details. Remove the row or pick a subject.';

                continue;
            }

            $subjectLabel = $this->subjectDisplayLabel($specification);

            if (! in_array($specification->state, [CourseSpecification::StateActive, CourseSpecification::StateDraft], true)) {
                $errors[] = "{$subjectLabel} is no longer active. Replace it with an active or draft revision.";

                continue;
            }

            $specErrors = $this->courseSpecifications->readinessErrors($specification);

            if ($specErrors !== []) {
                foreach ($specErrors as $error) {
                    $errors[] = "{$subjectLabel}: {$error}";
                }
            }
        }

        foreach ($curriculumVersion->externalCompetencyRequirements()->get() as $requirement) {
            if (blank($requirement->qualification_label)
                || ! in_array($requirement->treatment, [
                    ExternalCompetencyRequirement::TreatmentTrackedOnly,
                    ExternalCompetencyRequirement::TreatmentCompletionRequired,
                ], true)) {
                $errors[] = 'An external qualification is missing its name or its effect on graduation.';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    public function readyDraftSpecificationLabels(CurriculumVersion $curriculumVersion): array
    {
        $entries = $curriculumVersion->entries()
            ->with('courseSpecification.course', 'courseSpecification.components')
            ->get();
        $labels = [];

        foreach ($entries as $entry) {
            $specification = $entry->courseSpecification;

            if (! $specification instanceof CourseSpecification || $specification->state !== CourseSpecification::StateDraft) {
                continue;
            }

            if ($this->courseSpecifications->readinessErrors($specification) === []) {
                $labels[] = $this->subjectDisplayLabel($specification);
            }
        }

        return array_values(array_unique($labels));
    }

    private function subjectDisplayLabel(CourseSpecification $specification): string
    {
        $code = trim((string) ($specification->course?->code ?? ''));
        $title = trim((string) $specification->title);

        if ($code !== '' && $title !== '') {
            return "{$code} · {$title}";
        }

        return $code !== '' ? $code : ($title !== '' ? $title : 'This subject');
    }

    private function computeContentHash(CurriculumVersion $curriculumVersion): string
    {
        $payload = [
            'program_id' => $curriculumVersion->program_id,
            'version_code' => $curriculumVersion->version_code,
            'entries' => $curriculumVersion->entries()
                ->orderBy('year_level')
                ->orderBy('term_type')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get(['course_specification_id', 'year_level', 'term_label', 'term_type', 'sequence', 'requirement_group'])
                ->toArray(),
            'external_competencies' => $curriculumVersion->externalCompetencyRequirements()
                ->orderBy('requirement_code')
                ->get(['requirement_code', 'qualification_label', 'qualification_level', 'treatment'])
                ->toArray(),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function ensureProgramActiveForBatchCurriculum(
        User $actor,
        Program $program,
        ?AcademicYear $startingBatch,
        bool $activateProgramIfInactive,
    ): void {
        if ($program->is_active) {
            return;
        }

        if ($activateProgramIfInactive) {
            Gate::forUser($actor)->authorize('update', $program);
            $program->update(['is_active' => true]);

            activity()
                ->performedOn($program)
                ->causedBy($actor)
                ->event('program_activated_for_curriculum_batch')
                ->withProperties([
                    'academic_year_id' => $startingBatch?->id,
                    'academic_year_label' => $startingBatch?->label,
                ])
                ->log('Program activated for curriculum batch assignment');

            return;
        }

        throw ValidationException::withMessages([
            'program_id' => "{$program->code} is inactive. Activate the program before creating or assigning a curriculum.",
        ]);
    }

    private function ensureBatchUnassignedForProgram(Program $program, AcademicYear $academicYear): void
    {
        $existing = CurriculumVersionBatch::query()
            ->where('program_id', $program->id)
            ->where('academic_year_id', $academicYear->id)
            ->with('curriculumVersion')
            ->first();

        if (! $existing instanceof CurriculumVersionBatch) {
            return;
        }

        $ownerLabel = $existing->curriculumVersion?->batchLabel()
            ?: $existing->curriculumVersion?->name
            ?: 'another curriculum';
        $short = CurriculumVersion::formatBatchShort($academicYear);

        $message = $existing->curriculumVersion?->state === CurriculumVersion::StateDraft
            ? "Batch {$short} is currently reserved by draft curriculum {$ownerLabel} for {$program->code}. Open that draft to continue editing, or discard it to free this batch."
            : "Batch {$short} is already assigned to {$ownerLabel} for {$program->code}.";

        throw ValidationException::withMessages([
            'academic_year_id' => $message,
        ]);
    }
}
