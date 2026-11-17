<?php

namespace App\Filament\Clusters;

use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Queries\Admissions\AdmissionQueueCounts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Clusters\Cluster;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Lands on a dashboard of Admission queue cards instead of redirecting straight into the
 * Needs review list, matching the Grades & Completion launcher pattern (#80).
 */
class Admissions extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * The Application queue tab pointed at the page it sits on, with nothing to switch to or from.
     * Admission cycles is reachable from the dashboard and from page actions instead (#80).
     */
    protected static bool $shouldRegisterSubNavigation = false;

    protected string $view = 'filament.pages.admissions-overview';

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }

    public function mount(): void {}

    /**
     * Cluster::getSubNavigation() ignores shouldRegisterSubNavigation() for the cluster's own
     * page (that flag is only consulted by the clustered resource pages), so this page would
     * otherwise still show the Application queue / Admission cycles tabs and mobile dropdown.
     *
     * @return array<NavigationItem|NavigationGroup>
     */
    public function getSubNavigation(): array
    {
        return [];
    }

    /**
     * Cluster::getNavigationItemActiveRoutePattern() only matches *child* routes nested under
     * this cluster (its own route name + '.*'), since Filament assumes a cluster is never visited
     * directly. This cluster doubles as its own dashboard page, so its exact route name must also
     * count as active, or the "Admissions" sidebar item never lights up while viewing it (#80).
     * The parent signature is `string` only, so a single wildcard with no separator before '*'
     * is used instead of an array: it matches the bare route name and every dot-suffixed child.
     */
    public static function getNavigationItemActiveRoutePattern(): string
    {
        return static::getRouteName().'*';
    }

    public function getTitle(): string
    {
        return 'Admissions';
    }

    public function getSubheading(): ?string
    {
        return 'Review applications and manage the admission cycle.';
    }

    public function getBreadcrumbs(): array
    {
        return ['Admissions'];
    }

    /**
     * Admission cycles is setup/management, not an application queue, so it doesn't belong in the
     * "Other queues" card list (it has no count and isn't a status of any application) — it lives
     * here instead, alongside the page title (#80).
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageAdmissionCycles')
                ->label('Admission cycles')
                ->icon('heroicon-o-calendar-date-range')
                ->color('gray')
                ->outlined()
                ->url(fn (): string => AdmissionCycleResource::getUrl()),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $queues = app(AdmissionQueueCounts::class);

        return ['queueCounts' => collect(AdmissionQueueCounts::labels())
            ->mapWithKeys(fn (string $label, string $queue): array => [$queue => $queues->count($queue)])
            ->all()];
    }
}
