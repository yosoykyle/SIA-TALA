<?php

namespace Tests\Feature;

use App\Actions\Reports\ExportOperationalReport;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\ClassPlanning;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Pages\IntegrationStatus;
use App\Filament\Pages\ReportsAudit;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\DisposalReviews\DisposalReviewResource;
use App\Filament\Resources\OperationalEvents\OperationalEventResource;
use App\Filament\Resources\OperationalEvents\Pages\ListOperationalEvents;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Completion;
use App\Filament\Student\Pages\CorView;
use App\Filament\Student\Pages\Enrollment;
use App\Filament\Student\Pages\GradesView;
use App\Filament\Student\Pages\HoldsView;
use App\Filament\Student\Pages\LifecycleView;
use App\Filament\Student\Pages\ScheduleView;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Http\Controllers\BillingSlipController;
use App\Http\Controllers\CorPrintController;
use App\Http\Controllers\FacultySchedulePrintController;
use App\Http\Controllers\FinanceStatementController;
use App\Http\Controllers\PaymentAcknowledgementController;
use App\Http\Controllers\StudentSchedulePrintController;
use App\Mail\ApplicantStatusChangedMail;
use App\Mail\PaymentPostedMail;
use App\Mail\ScheduleReleasedMail;
use App\Mail\ScheduleRevisionMail;
use App\Mail\TestConnectionMail;
use App\Models\GradeRoster;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5E1DRemainingRoleCapabilityClosureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::staffRoleNames() as $role) {
            Role::findOrCreate($role, 'web');
        }

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate('student', 'web');

        Role::findByName(User::StaffRoleRegistrar, 'web')->givePermissionTo([
            Permission::findOrCreate('approve-documents', 'web'),
            Permission::findOrCreate('evaluate-transferees', 'web'),
            Permission::findOrCreate('manage-admission-setup', 'web'),
        ]);
        Role::findByName(User::StaffRoleSystemSuperAdmin, 'web')->givePermissionTo(
            Permission::findOrCreate('manage-faqs', 'web'),
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[DataProvider('staffPrimaryNavigation')]
    #[Test]
    public function each_staff_role_has_only_its_task_centered_primary_navigation(
        string $role,
        array $expectedLabels,
    ): void {
        $this->actingAs($this->staff($role));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame($expectedLabels, $this->navigationLabels());
    }

    #[Test]
    public function applicant_navigation_keeps_requirements_contextual_and_removes_framework_information(): void
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');

        $this->actingAs($applicant);
        $panel = Filament::getPanel('applicant');
        Filament::setCurrentPanel($panel);

        $this->assertSame(['Home', 'Application', 'Requirements'], $this->navigationLabels());
        $this->assertContains(Requirements::class, $panel->getPages());
        $this->assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());
    }

    #[Test]
    public function student_navigation_uses_five_primary_tasks_and_keeps_projections_contextual(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $this->actingAs($student);
        $panel = Filament::getPanel('student');
        Filament::setCurrentPanel($panel);

        $this->assertSame(['Home', 'Enrollment', 'Academics', 'Finance', 'Profile'], $this->navigationLabels());
        $this->assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());

        foreach ([
            ScheduleView::class,
            CorView::class,
            GradesView::class,
            HoldsView::class,
            LifecycleView::class,
            Completion::class,
        ] as $contextualPage) {
            $this->assertContains($contextualPage, $panel->getPages());
        }
    }

    #[Test]
    public function student_academics_is_an_orientation_page_over_authoritative_projections(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Academics::class)
            ->assertOk()
            ->assertSee('Your academic record')
            ->assertSee('Class Schedule')
            ->assertSee('Released Grades')
            ->assertSee('Academic Status and Holds')
            ->assertSee('Completion Review');
    }

    #[Test]
    public function student_enrollment_exposes_schedule_and_cor_as_contextual_results(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        $component = $this->filamentComponent(Enrollment::class);

        $component->assertOk();
        $component
            ->assertActionExists('viewClassSchedule')
            ->assertActionExists('viewCor');
    }

    #[Test]
    public function contextual_task_links_keep_supporting_records_discoverable(): void
    {
        $this->actingAs($this->staff(User::StaffRoleAcademicHead));

        $this->filamentComponent(AcademicReadiness::class)
            ->assertActionExists('classPlanning')
            ->assertActionHasUrl('classPlanning', ClassPlanning::getUrl());

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));

        $this->filamentComponent(ListUsers::class)
            ->assertActionExists('manageRoles')
            ->assertActionHasUrl('manageRoles', RoleResource::getUrl('index'));

        $this->filamentComponent(IntegrationStatus::class)
            ->assertActionExists('systemSettings')
            ->assertActionHasUrl('systemSettings', SystemSettingResource::getUrl('index'));

        $reports = $this->filamentComponent(ReportsAudit::class);

        foreach ([
            'auditLogs' => ActivityResource::getUrl('index'),
            'operationalEvents' => OperationalEventResource::getUrl('index'),
            'disposalReviews' => DisposalReviewResource::getUrl('index'),
        ] as $action => $url) {
            $reports
                ->assertActionExists($action)
                ->assertActionHasUrl($action, $url);
        }
    }

    #[Test]
    public function canonical_blueprint_names_every_registered_capability_and_output_boundary(): void
    {
        $blueprint = file_get_contents(base_path('00_Project_Documents/ui_surface_blueprint.md'));

        $this->assertIsString($blueprint);

        $implementations = collect(['admin', 'applicant', 'student'])
            ->flatMap(function (string $panelId): array {
                $panel = Filament::getPanel($panelId);

                return [
                    ...$panel->getResources(),
                    ...$panel->getPages(),
                    ...$panel->getWidgets(),
                ];
            })
            ->merge([
                ExportOperationalReport::class,
                BillingSlipController::class,
                CorPrintController::class,
                FacultySchedulePrintController::class,
                FinanceStatementController::class,
                PaymentAcknowledgementController::class,
                StudentSchedulePrintController::class,
                ApplicantStatusChangedMail::class,
                PaymentPostedMail::class,
                ScheduleReleasedMail::class,
                ScheduleRevisionMail::class,
                TestConnectionMail::class,
                GeneralSystemNotification::class,
            ])
            ->unique()
            ->sort()
            ->values();

        foreach ($implementations as $implementation) {
            $this->assertStringContainsString(
                '`'.class_basename($implementation).'`',
                $blueprint,
                "The canonical capability inventory does not name {$implementation}.",
            );
        }

        foreach (File::allFiles(resource_path('views')) as $view) {
            $relativePath = str_replace('\\', '/', $view->getRelativePathname());

            $this->assertStringContainsString(
                "`{$relativePath}`",
                $blueprint,
                "The canonical capability inventory does not name the custom Blade boundary {$relativePath}.",
            );
        }

        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            $this->assertFileExists(resource_path("views/errors/{$status}.blade.php"));
            $this->assertStringContainsString("`{$status}`", $blueprint);
        }
    }

    #[Test]
    public function combined_staff_navigation_labels_have_truthful_task_center_pages(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));

        Livewire::test(GradesAndCompletion::class)
            ->assertOk()
            ->assertSee('Registrar academic results')
            ->assertSee('Grade Review and Release')
            ->assertSee('Completion and Graduation Review');

        $this->actingAs($this->staff(User::StaffRoleAcademicHead));

        Livewire::test(AcademicApprovals::class)
            ->assertOk()
            ->assertSee('Academic decisions requiring oversight')
            ->assertSee('Grade Review')
            ->assertSee('Lifecycle Exceptions')
            ->assertSee('Completion Exceptions');
    }

    #[Test]
    public function faculty_can_discover_and_open_their_own_unavailable_blocks(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);

        $this->actingAs($faculty);

        $this->assertContains('My Unavailable Times', $this->navigationLabels());
        $this->assertTrue(CalendarEventResource::canAccess());

        $this->get(CalendarEventResource::getUrl('index'))->assertOk();
    }

    #[Test]
    public function registrar_keeps_scheduling_blocks_contextual_to_class_planning(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));

        $this->assertNotContains('Scheduling Blocks', $this->navigationLabels());
        $this->assertTrue(CalendarEventResource::canAccess());
        $this->get(CalendarEventResource::getUrl('index'))->assertOk();
    }

    #[Test]
    public function faculty_grade_workspace_keeps_submitted_and_released_rosters_as_read_only_history(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $draft = $this->roster($faculty, GradeRoster::StateDraft);
        $submitted = $this->roster($faculty, GradeRoster::StateSubmitted);
        $released = $this->roster($faculty, GradeRoster::StateReleased);

        $this->actingAs($faculty);

        $component = Livewire::test(FacultyGradeRoster::class);
        $options = $this->invoke($component->instance(), 'assignedRosterOptions');

        $this->assertArrayHasKey($draft->id, $options);
        $this->assertArrayHasKey($submitted->id, $options);
        $this->assertArrayHasKey($released->id, $options);

        $component->set('rosterId', $submitted->id);
        $this->assertFalse($this->invoke($component->instance(), 'selectedRosterIsEditable'));

        $component->set('rosterId', $released->id);
        $this->assertFalse($this->invoke($component->instance(), 'selectedRosterIsEditable'));
    }

    /**
     * @param  list<string>  $expectedLabels
     */
    #[DataProvider('remainingRoleWorkspaceSummaries')]
    #[Test]
    public function remaining_staff_roles_receive_a_plain_language_work_summary(
        string $role,
        string $heading,
        array $expectedLabels,
    ): void {
        $this->actingAs($this->staff($role));

        $widget = Livewire::test(StaffRoleWorkspaceOverviewWidget::class)
            ->assertSee($heading);

        foreach ($expectedLabels as $label) {
            $widget->assertSee($label);
        }
    }

    #[Test]
    public function staff_dashboard_does_not_expose_filament_framework_information(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
        $this->assertContains(StaffRoleWorkspaceOverviewWidget::class, $widgets);
        $this->assertSame(
            1,
            collect($widgets)
                ->filter(fn (string $widget): bool => $widget === StaffRoleWorkspaceOverviewWidget::class)
                ->count(),
        );
    }

    #[Test]
    public function raw_audit_surfaces_use_business_labels_and_responsive_tables(): void
    {
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));

        Livewire::test(ListActivities::class)
            ->assertOk()
            ->assertSee('Audit area')
            ->assertSee('Recorded action')
            ->assertSee('Recorded at');

        Livewire::test(ListOperationalEvents::class)
            ->assertOk()
            ->assertSee('Area')
            ->assertSee('Service')
            ->assertSee('Occurred at');
    }

    /**
     * @return array<string, array{role: string, heading: string, expectedLabels: list<string>}>
     */
    public static function remainingRoleWorkspaceSummaries(): array
    {
        return [
            'accounting' => [
                'role' => User::StaffRoleAccounting,
                'heading' => 'Accounting Work',
                'expectedLabels' => ['Fee Setup', 'Student Accounts', 'Payment Exceptions', 'Reports'],
            ],
            'faculty' => [
                'role' => User::StaffRoleFaculty,
                'heading' => 'My Faculty Work',
                'expectedLabels' => ['My Schedule', 'Grade Rosters', 'My Unavailable Times'],
            ],
            'academic head' => [
                'role' => User::StaffRoleAcademicHead,
                'heading' => 'Academic Oversight',
                'expectedLabels' => ['Academic Oversight', 'Approvals', 'Reports'],
            ],
            'system super admin' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'heading' => 'System Administration',
                'expectedLabels' => ['Users & Access', 'Public Content', 'System Health', 'Governance & Audit'],
            ],
        ];
    }

    /**
     * @return array<string, array{role: string, expectedLabels: list<string>}>
     */
    public static function staffPrimaryNavigation(): array
    {
        return [
            'registrar' => [
                'role' => User::StaffRoleRegistrar,
                'expectedLabels' => [
                    'Home',
                    'Admissions',
                    'Admission Cycles',
                    'Academic Readiness',
                    'Class Planning',
                    'Students & Enrollment',
                    'Grades & Completion',
                    'Reports',
                ],
            ],
            'accounting' => [
                'role' => User::StaffRoleAccounting,
                'expectedLabels' => ['Home', 'Student Accounts', 'Payment Exceptions', 'Fee Setup', 'Reports'],
            ],
            'faculty' => [
                'role' => User::StaffRoleFaculty,
                'expectedLabels' => ['Home', 'My Schedule', 'Grade Rosters', 'My Unavailable Times'],
            ],
            'academic head' => [
                'role' => User::StaffRoleAcademicHead,
                'expectedLabels' => ['Home', 'Academic Oversight', 'Approvals', 'Reports'],
            ],
            'system super admin' => [
                'role' => User::StaffRoleSystemSuperAdmin,
                'expectedLabels' => ['Home', 'Users & Access', 'Public Content', 'System Health', 'Governance & Audit'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function navigationLabels(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group) => $group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function roster(User $faculty, string $state): GradeRoster
    {
        return GradeRoster::factory()->create([
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'faculty_user_id' => $faculty->id,
            'state' => $state,
            'submitted_at' => in_array($state, [GradeRoster::StateSubmitted, GradeRoster::StateReleased], true) ? now() : null,
            'released_at' => $state === GradeRoster::StateReleased ? now() : null,
        ]);
    }

    private function invoke(object $object, string $method): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object);
    }

    /**
     * @template TComponent of \Livewire\Component
     *
     * @param  class-string<TComponent>  $component
     * @return Testable<TComponent>
     */
    private function filamentComponent(string $component): Testable
    {
        return Livewire::test($component);
    }
}
