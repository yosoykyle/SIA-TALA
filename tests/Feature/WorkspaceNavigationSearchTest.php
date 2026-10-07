<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Components\AccessibleSidebar;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\WorkspaceNavigationSearchProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkspaceNavigationSearchTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->assertContains('Home', $this->titles('home'));
        $this->assertSame([], $this->titles('Admission cycles'));
    }

    public function test_compact_table_actions_keep_their_accessible_names(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);
        Livewire::test(ListAdmissionApplications::class)
            ->assertSee('aria-label="Filter applications"', false)
            ->assertSee('aria-label="Columns"', false);
    }

    public function test_sidebar_uses_the_native_page_finder_and_registrar_context(): void
    {
        $this->signIn(User::StaffRoleRegistrar, ['approve-documents']);

        Filament::getCurrentPanel()->boot();

        Livewire::test(AccessibleSidebar::class)
            ->assertSee('Registrar workspace')
            ->assertSee('placeholder="Find a page"', false)
            ->assertDontSee('tala-workspace-search-caption', false);
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
