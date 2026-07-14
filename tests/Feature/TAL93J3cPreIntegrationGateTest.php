<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TAL93J3cPreIntegrationGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_manifest_exactly_matches_registered_staff_surfaces(): void
    {
        $this->assertSame(self::registeredResources(), $this->registeredSurfaceNames('resources'));
        $this->assertSame(self::registeredPages(), $this->registeredSurfaceNames('pages'));
    }

    public function test_applicant_manifest_exactly_matches_registered_workspace_surfaces(): void
    {
        $applicant = User::factory()->create(['status' => User::StatusApplicantPending]);
        $applicant->assignRole('applicant');

        $this->actingAs($applicant);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $panel = Filament::getPanel('applicant');

        $resources = array_map(class_basename(...), $panel->getResources());
        $pages = array_map(class_basename(...), $panel->getPages());
        $navigation = array_values(array_map(
            static fn (string $page): string => $page::getNavigationLabel(),
            array_filter(
                $panel->getPages(),
                static fn (string $page): bool => $page::shouldRegisterNavigation() && $page::canAccess(),
            ),
        ));

        sort($resources);
        sort($pages);

        $this->assertSame([], $resources);
        $this->assertSame(['Application', 'Dashboard'], $pages);
        $this->assertSame(['My Application', 'Dashboard'], $navigation);
    }

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('roleAccessManifest')]
    public function test_every_registered_surface_has_the_expected_role_access(string $role, array $allowed): void
    {
        $this->authenticateAs($role);

        $actual = [];

        foreach ($this->registeredSurfaces() as $surface) {
            if ($surface::canAccess()) {
                $actual[] = class_basename($surface);
            }
        }

        sort($actual);
        sort($allowed);

        $this->assertSame($allowed, $actual, "{$role} access must match the approved staff-surface manifest.");
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('roleNavigationManifest')]
    public function test_every_role_has_the_expected_registered_navigation(string $role, array $expected): void
    {
        $this->authenticateAs($role);

        $actual = [];

        foreach ($this->registeredSurfaces() as $surface) {
            if ($surface::canAccess() && $surface::shouldRegisterNavigation()) {
                $actual[] = class_basename($surface);
            }
        }

        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, "{$role} navigation must match the approved staff-surface manifest.");
    }

    /**
     * @return array<string, array{role: string, allowed: list<string>}>
     */
    public static function roleAccessManifest(): array
    {
        return [
            'registrar' => ['role' => User::StaffRoleRegistrar, 'allowed' => [
                'AcademicCalendarWindowResource', 'AcademicYearResource', 'AdmissionRequirementPolicyResource',
                'ApplicantIntakeResource', 'AssessmentResource', 'CalendarEventResource', 'CourseResource',
                'CourseSpecificationResource', 'CurriculumVersionResource', 'Dashboard',
                'DuplicateProfileResolutionResource', 'EnrollmentResource', 'FacultyQualificationResource',
                'FacultyTermLoadOverrideResource', 'GradeRosterResource', 'GraduationReviewBatchResource',
                'ImportBatchResource', 'LedgerEntryResource', 'ProgramResource', 'ReportsAudit', 'RoomResource',
                'ScheduleGenerationRunResource', 'SchedulingDemandResource', 'SectionMeetingResource',
                'SectionResource', 'StudentLifecycleChangeResource', 'StudentProfileResource', 'TermOfferingResource',
                'TermResource',
            ]],
            'accounting' => ['role' => User::StaffRoleAccounting, 'allowed' => [
                'AccountingAdjustmentResource', 'AssessmentResource', 'Dashboard', 'EnrollmentResource',
                'FeeRuleResource', 'FinancialAccommodationResource', 'LedgerEntryResource', 'PaymentAttemptResource',
                'PaymentResource', 'ReportsAudit', 'StudentLifecycleChangeResource', 'StudentProfileResource',
            ]],
            'faculty' => ['role' => User::StaffRoleFaculty, 'allowed' => [
                'CalendarEventResource', 'Dashboard', 'FacultyGradeRoster', 'FacultyQualificationResource',
                'FacultySchedule', 'GradeRosterResource',
            ]],
            'academic head' => ['role' => User::StaffRoleAcademicHead, 'allowed' => [
                'AcademicCalendarWindowResource', 'AcademicYearResource', 'CalendarEventResource', 'CourseResource',
                'CourseSpecificationResource', 'CurriculumVersionResource', 'Dashboard', 'EnrollmentResource',
                'FacultyQualificationResource', 'FacultyTermLoadOverrideResource', 'GradeRosterResource',
                'GraduationReviewBatchResource', 'ImportBatchResource', 'ProgramResource', 'ReportsAudit',
                'RoomResource', 'ScheduleGenerationRunResource', 'SchedulingDemandResource',
                'SectionMeetingResource', 'StudentLifecycleChangeResource', 'StudentProfileResource', 'TermResource',
            ]],
            'system super admin' => ['role' => User::StaffRoleSystemSuperAdmin, 'allowed' => [
                'ActivityResource', 'Dashboard', 'DisposalReviewResource', 'EnrollmentResource', 'FaqEntryResource',
                'GraduationReviewBatchResource', 'IntegrationStatus', 'OperationalEventResource', 'ReportsAudit',
                'RoleResource', 'ScheduleGenerationRunResource', 'SchedulingDemandResource',
                'StudentLifecycleChangeResource', 'StudentProfileResource', 'SystemSettingResource',
                'TermOfferingResource', 'UserResource',
            ]],
        ];
    }

    /**
     * @return array<string, array{role: string, expected: list<string>}>
     */
    public static function roleNavigationManifest(): array
    {
        $manifest = self::roleAccessManifest();
        $manifest['faculty']['allowed'] = array_values(array_diff($manifest['faculty']['allowed'], ['GradeRosterResource']));
        $manifest['system super admin']['allowed'] = array_values(array_diff(
            $manifest['system super admin']['allowed'],
            [
                'EnrollmentResource',
                'ScheduleGenerationRunResource',
                'SchedulingDemandResource',
                'TermOfferingResource',
            ],
        ));

        return array_map(
            fn (array $entry): array => ['role' => $entry['role'], 'expected' => $entry['allowed']],
            $manifest,
        );
    }

    /** @return list<string> */
    private static function registeredResources(): array
    {
        return [
            'UserResource', 'RoleResource', 'ActivityResource', 'SystemSettingResource', 'FaqEntryResource',
            'OperationalEventResource', 'ApplicantIntakeResource', 'AdmissionRequirementPolicyResource',
            'EnrollmentResource', 'FeeRuleResource', 'AssessmentResource', 'AccountingAdjustmentResource',
            'FinancialAccommodationResource', 'LedgerEntryResource', 'PaymentAttemptResource', 'PaymentResource',
            'ProgramResource', 'CourseResource', 'CourseSpecificationResource', 'CurriculumVersionResource',
            'ImportBatchResource', 'AcademicYearResource', 'TermResource', 'AcademicCalendarWindowResource',
            'CalendarEventResource', 'RoomResource', 'FacultyQualificationResource',
            'FacultyTermLoadOverrideResource', 'TermOfferingResource', 'SectionResource', 'GradeRosterResource',
            'GraduationReviewBatchResource', 'SchedulingDemandResource', 'ScheduleGenerationRunResource',
            'SectionMeetingResource', 'StudentProfileResource', 'StudentLifecycleChangeResource',
            'DuplicateProfileResolutionResource', 'DisposalReviewResource',
        ];
    }

    /** @return list<string> */
    private static function registeredPages(): array
    {
        return ['Dashboard', 'FacultyGradeRoster', 'FacultySchedule', 'ReportsAudit', 'IntegrationStatus'];
    }

    /**
     * @return list<class-string>
     */
    private function registeredSurfaces(): array
    {
        $panel = Filament::getPanel('admin');

        return [...$panel->getResources(), ...$panel->getPages()];
    }

    /**
     * @return list<string>
     */
    private function registeredSurfaceNames(string $type): array
    {
        $surfaces = $type === 'resources'
            ? Filament::getPanel('admin')->getResources()
            : Filament::getPanel('admin')->getPages();

        return array_map(class_basename(...), $surfaces);
    }

    private function authenticateAs(string $role): void
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        $this->actingAs($user);
    }
}
