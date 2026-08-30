<?php

namespace App\Actions\StudentLifecycle;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateHold
{
    public function __construct(private readonly CompletionReadinessProjection $completionReadiness) {}

    public function execute(StudentProfile $studentProfile, array $data, User $actor): Hold
    {
        $holdType = (string) ($data['hold_type'] ?? '');

        if (! $this->ownsType($actor, $holdType)) {
            throw new AuthorizationException('The current office does not own this hold type.');
        }

        foreach (['blocking_level', 'reason', 'resolution_requirement'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new RuntimeException("Hold field [$required] is required.");
            }
        }

        return DB::transaction(function () use ($studentProfile, $data, $actor): Hold {
            $hold = Hold::query()->create([
                ...$data,
                'student_profile_id' => $studentProfile->id,
                'created_by' => $actor->id,
                'status' => Hold::StatusActive,
                'effective_at' => $data['effective_at'] ?? now(),
            ]);

            activity()
                ->performedOn($hold)
                ->causedBy($actor)
                ->event('hold_created')
                ->withProperties([
                    'hold_type' => $hold->hold_type,
                    'blocking_level' => $hold->blocking_level,
                    'reason' => $hold->reason,
                    'status_after' => $hold->status,
                ])
                ->log('Hold created');

            $this->completionReadiness->persist($studentProfile, $actor);

            return $hold;
        }, attempts: 3);
    }

    private function ownsType(User $actor, string $holdType): bool
    {
        return Hold::officeOwnsType($actor, $holdType);
    }
}
