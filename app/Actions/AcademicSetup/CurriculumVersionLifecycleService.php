<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CurriculumVersionLifecycleService
{
    public function __construct(
        private readonly CourseSpecificationLifecycleService $courseSpecifications,
    ) {}

    public function recordApproval(
        User $actor,
        CurriculumVersion $curriculumVersion,
        string $approvalReference,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('recordApproval', $curriculumVersion);

        $normalizedReference = trim($approvalReference);

        if ($normalizedReference === '') {
            throw ValidationException::withMessages([
                'approval_reference' => 'Enter the external approval reference.',
            ]);
        }

        return DB::transaction(function () use ($actor, $curriculumVersion, $normalizedReference): CurriculumVersion {
            $locked = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            if ($locked->state !== CurriculumVersion::StateDraft) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'Only a Draft curriculum can be recorded as externally approved.',
                ]);
            }

            $locked->update([
                'state' => CurriculumVersion::StateRecordedApproved,
                'approval_reference' => $normalizedReference,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('curriculum_approval_recorded')
                ->withProperties([
                    'approval_reference' => $normalizedReference,
                    'state_after' => CurriculumVersion::StateRecordedApproved,
                ])
                ->log('External curriculum approval recorded');

            return $locked->fresh();
        });
    }

    /**
     * @return array{
     *     active_version_code:?string,
     *     entries:int,
     *     existing_student_locks:int,
     *     readiness_errors:list<string>
     * }
     */
    public function activationImpact(CurriculumVersion $curriculumVersion): array
    {
        $activeVersionCode = CurriculumVersion::query()
            ->where('program_id', $curriculumVersion->program_id)
            ->where('state', CurriculumVersion::StateActive)
            ->whereKeyNot($curriculumVersion->id)
            ->value('version_code');

        return [
            'active_version_code' => is_string($activeVersionCode) ? $activeVersionCode : null,
            'entries' => $curriculumVersion->entries()->count(),
            'existing_student_locks' => StudentProfile::query()
                ->where('program_id', $curriculumVersion->program_id)
                ->where('curriculum_version_id', '!=', $curriculumVersion->id)
                ->count(),
            'readiness_errors' => $this->readinessErrors($curriculumVersion),
        ];
    }

    public function activate(
        User $actor,
        CurriculumVersion $curriculumVersion,
        bool $confirmed,
    ): CurriculumVersion {
        Gate::forUser($actor)->authorize('activate', $curriculumVersion);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirmation' => 'Confirm that you reviewed the activation impact.',
            ]);
        }

        return DB::transaction(function () use ($actor, $curriculumVersion): CurriculumVersion {
            Program::query()->lockForUpdate()->findOrFail($curriculumVersion->program_id);
            $locked = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            if ($locked->state !== CurriculumVersion::StateRecordedApproved
                || blank($locked->approval_reference)
                || $locked->approved_by === null
                || $locked->approved_at === null) {
                throw ValidationException::withMessages([
                    'curriculum_version' => 'Record complete external approval evidence before activation.',
                ]);
            }

            $errors = $this->readinessErrors($locked);

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'curriculum_version' => $errors,
                ]);
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

            $locked->update(['state' => CurriculumVersion::StateActive]);

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

    /**
     * @return list<string>
     */
    public function readinessErrors(CurriculumVersion $curriculumVersion): array
    {
        $entries = $curriculumVersion->entries()
            ->with('courseSpecification')
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

            if ($specification->state !== CourseSpecification::StateActive) {
                $errors[] = "{$specification->title} ({$specification->revision_code}) must be Active.";

                continue;
            }

            foreach ($this->courseSpecifications->readinessErrors($specification) as $error) {
                $errors[] = "{$specification->title} ({$specification->revision_code}): {$error}";
            }
        }

        return array_values(array_unique($errors));
    }
}
