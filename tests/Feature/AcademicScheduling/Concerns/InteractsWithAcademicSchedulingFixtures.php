<?php

namespace Tests\Feature\AcademicScheduling\Concerns;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithAcademicSchedulingFixtures
{
    /**
     * @param  list<string>  $permissions
     */
    protected function createSchedulingStaff(
        string $roleName,
        array $permissions = [],
        bool $actAs = true,
        bool $withMfa = false,
        bool $verified = false,
    ): User {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate($roleName, 'web');

        if ($permissions !== []) {
            $permissionModels = array_map(
                fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'),
                $permissions,
            );
            $role->givePermissionTo($permissionModels);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $attributes = ['status' => User::StatusActive];
        if ($verified) {
            $attributes['email_verified_at'] = now();
        }
        if ($withMfa) {
            $attributes['two_factor_secret'] = encrypt('JBSWY3DPEHPK3PXP');
            $attributes['two_factor_recovery_codes'] = encrypt(json_encode(['code-1', 'code-2']));
            $attributes['two_factor_confirmed_at'] = now();
            $attributes['two_factor_recovery_codes_acknowledged_at'] = now();
        }

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        if ($actAs) {
            $this->withStaffSession($user, $roleName);
        }

        return $user;
    }

    protected function withStaffSession(User $user, string $roleName): static
    {
        return $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);
    }

    /**
     * @param  list<int>  $days
     * @param  list<array{starts_at:string,ends_at:string}>  $breaks
     * @param  array<string, mixed>  $packageOverrides
     */
    protected function createActiveTermCalendar(
        Term $term,
        ?User $recorder = null,
        array $days = [1, 2, 3, 4, 5],
        string $startsAt = '08:00:00',
        string $endsAt = '17:00:00',
        array $breaks = [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        array $packageOverrides = [],
    ): TermCalendarPackage {
        $attributes = [
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ];

        if ($recorder !== null) {
            $attributes['recorded_by'] = $recorder->id;
        }

        $package = TermCalendarPackage::factory()->for($term)->create([
            ...$attributes,
            ...$packageOverrides,
        ]);

        foreach ($days as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'breaks' => $breaks,
            ]);
        }

        return $package;
    }

    /** @return array{Term, TermCalendarPackage, Program, CurriculumVersion} */
    protected function createActiveTermContext(): array
    {
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $package = $this->createActiveTermCalendar($term);

        $program = Program::factory()->create(['name' => 'Bachelor of Science in Information Technology']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum 2025',
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }
}
