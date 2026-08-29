<?php

namespace Tests\Feature;

use App\Actions\Applicants\AdmissionWindowService;
use App\Models\AdmissionCycle;
use App\Models\Program;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PublicGatewayProjectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_missing_and_unavailable_admissions_are_not_reported_as_closed(): void
    {
        $this->get('/')->assertOk()->assertSee('No published admission cycle is available')
            ->assertDontSee('Applications are currently closed');

        $this->mock(AdmissionWindowService::class)->shouldReceive('currentCycle')
            ->andThrow(new QueryException('mysql', 'select admission cycle', [], new RuntimeException('Synthetic source failure')));
        $this->get('/')->assertOk()->assertSee('Admission availability could not be checked')
            ->assertDontSee('Applications are currently closed')
            ->assertSee(route('filament.applicant.auth.login'), false);
    }

    public function test_active_programs_are_not_all_presented_as_accepting(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create(['opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
        $accepting = Program::factory()->create(['is_active' => true]);
        $other = Program::factory()->create(['is_active' => true]);
        $inactive = Program::factory()->create(['is_active' => false]);
        $cycle->programs()->attach($accepting, ['accepts_first_year' => true, 'accepts_transferee' => false]);

        $this->get('/')->assertOk()->assertSee($accepting->name)->assertSee($other->name)
            ->assertDontSee($inactive->name)->assertViewHas('acceptingProgramIds', [$accepting->id])
            ->assertSee('No current application intake confirmed');
    }

    public function test_gateway_uses_canonical_order_and_current_public_terminology(): void
    {
        $this->get('/')->assertOk()->assertSeeInOrder(['id="login"', 'id="programs"', 'id="notices"', 'id="faq"', 'id="location"'], false)
            ->assertSee('Staff Workspace')->assertDontSee('System Administrator')
            ->assertDontSee('System Super Admin')
            ->assertDontSee('handed-over students');
    }

    public function test_future_and_closed_cycles_keep_existing_account_entry(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create(['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Upcoming')->assertSee('This published intake has not opened');
        $cycle->update(['opens_at' => now()->subDays(2), 'closes_at' => now()->subDay()]);
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Closed')->assertSee('Applications are currently closed')
            ->assertSee(route('filament.applicant.auth.login'), false);
    }
}
