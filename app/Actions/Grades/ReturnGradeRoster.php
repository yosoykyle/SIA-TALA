<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReturnGradeRoster
{
    public function execute(GradeRoster $roster, User $actor, string $reason): GradeRoster
    {
        return DB::transaction(function () use ($roster, $actor, $reason): GradeRoster {
            $locked = GradeRoster::query()->lockForUpdate()->findOrFail($roster->id);

            if ($locked->state !== GradeRoster::StateSubmitted) {
                throw new RuntimeException('Only submitted rosters can be returned.');
            }

            $locked->update([
                'state' => GradeRoster::StateReturned,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'return_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }
}
