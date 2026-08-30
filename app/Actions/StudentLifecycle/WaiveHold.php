<?php

namespace App\Actions\StudentLifecycle;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Models\Hold;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WaiveHold
{
    public function __construct(private readonly CompletionReadinessProjection $completionReadiness) {}

    public function execute(Hold $hold, User $actor, string $authority, string $reason): Hold
    {
        if (! Hold::officeOwnsType($actor, (string) $hold->hold_type)) {
            throw new AuthorizationException('The current office cannot waive this hold.');
        }

        if (blank($authority) || blank($reason)) {
            throw new RuntimeException('Waiver authority and reason are required.');
        }

        return DB::transaction(function () use ($hold, $actor, $authority, $reason): Hold {
            $locked = Hold::query()->lockForUpdate()->findOrFail($hold->id);
            if (! Hold::officeOwnsType($actor, (string) $locked->hold_type)) {
                throw new AuthorizationException('The current office cannot waive this hold.');
            }

            if ($locked->status !== Hold::StatusActive) {
                return $locked;
            }
            $statusBefore = $locked->status;
            $locked->update([
                'status' => Hold::StatusWaived,
                'waived_by' => $actor->id,
                'waived_at' => now(),
                'staff_only_reason' => trim(collect([$locked->staff_only_reason, "Waived by authority [$authority]: $reason"])->filter()->implode("\n")),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('hold_waived')
                ->withProperties([
                    'hold_type' => $locked->hold_type,
                    'blocking_level' => $locked->blocking_level,
                    'authority' => $authority,
                    'reason' => $reason,
                    'status_before' => $statusBefore,
                    'status_after' => Hold::StatusWaived,
                ])
                ->log('Hold waived');

            $this->completionReadiness->persist($locked->studentProfile, $actor);

            return $locked->refresh();
        }, attempts: 3);
    }
}
