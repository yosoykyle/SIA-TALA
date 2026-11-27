<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthRateLimitCountdownTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_inline_countdown_component_inherits_idle_dial_visuals_without_fullscreen_veil_or_account_disclosure(): void
    {
        $warmHtml = $this->blade(
            '<x-tala-rate-limit-countdown :remaining-seconds="45" :total-seconds="60" context="login" :show-use-another-account="true" />'
        );

        $warmHtml
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('data-phase="warm"', false)
            ->assertSee('data-remaining-seconds="45"', false)
            ->assertSee('data-total-seconds="60"', false)
            ->assertSee('tala-idle-dial', false)
            ->assertDontSee('tala-idle-ring', false)
            ->assertSee('tala-auth-countdown-progress', false)
            ->assertSee('tala-auth-countdown-progress-fill', false)
            ->assertSee('tala-idle-numeral', false)
            ->assertSee('<span>4</span><span>5</span>', false)
            ->assertSee('tala-idle-unit', false)
            ->assertSee('seconds')
            ->assertSee('tala-idle-plane', false)
            ->assertSee('tala-idle-plane-trail', false)
            ->assertSee('Hold on a moment')
            ->assertSee('Too many attempts')
            ->assertSee('To protect accounts, please wait until the timer finishes before trying again.')
            ->assertSee('Use another account')
            ->assertSee('css/tala-auth-countdown.css', false)
            ->assertSee('js/tala-auth-countdown.js', false)
            ->assertDontSee('tala-idle-glow', false)
            ->assertDontSee('tala-idle-edge', false)
            ->assertDontSee('tala-idle-field', false);

        $calmHtml = $this->blade(
            '<x-tala-rate-limit-countdown :remaining-seconds="8" :total-seconds="60" context="password_reset" />'
        );

        $calmHtml
            ->assertSee('data-phase="calm"', false)
            ->assertSee('<span>8</span><span></span>', false)
            ->assertDontSee('Use another account');
    }

    public function test_contextual_login_rate_limit_replaces_form_with_countdown_and_allows_switching_account(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $email = 'locked-sign-in@example.test';
        $loginKey = 'tala-login:'.$email.'|127.0.0.1';
        RateLimiter::clear($loginKey);

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($loginKey, 60);
        }

        $component = Livewire::test(ContextualLogin::class)
            ->set('data.email', $email)
            ->set('data.password', 'wrong password')
            ->call('authenticate')
            ->assertNotified()
            ->assertSet('rateLimitContext', 'login')
            ->assertSet('data.email', null)
            ->assertSet('data.password', null)
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('tala-idle-dial', false)
            ->assertSee('Hold on a moment')
            ->assertSee('Too many attempts')
            ->assertSee('To protect accounts, please wait until the timer finishes before trying again.')
            ->assertSee('Use another account')
            ->assertDontSee($email);

        $this->assertTrue($component->instance()->isAuthRateLimited());
        $this->assertGreaterThan(0, $component->get('rateLimitRemainingSeconds'));

        // Clicking "Use another account" clears the countdown UI and resets to the email input while keeping server RateLimiter intact.
        $component
            ->call('useAnotherAccount')
            ->assertSet('rateLimitRemainingSeconds', 0)
            ->assertSet('rateLimitedUntil', null)
            ->assertSet('data.email', null)
            ->assertSet('data.password', null)
            ->assertDispatched('focus-email-input')
            ->assertDontSee('Too many attempts');

        $this->assertFalse($component->instance()->isAuthRateLimited());
        $this->assertTrue(RateLimiter::tooManyAttempts($loginKey, 5));

        RateLimiter::clear($loginKey);
    }

    public function test_contextual_login_shows_non_enumerating_remaining_attempts_on_third_and_fourth_failures_and_triggers_countdown_on_fifth_failure(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $registered = User::factory()->create([
            'email' => 'registered-attempts@example.test',
            'password' => 'a secure password 2026',
        ]);
        $registered->assignRole(User::StaffRoleFaculty);

        foreach ([$registered->email, 'unknown-attempts@example.test'] as $email) {
            $loginKey = 'tala-login:'.$email.'|127.0.0.1';
            RateLimiter::clear($loginKey);

            $login = Livewire::test(ContextualLogin::class);

            // Malformed email format validation failure does not consume attempts or show remaining-attempt warnings.
            $login
                ->set('data.email', 'not-an-email')
                ->set('data.password', 'wrong password')
                ->call('authenticate')
                ->assertHasErrors(['data.email'])
                ->assertDontSee('attempts remaining')
                ->assertDontSee('attempt remaining');

            $this->assertSame(0, RateLimiter::attempts($loginKey));

            // Attempts 1 and 2 show the clean standard credential message without attempt count noise.
            foreach ([1, 2] as $attempt) {
                $login
                    ->set('data.email', $email)
                    ->set('data.password', 'wrong password')
                    ->call('authenticate')
                    ->assertHasErrors(['data.email'])
                    ->assertSee('These credentials do not match our records.')
                    ->assertDontSee('attempts remaining')
                    ->assertDontSee('attempt remaining')
                    ->assertDontSee('tala-auth-countdown', false);

                $this->assertSame($attempt, RateLimiter::attempts($loginKey));
                $this->assertFalse($login->instance()->isAuthRateLimited());
            }

            // Attempt 3 appends "2 attempts remaining before a 60-second pause."
            $login
                ->set('data.email', $email)
                ->set('data.password', 'wrong password')
                ->call('authenticate')
                ->assertHasErrors(['data.email'])
                ->assertSee('These credentials do not match our records. 2 attempts remaining before a 60-second pause.')
                ->assertDontSee('tala-auth-countdown', false);

            $this->assertSame(3, RateLimiter::attempts($loginKey));
            $this->assertFalse($login->instance()->isAuthRateLimited());

            // Attempt 4 appends "1 attempt remaining before a 60-second pause."
            $login
                ->set('data.email', $email)
                ->set('data.password', 'wrong password')
                ->call('authenticate')
                ->assertHasErrors(['data.email'])
                ->assertSee('These credentials do not match our records. 1 attempt remaining before a 60-second pause.')
                ->assertDontSee('tala-auth-countdown', false);

            $this->assertSame(4, RateLimiter::attempts($loginKey));
            $this->assertFalse($login->instance()->isAuthRateLimited());

            // Attempt 5 immediately triggers the 60-second inline countdown dial and empties email & password inputs.
            $login
                ->set('data.email', $email)
                ->set('data.password', 'wrong password')
                ->set('data.remember', true)
                ->call('authenticate')
                ->assertHasErrors(['data.email'])
                ->assertNotified()
                ->assertSet('rateLimitContext', 'login')
                ->assertSet('data.email', null)
                ->assertSet('data.password', null)
                ->assertSet('data.remember', false)
                ->assertSee('tala-auth-countdown', false)
                ->assertSee('tala-idle-dial', false)
                ->assertSee('Too many attempts')
                ->assertDontSee($email);

            $this->assertSame(5, RateLimiter::attempts($loginKey));
            $this->assertTrue($login->instance()->isAuthRateLimited());
            $this->assertGreaterThan(0, $login->get('rateLimitRemainingSeconds'));

            RateLimiter::clear($loginKey);
        }
    }

    public function test_contextual_login_mfa_challenge_shows_remaining_attempt_warnings_and_triggers_countdown_on_fifth_failure(): void
    {
        $staff = User::factory()->create([
            'email' => 'mfa-progression-staff@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $provider->saveSecret($staff, $secret);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $loginKey = 'tala-login:'.$staff->email.'|127.0.0.1';
        $mfaKey = 'tala-mfa:'.$staff->getAuthIdentifier().'|127.0.0.1';
        RateLimiter::clear($loginKey);
        RateLimiter::clear($mfaKey);

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $currentCode = $provider->getCurrentCode($staff, $secret);
        $invalidCode = $currentCode === '000000' ? '111111' : '000000';

        // Attempts 1 and 2 on MFA challenge show plain invalid code message without attempt count noise.
        foreach ([1, 2] as $attempt) {
            $login
                ->set('data.multiFactor.app.code', $invalidCode)
                ->call('authenticate')
                ->assertHasErrors(['data.multiFactor.app.code'])
                ->assertDontSee('attempts remaining')
                ->assertDontSee('attempt remaining')
                ->assertDontSee('tala-auth-countdown', false);

            $this->assertSame($attempt, RateLimiter::attempts($mfaKey));
            $this->assertFalse($login->instance()->isAuthRateLimited());
        }

        // Attempt 3 on MFA challenge shows "2 attempts remaining before a 60-second pause."
        $login
            ->set('data.multiFactor.app.code', $invalidCode)
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code'])
            ->assertSee('2 attempts remaining before a 60-second pause.')
            ->assertDontSee('tala-auth-countdown', false);

        $this->assertSame(3, RateLimiter::attempts($mfaKey));
        $this->assertFalse($login->instance()->isAuthRateLimited());

        // Attempt 4 on MFA challenge shows "1 attempt remaining before a 60-second pause."
        $login
            ->set('data.multiFactor.app.code', $invalidCode)
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code'])
            ->assertSee('1 attempt remaining before a 60-second pause.')
            ->assertDontSee('tala-auth-countdown', false);

        $this->assertSame(4, RateLimiter::attempts($mfaKey));
        $this->assertFalse($login->instance()->isAuthRateLimited());

        // Attempt 5 on MFA challenge immediately triggers the 60-second inline countdown dial and empties MFA inputs.
        $login
            ->set('data.multiFactor.app.code', $invalidCode)
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code'])
            ->assertNotified()
            ->assertSet('rateLimitContext', 'mfa')
            ->assertSet('data.multiFactor.app.code', null)
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('tala-idle-dial', false)
            ->assertSee('Too many attempts');

        $this->assertSame(5, RateLimiter::attempts($mfaKey));
        $this->assertTrue($login->instance()->isAuthRateLimited());
        $this->assertGreaterThan(0, $login->get('rateLimitRemainingSeconds'));

        RateLimiter::clear($mfaKey);
        RateLimiter::clear($loginKey);
    }

    public function test_contextual_login_mfa_challenge_rate_limit_shows_countdown_and_allows_use_another_account_recovery(): void
    {
        $staff = User::factory()->create([
            'email' => 'mfa-dial-staff@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $provider->saveSecret($staff, $secret);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $loginKey = 'tala-login:'.$staff->email.'|127.0.0.1';
        $mfaKey = 'tala-mfa:'.$staff->getAuthIdentifier().'|127.0.0.1';
        RateLimiter::clear($loginKey);
        RateLimiter::clear($mfaKey);

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($mfaKey, 60);
        }

        $login
            ->set('data.multiFactor.app.code', '000000')
            ->call('authenticate')
            ->assertNotified()
            ->assertSet('rateLimitContext', 'mfa')
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many attempts')
            ->assertSee('Use another account');

        $this->assertTrue($login->instance()->isAuthRateLimited());

        // Resetting via "Use another account" exits MFA challenge and clears countdown UI without resetting MFA RateLimiter.
        $login
            ->call('useAnotherAccount')
            ->assertSet('userUndertakingMultiFactorAuthentication', null)
            ->assertSet('rateLimitRemainingSeconds', 0)
            ->assertDispatched('focus-email-input')
            ->assertDontSee('Too many attempts');

        $this->assertTrue(RateLimiter::tooManyAttempts($mfaKey, 5));

        RateLimiter::clear($mfaKey);
    }

    public function test_password_reset_rate_limit_shows_inline_countdown_on_throttle_and_on_mount_then_recovers_when_cleared(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $rateLimitKey = 'livewire-rate-limiter:'.sha1(RequestPasswordReset::class.'|request|'.request()->ip());
        RateLimiter::clear($rateLimitKey);

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', 'first-reset@example.test')
            ->call('request')
            ->assertHasNoErrors();

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', 'second-reset@example.test')
            ->call('request')
            ->assertHasNoErrors();

        $throttled = Livewire::test(RequestPasswordReset::class)
            ->set('data.email', 'third-reset@example.test')
            ->call('request')
            ->assertNotified('Please wait before requesting another link')
            ->assertSet('rateLimitContext', 'password_reset')
            ->assertSet('data.email', null)
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many attempts')
            ->assertSee('To protect accounts, please wait until the timer finishes before trying again.')
            ->assertDontSee('Send reset link');

        $this->assertTrue($throttled->instance()->isAuthRateLimited());

        // Mounting a fresh instance while still rate-limited shows the countdown immediately on mount().
        $mountedWhileLocked = Livewire::test(RequestPasswordReset::class)
            ->assertSet('rateLimitContext', 'password_reset')
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many attempts')
            ->assertDontSee('Send reset link');

        $this->assertTrue($mountedWhileLocked->instance()->isAuthRateLimited());

        // When client countdown finishes and calls clearAuthRateLimit(), the form reappears automatically.
        $mountedWhileLocked
            ->call('clearAuthRateLimit')
            ->assertSet('rateLimitRemainingSeconds', 0)
            ->assertSet('rateLimitedUntil', null)
            ->assertDontSee('Too many attempts')
            ->assertSee('Send reset link');

        RateLimiter::clear($rateLimitKey);
    }

    public function test_register_applicant_shows_rate_limit_countdown_and_renders_instant_client_side_password_checklist(): void
    {
        $term = Term::query()->where('state', Term::StateActive)->first()
            ?? Term::factory()->create(['state' => Term::StateActive]);

        AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $rateLimitKey = 'livewire-rate-limiter:'.sha1(RegisterApplicant::class.'|register|'.request()->ip());
        RateLimiter::clear($rateLimitKey);

        // Verify the compact 2-item Alpine password checklist and zero-debounce password field.
        $register = Livewire::test(RegisterApplicant::class)
            ->assertSee('data-tala-password-checklist', false)
            ->assertSee('15–64 characters')
            ->assertSee('Passwords match')
            ->assertSee('Spaces and password managers are supported.')
            ->assertSee('data-tala-password-input="password"', false)
            ->assertSee('data-tala-password-input="password_confirmation"', false);

        /** @var TextInput $passwordComponent */
        $passwordComponent = $register->instance()->form->getComponent('password');
        $this->assertInstanceOf(TextInput::class, $passwordComponent);
        $this->assertFalse($passwordComponent->isLive());

        // Simulate hitting the registration rate limit (2 attempts max).
        RateLimiter::hit($rateLimitKey, 60);
        RateLimiter::hit($rateLimitKey, 60);

        $register
            ->call('register')
            ->assertNotified()
            ->assertSet('rateLimitContext', 'register')
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many attempts')
            ->assertSee('To protect accounts, please wait until the timer finishes before trying again.')
            ->assertDontSee('Create account');

        // Fresh mount while rate-limited shows the countdown immediately on mount().
        $mountedLocked = Livewire::test(RegisterApplicant::class)
            ->assertSet('rateLimitContext', 'register')
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many attempts')
            ->assertDontSee('Create account');

        $this->assertTrue($mountedLocked->instance()->isAuthRateLimited());

        // Automatic expiry when time passes or clearAuthRateLimit() is called restores the registration form.
        $this->travel(61)->seconds();
        $this->assertFalse($mountedLocked->instance()->isAuthRateLimited());
        $this->travelBack();

        RateLimiter::clear($rateLimitKey);
    }

    public function test_email_verification_prompt_reuses_rate_limit_countdown_on_throttle_and_mount_then_recovers(): void
    {
        Notification::fake();

        foreach (['admin' => User::StaffRoleFaculty, 'student' => 'student'] as $panelId => $role) {
            $user = User::factory()->unverified()->create([
                'status' => User::StatusActive,
            ]);
            $user->assignRole($role);

            Filament::setCurrentPanel(Filament::getPanel($panelId));

            $userKey = 'filament-resend-email-verification:'.$user->id;
            $livewireKey = 'livewire-rate-limiter:'.sha1(EmailVerificationPrompt::class.'|resendNotification|'.request()->ip());
            RateLimiter::clear($userKey);
            RateLimiter::clear($livewireKey);

            $prompt = Livewire::actingAs($user)
                ->test(EmailVerificationPrompt::class)
                ->assertSee('Resend verification email')
                ->assertSee('Sign out')
                ->assertDontSee('tala-auth-countdown', false);

            $prompt->callAction('resendNotification')->assertNotified();
            $prompt->callAction('resendNotification')->assertNotified();
            Notification::assertSentToTimes($user, VerifyEmail::class, 2);

            $prompt
                ->callAction('resendNotification')
                ->assertNotified()
                ->assertSet('rateLimitContext', 'email_verification')
                ->assertSee('tala-auth-countdown', false)
                ->assertSee('Hold on a moment')
                ->assertSee('Too many verification emails sent')
                ->assertSee('Please wait until the timer finishes before requesting another verification email. You can still open the newest link already sent to your inbox.')
                ->assertDontSee('Too many attempts')
                ->assertSee('Sign out')
                ->assertDontSee('Resend verification email');

            $this->assertTrue($prompt->instance()->isAuthRateLimited());
            $this->assertGreaterThan(0, $prompt->get('rateLimitRemainingSeconds'));

            $mountedWhileLocked = Livewire::actingAs($user)
                ->test(EmailVerificationPrompt::class)
                ->assertSet('rateLimitContext', 'email_verification')
                ->assertSee('tala-auth-countdown', false)
                ->assertSee('Too many verification emails sent')
                ->assertSee('Sign out')
                ->assertDontSee('Resend verification email');

            $this->assertTrue($mountedWhileLocked->instance()->isAuthRateLimited());

            $mountedWhileLocked
                ->call('clearAuthRateLimit')
                ->assertSet('rateLimitRemainingSeconds', 0)
                ->assertSet('rateLimitedUntil', null)
                ->assertDontSee('Too many verification emails sent')
                ->assertSee('Resend verification email');

            RateLimiter::clear($userKey);
            RateLimiter::clear($livewireKey);
        }
    }

    public function test_applicant_email_verification_shows_rate_limit_countdown_when_throttled_and_recovers(): void
    {
        Notification::fake();

        $applicant = User::factory()->unverified()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'status' => User::StatusActive,
        ]);
        $applicant->assignRole('applicant');

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $rateLimitKey = 'applicant-email-verification-resend:'.$applicant->id;
        RateLimiter::clear($rateLimitKey);

        $component = Livewire::actingAs($applicant)
            ->test(ApplicantEmailVerification::class)
            ->assertSee('Resend verification link')
            ->callAction('resendNotification');

        Notification::assertSentToTimes($applicant, VerifyEmail::class, 1);

        $component
            ->callAction('resendNotification')
            ->assertSet('rateLimitContext', 'email_verification')
            ->assertSee('tala-auth-countdown', false)
            ->assertSee('Too many verification emails sent')
            ->assertSee('Please wait until the timer finishes before requesting another verification email. You can still open the newest link already sent to your inbox.')
            ->assertDontSee('Too many attempts')
            ->assertSee('Back to sign in')
            ->assertSee('Contact support')
            ->assertDontSee('Resend verification link');

        $this->assertTrue($component->instance()->isAuthRateLimited());

        $component
            ->call('clearAuthRateLimit')
            ->assertSet('rateLimitRemainingSeconds', 0)
            ->assertDontSee('Too many verification emails sent')
            ->assertSee('Resend verification link');

        RateLimiter::clear($rateLimitKey);
    }
}
