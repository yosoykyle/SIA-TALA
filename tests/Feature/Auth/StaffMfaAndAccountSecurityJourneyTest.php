<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\StaffMfaService;
use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\FacultyOverview;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_mandatory_mfa_setup_renders_ambient_backdrop_and_omits_unreachable_menu_links_across_panels(): void
    {
        $multiRoleStaff = User::factory()->create();
        $multiRoleStaff->assignRole([User::StaffRoleFaculty, 'student', 'applicant']);
        StudentProfile::factory()->create(['user_id' => $multiRoleStaff->id]);
        AdmissionApplication::factory()->create([
            'user_id' => $multiRoleStaff->id,
            'application_state' => AdmissionApplication::StateActionNeeded,
        ]);

        foreach ([
            'admin' => User::StaffRoleFaculty,
            'student' => 'student',
            'applicant' => 'applicant',
        ] as $panelId => $context) {
            Filament::setCurrentPanel(Filament::getPanel($panelId));
            $this->actingAs($multiRoleStaff)
                ->withSession([WorkspaceContextResolver::SessionKey => $context])
                ->get('/'.$panelId.'/multi-factor-authentication/set-up')
                ->assertOk()
                ->assertSee('tala-ambient', false)
                ->assertSee('tala-ambient-glow--a', false)
                ->assertSee('tala-ambient-glow--b', false)
                ->assertSee('tala-ambient-trinket', false)
                ->assertSee('Set up authenticator app')
                ->assertSee('Set up an authenticator app and save your recovery codes before continuing.')
                ->assertDontSee('adds an extra layer')
                ->assertSee('Contact support')
                ->assertSee('Sign out')
                ->assertDontSee('/'.$panelId.'/account-profile', false)
                ->assertDontSee('database-notifications', false)
                ->assertDontSee('fi-topbar-database-notifications-btn', false)
                ->assertDontSee("Applicant's History");
        }
    }

    public function test_staff_can_open_the_two_step_authenticator_setup(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(User::StaffRoleFaculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff);

        $action = TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content');

        $livewire = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
            ->mountAction($action)
            ->assertActionMounted($action);

        $modalHtml = $livewire->getMountedActionModalHtml();
        $this->assertStringContainsString('tala-mfa-modal', $modalHtml);
        $this->assertStringContainsString('tala-mfa-setup-modal', $modalHtml);
        $this->assertStringContainsString('tala-mfa-setup-qr-card', $modalHtml);
        $this->assertStringContainsString('tala-mfa-setup-verify-col', $modalHtml);
        $this->assertStringContainsString('tala-mfa-recovery-step', $modalHtml);
        $this->assertStringContainsString('Set up two-factor authentication', $modalHtml);
        $this->assertStringContainsString('Connect and verify', $modalHtml);
        $this->assertStringContainsString('Save recovery codes', $modalHtml);
        $this->assertStringContainsString('Current password', $modalHtml);
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
            ->set('data.multiFactor.app.code', '123')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);

        $login
            ->callAction(TestAction::make('useRecoveryCode')->schemaComponent('app.code', 'multiFactorChallengeForm'))
            ->assertHasNoErrors()
            ->assertSet('data.multiFactor.app.useRecoveryCode', true)
            ->assertSet('data.multiFactor.app.code', null)
            ->assertSeeHtml('required="required"')
            ->set('data.multiFactor.app.recoveryCode', " single-use-recovery\n")
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(FacultyOverview::getUrl(panel: 'admin'));

        $this->assertSame([], $staff->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_invalid_or_blank_recovery_code_validates_only_recovery_code_and_never_code_field(): void
    {
        $staff = User::factory()->create([
            'email' => 'mfa-recovery-invalid@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());
        $provider->saveRecoveryCodes($staff, ['PREV-9301-AAAA']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        $login
            ->callAction(TestAction::make('useRecoveryCode')->schemaComponent('app.code', 'multiFactorChallengeForm'))
            ->set('data.multiFactor.app.recoveryCode', '')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.recoveryCode'])
            ->assertHasNoErrors(['data.multiFactor.app.code']);

        $login
            ->set('data.multiFactor.app.recoveryCode', 'WRONG-CODE-9999')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.recoveryCode'])
            ->assertHasNoErrors(['data.multiFactor.app.code']);
    }

    public function test_all_auth_screens_reuse_native_html5_constraints_and_shared_client_on_submit_validation(): void
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

        foreach (['/admin/login', '/admin/password-reset/request', '/applicant/register'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('type="email"', false)
                ->assertSee('required="required"', false)
                ->assertSee('novalidate', false)
                ->assertSee('js/tala-auth-validation.js', false);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ContextualLogin::class)
            ->set('data.email', 'partial-email-while-typing')
            ->set('data.password', 'short')
            ->assertHasNoErrors()
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::test(RegisterApplicant::class)
            ->set('data.email', 'not-yet-an-email')
            ->set('data.password', 'typing-in')
            ->assertHasNoErrors()
            ->assertSee('9 of 15–64 characters entered (minimum 15)')
            ->call('register')
            ->assertHasErrors(['data.email', 'data.password', 'data.password_confirmation', 'data.privacy_acknowledged']);
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
            ->assertRedirect(FacultyOverview::getUrl(panel: 'admin'));

        $this->assertSame(0, RateLimiter::attempts($mfaKey));
    }

    public function test_account_security_is_reachable_from_every_workspace(): void
    {
        $this->assertStringEndsWith('/applicant/account-profile', route('filament.applicant.auth.profile'));
        $this->assertStringEndsWith('/student/account-profile', route('filament.student.auth.profile'));
        $this->assertStringEndsWith('/admin/account-profile', route('filament.admin.auth.profile'));

        $applicant = User::factory()->create();
        $applicant->assignRole('applicant');
        $this->actingAs($applicant)
            ->get('/applicant/account-security?source=legacy')
            ->assertRedirect(AccountSecurity::getUrl(panel: 'applicant').'?source=legacy');
        $this->actingAs($applicant)
            ->get(route('filament.applicant.auth.profile'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Upload photo')
            ->assertSee('120 minutes of inactivity')
            ->assertSee('Remember device')
            ->assertSee('Optional at sign-in')
            ->assertDontSee('Available workspaces')
            ->assertDontSee('Staff identity')
            ->assertSee('tala-profile-access-grid', false)
            ->assertSee('tala-profile-access-item', false);

        $student = User::factory()->create();
        $student->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student)
            ->get('/student/account-security')
            ->assertRedirect(AccountSecurity::getUrl(panel: 'student'));
        $this->actingAs($student)
            ->get(route('filament.student.auth.profile'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Student details')
            ->assertSee('/student/student-profile')
            ->assertSee('Remember device')
            ->assertSee('Optional at sign-in')
            ->assertDontSee('Available workspaces')
            ->assertDontSee('Password and security');

        $staff = User::factory()->create();
        $staff->assignRole(User::StaffRoleFaculty);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $this->actingAs($staff)
            ->get('/admin/account-security')
            ->assertRedirect(AccountSecurity::getUrl(panel: 'admin'));
        $staffResponse = $this->actingAs($staff)
            ->get(route('filament.admin.auth.profile'))
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Staff account email changes are managed by a System Administrator.')
            ->assertSee('Two-factor authentication (2FA)')
            ->assertSee('Access and sessions')
            ->assertSee('30 minutes of inactivity')
            ->assertDontSee('Available workspaces')
            ->assertDontSee('Remember device')
            ->assertDontSee('Unavailable for Staff-capable accounts')
            ->assertDontSee('Staff identity')
            ->assertSee('tala-profile-mfa-section', false)
            ->assertSee('tala-profile-mfa-box', false)
            ->assertSee('tala-profile-access-grid', false)
            ->assertSee('tala-profile-access-item', false);

        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*tala-profile-mfa-section[^"]*"[^>]*>.*?<section[^>]*class="[^"]*\bfi-secondary\b/s',
            $staffResponse->getContent(),
        );
    }

    public function test_unenrolled_applicant_account_security_omits_inapplicable_mfa_setup(): void
    {
        $applicant = User::factory()->create();
        $applicant->assignRole('applicant');

        $this->actingAs($applicant)
            ->get(route('filament.applicant.auth.profile'))
            ->assertOk()
            ->assertSee('Email and password')
            ->assertSee('Access and sessions')
            ->assertSee('Remember device')
            ->assertDontSee('Authenticator app');
    }

    public function test_staff_capable_applicant_retains_native_mfa_management(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(['applicant', User::StaffRoleFaculty]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $staff->acknowledgeRecoveryCodeStorage();

        $this->actingAs($staff)
            ->withSession([WorkspaceContextResolver::SessionKey => 'applicant'])
            ->get(route('filament.applicant.auth.profile'))
            ->assertOk()
            ->assertSee('Authenticator app')
            ->assertSee('Available workspaces')
            ->assertSee('30 minutes of inactivity')
            ->assertDontSee('Staff identity')
            ->assertDontSee('Remember device')
            ->assertDontSee('Unavailable for Staff-capable accounts')
            ->assertSee('Staff account email changes are managed by a System Administrator.');
    }

    public function test_enrolled_applicant_retains_native_mfa_management(): void
    {
        $applicant = User::factory()->create();
        $applicant->assignRole('applicant');
        $applicant->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $applicant->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $applicant->acknowledgeRecoveryCodeStorage();

        $this->actingAs($applicant)
            ->get(route('filament.applicant.auth.profile'))
            ->assertOk()
            ->assertSee('Authenticator app')
            ->assertSee('Enabled');
    }

    public function test_learner_email_change_keeps_the_old_address_and_alerts_both_addresses_until_verification(): void
    {
        Notification::fake();
        $applicant = User::factory()->create(['email' => 'current-learner@example.test']);
        $applicant->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->actingAs($applicant);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
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
            ->call('enableSchemaStateUpdateHooksForTesting')
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
        $this->assertSame('Sign in', $login->instance()->getHeading());

        $login->update(
            updates: [
                'data.email' => $staff->email,
                'data.password' => 'a secure password 2026',
            ],
            calls: [
                ['method' => 'authenticate', 'params' => []],
            ],
        );

        $login
            ->assertHasNoErrors()
            ->assertSeeHtml('id="multiFactorChallengeForm"')
            ->assertSeeHtml('id="multiFactorChallengeForm.app.code"')
            ->assertSee('Enter the 6-digit code from the authenticator app');
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
            ->assertRedirect('/admin/admissions');

        // 9. Fully authenticated as User B
        $this->assertAuthenticatedAs($userB);

        // 10. User A and B recovery codes untouched and unconsumed
        $this->assertCount(1, $userA->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue($provider->verifyRecoveryCode('recovery-a-code', $userA->fresh()));
        $this->assertCount(1, $userB->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue($provider->verifyRecoveryCode('recovery-b-code', $userB->fresh()));
    }

    public function test_profile_page_manages_account_photo_and_applicant_name_fallback(): void
    {
        Storage::fake('local');

        $applicantWithoutApplication = User::factory()->create([
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'name' => '',
        ]);
        $applicantWithoutApplication->assignRole('applicant');
        $this->assertSame('Applicant account', $applicantWithoutApplication->getFilamentName());

        $applicant = User::factory()->create([
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'name' => '',
        ]);
        $applicant->assignRole('applicant');
        AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'first_name' => 'Elena',
            'last_name' => 'Navarro',
        ]);
        $this->assertSame('Elena Navarro', $applicant->fresh()->getFilamentName());

        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->actingAs($applicant);

        $page = Livewire::test(AccountSecurity::class)
            ->assertSee('Elena Navarro')
            ->assertSee('Upload photo')
            ->callAction('changePhoto', [
                'photo' => UploadedFile::fake()->image('applicant.png', 200, 200),
            ])
            ->assertHasNoActionErrors();

        $savedPath = $applicant->fresh()->avatar_path;
        $this->assertNotNull($savedPath);
        $this->assertStringStartsWith('profile-photos/'.$applicant->id.'/', $savedPath);
        Storage::disk('local')->assertExists($savedPath);

        $page->assertSee('Change photo')
            ->assertSee('Remove photo')
            ->callAction('removePhoto')
            ->assertHasNoActionErrors();

        $this->assertNull($applicant->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($savedPath);
    }

    #[DataProvider('accountProfilePanelsAndRoles')]
    public function test_profile_orders_visible_current_password_before_new_password_and_updates_password_safely(string $panelId, string $role): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $user = User::factory()->create([
            'password' => Hash::make('original passphrase 2026'),
            'remember_token' => 'initial-remember-token',
        ]);
        $user->assignRole($role);

        if ($role === 'student') {
            StudentProfile::factory()->for($user)->create();
        }

        DB::table('sessions')->insert([
            'id' => 'other-device-session-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'OtherBrowser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        Filament::setCurrentPanel(Filament::getPanel($panelId));
        $this->actingAs($user);
        session()->put(WorkspaceContextResolver::SessionKey, $role);

        $expectedHelper = $user->isStaffCapable()
            ? 'Required to change your password.'
            : 'Required to change your password or sign-in email.';

        $forgotAction = TestAction::make('forgotPassword')->schemaComponent('currentPassword', 'form');

        $page = Livewire::test(AccountSecurity::class)
            ->assertDontSee('Current password')
            ->assertDontSee('Save changes')
            ->call('startEditingCredentials')
            ->assertSee($expectedHelper)
            ->assertDontSee('For security, please confirm your password to continue.')
            ->assertActionVisible($forgotAction);

        $html = $page->html();
        $emailPosition = strpos($html, 'Verified sign-in email');
        $currentPosition = strpos($html, 'Current password');
        $newPosition = strpos($html, 'New password');
        $confirmPosition = strpos($html, 'Confirm new password');

        $this->assertNotFalse($emailPosition);
        $this->assertNotFalse($currentPosition);
        $this->assertNotFalse($newPosition);
        $this->assertNotFalse($confirmPosition);
        $this->assertTrue($emailPosition < $currentPosition && $currentPosition < $newPosition && $newPosition < $confirmPosition);

        $page->fillForm([
            'currentPassword' => 'wrong current passphrase',
            'password' => 'replacement passphrase 2026',
            'passwordConfirmation' => 'replacement passphrase 2026',
        ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('original passphrase 2026', $user->fresh()->password));
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session-'.$user->id]);

        $page->fillForm([
            'currentPassword' => 'original passphrase 2026',
            'password' => 'replacement passphrase 2026',
            'passwordConfirmation' => 'replacement passphrase 2026',
        ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.currentPassword', null)
            ->assertSet('data.password', null)
            ->assertSet('data.passwordConfirmation', null);

        $freshUser = $user->fresh();
        $this->assertTrue(Hash::check('replacement passphrase 2026', $freshUser->password));
        $this->assertNotSame('initial-remember-token', $freshUser->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session-'.$user->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'event' => 'password_changed',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    /** @return array<string, array{string, string}> */
    public static function accountProfilePanelsAndRoles(): array
    {
        return [
            'applicant panel' => ['applicant', 'applicant'],
            'student panel' => ['student', 'student'],
            'admin faculty' => ['admin', User::StaffRoleFaculty],
            'admin registrar' => ['admin', User::StaffRoleRegistrar],
            'admin accounting' => ['admin', User::StaffRoleAccounting],
            'admin academic-head' => ['admin', User::StaffRoleAcademicHead],
            'admin system-super-admin' => ['admin', User::StaffRoleSystemSuperAdmin],
        ];
    }

    public function test_profile_unchanged_and_email_only_saves_keep_other_sessions_and_clear_current_password(): void
    {
        Notification::fake();

        $applicant = User::factory()->create([
            'email' => 'unchanged-or-email@example.test',
            'password' => Hash::make('original passphrase 2026'),
            'remember_token' => 'stable-remember-token',
        ]);
        $applicant->assignRole('applicant');

        DB::table('sessions')->insert([
            'id' => 'applicant-second-session-'.$applicant->id,
            'user_id' => $applicant->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'OtherBrowser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->actingAs($applicant);
        session()->put(WorkspaceContextResolver::SessionKey, 'applicant');

        $emptyProfile = Livewire::test(AccountSecurity::class);
        $emptyProfile
            ->assertDontSee('Current password')
            ->assertDontSee('Save changes')
            ->call('startEditingCredentials')
            ->assertSeeHtml('<fieldset')
            ->assertSeeHtml('tala-profile-password-box')
            ->assertSeeInOrder([
                'Change password',
                'Current password',
                'New password',
                'Confirm new password',
                'Cancel',
                'Save changes',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->fillForm([
                'currentPassword' => 'original passphrase 2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->fillForm([
                'passwordConfirmation' => 'different passphrase 2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->fillForm([
                'currentPassword' => 'original passphrase 2026',
                'password' => 'original passphrase 2026',
                'passwordConfirmation' => 'original passphrase 2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->fillForm([
                'email' => 'abandoned-edit@example.test',
                'currentPassword' => 'wrong',
                'password' => 'short',
            ])
            ->call('save')
            ->assertHasFormErrors(['password'])
            ->assertNotNotified()
            ->callAction(TestAction::make('cancelChanges')->schemaComponent('form-actions', 'content'))
            ->assertSet('editingCredentials', false)
            ->assertHasNoFormErrors()
            ->assertFormSet([
                'email' => 'unchanged-or-email@example.test',
                'currentPassword' => null,
                'password' => null,
                'passwordConfirmation' => null,
            ]);

        Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->fillForm([
                'email' => 'successor-only@example.test',
                'currentPassword' => 'original passphrase 2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('editingCredentials', false)
            ->assertSet('data.currentPassword', null);

        $freshApplicant = $applicant->fresh();
        $this->assertSame('stable-remember-token', $freshApplicant->remember_token);
        $this->assertTrue(Hash::check('original passphrase 2026', $freshApplicant->password));
        $this->assertDatabaseHas('sessions', ['id' => 'applicant-second-session-'.$applicant->id]);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $applicant->id,
            'event' => 'password_changed',
        ]);
        Notification::assertSentTo($applicant, NoticeOfEmailChangeRequest::class);
        Notification::assertSentOnDemand(VerifyEmailChange::class);
    }

    public function test_profile_forgot_password_action_sends_reset_link_to_verified_email_only_and_enforces_rate_limit(): void
    {
        Notification::fake();

        $accounting = User::factory()->create([
            'email' => 'accounting-recovery@example.test',
            'password' => Hash::make('original passphrase 2026'),
        ]);
        $accounting->assignRole(User::StaffRoleAccounting);
        $accounting->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $accounting->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $accounting->acknowledgeRecoveryCodeStorage();

        $otherUser = User::factory()->create(['email' => 'bystander@example.test']);
        $otherUser->assignRole(User::StaffRoleFaculty);

        $rateLimitKey = 'tala-authenticated-password-recovery:'.sha1('admin|'.(string) $accounting->getKey());
        RateLimiter::clear($rateLimitKey);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($accounting);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleAccounting);

        $forgotAction = TestAction::make('forgotPassword')->schemaComponent('currentPassword', 'form');

        $page = Livewire::test(AccountSecurity::class)
            ->call('startEditingCredentials')
            ->mountAction($forgotAction)
            ->assertSee($accounting->email)
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Check your email for account recovery');

        Notification::assertSentToTimes($accounting, ResetPasswordNotification::class, 1);
        Notification::assertNotSentTo($otherUser, ResetPasswordNotification::class);
        $notification = Notification::sent($accounting, ResetPasswordNotification::class)->sole();
        $this->assertStringContainsString('/admin/password-reset/reset', $notification->url);
        $this->assertTrue(Hash::check('original passphrase 2026', $accounting->fresh()->password));
        $this->assertSame(['stored-code'], $accounting->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertAuthenticatedAs($accounting);

        $page->callAction($forgotAction)
            ->assertNotified('Please wait before requesting another link');

        Notification::assertSentToTimes($accounting, ResetPasswordNotification::class, 1);

        RateLimiter::clear($rateLimitKey);
        RateLimiter::hit($rateLimitKey, 60);
        RateLimiter::hit($rateLimitKey, 60);

        $page->callAction($forgotAction)
            ->assertNotified('Please wait before requesting another link');

        Notification::assertSentToTimes($accounting, ResetPasswordNotification::class, 1);
    }

    public function test_recovery_code_regeneration_uses_current_password_only_and_shows_role_guidance(): void
    {
        $applicant = User::factory()->create([
            'email' => 'applicant-recovery-codes@example.test',
            'password' => 'applicant passphrase 2026',
        ]);
        $applicant->assignRole('applicant');
        $applicant->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $applicant->saveAppAuthenticationRecoveryCodes(['stored-code-alpha', 'stored-code-beta']);
        $applicant->acknowledgeRecoveryCodeStorage();

        Filament::setCurrentPanel('applicant');
        Filament::bootCurrentPanel();
        $this->actingAs($applicant);

        $regenerateAction = TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent('app', 'content');

        $applicantModal = Livewire::test(AccountSecurity::class)
            ->assertSee('Turn off')
            ->assertDontSee('System Administrator')
            ->mountAction($regenerateAction)
            ->assertMountedActionModalSee('Turn off')
            ->assertMountedActionModalDontSee('Enter the 6-digit code from the authenticator app');

        $this->assertStringContainsString('tala-mfa-modal', $applicantModal->getMountedActionModalHtml());

        $applicantModal
            ->call('unmountAction', false)
            ->callAction($regenerateAction, [
                'password' => 'wrong passphrase 2026',
            ])
            ->assertHasActionErrors(['password']);

        $this->assertSame(['stored-code-alpha', 'stored-code-beta'], $applicant->fresh()->getAppAuthenticationRecoveryCodes());

        $applicantRegenerated = Livewire::test(AccountSecurity::class)
            ->callAction($regenerateAction, [
                'password' => 'applicant passphrase 2026',
            ])
            ->assertHasNoActionErrors();

        $regeneratedCodes = $applicant->fresh()->getAppAuthenticationRecoveryCodes();
        $this->assertNotEmpty($regeneratedCodes);
        $this->assertNotSame(['stored-code-alpha', 'stored-code-beta'], $regeneratedCodes);

        $showNewCodesAction = TestAction::make('showNewRecoveryCodes');
        $showNewCodesModalHtml = $applicantRegenerated
            ->assertActionMounted($showNewCodesAction)
            ->getMountedActionModalHtml();

        $this->assertRenderedRecoveryCodesModalMatchesHashes($showNewCodesModalHtml, $regeneratedCodes);

        $staff = User::factory()->create([
            'email' => 'staff-recovery-codes@example.test',
            'password' => 'staff passphrase 2026',
        ]);
        $staff->assignRole(User::StaffRoleAccounting);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['staff-code-alpha']);
        $staff->acknowledgeRecoveryCodeStorage();

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $this->actingAs($staff);

        $staffRegenerated = Livewire::test(AccountSecurity::class)
            ->callAction($regenerateAction, [
                'password' => 'staff passphrase 2026',
            ])
            ->assertHasNoActionErrors();

        $regeneratedStaffCodes = $staff->fresh()->getAppAuthenticationRecoveryCodes();
        $this->assertNotEmpty($regeneratedStaffCodes);
        $this->assertNotSame(['staff-code-alpha'], $regeneratedStaffCodes);

        $staffShowNewCodesHtml = $staffRegenerated
            ->assertActionMounted($showNewCodesAction)
            ->getMountedActionModalHtml();

        $this->assertRenderedRecoveryCodesModalMatchesHashes($staffShowNewCodesHtml, $regeneratedStaffCodes);
    }

    public function test_mfa_modal_forgot_password_dispatches_reset_link_without_bypassing_password_or_mfa(): void
    {
        Notification::fake();

        $staff = User::factory()->create([
            'email' => 'staff-mfa-recovery@example.test',
            'password' => Hash::make('original passphrase 2026'),
        ]);
        $staff->assignRole(User::StaffRoleRegistrar);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $staff->acknowledgeRecoveryCodeStorage();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);

        $regenerateAction = TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent('app', 'content');
        $modalForgotAction = TestAction::make('forgotPassword')->schemaComponent('password', 'mountedActionSchema0');

        $profile = Livewire::test(AccountSecurity::class)
            ->assertSee('System Administrator')
            ->assertSee('reset two-factor authentication')
            ->assertDontSee('reset MFA')
            ->assertDontSee('after identity verification')
            ->mountAction($regenerateAction)
            ->assertMountedActionModalSee('System Administrator')
            ->assertMountedActionModalSee('reset two-factor authentication')
            ->assertMountedActionModalDontSee('reset MFA')
            ->assertMountedActionModalDontSee('after identity verification')
            ->assertMountedActionModalDontSee('Enter the 6-digit code from the authenticator app')
            ->assertActionVisible($modalForgotAction)
            ->mountAction($modalForgotAction)
            ->assertMountedActionModalSee([
                $staff->email,
                'does not turn off two-factor authentication',
            ])
            ->call('unmountAction', false)
            ->assertActionMounted($regenerateAction);

        Notification::assertNothingSent();

        $profile->mountAction($modalForgotAction)
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Check your email for account recovery')
            ->assertActionNotMounted($regenerateAction);

        Notification::assertSentToTimes($staff, ResetPasswordNotification::class, 1);
        $this->assertSame(['stored-code'], $staff->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertAuthenticatedAs($staff);

        // Required MFA setup wizard also exposes the confirmation action on its password field.
        $unenrolledStaff = User::factory()->create(['email' => 'unenrolled-mfa-recovery@example.test']);
        $unenrolledStaff->assignRole(User::StaffRoleFaculty);

        $this->actingAs($unenrolledStaff);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleFaculty);

        $setUpAction = TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content');

        Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
            ->mountAction($setUpAction)
            ->assertActionVisible($modalForgotAction)
            ->callAction($modalForgotAction)
            ->assertHasNoActionErrors()
            ->assertNotified('Check your email for account recovery')
            ->assertActionNotMounted($setUpAction);

        Notification::assertSentToTimes($unenrolledStaff, ResetPasswordNotification::class, 1);
        $this->assertNull($unenrolledStaff->fresh()->getAppAuthenticationSecret());

        // Enrolled learners also retain the confirmation action inside the disable-MFA modal.
        $applicant = User::factory()->create(['email' => 'applicant-mfa-recovery@example.test']);
        $applicant->assignRole('applicant');
        $applicant->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $applicant->saveAppAuthenticationRecoveryCodes(['applicant-code']);
        $applicant->acknowledgeRecoveryCodeStorage();

        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->actingAs($applicant);
        session()->put(WorkspaceContextResolver::SessionKey, 'applicant');

        $disableAction = TestAction::make('disableAppAuthentication')->schemaComponent('app', 'content');

        $disableProfile = Livewire::test(AccountSecurity::class)
            ->mountAction($disableAction)
            ->assertActionVisible($modalForgotAction);

        $this->assertStringContainsString('tala-mfa-modal', $disableProfile->getMountedActionModalHtml());

        $disableProfile
            ->callAction($modalForgotAction)
            ->assertHasNoActionErrors()
            ->assertNotified('Check your email for account recovery')
            ->assertActionNotMounted($disableAction);

        Notification::assertSentToTimes($applicant, ResetPasswordNotification::class, 1);
        $this->assertNotNull($applicant->fresh()->getAppAuthenticationSecret());
    }

    /**
     * Assert the chained recovery-code modal uses shared MFA shell styling and displays plaintext codes matching the stored hashes.
     *
     * @param  list<string>  $hashedCodes
     */
    private function assertRenderedRecoveryCodesModalMatchesHashes(string $modalHtml, array $hashedCodes): void
    {
        $this->assertStringContainsString('tala-mfa-modal', $modalHtml);
        $this->assertStringContainsString('tala-mfa-recovery-step', $modalHtml);

        preg_match('/<div class="tala-mfa-recovery-step">.*?<ul[^>]*fi-sc-unordered-list[^>]*>(.*?)<\/ul>/s', $modalHtml, $listMatch);
        $this->assertNotEmpty($listMatch);

        preg_match_all('/<li[^>]*>\s*<span[^>]*>\s*([^\s<]+)\s*<\/span>\s*<\/li>/s', $listMatch[1], $codeMatches);
        $displayedCodes = $codeMatches[1];

        $this->assertCount(count($hashedCodes), $displayedCodes);

        foreach ($displayedCodes as $index => $plainCode) {
            $this->assertTrue(Hash::check($plainCode, $hashedCodes[$index]));
        }
    }
}
