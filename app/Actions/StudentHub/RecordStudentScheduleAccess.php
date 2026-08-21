<?php

namespace App\Actions\StudentHub;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordStudentScheduleAccess
{
    public const OutputType = 'STUDENT_SCHEDULE';

    public const ActionView = 'VIEW';

    public const ActionPrint = 'PRINT';

    public const CopyStudent = 'STUDENT_COPY';

    public function __construct(
        private readonly CurrentOfficialEnrollmentResolver $currentEnrollmentResolver,
    ) {}

    public function execute(
        User $student,
        ?Request $request = null,
        string $action = self::ActionView,
        ?Enrollment $enrollment = null,
    ): void {
        if (! $student->hasRole('student') || ! in_array($action, [self::ActionView, self::ActionPrint], true)) {
            return;
        }

        $currentEnrollment = $this->currentEnrollmentResolver->forStudent($student);

        if (! $currentEnrollment instanceof Enrollment
            || ($enrollment instanceof Enrollment && ! $enrollment->is($currentEnrollment))) {
            return;
        }

        $enrollment = $currentEnrollment;

        $registrations = CourseEnrollment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereNotNull('section_id')
            ->whereNotNull('published_timetable_version_id')
            ->get();

        if ($registrations->isEmpty()) {
            return;
        }

        $currentVersion = PublishedTimetableVersion::query()
            ->where('term_id', $enrollment->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->first();

        if (! $currentVersion instanceof PublishedTimetableVersion) {
            return;
        }

        $rowCount = PublishedTimetableMeeting::query()
            ->where('published_timetable_version_id', $currentVersion->id)
            ->whereIn('section_id', $registrations->pluck('section_id')->unique())
            ->count();

        DB::table('output_access_logs')->insert([
            'output_type' => self::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $enrollment->id,
            'student_profile_id' => $enrollment->student_profile_id,
            'actor_user_id' => $student->id,
            'actor_role' => $student->getRoleNames()->first(),
            'action' => $action,
            'copy_context' => self::CopyStudent,
            'schedule_version' => $currentVersion->version,
            'row_count' => $rowCount,
            'purpose' => $action === self::ActionPrint
                ? 'Student opened the current published class schedule for printing or saving as PDF.'
                : 'Student viewed the current published class schedule.',
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
