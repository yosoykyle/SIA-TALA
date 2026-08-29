<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionRequirementSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublishAdmissionRequirementSet
{
    public function __construct(private readonly AdmissionRequirementSetCompletenessService $completeness) {}

    public function execute(
        AdmissionRequirementSet $requirementSet,
        User $actor,
        string $authorityReference,
        ?AdmissionRequirementSet $replaces = null,
    ): AdmissionRequirementSet {
        $this->authorize($actor);
        $authorityReference = Validator::make(
            ['authority_reference' => trim($authorityReference)],
            ['authority_reference' => ['required', 'string', 'max:255']],
        )->validate()['authority_reference'];

        return DB::transaction(function () use (
            $requirementSet,
            $actor,
            $authorityReference,
            $replaces,
        ): AdmissionRequirementSet {
            $locked = AdmissionRequirementSet::query()
                ->with('requirements')
                ->lockForUpdate()
                ->findOrFail($requirementSet->id);

            if ($locked->state !== AdmissionRequirementSet::StateDraft) {
                throw ValidationException::withMessages([
                    'state' => 'Only a Draft Admission Requirement Set can be published.',
                ]);
            }

            $requirementErrors = $this->completeness->errors($locked);

            if ($requirementErrors !== []) {
                throw ValidationException::withMessages([
                    'requirements' => $requirementErrors,
                ]);
            }

            $current = AdmissionRequirementSet::query()
                ->where('admission_cycle_id', $locked->admission_cycle_id)
                ->where('application_path', $locked->application_path)
                ->where('state', AdmissionRequirementSet::StatePublished)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if ($current instanceof AdmissionRequirementSet) {
                if (! $replaces instanceof AdmissionRequirementSet
                    || $replaces->id !== $current->id
                    || $replaces->admission_cycle_id !== $locked->admission_cycle_id
                    || $replaces->application_path !== $locked->application_path
                    || $locked->version <= $replaces->version) {
                    throw ValidationException::withMessages([
                        'replaces_requirement_set_id' => 'A replacement must name the current published version for the same cycle and path and use a later version.',
                    ]);
                }

                $locked->replaces_requirement_set_id = $replaces->id;
            } elseif ($replaces instanceof AdmissionRequirementSet) {
                throw ValidationException::withMessages([
                    'replaces_requirement_set_id' => 'There is no current published version to replace for this cycle and path.',
                ]);
            }

            if ($locked->effective_at === null) {
                throw ValidationException::withMessages([
                    'effective_at' => 'Set the effective date and time before publication.',
                ]);
            }

            $publishedAt = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'state' => AdmissionRequirementSet::StatePublished,
                'authority_reference' => $authorityReference,
                'published_by' => $actor->id,
                'published_at' => $publishedAt,
            ])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('manage-admission-setup')) {
            throw new AuthorizationException('Only an authorized Registrar may publish admission requirements.');
        }
    }
}
