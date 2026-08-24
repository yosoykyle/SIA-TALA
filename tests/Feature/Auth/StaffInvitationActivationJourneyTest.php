<?php

namespace Tests\Feature\Auth;

use App\Actions\SystemAdministration\StaffInvitationService;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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
            ->assertSee('Ask a System Administrator to resend the invitation.')
            ->assertDontSee('name="password"', false);

        $this->travel(61)->minutes();
        $this->get(route('staff-invitations.activate', [
            'invitation' => $result->invitation,
            'token' => $result->plainTextToken,
        ]))
            ->assertOk()
            ->assertSee('Ask a System Administrator to resend the invitation.')
            ->assertDontSee('name="password"', false);
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
