<?php

namespace App\Support;

use App\Models\SectionMeeting;
use App\Models\TermCalendarPackage;
use Carbon\CarbonImmutable;

class TeachingWeekSummary
{
    /**
     * @return array<int, array{day: string, summary: string, segments: array<int, array{type: string, label: string, time: string, left: float, width: float}>}>
     */
    public function rows(TermCalendarPackage $package): array
    {
        return $package->teachingGridRows
            ->sortBy('day_of_week')
            ->map(function ($row): array {
                $start = CarbonImmutable::parse($row->starts_at);
                $end = CarbonImmutable::parse($row->ends_at);
                $startMinute = ((int) $start->format('G') * 60) + (int) $start->format('i');
                $endMinute = ((int) $end->format('G') * 60) + (int) $end->format('i');
                $duration = $endMinute - $startMinute;
                $breaks = collect($row->breaks ?? [])->map(function (array $break): array {
                    $breakStart = CarbonImmutable::parse($break['starts_at']);
                    $breakEnd = CarbonImmutable::parse($break['ends_at']);

                    return [
                        'start' => ((int) $breakStart->format('G') * 60) + (int) $breakStart->format('i'),
                        'end' => ((int) $breakEnd->format('G') * 60) + (int) $breakEnd->format('i'),
                    ];
                })->sortBy('start')->values();
                $segments = [];
                $cursor = $startMinute;
                $addSegment = function (string $type, int $segmentStart, int $segmentEnd) use (&$segments, $startMinute, $duration): void {
                    if ($segmentEnd <= $segmentStart || $duration <= 0) {
                        return;
                    }

                    $startTime = CarbonImmutable::createFromTime(intdiv($segmentStart, 60), $segmentStart % 60);
                    $endTime = CarbonImmutable::createFromTime(intdiv($segmentEnd, 60), $segmentEnd % 60);
                    $label = $type === 'break' ? 'Break' : 'Teaching hours';

                    $segments[] = [
                        'type' => $type,
                        'label' => $label,
                        'time' => $startTime->format('g:i A').'–'.$endTime->format('g:i A'),
                        'left' => round((($segmentStart - $startMinute) / $duration) * 100, 3),
                        'width' => round((($segmentEnd - $segmentStart) / $duration) * 100, 3),
                    ];
                };

                foreach ($breaks as $break) {
                    $breakStart = max($startMinute, min($endMinute, $break['start']));
                    $breakEnd = max($startMinute, min($endMinute, $break['end']));

                    if ($breakStart >= $breakEnd || $breakEnd <= $cursor) {
                        continue;
                    }

                    $breakStart = max($breakStart, $cursor);
                    $addSegment('teaching', $cursor, $breakStart);
                    $addSegment('break', $breakStart, $breakEnd);
                    $cursor = $breakEnd;
                }

                $addSegment('teaching', $cursor, $endMinute);

                return [
                    'day' => SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day',
                    'summary' => collect($segments)->map(fn (array $segment): string => $segment['label'].': '.$segment['time'])->implode(' · '),
                    'segments' => $segments,
                ];
            })
            ->values()
            ->all() ?? [];
    }
}
