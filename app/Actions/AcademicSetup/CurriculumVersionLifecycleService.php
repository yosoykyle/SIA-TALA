<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CurriculumVersionLifecycleService
{
    public function __construct(
        private readonly CourseSpecificationLifecycleService $courseSpecifications,
    ) {}

    /**
     * Copy an Active, Superseded, or Draft CurriculumVersion into a new independent Draft successor.
     */
    public function copyToDraft(
        User $actor,
        CurriculumVersion $source,
        string $versionCode,
        ?string $name = null,
        ?int $effectiveEntryTermId = null,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('copy', $source);

        $normalizedCode = trim($versionCode);

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'version_code' => 'Enter a curriculum version code for the new Draft.',
            ]);
        }

        $normalizedName = $name !== null ? trim($name) : '';

        return DB::transaction(function () use ($actor, $source, $normalizedCode, $normalizedName, $effectiveEntryTermId): CurriculumVersion {
            $lockedSource = CurriculumVersion::query()
                ->with(['entries', 'externalCompetencyRequirements'])
                ->lockForUpdate()
                ->findOrFail($source->id);

            if (CurriculumVersion::query()
                ->where('program_id', $lockedSource->program_id)
                ->where('version_code', $normalizedCode)
                ->exists()) {
                throw ValidationException::withMessages([
                    'version_code' => 'That curriculum version code already exists for this program.',
                ]);
            }

            $draft = CurriculumVersion::query()->create([
                'program_id' => $lockedSource->program_id,
                'version_code' => $normalizedCode,
                'name' => $normalizedName !== '' ? $normalizedName : "{$lockedSource->name} (Draft)",
                'effective_entry_term_id' => $effectiveEntryTermId,
                'state' => CurriculumVersion::StateDraft,
                'content_hash' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

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
                    'entries_copied' => count($entryMap),
                ])
                ->log('Curriculum Version copied to Draft');

            return $draft->fresh(['program', 'entries', 'externalCompetencyRequirements']);
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

            $supersededIds = CurriculumVersion::query()
                ->where('program_id', $locked->program_id)
                ->where('state', CurriculumVersion::StateActive)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($supersededIds !== []) {
                CurriculumVersion::query()
                    ->whereKey($supersededIds)
                    ->update(['state' => CurriculumVersion::StateSuperseded]);
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

    public function readinessErrors(CurriculumVersion $curriculumVersion): array
    {
        $entries = $curriculumVersion->entries()
            ->with('courseSpecification.components')
            ->get();
        $errors = [];

        if ($entries->isEmpty()) {
            return ['At least one Curriculum Entry is required.'];
        }

        foreach ($entries as $entry) {
            $specification = $entry->courseSpecification;

            if (! $specification instanceof CourseSpecification) {
                $errors[] = "Curriculum Entry {$entry->id} has no Course Specification.";

                continue;
            }

            if (! in_array($specification->state, [CourseSpecification::StateActive, CourseSpecification::StateDraft], true)) {
                $errors[] = "{$specification->title} ({$specification->revision_code}) must be Active.";

                continue;
            }

            $specErrors = $this->courseSpecifications->readinessErrors($specification);

            if ($specErrors !== []) {
                foreach ($specErrors as $error) {
                    $prefix = $specification->state === CourseSpecification::StateDraft
                        ? "{$specification->title} ({$specification->revision_code}) must be Active or have complete teaching details"
                        : "{$specification->title} ({$specification->revision_code})";
                    $errors[] = "{$prefix}: {$error}";
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
                $code = $specification->course?->code;
                $labels[] = $code
                    ? "{$code} · {$specification->title} ({$specification->revision_code})"
                    : "{$specification->title} ({$specification->revision_code})";
            }
        }

        return array_values(array_unique($labels));
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
}
