<?php

namespace App\Filament\Pages;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class GovernanceAudit extends Page implements HasTable
{
    use InteractsWithTable {
        handleTableFilterUpdates as protected baseHandleTableFilterUpdates;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'Governance & Audit';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'governance-audit';

    protected static ?string $title = 'Governance & Audit';

    public function getSubheading(): ?string
    {
        return 'Read-only institutional audit trail across access, academic, finance, system, and output events.';
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected string $view = 'filament.pages.governance-audit';

    #[Url(as: 'tab', history: true)]
    public string $activeTab = GovernanceEvidenceProjection::InstitutionalChanges;

    #[Url(as: 'actor', history: true)]
    public ?string $actor = null;

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

        $actorId = $this->normalizeActorId($this->actor ?? request()->query('actor'));
        $this->actor = $actorId;

        if ($actorId !== null) {
            $this->tableFilters['actor']['value'] = $actorId;
        }
    }

    /** @return array<string, string> */
    public function getTabsProperty(): array
    {
        return GovernanceEvidenceProjection::tabs();
    }

    public function setActiveTab(string $tab): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(array_key_exists($tab, GovernanceEvidenceProjection::tabs()), 404);

        $this->activeTab = $tab;
        if (is_array($this->tableFilters)) {
            unset($this->tableFilters['type'], $this->tableFilters['source']);
        }
        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->resetPage();
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record)) {
            return (string) ($record['reference_id'] ?? $record['key'] ?? '');
        }

        return (string) $record->getKey();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage, ?string $search, array $filters) => $this->projection()->paginate(
                $this->activeTab,
                $page,
                $recordsPerPage,
                $search,
                $this->effectiveFilters($filters),
            ))
            ->columns([
                TextColumn::make('occurred_date_display')
                    ->label('When')
                    ->tooltip(fn (array $record): string => (string) ($record['occurred_at_display'] ?? ''))
                    ->description(fn (array $record): ?string => filled($record['occurred_time_display'] ?? null)
                        ? (string) $record['occurred_time_display']
                        : null),
                TextColumn::make('type')
                    ->label('Event')
                    ->weight('semibold')
                    ->limit(24)
                    ->tooltip(fn (array $record): string => (string) ($record['type'] ?? ''))
                    ->description(fn (array $record): ?string => filled($record['source'] ?? null)
                        ? Str::limit((string) $record['source'], 28)
                        : null),
                TextColumn::make('actor')
                    ->label('Who')
                    ->limit(22)
                    ->tooltip(fn (array $record): string => (string) ($record['actor_label'] ?? $record['actor'] ?? ''))
                    ->description(fn (array $record): ?string => filled($record['actor_meta'] ?? null)
                        ? Str::limit((string) $record['actor_meta'], 24)
                        : null),
                TextColumn::make('affected_record')
                    ->label('Target')
                    ->limit(22)
                    ->tooltip(fn (array $record): string => (string) ($record['affected_record'] ?? '')),
                TextColumn::make('summary')
                    ->label('Summary')
                    ->limit(28)
                    ->tooltip(fn (array $record): string => (string) ($record['summary'] ?? '')),
            ])
            ->recordActions([
                Action::make('viewDetails')
                    ->label('View detail')
                    ->icon(Heroicon::OutlinedEye)
                    ->button()
                    ->color('gray')
                    ->size(Size::Small)
                    ->slideOver()
                    ->modalWidth(Width::ExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalHeading(fn (array $record): string => (string) ($record['type'] ?? 'Audit event'))
                    ->modalDescription(null)
                    ->modalContent(fn (array $record): View => view('filament.admin.governance.event-detail-modal', [
                        'record' => $record,
                    ])),
            ])
            ->filters([
                SelectFilter::make('actor')
                    ->label('Actor')
                    ->options(fn (): array => $this->projection()->actorOptions())
                    ->searchable(),
                SelectFilter::make('source')
                    ->label('Category')
                    ->options(fn (): array => $this->projection()->sourceOptions($this->activeTab))
                    ->searchable(),
                SelectFilter::make('type')
                    ->label('Event type')
                    ->options(fn (): array => $this->projection()->typeOptions($this->activeTab))
                    ->searchable(),
                Filter::make('date')
                    ->label('Date range')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('from')->label('From date'),
                        DatePicker::make('until')->label('Until date'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = Indicator::make('From '.Carbon::parse((string) $data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = Indicator::make('Until '.Carbon::parse((string) $data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->deferFilters(false)
            ->searchable()
            ->stackedOnMobile()
            ->emptyStateHeading('No governance evidence matches this view')
            ->emptyStateDescription('Clear the active filters or select another evidence tab. No evidence is modified.')
            ->emptyState(view('filament.partials.collection-empty-state'));
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->disabled(fn (): bool => $this->activeTab === GovernanceEvidenceProjection::PrivacyRetention)
                ->tooltip(fn (): ?string => $this->activeTab === GovernanceEvidenceProjection::PrivacyRetention
                    ? 'Switch to an evidence tab to export records.'
                    : 'Download the currently filtered allowlisted evidence as CSV.')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->activeTab === GovernanceEvidenceProjection::PrivacyRetention, 422);

        $search = is_string($this->tableSearch) ? $this->tableSearch : null;
        $filters = $this->effectiveFilters(is_array($this->tableFilters) ? $this->tableFilters : []);
        $rows = $this->projection()->exportRows($this->activeTab, $search, $filters);

        $filename = sprintf(
            'tala-governance-%s-%s.csv',
            $this->activeTab,
            now((string) config('app.display_timezone', 'Asia/Manila'))->format('Y-m-d-Hi'),
        );

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Timestamp',
                'Category',
                'Event',
                'Actor',
                'Actor role',
                'Affected record',
                'Activity summary',
                'Reference ID',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $this->sanitizeCsvCell((string) ($row['occurred_at_display'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['source'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['type'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['actor_label'] ?? $row['actor'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['actor_role_display'] ?? $row['actor_role'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['affected_record'] ?? 'None')),
                    $this->sanitizeCsvCell((string) ($row['summary'] ?? '')),
                    $this->sanitizeCsvCell((string) ($row['reference_id'] ?? '')),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function handleTableFilterUpdates(): void
    {
        $actorValue = $this->tableFilters['actor']['value'] ?? null;
        $this->actor = $this->normalizeActorId(is_scalar($actorValue) ? (string) $actorValue : null);

        $this->baseHandleTableFilterUpdates();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function effectiveFilters(array $filters): array
    {
        if (! filled($filters['actor']['value'] ?? null)) {
            $actorId = $this->normalizeActorId($this->actor);
            if ($actorId !== null) {
                $filters['actor'] = ['value' => $actorId];
            }
        }

        return $filters;
    }

    private function normalizeActorId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ! ctype_digit($trimmed) || (int) $trimmed <= 0) {
            return null;
        }

        return (string) ((int) $trimmed);
    }

    private function sanitizeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function projection(): GovernanceEvidenceProjection
    {
        return app(GovernanceEvidenceProjection::class);
    }
}
