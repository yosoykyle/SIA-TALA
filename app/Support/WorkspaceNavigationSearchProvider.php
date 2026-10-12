<?php

namespace App\Support;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\Navigation\NavigationItem;

class WorkspaceNavigationSearchProvider implements GlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();

        if ($panel === null || $user === null || ! $user->canAccessPanel($panel) || blank(trim($query))) {
            return null;
        }

        $items = [];
        foreach (Filament::getNavigation() as $group) {
            $items = [...$items, ...$this->visibleItems($group->getItems())];
        }

        $workspaceUrls = array_map(fn (NavigationItem $item): ?string => $item->getUrl(), $items);

        foreach (Filament::getResources() as $resource) {
            $cluster = $resource::getCluster();
            if ($cluster !== null && in_array($cluster::getUrl(), $workspaceUrls, true) && $resource::shouldRegisterNavigation() && $cluster::canAccess() && $resource::canAccess()) {
                $items = [...$items, ...$this->visibleItems($resource::getNavigationItems())];
            }
        }

        if ($panel->getId() === 'admin' && $user instanceof User) {
            $items = [...$items, ...$this->registrarSetupItems($user)];
            $contexts = app(WorkspaceContextResolver::class);
            $selected = $contexts->selected($user);
            if ($selected === null) {
                $available = $contexts->availableContexts($user);
                $selected = count($available) === 1 ? array_key_first($available) : null;
            }
            if ($selected === User::StaffRoleFaculty && MyAvailability::canAccess()) {
                $items[] = NavigationItem::make('My Availability')->url(MyAvailability::getUrl());
                if (FacultySchedule::canAccess()) {
                    $items[] = NavigationItem::make('My Schedule')->url(FacultySchedule::getUrl());
                }
            }
        }

        $matches = collect($items)
            ->unique(fn (NavigationItem $item): string => $item->getLabel().'|'.$item->getUrl())
            ->filter(fn (NavigationItem $item): bool => str($item->getLabel())->lower()->contains(str(trim($query))->lower()->toString()))
            ->take(12)
            ->map(fn (NavigationItem $item): GlobalSearchResult => new GlobalSearchResult($item->getLabel(), $item->getUrl()))
            ->values();

        $results = GlobalSearchResults::make();
        if ($matches->isNotEmpty()) {
            $results->category('Workspace pages', $matches);
        }

        return $results;
    }

    /** @return list<NavigationItem> */
    private function registrarSetupItems(User $user): array
    {
        $contexts = app(WorkspaceContextResolver::class);
        $selected = $contexts->selected($user);

        if ($selected === null) {
            $available = $contexts->availableContexts($user);
            $selected = count($available) === 1 ? array_key_first($available) : null;
        }

        if ($selected !== User::StaffRoleRegistrar) {
            return [];
        }

        $items = [];

        foreach ([
            AcademicYearResource::class,
            TermResource::class,
            TermOfferingResource::class,
            SectionResource::class,
            RoomResource::class,
            FacultyQualificationResource::class,
            FacultyTermLoadOverrideResource::class,
            CalendarEventResource::class,
            SchedulingDemandResource::class,
            ScheduleGenerationRunResource::class,
            SectionMeetingResource::class,
            ProgramResource::class,
            CourseResource::class,
            CourseSpecificationResource::class,
            CurriculumVersionResource::class,
            ImportBatchResource::class,
        ] as $resource) {
            if ($resource::canAccess()) {
                $resourceItems = $this->visibleItems($resource::getNavigationItems());
                if ($resourceItems === [] && $resource::hasPage('index')) {
                    $resourceItems = [
                        NavigationItem::make($resource::getNavigationLabel())
                            ->url($resource::getUrl('index')),
                    ];
                }
                $items = [...$items, ...$resourceItems];
            }
        }

        if (TermPlanningWorkbench::canAccess()) {
            $items = [...$items, ...TermPlanningCluster::workspaceNavigation(request()->integer('termId') ?: null, '')];
        }

        return $items;
    }

    /** @param iterable<NavigationItem> $items
     * @return list<NavigationItem>
     */
    private function visibleItems(iterable $items): array
    {
        $visible = [];
        foreach ($items as $item) {
            if (! $item->isVisible()) {
                continue;
            }
            if (filled($item->getUrl())) {
                $visible[] = $item;
            }
            $visible = [...$visible, ...$this->visibleItems($item->getChildItems())];
        }

        return $visible;
    }
}
