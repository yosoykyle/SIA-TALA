<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\AdmissionsAcceptanceSeeder;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdmissionsAcceptanceSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coordinated_synthetic_cycle_is_complete_published_and_idempotent(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-12-20',
        ]);
        foreach (['DBM', 'DIT', 'DTHM'] as $code) {
            Program::factory()->create(['code' => $code, 'is_active' => true]);
        }
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $this->seed(AdmissionsAcceptanceSeeder::class);
        $this->seed(AdmissionsAcceptanceSeeder::class);

        $cycle = AdmissionCycle::query()->where('code', 'CYCLE-2026-A')->sole();

        $this->assertSame(AdmissionCycle::StatePublished, $cycle->state);
        $this->assertSame(3, $cycle->programs()->count());
        $this->assertSame(2, $cycle->requirementSets()->count());
        $this->assertSame(2, $cycle->requirementSets()
            ->where('state', AdmissionRequirementSet::StatePublished)
            ->count());
        $this->assertSame(5, $cycle->requirementSets()
            ->withCount('requirements')
            ->get()
            ->sum('requirements_count'));
        $this->assertSame(
            '2026-09-30 17:00',
            $cycle->closes_at->timezone('Asia/Manila')->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-10-07 17:00',
            $cycle->correction_closes_at->timezone('Asia/Manila')->format('Y-m-d H:i'),
        );
        $this->assertSame(1, AdmissionCycle::query()->where('code', 'CYCLE-2026-A')->count());
    }

    public function test_complete_client_acceptance_baseline_includes_the_canonical_admissions_cycle(): void
    {
        Storage::fake('local');
        $seeder = app(ClientAlignedAcceptanceBaselineSeeder::class);

        $this->assertSame(ClientAlignedAcceptanceBaselineSeeder::StateEmpty, $seeder->state());

        $seeder->run();

        $this->assertSame(ClientAlignedAcceptanceBaselineSeeder::StateComplete, $seeder->state());
        $this->assertDatabaseHas('admission_cycles', [
            'code' => 'CYCLE-2026-A',
            'state' => AdmissionCycle::StatePublished,
        ]);
        $this->assertSame(User::StatusActive, User::query()
            ->where('email', 'applicant.demo@example.test')
            ->sole()
            ->status);
    }
}
