<?php

namespace Tests\Feature;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Authentication\UserSessionService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\AdmissionCycle;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue64PublicEntryAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_legacy_status_urls_recover_without_disclosure_dispatch_or_logout(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create();
        $user->assignRole('applicant');
        Mail::fake();

        $this->get('/application-status/old-token?signature=expired')
            ->assertOk()->assertSee('Public status links have been retired')
            ->assertSee(route('filament.admin.auth.login'), false)
            ->assertDontSee($user->email)->assertDontSee('old-token')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($user)->withSession(['public_entry.program_id' => 12])
            ->get('/application-status/'.str_repeat('a', 64))
            ->assertOk()->assertSessionHas('public_entry.program_id', 12)
            ->assertDontSee($user->email);
        $this->assertAuthenticatedAs($user);
        $this->post('/application-status', ['reference' => 'APP-PRIVATE', 'email' => $user->email])->assertMethodNotAllowed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_program_filters_and_details_preserve_context_and_only_expose_active_facts(): void
    {
        $program = Program::factory()->create(['is_active' => true, 'name' => 'Synthetic four-year Program', 'duration_years' => 4]);
        Program::factory()->create(['is_active' => true, 'name' => 'Synthetic two-year Program', 'duration_years' => 2]);
        $inactive = Program::factory()->create(['is_active' => false, 'name' => 'Private inactive Program', 'duration_years' => 4]);
        $this->openFor($program);

        $filters = ['duration' => 4, 'availability' => 'open'];
        $this->get(route('home', $filters))->assertOk()
            ->assertSee($program->name)->assertDontSee('Synthetic two-year Program')->assertDontSee($inactive->name)
            ->assertViewHas('programFilters', $filters)
            ->assertSee(route('home', [...$filters, 'program' => $program->id]).'#programs');

        $this->get(route('home', [...$filters, 'program' => $program->id]))->assertOk()
            ->assertSee('id="program-detail-title-'.$program->id.'"', false)
            ->assertSee(route('home', $filters).'#programs')
            ->assertSee(route('public.apply', ['program' => $program->id]), false)
            ->assertDontSee('Synthetic two-year Program');

        $this->get(route('home', ['duration' => 999, 'availability' => 'invented', 'program' => $inactive->id]))
            ->assertOk()->assertViewHas('programFilters', [])->assertViewHas('selectedProgram', null)
            ->assertDontSee($inactive->name);
    }

    public function test_program_availability_includes_each_open_source_and_closed_filtered_results_recover(): void
    {
        $first = Program::factory()->create(['is_active' => true, 'duration_years' => 4]);
        $second = Program::factory()->create(['is_active' => true, 'duration_years' => 2]);
        $this->openFor($first);
        $this->openFor($second);

        $this->get(route('home', ['availability' => 'open']))->assertOk()
            ->assertSee($first->name)->assertSee($second->name)
            ->assertSee('Choose a Program and Student type to see your intake and deadline.')
            ->assertDontSee('Applications close')
            ->assertViewHas('acceptingProgramIds', fn (array $ids): bool => in_array($first->id, $ids, true) && in_array($second->id, $ids, true));

        $this->get(route('home', ['duration' => 4, 'availability' => 'enquire']))->assertOk()
            ->assertSee('No Programs match these filters')->assertSee('Clear filters');
    }

    public function test_apply_captures_available_program_intent_and_rejects_inactive_intent(): void
    {
        $program = Program::factory()->create(['is_active' => true]);
        $inactive = Program::factory()->create(['is_active' => false]);
        $this->openFor($program);

        $this->get(route('public.apply', ['program' => $inactive->id]))
            ->assertRedirect(route('home').'#programs')->assertSessionMissing('public_entry.program_id');
        $this->get(route('public.apply', ['program' => $program->id]))
            ->assertRedirect(route('filament.applicant.auth.register'))
            ->assertSessionHas('public_entry.program_id', $program->id)
            ->assertSessionHas('url.intended', Application::getUrl(['program' => $program->id], panel: 'applicant'));
    }

    public function test_applicant_sign_in_retains_the_program_context(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['password' => 'a secure synthetic passphrase']);
        $user->assignRole('applicant');
        $program = Program::factory()->create(['is_active' => true]);
        $this->openFor($program);
        $this->get(route('public.apply', ['program' => $program->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ContextualLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'a secure synthetic passphrase')
            ->call('authenticate')->assertHasNoErrors()
            ->assertRedirect(Application::getUrl(['program' => $program->id], panel: 'applicant'));
    }

    public function test_native_email_verification_retains_the_public_program_intent(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->unverified()->create();
        $user->assignRole('applicant');
        $program = Program::factory()->create(['is_active' => true]);
        $this->openFor($program);
        $this->get(route('public.apply', ['program' => $program->id]));
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        app(DispatchApplicantEmailVerification::class)->execute($user);
        $url = Notification::sent($user, VerifyEmail::class)->sole()->url;

        $this->actingAs($user)->get($url)
            ->assertRedirect(Application::getUrl(['program' => $program->id], panel: 'applicant'))
            ->assertSessionHas('public_entry.program_id', $program->id);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_native_reset_rejects_a_compromised_passphrase_without_consuming_the_token(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['password' => 'original synthetic passphrase']);
        $user->assignRole('applicant');
        $passphrase = 'compromised synthetic passphrase';
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr(strtoupper(sha1($passphrase)), 5).':20', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', $passphrase)->set('passwordConfirmation', $passphrase)
            ->call('resetPassword')->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('original synthetic passphrase', $user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'));
    }

    public function test_reset_requests_have_identical_feedback_and_cleared_fields_for_known_and_unknown_accounts(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create();
        $user->assignRole('applicant');
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        foreach ([$user->email, 'unknown-synthetic@example.test'] as $email) {
            Livewire::test(RequestPasswordReset::class)
                ->set('data.email', $email)
                ->call('request')
                ->assertHasNoErrors()
                ->assertSet('data.email', null)
                ->assertNotified(FilamentNotification::make()
                    ->title('Check your email for account recovery')
                    ->body('If an account can use this workspace, a recovery link will be requested. If no link arrives, try again later or contact official support.')
                    ->success());
        }

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        $this->assertDatabaseMissing('users', ['email' => 'unknown-synthetic@example.test']);
    }

    #[DataProvider('publicRecoveryRoles')]
    public function test_public_admin_recovery_uses_credential_eligibility_without_changing_workspace_or_mfa(array $roles): void
    {
        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }
        $user = User::factory()->unverified()->create([
            'password' => 'original synthetic passphrase',
            'two_factor_secret' => 'retained-synthetic-factor',
            'two_factor_recovery_codes' => 'retained-synthetic-codes',
        ]);
        $user->assignRole($roles);
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        session()->put(WorkspaceContextResolver::SessionKey, 'applicant');
        session()->put('tala.requested_context', 'applicant');

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', $user->email)->call('request')
            ->assertHasNoErrors()->assertSet('data.email', null)
            ->assertNotified('Check your email for account recovery');

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        $notification = Notification::sent($user, ResetPasswordNotification::class)->sole();
        $this->assertStringContainsString('/admin/password-reset/reset', $notification->url);
        $this->assertFalse(session()->has(ContextualLogin::AuthenticatingSessionKey));
        $this->assertSame('applicant', session(WorkspaceContextResolver::SessionKey));

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $notification->token])
            ->set('password', 'replacement synthetic passphrase')
            ->set('passwordConfirmation', 'replacement synthetic passphrase')
            ->call('resetPassword')->assertHasNoErrors()
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertTrue(Hash::check('replacement synthetic passphrase', $user->fresh()->password));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('retained-synthetic-factor', $user->fresh()->two_factor_secret);
        $this->assertSame('retained-synthetic-codes', $user->fresh()->two_factor_recovery_codes);
        $this->assertSame('applicant', session(WorkspaceContextResolver::SessionKey));
        $this->assertSame('applicant', session('tala.requested_context'));
        $this->assertFalse(session()->has(ContextualLogin::AuthenticatingSessionKey));
        $this->assertGuest();
    }

    /** @return array<string, array{array<string>}> */
    public static function publicRecoveryRoles(): array
    {
        return ['Applicant only' => [['applicant']], 'Applicant and staff' => [['applicant', User::StaffRoleRegistrar]]];
    }

    public function test_public_admin_recovery_refuses_disabled_accounts_without_changing_credentials(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['status' => User::StatusInactive, 'password' => 'original synthetic passphrase']);
        $user->assignRole('applicant');
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $token = Password::broker()->createToken($user);
        session()->put(ContextualLogin::AuthenticatingSessionKey, false);
        session()->put(WorkspaceContextResolver::SessionKey, 'applicant');

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', $user->email)->call('request')
            ->assertHasNoErrors()->assertSet('data.email', null)
            ->assertNotified('Check your email for account recovery');
        Notification::assertNothingSent();
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', 'replacement synthetic passphrase')
            ->set('passwordConfirmation', 'replacement synthetic passphrase')
            ->call('resetPassword')->assertNoRedirect();

        $this->assertTrue(Hash::check('original synthetic passphrase', $user->fresh()->password));
        $this->assertSame(false, session(ContextualLogin::AuthenticatingSessionKey));
        $this->assertSame('applicant', session(WorkspaceContextResolver::SessionKey));
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $user->id, 'event' => 'password_recovery_completed']);
        $this->assertGuest();
    }

    public function test_native_reset_completion_failure_rolls_back_password_and_token_and_withholds_success(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['password' => 'original synthetic passphrase']);
        $user->assignRole('applicant');
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $token = Password::broker()->createToken($user);
        $this->mock(UserSessionService::class, function ($mock): void {
            $mock->shouldReceive('revokeAll')->once()->andThrow(new RuntimeException('Synthetic session store failure.'));
        });

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', 'replacement synthetic passphrase')
            ->set('passwordConfirmation', 'replacement synthetic passphrase')
            ->call('resetPassword')->assertNoRedirect()
            ->assertSet('password', 'replacement synthetic passphrase')
            ->assertNotified('Password recovery could not be completed')
            ->assertNotNotified(__('passwords.reset'));

        $this->assertTrue(Hash::check('original synthetic passphrase', $user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->assertFalse(session()->has(ContextualLogin::AuthenticatingSessionKey));
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $user->id, 'event' => 'password_recovery_completed']);
    }

    public function test_reset_request_transport_failure_keeps_email_and_can_be_retried_after_the_native_wait(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create();
        $user->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic dispatch failure.'));
            $mock->shouldReceive('send')->once()->andReturnNull();
        });

        $component = Livewire::test(RequestPasswordReset::class)
            ->set('data.email', $user->email)
            ->call('request')
            ->assertHasNoErrors()
            ->assertSet('data.email', $user->email)
            ->assertNotified('Account recovery is temporarily unavailable');

        $this->travel(61)->seconds();
        $component->call('request')
            ->assertHasNoErrors()
            ->assertSet('data.email', null)
            ->assertNotified('Check your email for account recovery');
        $this->travelBack();
        $this->assertNull($user->fresh()->disabled_at);
    }

    public function test_reset_request_keeps_native_email_validation_and_entered_input(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', 'invalid-email')
            ->call('request')
            ->assertHasErrors(['data.email'])
            ->assertSet('data.email', 'invalid-email');

        Notification::assertNothingSent();
    }

    public function test_each_panel_uses_shared_recovery_pages_with_native_designer_layout(): void
    {
        foreach (['applicant', 'student', 'admin'] as $panelId) {
            $panel = Filament::getPanel($panelId);
            $this->assertSame(RequestPasswordReset::class, $panel->getRequestPasswordResetRouteAction());
            $this->assertSame(ResetPassword::class, $panel->getResetPasswordRouteAction());
        }
    }

    public function test_native_reset_preserves_spaces_when_the_compromise_provider_is_unavailable(): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create();
        $user->assignRole('applicant');
        Http::preventStrayRequests();
        Http::fake(fn () => throw new RuntimeException('Synthetic compromise provider outage.'));
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $token = Password::broker()->createToken($user);
        $passphrase = '  synthetic passphrase with spaces  ';

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', $passphrase)->set('passwordConfirmation', $passphrase)
            ->call('resetPassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check($passphrase, $user->fresh()->password));
        $this->assertFalse(Hash::check(trim($passphrase), $user->fresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
    }

    #[DataProvider('invalidPassphraseLengths')]
    public function test_native_reset_rejects_passphrases_outside_the_existing_policy(int $length): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create(['password' => 'original synthetic passphrase']);
        $user->assignRole('applicant');
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', str_repeat('a', $length))
            ->set('passwordConfirmation', str_repeat('a', $length))
            ->call('resetPassword')->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('original synthetic passphrase', $user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    /** @return array<string, array{int}> */
    public static function invalidPassphraseLengths(): array
    {
        return ['below minimum' => [14], 'above maximum' => [65]];
    }

    #[DataProvider('validPassphraseLengths')]
    public function test_native_reset_accepts_both_passphrase_policy_boundaries(int $length): void
    {
        Role::findOrCreate('applicant', 'web');
        $user = User::factory()->create();
        $user->assignRole('applicant');
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $token = Password::broker()->createToken($user);
        $passphrase = str_repeat('a', $length);
        DB::table('sessions')->insert([
            'id' => 'issue64-native-reset-session', 'user_id' => $user->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit',
            'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->set('password', $passphrase)->set('passwordConfirmation', $passphrase)
            ->call('resetPassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check($passphrase, $user->fresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class, 'subject_id' => $user->id,
            'causer_type' => User::class, 'causer_id' => $user->id,
            'event' => 'password_recovery_completed',
        ]);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'));
    }

    /** @return array<string, array{int}> */
    public static function validPassphraseLengths(): array
    {
        return ['minimum' => [15], 'maximum' => [64]];
    }

    private function openFor(Program $program): AdmissionCycle
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create(['opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
        $cycle->programs()->attach($program, ['accepts_first_year' => true, 'accepts_transferee' => true]);

        return $cycle;
    }
}
