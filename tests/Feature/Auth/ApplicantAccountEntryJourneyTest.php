<?php

namespace Tests\Feature\Auth;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Models\AdmissionCycle;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantAccountEntryJourneyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_open_admissions_exposes_registration_without_an_invented_mail_readiness_toggle(): void
    {
        $this->openAdmissions();
        config()->set('institution.public.support_facebook_url', null);

        $this->get('/')
            ->assertOk()
            ->assertSee('Create Applicant account')
            ->assertSee(route('public.sign-in'), false)
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('Student Hub')
            ->assertSee(route('public.apply'), false)
            ->assertSee('id="privacyModal"', false)
            ->assertSee('id="accessibilityModal"', false);

        $this->get(route('public.sign-in'))->assertOk()
            ->assertSee('New applicant? Apply for admission');
    }

    public function test_open_entry_exposes_project_approved_support_and_single_page_public_information(): void
    {
        $this->openAdmissions();

        $this->get('/')
            ->assertOk()
            ->assertSee('https://www.facebook.com/servitechinstituteasiaph', false)
            ->assertSee('0947 737 9208')
            ->assertSee('Privacy Notice')
            ->assertSee('id="privacyModal"', false)
            ->assertSee('Application updates, requirements and corrections are available only through your signed-in Applicant account.')
            ->assertSee('aria-label="Close privacy notice"', false);
    }

    public function test_missing_admission_cycle_keeps_existing_applicant_sign_in_available(): void
    {
        AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->update(['state' => AdmissionCycle::StateDraft]);

        $this->get('/')
            ->assertOk()
            ->assertViewHas('admissionState', 'Missing')
            ->assertSee('Ask Admissions about applying')
            ->assertSee('Existing accounts can still sign in.')
            ->assertSee(route('public.sign-in'), false)
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('Student Hub')
            ->assertDontSee(route('filament.applicant.auth.register'), false);

        $this->get(route('public.sign-in'))->assertOk()
            ->assertDontSee('New applicant? Apply for admission');
    }

    public function test_ready_entry_creates_only_one_minimal_unverified_applicant_account(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();

        $applicant = app(CreateNewUser::class)->create([
            'email' => '  Applicant@Example.TEST  ',
            'password' => 'Valid1!passphrase',
            'password_confirmation' => 'Valid1!passphrase',
            'privacy_acknowledged' => true,
        ]);

        $this->assertNull($applicant->name);
        $this->assertSame('applicant@example.test', $applicant->email);
        $this->assertSame(User::StatusActive, $applicant->status);
        $this->assertNull($applicant->email_verified_at);
        $this->assertSame(route('home', ['modal' => 'privacy']), $applicant->privacy_notice_reference);
        $this->assertNotNull($applicant->privacy_acknowledged_at);
        $this->assertTrue(Hash::check('Valid1!passphrase', $applicant->password));
        $this->assertTrue($applicant->hasRole('applicant'));
        $this->assertSame('Applicant account', $applicant->getFilamentName());
        $this->assertSame(1, User::query()->where('email', 'applicant@example.test')->count());
        $this->assertSame(0, ApplicantIntake::query()->where('user_id', $applicant->id)->count());
        $this->assertSame(0, StudentProfile::query()->where('user_id', $applicant->id)->count());
    }

    public function test_account_creation_requires_privacy_acknowledgement(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();

        try {
            app(CreateNewUser::class)->create([
                'email' => 'applicant@example.test',
                'password' => 'Valid1!passphrase',
                'password_confirmation' => 'Valid1!passphrase',
                'privacy_acknowledged' => false,
            ]);

            $this->fail('Privacy acknowledgement should be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('privacy_acknowledged', $exception->errors());
        }

        $this->assertDatabaseMissing('users', ['email' => 'applicant@example.test']);
    }

    public function test_account_creation_enforces_the_approved_password_length_bounds(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        foreach ([
            'fourteen-chars@example.test' => str_repeat('a', 14),
            'legacy-eight@example.test' => 'Valid1!x',
            'too-long@example.test' => str_repeat('a', 65),
        ] as $email => $password) {
            try {
                app(CreateNewUser::class)->create([
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'privacy_acknowledged' => true,
                ]);

                $this->fail('The password length boundary should be enforced.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('password', $exception->errors());
            }

            RateLimiter::clear('livewire-rate-limiter:'.sha1(RegisterApplicant::class.'|register|'.request()->ip()));

            Livewire::test(RegisterApplicant::class)
                ->fillForm([
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'privacy_acknowledged' => true,
                ])
                ->call('register')
                ->assertHasFormErrors(['password']);

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_account_creation_accepts_fifteen_to_sixty_four_character_passwords_without_composition_rules(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Notification::fake();

        foreach ([
            'fifteen-chars@example.test' => 'abcdefghijklmno',
            'passphrase-spaces@example.test' => 'simple synthetic passphrase',
            'sixty-four-chars@example.test' => str_repeat('x', 64),
        ] as $email => $password) {
            $applicant = app(CreateNewUser::class)->create([
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'privacy_acknowledged' => true,
            ]);

            $this->assertTrue(Hash::check($password, $applicant->password));
        }

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RegisterApplicant::class)
            ->fillForm([
                'email' => 'livewire-spaces@example.test',
                'password' => 'simple synthetic passphrase',
                'password_confirmation' => 'simple synthetic passphrase',
                'privacy_acknowledged' => true,
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $livewireApplicant = User::query()->where('email', 'livewire-spaces@example.test')->sole();
        $this->assertTrue(Hash::check('simple synthetic passphrase', $livewireApplicant->password));
    }

    public function test_legacy_short_password_credentials_still_authenticate_cleanly_at_login(): void
    {
        Role::findOrCreate('applicant', 'web');
        $applicant = User::factory()->create([
            'email' => 'legacy-login@example.test',
            'password' => 'Valid1!x',
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        $this->post('/login', [
            'email' => $applicant->email,
            'password' => 'Valid1!x',
        ])->assertRedirect('/applicant');
        $this->assertAuthenticatedAs($applicant);

        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $applicant->email)
            ->set('data.password', 'Valid1!x')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($applicant);
    }

    public function test_registration_password_length_guidance_updates_as_the_applicant_types(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RegisterApplicant::class)
            ->assertSee('Use 15–64 characters. Spaces and password-manager paste are allowed.')
            ->assertSee('0 of 15–64 characters entered')
            ->assertSee('Not met')
            ->assertDontSee('At least 8 characters')
            ->assertDontSee('Uppercase letter')
            ->assertDontSee('Lowercase letter')
            ->assertDontSee('Number')
            ->assertDontSee('Special character')
            ->fillForm(['password' => 'too short'])
            ->assertSee('9 of 15–64 characters entered (minimum 15)')
            ->assertSee('Not met')
            ->fillForm(['password' => 'simple synthetic passphrase'])
            ->assertSee('27 of 15–64 characters entered (ready)')
            ->assertDontSee('Not met')
            ->fillForm(['password' => str_repeat('a', 65)])
            ->assertSee('65 of 15–64 characters entered (maximum 64)')
            ->assertSee('Not met');
    }

    public function test_duplicate_account_creation_uses_non_disclosing_feedback(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        $password = 'Valid1!passphrase';

        app(CreateNewUser::class)->create([
            'email' => 'applicant@example.test',
            'password' => $password,
            'password_confirmation' => $password,
            'privacy_acknowledged' => true,
        ]);

        try {
            app(CreateNewUser::class)->create([
                'email' => '  APPLICANT@EXAMPLE.TEST ',
                'password' => $password,
                'password_confirmation' => $password,
                'privacy_acknowledged' => true,
            ]);

            $this->fail('A normalized duplicate account should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['An account could not be created with those details. Try signing in or use account recovery.'],
                $exception->errors()['email'],
            );
        }

        $this->assertSame(1, User::query()->where('email', 'applicant@example.test')->count());
    }

    public function test_database_unique_race_uses_non_disclosing_feedback_without_creating_a_duplicate(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        $raceWasTriggered = false;

        User::creating(function (User $user) use (&$raceWasTriggered): void {
            if ($raceWasTriggered || $user->email !== 'race@example.test') {
                return;
            }

            $raceWasTriggered = true;

            User::withoutEvents(fn (): User => User::query()->create([
                'name' => null,
                'email' => 'race@example.test',
                'password' => 'Valid1!passphrase',
                'status' => User::StatusActive,
            ]));
        });

        try {
            app(CreateNewUser::class)->create([
                'email' => 'race@example.test',
                'password' => 'Valid1!passphrase',
                'password_confirmation' => 'Valid1!passphrase',
                'privacy_acknowledged' => true,
            ]);

            $this->fail('A database uniqueness race should be reported as safe duplicate feedback.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['An account could not be created with those details. Try signing in or use account recovery.'],
                $exception->errors()['email'],
            );
        }

        $this->assertTrue($raceWasTriggered);
        $this->assertLessThanOrEqual(1, User::query()->where('email', 'race@example.test')->count());
    }

    public function test_compromised_password_is_rejected_without_creating_an_account(): void
    {
        $this->openAdmissions();
        $password = 'A compromised1!passphrase';
        $hash = strtoupper(sha1($password));

        Http::fake([
            'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':3', 200),
        ]);

        try {
            app(CreateNewUser::class)->create([
                'email' => 'compromised@example.test',
                'password' => $password,
                'password_confirmation' => $password,
                'privacy_acknowledged' => true,
            ]);

            $this->fail('A compromised password should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }

        $this->assertDatabaseMissing('users', ['email' => 'compromised@example.test']);
    }

    public function test_compromised_password_provider_failure_degrades_safely(): void
    {
        $this->openAdmissions();
        Http::fake(fn () => throw new RuntimeException('Simulated compromised-password provider failure.'));

        $applicant = app(CreateNewUser::class)->create([
            'email' => 'provider-failure@example.test',
            'password' => 'Valid1!passphrase',
            'password_confirmation' => 'Valid1!passphrase',
            'privacy_acknowledged' => true,
        ]);

        $this->assertSame('provider-failure@example.test', $applicant->email);
        $this->assertTrue($applicant->hasRole('applicant'));
    }

    public function test_account_creation_rechecks_readiness_after_the_entry_page_was_loaded(): void
    {
        $admissionsWindow = $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        AdmissionCycle::query()
            ->whereKeyNot($admissionsWindow->id)
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereHas('term', fn (Builder $query): Builder => $query->where('state', Term::StateActive))
            ->update(['closes_at' => now()->subMinute()]);
        $admissionsWindow->update(['closes_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);

        app(CreateNewUser::class)->create([
            'email' => 'applicant@example.test',
            'password' => 'Valid1!passphrase',
            'password_confirmation' => 'Valid1!passphrase',
            'privacy_acknowledged' => true,
        ]);
    }

    public function test_parallel_fortify_registration_endpoint_is_disabled(): void
    {
        $this->post('/register', [
            'email' => 'applicant@example.test',
            'password' => 'Valid1!passphrase',
            'password_confirmation' => 'Valid1!passphrase',
            'privacy_acknowledged' => true,
        ])->assertNotFound();
    }

    public function test_filament_registration_uses_only_the_minimal_account_fields(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RegisterApplicant::class)
            ->assertFormFieldDoesNotExist('name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('password')
            ->assertFormFieldExists('password_confirmation')
            ->assertFormFieldExists('privacy_acknowledged')
            ->fillForm([
                'email' => 'new-applicant@example.test',
                'password' => 'Another1!passphrase',
                'password_confirmation' => 'Another1!passphrase',
                'privacy_acknowledged' => true,
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $applicant = User::query()->where('email', 'new-applicant@example.test')->sole();

        $this->assertAuthenticatedAs($applicant);
        $this->assertTrue($applicant->hasRole('applicant'));
        $this->assertNull($applicant->name);
        $this->assertNotNull($applicant->email_verification_nonce);
        Notification::assertSentTo($applicant, VerifyEmail::class);
    }

    public function test_registration_validation_errors_stay_on_their_fields_and_focus_the_first_invalid_field(): void
    {
        $this->openAdmissions();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RegisterApplicant::class)
            ->fillForm([
                'email' => 'new-applicant@example.test',
                'password' => 'short',
                'password_confirmation' => 'different',
                'privacy_acknowledged' => true,
            ])
            ->call('register')
            ->assertHasFormErrors(['password'])
            ->assertDispatched('focus-applicant-password')
            ->assertNotDispatched('focus-applicant-email')
            ->assertDontSeeHtml('id="applicant-registration-error-summary"');
    }

    public function test_duplicate_account_registration_through_the_form_shows_an_inline_error(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $password = 'Valid1!passphrase';

        app(CreateNewUser::class)->create([
            'email' => 'already-registered@example.test',
            'password' => $password,
            'password_confirmation' => $password,
            'privacy_acknowledged' => true,
        ]);

        Livewire::test(RegisterApplicant::class)
            ->fillForm([
                'email' => 'already-registered@example.test',
                'password' => $password,
                'password_confirmation' => $password,
                'privacy_acknowledged' => true,
            ])
            ->call('register')
            ->assertHasFormErrors(['email'])
            ->assertDispatched('focus-applicant-email');

        $this->assertSame(1, User::query()->where('email', 'already-registered@example.test')->count());
    }

    public function test_resending_verification_supersedes_the_previous_link_and_success_clears_the_nonce(): void
    {
        $applicant = User::factory()->unverified()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'status' => User::StatusActive,
        ]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $dispatch = app(DispatchApplicantEmailVerification::class);

        $this->assertTrue($dispatch->execute($applicant));
        $firstUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;
        $firstNonce = $applicant->fresh()->email_verification_nonce;

        $this->assertTrue($dispatch->execute($applicant->fresh()));
        $secondUrl = Notification::sent($applicant, VerifyEmail::class)->last()->url;
        $secondNonce = $applicant->fresh()->email_verification_nonce;

        $this->assertNotSame($firstNonce, $secondNonce);
        $this->assertNotSame($firstUrl, $secondUrl);

        $this->actingAs($applicant->fresh())
            ->get($firstUrl)
            ->assertForbidden()
            ->assertSee('Request a new verification link')
            ->assertSee($applicant->getFilamentName());

        $this->actingAs($applicant->fresh())
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->getTimestamp()])
            ->get($secondUrl)
            ->assertRedirect('/applicant')
            ->assertSessionMissing(DispatchApplicantEmailVerification::SentAtSessionKey);

        $this->assertNotNull($applicant->fresh()->email_verified_at);
        $this->assertNull($applicant->fresh()->email_verification_nonce);
    }

    public function test_reused_verification_link_gives_an_already_verified_applicant_a_safe_workspace_action(): void
    {
        $applicant = User::factory()->unverified()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'status' => User::StatusActive,
        ]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));
        $verificationUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;

        $this->actingAs($applicant->fresh())
            ->get($verificationUrl)
            ->assertRedirect('/applicant');

        $this->actingAs($applicant->fresh())
            ->get($verificationUrl)
            ->assertForbidden()
            ->assertSee('Return to your application');

        $this->assertNotNull($applicant->fresh()->email_verified_at);
        $this->assertNull($applicant->fresh()->email_verification_nonce);
    }

    public function test_expired_and_malformed_verification_links_preserve_a_recovery_action(): void
    {
        $applicant = User::factory()->unverified()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'status' => User::StatusActive,
        ]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));
        $verificationUrl = Notification::sent($applicant, VerifyEmail::class)->sole()->url;

        $this->travel(61)->minutes();
        $this->actingAs($applicant->fresh())
            ->get($verificationUrl)
            ->assertForbidden()
            ->assertSee('Request a new verification link');
        $this->travelBack();

        $malformedUrl = route('filament.applicant.auth.email-verification.verify', [
            'id' => $applicant->id,
            'hash' => sha1($applicant->fresh()->getEmailForVerification()),
        ]);

        $this->actingAs($applicant->fresh())
            ->get($malformedUrl)
            ->assertForbidden()
            ->assertSee('Request a new verification link');
    }

    public function test_immediate_verification_dispatch_failure_preserves_the_account_for_recovery(): void
    {
        $applicant = new class extends User
        {
            public function notify($instance)
            {
                throw new RuntimeException('Simulated queue failure.');
            }
        };
        $applicant->setTable('users');
        $applicant->forceFill([
            'name' => null,
            'email' => 'dispatch-failure@example.test',
            'password' => 'Valid1!passphrase',
            'status' => User::StatusActive,
        ])->save();

        $this->assertFalse(app(DispatchApplicantEmailVerification::class)->execute($applicant));
        $this->assertDatabaseHas('users', [
            'id' => $applicant->id,
            'email' => 'dispatch-failure@example.test',
            'email_verified_at' => null,
        ]);
        $this->assertNotNull($applicant->fresh()->email_verification_nonce);
    }

    public function test_verification_prompt_resends_once_per_minute_without_exposing_the_nonce(): void
    {
        $applicant = User::factory()->unverified()->create([
            'status' => User::StatusActive,
            'email_verification_nonce' => 'existing-secret-nonce',
        ]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        RateLimiter::clear('applicant-email-verification-resend:'.$applicant->id);

        $component = Livewire::actingAs($applicant)
            ->test(ApplicantEmailVerification::class)
            ->assertSee('A verification link was sent to your email address.')
            ->assertDontSee('existing-secret-nonce')
            ->callAction('resendNotification');

        $rotatedNonce = $applicant->fresh()->email_verification_nonce;

        $this->assertNotSame('existing-secret-nonce', $rotatedNonce);
        Notification::assertSentToTimes($applicant, VerifyEmail::class, 1);

        $component->callAction('resendNotification');

        $this->assertSame($rotatedNonce, $applicant->fresh()->email_verification_nonce);
        Notification::assertSentToTimes($applicant, VerifyEmail::class, 1);
    }

    public function test_verification_page_shows_a_live_expiry_countdown_and_no_sign_out_or_recovery_links(): void
    {
        $applicant = User::factory()->unverified()->create(['status' => User::StatusActive]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');

        $this->actingAs($applicant)
            ->withSession([DispatchApplicantEmailVerification::SentAtSessionKey => now()->getTimestamp()])
            ->get('/applicant/email-verification/prompt')
            ->assertOk()
            ->assertSee('Verify your account')
            ->assertSee('Link expires in 60:00')
            ->assertSee('Only the most recent verification link can be used.')
            ->assertSee('Resend verification link')
            ->assertSee('Back to sign in')
            ->assertSee('Contact support')
            ->assertDontSee('Sign out')
            ->assertDontSee('Back to home')
            ->assertDontSee('Need help?');
    }

    public function test_sending_a_verification_link_starts_the_expiry_countdown_from_that_send(): void
    {
        $applicant = User::factory()->unverified()->create(['status' => User::StatusActive]);
        Notification::fake();
        $this->withSession([]);
        $this->assertNull(DispatchApplicantEmailVerification::expiresAt());

        $this->assertTrue(app(DispatchApplicantEmailVerification::class)->execute($applicant));

        $this->assertSame(now()->addMinutes(60)->getTimestamp(), DispatchApplicantEmailVerification::expiresAt()?->getTimestamp());
    }

    public function test_verification_countdown_falls_back_to_the_window_when_no_send_time_is_known(): void
    {
        $applicant = User::factory()->unverified()->create(['status' => User::StatusActive]);
        Role::findOrCreate('applicant', 'web');
        $applicant->assignRole('applicant');

        $this->actingAs($applicant)
            ->get('/applicant/email-verification/prompt')
            ->assertOk()
            ->assertSee('Link expires 60 minutes after it was sent.');
    }

    public function test_login_hides_register_link_when_admissions_are_closed_and_shows_it_when_open(): void
    {
        AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->update(['state' => AdmissionCycle::StateDraft]);

        $this->get(route('public.sign-in'))
            ->assertOk()
            ->assertDontSee('New applicant? Apply for admission')
            ->assertDontSee(route('filament.applicant.auth.register'), false);

        $this->get(route('filament.applicant.auth.login'))
            ->assertOk()
            ->assertDontSee('New applicant? Apply for admission')
            ->assertDontSee(route('filament.applicant.auth.register'), false)
            ->assertSee('Check admission availability');

        $this->openAdmissions();

        $this->get(route('public.sign-in'))
            ->assertOk()
            ->assertSee('New applicant? Apply for admission');

        $this->get(route('filament.applicant.auth.login'))
            ->assertOk()
            ->assertSee('New applicant? Apply for admission')
            ->assertDontSee('Check admission availability');
    }

    public function test_register_page_renders_styled_closed_card_and_blocks_submission_when_admissions_are_closed_or_upcoming(): void
    {
        AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->update(['state' => AdmissionCycle::StateDraft]);

        $this->get(route('filament.applicant.auth.register'))
            ->assertOk()
            ->assertSee('Applications are currently closed')
            ->assertSee('Existing applicants, students, and staff can still sign in to access their accounts.')
            ->assertSee('Back to sign in')
            ->assertSee('Back to school site')
            ->assertSee('Contact support')
            ->assertSee('data-tala-registration-closed', false)
            ->assertDontSee('Create account');

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RegisterApplicant::class)
            ->assertSee('Applications are currently closed')
            ->set('data.email', 'closed-attempt@example.test')
            ->set('data.password', 'Valid1!passphrase')
            ->set('data.password_confirmation', 'Valid1!passphrase')
            ->set('data.privacy_acknowledged', true)
            ->call('register')
            ->assertNoRedirect()
            ->assertNotified('Applications are currently closed');

        $this->assertDatabaseMissing('users', ['email' => 'closed-attempt@example.test']);

        $term = Term::query()->where('state', Term::StateActive)->first()
            ?? Term::factory()->create(['state' => Term::StateActive]);

        $upcoming = AdmissionCycle::factory()->for($term)->published()->create([
            'label' => 'SY 2027-2028 First Intake',
            'opens_at' => now()->addDays(5),
            'closes_at' => now()->addDays(30),
        ]);

        $this->get(route('filament.applicant.auth.register'))
            ->assertOk()
            ->assertSee('Applications have not opened yet')
            ->assertSee($upcoming->label)
            ->assertSee('Applications open')
            ->assertSee('Back to sign in')
            ->assertDontSee('Create account');
    }

    private function openAdmissions(): AdmissionCycle
    {
        $term = Term::query()->where('state', Term::StateActive)->first()
            ?? Term::factory()->create(['state' => Term::StateActive]);

        return AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
    }

    private function configureReadyApplicantEntry(): void
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

    }
}
