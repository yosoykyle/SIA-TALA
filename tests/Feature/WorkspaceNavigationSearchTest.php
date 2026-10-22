<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Components\AccessibleSidebar;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
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

    public function test_registrar_navigation_groups_keep_all_five_destinations_and_admissions_entry(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);

        $groups = collect(Filament::getNavigation())->mapWithKeys(fn ($group): array => [
            $group->getLabel() => collect($group->getItems())->map->getLabel()->all(),
        ])->all();

        $this->assertSame([
            'Learner work' => ['Admissions', 'Students & Enrollment', 'Grades & Completion'],
            'Academic setup' => ['Catalog & Curricula', 'Term Planning'],
        ], $groups);
        $this->assertSame('/admin/admissions/admission-applications', app(WorkspaceContextResolver::class)
            ->destinationFor($user, User::StaffRoleRegistrar));
    }

    public function test_registrar_finds_essential_setup_and_catalog_destinations_without_private_records(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['manage-schedules']);

        foreach ([
            'Academic Years', 'Terms', 'Term Offerings', 'Classes', 'Rooms',
            'Faculty Qualifications', 'Faculty Term Load Overrides', 'Programs', 'Course Catalog',
            'Course Specifications', 'Curriculum Versions', 'Import Batch Audit',
            'Weekly unavailable times', 'Schedule Requirements', 'Generated Timetables', 'Published Timetable',
        ] as $title) {
            $this->assertContains($title, $this->titles($title));
        }

        $this->assertSame([], $this->titles($user->email));
        $this->assertSame([], $this->titles('Users'));
        $this->assertSame([], $this->titles('Duplicate'));
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
            $this->assertSame([], $this->titles($title));
        }
    }

    public function test_multi_role_accounting_context_excludes_registrar_setup_and_admissions_cluster(): void
    {
        $user = $this->signIn(User::StaffRoleRegistrar, ['approve-documents', 'manage-admission-setup', 'manage-schedules']);
        $user->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));
        $this->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAccounting]);

        foreach (['queue', 'cycle', 'Rooms', 'Term Offerings', 'Qualifications', 'Course Catalog'] as $query) {
            $this->assertSame([], $this->titles($query));
        }
        $this->assertContains('Student Accounts', $this->titles('Student Accounts'));
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

        $this->assertContains('FAQ', $this->titles('FAQ'));
    }

    public function test_faculty_finds_sibling_availability_without_a_duplicate_sidebar_destination(): void
    {
        $this->signIn(User::StaffRoleFaculty);

        $this->assertContains('My Availability', $this->titles('Availability'));
        $this->assertSame([], $this->titles('Term Planning'));
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function otherStaffWorkspaces(): array
    {
        return [
            'Accounting' => [User::StaffRoleAccounting, ['Student Accounts', 'Fee Plans'], []],
            'Faculty' => [User::StaffRoleFaculty, ['My schedule', 'My availability', 'Grade rosters'], []],
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
            ->assertSee('aria-label="Columns"', false);
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
