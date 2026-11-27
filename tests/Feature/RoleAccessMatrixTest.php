<?php

namespace Tests\Feature;

use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifies TAL-93G: the canonical permission seeding grants each role exactly the
 * PRD §2.3 permission set, and the previously permission-hidden Filament surfaces
 * are reachable by their authorized role and blocked for others.
 */
class RoleAccessMatrixTest extends TestCase
{
    /**
     * Transaction-only isolation preserves the persistent TAL-96 acceptance fixture.
     */
    use DatabaseTransactions;

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('canonicalRolePermissions')]
    public function test_seeded_role_has_exactly_its_canonical_permissions(string $role, array $expected): void
    {
        $this->seed(DatabaseSeeder::class);

        $roleModel = Role::findByName($role, 'web');

        $this->assertEqualsCanonicalizing(
            $expected,
            $roleModel->permissions->pluck('name')->all(),
            "{$role} must be granted exactly its PRD §2.3 permission set.",
        );
    }

    /**
     * @param  class-string  $resource
     */
    #[DataProvider('resourceAccessMatrix')]
    public function test_permission_gated_resource_access_matches_role(string $role, string $resource, bool $expected): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = $this->canonicalPermissionsForRole($role);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(
            $expected,
            $resource::canAccess(),
            "{$role} access to {$resource} must match the canonical matrix.",
        );
    }

    public function test_canonical_permission_set_is_complete_and_fully_assigned(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['academic-head', 'accounting', 'applicant', 'faculty', 'registrar', 'student', 'system-super-admin'],
            Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'Exactly the 7 canonical roles must be seeded.',
        );

        $this->assertSame(
            13,
            Permission::query()->where('guard_name', 'web')->count(),
            'Exactly the 13 canonical permissions, including public-notice production, must be seeded.',
        );

        $orphans = Permission::query()->whereDoesntHave('roles')->pluck('name')->all();

        $this->assertSame(
            [],
            $orphans,
            'Every canonical permission must be assigned to at least one role.',
        );
    }

    /**
     * @return array<string, array{role: string, expected: list<string>}>
     */
    public static function canonicalRolePermissions(): array
    {
        return [
            'applicant has no action permissions' => [
                'role' => 'applicant',
                'expected' => [],
            ],
            'student has no action permissions' => [
                'role' => 'student',
                'expected' => [],
            ],
            'registrar owns admissions, records, scheduling, and grade review' => [
                'role' => 'registrar',
                'expected' => [
                    'approve-documents',
                    'evaluate-transferees',
                    'manage-student-profiles',
                    'manage-admission-setup',
                    'manage-schedules',
                    'manage-sections',
                ],
            ],
            'accounting owns assessment, payments, and accommodations' => [
                'role' => 'accounting',
                'expected' => [
                    'create-assessments',
                    'process-payments',
                    'post-accounting-adjustments',
                ],
            ],
            'faculty has no action permissions' => [
                'role' => 'faculty',
                'expected' => [],
            ],
            'academic head owns overrides, curriculum, and oversight' => [
                'role' => 'academic-head',
                'expected' => [
                    'authorize-overrides',
                    'view-global-records',
                ],
            ],
            'system super admin is scoped to configuration content' => [
                'role' => 'system-super-admin',
                'expected' => ['manage-faqs', 'manage-public-notices'],
            ],
        ];
    }

    /**
     * @return array<string, array{role: string, resource: class-string, expected: bool}>
     */
    public static function resourceAccessMatrix(): array
    {
        $publicNoticeAccess = [];
        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            $publicNoticeAccess[$role.' public-notice boundary'] = [
                'role' => $role, 'resource' => PublicNoticeResource::class,
                'expected' => $role === User::StaffRoleSystemSuperAdmin,
            ];
        }

        return [...$publicNoticeAccess,
            'registrar reaches section placement' => [
                'role' => 'registrar', 'resource' => SectionResource::class, 'expected' => true,
            ],
            'registrar reaches official schedules' => [
                'role' => 'registrar', 'resource' => TermTimetable::class, 'expected' => true,
            ],
            'registrar reaches applicant review' => [
                'role' => 'registrar', 'resource' => AdmissionApplicationResource::class, 'expected' => true,
            ],
            'registrar cannot manage FAQs' => [
                'role' => 'registrar', 'resource' => FaqEntryResource::class, 'expected' => false,
            ],
            'accounting reaches fee plans' => [
                'role' => 'accounting', 'resource' => FeePlanResource::class, 'expected' => true,
            ],
            'accounting cannot reach section placement' => [
                'role' => 'accounting', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'faculty reaches assigned class lists' => [
                'role' => 'faculty', 'resource' => FacultyGradeRoster::class, 'expected' => true,
            ],
            'faculty reaches assigned schedules' => [
                'role' => 'faculty', 'resource' => FacultySchedule::class, 'expected' => true,
            ],
            'faculty cannot reach official schedules' => [
                'role' => 'faculty', 'resource' => TermTimetable::class, 'expected' => false,
            ],
            'faculty cannot reach section placement' => [
                'role' => 'faculty', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'faculty cannot reach fee plans' => [
                'role' => 'faculty', 'resource' => FeePlanResource::class, 'expected' => false,
            ],
            'academic head reaches curriculum version' => [
                'role' => 'academic-head', 'resource' => CurriculumVersionResource::class, 'expected' => true,
            ],
            'academic head reaches official schedules' => [
                'role' => 'academic-head', 'resource' => TermTimetable::class, 'expected' => true,
            ],
            'academic head cannot reach section placement' => [
                'role' => 'academic-head', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'system super admin reaches user management' => [
                'role' => 'system-super-admin', 'resource' => UserResource::class, 'expected' => true,
            ],
            'system super admin manages FAQs' => [
                'role' => 'system-super-admin', 'resource' => FaqEntryResource::class, 'expected' => true,
            ],
            'system super admin cannot reach section placement' => [
                'role' => 'system-super-admin', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'system super admin cannot reach official schedules' => [
                'role' => 'system-super-admin', 'resource' => TermTimetable::class, 'expected' => false,
            ],
            'accounting cannot reach official schedules' => [
                'role' => 'accounting', 'resource' => TermTimetable::class, 'expected' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function canonicalPermissionsForRole(string $role): array
    {
        foreach (self::canonicalRolePermissions() as $entry) {
            if ($entry['role'] === $role) {
                return $entry['expected'];
            }
        }

        return [];
    }
}
