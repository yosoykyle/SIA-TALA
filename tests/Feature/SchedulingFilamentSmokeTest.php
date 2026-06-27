<?php

namespace Tests\Feature;

use App\Filament\Resources\FacultyAvailabilityPeriods\FacultyAvailabilityPeriodResource;
use App\Filament\Resources\FacultyAvailabilitySubmissions\FacultyAvailabilitySubmissionResource;
use App\Filament\Resources\FacultySubjectEligibilities\FacultySubjectEligibilityResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SchedulingFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_registrar_can_open_scheduling_admin_surfaces(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, [
            'manage-schedules',
            'manage-faculty-subject-eligibilities',
            'review-lock-faculty-availability',
        ]);

        $this->actingAs($registrar);

        foreach ($this->registrarSchedulingUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_faculty_can_open_only_their_scheduling_self_service_surfaces(): void
    {
        $faculty = $this->staffUser(User::StaffRoleFaculty, [
            'submit-faculty-availability',
        ]);

        $this->actingAs($faculty);

        $this->get(FacultySubjectEligibilityResource::getUrl('index'))->assertOk();
        $this->get(FacultyAvailabilitySubmissionResource::getUrl('index'))->assertOk();
        $this->get(FacultyAvailabilitySubmissionResource::getUrl('create'))->assertOk();

        $this->get(SectionResource::getUrl('index'))->assertForbidden();
        $this->get(FacultySubjectEligibilityResource::getUrl('create'))->assertForbidden();
        $this->get(FacultyAvailabilityPeriodResource::getUrl('create'))->assertForbidden();
        $this->get(ScheduleGenerationRunResource::getUrl('index'))->assertForbidden();
    }

    /**
     * @return list<string>
     */
    private function registrarSchedulingUrls(): array
    {
        return [
            SectionResource::getUrl('index'),
            SectionResource::getUrl('create'),
            SectionMeetingResource::getUrl('index'),
            FacultySubjectEligibilityResource::getUrl('index'),
            FacultySubjectEligibilityResource::getUrl('create'),
            FacultyAvailabilityPeriodResource::getUrl('index'),
            FacultyAvailabilityPeriodResource::getUrl('create'),
            FacultyAvailabilitySubmissionResource::getUrl('index'),
            ScheduleGenerationRunResource::getUrl('index'),
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
