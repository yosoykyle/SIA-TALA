<?php

namespace Tests\Feature;

use App\Actions\Applicants\AdmissionWindowService;
use App\Models\AdmissionCycle;
use App\Models\Program;
use App\Models\PublicNotice;
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
        AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->update(['state' => AdmissionCycle::StateDraft]);
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Missing')
            ->assertSee('Ask Admissions about applying')
            ->assertDontSee('Applications are closed')
            ->assertDontSee(route('filament.applicant.auth.register'), false);

        $this->mock(AdmissionWindowService::class)->shouldReceive('currentCycle')
            ->andThrow(new QueryException('mysql', 'select admission cycle', [], new RuntimeException('Synthetic source failure')));
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Unavailable')
            ->assertSee('Admissions information is temporarily unavailable')
            ->assertDontSee('Applications are closed')
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertDontSee(route('filament.applicant.auth.register'), false);
    }

    public function test_active_programs_are_not_all_presented_as_accepting(): void
    {
        $term = Term::query()->where('state', Term::StateActive)->first() ?? Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create(['opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
        $accepting = Program::factory()->create(['is_active' => true]);
        $other = Program::factory()->create(['is_active' => true]);
        $inactive = Program::factory()->create(['is_active' => false]);
        $cycle->programs()->attach($accepting, ['accepts_first_year' => true, 'accepts_transferee' => false]);

        $this->get('/')->assertOk()->assertSee($accepting->name)->assertSee($other->name)
            ->assertDontSee($inactive->name)->assertDontSee($accepting->code)->assertDontSee($other->code)
            ->assertSee($accepting->duration_years.' '.($accepting->duration_years === 1 ? 'year' : 'years'))
            ->assertViewHas('acceptingProgramIds', [$accepting->id])
            ->assertSee('Ask Admissions about applying');
    }

    public function test_gateway_leads_with_school_discovery_and_keeps_shared_sign_in(): void
    {
        $this->get('/')->assertOk()
            ->assertSeeInOrder(['id="top"', 'id="programs"', 'id="faq"', 'id="location"', 'id="application-status"'], false)
            ->assertSee('Servitech Institute Asia Inc.')
            ->assertSee('Explore programs')
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('System Administrator')
            ->assertDontSee('System Super Admin')
            ->assertDontSee('handed-over students')
            ->assertDontSee('id="notices"', false);
    }

    public function test_effective_announcements_follow_programs_and_hide_internal_metadata(): void
    {
        PublicNotice::factory()->create([
            'title' => 'Admission orientation',
            'state' => 'Published',
            'ever_published' => true,
            'published_at' => now(),
        ]);

        $this->get('/')->assertSeeInOrder(['id="top"', 'id="notices"', 'Admissions', 'id="admission-status-title"', 'School announcement', 'Admission orientation', 'data-update-next', 'id="programs"', 'id="application-status"'], false)
            ->assertSee('Announcements')
            ->assertSee('2 of 2')
            ->assertDontSee('System Administration')
            ->assertDontSee('Version 1');
    }

    public function test_approved_map_embed_renders_with_accessible_loading_and_external_fallback(): void
    {
        $this->get('/')->assertSee(config('institution.public.map_embed_url'), false)
            ->assertSee('title="Map showing Servitech Institute Asia Inc. campus location"', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('referrerpolicy="strict-origin-when-cross-origin"', false)
            ->assertSee(config('institution.public.map_url'));
    }

    public function test_missing_or_unsafe_map_embed_keeps_external_location_guidance(): void
    {
        foreach ([
            null,
            '',
            'http://www.google.com/maps/embed?pb=unsafe',
            'https://www.google.com.evil.example/maps/embed?pb=unsafe',
            'https://evil.example/maps/embed?pb=unsafe',
            'https://www.google.com/maps?cid=781880921815418296',
            'https://user@www.google.com/maps/embed?pb=unsafe',
            'https://www.google.com:444/maps/embed?pb=unsafe',
        ] as $reference) {
            config()->set('institution.public.map_embed_url', $reference);

            $this->get('/')->assertDontSee('<iframe', false)
                ->assertSee('Open in Google Maps')
                ->assertSee(config('institution.public.map_url'));
        }
    }

    public function test_invalid_status_request_returns_to_an_open_form_with_entered_values(): void
    {
        $response = $this->from(route('home'))->post(route('applications.status.request'), [
            'reference' => 'app-test-reference',
            'email' => 'invalid-email',
        ]);

        $response->assertRedirect(route('home').'#application-status')
            ->assertSessionHasErrors(['email']);

        $page = $this->get(route('home'))->assertOk()
            ->assertSee('value="app-test-reference"', false)
            ->assertSee('value="invalid-email"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Check the highlighted field, then send the request again. Your entries are kept.')
            ->assertSee('maxlength="40"', false)
            ->assertSee('autocomplete="off"', false);
        $this->assertDoesNotMatchRegularExpression('/<section\b[^>]*\bid="application-status"[^>]*>(?:(?!<\/section>).)*<details\b/s', $page->getContent());
    }

    public function test_status_confirmation_keeps_the_request_form_open(): void
    {
        $page = $this->withSession(['tracking_status' => 'If your details match, a link will be emailed.'])
            ->get(route('home'))->assertOk()
            ->assertSeeInOrder(['id="application-status-title"', 'data-tracking-confirmation', 'If your details match, a link will be emailed.', 'If a link was sent', 'action="'.route('applications.status.request').'"'], false)
            ->assertDontSee('Check the highlighted');
        $this->assertDoesNotMatchRegularExpression('/<section\b[^>]*\bid="application-status"[^>]*>(?:(?!<\/section>).)*<details\b/s', $page->getContent());
    }

    public function test_future_and_closed_cycles_keep_existing_account_entry(): void
    {
        AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->update(['state' => AdmissionCycle::StateDraft]);
        $term = Term::query()->where('state', Term::StateActive)->first() ?? Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create(['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Upcoming')->assertSee('Applications have not opened yet')
            ->assertDontSee(route('filament.applicant.auth.register'), false);
        $cycle->update(['opens_at' => now()->subDays(2), 'closes_at' => now()->subDay()]);
        $this->get('/')->assertOk()->assertViewHas('admissionState', 'Closed')->assertSee('Applications are closed')
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertDontSee(route('filament.applicant.auth.register'), false);
    }
}
