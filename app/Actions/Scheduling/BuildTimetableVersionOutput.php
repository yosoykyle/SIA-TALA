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
    /** @return array<string, mixed> */
    public function execute(PublishedTimetableVersion $version, User $actor, Request $request): array
    {
        abort_unless($actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]), 403);
        $version->loadMissing(['term.academicYear', 'scheduleRun']);
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

        abort_if($meetings->isEmpty(), 409, 'This timetable version has no complete published meeting set to print.');

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
            'title' => 'PUBLISHED TIMETABLE',
            'is_timetable_output' => true,
            'page_margin' => '12mm',
            'issuer' => (string) config('institution.name'),
            'owner' => $actor->hasRole(User::StaffRoleRegistrar) ? 'Registrar copy' : 'Academic Head oversight copy',
            'role_filter_context' => $actor->hasRole(User::StaffRoleRegistrar) ? 'All published classes' : 'Read-only academic oversight',
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'solver_generated_at' => $this->solverGeneratedAt($version),
            'published_at' => DisplayDateTime::format(CarbonImmutable::parse((string) $version->published_at), 'F j, Y g:i A'),
            'academic_year' => (string) $version->term?->academicYear?->label,
            'term_label' => (string) $version->term?->label,
            'reference' => sprintf('TALA-TT-%d-V%d', $version->id, $version->version),
            'authority_reference' => (string) $version->authority_reference,
            'version_label' => 'Timetable v'.$version->version,
            'version_state' => $version->state,
            'identity_line' => implode(' · ', array_filter([
                (string) config('institution.name'),
                (string) $version->term?->academicYear?->label,
                (string) $version->term?->label,
                'Timetable v'.$version->version,
                $version->state,
            ])),
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
                'revision_marker' => $meeting->supersedes_meeting_id !== null ? 'Revised' : 'Initial publication',
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

    private function solverGeneratedAt(PublishedTimetableVersion $version): string
    {
        $generatedAt = data_get($version->scheduleRun?->diagnostics, 'solver_result.generated_at');

        if (! is_string($generatedAt) || trim($generatedAt) === '') {
            return 'Not recorded';
        }

        try {
            return DisplayDateTime::format(CarbonImmutable::parse($generatedAt), 'F j, Y g:i A');
        } catch (\Throwable) {
            return 'Not recorded';
        }
    }
}
