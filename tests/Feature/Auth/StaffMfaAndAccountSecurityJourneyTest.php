<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\StaffMfaService;
use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\UserSessionService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\MyAvailability;
use App\Models\AdmissionApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffMfaAndAccountSecurityJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_app_authentication_uses_encrypted_columns_and_single_use_recovery_codes(): void
    {
        $user = User::factory()->create();
        $user->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $codes = ['alpha-recovery-code', 'beta-recovery-code'];

        $provider->saveSecret($user, $secret);
        $provider->saveRecoveryCodes($user, $codes);
        $user->acknowledgeRecoveryCodeStorage();

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($secret, $raw->two_factor_secret);
        $this->assertStringNotContainsString('alpha-recovery-code', (string) $raw->two_factor_recovery_codes);
        $this->assertNotNull($user->fresh()->two_factor_recovery_codes_acknowledged_at);
        $this->assertTrue($provider->verifyRecoveryCode('alpha-recovery-code', $user));
        $this->assertFalse($provider->verifyRecoveryCode('alpha-recovery-code', $user));

        $provider->saveRecoveryCodes($user, ['replacement-recovery-code']);
        $this->assertFalse($provider->verifyRecoveryCode('beta-recovery-code', $user));
        $this->assertTrue($provider->verifyRecoveryCode('replacement-recovery-code', $user));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => 'mfa_recovery_codes_regenerated',
        ]);
    }

    public function test_mfa_setup_qr_code_is_a_single_valid_image_data_uri(): void
    {
        $staff = User::factory()->create(['email' => 'mfa-qr-fixture@example.test']);
        $staff->assignRole(User::StaffRoleFaculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $dataUri = $provider->generateQrCodeDataUri($provider->generateSecret());
        $svgPrefix = 'data:image/svg+xml;base64,';
        $pngPrefix = 'data:image/png;base64,';

        $this->assertTrue(
            str_starts_with($dataUri, $svgPrefix) || str_starts_with($dataUri, $pngPrefix),
            'The generated MFA QR code must be a supported inline image.',
        );

        $prefix = str_starts_with($dataUri, $svgPrefix) ? $svgPrefix : $pngPrefix;
        $image = base64_decode(substr($dataUri, strlen($prefix)), strict: true);

        $this->assertIsString($image);
        $this->assertStringNotContainsString('data:image/', $image);

        if ($prefix === $svgPrefix) {
            $this->assertStringContainsString('<svg', $image);
        } else {
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image);
        }
    }

    public function test_mfa_qr_code_normalization_removes_only_a_duplicate_svg_data_uri_layer(): void
    {
        $provider = new class(app(Google2FA::class)) extends TalaAppAuthentication
        {
            public function normalizeForTest(#[\SensitiveParameter] string $dataUri): string
            {
                return $this->normalizeQrCodeDataUri($dataUri);
            }
        };
        $svgDataUri = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $duplicateSvgDataUri = 'data:image/svg+xml;base64,'.base64_encode($svgDataUri);
        $pngDataUri = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n");

        $this->assertSame($svgDataUri, $provider->normalizeForTest($duplicateSvgDataUri));
        $this->assertSame($svgDataUri, $provider->normalizeForTest($svgDataUri));
        $this->assertSame($pngDataUri, $provider->normalizeForTest($pngDataUri));
    }

    public function test_authorized_reset_removes_factor_codes_and_sessions_then_requires_reenrollment(): void
    {
        $actor = User::factory()->create(['password' => Hash::make('administrator password')]);
        $actor->assignRole(User::StaffRoleSystemSuperAdmin);
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);
        $target->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $target->saveAppAuthenticationRecoveryCodes(['hashed-code']);
        DB::table('sessions')->insert([
            'id' => 'clinic1-target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        app(StaffMfaService::class)->reset(
            actor: $actor,
            target: $target,
            actorPassword: 'administrator password',
            reason: 'Verified lost authenticator during an in-person identity check.',
            authority: 'System Administrator recovery procedure',
        );

        $target->refresh();
        $this->assertNull($target->getAppAuthenticationSecret());
        $this->assertNull($target->getAppAuthenticationRecoveryCodes());
        $this->assertSame(User::StatusVerificationRequired, $target->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    public function test_staff_capable_accounts_use_stricter_session_policy(): void
    {
        $staffLearner = User::factory()->create();
        $staffLearner->assignRole(['student', User::StaffRoleFaculty]);
        $learner = User::factory()->create();
        $learner->assignRole('student');

        $service = app(UserSessionService::class);

        $this->assertFalse($service->rememberAllowed($staffLearner));
        $this->assertSame(30, $service->idleTimeoutMinutes($staffLearner));
        $this->assertTrue($service->rememberAllowed($learner));
        $this->assertSame(120, $service->idleTimeoutMinutes($learner));
    }

    public function test_mfa_reset_rejects_an_incorrect_actor_password_without_change(): void
    {
        $actor = User::factory()->create(['password' => Hash::make('correct password')]);
        $actor->assignRole(User::StaffRoleSystemSuperAdmin);
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);
        $target->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        try {
            app(StaffMfaService::class)->reset(
                $actor,
                $target,
                'wrong password',
                'Verified lost authenticator during an in-person identity check.',
                'System Administrator recovery procedure',
            );
            $this->fail('An invalid actor password must reject the reset.');
        } catch (ValidationException) {
            $this->assertSame('JBSWY3DPEHPK3PXP', $target->fresh()->getAppAuthenticationSecret());
        }
    }

    public function test_completed_password_recovery_invalidates_existing_sessions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('student');
        DB::table('sessions')->insert([
            'id' => 'clinic1-password-recovery-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'replacement password 2026',
            'password_confirmation' => 'replacement password 2026',
        ]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => 'password_recovery_completed',
        ]);
        $projected = app(GovernanceEvidenceProjection::class)->paginate(
            GovernanceEvidenceProjection::SystemEvents,
            1,
            25,
            'password_recovery_completed',
            [],
        );
        $this->assertCount(1, $projected->items());
    }

    public function test_staff_without_mfa_is_sent_to_setup_while_learner_only_account_is_not(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(User::StaffRoleFaculty);

        $this->actingAs($staff)
            ->get('/admin')
            ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));

        $learner = User::factory()->create();
        $learner->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $learner->id]);

        $this->actingAs($learner)
            ->get('/student')
            ->assertOk();
    }

    public function test_staff_mfa_gate_applies_in_every_authorized_panel_and_valid_totp_is_accepted(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(['applicant', 'student', User::StaffRoleFaculty]);
        StudentProfile::factory()->create(['user_id' => $staff->id]);
        AdmissionApplication::factory()->create([
            'user_id' => $staff->id,
            'application_state' => AdmissionApplication::StateActionNeeded,
        ]);

        foreach ([
            'applicant' => 'applicant',
            'student' => 'student',
            'admin' => User::StaffRoleFaculty,
        ] as $panel => $context) {
            $this->actingAs($staff)
                ->withSession([WorkspaceContextResolver::SessionKey => $context])
                ->get("/{$panel}")
                ->assertRedirect(route("filament.{$panel}.auth.multi-factor-authentication.set-up-required"));
        }

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $provider->saveSecret($staff, $secret);
        $currentCode = $provider->getCurrentCode($staff, $secret);

        $this->actingAs($staff);
        $this->assertTrue($provider->verifyCode($currentCode, $secret));
        $this->assertFalse($provider->verifyCode('000000', $secret));
    }

    public function test_contextual_login_accepts_one_recovery_code_and_rejects_its_reuse(): void
    {
        $staff = User::factory()->create([
            'email' => 'recovery-login@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());
        $provider->saveRecoveryCodes($staff, ['single-use-recovery']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        $login
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', 'single-use-recovery')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(MyAvailability::getUrl(panel: 'admin'));

        $this->assertSame([], $staff->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_recovery_code_input_keeps_the_native_on_blur_binding(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());

        $recoveryCode = $provider->getChallengeFormComponents($staff)[1];

        $this->assertTrue($recoveryCode->isLiveOnBlur());
        $this->assertSame(['live', 'blur'], $recoveryCode->getStateBindingModifiers());
    }

    public function test_login_and_mfa_failures_are_limited_to_five_per_normalized_account_and_ip(): void
    {
        $staff = User::factory()->create([
            'email' => 'rate-limit@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $provider->saveSecret($staff, $secret);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(Hash::check('a secure password 2026', (string) $staff->fresh()->password));
        $activeProvider = collect(Filament::getMultiFactorAuthenticationProviders())->first();
        $this->assertInstanceOf(TalaAppAuthentication::class, $activeProvider);
        $this->assertTrue($activeProvider->isEnabled($staff->fresh()));

        $loginKey = 'tala-login:rate-limit@example.test|127.0.0.1';
        $mfaKey = 'tala-mfa:'.$staff->getAuthIdentifier().'|127.0.0.1';
        RateLimiter::clear($loginKey);
        RateLimiter::clear($mfaKey);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            Livewire::test(ContextualLogin::class)
                ->set('data.email', ' RATE-LIMIT@example.test ')
                ->set('data.password', 'wrong password')
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }

        $this->assertSame(5, RateLimiter::attempts($loginKey));

        RateLimiter::clear($loginKey);
        $this->assertSame(0, RateLimiter::attempts($loginKey));
        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));
        $currentCode = $provider->getCurrentCode($staff, $secret);
        $invalidCode = $currentCode === '000000' ? '111111' : '000000';
        $this->assertSame(0, RateLimiter::attempts($mfaKey));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $login
                ->set('data.multiFactor.app.code', $invalidCode)
                ->call('authenticate')
                ->assertHasErrors(['data.multiFactor.app.code']);
            $this->assertSame($attempt, RateLimiter::attempts($mfaKey));
        }

        $this->assertSame(5, RateLimiter::attempts($mfaKey));

        RateLimiter::clear($loginKey);
        RateLimiter::clear($mfaKey);
        $login
            ->set('data.multiFactor.app.code', $currentCode)
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(MyAvailability::getUrl(panel: 'admin'));

        $this->assertSame(0, RateLimiter::attempts($mfaKey));
    }

    public function test_account_security_is_reachable_from_every_workspace(): void
    {
        $applicant = User::factory()->create();
        $applicant->assignRole('applicant');
        $this->actingAs($applicant)
            ->get(route('filament.applicant.auth.profile'))
            ->assertOk()
            ->assertSee('Account Security');

        $student = User::factory()->create();
        $student->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student)
            ->get(route('filament.student.auth.profile'))
            ->assertOk()
            ->assertSee('Account Security');

        $staff = User::factory()->create();
        $staff->assignRole(User::StaffRoleFaculty);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $this->actingAs($staff)
            ->get(route('filament.admin.auth.profile'))
            ->assertOk()
            ->assertSee('Account Security')
            ->assertSee('Staff-capable account email changes are managed by a System Administrator.')
            ->assertSee('Authorized access and sessions')
            ->assertSee('30-minute idle timeout');
    }

    public function test_learner_email_change_keeps_the_old_address_and_alerts_both_addresses_until_verification(): void
    {
        Notification::fake();
        $applicant = User::factory()->create(['email' => 'current-learner@example.test']);
        $applicant->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->actingAs($applicant);

        Livewire::test(AccountSecurity::class)
            ->fillForm([
                'email' => 'successor-learner@example.test',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('current-learner@example.test', $applicant->fresh()->email);
        Notification::assertSentTo($applicant, NoticeOfEmailChangeRequest::class);
        Notification::assertSentOnDemand(VerifyEmailChange::class);
    }

    public function test_mfa_challenge_exposes_use_another_account_action_and_resets_pending_state(): void
    {
        $staff = User::factory()->create([
            'email' => 'mfa-restart@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertSee('Use another account');

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->set('data.multiFactor.app.code', '999999')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);

        $login->callAction('useAnotherAccount')
            ->assertHasNoErrors()
            ->assertDispatched('focus-email-input')
            ->assertDontSee('Use another account')
            ->assertSeeHtml('x-on:focus-email-input.window');

        $this->assertNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertNull($login->get('data.email'));
        $this->assertNull($login->get('data.password'));
        $this->assertSame('Sign in to Staff', $login->instance()->getHeading());
    }

    public function test_restarting_mfa_challenge_allows_second_account_to_authenticate_without_credential_leak(): void
    {
        $staff = User::factory()->create([
            'email' => 'staff-first@example.test',
            'password' => 'first account password',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());

        $student = User::factory()->create([
            'email' => 'student-second@example.test',
            'password' => 'second account password',
        ]);
        $student->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $student->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'first account password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->callAction('useAnotherAccount')
            ->assertHasNoErrors();

        $login->set('data.email', $student->email)
            ->set('data.password', 'second account password')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/student');

        $this->assertAuthenticatedAs($student);
    }

    public function test_restarting_mfa_does_not_reset_rate_limiting_counters_or_consume_recovery_codes(): void
    {
        $staff = User::factory()->create([
            'email' => 'staff-rate-limit-preservation@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);
        $provider = app(TalaAppAuthentication::class)->recoverable();
        $secret = $provider->generateSecret();
        $provider->saveSecret($staff, $secret);
        $provider->saveRecoveryCodes($staff, ['unconsumed-recovery-code']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $mfaKey = 'tala-mfa:'.$staff->getAuthIdentifier().'|127.0.0.1';
        RateLimiter::clear($mfaKey);

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->set('data.multiFactor.app.code', '999999')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);

        $this->assertSame(1, RateLimiter::attempts($mfaKey));

        $login->callAction('useAnotherAccount');

        $this->assertSame(1, RateLimiter::attempts($mfaKey));
        $this->assertCount(1, $staff->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue($provider->verifyRecoveryCode('unconsumed-recovery-code', $staff->fresh()));
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $staff->id,
            'event' => 'mfa_challenge_succeeded',
        ]);
    }

    public function test_restarting_mfa_challenge_from_mfa_account_a_to_mfa_account_b(): void
    {
        $provider = app(TalaAppAuthentication::class)->recoverable();

        $userA = User::factory()->create([
            'email' => 'staff-a@example.test',
            'password' => 'password a 2026',
        ]);
        $userA->assignRole(User::StaffRoleFaculty);
        $secretA = $provider->generateSecret();
        $provider->saveSecret($userA, $secretA);
        $provider->saveRecoveryCodes($userA, ['recovery-a-code']);

        $userB = User::factory()->create([
            'email' => 'staff-b@example.test',
            'password' => 'password b 2026',
        ]);
        $userB->assignRole(User::StaffRoleRegistrar);
        $secretB = $provider->generateSecret();
        $provider->saveSecret($userB, $secretB);
        $provider->saveRecoveryCodes($userB, ['recovery-b-code']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $userA->email)
            ->set('data.password', 'password a 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        // 1. User A is in challenge
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertGuest();

        // 2. User A enters invalid code, generating error
        $login->set('data.multiFactor.app.code', '999999')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);

        // 3. User clicks "Use another account"
        $login->callAction('useAnotherAccount')
            ->assertHasNoErrors()
            ->assertDispatched('focus-email-input');

        // 4. Assert challenge identity cleared, credential data cleared, errors cleared
        $this->assertNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertNull($login->get('data.email'));
        $this->assertNull($login->get('data.password'));
        $this->assertGuest();

        // 5. Enter Account B credentials
        $login->set('data.email', $userB->email)
            ->set('data.password', 'password b 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        // 6. User B now enters MFA challenge, and remains guest
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));
        $this->assertGuest();

        // 7. Submitting User A's TOTP or invalid code fails for User B
        $totpA = $provider->getCurrentCode($userA, $secretA);
        $invalidForB = $totpA === $provider->getCurrentCode($userB, $secretB) ? '000000' : $totpA;

        $login->set('data.multiFactor.app.code', $invalidForB)
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);
        $this->assertGuest();

        // 8. Submitting User B's own valid TOTP succeeds
        $totpB = $provider->getCurrentCode($userB, $secretB);

        $login->set('data.multiFactor.app.code', $totpB)
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect('/admin/admission-applications');

        // 9. Fully authenticated as User B
        $this->assertAuthenticatedAs($userB);

        // 10. User A and B recovery codes untouched and unconsumed
        $this->assertCount(1, $userA->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue($provider->verifyRecoveryCode('recovery-a-code', $userA->fresh()));
        $this->assertCount(1, $userB->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue($provider->verifyRecoveryCode('recovery-b-code', $userB->fresh()));
    }
}
