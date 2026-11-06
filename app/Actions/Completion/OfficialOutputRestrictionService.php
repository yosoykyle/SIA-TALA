<?php

namespace App\Actions\Completion;

use App\Models\OfficialOutputRestriction;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OfficialOutputRestrictionService
{
    public function record(StudentProfile $student, User $actor, string $reason, ?string $sourceReference = null): OfficialOutputRestriction
    {
        $this->authorize($actor);
        validator(['reason' => $reason, 'source_reference' => $sourceReference], [
            'reason' => ['required', 'string', 'max:1000'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($student, $actor, $reason, $sourceReference): OfficialOutputRestriction {
            StudentProfile::query()->lockForUpdate()->findOrFail($student->id);

            return OfficialOutputRestriction::query()->firstOrCreate([
                'student_profile_id' => $student->id,
                'output_type' => OfficialOutputRestriction::OutputTor,
                'reason' => trim($reason),
                'source_reference' => filled($sourceReference) ? trim($sourceReference) : null,
                'resolved_at' => null,
            ], ['recorded_by' => $actor->id, 'recorded_at' => now()]);
        }, attempts: 3);
    }

    public function resolve(OfficialOutputRestriction $restriction, User $actor, string $reason): OfficialOutputRestriction
    {
        $this->authorize($actor);
        validator(['resolution_reason' => $reason], ['resolution_reason' => ['required', 'string', 'max:1000']])->validate();

        return DB::transaction(function () use ($restriction, $actor, $reason): OfficialOutputRestriction {
            StudentProfile::query()->lockForUpdate()->findOrFail($restriction->student_profile_id);
            $locked = OfficialOutputRestriction::query()->lockForUpdate()->findOrFail($restriction->id);
            if ($locked->resolved_at === null) {
                $locked->update(['resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_reason' => trim($reason)]);
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, OfficialOutputRestriction> */
    public function activeFor(StudentProfile $student): Collection
    {
        return OfficialOutputRestriction::query()->where('student_profile_id', $student->id)
            ->where('output_type', OfficialOutputRestriction::OutputTor)->whereNull('resolved_at')->orderBy('id')->get();
    }

    public function fingerprintFor(StudentProfile $student): string
    {
        $sources = OfficialOutputRestriction::query()->where('student_profile_id', $student->id)
            ->where('output_type', OfficialOutputRestriction::OutputTor)->orderBy('id')
            ->get(['id', 'reason', 'source_reference', 'recorded_at', 'resolved_at']);

        return hash('sha256', $sources->toJson());
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record or resolve an official-output restriction.');
        }
    }
}
