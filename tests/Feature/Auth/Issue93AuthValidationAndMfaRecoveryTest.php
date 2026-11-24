<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\FacultyOverview;
use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93AuthValidationAndMfaRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

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

        // All auth routes share the exact same native HTML5 constraints + shared client-on-submit validation script.
        foreach (['/admin/login', '/admin/password-reset/request', '/applicant/register'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('type="email"', false)
                ->assertSee('required="required"', false)
                ->assertSee('novalidate', false)
                ->assertSee('js/tala-auth-validation.js', false);
        }

        $themeCss = file_get_contents(resource_path('css/filament/tala/theme.css'));
        $this->assertStringContainsString(
            '.fi-body .tala-auth-main form:not([data-tala-submitted="true"])',
            $themeCss,
        );

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

    public function test_switching_to_mfa_recovery_code_clears_stale_code_errors_and_accepts_pasted_recovery_code(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $staff = User::factory()->create([
            'email' => 'mfa-recovery-paste@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());
        $provider->saveRecoveryCodes($staff, ['PREV-9301-AAAA', 'PREV-9301-BBBB']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertHasNoErrors();

        // Trigger a stale 6-digit code error first.
        $login
            ->set('data.multiFactor.app.code', '123')
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);

        // Switch to recovery code mode via the schema action and verify native required constraint is present for instant client-side submit validation.
        $login
            ->callAction(TestAction::make('useRecoveryCode')->schemaComponent('app.code', 'multiFactorChallengeForm'))
            ->assertHasNoErrors()
            ->assertSet('data.multiFactor.app.useRecoveryCode', true)
            ->assertSet('data.multiFactor.app.code', null)
            ->assertSeeHtml('required="required"')
            ->set('data.multiFactor.app.recoveryCode', " PREV-9301-AAAA\n")
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(FacultyOverview::getUrl(panel: 'admin'));

        $this->assertCount(1, $staff->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_invalid_or_blank_recovery_code_validates_only_recovery_code_and_never_code_field(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
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

    public function test_restarting_authentication_with_use_another_account_does_not_cache_blank_mfa_challenge_form(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $staff = User::factory()->create([
            'email' => 'mfa-restart-blank-regression@example.test',
            'password' => 'a secure password 2026',
        ]);
        $staff->assignRole(User::StaffRoleFaculty);

        $provider = app(TalaAppAuthentication::class)->recoverable();
        $provider->saveSecret($staff, $provider->generateSecret());
        $provider->saveRecoveryCodes($staff, ['PREV-9302-AAAA']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $login = Livewire::test(ContextualLogin::class)
            ->call('enableSchemaStateUpdateHooksForTesting')
            ->set('data.email', $staff->email)
            ->set('data.password', 'a secure password 2026')
            ->call('authenticate')
            ->assertSeeHtml('id="multiFactorChallengeForm.app.code"')
            ->call('useAnotherAccount');

        // Reproduce real browser request where data.email/data.password updates run before authenticate() in the same roundtrip.
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
}
