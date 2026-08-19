<?php

namespace App\Actions\AcademicSetup;

use App\Models\Program;
use App\Models\ProgramAuthority;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ActivateProgramAuthority
{
    public function __construct(private readonly AcademicAuthorityReadinessService $readiness) {}

    public function execute(ProgramAuthority $authority, User $actor): ProgramAuthority
    {
        Gate::forUser($actor)->authorize('update', $authority->program);

        return DB::transaction(function () use ($authority, $actor): ProgramAuthority {
            Program::query()->whereKey($authority->program_id)->lockForUpdate()->firstOrFail();
            $locked = ProgramAuthority::query()->whereKey($authority)->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked->program);

            if ($locked->state !== ProgramAuthority::StateDraft) {
                throw ValidationException::withMessages([
                    'state' => 'Only a Draft Program authority can be activated.',
                ]);
            }

            $readiness = $this->readiness->for($locked);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'readiness' => collect($readiness['blockers'])->pluck('reason')->all(),
                ]);
            }

            $overlap = ProgramAuthority::query()
                ->where('program_id', $locked->program_id)
                ->where('state', ProgramAuthority::StateActive)
                ->whereKeyNot($locked)
                ->whereDate('effective_from', '<=', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($query) => $query
                    ->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $locked->effective_from))
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'effective_from' => 'An active Program authority already covers this effective period. Record a non-overlapping successor.',
                ]);
            }

            $locked->forceFill([
                'state' => ProgramAuthority::StateActive,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ])->save();

            return $locked->fresh();
        }, attempts: 5);
    }
}
