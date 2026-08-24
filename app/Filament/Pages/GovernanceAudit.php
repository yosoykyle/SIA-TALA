<?php

namespace App\Filament\Pages;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use UnitEnum;

class GovernanceAudit extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'Governance & Audit';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'governance-audit';

    protected static ?string $title = 'Governance & Audit';

    protected string $view = 'filament.pages.governance-audit';

    #[Url(as: 'tab', history: true)]
    public string $activeTab = GovernanceEvidenceProjection::InstitutionalChanges;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! array_key_exists($this->activeTab, GovernanceEvidenceProjection::tabs())) {
            $this->activeTab = GovernanceEvidenceProjection::InstitutionalChanges;
        }
    }

    /** @return array<string, string> */
    public function getTabsProperty(): array
    {
        return GovernanceEvidenceProjection::tabs();
    }

    public function setActiveTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, GovernanceEvidenceProjection::tabs()), 404);

        $this->activeTab = $tab;
        $this->tableFilters = [];
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage, ?string $search, array $filters) => $this->projection()->paginate(
                $this->activeTab,
                $page,
                $recordsPerPage,
                $search,
                $filters,
            ))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Date and time')
                    ->wrap(),
                TextColumn::make('actor')
                    ->label('Actor')
                    ->wrap(),
                TextColumn::make('type')
                    ->label('Type')
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Evidence source')
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Attention' ? 'warning' : 'gray'),
                TextColumn::make('summary')
                    ->label('Allowlisted detail')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('actor')
                    ->options(fn (): array => $this->projection()->actorOptions())
                    ->searchable(),
                SelectFilter::make('type')
                    ->options(fn (): array => $this->projection()->typeOptions($this->activeTab))
                    ->searchable(),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From date'),
                        DatePicker::make('until')->label('Until date'),
                    ]),
            ])
            ->searchable()
            ->stackedOnMobile()
            ->paginated([25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No governance evidence matches this view')
            ->emptyStateDescription('Clear the safe filters or select another canonical tab. No evidence is changed.');
    }

    private function projection(): GovernanceEvidenceProjection
    {
        return app(GovernanceEvidenceProjection::class);
    }
}
