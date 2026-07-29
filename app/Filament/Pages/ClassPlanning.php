<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\ClassPlanningWorkflow;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Term;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ClassPlanning extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Offerings & Scheduling';

    protected static ?string $navigationLabel = 'Class Planning';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'class-planning';

    protected string $view = 'filament.pages.class-planning';

    public ?int $termId = null;

    public function mount(): void
    {
        $requestedTermId = request()->integer('term');

        $this->termId = Term::query()
            ->when($requestedTermId > 0, fn ($query) => $query->whereKey($requestedTermId))
            ->orderByRaw('state = ? desc', [Term::StateActive])
            ->orderByDesc('starts_on')
            ->value('id');

        $this->termId ??= Term::query()
            ->orderByRaw('state = ? desc', [Term::StateActive])
            ->orderByDesc('starts_on')
            ->value('id');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string
    {
        return 'Class Planning';
    }

    public function getSubheading(): string
    {
        return 'Follow one term from prerequisite readiness through generated timetable review and explicit Registrar publication.';
    }

    public function selectedTerm(): ?Term
    {
        return filled($this->termId)
            ? Term::query()->with('academicYear')->find($this->termId)
            : null;
    }

    /**
     * @return array{
     *     is_ready: bool,
     *     counts: array<string, int>,
     *     stages: list<array<string, mixed>>
     * }|null
     */
    public function workflowSummary(): ?array
    {
        $term = $this->selectedTerm();

        return $term instanceof Term
            ? app(ClassPlanningWorkflow::class)->present($term)
            : null;
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('chooseTerm')
                ->label('Choose term')
                ->icon(Heroicon::OutlinedCalendar)
                ->schema([
                    Select::make('term_id')
                        ->label('Academic term')
                        ->options(fn (): array => Term::query()
                            ->orderByDesc('starts_on')
                            ->orderBy('label')
                            ->pluck('label', 'id')
                            ->all())
                        ->default($this->termId)
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->termId = (int) $data['term_id'];
                }),
            ActionGroup::make([
                Action::make('termOfferings')
                    ->label('Term offerings')
                    ->url(fn (): string => TermOfferingResource::getUrl('index', [
                        'filters' => ['term' => ['value' => $this->termId]],
                    ])),
                Action::make('sections')
                    ->label('Sections and delivery groups')
                    ->url(SectionResource::getUrl('index'))
                    ->visible(fn (): bool => SectionResource::canAccess()),
                Action::make('rooms')
                    ->label('Rooms')
                    ->url(RoomResource::getUrl('index')),
                Action::make('facultyQualifications')
                    ->label('Faculty qualifications')
                    ->url(FacultyQualificationResource::getUrl('index')),
                Action::make('facultyLoads')
                    ->label('Faculty term loads')
                    ->url(FacultyTermLoadOverrideResource::getUrl('index')),
                Action::make('availability')
                    ->label('Scheduling availability')
                    ->url(CalendarEventResource::getUrl('index')),
                Action::make('requirements')
                    ->label('Schedule requirements')
                    ->url(fn (): string => SchedulingDemandResource::getUrl('index', [
                        'filters' => ['term_id' => ['value' => $this->termId]],
                    ])),
                Action::make('generatedTimetables')
                    ->label('Generated timetables')
                    ->url(fn (): string => ScheduleGenerationRunResource::getUrl('index', [
                        'filters' => ['term_id' => ['value' => $this->termId]],
                    ])),
                Action::make('publishedTimetable')
                    ->label('Published timetable')
                    ->url(fn (): string => SectionMeetingResource::getUrl('index', [
                        'filters' => ['term_id' => ['value' => $this->termId]],
                    ])),
            ])
                ->label('Source records')
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('gray'),
        ];
    }
}
