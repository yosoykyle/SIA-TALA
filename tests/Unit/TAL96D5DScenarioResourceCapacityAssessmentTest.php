<?php

namespace Tests\Unit;

use App\Actions\Scheduling\SchedulingScenarioFeasibilityAudit;
use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use App\Models\Room;
use PHPUnit\Framework\TestCase;

final class TAL96D5DScenarioResourceCapacityAssessmentTest extends TestCase
{
    public function test_original_max_room_mix_fails_a_necessary_laboratory_capacity_condition(): void
    {
        $audit = (new SchedulingScenarioFeasibilityAudit)->assess(
            $this->snapshot([
                ['room_id' => 1, 'room_type' => 'LECTURE_ROOM'],
                ['room_id' => 2, 'room_type' => 'LECTURE_ROOM'],
                ['room_id' => 3, 'room_type' => 'LECTURE_ROOM'],
                ['room_id' => 4, 'room_type' => 'LABORATORY'],
                ['room_id' => 5, 'room_type' => 'COMPUTER_LABORATORY'],
                ['room_id' => 6, 'room_type' => 'SPECIAL_ROOM'],
            ]),
        );

        $laboratory = collect($audit['room_type_capacity'])
            ->firstWhere('room_type', 'LABORATORY');
        $computer = collect($audit['room_type_capacity'])
            ->firstWhere('room_type', 'COMPUTER_LABORATORY');

        $this->assertFalse($audit['passes_necessary_conditions']);
        $this->assertSame(7_560, $laboratory['required_minutes']);
        $this->assertSame(5_040, $laboratory['available_minutes']);
        $this->assertSame(-2_520, $laboratory['slack_minutes']);
        $this->assertSame('FAIL', $laboratory['status']);
        $this->assertSame(120, $computer['slack_minutes']);
    }

    public function test_corrected_six_room_max_mix_passes_the_bounded_room_capacity_conditions(): void
    {
        $audit = (new SchedulingScenarioFeasibilityAudit)->assess(
            $this->snapshot([
                ['room_id' => 1, 'room_type' => 'LECTURE_ROOM'],
                ['room_id' => 2, 'room_type' => 'LECTURE_ROOM'],
                ['room_id' => 3, 'room_type' => 'LABORATORY'],
                ['room_id' => 4, 'room_type' => 'LABORATORY'],
                ['room_id' => 5, 'room_type' => 'COMPUTER_LABORATORY'],
                ['room_id' => 6, 'room_type' => 'COMPUTER_LABORATORY'],
            ]),
        );

        $byType = collect($audit['room_type_capacity'])->keyBy('room_type');

        $this->assertTrue($audit['passes_necessary_conditions']);
        $this->assertSame(1_500, $byType['LECTURE_ROOM']['slack_minutes']);
        $this->assertSame(2_520, $byType['LABORATORY']['slack_minutes']);
        $this->assertSame(5_160, $byType['COMPUTER_LABORATORY']['slack_minutes']);
        $this->assertSame('PASS', $byType['LABORATORY']['status']);
        $this->assertStringContainsString('necessary', $audit['limitation']);
    }

    public function test_coordinated_catalog_keeps_ten_rooms_with_workload_specific_type_mix(): void
    {
        $catalog = new SchedulingAcceptanceScenarioCatalog;
        $roomTypes = collect($catalog->roomDefinitions(SchedulingAcceptanceScenarioCatalog::Max))
            ->countBy(fn (array $room): string => $room[2]);

        $this->assertCount(10, $catalog->roomDefinitions(SchedulingAcceptanceScenarioCatalog::Max));
        $this->assertSame(4, $roomTypes[Room::TypeLectureRoom]);
        $this->assertSame(3, $roomTypes[Room::TypeLaboratory]);
        $this->assertSame(3, $roomTypes[Room::TypeComputerLaboratory]);
        $this->assertArrayNotHasKey(Room::TypeSpecialRoom, $roomTypes->all());
        $this->assertCount(10, $catalog->roomDefinitions(SchedulingAcceptanceScenarioCatalog::Middle));
    }

    /**
     * @param  list<array{room_id:int,room_type:string}>  $rooms
     * @return array<string, mixed>
     */
    private function snapshot(array $rooms): array
    {
        return [
            'term' => [
                'scheduling_days' => [1, 2, 3, 4, 5, 6],
                'scheduling_day_starts_at' => '07:00:00',
                'scheduling_day_ends_at' => '21:00:00',
            ],
            'rooms' => $rooms,
            'faculty' => [
                ['faculty_id' => 1, 'max_allowed_units' => '21.00'],
            ],
            'scheduling_demands' => [
                ...$this->demands('LECTURE_ROOM', 8_580, 1),
                ...$this->demands('LABORATORY', 7_560, 2),
                ...$this->demands('COMPUTER_LABORATORY', 4_920, 3),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function demands(string $roomType, int $totalMinutes, int $cohort): array
    {
        $demands = [];
        $remaining = $totalMinutes;

        while ($remaining > 0) {
            $duration = min(240, $remaining);
            $demands[] = [
                'scheduling_demand_id' => count($demands) + 1,
                'room_required' => true,
                'room_type_requirement' => $roomType,
                'required_duration_minutes' => $duration,
                'cohort_or_student_group_id' => $cohort,
                'eligible_faculty_user_ids' => [1],
            ];
            $remaining -= $duration;
        }

        return $demands;
    }
}
