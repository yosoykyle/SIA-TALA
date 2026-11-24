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

    public static function getNavigationUrl(): string
    {
        return TermOverview::getUrl();
    }

    /** @return list<NavigationItem> */
    public static function workspaceNavigation(?int $termId, string $active = 'plan'): array
    {
        if ($termId === null) {
            return [];
        }

        $stages = [
            'overview' => [
                'page' => TermCalendar::class,
                'label' => 'Calendar',
                'icon' => Heroicon::OutlinedCalendar,
            ],
            'classes' => [
                'page' => TermClasses::class,
                'label' => 'Classes & sections',
                'icon' => Heroicon::OutlinedRectangleGroup,
            ],
            'resources' => [
                'page' => TermResources::class,
                'label' => 'Faculty & rooms',
                'icon' => Heroicon::OutlinedUsers,
            ],
            'generate' => [
                'page' => TermTimetable::class,
                'label' => 'Timetable',
                'icon' => Heroicon::OutlinedTableCells,
            ],
        ];

        $items = [];
        foreach ($stages as $key => $stage) {
            /** @var class-string<TermPlanningWorkbench> $page */
            $page = $stage['page'];
            if (! $page::canAccess()) {
                continue;
            }

            $items[] = NavigationItem::make($stage['label'])
                ->icon($stage['icon'])
                ->url($page::getUrl(['termId' => $termId]))
                ->isActiveWhen(fn (): bool => $active === $key)
                ->sort(count($items));
        }

        return $items;
    }
}
