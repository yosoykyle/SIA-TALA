<?php

namespace App\Actions\Scheduling;

use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BuildTimetableVersionOutput
{
    /** @return array{title:string,owner:string,generated_at:string,version_label:string,version_state:string,rows:list<array<string,string>>} */
    public function execute(PublishedTimetableVersion $version, User $actor, Request $request): array
    {
        abort_unless($actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]), 403);
        $version->loadMissing('term');
        $meetings = $version->meetings()
            ->with([
                'classOffering',
                'faculty',
                'room',
                'schedulingDemand.courseComponent.courseSpecification.course',
            ])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        DB::table('output_access_logs')->insert([
            'output_type' => 'TIMETABLE_VERSION',
            'source_record_type' => PublishedTimetableVersion::class,
            'source_record_id' => $version->id,
            'student_profile_id' => null,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(),
            'action' => 'PRINT',
            'copy_context' => $version->state === PublishedTimetableVersion::StatePublished ? 'CURRENT' : 'SUPERSEDED_HISTORY',
            'schedule_version' => $version->version,
            'filter_summary' => json_encode(['term_id' => $version->term_id, 'timetable_version_id' => $version->id], JSON_THROW_ON_ERROR),
            'row_count' => $meetings->count(),
            'purpose' => 'Authorized user opened an exact-Term immutable timetable version for print or PDF.',
            'sensitivity' => 'academic_schedule',
            'stored_file_reference' => null,
            'request_context' => json_encode(['ip' => $request->ip(), 'user_agent' => $request->userAgent(), 'route' => $request->route()?->getName()], JSON_THROW_ON_ERROR),
            'status' => 'logged',
            'occurred_at' => now(),
        ]);

        return [
            'title' => 'Published Timetable',
            'owner' => 'Registrar and Academic Head copy',
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'version_label' => $version->term?->label.' · Timetable v'.$version->version,
            'version_state' => $version->state,
            'rows' => $meetings->map(fn (PublishedTimetableMeeting $meeting): array => [
                'term' => (string) $version->term?->label,
                'course' => (string) $meeting->schedulingDemand?->courseComponent?->courseSpecification?->course?->code,
                'description' => (string) $meeting->schedulingDemand?->courseComponent?->courseSpecification?->title,
                'section' => (string) $meeting->classOffering?->code,
                'component' => str((string) $meeting->schedulingDemand?->courseComponent?->component_type)->headline()->toString(),
                'day' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
                'time' => $this->timeRange($meeting),
                'room' => $this->roomLabel($meeting),
                'modality' => SectionMeeting::modalityOptions()[$meeting->modality] ?? str((string) $meeting->modality)->headline()->toString(),
                'faculty' => (string) $meeting->faculty?->name,
            ])->all(),
        ];
    }

    private function timeRange(PublishedTimetableMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    private function roomLabel(PublishedTimetableMeeting $meeting): string
    {
        $room = $meeting->getRelation('room');

        return $room instanceof Room
            ? (string) $room->code
            : (string) ($meeting->location_label ?? 'TBA');
    }
}
