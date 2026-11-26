<?php

namespace App\Filament\Clusters\TermPlanning;

use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Models\Term;
use App\Models\User;
use App\Support\TermPlanningStepState;
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

        $term = Term::query()->find($termId);
        $user = auth()->user();
        $readOnly = ! $user instanceof User
            || $user->hasRole(User::StaffRoleAcademicHead)
            || ! $user->hasRole(User::StaffRoleRegistrar);
        $stepState = $term instanceof Term
            ? TermPlanningStepState::evaluate($term, $readOnly)
            : null;

        $stages = [
            'overview' => [
                'page' => TermCalendar::class,
                'step_key' => 'calendar',
                'label' => '1. Calendar',
                'icon' => Heroicon::OutlinedCalendar,
            ],
            'classes' => [
                'page' => TermClasses::class,
                'step_key' => 'classes',
                'label' => '2. Sections & classes',
                'icon' => Heroicon::OutlinedRectangleGroup,
            ],
            'resources' => [
                'page' => TermResources::class,
                'step_key' => 'resources',
                'label' => '3. Faculty & rooms',
                'icon' => Heroicon::OutlinedUsers,
            ],
            'generate' => [
                'page' => TermTimetable::class,
                'step_key' => 'timetable',
                'label' => '4. Timetable',
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

            $step = $stepState['steps'][$stage['step_key']] ?? null;
            $isLocked = is_array($step) && (bool) ($step['locked'] ?? false) && $active !== $key;

            $item = NavigationItem::make($stage['label'])
                ->icon($isLocked ? Heroicon::OutlinedLockClosed : $stage['icon'])
                ->url($page::getUrl(['termId' => $termId]))
                ->isActiveWhen(fn (): bool => $active === $key)
                ->sort(count($items));

            if ($isLocked) {
                $item
                    ->badge('Locked', 'gray')
                    ->badgeTooltip((string) ($step['locked_reason'] ?? 'Complete the previous step first.'))
                    ->extraAttributes([
                        'data-step-locked' => 'true',
                    ]);
            }

            $items[] = $item;
        }

        return $items;
    }
}
