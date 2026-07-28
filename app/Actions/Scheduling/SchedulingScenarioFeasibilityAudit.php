<?php

namespace App\Actions\Scheduling;

use DateTimeImmutable;

/**
 * Calculates inexpensive necessary conditions for a scheduling snapshot.
 *
 * A passing result removes obvious capacity contradictions but does not prove
 * that the full CP-SAT model is feasible or optimal.
 */
final class SchedulingScenarioFeasibilityAudit
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     passes_necessary_conditions:bool,
     *     room_type_capacity:list<array<string, int|float|string>>,
     *     demands_without_faculty:list<int|string>,
     *     demands_without_room_type:list<int|string>,
     *     demands_exceeding_daily_window:list<int|string>,
     *     limitation:string
     * }
     */
    public function assess(array $snapshot): array
    {
        $term = is_array($snapshot['term'] ?? null) ? $snapshot['term'] : [];
        $days = is_array($term['scheduling_days'] ?? null) ? $term['scheduling_days'] : [];
        $dailyMinutes = $this->minutesBetween(
            (string) ($term['scheduling_day_starts_at'] ?? ''),
            (string) ($term['scheduling_day_ends_at'] ?? ''),
        );
        $weeklyMinutesPerRoom = count($days) * $dailyMinutes;
        $rooms = is_array($snapshot['rooms'] ?? null) ? $snapshot['rooms'] : [];
        $demands = is_array($snapshot['scheduling_demands'] ?? null)
            ? $snapshot['scheduling_demands']
            : [];
        $roomCounts = collect($rooms)
            ->filter(fn (mixed $room): bool => is_array($room))
            ->countBy(fn (array $room): string => (string) ($room['room_type'] ?? ''));
        $requiredMinutes = [];
        $withoutFaculty = [];
        $withoutRoomType = [];
        $exceedingWindow = [];

        foreach ($demands as $index => $demand) {
            if (! is_array($demand)) {
                continue;
            }

            $identity = $demand['scheduling_demand_id'] ?? $demand['demand_key'] ?? $index;
            $duration = max(0, (int) ($demand['required_duration_minutes'] ?? 0));
            $eligibleFaculty = $demand['eligible_faculty_user_ids'] ?? [];

            if (! is_array($eligibleFaculty) || $eligibleFaculty === []) {
                $withoutFaculty[] = $identity;
            }

            if ($duration > $dailyMinutes) {
                $exceedingWindow[] = $identity;
            }

            if (($demand['room_required'] ?? false) !== true) {
                continue;
            }

            $roomType = trim((string) ($demand['room_type_requirement'] ?? ''));

            if ($roomType === '') {
                $withoutRoomType[] = $identity;

                continue;
            }

            $requiredMinutes[$roomType] = ($requiredMinutes[$roomType] ?? 0) + $duration;
        }

        ksort($requiredMinutes);

        $capacity = collect($requiredMinutes)
            ->map(function (int $minutes, string $roomType) use ($roomCounts, $weeklyMinutesPerRoom): array {
                $roomCount = (int) $roomCounts->get($roomType, 0);
                $available = $roomCount * $weeklyMinutesPerRoom;
                $slack = $available - $minutes;

                return [
                    'room_type' => $roomType,
                    'room_count' => $roomCount,
                    'required_minutes' => $minutes,
                    'available_minutes' => $available,
                    'slack_minutes' => $slack,
                    'utilization_percent' => $available === 0
                        ? 0.0
                        : round(($minutes / $available) * 100, 2),
                    'status' => $available > 0 && $slack >= 0 ? 'PASS' : 'FAIL',
                ];
            })
            ->values()
            ->all();
        $capacityPasses = collect($capacity)->every(
            fn (array $row): bool => $row['status'] === 'PASS',
        );

        return [
            'passes_necessary_conditions' => $dailyMinutes > 0
                && $days !== []
                && $capacityPasses
                && $withoutFaculty === []
                && $withoutRoomType === []
                && $exceedingWindow === [],
            'room_type_capacity' => $capacity,
            'demands_without_faculty' => $withoutFaculty,
            'demands_without_room_type' => $withoutRoomType,
            'demands_exceeding_daily_window' => $exceedingWindow,
            'limitation' => 'These are necessary aggregate-capacity and input-readiness checks. A passing result does not prove that the interacting CP-SAT constraints are feasible or optimal.',
        ];
    }

    private function minutesBetween(string $startsAt, string $endsAt): int
    {
        $start = DateTimeImmutable::createFromFormat('!H:i:s', $startsAt);
        $end = DateTimeImmutable::createFromFormat('!H:i:s', $endsAt);

        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable || $end <= $start) {
            return 0;
        }

        return (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
    }
}
