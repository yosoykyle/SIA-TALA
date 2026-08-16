<?php

namespace Tests\Feature;

use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BAdmissionsClarityAndNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
    }

    public function test_canonical_admissions_resources_use_plain_operating_labels(): void
    {
        $this->assertSame('Admissions', AdmissionApplicationResource::getNavigationLabel());
        $this->assertSame('Admission Cycles', AdmissionCycleResource::getNavigationLabel());
    }

    public function test_applicant_home_explains_the_safe_empty_state_without_handover_language(): void
    {
        $applicant = $this->applicant();

        Livewire::actingAs($applicant)
            ->test(Dashboard::class)
            ->assertSee('No application yet')
            ->assertSee('one Application per published Admission Cycle')
            ->assertDontSee('Approved for handover')
            ->assertDontSee('payment unlock');
    }

    public function test_requirements_explain_when_no_version_bound_set_exists(): void
    {
        $applicant = $this->applicant();

        Livewire::actingAs($applicant)
            ->test(Requirements::class)
            ->assertSee('Requirements are not available yet')
            ->assertSee('version-bound Requirement Set')
            ->assertSee('Open application')
            ->assertDontSee('Upload replacement');
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
