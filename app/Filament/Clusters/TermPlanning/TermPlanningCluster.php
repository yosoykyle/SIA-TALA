<?php

namespace App\Filament\Clusters\TermPlanning;

use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class TermPlanningCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Term Planning';

    protected static ?string $slug = 'term-planning';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool
    {
        return TermPlanningWorkbench::canAccess();
    }

    /** @return list<NavigationItem> */
    public static function workspaceNavigation(?int $termId, string $active = 'plan'): array
    {
        $pages = ['plan' => TermOverview::class, 'overview' => TermCalendar::class, 'classes' => TermClasses::class, 'resources' => TermResources::class, 'generate' => TermTimetable::class];
        $items = [];
        foreach ($pages as $key => $page) {
            if (! $page::canAccess()) {
                continue;
            }
            $items[] = NavigationItem::make(match ($key) {
                'plan' => 'Term overview',
                'classes' => 'Sections & classes',
                default => $page::getNavigationLabel(),
            })
                ->url($page::getUrl(['termId' => $termId]))
                ->isActiveWhen(fn (): bool => $active === $key)
                ->sort(count($items));
        }

        return $items;
    }
}
