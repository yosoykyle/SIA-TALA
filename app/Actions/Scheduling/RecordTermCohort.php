<?php

namespace App\Actions\Scheduling;

use App\Models\CurriculumVersion;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordTermCohort
{
    /** @param array<string, mixed> $data */
    public function execute(Term $term, User $actor, array $data): TermCohort
    {
        abort_unless($actor->hasRole(User::StaffRoleRegistrar), 403);
        Gate::forUser($actor)->authorize('create', Section::class);

        return DB::transaction(function () use ($term, $actor, $data): TermCohort {
            $term = Term::query()->lockForUpdate()->findOrFail($term->id);
            if ($term->state === Term::StateClosed) {
                throw ValidationException::withMessages(['term_id' => 'A Closed Term cannot receive new confirmed cohorts.']);
            }
            $validated = Validator::make([
                'curriculum_version_id' => $data['curriculum_version_id'] ?? null,
                'reference' => trim((string) ($data['reference'] ?? '')),
                'confirmed_count' => $data['confirmed_count'] ?? null,
            ], [
                'curriculum_version_id' => ['required', 'integer', 'exists:curriculum_versions,id'],
                'reference' => ['required', 'string', 'max:64', Rule::unique('term_cohorts')->where('term_id', $term->id)],
                'confirmed_count' => ['required', 'integer', 'min:1'],
            ])->validate();
            $curriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail($validated['curriculum_version_id']);
            if ($curriculum->state !== CurriculumVersion::StateActive) {
                throw ValidationException::withMessages(['curriculum_version_id' => 'Choose an Active approved curriculum for this confirmed cohort.']);
            }

            return TermCohort::query()->create([
                ...$validated,
                'term_id' => $term->id,
                'program_id' => $curriculum->program_id,
                'source' => 'Confirmed',
                'forecast_count' => null,
                'state' => 'Confirmed',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);
        }, attempts: 5);
    }
}
