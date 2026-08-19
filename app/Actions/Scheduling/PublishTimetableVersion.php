<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionDeliveryGroup;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublishTimetableVersion
{
    /**
     * The caller owns the surrounding Term/run transaction and row locks.
     *
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     * @param  array<string, mixed>  $sourceVersions
     * @param  array<string, mixed>  $impactSummary
     */
    public function createLocked(
        ScheduleGenerationRun $run,
        User $publisher,
        Collection $candidateRows,
        string $authorityReference,
        ?string $reason,
        array $sourceVersions,
        array $impactSummary,
    ): PublishedTimetableVersion {
        $authorityReference = Str::of($authorityReference)->trim()->toString();

        if ($authorityReference === '') {
            throw ValidationException::withMessages([
                'authority_reference' => 'Recorded external timetable sign-off is required before publication.',
            ]);
        }

        $current = PublishedTimetableVersion::query()
            ->where('term_id', $run->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->lockForUpdate()
            ->first();
        $nextVersion = ((int) PublishedTimetableVersion::query()
            ->where('term_id', $run->term_id)
            ->lockForUpdate()
            ->max('version')) + 1;

        $meetingPayloads = $candidateRows
            ->map(function (CandidateScheduleRow $row): array {
                $row->loadMissing(['schedulingDemand.sectionDeliveryGroup', 'room']);
                $group = $row->schedulingDemand?->sectionDeliveryGroup;
                $room = $row->getRelation('room');

                if (! $group instanceof SectionDeliveryGroup) {
                    throw ValidationException::withMessages([
                        'candidate_schedule_rows' => 'Every candidate meeting must resolve to one Class Offering.',
                    ]);
                }

                $modality = (string) $row->schedulingDemand->modality;

                return [
                    'section_id' => (int) $group->section_id,
                    'scheduling_demand_id' => (int) $row->scheduling_demand_id,
                    'faculty_user_id' => (int) $row->faculty_user_id,
                    'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
                    'meeting_sequence' => (int) $row->meeting_sequence,
                    'day_of_week' => (int) $row->day_of_week,
                    'starts_at' => (string) $row->starts_at,
                    'ends_at' => (string) $row->ends_at,
                    'modality' => $modality,
                    'location_label' => $modality === TermOffering::ModalityOnline
                        ? 'Online'
                        : ($room instanceof Room ? (string) $room->code : 'Assigned room'),
                ];
            })
            ->sortBy(['section_id', 'meeting_sequence'])
            ->values();

        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $run->term_id,
            'schedule_run_id' => $run->id,
            'supersedes_version_id' => $current?->id,
            'version' => $nextVersion,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => $authorityReference,
            'publication_reason' => $reason,
            'source_versions' => $sourceVersions,
            'impact_summary' => $impactSummary,
            'content_hash' => hash('sha256', json_encode([
                'term_id' => (int) $run->term_id,
                'version' => $nextVersion,
                'meetings' => $meetingPayloads->all(),
            ], JSON_THROW_ON_ERROR)),
            'published_by' => $publisher->id,
            'published_at' => now(),
        ]);

        foreach ($meetingPayloads as $payload) {
            PublishedTimetableMeeting::query()->create([
                ...$payload,
                'published_timetable_version_id' => $version->id,
                'supersedes_meeting_id' => null,
            ]);
        }

        if ($current instanceof PublishedTimetableVersion) {
            $current->forceFill(['state' => PublishedTimetableVersion::StateSuperseded])->save();
        }

        return $version->fresh('meetings');
    }
}
