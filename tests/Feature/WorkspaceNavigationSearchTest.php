<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Applicant\Pages\ApplicationHistory;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Clusters\Admissions;
use App\Filament\Clusters\PublicContent;
use App\Filament\Components\AccessibleSidebar;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\FacultyAcademicCalendar;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\SystemHealth;
use App\Filament\Pages\TermOverview;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Filament\Resources\AcademicCalendarWindows\Pages\CreateAcademicCalendarWindow;
use App\Filament\Resources\AcademicCalendarWindows\Pages\ListAcademicCalendarWindows;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\EditAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\ListAcademicYears;
use App\Filament\Resources\AcademicYears\Pages\ViewAcademicYear;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\CreateDuplicateProfileResolution;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\ListDuplicateProfileResolutions;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FaqEntries\Pages\CreateFaqEntry;
use App\Filament\Resources\FaqEntries\Pages\ListFaqEntries;
use App\Filament\Resources\PublicNotices\Pages\CreatePublicNotice;
use App\Filament\Resources\PublicNotices\Pages\ListPublicNotices;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AcademicYear;
use App\Models\ChecklistItem;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\WorkspaceNavigationSearchProvider;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkspaceNavigationSearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registrar_navigation_groups_keep_all_six_destinations_and_admissions_entry(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents', 'manage-admission-setup']);

        $groups = collect(Filament::getNavigation())->mapWithKeys(fn ($group): array => [
            $group->getLabel() => collect($group->getItems())->map->getLabel()->all(),
        ])->all();

        $this->assertSame([
            'Learner work' => ['Admissions', 'Students & Enrollment', 'Students Record'],
            'Academic setup' => ['Programs & Curricula', 'School Resources', 'Term Planning', 'Admission cycles'],
        ], $groups);
        $this->assertSame('/admin/admissions', app(WorkspaceContextResolver::class)
            ->destinationFor($user, User::StaffRoleRegistrar));
    }

    public function test_admissions_nav_item_is_marked_active_while_viewing_its_own_dashboard(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        $response = $this->get(Admissions::getUrl(panel: 'admin'))->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $activeAdmissionsLabel = $xpath->query(
            "//li[contains(concat(' ', normalize-space(@class), ' '), ' fi-active ')]"
            ."//span[contains(@class, 'fi-sidebar-item-label')][contains(., 'Admissions')]",
        );

        $this->assertGreaterThan(0, $activeAdmissionsLabel->length, 'The Admissions sidebar item must be marked active while viewing its own dashboard.');
    }

    public function test_registrar_finds_essential_setup_and_catalog_destinations_without_private_records(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['manage-schedules']);

        foreach ([
            'Academic Years', 'Terms', 'Term Offerings', 'Classes', 'Rooms',
            'Faculty Qualifications', 'Faculty Term Load Overrides', 'Programs', 'Subject catalog',
            'Curricula',
            'Weekly unavailable times', 'Schedule Requirements', 'Generated Timetables',
        ] as $title) {
            $this->assertContains($title, $this->titles($title));
        }

        $this->assertSame([], $this->titles('Published Timetable'));

        $this->assertSame([], $this->titles($user->email));
        $this->assertSame([], $this->titles('Users'));
        $this->assertSame([], $this->titles('Duplicate'));
        $this->assertSame([], $this->titles('Import history'));
        $this->assertSame([], $this->titles('Course Specifications'));
    }

    public function test_accounting_can_find_every_account_work_filter_in_the_sidebar(): void
    {
        $this->signIn(User::StaffRoleAccounting);
        $items = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());
        $accounts = $items->first(fn ($item): bool => $item->getLabel() === 'Student accounts');

        foreach ([
            'All student accounts' => 'accounts',
            'Review receipts' => 'payment_proof',
            'Assessment reviews' => 'assessment_required',
            'Student status changes' => 'student_changes',
        ] as $label => $queue) {
            $item = collect($accounts->getChildItems())->first(fn ($item): bool => $item->getLabel() === $label);
            $this->assertNotNull($item);
            $this->assertSame(EnrollmentResource::getUrl('index', ['tab' => $queue]), $item->getUrl());
            $this->assertContains($label, $this->titles($label));
        }

        $this->assertContains('Payment channels', $this->titles('Payment channels'));
    }

    public function test_registrar_without_schedule_permission_cannot_find_sections(): void
    {
        $this->signIn(User::StaffRoleRegistrar);

        $this->assertSame([], $this->titles('Section'));
        $this->assertContains('Rooms', $this->titles('Rooms'));
    }

    #[DataProvider('otherStaffWorkspaces')]
    public function test_other_workspaces_keep_their_navigation_and_exclude_registrar_setup(string $role, array $labels, array $permissions): void
    {
        $this->signIn($role, $permissions);

        $this->assertSame($labels, collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())->map->getLabel()->values()->all());
        foreach (['Term Offerings', 'Classes', 'Rooms', 'Faculty Qualifications', 'Programs', 'Admission cycles'] as $title) {
            $this->assertNotContains($title, $this->titles($title));
        }
    }

    public function test_multi_role_accounting_context_excludes_registrar_setup_and_admissions_cluster(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents', 'manage-admission-setup', 'manage-schedules']);
        $user->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));
        $this->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAccounting]);

        foreach (['queue', 'cycle', 'Rooms', 'Term Offerings', 'Qualifications', 'Subject catalog'] as $query) {
            $this->assertSame([], $this->titles($query));
        }
        $this->assertContains('Student accounts', $this->titles('Student accounts'));
    }

    public function test_multi_role_user_without_selected_context_gets_no_registrar_destinations(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents', 'manage-admission-setup']);
        $user->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));

        $this->assertSame([], $this->titles('Rooms'));
        $this->assertSame([], $this->titles('queue'));
    }

    public function test_system_administrator_keeps_authorized_public_content_cluster_search(): void
    {
        $this->signIn(User::StaffRoleSystemSuperAdmin, ['manage-faqs', 'manage-public-notices']);

        $this->assertContains('FAQs', $this->titles('FAQs'));

        $publicContent = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->first(fn ($item): bool => $item->getLabel() === 'Public Content');

        $this->assertNotNull($publicContent);
        $this->assertSame(
            ['Announcements', 'FAQs'],
            collect($publicContent->getChildItems())->map->getLabel()->values()->all(),
        );
    }

    public function test_faculty_finds_sibling_availability_without_a_duplicate_sidebar_destination(): void
    {
        $this->signIn(User::StaffRoleFaculty);

        $this->assertContains('Overview', $this->titles('Overview'));
        $this->assertContains('My schedule', $this->titles('Schedule'));
        $this->assertContains('Academic calendar', $this->titles('Academic calendar'));
        $this->assertContains('My availability', $this->titles('Availability'));
        $this->assertContains('Class list & grades', $this->titles('Class list & grades'));
        $this->assertSame([], $this->titles('Term Planning'));
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function otherStaffWorkspaces(): array
    {
        return [
            'Accounting' => [User::StaffRoleAccounting, ['Overview', 'Student accounts', 'Fee plans', 'Payment channels'], []],
            'Faculty' => [User::StaffRoleFaculty, ['Overview', 'My schedule', 'Academic calendar', 'My availability', 'Class list & grades'], []],
            'Academic Head' => [User::StaffRoleAcademicHead, ['Academic Oversight'], []],
            'System Administrator' => [User::StaffRoleSystemSuperAdmin, ['Users & Access', 'Public Content', 'System Health', 'Governance & Audit'], ['manage-faqs', 'manage-public-notices']],
        ];
    }

    public function test_setup_only_registrar_finds_cycles_without_an_application_queue_result(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['manage-admission-setup']);
        $this->assertContains('Admission cycles', $this->titles('cycle'));
        $this->assertSame([], $this->titles('queue'));
    }

    public function test_reviewing_registrar_finds_the_authorized_queue_destination(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['manage-admission-setup', 'approve-documents']);
        $results = app(WorkspaceNavigationSearchProvider::class)->getResults('queue');
        $this->assertNotNull($results);
        $queue = $results->getCategories()->flatten()->first();
        $this->assertSame('Application queue', $queue->title);
        $this->assertStringEndsWith('/admin/admissions/admission-applications', $queue->url);
        $this->assertSame([], $queue->details);
    }

    public function test_other_staff_cannot_find_admissions_destinations(): void
    {
        $this->signIn(User::StaffRoleAccounting);
        $this->assertSame([], $this->titles('queue'));
        $this->assertSame([], $this->titles('cycle'));
    }

    public function test_applicant_sidebar_uses_direct_navigation_without_a_page_finder(): void
    {
        $this->signIn('applicant', panel: 'applicant');
        Filament::getCurrentPanel()->boot();

        $this->assertNull(Filament::getCurrentPanel()->getGlobalSearchProvider());
        Livewire::test(AccessibleSidebar::class)
            ->assertSee('Home')
            ->assertSee('Application')
            ->assertDontSee('Find a page')
            ->assertDontSee('Find a workspace page');
    }

    public function test_search_returns_no_destinations_without_the_active_panel_context(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertNull(app(WorkspaceNavigationSearchProvider::class)->getResults('admissions'));
        $this->signIn(User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->assertNull(app(WorkspaceNavigationSearchProvider::class)->getResults('home'));
    }

    public function test_student_finds_its_workspace_without_staff_setup_results(): void
    {
        $user = $this->signIn('student', panel: 'student');
        StudentProfile::factory()->for($user)->create();
        $this->assertContains('Overview', $this->titles('overview'));
        $this->assertSame([], $this->titles('Admission cycles'));
    }

    public function test_compact_table_actions_keep_their_accessible_names(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);
        Livewire::test(ListAdmissionApplications::class)
            ->assertSee('aria-label="Filter applications"', false)
            ->assertSee('aria-label="Columns"', false)
            ->assertDontSeeHtml('fi-ta-filters-above-content-ctn');
    }

    public function test_record_action_accessible_name_waits_for_its_record(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);
        $table = Livewire::test(ListAdmissionApplications::class)->instance()->getTable();
        $action = Action::make('reviewChecklistItem')
            ->table($table)
            ->icon('heroicon-o-check')
            ->label(fn (ChecklistItem $record): string => 'Review item '.$record->getKey());

        $this->assertArrayNotHasKey('aria-label', $action->getExtraAttributes());

        $action->record(ChecklistItem::factory()->make(['id' => 42]));

        $this->assertSame('Review item 42', $action->getExtraAttributes()['aria-label']);
    }

    public function test_sidebar_uses_the_native_page_finder_and_registrar_context(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);

        Filament::getCurrentPanel()->boot();

        $sidebar = Livewire::test(AccessibleSidebar::class)
            ->assertSee('Registrar workspace')
            ->assertSee('placeholder="Find a page"', false)
            ->assertDontSee('tala-workspace-search-caption', false);

        $this->assertSame(1, substr_count($sidebar->html(), 'placeholder="Find a page"'));
    }

    public function test_page_only_student_sidebar_has_one_native_page_finder(): void
    {
        $user = $this->signIn('student', panel: 'student');
        StudentProfile::factory()->for($user)->create();
        Filament::getCurrentPanel()->boot();

        $sidebar = Livewire::test(AccessibleSidebar::class)
            ->assertSee('placeholder="Find a page"', false)
            ->assertSee('Find a workspace page');

        $this->assertSame(1, substr_count($sidebar->html(), 'placeholder="Find a page"'));
    }

    public function test_sidebar_names_the_selected_staff_context_for_a_multi_role_user(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);
        $user->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));
        $this->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAccounting]);
        Filament::getCurrentPanel()->boot();

        Livewire::test(AccessibleSidebar::class)
            ->assertSee('Accounting workspace')
            ->assertDontSee('Registrar workspace');
    }

    public function test_role_pages_expose_standardized_breadcrumbs_without_generic_crud_segments(): void
    {
        $admin = $this->signIn(User::StaffRoleSystemSuperAdmin, ['manage-faqs', 'manage-public-notices']);
        $targetUser = User::factory()->create(User::staffNamePayload('Elena', null, 'Santos'));

        $this->assertSame([], Livewire::test(ListUsers::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            UserResource::getUrl('index') => 'Users & Access',
            'Elena Santos',
        ], Livewire::test(ViewUser::class, ['record' => $targetUser->getRouteKey()])->instance()->getBreadcrumbs());
        $this->assertSame([], Livewire::test(SystemHealth::class)->instance()->getBreadcrumbs());
        $this->assertSame([], Livewire::test(GovernanceAudit::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            PublicContent::getUrl() => 'Public Content',
            'Announcements',
        ], Livewire::test(ListPublicNotices::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            PublicContent::getUrl() => 'Public Content',
            PublicNoticeResource::getUrl('index') => 'Announcements',
            'Add announcement',
        ], Livewire::test(CreatePublicNotice::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            PublicContent::getUrl() => 'Public Content',
            'FAQs',
        ], Livewire::test(ListFaqEntries::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            PublicContent::getUrl() => 'Public Content',
            FaqEntryResource::getUrl('index') => 'FAQs',
            'Add FAQ',
        ], Livewire::test(CreateFaqEntry::class)->instance()->getBreadcrumbs());
        $this->assertNotNull($admin);

        $this->signIn(User::StaffRoleFaculty);
        $this->assertSame([], Livewire::test(FacultyAcademicCalendar::class)->instance()->getBreadcrumbs());
        $this->assertSame([], Livewire::test(FacultySchedule::class)->instance()->getBreadcrumbs());
        $this->assertSame([], Livewire::test(MyAvailability::class)->instance()->getBreadcrumbs());

        $year = AcademicYear::factory()->create(['label' => 'Academic Year 2030-2031']);

        $this->signIn(User::StaffRoleAcademicHead);
        $this->assertSame([], Livewire::test(AcademicApprovals::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            AcademicApprovals::getUrl() => 'Academic Oversight',
            'Student Profiles',
        ], Livewire::test(ListStudentProfiles::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            AcademicApprovals::getUrl() => 'Academic Oversight',
            'Academic Years',
        ], Livewire::test(ListAcademicYears::class)->instance()->getBreadcrumbs());

        $this->signIn(User::StaffRoleRegistrar, ['approve-documents', 'manage-schedules', 'resolve-duplicate-profiles']);
        $this->assertSame([
            GradesAndCompletion::getUrl(['viewTab' => 'academic-progress']) => 'Students Record',
            'Student Profiles',
        ], Livewire::test(ListStudentProfiles::class)->instance()->getBreadcrumbs());
        $directoryProfiles = Livewire::withQueryParams(['viewTab' => 'directory'])->test(ListStudentProfiles::class);
        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Students & Enrollment',
            'Student Profiles',
        ], $directoryProfiles->instance()->getBreadcrumbs());
        Livewire::withQueryParams([]);
        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Students & Enrollment',
            'Duplicate resolutions',
        ], Livewire::test(ListDuplicateProfileResolutions::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            EnrollmentResource::getUrl('index') => 'Students & Enrollment',
            DuplicateProfileResolutionResource::getUrl('index') => 'Duplicate resolutions',
            'Resolve duplicate',
        ], Livewire::test(CreateDuplicateProfileResolution::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            'Academic Years',
        ], Livewire::test(ListAcademicYears::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            AcademicYearResource::getUrl('index') => 'Academic Years',
            'Academic Year 2030-2031',
        ], Livewire::test(ViewAcademicYear::class, ['record' => $year->getRouteKey()])->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            AcademicYearResource::getUrl('index') => 'Academic Years',
            'Add academic year',
        ], Livewire::test(CreateAcademicYear::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            AcademicYearResource::getUrl('index') => 'Academic Years',
            AcademicYearResource::getUrl('view', ['record' => $year]) => 'Academic Year 2030-2031',
            'Edit',
        ], Livewire::test(EditAcademicYear::class, ['record' => $year->getRouteKey()])->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            'Academic Calendar Windows',
        ], Livewire::test(ListAcademicCalendarWindows::class)->instance()->getBreadcrumbs());
        $this->assertSame([
            TermOverview::getUrl() => 'Term Planning',
            AcademicCalendarWindowResource::getUrl('index') => 'Academic Calendar Windows',
            'Add calendar window',
        ], Livewire::test(CreateAcademicCalendarWindow::class)->instance()->getBreadcrumbs());

        $this->signIn('applicant', panel: 'applicant');
        $this->assertSame([
            ApplicantDashboard::getUrl(panel: 'applicant') => 'Home',
            "Applicant's History",
        ], Livewire::test(ApplicationHistory::class)->instance()->getBreadcrumbs());
    }

    /** @param list<string> $permissions */
    private function signIn(string $roleName, array $permissions = [], string $panel = 'admin'): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'), $permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($panel));

        return $user;
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        return app(WorkspaceNavigationSearchProvider::class)->getResults($query)?->getCategories()
            ->flatten()->pluck('title')->all() ?? [];
    }
}
