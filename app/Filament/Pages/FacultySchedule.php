<?php

namespace App\Filament\Pages;

use App\Models\CourseComponent;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Support\DisplayDateTime;
use App\Support\FacultyScheduleFilters;
use App\Support\HasSearchableTermSelection;
use App\Support\TimetablePresentation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class FacultySchedule extends Page implements HasTable
{
    use HasSearchableTermSelection;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Schedule & Availability';

    protected static ?string $title = 'My Schedule';

    protected string $view = 'filament.pages.faculty-schedule';

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $scheduleView = 'week';

    /** @return array<string, array<string, mixed>> */
    protected function queryString(): array
    {
        return ['tableFilters' => ['except' => null], 'tableSearch' => ['except' => '']];
    }

    public function mount(): void
    {
        if (! in_array($this->scheduleView, ['week', 'list'], true)) {
            $this->scheduleView = 'week';
        }
        if ($this->termId !== null && ! Term::query()->whereKey($this->termId)->exists()) {
            $this->termId = null;
        }

        if ($this->termId !== null) {
            return;
        }

        $facultyId = auth()->id();
        $publishedTermIds = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $facultyId)
            ->with('schedulingDemand.termOffering')
            ->get()
            ->pluck('schedulingDemand.termOffering.term_id')
            ->filter()
            ->unique()
            ->values();

        if ($publishedTermIds->count() === 1) {
            $this->termId = (int) $publishedTermIds->sole();

            return;
        }

        if ($publishedTermIds->isEmpty()) {
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

    public function printScheduleAction(): Action
    {
        return Action::make('printSchedule')
            ->label('Print all official meetings')
            ->icon(Heroicon::OutlinedPrinter)
            ->visible(fn (): bool => $this->currentVersion() instanceof PublishedTimetableVersion)
            ->url(fn (): ?string => ($version = $this->currentVersion()) ? route('faculty.schedule.print', ['timetable_version' => $version->id, 'view' => $this->scheduleView]) : null)
            ->openUrlInNewTab();
    }

    public function printFilteredScheduleAction(): Action
    {
        return Action::make('printFilteredSchedule')
            ->label('Print this view')
            ->icon(Heroicon::OutlinedPrinter)
            ->visible(fn (): bool => $this->currentVersion() instanceof PublishedTimetableVersion && $this->scheduleCriteria() !== [])
            ->url(fn (): ?string => ($version = $this->currentVersion()) ? route('faculty.schedule.print', ['timetable_version' => $version->id, 'scope' => 'filtered', 'view' => $this->scheduleView, ...$this->scheduleCriteria()]) : null)
            ->openUrlInNewTab();
    }

    /** @return array<string, int|string> */
    public function scheduleCriteria(): array
    {
        $criteria = ['search' => $this->tableSearch];
        foreach (['course_id', 'class_id', 'room_id', 'day_of_week', 'modality'] as $field) {
            $criteria[$field] = $this->tableFilters[$field]['value'] ?? null;
        }

        return FacultyScheduleFilters::normalize($criteria);
    }

    public function clearScheduleFilters(): void
    {
        $this->removeTableFilters();
        $this->resetTableSearch();
    }

    public function scheduleCriteriaDescription(): string
    {
        return FacultyScheduleFilters::describe($this->scheduleCriteria(), $this->meetingsQuery()->get());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->meetingsQuery())
            ->paginated(fn (): bool => $this->scheduleView === 'list')
            ->content(function (): ?View {
                $version = $this->currentVersion();

                return $this->scheduleView === 'week' && $version
                    ? view('filament.components.faculty-schedule-week', ['presentation' => TimetablePresentation::forPublished($version, $this->getFilteredTableQuery()->get())])
                    : null;
            })
            ->toolbarActions([
                Action::make('weekView')->label('Weekly schedule')->color('gray')->icon(Heroicon::OutlinedCalendarDays)->visible(fn (): bool => $this->scheduleView === 'list')->action(function (): void {
                    $this->scheduleView = 'week';
                    $this->resetPage($this->getTablePaginationPageName());
                }),
                Action::make('listView')->label('Meeting list and filters')->color('gray')->icon(Heroicon::OutlinedListBullet)->visible(fn (): bool => $this->scheduleView === 'week')->action(function (): void {
                    $this->scheduleView = 'list';
                    $this->resetPage($this->getTablePaginationPageName());
                }),
                ActionGroup::make([$this->printFilteredScheduleAction(), $this->printScheduleAction()])->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->button(),
            ])
            ->searchUsing(fn (Builder $query, string $search): Builder => FacultyScheduleFilters::search($query, $search))
            ->searchPlaceholder('Search courses, classes or rooms')
            ->recordActions([$this->viewMeetingAction()])
            ->columns([
                TextColumn::make('schedulingDemand.termOffering.term.label')
                    ->label('Term')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Course')
                    ->description(fn (SectionMeeting $record): ?string => $record->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Class')
                    ->searchable(),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseComponent::typeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextColumn::make('meeting_time')
                    ->label('Time')
                    ->state(fn (SectionMeeting $record): string => self::timeRange($record)),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->searchable()
                    ->placeholder('TBA'),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SectionMeeting::modalityOptions()[$state] ?? str((string) $state)->headline()->toString()),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Course')
                    ->searchable()
                    ->columnSpanFull()
                    ->options(fn (): array => $this->meetingsQuery()->get()->mapWithKeys(function (SectionMeeting $meeting): array {
                        $specification = $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification;

                        return $specification?->course ? [$specification->course->id => $specification->title.' · '.$specification->course->code] : [];
                    })->sort()->all())
                    ->query(fn (Builder $query, array $data): Builder => FacultyScheduleFilters::apply($query, ['course_id' => $data['value'] ?? null])),
                SelectFilter::make('class_id')
                    ->label('Class')
                    ->searchable()
                    ->options(fn (): array => $this->meetingsQuery()->get()->pluck('schedulingDemand.sectionDeliveryGroup.section.code', 'schedulingDemand.sectionDeliveryGroup.section.id')->filter()->all())
                    ->query(fn (Builder $query, array $data): Builder => FacultyScheduleFilters::apply($query, ['class_id' => $data['value'] ?? null])),
                SelectFilter::make('room_id')
                    ->label('Room')
                    ->searchable()
                    ->options(fn (): array => $this->meetingsQuery()->get()->pluck('room.code', 'room_id')->filter()->all())
                    ->query(fn (Builder $query, array $data): Builder => FacultyScheduleFilters::apply($query, ['room_id' => $data['value'] ?? null])),
                SelectFilter::make('day_of_week')
                    ->label('Day')
                    ->options(SectionMeeting::dayOptions())
                    ->query(fn (Builder $query, array $data): Builder => FacultyScheduleFilters::apply($query, ['day_of_week' => $data['value'] ?? null])),
                SelectFilter::make('modality')
                    ->options(SectionMeeting::modalityOptions())
                    ->query(fn (Builder $query, array $data): Builder => FacultyScheduleFilters::apply($query, ['modality' => $data['value'] ?? null])),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters'))
            ->filtersResetAction(fn (Action $action): Action => $action->color('gray'))
            ->deferFilters(false)
            ->filtersFormWidth(Width::TwoExtraLarge)
            ->filtersFormColumns(['default' => 1, 'sm' => 2])
            ->groups([
                Group::make('day_of_week')
                    ->label('Day')
                    ->getTitleFromRecordUsing(fn (SectionMeeting $record): string => SectionMeeting::dayOptions()[(int) $record->day_of_week] ?? 'Unscheduled'),
            ])
            ->defaultGroup('day_of_week')
            ->groupingSettingsHidden()
            ->emptyStateHeading(fn (): string => $this->meetingsQuery()->exists() ? 'No meetings match your filters' : 'No assigned schedule yet')
            ->emptyStateDescription(fn (): string => $this->meetingsQuery()->exists() ? 'Clear the filters and search to see your full official schedule.' : 'Your class assignments appear here after the Registrar publishes your schedule.')
            ->emptyStateActions([
                Action::make('clearScheduleFilters')->label('Clear filters and search')->action(fn () => $this->clearScheduleFilters())->visible(fn (): bool => $this->meetingsQuery()->exists()),
                Action::make('openAvailability')->label('Open My Availability')->color('gray')->url(fn (): string => MyAvailability::getUrl(['termId' => $this->termId]))->visible(fn (): bool => $this->termId !== null && ! $this->meetingsQuery()->exists()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCalendar);
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->resetTable();
    }

    public function viewMeetingAction(): Action
    {
        return Action::make('viewMeeting')
            ->label('Details')->color('gray')->icon(Heroicon::OutlinedEye)
            ->authorize(fn (SectionMeeting $record): bool => ($version = $this->currentVersion()) && $this->meetingsQuery()->where('published_timetable_version_id', $version->id)->whereKey($record)->exists())
            ->modalHeading('Class meeting')
            ->modalWidth('lg')
            ->modalContent(function (SectionMeeting $record): View {
                $version = $this->currentVersion();
                abort_unless($version, 404);
                $owned = $this->meetingsQuery()->where('published_timetable_version_id', $version->id)->whereKey($record)->firstOrFail();

                return view('filament.components.faculty-meeting-details', ['meeting' => TimetablePresentation::forPublished($version, collect([$owned]))['meetings'][0]]);
            })
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $meetings = $this->getFilteredTableQuery()->get();
        $assignedMeetings = $this->meetingsQuery()->get();
        $version = $this->currentVersion();
        $package = $this->termId === null ? null : TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('windows')
            ->first();
        $examinationPeriod = $package?->windows->first(
            fn (TermCalendarWindow $window): bool => $window->window_type === 'ExaminationPeriod',
        );

        return [
            'terms' => $terms,
            'term' => $terms->firstWhere('id', $this->termId),
            'version' => $version,
            'presentation' => $version ? TimetablePresentation::forPublished($version, $meetings) : null,
            'assignmentCount' => $assignedMeetings->count(),
            'classCount' => $assignedMeetings->pluck('schedulingDemand.sectionDeliveryGroup.section.id')->filter()->unique()->count(),
            'teachingDayCount' => $assignedMeetings->pluck('day_of_week')->filter()->unique()->count(),
            'meetingsByDay' => $meetings->groupBy('day_of_week'),
            'revisionEvents' => $this->revisionEvents(),
            'examinationPeriod' => $examinationPeriod,
            'examinationSource' => $package,
            'asOf' => DisplayDateTime::format(now()),
        ];
    }

    /**
     * @return Builder<SectionMeeting>
     */
    private function meetingsQuery(): Builder
    {
        return SectionMeeting::query()
            ->activeOfficial()
            ->with([
                'schedulingDemand.termOffering.term',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.courseComponent',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'faculty',
                'room',
            ])
            ->where('faculty_user_id', auth()->id())
            ->when(
                $this->termId !== null,
                fn (Builder $query): Builder => $query->whereHas(
                    'schedulingDemand.termOffering',
                    fn (Builder $query): Builder => $query->where('term_id', $this->termId),
                ),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    private function currentVersion(): ?PublishedTimetableVersion
    {
        if ($this->termId === null) {
            return null;
        }

        return PublishedTimetableVersion::query()
            ->with(['scheduleRun', 'term.academicYear'])
            ->where('term_id', $this->termId)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->whereHas('meetings', fn ($query) => $query->where('faculty_user_id', auth()->id()))
            ->latest('version')
            ->first();
    }

    /** @return Collection<int, ScheduleRevisionEvent> */
    private function revisionEvents(): Collection
    {
        if ($this->termId === null) {
            return collect();
        }

        $facultyId = (int) auth()->id();

        return ScheduleRevisionEvent::query()
            ->where('term_id', $this->termId)
            ->latest('effective_date')
            ->get()
            ->filter(function (ScheduleRevisionEvent $event) use ($facultyId): bool {
                $oldFacultyId = data_get($event->old_snapshot_json, 'faculty_user_id');
                $newFacultyId = data_get($event->new_snapshot_json, 'faculty_user_id');

                return (int) $oldFacultyId === $facultyId || (int) $newFacultyId === $facultyId;
            })
            ->values();
    }

    private static function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }
}
