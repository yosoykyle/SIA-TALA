<?php

namespace Tests\Feature\Auth;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Filament\Applicant\Pages\Auth\ApplicantEmailVerification;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Models\AdmissionCycle;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
    use LazilyRefreshDatabase;

    public function test_open_admissions_exposes_registration_without_an_invented_mail_readiness_toggle(): void
    {
        $this->openAdmissions();
        config()->set('institution.public.support_facebook_url', null);

        $this->get('/')
            ->assertOk()
            ->assertSee('Create Applicant Account')
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee('id="privacyModal"', false)
            ->assertSee('id="accessibilityModal"', false);
    }

    public function test_open_entry_exposes_project_approved_support_and_single_page_public_information(): void
    {
        $this->openAdmissions();

        $this->get('/')
            ->assertOk()
            ->assertSee('Create Applicant Account')
            ->assertSee(route('filament.applicant.auth.register'), false)
            ->assertSee('https://www.facebook.com/servitechinstituteasiaph', false)
            ->assertSee('0947 737 9208')
            ->assertSee('Applicant Privacy Notice')
            ->assertSee('modal-fullscreen-sm-down', false);
    }

    public function test_closed_entry_keeps_existing_applicant_sign_in_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Applications are currently closed')
            ->assertSee(route('filament.applicant.auth.login'), false)
            ->assertDontSee(route('filament.applicant.auth.register'), false);
    }

    public function test_ready_entry_creates_only_one_minimal_unverified_applicant_account(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();

        $applicant = app(CreateNewUser::class)->create([
            'email' => '  Applicant@Example.TEST  ',
            'password' => 'a valid passphrase',
            'password_confirmation' => 'a valid passphrase',
            'privacy_acknowledged' => true,
        ]);

        $this->assertNull($applicant->name);
        $this->assertSame('applicant@example.test', $applicant->email);
        $this->assertSame(User::StatusActive, $applicant->status);
        $this->assertNull($applicant->email_verified_at);
        $this->assertSame(route('home', ['modal' => 'privacy']), $applicant->privacy_notice_reference);
        $this->assertNotNull($applicant->privacy_acknowledged_at);
        $this->assertTrue(Hash::check('a valid passphrase', $applicant->password));
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
                'password' => 'a valid passphrase',
                'password_confirmation' => 'a valid passphrase',
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

        foreach ([
            'too-short@example.test' => str_repeat('a', 14),
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
        }

        $this->assertDatabaseMissing('users', ['email' => 'too-short@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'too-long@example.test']);
    }

    public function test_duplicate_account_creation_uses_non_disclosing_feedback(): void
    {
        $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        $password = 'a valid passphrase';

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
                'password' => 'a valid passphrase',
                'status' => User::StatusActive,
            ]));
        });

        try {
            app(CreateNewUser::class)->create([
                'email' => 'race@example.test',
                'password' => 'a valid passphrase',
                'password_confirmation' => 'a valid passphrase',
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
        $password = 'a compromised passphrase';
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
            'password' => 'a valid passphrase',
            'password_confirmation' => 'a valid passphrase',
            'privacy_acknowledged' => true,
        ]);

        $this->assertSame('provider-failure@example.test', $applicant->email);
        $this->assertTrue($applicant->hasRole('applicant'));
    }

    public function test_account_creation_rechecks_readiness_after_the_entry_page_was_loaded(): void
    {
        $admissionsWindow = $this->openAdmissions();
        $this->configureReadyApplicantEntry();
        $admissionsWindow->update(['closes_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);

        app(CreateNewUser::class)->create([
            'email' => 'applicant@example.test',
            'password' => 'a valid passphrase',
            'password_confirmation' => 'a valid passphrase',
            'privacy_acknowledged' => true,
        ]);
    }

    public function test_parallel_fortify_registration_endpoint_is_disabled(): void
    {
        $this->post('/register', [
            'email' => 'applicant@example.test',
            'password' => 'a valid passphrase',
            'password_confirmation' => 'a valid passphrase',
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
                'password' => 'another valid passphrase',
                'password_confirmation' => 'another valid passphrase',
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

    public function test_registration_validation_errors_have_an_announced_focusable_summary(): void
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
            ->assertSeeHtml('id="applicant-registration-error-summary"')
            ->assertSeeHtml('role="alert"')
            ->assertSeeHtml('aria-live="assertive"')
            ->assertSeeHtml('data-error-field="password"')
            ->assertSee('Review the highlighted fields');
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
            ->get($secondUrl)
            ->assertRedirect('/applicant');

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
            ->assertSee('Return to Applicant Workspace');

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
            'password' => 'a valid passphrase',
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
            ->assertSee($applicant->email)
            ->assertDontSee('existing-secret-nonce')
            ->callAction('resendNotification');

        $rotatedNonce = $applicant->fresh()->email_verification_nonce;

        $this->assertNotSame('existing-secret-nonce', $rotatedNonce);
        Notification::assertSentToTimes($applicant, VerifyEmail::class, 1);

        $component->callAction('resendNotification');

        $this->assertSame($rotatedNonce, $applicant->fresh()->email_verification_nonce);
        Notification::assertSentToTimes($applicant, VerifyEmail::class, 1);
    }

    private function openAdmissions(): AdmissionCycle
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);

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
