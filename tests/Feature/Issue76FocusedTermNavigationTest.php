<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Models\Term;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Support\Enums\Alignment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Issue76FocusedTermNavigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_shared_modal_actions_use_native_footer_order_and_end_alignment(): void
    {
        $action = Action::make('save')->livewire(app(TermTimetable::class))
            ->requiresConfirmation()
            ->extraModalFooterActions([Action::make('preview')]);

        $this->assertSame(['submit', 'preview', 'cancel'], array_keys($action->getModalFooterActions()));
        $this->assertSame(Alignment::End, $action->getModalFooterActionsAlignment());

        $readOnly = Action::make('inspect')->livewire(app(TermTimetable::class))->modalSubmitAction(false);
        $this->assertSame(['cancel'], array_keys($readOnly->getModalFooterActions()));
    }

    public function test_registrar_term_workspace_sidebar_keeps_every_destination_on_the_exact_term(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $termId = Term::factory()->create()->id;
        $this->resolveCurrentPage(TermOverview::class, ['termId' => $termId]);

        $groups = $this->staffNavigation()->getNavigation();

        $this->assertSame(['Learner work', 'Academic setup'], array_map(fn ($group): ?string => $group->getLabel(), $groups));
        $this->assertSame(['Admissions', 'Students & Enrollment', 'Grades & Completion'], array_map(fn ($item): string => $item->getLabel(), $groups[0]->getItems()));
        $this->assertSame(['Catalog & Curricula', 'Term Planning'], array_map(fn ($item): string => $item->getLabel(), $groups[1]->getItems()));
        $items = $this->planningChildren();
        $this->assertSame(['Term overview', 'Teaching calendar', 'Classes & student sections', 'Faculty & rooms', 'Timetable'], array_map(fn ($item): string => $item->getLabel(), $items));

        foreach ($items as $item) {
            $this->assertStringContainsString('termId='.$termId, $item->getUrl());
        }

        $this->assertStringContainsString(TermOverview::getUrl(['termId' => $termId]), $items[0]->getUrl());
        $this->assertStringContainsString(TermCalendar::getUrl(['termId' => $termId]), $items[1]->getUrl());
        $this->assertStringContainsString(TermClasses::getUrl(['termId' => $termId]), $items[2]->getUrl());
        $this->assertStringContainsString(TermResources::getUrl(['termId' => $termId]), $items[3]->getUrl());
        $this->assertStringContainsString(TermTimetable::getUrl(['termId' => $termId]), $items[4]->getUrl());
    }

    public function test_term_chooser_selects_the_module_without_term_specific_children(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        foreach ([[], ['chooseTerm' => true], ['chooseTerm' => true, 'termId' => $term->id]] as $query) {
            $this->resolveCurrentPage(TermOverview::class, $query);
            $groups = $this->staffNavigation()->getNavigation();
            $parent = $groups[1]->getItems()[1];

            $this->assertSame('Term Planning', $parent->getLabel());
            $this->assertTrue($parent->isActive());
            $this->assertSame([], $parent->getChildItems());
        }
    }

    public function test_invalid_term_context_does_not_expose_term_specific_navigation(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        foreach (['not-a-term', -7654, 7654] as $termId) {
            $this->resolveCurrentPage(TermOverview::class, ['termId' => $termId]);

            $this->assertSame([], $this->planningChildren());
        }
    }

    public function test_academic_head_term_settings_sidebar_keeps_read_only_planning_and_exact_term_context(): void
    {
        $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $this->resolveCurrentPage(ViewTerm::class, ['viewTab' => 'classes'], ['record' => $term->id]);

        $groups = $this->staffNavigation()->getNavigation();

        $this->assertCount(1, $groups);
        $this->assertSame(['Academic Oversight'], array_map(fn ($item): string => $item->getLabel(), $groups[0]->getItems()));
        $planningItems = $this->planningChildren();
        $this->assertSame(['Term overview', 'Teaching calendar', 'Classes & student sections', 'Faculty & rooms', 'Timetable'], array_map(fn ($item): string => $item->getLabel(), $planningItems));
        $this->assertTrue($planningItems[2]->isActive());

        foreach ($planningItems as $item) {
            $this->assertStringContainsString('termId='.$term->id, $item->getUrl());
        }

        $otherItems = $groups[0]->getItems();
        $this->assertSame(['Academic Oversight'], array_map(fn ($item): string => $item->getLabel(), $otherItems));
        $this->assertStringContainsString('termId='.$term->id, $otherItems[0]->getUrl());
        $this->assertSame(AcademicApprovals::getUrl(['termId' => $term->id]), $otherItems[0]->getUrl());
    }

    public function test_reached_planning_sources_keep_exact_term_navigation_and_their_work_area(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $sources = [
            [ListFacultyQualifications::class, [], 3],
            [ListCalendarEvents::class, ['resourceView' => 'rooms'], 3],
            [ViewScheduleGenerationRun::class, [], 4],
        ];

        foreach ($sources as [$page, $query, $activeIndex]) {
            $this->resolveCurrentPage($page, ['termId' => $term->id, ...$query]);
            $groups = $this->staffNavigation()->getNavigation();
            $this->assertSame(['Learner work', 'Academic setup'], array_map(fn ($group): ?string => $group->getLabel(), $groups));
            $items = $this->planningChildren();
            $this->assertTrue($items[$activeIndex]->isActive());
            foreach ($items as $item) {
                $this->assertStringContainsString('termId='.$term->id, $item->getUrl());
            }
        }
    }

    public function test_registrar_global_navigation_order_stays_the_same_between_admissions_and_planning(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $this->resolveCurrentPage(ListAdmissionApplications::class, []);
        $before = $this->navigationOutline();

        $this->resolveCurrentPage(TermOverview::class, ['termId' => $term->id]);

        $this->assertSame($before, $this->navigationOutline());
        $this->assertCount(5, $this->planningChildren());
    }

    /** @return array<string, list<string>> */
    private function navigationOutline(): array
    {
        $outline = [];
        foreach ($this->staffNavigation()->getNavigation() as $group) {
            $outline[$group->getLabel() ?? ''] = array_map(fn ($item): string => $item->getLabel(), $group->getItems());
        }

        return $outline;
    }

    /** @return list<NavigationItem> */
    private function planningChildren(): array
    {
        foreach ($this->staffNavigation()->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                if (in_array($item->getLabel(), ['Term Planning', 'Academic Oversight'], true)) {
                    return $item->getChildItems();
                }
            }
        }

        return [];
    }

    private function staff(string $roleName): void
    {
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([Permission::findOrCreate('manage-schedules', 'web'), Permission::findOrCreate('approve-documents', 'web')]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param array<string, bool|int|string> $query
     * @param  array<string, int|string>  $routeParameters
     */
    private function resolveCurrentPage(string $page, array $query, array $routeParameters = []): void
    {
        $route = new Route(['GET'], '/admin/focused-page', fn (): null => null);
        $route->name($page::getRouteName());
        $request = Request::create('http://localhost/admin/focused-page', 'GET', $query);
        $route->bind($request);
        foreach ($routeParameters as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn (): Route => $route);
        $this->app->instance('originalRequest', $request);
    }

    private function staffNavigation(): NavigationBuilder
    {
        $method = new \ReflectionMethod(AdminPanelProvider::class, 'staffNavigation');

        return $method->invoke(new AdminPanelProvider($this->app), new NavigationBuilder);
    }
}
