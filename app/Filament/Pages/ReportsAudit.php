<?php

namespace App\Filament\Pages;

use App\Actions\Reports\ExportOperationalReport;
use App\Actions\Reports\OperationalReportService;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Policies\OperationalReportPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReportsAudit extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports & Audit';

    protected static ?string $navigationLabel = 'Reports / Audit';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'reports-audit';

    protected string $view = 'filament.pages.reports-audit';

    public ?string $reportKey = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OperationalReportPolicy::class)->viewAny($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->reportKey = $this->reports()->defaultFor($this->actor());

        abort_if($this->reportKey === null, 403);
    }

    public function getTitle(): string
    {
        return $this->reportKey === null ? 'Reports / Audit' : $this->reports()->label($this->reportKey);
    }

    public function getSubheading(): ?string
    {
        return $this->reportKey === null ? null : $this->reports()->description($this->reportKey);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->reports()->query($this->currentReportKey(), $this->actor()))
            ->columns($this->tableColumns())
            ->filters([
                Filter::make('scope')
                    ->label('Report filters')
                    ->schema($this->filterSchema())
                    ->columns(3)
                    ->query(fn (Builder $query, array $data): Builder => $this->reports()->applyFilters(
                        $this->currentReportKey(),
                        $query,
                        $data,
                    )),
            ])
            ->stackedOnMobile()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No report rows match the current scope')
            ->emptyStateDescription('Change the controlled filters or select another authorized fixed report.');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('selectReport')
                ->label('Change report')
                ->icon(Heroicon::OutlinedListBullet)
                ->fillForm(fn (): array => ['report_key' => $this->reportKey])
                ->schema([
                    Select::make('report_key')
                        ->label('Fixed report')
                        ->options(fn (): array => $this->reports()->optionsFor($this->actor()))
                        ->helperText('Choose from the fixed reports authorized for your staff role. Each report keeps its approved columns and filters.')
                        ->required()
                        ->native(false),
                ])
                ->modalHeading('Select an authorized report')
                ->modalSubmitActionLabel('Open report')
                ->action(function (array $data): void {
                    $reportKey = (string) $data['report_key'];

                    abort_unless(array_key_exists($reportKey, $this->reports()->optionsFor($this->actor())), 403);

                    $this->reportKey = $reportKey;
                    $this->tableFilters = [];
                    $this->resetTable();
                }),
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->schema([
                    Textarea::make('purpose')
                        ->label('Export purpose')
                        ->required(fn (): bool => $this->reports()->isSensitive($this->currentReportKey()))
                        ->minLength(5)
                        ->maxLength(1000)
                        ->helperText(fn (): string => $this->reports()->isSensitive($this->currentReportKey())
                            ? 'Required because this report contains student, finance, staff, or audit data.'
                            : 'Optional for this normal-sensitivity operational report.'),
                ])
                ->modalHeading(fn (): string => 'Export '.$this->reports()->label($this->currentReportKey()))
                ->modalSubmitActionLabel('Generate CSV')
                ->action(fn (array $data): StreamedResponse => app(ExportOperationalReport::class)->execute(
                    $this->actor(),
                    $this->currentReportKey(),
                    $this->activeFilters(),
                    $data['purpose'] ?? null,
                    request(),
                )),
        ];
    }

    /** @return list<TextColumn> */
    private function tableColumns(): array
    {
        return collect($this->reports()->columns($this->currentReportKey()))
            ->map(function (array $definition): TextColumn {
                $column = TextColumn::make($definition['key'])
                    ->label($definition['label'])
                    ->state(fn (Model $record): string => $this->reports()->value($record, $definition))
                    ->wrap();

                if (($definition['badge'] ?? false) === true) {
                    $column->badge()->color(fn (string $state): string => $this->badgeColor($state));
                }

                return $column;
            })
            ->values()
            ->all();
    }

    /** @return list<Select|DatePicker|TextInput> */
    private function filterSchema(): array
    {
        $supported = $this->reports()->supportedFilters($this->currentReportKey());
        $fields = [];

        if (in_array('academic_year_id', $supported, true)) {
            $fields[] = Select::make('academic_year_id')->label('Academic year')->options(fn () => AcademicYear::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())->searchable();
        }

        if (in_array('term_id', $supported, true)) {
            $fields[] = Select::make('term_id')->label('Term')->options(fn () => Term::query()->with('academicYear')->latest('starts_on')->get()->mapWithKeys(fn (Term $term): array => [$term->id => (data_get($term, 'academicYear.label').' / '.$term->label)])->all())->searchable();
        }

        if (in_array('program_id', $supported, true)) {
            $fields[] = Select::make('program_id')->label('Program')->options(fn () => Program::query()->orderBy('code')->pluck('name', 'id')->all())->searchable();
        }

        if (in_array('section_id', $supported, true)) {
            $fields[] = Select::make('section_id')->label('Section')->options(fn () => Section::query()->orderBy('code')->pluck('code', 'id')->all())->searchable();
        }

        if (in_array('student_profile_id', $supported, true)) {
            $fields[] = Select::make('student_profile_id')->label('Student')->options(fn () => StudentProfile::query()->active()->orderBy('student_number')->get()->mapWithKeys(fn (StudentProfile $profile): array => [$profile->id => $profile->student_number.' / '.collect([$profile->first_name, $profile->last_name])->filter()->implode(' ')])->all())->searchable();
        }

        if (in_array('status', $supported, true)) {
            $fields[] = Select::make('status')->label('Status')->options(fn (): array => $this->reports()->statusOptions($this->currentReportKey()))->native(false);
        }

        if (in_array('event_type', $supported, true)) {
            $fields[] = Select::make('event_type')
                ->label('Event type')
                ->options(fn (): array => $this->reports()->eventTypeOptions($this->currentReportKey()))
                ->native(false);
        }

        if (in_array('actor_id', $supported, true)) {
            $fields[] = Select::make('actor_id')->label('Actor')->options(fn () => User::query()->whereHas('roles')->orderBy('name')->pluck('name', 'id')->all())->searchable();
        }

        if (in_array('output_type', $supported, true)) {
            $fields[] = Select::make('output_type')->label('Output type')->options([
                'COR' => 'COR', 'SOA' => 'SOA', 'BILLING_SLIP' => 'Billing Slip',
                'PAYMENT_ACKNOWLEDGEMENT' => 'Payment Acknowledgement', 'SCHEDULE' => 'Schedule',
                'ROSTER' => 'Roster', 'GRADUATION_SNAPSHOT' => 'Graduation Snapshot', 'REPORT' => 'Report',
            ])->native(false);
        }

        if (in_array('sensitivity', $supported, true)) {
            $fields[] = Select::make('sensitivity')->label('Sensitivity')->options([
                OperationalReportService::SensitivityNormal => 'Normal',
                OperationalReportService::SensitivityStudentData => 'Student Data',
                OperationalReportService::SensitivityFinanceData => 'Finance Data',
                OperationalReportService::SensitivitySensitive => 'Sensitive',
            ])->native(false);
        }

        if (in_array('source_record_id', $supported, true)) {
            $fields[] = TextInput::make('source_record_id')->label('Source record ID')->integer()->minValue(0);
        }

        if (in_array('date_from', $supported, true)) {
            $fields[] = DatePicker::make('date_from')->label('From date')->native(false);
        }

        if (in_array('date_until', $supported, true)) {
            $fields[] = DatePicker::make('date_until')->label('Until date')->native(false)->afterOrEqual('date_from');
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function activeFilters(): array
    {
        $filters = $this->tableFilters['scope'] ?? [];

        return is_array($filters) ? $filters : [];
    }

    private function reports(): OperationalReportService
    {
        return app(OperationalReportService::class);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function currentReportKey(): string
    {
        abort_if($this->reportKey === null, 403);

        return $this->reportKey;
    }

    private function badgeColor(string $state): string
    {
        return match (true) {
            str_contains(strtolower($state), 'failed'),
            str_contains(strtolower($state), 'blocked'),
            str_contains(strtolower($state), 'cancelled'),
            str_contains(strtolower($state), 'revoked') => 'danger',
            str_contains(strtolower($state), 'pending'),
            str_contains(strtolower($state), 'action required'),
            str_contains(strtolower($state), 'incomplete') => 'warning',
            str_contains(strtolower($state), 'active'),
            str_contains(strtolower($state), 'complete'),
            str_contains(strtolower($state), 'generated'),
            str_contains(strtolower($state), 'released') => 'success',
            default => 'info',
        };
    }
}
