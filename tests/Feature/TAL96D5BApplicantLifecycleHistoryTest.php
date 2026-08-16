<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Dashboard;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BApplicantLifecycleHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
    }

    public function test_applicant_account_exposes_all_applications_and_resolves_the_current_nonwithdrawn_record(): void
    {
        $applicant = $this->applicant();
        AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->create([
                'application_state' => AdmissionApplication::StateWithdrawn,
                'created_at' => now()->subDay(),
            ]);
        $current = AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->create([
                'application_state' => AdmissionApplication::StateDraft,
                'created_at' => now(),
            ]);

        $this->assertSame(2, $applicant->fresh()->admissionApplications()->count());
        $this->assertSame($current->id, $applicant->fresh()->currentAdmissionApplication?->id);
        $this->assertSame(User::StatusActive, $applicant->fresh()->status);
    }

    public function test_database_allows_only_one_application_per_account_and_cycle(): void
    {
        $applicant = $this->applicant();
        $cycle = AdmissionCycle::factory()->create();

        AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->for($cycle, 'admissionCycle')
            ->create();

        $this->expectException(QueryException::class);

        AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->for($cycle, 'admissionCycle')
            ->create();
    }

    public function test_dashboard_lists_only_the_authenticated_applicants_history(): void
    {
        $applicant = $this->applicant();
        $own = AdmissionApplication::factory()
            ->for($applicant, 'user')
            ->create([
                'application_reference' => 'APP-OWN-0001',
                'application_state' => AdmissionApplication::StateWithdrawn,
            ]);
        $other = AdmissionApplication::factory()->create([
            'application_reference' => 'APP-OTHER-0001',
        ]);

        Livewire::actingAs($applicant)
            ->test(Dashboard::class)
            ->assertSee($own->application_reference)
            ->assertDontSee($other->application_reference);
    }

    private function applicant(): User
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        return $applicant;
    }
}
