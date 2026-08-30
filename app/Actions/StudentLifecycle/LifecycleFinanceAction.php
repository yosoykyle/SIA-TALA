<?php

namespace App\Actions\StudentLifecycle;

use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\User;

class LifecycleFinanceAction
{
    public function execute(StudentLifecycleChange $change, User $actor): ?OperationalEvent
    {
        if ($change->enrollment_id === null) {
            return null;
        }

        return OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainOperations,
                'external_id' => "lifecycle-accounting-review:{$change->id}",
            ],
            [
                'integration' => OperationalEvent::IntegrationAcademicRecords,
                'channel' => OperationalEvent::ChannelEvidenceIngest,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeLifecycleAccountingReview,
                'event_version' => '1',
                'user_id' => $actor->id,
                'status' => OperationalEvent::StatusReviewRequired,
                'occurred_at' => now(),
                'related_record_type' => StudentLifecycleChange::class,
                'related_record_id' => $change->id,
                'payload' => [
                    'student_profile_id' => $change->student_profile_id,
                    'enrollment_id' => $change->enrollment_id,
                    'lifecycle_type' => $change->type,
                    'instruction' => 'Accounting must review any financial consequence separately. No amount, refund, credit, penalty, forfeiture, balance change, or hold is inferred.',
                ],
            ],
        );
    }
}
