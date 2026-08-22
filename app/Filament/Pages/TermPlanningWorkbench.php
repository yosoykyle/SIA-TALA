<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\ClassOfferingTeachingAssignment;
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

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $viewTab = 'overview';

    /** @var list<string> */
    private const Tabs = ['overview', 'classes', 'resources', 'generate', 'correction', 'published'];

    public function mount(): void
    {
        $this->termId ??= Term::query()->latest('starts_on')->value('id');
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

        return [
            $this->recordCalendarPackageAction(),
            $this->activateCalendarPackageAction(),
            $this->confirmClassAction(),
            $this->recordFacultyAvailabilityAction(),
            $this->manageTeachingAssignmentAction(),
        ];
    }

    private function recordCalendarPackageAction(): Action
    {
        return Action::make('recordCalendarPackage')
            ->label('Record Calendar Package')
            ->schema([
                Select::make('term_id')->label('Exact Term')->options(Term::query()->latest('starts_on')->pluck('label', 'id'))->required()->searchable(),
                DatePicker::make('administrative_starts_on')->required(),
                DatePicker::make('administrative_ends_on')->required()->after('administrative_starts_on'),
                DatePicker::make('classes_start_on')->required(),
                DatePicker::make('classes_end_on')->required()->after('classes_start_on'),
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
                    TimePicker::make('cutoff_at')->seconds(false),
                ])->minItems(3)->required()->columns(4),
                Repeater::make('teaching_grid_rows')->schema([
                    Select::make('day_of_week')->options(SectionMeeting::dayOptions())->required(),
                    TimePicker::make('starts_at')->required()->seconds(false),
                    TimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
                    Repeater::make('breaks')->schema([
                        TimePicker::make('starts_at')->required()->seconds(false),
                        TimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
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
                    $term = Term::query()->whereKey((int) $data['term_id'])->lockForUpdate()->firstOrFail();
                    $version = ((int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version')) + 1;
                    $package = TermCalendarPackage::query()->create([
                        ...collect($data)->except(['windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
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
                Select::make('package_id')->label('Draft package')->options(TermCalendarPackage::query()->with('term')->where('state', TermCalendarPackage::StateDraft)->get()->mapWithKeys(
                    fn (TermCalendarPackage $package): array => [$package->id => $package->term?->label.' · v'.$package->version],
                ))->required()->searchable(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(ActivateTermCalendarPackage::class)->execute(TermCalendarPackage::query()->findOrFail((int) $data['package_id']), $actor);
                Notification::make()->title('Calendar Package activated')->success()->send();
            });
    }

    private function confirmClassAction(): Action
    {
        return Action::make('confirmClassOffering')
            ->label('Confirm Class Offering')
            ->schema([
                Select::make('section_id')->label('Class Offering')->options(Section::query()->whereNull('confirmed_at')->orderBy('code')->pluck('code', 'id'))->required()->searchable(),
                Repeater::make('cohorts')->schema([
                    Select::make('term_cohort_id')->label('Cohort')->options(TermCohort::query()->orderBy('reference')->pluck('reference', 'id'))->required()->searchable(),
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

    private function recordFacultyAvailabilityAction(): Action
    {
        return Action::make('recordFacultyAvailability')
            ->label('Record Faculty availability')
            ->schema([
                Select::make('term_id')->label('Exact Term')->options(Term::query()->latest('starts_on')->pluck('label', 'id'))->required()->searchable(),
                Select::make('faculty_user_id')->label('Faculty')->options(User::query()
                    ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                    ->orderBy('name')
                    ->pluck('name', 'id'))->required()->searchable(),
                Select::make('declaration')->options(['Available' => 'Available', 'Unavailable' => 'Unavailable'])->required(),
                Repeater::make('hard_unavailability')->schema([
                    Select::make('day_of_week')->options(SectionMeeting::dayOptions())->required(),
                    TimePicker::make('starts_at')->required()->seconds(false),
                    TimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
                ])->columns(3),
                TextInput::make('correction_reason')->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(RecordFacultyAvailabilityDeclaration::class)->execute(
                    Term::query()->findOrFail((int) $data['term_id']),
                    User::query()->findOrFail((int) $data['faculty_user_id']),
                    $actor,
                    (string) $data['declaration'],
                    $data['hard_unavailability'] ?? [],
                    $data['correction_reason'] ?? null,
                );
                Notification::make()->title('Faculty availability recorded')->success()->send();
            });
    }

    private function manageTeachingAssignmentAction(): Action
    {
        return Action::make('manageTeachingAssignment')
            ->label('Assign Teaching Faculty')
            ->schema([
                Select::make('section_id')
                    ->label('Official Class Offering')
                    ->options(Section::query()->whereNotNull('confirmed_at')->orderBy('code')->pluck('code', 'id'))
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
                'correction' => 'Candidate Correction',
                'published' => 'Published Timetable',
            ],
            'destinations' => [
                'overview' => TermResource::getUrl(),
                'classes' => SectionResource::getUrl(),
                'resources' => RoomResource::getUrl(),
                'generate' => ScheduleGenerationRunResource::getUrl(),
                'correction' => ScheduleGenerationRunResource::getUrl(),
                'published' => SectionMeetingResource::getUrl(),
                'availability' => CalendarEventResource::getUrl(),
            ],
            'readOnly' => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true,
        ];
    }
}
