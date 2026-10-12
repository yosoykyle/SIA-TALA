<?php

namespace App\Support;

use App\Models\CandidateScheduleRow;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TimetablePresentation
{
    private const Days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param  Collection<int, CandidateScheduleRow>  $rows
     * @return array<string, mixed>
     */
    public static function forCandidate(ScheduleGenerationRun $run, Collection $rows): array
    {

        return [...self::project($run->input_snapshot ?? [], $rows, 'Candidate timetable', 'Candidate '.($run->candidate_version ?? 'version unavailable').' · Run '.$run->getKey()), 'synthetic' => (bool) data_get($run->diagnostics, 'synthetic_review', false)];

    }

    /**
     * @param  Collection<int, PublishedTimetableMeeting|SectionMeeting>  $meetings
     * @return array<string, mixed>
     */
    public static function forPublished(PublishedTimetableVersion $version, Collection $meetings): array
    {

        $run = self::related($version, 'scheduleRun');

        return [...self::project($run?->input_snapshot ?? [], $meetings, 'Official timetable', 'Published version '.$version->version.' · '.($version->state ?? 'State unavailable')), 'synthetic' => (bool) data_get($run?->diagnostics, 'synthetic_review', false)];

    }

    /**
     * Projection never queries: callers supply eager-loaded display relations.

     * days: snapshot plus actual days; ticks: 30-minute axis; slots: axis size.

     * meetings: each record once, with top/height percentages and overlap lane/lanes.

     * Invalid records have plottable=false and remain in the equivalent list.

     * filters: faculty/room/class/modality labels. snapshotAvailable records geometry provenance.

     *

     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, Model>  $records
     * @return array<string, mixed>
     */
    private static function project(array $snapshot, Collection $records, string $title, string $source): array
    {

        $term = $snapshot['term'] ?? [];

        $grid = is_array($term['teaching_grid_rows'] ?? null) ? $term['teaching_grid_rows'] : [];

        $breaks = collect($snapshot['calendar_blocks'] ?? [])

            ->filter(fn (array $block): bool => ($block['scope_type'] ?? null) === 'Institution'

                && empty($block['faculty_user_id']) && empty($block['room_id'])

                && isset(self::Days[(int) ($block['day_of_week'] ?? 0)]))

            ->map(fn (array $block): array => ['day' => (int) $block['day_of_week'],

                'start' => self::minutes($block['starts_at'] ?? null), 'end' => self::minutes($block['ends_at'] ?? null),

                'label' => ($block['event_type'] ?? null) === 'TeachingGridBreak' ? 'Approved break' : 'Institution unavailable'])

            ->filter(fn (array $block): bool => $block['start'] !== null && $block['end'] !== null && $block['end'] > $block['start'])

            ->values()->all();

        $snapshotDays = collect($term['scheduling_days'] ?? [])->merge(array_column($grid, 'day_of_week'))

            ->map(fn (mixed $day): int => (int) $day)->filter(fn (int $day): bool => isset(self::Days[$day]));

        $snapshotAvailable = $snapshotDays->isNotEmpty() && ($grid !== [] || (self::minutes($term['scheduling_day_starts_at'] ?? null) !== null && self::minutes($term['scheduling_day_ends_at'] ?? null) !== null));

        $days = clone $snapshotDays;

        $capturedDemands = collect($snapshot['scheduling_demands'] ?? [])->keyBy('scheduling_demand_id');

        $meetings = $records->values()->map(function (Model $record, int $index) use ($capturedDemands, $term): array {

            $meeting = self::meeting($record, $index);

            $demand = self::related($record, 'schedulingDemand');

            $class = self::related($record, 'classOffering') ?? self::related(self::related($demand, 'sectionDeliveryGroup'), 'section');

            $captured = $capturedDemands->get($record->getAttribute('scheduling_demand_id'), []);

            $cohortIds = $captured['cohort_or_student_group_ids'] ?? [];

            $cohorts = $class && $class->relationLoaded('cohorts') ? $class->cohorts : collect();

            $entry = self::related(self::related($demand, 'termOffering'), 'curriculumEntry');

            $curriculum = self::related($entry, 'curriculumVersion');

            $program = self::related($curriculum, 'program');

            $meeting['sections'] = collect($cohortIds)->map(function ($id) use ($cohorts, $captured, $demand, $class, $term): array {
                $cohort = $cohorts->firstWhere('id', $id);
                $cohortProgram = self::related($cohort, 'program');
                $cohortCurriculum = self::related($cohort, 'curriculumVersion');
                $component = self::related($demand, 'courseComponent');
                $revision = self::related($component, 'courseSpecification') ?? self::related($class, 'courseRevision');
                $courseId = $captured['course_id'] ?? $revision?->course_id;
                $years = $cohortCurriculum && $cohortCurriculum->relationLoaded('entries')
                    ? $cohortCurriculum->entries->filter(fn ($entry): bool => $entry->term_type === ($term['type'] ?? null)
                        && (int) self::related($entry, 'courseSpecification')?->course_id === (int) $courseId)
                        ->pluck('year_level')->filter()->unique()->values()->all() : [];

                return ['id' => (string) $id, 'label' => $cohort?->reference ?? 'Captured student group #'.$id,
                    'program' => $cohortProgram?->name ?? 'Program not associated', 'years' => $years,
                    'year' => count($years) === 1 ? $years[0] : ($years === [] ? 'Year not associated' : 'Multiple curriculum years')];
            })->all();
            if ($meeting['sections'] === []) {
                $meeting['sections'] = [['id' => 'unassociated-'.$meeting['class'], 'label' => $meeting['class'].' - student group not captured', 'program' => 'Program not associated', 'years' => [], 'year' => 'Year not associated']];
            }

            return $meeting;

        })->all();

        $bounds = [self::minutes($term['scheduling_day_starts_at'] ?? null), self::minutes($term['scheduling_day_ends_at'] ?? null)];

        foreach ($grid as $interval) {

            $bounds[] = self::minutes($interval['starts_at'] ?? null);

            $bounds[] = self::minutes($interval['ends_at'] ?? null);

        }

        foreach ($meetings as &$meeting) {

            if (isset(self::Days[$meeting['day']])) {

                $days->push($meeting['day']);

            }

            if ($meeting['plottable']) {

                $bounds[] = $meeting['start'];

                $bounds[] = $meeting['end'];

            }

            $meeting['offGrid'] = $meeting['plottable'] && self::outsideGrid($meeting, $term, $grid);

            foreach ($breaks as $break) {

                if ($meeting['plottable'] && $meeting['day'] === $break['day'] && $meeting['start'] < $break['end'] && $meeting['end'] > $break['start']) {

                    $meeting['offGrid'] = true;

                }

            }

        }

        unset($meeting);

        $bounds = array_values(array_filter($bounds, fn (?int $value): bool => $value !== null));

        $start = $bounds === [] ? null : (int) (floor(min($bounds) / 30) * 30);

        $end = $bounds === [] ? null : (int) (ceil(max($bounds) / 30) * 30);

        $slots = $start !== null && $end > $start ? (int) (($end - $start) / 30) : 0;

        $ticks = [];

        for ($slot = 0; $slot <= $slots && $slots > 0; $slot++) {

            $ticks[] = ['label' => self::clock($start + $slot * 30), 'offset' => $slot / $slots * 100];

        }

        usort($meetings, fn (array $a, array $b): int => [$a['day'], $a['start'] ?? 1441, $a['end'] ?? 1441, $a['id']] <=> [$b['day'], $b['start'] ?? 1441, $b['end'] ?? 1441, $b['id']]);

        foreach ($meetings as &$meeting) {

            $meeting['top'] = $meeting['plottable'] && $slots > 0 ? ($meeting['start'] - $start) / ($end - $start) * 100 : 0;

            $meeting['height'] = $meeting['plottable'] && $slots > 0 ? ($meeting['end'] - $meeting['start']) / ($end - $start) * 100 : 0;

        }

        unset($meeting);

        self::assignLanes($meetings);

        $blockedPeriods = [];

        foreach ($breaks as $break) {

            if ($slots > 0 && $break['start'] < $end && $break['end'] > $start) {

                $blockedPeriods[] = [...$break, 'time' => self::clock($break['start']).'–'.self::clock($break['end']),

                    'top' => (max($break['start'], $start) - $start) / ($end - $start) * 100,

                    'height' => (min($break['end'], $end) - max($break['start'], $start)) / ($end - $start) * 100];

            }

        }

        $filters = [];

        foreach (['faculty', 'room', 'class', 'modality'] as $field) {

            $filters[$field] = collect($meetings)->pluck($field)->unique()->sort()->values()->all();

        }

        return ['title' => $title, 'source' => $source, 'snapshotAvailable' => $snapshotAvailable,

            'days' => $days->unique()->sort()->map(fn (int $day): array => ['number' => $day, 'label' => self::Days[$day]])->values()->all(),

            'ticks' => $ticks, 'slots' => $slots, 'blockedPeriods' => $blockedPeriods, 'meetings' => $meetings, 'filters' => $filters];

    }

    /** @return array<string, mixed> */
    private static function meeting(Model $record, int $index): array
    {

        $demand = self::related($record, 'schedulingDemand');

        $component = self::related($demand, 'courseComponent');

        $section = self::related($record, 'classOffering') ?? self::related(self::related($demand, 'sectionDeliveryGroup'), 'section');

        $specification = self::related($component, 'courseSpecification') ?? self::related($section, 'courseRevision')

            ?? self::related(self::related(self::related($demand, 'termOffering'), 'curriculumEntry'), 'courseSpecification');

        $course = self::related($specification, 'course');

        $faculty = self::related($record, 'faculty');

        $room = self::related($record, 'room');

        $source = $demand?->source_snapshot ?? [];

        $day = (int) $record->day_of_week;

        $start = self::minutes($record->starts_at);

        $end = self::minutes($record->ends_at);

        $modality = $record->getAttribute('modality') ?? $demand?->modality;

        return [

            'id' => (string) ($record->getKey() ?? 'meeting-'.$index),

            'day' => $day, 'dayLabel' => self::Days[$day] ?? 'Day unavailable', 'start' => $start, 'end' => $end,

            'time' => $start !== null && $end !== null ? self::clock($start).'–'.self::clock($end) : 'Time unavailable',

            'duration' => $start !== null && $end !== null && $end > $start ? self::duration($end - $start) : 'Unavailable',

            'courseCode' => (string) ($course?->code ?? $source['course_code'] ?? 'Course unavailable'),

            'courseTitle' => (string) ($specification?->title ?? ''),

            'class' => (string) ($section?->class_reference ?? $section?->code ?? 'Class unavailable'),

            'faculty' => (string) ($faculty?->name ?? 'Faculty unassigned'),

            'room' => (string) ($room?->code ?? $record->getAttribute('location_label') ?? ($modality === 'ONLINE' ? 'Online' : 'Room unassigned')),

            'modality' => match ($modality) {

                'FACE_TO_FACE' => 'Face to face', 'ONLINE' => 'Online', default => 'Modality unavailable'

            },

            'component' => match ($component?->component_type ?? $source['component_type'] ?? null) {

                'LECTURE' => 'Lecture', 'LABORATORY' => 'Laboratory', default => 'Meeting'

            },

            'status' => match ($record->getAttribute('status')) {

                CandidateScheduleRow::StatusOk => 'No recorded issue',

                CandidateScheduleRow::StatusWarning => 'Warning recorded',

                CandidateScheduleRow::StatusConflict => 'Conflict recorded',

                null => 'Published',

                default => (string) $record->getAttribute('status'),

            },

            'plottable' => isset(self::Days[$day]) && $start !== null && $end !== null && $end > $start, 'lane' => 0, 'lanes' => 1,

        ];

    }

    /** @param list<array<string, mixed>> $meetings */
    private static function assignLanes(array &$meetings): void
    {

        $group = [];

        $laneEnds = [];

        $day = null;

        $groupEnd = null;

        foreach ($meetings as $index => $meeting) {

            if (! $meeting['plottable']) {

                continue;

            }

            if ($meeting['day'] !== $day || $meeting['start'] >= $groupEnd) {

                foreach ($group as $member) {

                    $meetings[$member]['lanes'] = count($laneEnds);

                }

                $group = [];

                $laneEnds = [];

                $day = $meeting['day'];

                $groupEnd = $meeting['end'];

            }

            $lane = 0;

            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $meeting['start']) {

                $lane++;

            }

            $laneEnds[$lane] = $meeting['end'];

            $meetings[$index]['lane'] = $lane;

            $group[] = $index;

            $groupEnd = max($groupEnd, $meeting['end']);

        }

        foreach ($group as $member) {

            $meetings[$member]['lanes'] = count($laneEnds);

        }

    }

    /** @param array<string, mixed> $meeting @param array<string, mixed> $term @param list<array<string, mixed>> $grid */
    private static function outsideGrid(array $meeting, array $term, array $grid): bool
    {

        if ($grid !== []) {

            $intervals = collect($grid)->where('day_of_week', $meeting['day'])->sortBy('starts_at');

            $coveredUntil = $meeting['start'];

            foreach ($intervals as $interval) {

                $start = self::minutes($interval['starts_at'] ?? null);

                $end = self::minutes($interval['ends_at'] ?? null);

                if ($start !== null && $end !== null && $start <= $coveredUntil) {

                    $coveredUntil = max($coveredUntil, $end);

                }

            }

            return $coveredUntil < $meeting['end'];

        }

        $start = self::minutes($term['scheduling_day_starts_at'] ?? null);

        $end = self::minutes($term['scheduling_day_ends_at'] ?? null);

        return (isset($term['scheduling_days']) && ! in_array($meeting['day'], array_map('intval', $term['scheduling_days']), true))

            || ($start !== null && $meeting['start'] < $start) || ($end !== null && $meeting['end'] > $end);

    }

    private static function related(?Model $model, string $relation): ?Model
    {

        return $model?->relationLoaded($relation) ? $model->getRelation($relation) : null;

    }

    private static function duration(int $minutes): string
    {

        $hours = intdiv($minutes, 60);

        $remaining = $minutes % 60;

        return trim(($hours > 0 ? $hours.'h ' : '').($remaining > 0 ? $remaining.'m' : ''));

    }

    private static function minutes(mixed $time): ?int
    {

        if (! is_string($time) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $parts) !== 1) {

            return null;

        }

        $hours = (int) $parts[1];

        $minutes = (int) $parts[2];

        return $hours <= 23 && $minutes < 60 ? $hours * 60 + $minutes : null;

    }

    private static function clock(int $minutes): string
    {

        $hour = intdiv($minutes, 60);

        return sprintf('%d:%02d %s', $hour % 12 ?: 12, $minutes % 60, $hour < 12 ? 'AM' : 'PM');

    }
}
