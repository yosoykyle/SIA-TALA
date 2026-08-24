<?php

namespace Tests\Feature;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffNavigationVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        foreach (User::staffRoleNames() as $role) {
            $user = User::factory()->create(['status' => User::StatusActive]);
            $user->assignRole($role);
            $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $user->saveAppAuthenticationRecoveryCodes(['stored-code']);
        }
    }

    /**
     * @param  list<string>  $forbiddenGroups
     * @param  list<string>  $forbiddenLabels
     */
    #[DataProvider('staffNavigationBoundaries')]
    public function test_staff_roles_only_see_current_prd_navigation_for_their_workspace(
        string $role,
        array $forbiddenGroups,
        array $forbiddenLabels,
    ): void {
        $entries = $this->navigationEntriesForRole($role);

        foreach ($forbiddenGroups as $group) {
            $this->assertNotContains($group, array_column($entries, 'group'), "{$role} should not see {$group} navigation.");
        }

        foreach ($forbiddenLabels as $label) {
            $this->assertNotContains($label, array_column($entries, 'label'), "{$role} should not see {$label} navigation.");
        }
    }

    public function test_system_super_admin_with_stale_operational_permissions_still_cannot_see_operational_resources(): void
    {
        $user = User::role(User::StaffRoleSystemSuperAdmin)->firstOrFail();
        $user->givePermissionTo([
            Permission::findOrCreate('manage-faculty-subject-eligibilities', 'web'),
            Permission::findOrCreate('review-lock-faculty-availability', 'web'),
            Permission::findOrCreate('view-faculty-availability', 'web'),
            Permission::findOrCreate('submit-faculty-availability', 'web'),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(CalendarEventResource::canAccess());
        $this->assertFalse(FacultyQualificationResource::canAccess());
    }

    public function test_system_administration_baseline_is_system_super_admin_only(): void
    {
        $systemSuperAdmin = User::role(User::StaffRoleSystemSuperAdmin)->firstOrFail();

        $this->actingAs($systemSuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();

        $this->assertSame([
            'Home',
            'Users & Access',
            'Public Content',
            'System Health',
            'Governance & Audit',
        ], $labels);

        foreach ([
            '/admin/users',
            '/admin/system-health',
            '/admin/governance-audit',
        ] as $path) {
            $this->actingAs($systemSuperAdmin)
                ->get($path)
                ->assertOk();
        }

        foreach (['/admin/roles', '/admin/activities', '/admin/system-settings'] as $retiredPath) {
            $this->actingAs($systemSuperAdmin)
                ->get($retiredPath)
                ->assertNotFound();
        }
    }

    #[DataProvider('nonSystemAdministratorRoles')]
    public function test_other_staff_roles_cannot_access_system_administration(string $role): void
    {
        $user = User::role($role)->firstOrFail();

        foreach ([
            '/admin/users',
            '/admin/system-health',
            '/admin/governance-audit',
        ] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertForbidden();
        }

        foreach (['/admin/roles', '/admin/activities', '/admin/system-settings'] as $retiredPath) {
            $this->actingAs($user)
                ->get($retiredPath)
                ->assertNotFound();
        }
    }

    public function test_seeded_permissions_do_not_include_legacy_navigation_permissions(): void
    {
        $this->assertDatabaseMissing('permissions', ['name' => 'manage-lis']);
        $this->assertDatabaseMissing('permissions', ['name' => 'view-advising-status']);
        $this->assertDatabaseMissing('permissions', ['name' => 'start-enrollment']);
        $this->assertDatabaseMissing('permissions', ['name' => 'upload-enrollment-documents']);
        $this->assertDatabaseMissing('permissions', ['name' => 'manage-faculty-subject-eligibilities']);
        $this->assertDatabaseMissing('permissions', ['name' => 'review-lock-faculty-availability']);
        $this->assertDatabaseMissing('permissions', ['name' => 'submit-faculty-availability']);
        $this->assertDatabaseMissing('permissions', ['name' => 'view-faculty-availability']);
        $this->assertDatabaseMissing('permissions', ['name' => 'encode-grades']);
        $this->assertDatabaseMissing('permissions', ['name' => 'finalize-grades']);
        $this->assertDatabaseMissing('permissions', ['name' => 'verify-grade-submissions']);
        $this->assertDatabaseMissing('permissions', ['name' => 'manage-grade-corrections']);
        $this->assertDatabaseMissing('permissions', ['name' => 'request-grade-corrections']);
        $this->assertDatabaseMissing('permissions', ['name' => 'view-grade-submission-progress']);
        $this->assertDatabaseMissing('permissions', ['name' => 'view-class-list']);
    }

    /**
     * @return array<string, array{role: string, forbiddenGroups: list<string>, forbiddenLabels: list<string>}>
     */
    public static function staffNavigationBoundaries(): array
    {
        return [
            'accounting has no registrar or system admin navigation' => [
                'role' => User::StaffRoleAccounting,
                'forbiddenGroups' => ['Registrar', 'System Administration', 'Faculty', 'Academic Head'],
                'forbiddenLabels' => ['COR Controls', 'Applicant Review', 'Document Review', 'Schedule Drafts', 'Assigned Schedule', 'Audit Logs'],
            ],
            'faculty has no registrar accounting or system admin navigation' => [
                'role' => User::StaffRoleFaculty,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'System Administration', 'Academic Head'],
                'forbiddenLabels' => ['Applicant Review', 'Document Review', 'Payment Queue', 'Users', 'Audit Logs'],
            ],
            'academic head has no registrar accounting or system admin navigation groups' => [
                'role' => User::StaffRoleAcademicHead,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'System Administration', 'Faculty'],
                'forbiddenLabels' => ['Applicant Review', 'Document Review', 'COR Controls', 'Accounting Adjustments', 'Payment Queue', 'Confirmed Payments', 'Assigned Schedule'],
            ],
            'system super admin has no operational workspace navigation' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'Faculty', 'Academic Head'],
                'forbiddenLabels' => ['COR Controls', 'Faculty Subject Eligibility', 'Schedule Drafts', 'Assigned Schedule', 'Enrollments', 'Payment Queue'],
            ],
            'registrar has no accounting faculty or system admin navigation' => [
                'role' => User::StaffRoleRegistrar,
                'forbiddenGroups' => ['Accounting', 'Faculty', 'System Administration', 'Academic Head'],
                'forbiddenLabels' => ['Payment Queue', 'Confirmed Payments', 'Assigned Schedule', 'Users', 'Audit Logs'],
            ],
        ];
    }

    /**
     * @return array<string, array{role: string}>
     */
    public static function nonSystemAdministratorRoles(): array
    {
        return [
            'registrar' => ['role' => User::StaffRoleRegistrar],
            'accounting' => ['role' => User::StaffRoleAccounting],
            'faculty' => ['role' => User::StaffRoleFaculty],
            'academic head' => ['role' => User::StaffRoleAcademicHead],
        ];
    }

    /**
     * @return list<array{group: string, label: string}>
     */
    private function navigationEntriesForRole(string $role): array
    {
        $user = User::role($role)->firstOrFail();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $entries = [];

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            if ($page::shouldRegisterNavigation() && $page::canAccess()) {
                $entries[] = [
                    'group' => (string) ($page::getNavigationGroup() ?? ''),
                    'label' => $page::getNavigationLabel(),
                ];
            }
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource::shouldRegisterNavigation() && $resource::canAccess()) {
                $entries[] = [
                    'group' => (string) ($resource::getNavigationGroup() ?? ''),
                    'label' => $resource::getNavigationLabel(),
                ];
            }
        }

        return $entries;
    }
}
