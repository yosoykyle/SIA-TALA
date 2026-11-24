<?php

namespace Tests\Feature\Auth;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthVerificationAndErrorPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
    }

    public function test_applicant_verification_email_displays_institution_branding_without_laravel_or_broken_icon_boxes(): void
    {
        config()->set('app.name', 'Laravel');
        config()->set('institution.name', 'Servitech Institute Asia Inc.');

        $applicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));

        /** @var VerifyEmail $notification */
        $notification = Notification::sent($applicant, VerifyEmail::class)->sole();
        $mailMessage = $notification->toMail($applicant);

        $this->assertInstanceOf(MailMessage::class, $mailMessage);
        $this->assertSame('Servitech Institute Asia Inc. — Verify your email address', $mailMessage->subject);
        $this->assertSame("Regards,\nServitech Institute Asia Inc.", $mailMessage->salutation);

        $renderedHtml = (string) $mailMessage->render();

        $this->assertStringContainsString('Servitech Institute Asia Inc.', $renderedHtml);
        $this->assertStringContainsString('Verify email address', $renderedHtml);
        $this->assertStringContainsString('Regards,', $renderedHtml);
        $this->assertStringNotContainsString('Laravel', $renderedHtml);
        $this->assertStringNotContainsString('Powered by', $renderedHtml);
        $this->assertStringNotContainsString('servitech-crest.webp', $renderedHtml);
        $this->assertStringNotContainsString('talalogo.png', $renderedHtml);

        $fallbackNotificationHtml = (string) (new MailMessage)
            ->line('System update notice.')
            ->render();

        $this->assertStringContainsString('Servitech Institute Asia Inc.', $fallbackNotificationHtml);
        $this->assertStringNotContainsString('Laravel', $fallbackNotificationHtml);
        $this->assertStringNotContainsString('Powered by', $fallbackNotificationHtml);
    }

    public function test_unauthenticated_applicant_clicking_valid_signed_verification_link_verifies_and_redirects_to_login_with_status(): void
    {
        $applicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));
        $verificationUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;

        $this->assertGuest();

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('filament.applicant.auth.login'))
            ->assertSessionHas('status', 'Your email address has been verified. Sign in to continue your application.');

        $applicant->refresh();

        $this->assertTrue($applicant->hasVerifiedEmail());
        $this->assertNull($applicant->email_verification_nonce);
        $this->assertTrue(
            Activity::query()
                ->where('event', 'email_verified')
                ->where('subject_id', $applicant->id)
                ->exists(),
        );

        $this->get(route('filament.applicant.auth.login'))
            ->assertOk()
            ->assertSee('Your email address has been verified. Sign in to continue your application.');

        $this->actingAs($applicant)
            ->get('/applicant')
            ->assertOk();
    }

    public function test_signed_in_applicant_clicking_valid_signed_verification_link_verifies_and_redirects_to_applicant_workspace(): void
    {
        $applicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));
        $verificationUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;

        $this->actingAs($applicant->fresh())
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->getTimestamp()])
            ->get($verificationUrl)
            ->assertRedirect('/applicant')
            ->assertSessionMissing(DispatchApplicantEmailVerification::SentAtSessionKey);

        $this->assertTrue($applicant->fresh()->hasVerifiedEmail());
        $this->assertNull($applicant->fresh()->email_verification_nonce);
    }

    public function test_signed_in_different_user_cannot_verify_another_applicants_link(): void
    {
        $targetApplicant = $this->createUnverifiedApplicant();
        $otherApplicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($targetApplicant));
        $verificationUrl = Notification::sent($targetApplicant, VerifyEmail::class)->sole()->url;

        $this->actingAs($otherApplicant)
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($targetApplicant->fresh()->hasVerifiedEmail());
        $this->assertNotNull($targetApplicant->fresh()->email_verification_nonce);
    }

    public function test_expired_superseded_and_reused_verification_links_fail_cleanly_for_guests(): void
    {
        $applicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $dispatch = app(DispatchApplicantEmailVerification::class);

        $this->assertTrue($dispatch->execute($applicant));
        $firstUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;

        $this->assertTrue($dispatch->execute($applicant->fresh()));
        $secondUrl = Notification::sent($applicant, VerifyEmail::class)->last()->url;

        $this->get($firstUrl)
            ->assertForbidden()
            ->assertSee('Sign in to request a new link')
            ->assertSee('Contact support')
            ->assertDontSee('HTTP 403');

        $this->travel(61)->minutes();
        $this->get($secondUrl)
            ->assertForbidden()
            ->assertSee('Sign in to request a new link')
            ->assertDontSee('HTTP 403');
        $this->travelBack();

        $this->get($secondUrl)
            ->assertRedirect(route('filament.applicant.auth.login'));

        $this->get($secondUrl)
            ->assertForbidden()
            ->assertDontSee('HTTP 403');
    }

    public function test_verification_prompt_passes_server_remaining_seconds_and_monotonic_countdown_immune_to_clock_skew(): void
    {
        $this->travelTo(now()->startOfMinute());

        $applicant = $this->createUnverifiedApplicant();

        $this->actingAs($applicant)
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->getTimestamp()])
            ->get('/applicant/email-verification/prompt')
            ->assertOk()
            ->assertSee('Link expires in 60:00')
            ->assertSee('initialSeconds: 3600', false)
            ->assertSee('startedAt: Date.now()', false)
            ->assertDontSee('expiresAt:', false);

        $this->actingAs($applicant)
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->subSeconds(90)->getTimestamp()])
            ->get('/applicant/email-verification/prompt')
            ->assertOk()
            ->assertSee('Link expires in 58:30')
            ->assertSee('initialSeconds: 3510', false);

        $this->travelBack();
    }

    public function test_verification_prompt_uses_consistent_recovery_links_and_back_to_sign_in_logs_out_cleanly(): void
    {
        $applicant = $this->createUnverifiedApplicant();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->actingAs($applicant)
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->getTimestamp()])
            ->get('/applicant/email-verification/prompt')
            ->assertOk()
            ->assertSee('Back to sign in')
            ->assertSee('Contact support')
            ->assertSee('data-tala-open-auth-modal="support"', false)
            ->assertSee('class="tala-auth-recovery"', false)
            ->assertDontSee('Need help?')
            ->assertDontSee('tala-verification-support', false);

        Livewire::actingAs($applicant)
            ->test(ApplicantEmailVerification::class)
            ->callAction('backToSignIn')
            ->assertRedirect(route('filament.applicant.auth.login'));

        $this->assertGuest();
    }

    public function test_forbidden_error_page_removes_stray_http_403_footer_and_renders_inline_contact_support_link(): void
    {
        $applicant = $this->createUnverifiedApplicant();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));

        $malformedUrl = route('filament.applicant.auth.email-verification.verify', [
            'id' => $applicant->id,
            'hash' => sha1($applicant->fresh()->getEmailForVerification()),
        ]);

        $supportUrl = route('home', ['modal' => 'support']);

        $this->actingAs($applicant->fresh())
            ->get($malformedUrl)
            ->assertForbidden()
            ->assertSee('Request a new verification link')
            ->assertSee('Use another account')
            ->assertSee('Contact support')
            ->assertSee('class="error-support-link"', false)
            ->assertDontSee('HTTP 403')
            ->assertDontSee('class="secondary-action" href="'.$supportUrl.'"', false);

        $verifiedApplicant = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $verifiedApplicant->assignRole('applicant');

        $this->actingAs($verifiedApplicant);
        $this->view('errors.403')
            ->assertSee('Return to your application')
            ->assertSee('Use another account')
            ->assertSee('Contact support')
            ->assertSee('class="error-support-link"', false)
            ->assertDontSee('HTTP 403');
    }

    private function createUnverifiedApplicant(): User
    {
        $applicant = User::factory()->unverified()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'status' => User::StatusActive,
        ]);
        $applicant->assignRole('applicant');

        return $applicant;
    }
}
