<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\UserAccountLifecycleService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserAccountLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_super_admin_can_archive_staff_account_with_reason(): void
    {
        $admin = $this->admin();
        $target = $this->staff(User::StaffRoleRegistrar);
        $archivedAt = CarbonImmutable::parse('2026-06-06 10:15:00', config('app.timezone'));

        $archived = app(UserAccountLifecycleService::class)->archive(
            target: $target,
            actor: $admin,
            reason: 'Registrar resigned from service.',
            archivedAt: $archivedAt,
        );

        $properties = $this->activityProperties($target, 'staff_account_archived');

        $this->assertSame(User::StatusArchived, $archived->status);
        $this->assertSame($archivedAt->toDateTimeString(), $archived->archived_at?->toDateTimeString());
        $this->assertSame('Registrar resigned from service.', $archived->archived_reason);
        $this->assertSame([], $archived->fresh()->getRoleNames()->all());
        $this->assertSame('Registrar resigned from service.', $properties['reason']);
        $this->assertSame(User::StatusArchived, $properties['status_after']);
    }

    public function test_system_super_admin_can_restore_archived_staff_account_with_one_approved_role(): void
    {
        $admin = $this->admin();
        $target = $this->staff(User::StaffRoleRegistrar)->forceFill([
            'status' => User::StatusArchived,
            'archived_at' => now(),
            'archived_reason' => 'Former staff archived.',
        ]);
        $target->save();
        $target->syncRoles([]);

        $restored = app(UserAccountLifecycleService::class)->restore(
            target: $target,
            actor: $admin,
            role: User::StaffRoleAccounting,
        );

        $properties = $this->activityProperties($target, 'staff_account_restored');

        $this->assertSame(User::StatusActive, $restored->status);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_reason);
        $this->assertSame([User::StaffRoleAccounting], $restored->fresh()->getRoleNames()->all());
        $this->assertSame(User::StaffRoleAccounting, $properties['role']);
        $this->assertSame(User::StatusActive, $properties['status_after']);
    }

    public function test_staff_lifecycle_actions_require_manage_users_permission(): void
    {
        $actor = User::factory()->create();
        $target = $this->staff(User::StaffRoleRegistrar);

        $this->expectException(AuthorizationException::class);

        app(UserAccountLifecycleService::class)->archive(
            target: $target,
            actor: $actor,
            reason: 'Unauthorized archive attempt.',
        );
    }

    public function test_system_super_admin_cannot_archive_own_account(): void
    {
        $admin = $this->admin();

        $this->expectException(AuthorizationException::class);

        app(UserAccountLifecycleService::class)->archive(
            target: $admin,
            actor: $admin,
            reason: 'Self archive attempt.',
        );
    }

    public function test_restore_requires_archived_target_and_approved_staff_role(): void
    {
        $admin = $this->admin();
        $activeTarget = $this->staff(User::StaffRoleRegistrar);
        $archivedTarget = $this->staff(User::StaffRoleAccounting)->forceFill([
            'status' => User::StatusArchived,
            'archived_at' => now(),
            'archived_reason' => 'Archived for test.',
        ]);
        $archivedTarget->save();

        $this->expectException(ValidationException::class);

        try {
            app(UserAccountLifecycleService::class)->restore(
                target: $activeTarget,
                actor: $admin,
                role: User::StaffRoleAccounting,
            );
        } finally {
            try {
                app(UserAccountLifecycleService::class)->restore(
                    target: $archivedTarget,
                    actor: $admin,
                    role: 'student',
                );
            } catch (ValidationException) {
                $this->assertSame(User::StatusArchived, $archivedTarget->refresh()->status);
            }
        }
    }

    private function admin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage-users');

        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $admin->givePermissionTo('manage-users');

        return $admin;
    }

    private function staff(string $role): User
    {
        foreach (User::staffRoleNames() as $staffRole) {
            Role::findOrCreate($staffRole);
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(User $target, string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
