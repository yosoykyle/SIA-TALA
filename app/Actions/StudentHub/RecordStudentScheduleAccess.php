<?php

namespace App\Actions\StudentHub;

use App\Models\Enrollment;
use App\Models\StudentScheduleBinding;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordStudentScheduleAccess
{
    public const OutputType = 'STUDENT_SCHEDULE';

    public const ActionView = 'VIEW';

    public const CopyStudent = 'STUDENT_COPY';

    public function execute(User $student, ?Request $request = null): void
    {
        if (! $student->hasRole('student')) {
            return;
        }

        $bindings = StudentScheduleBinding::query()
            ->activeOfficial()
            ->forStudent($student)
            ->with([
                'courseEnrollment.enrollment.studentProfile',
                'sectionMeeting.scheduleRun',
            ])
            ->get();

        if ($bindings->isEmpty()) {
            return;
        }

        $enrollment = $bindings
            ->map(fn (StudentScheduleBinding $binding): mixed => $binding->courseEnrollment?->enrollment)
            ->filter(fn (mixed $record): bool => $record instanceof Enrollment)
            ->sortByDesc('id')
            ->first();

        if (! $enrollment instanceof Enrollment) {
            return;
        }

        $versions = $bindings
            ->map(function (StudentScheduleBinding $binding): ?int {
                $version = $binding->sectionMeeting?->scheduleRun?->publication_version;

                return $version !== null ? (int) $version : null;
            })
            ->filter(fn (?int $version): bool => $version !== null)
            ->unique()
            ->values();

        DB::table('output_access_logs')->insert([
            'output_type' => self::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $enrollment->id,
            'student_profile_id' => $enrollment->student_profile_id,
            'actor_user_id' => $student->id,
            'actor_role' => $student->getRoleNames()->first(),
            'action' => self::ActionView,
            'copy_context' => self::CopyStudent,
            'schedule_version' => $versions->count() === 1 ? $versions->first() : null,
            'row_count' => $bindings->count(),
            'purpose' => 'Student viewed the current published class schedule.',
            'sensitivity' => 'student_record',
            'request_context' => json_encode([
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'route' => $request?->route()?->getName(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'logged',
            'occurred_at' => now(),
        ]);
    }
}
