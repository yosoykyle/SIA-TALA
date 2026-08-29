<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

final class TermPlanningWorkbench extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Term Planning';

    protected static ?string $title = 'Selected-Term Planning';

    protected string $view = 'filament.pages.term-planning-workbench';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-planning-page'];

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $viewTab = 'overview';

    /** @var list<string> */
    private const Tabs = ['overview', 'classes', 'resources', 'generate', 'published'];

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

        $this->viewTab = in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'overview';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [];
        }

        if ($this->termId === null) {
            return [];
        }

        return [
            $this->recordCalendarPackageAction(),
            $this->activateCalendarPackageAction(),
            $this->requestFacultyAvailabilityAction(),
            $this->confirmClassAction(),
            $this->manageTeachingAssignmentAction(),
        ];
    }

    private function recordCalendarPackageAction(): Action
    {
        return Action::make('recordCalendarPackage')
            ->label('Record Calendar Package')
            ->schema([
                DatePicker::make('administrative_starts_on')->required(),
                DatePicker::make('administrative_ends_on')->required()->after('administrative_starts_on'),
                DatePicker::make('classes_start_on')->required(),
                DatePicker::make('classes_end_on')->required()->after('classes_start_on'),
                DateTimePicker::make('faculty_availability_due_at')
                    ->label('Faculty availability deadline')
                    ->timezone((string) config('app.display_timezone'))
                    ->seconds(false)
                    ->required()
                    ->beforeOrEqual('classes_start_on'),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required(),
                TextInput::make('special_term_schedule_basis')->maxLength(255),
                Repeater::make('windows')->schema([
                    Select::make('window_type')->options([
                        'Enrollment' => 'Enrollment',
                        'ExaminationPeriod' => 'Examination Period',
                        'GradeEntry' => 'Grade Entry',
                    ])->required(),
                    DatePicker::make('opens_on')->required(),
                    DatePicker::make('closes_on')->required()->afterOrEqual('opens_on'),
                    TimePicker::make('cutoff_at')->timezone((string) config('app.timezone'))->seconds(false),
                ])->minItems(3)->required()->columns(4),
                Repeater::make('teaching_grid_rows')->schema([
                    Select::make('day_of_week')->options(SectionMeeting::dayOptions())->required(),
                    TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->required()->seconds(false),
                    TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->required()->seconds(false)->after('starts_at'),
                    Repeater::make('breaks')->schema([
                        TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->required()->seconds(false),
                        TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->required()->seconds(false)->after('starts_at'),
                    ])->columns(2),
                ])->minItems(1)->required()->columns(3),
                Repeater::make('dated_exceptions')->schema([
                    DatePicker::make('starts_on')->required(),
                    DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
                    TextInput::make('exception_type')->required()->maxLength(64),
                    TextInput::make('label')->required()->maxLength(255),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                ])->columns(3),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                DB::transaction(function () use ($data, $actor): void {
                    $term = Term::query()->whereKey($this->selectedTerm()->id)->lockForUpdate()->firstOrFail();
                    $version = ((int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version')) + 1;
                    $package = TermCalendarPackage::query()->create([
                        ...collect($data)->except(['windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                        'term_id' => $term->id,
                        'version' => $version,
                        'state' => TermCalendarPackage::StateDraft,
                        'recorded_by' => $actor->id,
                    ]);
                    foreach ($data['windows'] as $window) {
                        $package->windows()->create($window);
                    }
                    foreach ($data['teaching_grid_rows'] as $row) {
                        $package->teachingGridRows()->create($row);
                    }
                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([...$exception, 'blocks_teaching' => true]);
                    }
                }, 3);
                Notification::make()->title('Draft Calendar Package recorded')->body('Run readiness and activate it separately.')->success()->send();
            });
    }

    private function activateCalendarPackageAction(): Action
    {
        return Action::make('activateCalendarPackage')
            ->label('Activate Calendar Package')
            ->color('success')
            ->schema([
                Select::make('package_id')->label('Draft package')->options(TermCalendarPackage::query()
                    ->where('term_id', $this->selectedTerm()->id)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->get()
                    ->mapWithKeys(
                        fn (TermCalendarPackage $package): array => [$package->id => 'v'.$package->version.' · '.$package->authority_reference],
                    ))->required()->searchable(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(ActivateTermCalendarPackage::class)->execute(TermCalendarPackage::query()->findOrFail((int) $data['package_id']), $actor);
                Notification::make()->title('Calendar Package activated')->success()->send();
            });
    }

    private function requestFacultyAvailabilityAction(): Action
    {
        return Action::make('requestFacultyAvailability')
            ->label('Request Faculty Availability')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading('Request exact-Term availability declarations')
            ->modalDescription('Choose only affected Faculty who have not declared for this Term. TALA sends one attributable action-required email for this Calendar Package generation; routine saves send no email.')
            ->schema([
                Select::make('faculty_user_ids')
                    ->label('Affected Faculty')
                    ->options(function (): array {
                        $term = $this->selectedTerm();
                        $declaredIds = FacultyAvailabilityDeclaration::query()
                            ->where('term_id', $term->id)
                            ->pluck('faculty_user_id');

                        return User::query()
                            ->where('status', User::StatusActive)
                            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                            ->whereNotIn('id', $declaredIds)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (): bool => $this->activeCalendarPackage() instanceof TermCalendarPackage)
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $package = $this->activeCalendarPackage();
                abort_unless($package instanceof TermCalendarPackage, 409);

                $events = app(FacultyAvailabilityRequestService::class)->request(
                    $package,
                    $data['faculty_user_ids'] ?? [],
                    $actor,
                );

                Notification::make()
                    ->title('Faculty availability requests recorded')
                    ->body($events->count().' attributable request record(s) are available for delivery tracking and authorized resend.')
                    ->success()
                    ->send();
            });
    }

    private function confirmClassAction(): Action
    {
        return Action::make('confirmClassOffering')
            ->label('Confirm Class Offering')
            ->schema([
                Select::make('section_id')->label('Class Offering')->options(Section::query()
                    ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->selectedTerm()->id))
                    ->whereNull('confirmed_at')
                    ->orderBy('code')
                    ->pluck('code', 'id'))->required()->searchable(),
                Repeater::make('cohorts')->schema([
                    Select::make('term_cohort_id')->label('Cohort')->options(TermCohort::query()
                        ->where('term_id', $this->selectedTerm()->id)
                        ->orderBy('reference')
                        ->pluck('reference', 'id'))->required()->searchable(),
                    TextInput::make('expected_count')->numeric()->integer()->minValue(1)->required(),
                ])->minItems(1)->required()->columns(2),
                TextInput::make('additional_authority_reference')->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $counts = collect($data['cohorts'])->mapWithKeys(
                    fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']],
                )->all();
                app(ConfirmClassOffering::class)->execute(
                    Section::query()->findOrFail((int) $data['section_id']),
                    $actor,
                    $counts,
                    $data['additional_authority_reference'] ?? null,
                );
                Notification::make()->title('Class Offering confirmed')->success()->send();
            });
    }

    private function manageTeachingAssignmentAction(): Action
    {
        return Action::make('manageTeachingAssignment')
            ->label('Assign Teaching Faculty')
            ->schema([
                Select::make('section_id')
                    ->label('Official Class Offering')
                    ->options(Section::query()
                        ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->selectedTerm()->id))
                        ->whereNotNull('confirmed_at')
                        ->orderBy('code')
                        ->pluck('code', 'id'))
                    ->required()->searchable(),
                Select::make('faculty_user_id')
                    ->label('Faculty')
                    ->options(User::query()->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->required()->searchable(),
                Select::make('role')
                    ->options([
                        ClassOfferingTeachingAssignment::RoleDesignated => 'Designated submitter',
                        ClassOfferingTeachingAssignment::RoleCoFaculty => 'View-only co-Faculty',
                    ])->required(),
                TextInput::make('authority_reference')
                    ->label('Assignment authority')
                    ->helperText('Record the memo, load, or Registrar authority used for this attributable assignment.')
                    ->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $section = Section::query()->findOrFail((int) $data['section_id']);
                $faculty = User::query()->findOrFail((int) $data['faculty_user_id']);
                $assignments = app(ManageTeachingAssignment::class);

                if ($data['role'] === ClassOfferingTeachingAssignment::RoleDesignated) {
                    $assignments->designate($section, $faculty, $actor, (string) $data['authority_reference']);
                    app(SynchronizeOfficialGradeRoster::class)->execute($section, $actor);
                } else {
                    $assignments->addCoFaculty($section, $faculty, $actor, (string) $data['authority_reference']);
                }

                Notification::make()->title('Teaching assignment recorded')->success()->send();
            });
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->viewTab = 'overview';
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::Tabs, true), 404);
        $this->viewTab = $tab;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with(['academicYear', 'calendarPackages'])->latest('starts_on')->get();
        $term = $terms->firstWhere('id', $this->termId);
        $activePackage = $term instanceof Term
            ? TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first()
            : null;
        $currentVersion = $term instanceof Term
            ? PublishedTimetableVersion::query()->where('term_id', $term->id)->where('state', PublishedTimetableVersion::StatePublished)->withCount('meetings')->first()
            : null;
        $versions = $term instanceof Term
            ? PublishedTimetableVersion::query()->where('term_id', $term->id)->withCount('meetings')->latest('version')->get()
            : collect();

        return [
            'terms' => $terms,
            'term' => $term,
            'activePackage' => $activePackage,
            'currentVersion' => $currentVersion,
            'versions' => $versions,
            'readiness' => $term instanceof Term ? app(ReadyTermPlanningProjection::class)->forTerm($term) : null,
            'counts' => $term instanceof Term ? [
                'classes' => Section::query()->where('term_calendar_package_id', $activePackage?->id)->count(),
                'confirmed' => Section::query()->where('term_calendar_package_id', $activePackage?->id)->whereNotNull('confirmed_at')->count(),
                'runs' => ScheduleGenerationRun::query()->where('term_id', $term->id)->count(),
            ] : ['classes' => 0, 'confirmed' => 0, 'runs' => 0],
            'tabs' => [
                'overview' => 'Overview',
                'classes' => 'Cohorts & Classes',
                'resources' => 'Teaching Resources',
                'generate' => 'Generate & Review',
                'published' => 'Published Timetable',
            ],
            'destinations' => [
                'overview' => TermResource::getUrl(),
                'classes' => SectionResource::getUrl(),
                'resources' => RoomResource::getUrl(),
                'generate' => ScheduleGenerationRunResource::getUrl(),
                'published' => SectionMeetingResource::getUrl(),
            ],
            'readOnly' => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true,
        ];
    }

    private function selectedTerm(): Term
    {
        abort_if($this->termId === null, 404);

        return Term::query()->findOrFail($this->termId);
    }

    private function activeCalendarPackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
    }
}
