<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Filament\Pages\Auth\StaffActivation;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffInvitationActivationJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_new_staff_is_invited_without_an_administrator_created_password(): void
    {
        Notification::fake();
        $actor = $this->administrator();

        $result = app(StaffInvitationService::class)->invite($actor, [
            'email' => 'faculty@example.test',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Lovelace',
            'staff_identifier' => 'FAC-001',
            'roles' => [User::StaffRoleFaculty],
            'reason' => 'Approved faculty access for the current term.',
            'authority' => 'Registrar memorandum 2026-01',
            'evidence_reference' => null,
        ]);

        $user = $result->invitation->user->refresh();

        $this->assertNull($user->password);
        $this->assertSame(User::StatusInvitationPending, $user->status);
        $this->assertSame([], $user->roles->pluck('name')->all());
        $this->assertSame('FAC-001', $user->staffAccessProfile?->staff_identifier);
        $this->assertSame(64, strlen($result->plainTextToken));
        $this->assertNotSame($result->plainTextToken, $result->invitation->token_digest);
    }

    public function test_existing_verified_account_is_reused_without_changing_its_password(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $existing = User::factory()->create(['email' => 'learner@example.test']);
        $existing->assignRole('applicant');
        $password = $existing->password;

        $result = app(StaffInvitationService::class)->invite($actor, [
            'email' => ' LEARNER@example.test ',
            'first_name' => 'Existing',
            'middle_name' => null,
            'last_name' => 'Learner',
            'roles' => [User::StaffRoleRegistrar],
            'reason' => 'Approved additional Registrar responsibility.',
            'authority' => 'Executive approval 2026-02',
        ]);

        $this->assertSame($existing->id, $result->invitation->user_id);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame(1, User::query()->where('email', 'learner@example.test')->count());
        $this->assertFalse($existing->fresh()->hasAssignedRole(User::StaffRoleRegistrar));
    }

    public function test_resend_supersedes_old_link_and_new_link_activates_once(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $service = app(StaffInvitationService::class);
        $first = $service->invite($actor, $this->invitationPayload());
        $this->travel(61)->seconds();
        $second = $service->resend($first->invitation, $actor);

        try {
            $service->activate($first->invitation, $first->plainTextToken, 'a secure password 2026', now());
            $this->fail('The superseded invitation must not activate.');
        } catch (ValidationException) {
            $this->assertNull($first->invitation->fresh()->accepted_at);
        }

        $activated = $service->activate($second->invitation, $second->plainTextToken, 'a secure password 2026', now());
        $this->assertTrue(Hash::check('a secure password 2026', (string) $activated->password));
        $this->assertNotNull($activated->email_verified_at);
        $this->assertSame(User::StatusVerificationRequired, $activated->status);
        $this->assertTrue($activated->hasAssignedRole(User::StaffRoleFaculty));
        $this->assertNotNull($second->invitation->fresh()->accepted_at);

        $this->expectException(ValidationException::class);
        $service->activate($second->invitation, $second->plainTextToken, 'a secure password 2026', now());
    }

    public function test_resend_is_throttled_and_expired_or_malformed_links_have_one_safe_recovery_action(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $service = app(StaffInvitationService::class);
        $result = $service->invite($actor, $this->invitationPayload());

        try {
            $service->resend($result->invitation, $actor);
            $this->fail('An immediate resend must be throttled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }

        $this->get(route('staff-invitations.activate', [
            'invitation' => $result->invitation,
            'token' => 'malformed',
        ]))
            ->assertOk()
            ->assertSee('fi-auth-layout has-media media-top', false)
            ->assertSee('Ask a System Administrator to resend the invitation.')
            ->assertDontSee('autocomplete="new-password"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('faculty@example.test', false)
            ->assertDontSee('Ada Lovelace', false);

        $this->travel(61)->minutes();
        $this->get(route('staff-invitations.activate', [
            'invitation' => $result->invitation,
            'token' => $result->plainTextToken,
        ]))
            ->assertOk()
            ->assertSee('fi-auth-layout has-media media-top', false)
            ->assertSee('Ask a System Administrator to resend the invitation.')
            ->assertDontSee('autocomplete="new-password"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('faculty@example.test', false)
            ->assertDontSee('Ada Lovelace', false);
    }

    public function test_staff_activation_auth_designer_page_activates_logs_in_and_redirects_to_mfa_setup(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $service = app(StaffInvitationService::class);
        $result = $service->invite($actor, $this->invitationPayload());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        RateLimiter::clear('livewire-rate-limiter:'.sha1(StaffActivation::class.'|activate|127.0.0.1'));

        Livewire::withQueryParams(['token' => $result->plainTextToken])
            ->test(StaffActivation::class, ['invitation' => $result->invitation])
            ->assertSet('isUsable', true)
            ->assertSee('Ada Lovelace')
            ->assertSee('faculty@example.test')
            ->assertSee('Faculty')
            ->fillForm([
                'password' => 'a secure staff passphrase 2026',
                'passwordConfirmation' => 'a secure staff passphrase 2026',
            ])
            ->call('activate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));

        $user = $result->invitation->user->fresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a secure staff passphrase 2026', (string) $user->password));
        $this->assertSame(User::StatusVerificationRequired, $user->status);
        $this->assertTrue($user->hasAssignedRole(User::StaffRoleFaculty));
        $this->assertSame(User::StaffRoleFaculty, session(WorkspaceContextResolver::SessionKey));
        $this->assertNotNull($result->invitation->fresh()->accepted_at);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => 'staff_invitation_activated',
        ]);
    }

    public function test_staff_activation_page_rate_limits_repeated_attempts_and_post_route_remains_compatible(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $service = app(StaffInvitationService::class);
        $result = $service->invite($actor, $this->invitationPayload());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $component = Livewire::withQueryParams(['token' => $result->plainTextToken])
            ->test(StaffActivation::class, ['invitation' => $result->invitation]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $component
                ->fillForm([
                    'password' => 'short',
                    'passwordConfirmation' => 'short',
                ])
                ->call('activate')
                ->assertHasFormErrors(['password']);
        }

        $component
            ->fillForm([
                'password' => 'a secure staff passphrase 2026',
                'passwordConfirmation' => 'a secure staff passphrase 2026',
            ])
            ->call('activate')
            ->assertSet('rateLimitContext', 'staff_activation')
            ->assertSee('Too many activation attempts');

        $this->assertGuest();

        $this->post(route('staff-invitations.accept', $result->invitation), [
            'token' => $result->plainTextToken,
            'password' => 'a secure post passphrase 2026',
            'password_confirmation' => 'a secure post passphrase 2026',
        ])->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));

        $this->assertAuthenticatedAs($result->invitation->user->fresh());
        $this->assertNotNull($result->invitation->fresh()->accepted_at);
    }

    public function test_dispatch_failure_does_not_reverse_the_invitation_and_records_safe_recovery_evidence(): void
    {
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic mail dispatch failure.'));
        });

        $result = app(StaffInvitationService::class)->invite($this->administrator(), $this->invitationPayload());

        $this->assertDatabaseHas('staff_invitations', ['id' => $result->invitation->id]);
        $this->assertSame(User::StatusInvitationPending, $result->invitation->user->fresh()->status);
        $this->assertDatabaseHas('operational_events', [
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => 'staff_invitation_email',
            'related_record_id' => $result->invitation->id,
            'status' => OperationalEvent::StatusFailed,
        ]);
    }

    public function test_streamlined_invite_accepts_optional_reason_and_authority_and_supports_correct_email_and_cancel(): void
    {
        Notification::fake();
        $actor = $this->administrator();
        $service = app(StaffInvitationService::class);

        $result = $service->invite($actor, [
            'email' => 'typo.faculty@example.test',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'roles' => [User::StaffRoleFaculty],
        ]);

        $invitedUser = $result->invitation->user->refresh();
        $this->assertNull($result->invitation->authority);
        $this->assertNull($result->invitation->reason);
        $this->assertSame('typo.faculty@example.test', $invitedUser->email);

        $correctedInvitation = $service->correctPendingEmailAndResend($invitedUser, 'grace.hopper@example.test', $actor);

        $this->assertSame('grace.hopper@example.test', $invitedUser->fresh()->email);
        $this->assertSame($correctedInvitation->id, $result->invitation->fresh()->superseded_by_id);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'user_access',
            'event' => 'staff_invitation_email_corrected',
            'subject_id' => $invitedUser->id,
        ]);

        $cancelledUserId = $invitedUser->id;
        $service->cancelPendingInvitation($invitedUser->fresh(), $actor, 'Invitation no longer needed.');

        $this->assertDatabaseMissing('users', ['id' => $cancelledUserId]);
        $this->assertDatabaseMissing('staff_invitations', ['user_id' => $cancelledUserId]);
        $this->assertDatabaseMissing('staff_access_profiles', ['user_id' => $cancelledUserId]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'user_access',
            'event' => 'staff_invitation_cancelled',
        ]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(User::StaffRoleSystemSuperAdmin);

        return $user;
    }

    /** @return array<string, mixed> */
    private function invitationPayload(): array
    {
        return [
            'email' => 'faculty@example.test',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Lovelace',
            'roles' => [User::StaffRoleFaculty],
            'reason' => 'Approved faculty access for the current term.',
            'authority' => 'Registrar memorandum 2026-01',
        ];
    }
}
