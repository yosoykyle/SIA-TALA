<?php

namespace App\Support;

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

        foreach (Filament::getResources() as $resource) {
            $cluster = $resource::getCluster();
            if ($cluster !== null && $resource::shouldRegisterNavigation() && $cluster::canAccess() && $resource::canAccess()) {
                $items = [...$items, ...$this->visibleItems($resource::getNavigationItems())];
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
