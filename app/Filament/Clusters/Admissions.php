<?php

namespace App\Filament\Clusters;

use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionCycle;
use App\Queries\Admissions\AdmissionQueueCounts;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Lands on a dashboard of Admission queue cards instead of redirecting straight into the
 * Needs review list, matching the Students Record launcher pattern (#80).
 */
class Admissions extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * The Application queue tab pointed at the page it sits on, with nothing to switch to or from.
     * Admission cycles now has its own Academic setup sidebar entry instead of a sub-nav tab (#80).
     */
    protected static bool $shouldRegisterSubNavigation = false;

    protected string $view = 'filament.pages.admissions-overview';

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }

    /** The Student type tab chosen on the Admissions page; null shows every type. */
    #[Url]
    public ?string $type = null;

    public function mount(): void
    {
        $this->type = self::normalizedStudentType($this->type);
    }

    public function selectStudentType(?string $type): void
    {
        $this->type = self::normalizedStudentType($type);
    }

    /** Opens a queue page, carrying the chosen Student type into its Student type filter. */
    public function queueUrl(string $queue): string
    {
        return ListAdmissionApplications::getUrl([
            'tab' => $queue,
            ...($this->type !== null ? ['filters' => ['application_path' => ['value' => $this->type]]] : []),
        ]);
    }

    private static function normalizedStudentType(?string $type): ?string
    {
        return in_array($type, [AdmissionCycle::PathFirstYear, AdmissionCycle::PathTransferee], true) ? $type : null;
    }

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

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $queues = app(AdmissionQueueCounts::class);

        return [
            'queueCounts' => collect(AdmissionQueueCounts::labels())
                ->mapWithKeys(fn (string $label, string $queue): array => [$queue => $queues->count($queue, $this->type)])
                ->all(),
            'studentTypeTabs' => [
                ['type' => null, 'label' => 'All types', 'icon' => null, 'cases' => $queues->activeCases(), 'available' => true],
                ['type' => AdmissionCycle::PathFirstYear, 'label' => 'Freshmen', 'icon' => 'heroicon-o-academic-cap', 'cases' => $queues->activeCases(AdmissionCycle::PathFirstYear), 'available' => true],
                ['type' => AdmissionCycle::PathTransferee, 'label' => 'Transferees', 'icon' => 'heroicon-o-arrows-right-left', 'cases' => $queues->activeCases(AdmissionCycle::PathTransferee), 'available' => true],
                ['type' => 'Returnee', 'label' => 'Returnees', 'icon' => 'heroicon-o-arrow-uturn-left', 'cases' => null, 'available' => false],
            ],
            'studentTypeLabel' => match ($this->type) {
                AdmissionCycle::PathFirstYear => 'Freshmen',
                AdmissionCycle::PathTransferee => 'Transferees',
                default => null,
            },
        ];
    }
}
