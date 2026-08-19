<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $runs = DB::table('schedule_runs')
                ->whereIn('status', ['published', 'superseded'])
                ->whereNotNull('published_at')
                ->whereNotNull('published_by')
                ->orderBy('term_id')
                ->orderBy('published_at')
                ->orderBy('id')
                ->get();

            foreach ($runs as $run) {
                if (DB::table('published_timetable_versions')->where('schedule_run_id', $run->id)->exists()) {
                    continue;
                }

                $meetings = DB::table('section_meetings as meetings')
                    ->join('scheduling_demands as demands', 'demands.id', '=', 'meetings.scheduling_demand_id')
                    ->join('section_delivery_groups as groups', 'groups.id', '=', 'demands.section_delivery_group_id')
                    ->where('meetings.schedule_run_id', $run->id)
                    ->orderBy('meetings.id')
                    ->get([
                        'meetings.id', 'meetings.scheduling_demand_id', 'meetings.meeting_sequence',
                        'meetings.faculty_user_id', 'meetings.room_id', 'meetings.day_of_week',
                        'meetings.starts_at', 'meetings.ends_at', 'meetings.modality',
                        'groups.section_id',
                    ]);

                if ($meetings->isEmpty()) {
                    continue;
                }

                $version = max(
                    (int) ($run->publication_version ?? 0),
                    ((int) DB::table('published_timetable_versions')->where('term_id', $run->term_id)->max('version')) + 1,
                );
                $payload = $meetings->map(fn (object $meeting): array => [
                    'section_id' => (int) $meeting->section_id,
                    'scheduling_demand_id' => (int) $meeting->scheduling_demand_id,
                    'faculty_user_id' => (int) $meeting->faculty_user_id,
                    'room_id' => $meeting->room_id !== null ? (int) $meeting->room_id : null,
                    'meeting_sequence' => (int) $meeting->meeting_sequence,
                    'day_of_week' => (int) $meeting->day_of_week,
                    'starts_at' => (string) $meeting->starts_at,
                    'ends_at' => (string) $meeting->ends_at,
                    'modality' => (string) $meeting->modality,
                ])->all();
                $timestamp = now();
                $previousVersion = DB::table('published_timetable_versions')
                    ->where('term_id', $run->term_id)
                    ->where('published_at', '<=', $run->published_at)
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->first();
                $timetableVersionId = DB::table('published_timetable_versions')->insertGetId([
                    'term_id' => $run->term_id,
                    'schedule_run_id' => $run->id,
                    'supersedes_version_id' => $previousVersion?->id,
                    'version' => $version,
                    'state' => $run->status === 'published' ? 'Published' : 'Superseded',
                    'authority_reference' => filled($run->publication_note) ? $run->publication_note : "Legacy published schedule run {$run->id}",
                    'publication_reason' => 'Forward-only attributable baseline of the pre-Slice-3 published state.',
                    'source_versions' => json_encode(['legacy_schedule_run_id' => (int) $run->id], JSON_THROW_ON_ERROR),
                    'impact_summary' => json_encode(['legacy_baseline' => true], JSON_THROW_ON_ERROR),
                    'content_hash' => hash('sha256', json_encode(['term_id' => (int) $run->term_id, 'version' => $version, 'meetings' => $payload], JSON_THROW_ON_ERROR)),
                    'published_by' => $run->published_by,
                    'published_at' => $run->published_at,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                foreach ($meetings as $index => $meeting) {
                    $supersededMeetingId = $previousVersion === null
                        ? null
                        : DB::table('published_timetable_meetings')
                            ->where('published_timetable_version_id', $previousVersion->id)
                            ->where('scheduling_demand_id', $meeting->scheduling_demand_id)
                            ->where('meeting_sequence', $meeting->meeting_sequence)
                            ->value('id');
                    DB::table('published_timetable_meetings')->insert([
                        ...$payload[$index],
                        'published_timetable_version_id' => $timetableVersionId,
                        'location_label' => $meeting->room_id === null ? 'Online or assigned location' : "Room #{$meeting->room_id}",
                        'supersedes_meeting_id' => $supersededMeetingId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                    DB::table('section_meetings')->where('id', $meeting->id)->update([
                        'published_timetable_version_id' => $timetableVersionId,
                        'updated_at' => $timestamp,
                    ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only evidence migration: later authoritative versions must never be erased.
    }
};
