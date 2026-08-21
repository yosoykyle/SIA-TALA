<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildOfficialScheduleOutput
{
    public function __construct(
        private readonly CurrentOfficialEnrollmentResolver $currentEnrollmentResolver,
    ) {}

    /**
     * @return array{title:string,owner:string,generated_at:string,version_label:string,version_state:string,rows:list<array<string, string>>}
     */
    public function forFaculty(User $faculty, Request $request): array
    {
        abort_unless($faculty->hasRole(User::StaffRoleFaculty), 403);

        $requestedVersionId = $request->integer('timetable_version');
        $availableVersions = PublishedTimetableVersion::query()
            ->whereHas('meetings', fn ($query) => $query->where('faculty_user_id', $faculty->id))
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('published_at')
            ->get();
        $version = $requestedVersionId > 0
            ? $availableVersions->firstWhere('id', $requestedVersionId)
            : ($availableVersions->count() === 1 ? $availableVersions->sole() : null);
        $meetings = SectionMeeting::query()
            ->with([
                'scheduleRun',
                'schedulingDemand.termOffering.term',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.courseComponent',
                'faculty',
                'room',
            ])
            ->where('faculty_user_id', $faculty->id)
            ->when(
                $version instanceof PublishedTimetableVersion,
                fn ($query) => $query->where('published_timetable_version_id', $version->id),
                fn ($query) => $requestedVersionId === 0 && $availableVersions->isEmpty()
                    ? $query->whereNull('published_timetable_version_id')
                        ->whereHas('scheduleRun', fn ($runQuery) => $runQuery->where('status', ScheduleGenerationRun::StatusPublished))
                    : $query->whereRaw('1 = 0'),
            )
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        if ($meetings->isNotEmpty()) {
            $versions = $meetings->pluck('scheduleRun.publication_version')->filter()->unique()->values();

            DB::table('output_access_logs')->insert([
                'output_type' => 'FACULTY_SCHEDULE',
                'source_record_type' => User::class,
                'source_record_id' => $faculty->id,
                'student_profile_id' => null,
                'actor_user_id' => $faculty->id,
                'actor_role' => $faculty->getRoleNames()->first(),
                'action' => 'PRINT',
                'copy_context' => 'FACULTY_COPY',
                'schedule_version' => $versions->count() === 1 ? $versions->first() : null,
                'filter_summary' => json_encode([
                    'timetable_version_id' => $version?->id,
                    'faculty_user_id' => $faculty->id,
                ], JSON_THROW_ON_ERROR),
                'row_count' => $meetings->count(),
                'purpose' => 'Faculty opened their current official assigned schedule for printing or saving as PDF.',
                'sensitivity' => 'faculty_record',
                'stored_file_reference' => null,
                'request_context' => json_encode($this->requestContext($request), JSON_THROW_ON_ERROR),
                'status' => 'logged',
                'occurred_at' => now(),
            ]);
        }

        return [
            'title' => 'Faculty Assigned Schedule',
            'owner' => $faculty->name,
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'version_label' => match (true) {
                $version instanceof PublishedTimetableVersion => $version->term?->label.' · Timetable v'.$version->version,
                $availableVersions->count() > 1 => 'Select one exact Term and timetable version',
                $meetings->isNotEmpty() => 'Legacy published timetable baseline',
                default => 'No current published schedule is available for this account.',
            },
            'version_state' => $version instanceof PublishedTimetableVersion
                ? $version->state
                : ($meetings->isNotEmpty() ? 'Published' : 'Unavailable'),
            'rows' => $meetings
                ->map(fn (SectionMeeting $meeting): array => $this->meetingRow($meeting))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{title:string,owner:string,generated_at:string,version_label:string,version_state:string,rows:list<array<string, string>>}
     */
    public function forStudent(User $student, Request $request): array
    {
        abort_unless($student->hasRole('student'), 403);

        $enrollment = $this->currentEnrollmentResolver->forStudent($student);
        $version = $enrollment instanceof Enrollment
            ? PublishedTimetableVersion::query()
                ->where('term_id', $enrollment->term_id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->latest('version')
                ->first()
            : null;

        $registrations = CourseEnrollment::query()
            ->with([
                'termOffering.term',
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
                'publishedTimetableVersion',
            ])
            ->when($enrollment instanceof Enrollment,
                fn ($query) => $query->where('enrollment_id', $enrollment->id),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereNotNull('section_id')
            ->get();
        $meetings = PublishedTimetableMeeting::query()
            ->with(['faculty', 'room', 'classOffering'])
            ->when($version instanceof PublishedTimetableVersion && $registrations->isNotEmpty(),
                fn ($query) => $query
                    ->where('published_timetable_version_id', $version->id)
                    ->whereIn('section_id', $registrations->pluck('section_id')->unique()),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('meeting_sequence')
            ->get();

        app(RecordStudentScheduleAccess::class)->execute(
            $student,
            $request,
            RecordStudentScheduleAccess::ActionPrint,
            $enrollment,
        );

        return [
            'title' => 'Student Class Schedule',
            'owner' => $student->name,
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'version_label' => $version instanceof PublishedTimetableVersion
                ? $version->term?->label.' · Timetable v'.$version->version
                : ((string) $enrollment?->term?->label ?: 'No current published schedule is available for this account.'),
            'version_state' => $version instanceof PublishedTimetableVersion
                ? $version->state
                : ($meetings->isNotEmpty() ? 'Published' : 'Unavailable'),
            'rows' => $meetings
                ->map(function (PublishedTimetableMeeting $meeting) use ($registrations, $enrollment): array {
                    $registration = $registrations->first(fn (CourseEnrollment $registration): bool => (int) $registration->section_id === (int) $meeting->section_id);

                    if (! $registration instanceof CourseEnrollment) {
                        return [];
                    }

                    return [
                        'term' => (string) ($enrollment !== null ? $enrollment->term?->label : $registration->termOffering?->term?->label),
                        'course' => (string) $registration->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                        'description' => (string) $registration->termOffering?->curriculumEntry?->courseSpecification?->title,
                        'section' => (string) $registration->section?->code,
                        'component' => 'Published meeting '.$meeting->meeting_sequence,
                        'day' => SectionMeeting::dayOptions()[$meeting->day_of_week] ?? 'Unscheduled',
                        'time' => $this->publishedTimeRange($meeting),
                        'room' => ($meeting->room !== null ? $meeting->room->code : null) ?? $meeting->location_label ?? 'TBA',
                        'modality' => SectionMeeting::modalityOptions()[$meeting->modality] ?? str($meeting->modality)->headline()->toString(),
                        'faculty' => (string) $meeting->faculty?->name,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function publishedTimeRange(PublishedTimetableMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    /**
     * @return array<string, string>
     */
    private function meetingRow(SectionMeeting $meeting): array
    {
        $room = $meeting->getRelation('room');
        $faculty = $meeting->getRelation('faculty');

        return [
            'term' => (string) $meeting->schedulingDemand?->termOffering?->term?->label,
            'course' => (string) $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
            'description' => (string) $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->title,
            'section' => (string) $meeting->schedulingDemand?->sectionDeliveryGroup?->section?->code,
            'component' => str((string) $meeting->schedulingDemand?->courseComponent?->component_type)->headline()->toString(),
            'day' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
            'time' => $this->timeRange($meeting),
            'room' => $room instanceof Room ? $room->code : 'TBA',
            'modality' => SectionMeeting::modalityOptions()[$meeting->modality] ?? str($meeting->modality)->headline()->toString(),
            'faculty' => $faculty instanceof User ? $faculty->name : '',
        ];
    }

    private function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    /**
     * @return array{ip:string|null,user_agent:string|null,route:string|null}
     */
    private function requestContext(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'route' => $request->route()?->getName(),
        ];
    }
}
