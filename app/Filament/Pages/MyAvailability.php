<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class MyAvailability extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'My Availability';

    protected static ?string $title = 'My Availability';

    protected string $view = 'filament.pages.my-availability';

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

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if ($this->termId === null) {
            return [];
        }

        if (! $this->hasDeclaration() && ! $this->hasAvailabilityRequest()) {
            return [];
        }

        return [
            Action::make('declareAvailability')
                ->label($this->hasDeclaration() ? 'Correct my availability' : 'Submit my availability')
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading('Declare hard availability')
                ->modalDescription('Record only times when you cannot teach. This declaration is attributable and can make a pending candidate stale; it never edits a published timetable automatically.')
                ->schema([
                    Select::make('declaration')
                        ->options(FacultyAvailabilityDeclaration::declarationOptions())
                        ->required()
                        ->native(false),
                    Repeater::make('hard_unavailability')
                        ->label('Unavailable times')
                        ->defaultItems(0)
                        ->schema([
                            Select::make('day_of_week')
                                ->label('Day')
                                ->options(SectionMeeting::dayOptions())
                                ->required()
                                ->native(false),
                            TimePicker::make('starts_at')
                                ->label('Starts')
                                ->timezone((string) config('app.timezone'))
                                ->seconds(false)
                                ->required(),
                            TimePicker::make('ends_at')
                                ->label('Ends')
                                ->timezone((string) config('app.timezone'))
                                ->seconds(false)
                                ->after('starts_at')
                                ->required(),
                        ])
                        ->columns(3)
                        ->reorderable(false),
                    Textarea::make('correction_reason')
                        ->label('Correction reason')
                        ->helperText('Required when replacing an earlier declaration. State what changed; do not include sensitive personal details.')
                        ->required(fn (): bool => $this->hasDeclaration())
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $faculty = auth()->user();
                    abort_unless($faculty instanceof User, 403);
                    abort_unless($this->hasDeclaration() || $this->hasAvailabilityRequest(), 409);

                    app(RecordFacultyAvailabilityDeclaration::class)->execute(
                        $this->selectedTerm(),
                        $faculty,
                        $faculty,
                        (string) $data['declaration'],
                        $data['hard_unavailability'] ?? [],
                        $data['correction_reason'] ?? null,
                    );

                    Notification::make()
                        ->title('Availability declaration recorded')
                        ->body('The Registrar will see this exact-Term source during readiness and timetable review.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->declarationsQuery())
            ->columns([
                TextColumn::make('version')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => 'v'.$state),
                TextColumn::make('declaration')
                    ->label('Declaration')
                    ->badge(),
                TextColumn::make('hard_unavailability')
                    ->label('Hard unavailability')
                    ->state(fn (FacultyAvailabilityDeclaration $record): string => $this->intervalSummary($record))
                    ->wrap(),
                TextColumn::make('correction_reason')
                    ->label('What changed')
                    ->placeholder('Initial declaration')
                    ->wrap(),
                TextColumn::make('declared_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A'),
            ])
            ->defaultSort('version', 'desc')
            ->emptyStateHeading($this->termId === null ? 'Select one exact Term' : 'No availability declaration yet')
            ->emptyStateDescription($this->termId === null
                ? 'Choose a Term above before submitting or reviewing availability.'
                : 'Submit your own hard availability so the Registrar can complete timetable readiness.')
            ->emptyStateIcon(Heroicon::OutlinedClock);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $term = $terms->firstWhere('id', $this->termId);
        $package = $this->activePackage();
        $loadOverride = $term instanceof Term
            ? FacultyTermLoadOverride::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', auth()->id())
                ->latest('id')
                ->first()
            : null;

        return [
            'terms' => $terms,
            'term' => $term,
            'availabilityRequested' => $this->hasAvailabilityRequest(),
            'hasDeclaration' => $this->hasDeclaration(),
            'availabilityDueAt' => $package?->faculty_availability_due_at,
            'qualificationCount' => FacultyQualification::query()
                ->where('faculty_user_id', auth()->id())
                ->where('is_active', true)
                ->count(),
            'termUnitLimit' => $loadOverride instanceof FacultyTermLoadOverride
                ? $loadOverride->allowedLoadUnits()
                : $term?->default_max_units,
            'historicalBlocksUrl' => CalendarEventResource::getUrl(),
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
                ->where('related_record_id', $package->id)
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

        return Activity::query()
            ->where('event', 'faculty_availability_revision_required')
            ->where('causer_id', auth()->id())
            ->whereJsonContains('properties->term_id', $this->termId)
            ->exists();
    }

    private function intervalSummary(FacultyAvailabilityDeclaration $record): string
    {
        if ($record->hard_unavailability === []) {
            return $record->declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable
                ? 'Unavailable for this Term'
                : 'No unavailable times recorded';
        }

        return collect($record->hard_unavailability)
            ->map(fn (array $interval): string => sprintf(
                '%s, %s–%s',
                SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day',
                substr((string) $interval['starts_at'], 0, 5),
                substr((string) $interval['ends_at'], 0, 5),
            ))
            ->implode('; ');
    }
}
