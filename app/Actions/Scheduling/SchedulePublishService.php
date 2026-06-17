<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchedulePublishService
{
    public function publish(
        ScheduleGenerationRun $run,
        User $actor,
        ?string $note = null,
        bool $emergency = false,
    ): ScheduleGenerationRun {
        $note = $this->normalizedNote($note);
        $this->authorizePublisher($actor, $emergency, $note);

        return DB::transaction(function () use ($run, $actor, $note, $emergency): ScheduleGenerationRun {
            $lockedRun = ScheduleGenerationRun::query()
                ->withCount('sectionMeetings')
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if (! $lockedRun->canBePublished()) {
                throw ValidationException::withMessages([
                    'status' => 'Only committed schedule runs can be published.',
                ]);
            }

            if ((int) $lockedRun->section_meetings_count < 1) {
                throw ValidationException::withMessages([
                    'section_meetings' => 'A committed run must have official meetings before it can be published.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusPublished,
                'published_by' => $actor->id,
                'published_at' => $timestamp,
                'publish_note' => $note,
                'emergency_published' => $emergency,
            ])->save();

            $this->recordActivity($lockedRun, $actor, $timestamp, $emergency);

            return $lockedRun->fresh();
        });
    }

    private function authorizePublisher(User $actor, bool $emergency, ?string $note): void
    {
        if ($emergency) {
            if ($actor->hasRole(User::StaffRoleSystemSuperAdmin) && filled($note)) {
                return;
            }

            throw new AuthorizationException('System Super Admin emergency publish requires a reason.');
        }

        if ($actor->hasRole(User::StaffRoleAcademicHead) && $actor->can('authorize-overrides')) {
            return;
        }

        throw new AuthorizationException('Only an authorized Academic Head can publish committed schedules.');
    }

    private function normalizedNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }

    private function recordActivity(
        ScheduleGenerationRun $run,
        User $actor,
        CarbonImmutable $timestamp,
        bool $emergency,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => 'Schedule generation run published.',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => 'schedule_generation_run_published',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'term_id' => $run->term_id,
                'status_after' => ScheduleGenerationRun::StatusPublished,
                'emergency_published' => $emergency,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
