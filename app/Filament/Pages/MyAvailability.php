<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\OperationalEvent;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use App\Support\DisplayDateTime;
use App\Support\HasSearchableTermSelection;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class MyAvailability extends Page implements HasTable
{
    use HasSearchableTermSelection;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'My Availability';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My Availability';

    protected string $view = 'filament.pages.my-availability';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-faculty-availability-page'];

    #[Url]
    public ?int $termId = null;

    public function mount(): void
    {
        if ($this->termId !== null && ! Term::query()->whereKey($this->termId)->exists()) {
            $this->termId = null;
        }

        if ($this->termId === null) {
            $activeTermIds = TermCalendarPackage::query()
                ->where('state', TermCalendarPackage::StateActive)
                ->distinct()
                ->pluck('term_id');

            if ($activeTermIds->count() === 1) {
                $this->termId = (int) $activeTermIds->sole();
            }
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function declareAvailabilityAction(): Action
    {
        return Action::make('declareAvailability')
            ->label(fn (): string => $this->hasDeclaration() ? 'Update my availability' : 'Submit my availability')
            ->visible(fn (): bool => $this->termId !== null && ($this->hasDeclaration() || $this->hasAvailabilityRequest()))
            ->icon(Heroicon::OutlinedClock)
            ->modalHeading(fn (): string => $this->hasDeclaration() ? 'Update my availability' : 'Submit my availability')
            ->modalDescription(fn (): string => ($latest = $this->declarationsQuery()->latest('version')->first())
                ? 'Current response · v'.$latest->version.': '.$this->intervalSummary($latest).'. Your update records a new version; current official meetings remain unchanged.'
                : 'Your availability helps the Registrar plan teaching assignments.')
            ->modalSubmitActionLabel(fn (): string => $this->hasDeclaration() ? 'Update availability' : 'Submit response')
            ->fillForm(function (): array {
                $latest = $this->declarationsQuery()->latest('version')->first();

                return [
                    'form_term_id' => $this->termId,
                    'declaration' => $latest?->declaration,
                    'has_weekly_unavailability' => $latest ? ($latest->hard_unavailability === [] ? 'no' : 'yes') : null,
                    'hard_unavailability' => $latest?->hard_unavailability ?? [],
                    'correction_reason' => null,
                ];
            })
            ->schema([
                Hidden::make('form_term_id')->default(fn (): ?int => $this->termId)->required(),
                Radio::make('declaration')
                    ->label('Can you teach this Term?')
                    ->options([
                        FacultyAvailabilityDeclaration::DeclarationAvailable => 'Yes',
                        FacultyAvailabilityDeclaration::DeclarationUnavailable => 'No',
                    ])
                    ->helperText(fn (Get $get): ?string => $get('declaration') === FacultyAvailabilityDeclaration::DeclarationUnavailable ? 'Unavailable for the whole Term.' : null)
                    ->inline()
                    ->live()
                    ->required(),
                Radio::make('has_weekly_unavailability')
                    ->label('Any weekly times you cannot teach?')
                    ->options(['yes' => 'Yes', 'no' => 'No'])
                    ->inline()
                    ->visible(fn (Get $get): bool => $get('declaration') === FacultyAvailabilityDeclaration::DeclarationAvailable)
                    ->helperText(fn (Get $get): ?string => $get('has_weekly_unavailability') === 'no' ? 'No additional restrictions.' : null)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if ($state === 'yes' && empty($get('hard_unavailability'))) {
                            $set('hard_unavailability', [(string) Str::uuid() => ['day_of_week' => null, 'starts_at' => null, 'ends_at' => null]]);
                        }
                    })
                    ->required(),
                Repeater::make('hard_unavailability')
                    ->label('Weekly unavailable times')
                    ->helperText('All times are in Asia/Manila.')
                    ->visible(fn (Get $get): bool => $get('declaration') === FacultyAvailabilityDeclaration::DeclarationAvailable && $get('has_weekly_unavailability') === 'yes')
                    ->addActionLabel('Add another unavailable time')
                    ->addActionAlignment(Alignment::Start)
                    ->defaultItems(0)
                    ->minItems(1)
                    ->required()
                    ->schema([
                        Select::make('day_of_week')
                            ->label('Day')
                            ->options(SectionMeeting::dayOptions())
                            ->required()
                            ->native(true),
                        TimePicker::make('starts_at')
                            ->label('From')
                            ->timezone((string) config('app.timezone'))
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('ends_at')
                            ->label('To')
                            ->timezone((string) config('app.timezone'))
                            ->seconds(false)
                            ->after('starts_at')
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'sm' => 3])
                    ->reorderable(false),
                Textarea::make('correction_reason')
                    ->label('Reason for this update')
                    ->helperText('Explain what changed. Avoid sensitive personal details.')
                    ->visible(fn (): bool => $this->hasDeclaration())
                    ->required(fn (): bool => $this->hasDeclaration())
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $faculty = auth()->user();
                abort_unless($faculty instanceof User, 403);
                if ((int) $data['form_term_id'] !== $this->termId) {
                    Notification::make()->title('Term selection changed')->body('Reopen availability for the selected Term before saving.')->danger()->persistent()->send();
                    throw ValidationException::withMessages(['declaration' => 'Reopen availability for the selected Term before saving.']);
                }
                abort_unless($this->hasDeclaration() || $this->hasAvailabilityRequest(), 409);

                app(RecordFacultyAvailabilityDeclaration::class)->execute(
                    $this->selectedTerm(),
                    $faculty,
                    $faculty,
                    (string) $data['declaration'],
                    $data['declaration'] === FacultyAvailabilityDeclaration::DeclarationAvailable && ($data['has_weekly_unavailability'] ?? null) === 'yes' ? ($data['hard_unavailability'] ?? []) : [],
                    $data['correction_reason'] ?? null,
                );

                Notification::make()
                    ->title('Availability response saved')
                    ->body('The Registrar can use your response to plan teaching assignments for this Term.')
                    ->success()
                    ->send();
            });
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        if ($this->getMountedAction()?->getName() === 'viewAvailability') {
            $this->unmountAction();
        }
        $this->resetTable();
    }

    public function availabilitySummary(FacultyAvailabilityDeclaration $declaration): string
    {
        return $this->intervalSummary($declaration);
    }

    public function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['style' => 'min-inline-size: 0'])
            ->query($this->declarationsQuery())
            ->columns([
                TextColumn::make('version')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => 'v'.$state),
                TextColumn::make('declaration')
                    ->label('Answer')
                    ->formatStateUsing(fn (string $state): string => $state === FacultyAvailabilityDeclaration::DeclarationAvailable ? 'Can teach' : 'Cannot teach'),
                TextColumn::make('hard_unavailability')
                    ->label('Unavailable times')
                    ->state(fn (FacultyAvailabilityDeclaration $record): string => $this->intervalSummary($record))
                    ->wrap(),
                TextColumn::make('correction_reason')
                    ->label('Reason')
                    ->placeholder('Initial response')
                    ->limit(80),
                TextColumn::make('declared_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A'),
            ])
            ->recordActions([$this->viewAvailabilityAction()], position: RecordActionsPosition::AfterColumns)
            ->defaultSort('version', 'desc')
            ->emptyStateHeading($this->termId === null ? 'Select one exact Term' : 'No availability response yet')
            ->emptyStateDescription($this->termId === null
                ? 'Choose a Term above before submitting or reviewing availability.'
                : ($this->hasAvailabilityRequest()
                    ? 'Select Submit my availability to answer the teaching and weekly unavailable-time questions.'
                    : 'Wait for the Registrar to request your availability for this Term before submitting your first response.'))
            ->emptyStateIcon(Heroicon::OutlinedClock);
    }

    public function viewAvailabilityAction(): Action
    {
        return Action::make('viewAvailability')
            ->label('View details')->color('gray')->icon(Heroicon::OutlinedEye)
            ->authorize(fn (FacultyAvailabilityDeclaration $record): bool => static::canAccess() && $this->declarationsQuery()->whereKey($record)->exists())
            ->modalHeading('Availability response')
            ->modalWidth('2xl')
            ->schema(function (FacultyAvailabilityDeclaration $record): array {
                abort_unless(static::canAccess(), 403);
                $owned = $this->declarationsQuery()->with('term.academicYear')->whereKey($record)->firstOrFail();

                return [
                    TextEntry::make('exact_term')->label('Academic Year and Term')->state($owned->term->academicYear?->label.' · '.$owned->term->label.' · Term #'.$owned->term_id),
                    Group::make([
                        TextEntry::make('answer')->label('Can teach this Term?')
                            ->state($owned->declaration === FacultyAvailabilityDeclaration::DeclarationAvailable ? 'Yes' : 'No · Unavailable for the whole Term')
                            ->size(TextSize::Large)->weight(FontWeight::SemiBold)
                            ->columnSpanFull(),
                        TextEntry::make('restrictions')->label('Weekly unavailable times')->state('No additional restrictions')
                            ->visible($owned->declaration === FacultyAvailabilityDeclaration::DeclarationAvailable && $owned->hard_unavailability === [])
                            ->columnSpanFull(),
                        RepeatableEntry::make('blocked_periods')->label('Complete weekly unavailable times · Asia/Manila')
                            ->state($owned->hard_unavailability)
                            ->visible($owned->hard_unavailability !== [])
                            ->schema([
                                TextEntry::make('day_of_week')->label('Day')->formatStateUsing(fn (int $state): string => SectionMeeting::dayOptions()[$state]),
                                TextEntry::make('starts_at')->label('From')->formatStateUsing(fn (string $state): string => $this->displayIntervalTime($state)),
                                TextEntry::make('ends_at')->label('To')->formatStateUsing(fn (string $state): string => $this->displayIntervalTime($state)),
                            ])->columns(['default' => 1, 'sm' => 3])->contained(false)
                            ->columnSpanFull(),
                        TextEntry::make('reason')->label('Reason for this response')->state($owned->correction_reason ?: 'Initial response — no update reason required.')
                            ->columnSpanFull(),
                    ])->dense(),
                    Group::make([
                        TextEntry::make('response_version')->label('Response')->state('v'.$owned->version),
                        TextEntry::make('recorded')->label('Recorded · Asia/Manila')->state(DisplayDateTime::format($owned->declared_at)),
                    ])->columns(['default' => 1, 'sm' => 2])->dense(),
                ];
            })
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $term = $terms->firstWhere('id', $this->termId);
        $package = $this->activePackage();
        $deadlineSource = FacultyAvailabilityDeadline::query()->where('term_id', $term?->id)->latest('id')->first();

        return [
            'terms' => $terms,
            'term' => $term,
            'availabilityRequested' => $this->hasAvailabilityRequest(),
            'hasDeclaration' => $this->hasDeclaration(),
            'currentDeclaration' => $this->declarationsQuery()->latest('version')->first(),
            'availabilityDueAt' => $deadlineSource?->due_at ?? $package?->faculty_availability_due_at,
            'availabilityDeadlineSource' => $deadlineSource?->authority_reference ?? $package?->authority_reference,
            'historicalBlocksUrl' => CalendarEventResource::getUrl('index', ['termId' => $this->termId]),
            'hasHistoricalBlocks' => $term instanceof Term && CalendarEventResource::getEloquentQuery()->where('term_id', $term->id)->exists(),
            'hasPublishedImpact' => $this->hasPublishedImpact(),
        ];
    }

    /** @return Builder<FacultyAvailabilityDeclaration> */
    private function declarationsQuery(): Builder
    {
        $query = FacultyAvailabilityDeclaration::query()
            ->where('faculty_user_id', auth()->id());

        return $this->termId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('term_id', $this->termId);
    }

    private function selectedTerm(): Term
    {
        abort_if($this->termId === null, 404);

        return Term::query()->findOrFail($this->termId);
    }

    private function hasDeclaration(): bool
    {
        return $this->termId !== null && $this->declarationsQuery()->exists();
    }

    private function hasAvailabilityRequest(): bool
    {
        $package = $this->activePackage();

        return $package instanceof TermCalendarPackage
            && OperationalEvent::query()
                ->where('related_record_type', TermCalendarPackage::class)
                ->whereIn('related_record_id', $package->compatibleCoreSourceIds())
                ->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)
                ->where('user_id', auth()->id())
                ->exists();
    }

    private function activePackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
    }

    private function hasPublishedImpact(): bool
    {
        if ($this->termId === null) {
            return false;
        }

        $declaration = $this->declarationsQuery()->latest('version')->first();
        if (! $declaration) {
            return false;
        }

        $affectedIds = Activity::query()
            ->where('event', 'faculty_availability_revision_required')
            ->where('subject_type', FacultyAvailabilityDeclaration::class)
            ->where('subject_id', $declaration->id)
            ->whereJsonContains('properties->term_id', $this->termId)
            ->get()
            ->flatMap(fn (Activity $activity): array => $activity->properties->get('affected_section_meeting_ids', []))
            ->unique()->all();

        return SectionMeeting::query()->activeOfficial()
            ->where('faculty_user_id', auth()->id())
            ->whereHas('scheduleRun', fn (Builder $query): Builder => $query->where('term_id', $this->termId))
            ->whereIn('id', $affectedIds)->exists();
    }

    private function intervalSummary(FacultyAvailabilityDeclaration $record): string
    {
        if ($record->hard_unavailability === []) {
            return $record->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable
                ? 'Unavailable for the whole Term'
                : 'No additional restrictions';
        }

        return collect($record->hard_unavailability)
            ->map(fn (array $interval): string => sprintf(
                '%s, %s–%s',
                SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day',
                $this->displayIntervalTime((string) $interval['starts_at']),
                $this->displayIntervalTime((string) $interval['ends_at']),
            ))
            ->implode('; ');
    }

    private function displayIntervalTime(string $time): string
    {
        return CarbonImmutable::createFromFormat('!H:i', substr($time, 0, 5), (string) config('app.display_timezone', 'Asia/Manila'))->format('g:i A');
    }
}
