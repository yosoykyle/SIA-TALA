<?php

namespace Tests\Feature;

use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Term;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ApplicantPanelProvider;
use App\Providers\Filament\StudentPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TAL96D4DLandingAndCrossRolePresentationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_landing_leads_with_school_discovery_without_script_dependent_headings(): void
    {
        $this->openAdmissions();

        $this->get('/')
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false)
            ->assertSee('Explore programs')
            ->assertSee('Servitech Institute Asia Inc.')
            ->assertSee('id="faq-title"', false)
            ->assertDontSee('OUR MISSION')
            ->assertSee('title="Map showing Servitech Institute Asia Inc. campus location"', false)
            ->assertDontSee('typewriter', false)
            ->assertDontSee('image-placeholder-placeholder', false)
            ->assertDontSee('btn-blur', false)
            ->assertDontSee('card-blur', false);
    }

    public function test_public_landing_keeps_published_faq_entries_ordered_and_hides_drafts(): void
    {
        FaqEntry::query()->delete();

        FaqEntry::factory()->published()->create([
            'question' => 'Second public question?',
            'category' => FaqEntry::CategoryAccountLogin,
            'sort_order' => 2,
        ]);
        FaqEntry::factory()->published()->create([
            'question' => 'First public question?',
            'category' => FaqEntry::CategoryAdmissionEnrollment,
            'sort_order' => 1,
        ]);
        FaqEntry::factory()->create([
            'question' => 'Draft question?',
            'sort_order' => 0,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                'First public question?',
                'Second public question?',
            ])
            ->assertSee(FaqEntry::categoryLabel(FaqEntry::CategoryAdmissionEnrollment))
            ->assertSee(FaqEntry::categoryLabel(FaqEntry::CategoryAccountLogin))
            ->assertSee('faq-category', false)
            ->assertDontSee('Draft question?');
    }

    public function test_role_workspaces_retain_consistent_tala_branding_and_distinct_names(): void
    {
        $admin = (new AdminPanelProvider($this->app))->panel(new Panel);
        $applicant = (new ApplicantPanelProvider($this->app))->panel(new Panel);
        $student = (new StudentPanelProvider($this->app))->panel(new Panel);

        $this->assertSame('Servitech Institute Asia — Staff Workspace', $admin->getBrandName());
        $this->assertSame('Servitech Institute Asia', $applicant->getBrandName());
        $this->assertSame('Servitech Institute Asia — Student Hub', $student->getBrandName());
        foreach ([$admin, $applicant, $student] as $panel) {
            $logo = $panel->getBrandLogo();
            $this->assertInstanceOf(View::class, $logo);
            $markup = $logo->render();

            $this->assertStringContainsString(asset('images/brand/servitech-crest-outlined-192.webp'), $markup);
            $this->assertStringContainsString('tala-crest-outlined', $markup);
            $this->assertStringContainsString('Servitech Institute Asia', preg_replace('/\s+/', ' ', strip_tags($markup)));
            $this->assertStringNotContainsString('Powered by TALA', $markup);
            $this->assertStringNotContainsString('talalogo.png', $markup);
            $this->assertStringContainsString('alt="" aria-hidden="true"', $markup);
            $this->assertStringNotContainsString('alt="Servitech Institute Asia"', $markup);
        }

        $this->assertSame(Color::generatePalette('#2F7D3B'), $admin->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $applicant->getColors()['primary']);
        $this->assertSame($admin->getColors()['primary'], $student->getColors()['primary']);
    }

    private function openAdmissions(): AdmissionCycle
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);

        return AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
    }
}
