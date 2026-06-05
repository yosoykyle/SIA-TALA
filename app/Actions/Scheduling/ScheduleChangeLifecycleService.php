<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleChange;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\Scheduling\ScheduleChangePayload;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleChangeLifecycleService
{
    public function __construct(
        private readonly SectionMeetingAssignmentService $assignmentService,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function approve(ScheduleChange $scheduleChange, User $approver): ScheduleChange
    {
        $this->authorize($approver, 'authorize-overrides');

        return DB::transaction(function () use ($scheduleChange, $approver): ScheduleChange {
            $locked = $this->lockedScheduleChange($scheduleChange);

            if (! $locked->isProposed()) {
                throw ValidationException::withMessages([
                    'status' => 'Only proposed schedule changes can be approved.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'status' => ScheduleChange::StatusApproved,
                'approved_by' => $approver->id,
            ])->save();

            $this->recordActivity(
                $locked,
                $approver,
                'schedule_change_approved',
                ScheduleChange::StatusApproved,
                $timestamp,
            );

            return $locked->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function apply(ScheduleChange $scheduleChange, User $registrar): ScheduleChange
    {
        $this->authorize($registrar, 'manage-schedules');

        return DB::transaction(function () use ($scheduleChange, $registrar): ScheduleChange {
            $locked = $this->lockedScheduleChange($scheduleChange);

            if (! $locked->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Only approved schedule changes can be applied.',
                ]);
            }

            if ($locked->section_meeting_id === null) {
                throw ValidationException::withMessages([
                    'section_meeting_id' => 'Schedule change must target an official meeting before it can be applied.',
                ]);
            }

            if (! is_array($locked->new_payload)) {
                throw ValidationException::withMessages([
                    'new_payload' => 'Schedule change payload is incomplete.',
                ]);
            }

            $sectionMeeting = SectionMeeting::query()
                ->lockForUpdate()
                ->findOrFail($locked->section_meeting_id);

            $sectionMeeting->forceFill($this->assignmentService->prepareForScheduleChange(
                $sectionMeeting,
                ScheduleChangePayload::normalize($locked->new_payload),
            ))->save();

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'status' => ScheduleChange::StatusApplied,
                'applied_at' => $timestamp,
            ])->save();

            $this->recordActivity(
                $locked,
                $registrar,
                'schedule_change_applied',
                ScheduleChange::StatusApplied,
                $timestamp,
            );

            return $locked->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
    }

    private function lockedScheduleChange(ScheduleChange $scheduleChange): ScheduleChange
    {
        return ScheduleChange::query()
            ->lockForUpdate()
            ->findOrFail($scheduleChange->id);
    }

    private function recordActivity(
        ScheduleChange $scheduleChange,
        User $actor,
        string $event,
        string $statusAfter,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Schedule change state changed.',
            'subject_type' => ScheduleChange::class,
            'subject_id' => $scheduleChange->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'status_after' => $statusAfter,
                'term_id' => $scheduleChange->term_id,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
