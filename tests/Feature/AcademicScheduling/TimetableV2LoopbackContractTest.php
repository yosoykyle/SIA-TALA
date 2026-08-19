<?php

namespace Tests\Feature\AcademicScheduling;

use App\Models\ScheduleGenerationRun;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TimetableV2LoopbackContractTest extends TestCase
{
    public function test_timetable_v2_source_round_trips_through_the_temporary_loopback_service(): void
    {
        $url = trim((string) env('TALA_SLICE3_LOOPBACK_URL'));

        if ($url === '') {
            $this->markTestSkipped('Set TALA_SLICE3_LOOPBACK_URL only for bounded Slice 3 loopback verification.');
        }

        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $url);
        $snapshot = json_decode(
            file_get_contents(base_path('cloud/scheduler-solver/samples/minimal_snapshot.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $snapshot['run_metadata']['solver_run_id'] = 21001;

        $response = Http::timeout(30)->acceptJson()->post($url.'/solve', $snapshot);

        $response->throw();
        $result = $response->json();

        $this->assertSame(ScheduleGenerationRun::ContractVersion, $result['model_version']);
        $this->assertSame(ScheduleGenerationRun::SolverVersion, $result['solver_version']);
        $this->assertContains($result['solver_status'], ['optimal', 'feasible']);
        $this->assertSame(2, $result['assigned_count']);
        $this->assertSame(0, $result['unassigned_count']);
        $this->assertCount(2, $result['assignments']);
        $this->assertSame(
            ['cohort_mode_switches', 'cohort_idle_time', 'faculty_load_imbalance', 'faculty_idle_time', 'room_seat_waste', 'stable_earlier_placement'],
            $result['objective_details']['objective_hierarchy'],
        );
    }
}
