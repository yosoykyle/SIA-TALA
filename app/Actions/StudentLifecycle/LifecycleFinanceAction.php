<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\LedgerEntry;
use App\Models\StudentLifecycleChange;
use App\Models\User;

class LifecycleFinanceAction
{
    public function execute(StudentLifecycleChange $change, float $aggregateAdjustment, User $actor): ?LedgerEntry
    {
        if ($aggregateAdjustment === 0.0 || $change->enrollment_id === null) {
            return null;
        }

        $draft = Assessment::query()
            ->where('enrollment_id', $change->enrollment_id)
            ->where('state', Assessment::StateDraft)
            ->lockForUpdate()
            ->latest('version')
            ->first();

        if ($draft instanceof Assessment) {
            $draft->lines()
                ->whereNotNull('course_enrollment_id')
                ->whereHas('courseEnrollment', fn ($query) => $query->where('status', '!=', CourseEnrollment::StatusActive))
                ->delete();
            $subtotal = (string) $draft->lines()->sum('amount');
            $draft->paymentScheduleRows()->delete();
            $draft->update([
                'subtotal' => $subtotal,
                'total' => max(0, (float) $subtotal - (float) $draft->discount_total),
                'required_downpayment' => min((float) $draft->required_downpayment, max(0, (float) $subtotal - (float) $draft->discount_total)),
            ]);

            return null;
        }

        return LedgerEntry::query()->firstOrCreate(
            [
                'source_type' => StudentLifecycleChange::class,
                'source_id' => $change->id,
                'direction' => LedgerEntry::DirectionAdjustment,
            ],
            [
                'student_profile_id' => $change->student_profile_id,
                'term_id' => $change->term_id,
                'enrollment_id' => $change->enrollment_id,
                'category' => 'lifecycle_adjustment',
                'amount' => $aggregateAdjustment,
                'description' => 'Aggregate adjustment for '.str($change->type)->headline(),
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'state' => 'POSTED',
            ],
        );
    }
}
