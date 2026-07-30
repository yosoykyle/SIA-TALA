<?php

namespace App\Actions\Graduation;

use App\Models\GraduationReviewBatch;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CloseGraduationReviewBatch
{
    public function execute(GraduationReviewBatch $batch, User $actor): GraduationReviewBatch
    {
        Gate::forUser($actor)->authorize('update', $batch);

        return DB::transaction(function () use ($batch, $actor): GraduationReviewBatch {
            $lockedBatch = GraduationReviewBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->id);

            if ($lockedBatch->state === GraduationReviewBatch::StateClosed) {
                throw new DomainException('This completion review is already closed.');
            }

            $closedAt = now();

            $lockedBatch->update([
                'state' => GraduationReviewBatch::StateClosed,
                'closed_at' => $closedAt,
            ]);

            activity()
                ->performedOn($lockedBatch)
                ->causedBy($actor)
                ->event('graduation_review_batch_closed')
                ->withProperties([
                    'state_before' => GraduationReviewBatch::StateOpen,
                    'state_after' => GraduationReviewBatch::StateClosed,
                    'closed_at' => $closedAt->toISOString(),
                ])
                ->log('Completion eligibility review closed');

            return $lockedBatch;
        });
    }
}
