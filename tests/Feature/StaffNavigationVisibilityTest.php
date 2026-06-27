<?php

namespace Tests\Feature;

use App\Filament\Resources\CorVerifications\CorVerificationResource;
use App\Filament\Resources\FacultySubjectEligibilities\FacultySubjectEligibilityResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffNavigationVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
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
            Permission::findOrCreate('manage-cor-verifications', 'web'),
            Permission::findOrCreate('manage-faculty-subject-eligibilities', 'web'),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(CorVerificationResource::canAccess());
        $this->assertFalse(FacultySubjectEligibilityResource::canAccess());
    }

    public function test_seeded_permissions_do_not_include_legacy_navigation_permissions(): void
    {
        $this->assertDatabaseMissing('permissions', ['name' => 'manage-lis']);
        $this->assertDatabaseMissing('permissions', ['name' => 'view-advising-status']);
        $this->assertDatabaseMissing('permissions', ['name' => 'start-enrollment']);
        $this->assertDatabaseMissing('permissions', ['name' => 'upload-enrollment-documents']);
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
                'forbiddenLabels' => ['COR Controls', 'Applicant Review', 'Document Review', 'Schedule Drafts', 'Audit Logs'],
            ],
            'faculty has no registrar accounting or system admin navigation' => [
                'role' => User::StaffRoleFaculty,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'System Administration', 'Academic Head'],
                'forbiddenLabels' => ['Applicant Review', 'Document Review', 'Payment Queue', 'Users', 'Audit Logs'],
            ],
            'academic head has no registrar accounting or system admin navigation groups' => [
                'role' => User::StaffRoleAcademicHead,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'System Administration', 'Faculty'],
                'forbiddenLabels' => ['Applicant Review', 'Document Review', 'COR Controls', 'Accounting Adjustments', 'Payment Queue', 'Confirmed Payments'],
            ],
            'system super admin has no operational workspace navigation' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'forbiddenGroups' => ['Registrar', 'Accounting', 'Faculty', 'Academic Head'],
                'forbiddenLabels' => ['COR Controls', 'Faculty Subject Eligibility', 'Schedule Drafts', 'Enrollments', 'Payment Queue'],
            ],
            'registrar has no accounting faculty or system admin navigation' => [
                'role' => User::StaffRoleRegistrar,
                'forbiddenGroups' => ['Accounting', 'Faculty', 'System Administration', 'Academic Head'],
                'forbiddenLabels' => ['Payment Queue', 'Confirmed Payments', 'Users', 'Audit Logs'],
            ],
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
