<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\UserAccessService;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersAndAccessLifecycleJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::staffRoleNames() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_disable_and_reactivate_preserve_roles_and_end_sessions(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole([User::StaffRoleFaculty, User::StaffRoleAccounting]);
        DB::table('sessions')->insert([
            'id' => 'clinic1-lifecycle-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $service = app(UserAccessService::class);
        $service->disable($actor, $target, 'Access suspended after an approved separation review.', 'HR separation notice 2026-08');

        $target->refresh();
        $this->assertSame(User::StatusDisabled, $target->status);
        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAccounting],
            $target->roles->pluck('name')->all(),
        );
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $service->reactivate($actor, $target, 'Access restored after approved return to service.', 'HR return notice 2026-09');

        $this->assertSame(User::StatusActive, $target->fresh()->status);
        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAccounting],
            $target->fresh()->roles->pluck('name')->all(),
        );
    }

    public function test_self_disable_and_final_administrator_removal_are_rejected_without_change(): void
    {
        $actor = $this->administrator();
        $service = app(UserAccessService::class);

        try {
            $service->disable($actor, $actor, 'Attempt to disable the current administrator.', 'Invalid self action');
            $this->fail('Self-disable must fail.');
        } catch (AuthorizationException) {
            $this->assertSame(User::StatusActive, $actor->fresh()->status);
        }

        try {
            $service->changeStaffRoles(
                $actor,
                $actor,
                [User::StaffRoleRegistrar],
                'Attempt to remove the final administrator role.',
                'Invalid self action',
            );
            $this->fail('Final administrator removal must fail.');
        } catch (ValidationException) {
            $this->assertTrue($actor->fresh()->hasRole(User::StaffRoleSystemSuperAdmin));
        }
    }

    public function test_multi_role_change_is_transactional_and_audited(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);

        app(UserAccessService::class)->changeStaffRoles(
            $actor,
            $target,
            [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
            'Approved additional academic oversight responsibility.',
            'Academic council resolution 2026-04',
        );

        $this->assertEqualsCanonicalizing(
            [User::StaffRoleFaculty, User::StaffRoleAcademicHead],
            $target->fresh()->roles->pluck('name')->all(),
        );
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_access_changed',
        ]);
        $activity = Activity::query()->where('subject_type', User::class)->where('subject_id', $target->id)->where('event', 'staff_access_changed')->sole();
        $this->assertEqualsCanonicalizing([User::StaffRoleFaculty], $activity->properties->get('before_contexts'));
        $this->assertEqualsCanonicalizing([User::StaffRoleFaculty, User::StaffRoleAcademicHead], $activity->properties->get('after_contexts'));
        $projected = app(GovernanceEvidenceProjection::class)->paginate(
            GovernanceEvidenceProjection::InstitutionalChanges,
            1,
            25,
            'staff_access_changed',
            [],
        );
        $this->assertCount(1, $projected->items());
    }

    public function test_access_change_remains_authoritative_when_notification_dispatch_fails(): void
    {
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic mail dispatch failure.'));
        });
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);

        app(UserAccessService::class)->disable(
            $actor,
            $target,
            'Access suspended after an approved separation review.',
            'HR separation notice 2026-08',
        );

        $this->assertSame(User::StatusDisabled, $target->fresh()->status);
        $this->assertDatabaseHas('operational_events', [
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => 'staff_access_change',
            'user_id' => $target->id,
            'status' => OperationalEvent::StatusFailed,
        ]);
    }

    public function test_staff_email_change_keeps_the_current_address_until_successor_verification(): void
    {
        $actor = $this->administrator();
        $actor->forceFill(['password' => Hash::make('administrator password')])->save();
        $target = User::factory()->create(['email' => 'current@example.test']);
        $target->assignRole(User::StaffRoleFaculty);

        $change = app(UserAccessService::class)->requestStaffEmailChange(
            $actor,
            $target,
            'successor@example.test',
            'administrator password',
            'Approved correction of the Staff sign-in address.',
            'Staff identity record review 2026-08',
        );

        $this->assertSame('current@example.test', $target->fresh()->email);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_email_change_requested',
        ]);

        $token = str_repeat('a', 64);
        $change->update(['token_digest' => hash('sha256', $token)]);

        $this->get(route('staff-email-changes.verify', ['change' => $change, 'token' => $token]))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertSame('successor@example.test', $target->fresh()->email);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'staff_email_changed',
        ]);
    }

    public function test_direct_staff_create_edit_role_and_archive_routes_are_retired(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);

        $this->get('/admin/users/create')->assertNotFound();
        $this->get('/admin/users/1/edit')->assertNotFound();
        $this->get('/admin/roles')->assertNotFound();
    }

    public function test_account_detail_is_read_only_ordered_and_secret_free(): void
    {
        $administrator = $this->administrator();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $administrator->saveAppAuthenticationRecoveryCodes(['administrator-recovery']);
        $target = User::factory()->create();
        $target->assignRole(User::StaffRoleFaculty);
        $target->staffAccessProfile()->create([
            'staff_identifier' => 'C1-FACULTY-01',
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Lovelace',
            'suffix' => null,
        ]);
        $target->forceFill([
            'two_factor_secret' => 'forbidden-mfa-secret',
            'two_factor_recovery_codes' => 'forbidden-recovery-codes',
        ])->save();
        $this->actingAs($administrator)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleSystemSuperAdmin]);

        $this->get(route('filament.admin.resources.users.view', ['record' => $target]))
            ->assertOk()
            ->assertSeeInOrder(['Account identity', 'Authorized contexts', 'Access history'])
            ->assertSee('C1-FACULTY-01')
            ->assertDontSee('forbidden-mfa-secret')
            ->assertDontSee('forbidden-recovery-codes')
            ->assertDontSee('Password');
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(User::StaffRoleSystemSuperAdmin);

        return $user;
    }
}
