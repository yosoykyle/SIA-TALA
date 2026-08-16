<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublishAdmissionCycle
{
    public function __construct(private readonly AdmissionCycleReadinessService $readiness) {}

    public function execute(
        AdmissionCycle $cycle,
        User $actor,
        string $authorityReference,
    ): AdmissionCycle {
        $this->authorize($actor);
        $authorityReference = Validator::make(
            ['authority_reference' => trim($authorityReference)],
            ['authority_reference' => ['required', 'string', 'max:255']],
        )->validate()['authority_reference'];

        return DB::transaction(function () use ($cycle, $actor, $authorityReference): AdmissionCycle {
            $locked = AdmissionCycle::query()->lockForUpdate()->findOrFail($cycle->id);

            if ($locked->state !== AdmissionCycle::StateDraft) {
                throw ValidationException::withMessages([
                    'state' => 'Only a Draft Admission Cycle can be published.',
                ]);
            }

            $projection = $this->readiness->for($locked);

            if (! $projection['ready']) {
                throw ValidationException::withMessages([
                    'readiness' => collect($projection['blockers'])
                        ->map(fn (array $blocker): string => $blocker['reason'].' '.$blocker['recovery'])
                        ->all(),
                ]);
            }

            $locked->forceFill(['state' => AdmissionCycle::StatePublished])->save();
            $locked->events()->create([
                'event_type' => AdmissionCycleEvent::TypePublished,
                'event_key' => 'admission-cycle-published:'.Str::uuid(),
                'previous_values' => ['state' => AdmissionCycle::StateDraft],
                'new_values' => [
                    'state' => AdmissionCycle::StatePublished,
                    'opens_at' => $locked->opens_at?->toIso8601String(),
                    'closes_at' => $locked->closes_at?->toIso8601String(),
                ],
                'reason' => 'Admission Cycle publication readiness passed.',
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'occurred_at' => now(config('app.timezone')),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)
            || ! $actor->canAuthenticate()
            || ! $actor->can('manage-admission-setup')) {
            throw new AuthorizationException('Only an authorized Registrar may publish an Admission Cycle.');
        }
    }
}
