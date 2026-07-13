<?php

namespace App\Actions\Scheduling;

final class SectionMeetingAssignmentService
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
    ) {}

    /**
     * @param  array<string, mixed>  $assignment
     */
    public function assertRecurringBlocksAllow(array $assignment): void
    {
        $this->revalidator->assertRecurringBlocksAllow($assignment);
    }
}
