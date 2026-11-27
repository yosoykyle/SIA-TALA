<?php

namespace Tests\Feature\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthModalsAndPrivacyNoticeTest extends TestCase
{
    #[DataProvider('panels')]
    public function test_landing_modal_urls_are_excluded_from_filament_spa_navigation(string $panelId): void
    {
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        foreach ([
            route('home'),
            route('home', ['modal' => 'support']),
            route('home', ['modal' => 'privacy']),
            route('home', ['modal' => 'accessibility']),
            url('/?modal=support'),
            url('/?modal=privacy'),
            url('/#support'),
            route('public.sign-in'),
            route('public.apply'),
        ] as $publicUrl) {
            $this->assertStringNotContainsString(
                'wire:navigate',
                (string) \Filament\Support\generate_href_html($publicUrl),
                "Public landing URL [{$publicUrl}] must not be intercepted by Filament SPA mode in panel [{$panelId}].",
            );
        }
    }

    #[DataProvider('panels')]
    public function test_auth_pages_render_in_page_support_and_privacy_dialogs_and_back_to_school_site_link(string $panelId): void
    {
        $response = $this->get('/'.$panelId.'/login')
            ->assertOk()
            ->assertSee('Back to school site')
            ->assertDontSee('Back to home')
            ->assertSee('Contact support')
            ->assertSee('data-tala-open-auth-modal="support"', false)
            ->assertSee('tala-auth-support-dialog', false)
            ->assertSee('tala-auth-privacy-dialog', false)
            ->assertSee('Contact School Support')
            ->assertSee('Data Privacy Notice')
            ->assertSee('Servitech account')
            ->assertDontSee('TALA account')
            ->assertSee('fi-modal-window', false);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        $supportLink = $xpath->query('//nav[contains(@class, "tala-auth-recovery")]//a[@data-tala-open-auth-modal="support"]')->item(0);
        $this->assertNotNull($supportLink);
        $this->assertSame(route('home', ['modal' => 'support']), $supportLink->getAttribute('href'));
        $this->assertFalse($supportLink->hasAttribute('wire:navigate'));
    }

    public function test_register_applicant_and_public_landing_share_ra_10173_data_privacy_notice_and_support_channels(): void
    {
        config([
            'institution.address' => '162 R. Magsaysay Ave, San Pedro, Laguna, Philippines',
            'institution.public.support_phone' => '0947 737 9208',
            'institution.public.support_phone_uri' => 'tel:+639477379208',
            'institution.public.support_facebook_url' => 'https://www.facebook.com/servitechinstituteasiaph',
        ]);

        $expectedPrivacyAssertions = [
            'Republic Act No. 10173',
            'Data Privacy Act of 2012',
            'Personal Information Controller',
            'Servitech Institute Asia Inc.',
            'Purpose & Lawful Basis (RA 10173, Sec. 12)',
            'Personal Data Collected',
            'Security & Access Safeguards (RA 10173, Sec. 20)',
            'Your Rights as a Data Subject (RA 10173, Sec. 16)',
            'National Privacy Commission (NPC)',
            'Retention & Disposal',
            'CHED and TESDA',
            'Data Protection Officer & Support Contact',
        ];

        $landingResponse = $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="privacyModal"', false);

        $this->partialMock(ApplicantEntryReadinessService::class)
            ->shouldReceive('registrationIsAvailable')
            ->andReturn(true);

        $registerResponse = $this->get(route('filament.applicant.auth.register'))
            ->assertOk()
            ->assertSee('Back to school site')
            ->assertSee('tala-auth-privacy-dialog', false)
            ->assertSee('tala-auth-support-dialog', false)
            ->assertSee('fi-modal-window', false)
            ->assertSee('0947 737 9208')
            ->assertSee('tel:+639477379208', false)
            ->assertSee('https://www.facebook.com/servitechinstituteasiaph', false)
            ->assertSee('Monday to Friday, 7:00 AM – 5:00 PM')
            ->assertSee('162 R. Magsaysay Ave, San Pedro, Laguna, Philippines');

        foreach ($expectedPrivacyAssertions as $phrase) {
            $registerResponse->assertSee($phrase);
            $landingResponse->assertSee($phrase);
        }
    }

    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['Applicant' => ['applicant'], 'Student' => ['student'], 'Staff' => ['admin']];
    }
}
