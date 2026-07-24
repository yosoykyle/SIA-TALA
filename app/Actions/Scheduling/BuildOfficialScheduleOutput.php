<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Models\Enrollment;
use App\Models\Room;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildOfficialScheduleOutput
{
    public function __construct(
        private readonly CurrentOfficialEnrollmentResolver $currentEnrollmentResolver,
    ) {}

    /**
     * @return array{title:string,owner:string,generated_at:string,rows:list<array<string, string>>}
     */
    public function forFaculty(User $faculty, Request $request): array
    {
        abort_unless($faculty->hasRole(User::StaffRoleFaculty), 403);

        $meetings = SectionMeeting::query()
            ->activeOfficial()
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
                    'official_only' => true,
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
            'generated_at' => now()->format('F j, Y g:i A'),
            'rows' => $meetings
                ->map(fn (SectionMeeting $meeting): array => $this->meetingRow($meeting))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{title:string,owner:string,generated_at:string,rows:list<array<string, string>>}
     */
    public function forStudent(User $student, Request $request): array
    {
        abort_unless($student->hasRole('student'), 403);

        $enrollment = $this->currentEnrollmentResolver->forStudent($student);

        $bindings = StudentScheduleBinding::query()
            ->activeOfficial()
            ->when(
                $enrollment instanceof Enrollment,
                fn ($query) => $query->forEnrollment($enrollment),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->with([
                'courseEnrollment.termOffering.term',
                'courseEnrollment.termOffering.curriculumEntry.courseSpecification.course',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
                'sectionMeeting.schedulingDemand.courseComponent',
                'sectionMeeting.faculty',
                'sectionMeeting.room',
            ])
            ->get()
            ->sortBy(fn (StudentScheduleBinding $binding): string => sprintf(
                '%02d-%s-%020d',
                (int) $binding->sectionMeeting?->day_of_week,
                (string) $binding->sectionMeeting?->starts_at,
                (int) $binding->id,
            ));

        app(RecordStudentScheduleAccess::class)->execute(
            $student,
            $request,
            RecordStudentScheduleAccess::ActionPrint,
            $enrollment,
        );

        return [
            'title' => 'Student Class Schedule',
            'owner' => $student->name,
            'generated_at' => now()->format('F j, Y g:i A'),
            'rows' => $bindings
                ->map(function (StudentScheduleBinding $binding): array {
                    $meeting = $binding->sectionMeeting;

                    if (! $meeting instanceof SectionMeeting) {
                        return [];
                    }

                    $row = $this->meetingRow($meeting);
                    $row['term'] = (string) $binding->courseEnrollment?->termOffering?->term?->label;
                    $row['course'] = (string) $binding->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                    $row['description'] = (string) $binding->courseEnrollment?->termOffering?->curriculumEntry?->courseSpecification?->title;
                    $row['faculty'] = (string) $meeting->faculty?->name;

                    return $row;
                })
                ->filter()
                ->values()
                ->all(),
        ];
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
