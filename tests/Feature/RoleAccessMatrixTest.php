<?php

namespace Tests\Feature;

use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies TAL-93G: the canonical permission seeding grants each role exactly the
 * PRD §2.3 permission set, and the previously permission-hidden Filament surfaces
 * are reachable by their authorized role and blocked for others.
 */
class RoleAccessMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('canonicalRolePermissions')]
    public function test_seeded_role_has_exactly_its_canonical_permissions(string $role, array $expected): void
    {
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
        $this->assertSame(
            13,
            Permission::query()->where('guard_name', 'web')->count(),
            'Exactly the 13 canonical permissions must be seeded.',
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
                    'manage-curricula',
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
                    'manage-curricula',
                    'view-global-records',
                ],
            ],
            'system super admin is scoped to configuration content' => [
                'role' => 'system-super-admin',
                'expected' => ['manage-faqs'],
            ],
        ];
    }

    /**
     * @return array<string, array{role: string, resource: class-string, expected: bool}>
     */
    public static function resourceAccessMatrix(): array
    {
        return [
            'registrar reaches section placement' => [
                'role' => 'registrar', 'resource' => SectionResource::class, 'expected' => true,
            ],
            'registrar reaches applicant review' => [
                'role' => 'registrar', 'resource' => ApplicantIntakeResource::class, 'expected' => true,
            ],
            'registrar cannot manage FAQs' => [
                'role' => 'registrar', 'resource' => FaqEntryResource::class, 'expected' => false,
            ],
            'accounting reaches payments' => [
                'role' => 'accounting', 'resource' => PaymentResource::class, 'expected' => true,
            ],
            'accounting cannot reach section placement' => [
                'role' => 'accounting', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'faculty reaches assigned class lists' => [
                'role' => 'faculty', 'resource' => FacultyGradeRoster::class, 'expected' => true,
            ],
            'faculty cannot reach section placement' => [
                'role' => 'faculty', 'resource' => SectionResource::class, 'expected' => false,
            ],
            'faculty cannot reach payments' => [
                'role' => 'faculty', 'resource' => PaymentResource::class, 'expected' => false,
            ],
            'academic head reaches curriculum version' => [
                'role' => 'academic-head', 'resource' => CurriculumVersionResource::class, 'expected' => true,
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
        ];
    }
}
