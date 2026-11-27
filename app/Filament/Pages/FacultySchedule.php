<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\FacultyActiveTermResolver;
use App\Support\FacultyScheduleFilters;
use App\Support\TimetablePresentation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'My schedule';

    protected static ?string $title = 'My schedule';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected string $view = 'filament.pages.faculty-schedule';

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $scheduleView = 'week';

    /** @var Collection<int, SectionMeeting>|null */
    private ?Collection $cachedAssignedMeetings = null;

    private ?PublishedTimetableVersion $cachedCurrentVersion = null;

    private bool $resolvedCurrentVersion = false;

    /** @var list<array{course_code:string,course_title:string,section_code:string,role:string,units:string,enrolled_count:int,note:string}>|null */
    private ?array $cachedExternallyArrangedClasses = null;

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

        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);

            return;
        }

        $user = auth()->user();
        $this->termId = app(FacultyActiveTermResolver::class)->resolveId($user instanceof User ? $user : null);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->printFilteredScheduleAction(),
            $this->printScheduleAction(),
        ];
    }

    public function printScheduleAction(): Action
    {
        return Action::make('printSchedule')
            ->label(fn (): string => $this->scheduleCriteria() !== [] ? 'Print all' : 'Print schedule')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (): bool => $this->currentVersion() instanceof PublishedTimetableVersion || $this->externallyArrangedClasses() !== [])
            ->url(function (): ?string {
                $version = $this->currentVersion();

                if ($version instanceof PublishedTimetableVersion) {
                    return route('faculty.schedule.print', [
                        'timetable_version' => $version->id,
                        'view' => $this->scheduleView,
                        'return_filters' => $this->scheduleCriteria(),
                    ]);
                }

                if ($this->termId !== null && $this->externallyArrangedClasses() !== []) {
                    return route('faculty.schedule.print', [
                        'term_id' => $this->termId,
                        'view' => $this->scheduleView,
                    ]);
                }

                return null;
            })
            ->openUrlInNewTab();
    }

    public function printFilteredScheduleAction(): Action
    {
        return Action::make('printFilteredSchedule')
            ->label('Print filtered')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (): bool => $this->currentVersion() instanceof PublishedTimetableVersion && $this->scheduleCriteria() !== [])
            ->url(fn (): ?string => ($version = $this->currentVersion()) ? route('faculty.schedule.print', ['timetable_version' => $version->id, 'scope' => 'filtered', 'view' => $this->scheduleView, ...$this->scheduleCriteria()]) : null)
            ->openUrlInNewTab();
    }

    public function setScheduleView(string $view): void
    {
        if (! in_array($view, ['week', 'list'], true)) {
            return;
        }

        $this->scheduleView = $view;
        $this->resetPage($this->getTablePaginationPageName());
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
        return FacultyScheduleFilters::describe($this->scheduleCriteria(), $this->assignedMeetings());
    }

    public function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'tala-data-table'])
            ->query($this->meetingsQuery())
            ->paginated(fn (): bool => $this->scheduleView === 'list')
            ->content(function (): ?View {
                if ($this->scheduleView !== 'week') {
                    return null;
                }

                $version = $this->currentVersion();
                if (! $version instanceof PublishedTimetableVersion) {
                    return null;
                }

                $meetings = $this->scheduleCriteria() === []
                    ? $this->assignedMeetings()
                    : $this->getFilteredTableQuery()->get();
                if ($meetings->isEmpty()) {
                    return null;
                }

                return view('filament.components.faculty-schedule-week', [
                    'presentation' => TimetablePresentation::forPublished($version, $meetings),
                ]);
            })
            ->toolbarActions([])
            ->searchUsing(fn (Builder $query, string $search): Builder => FacultyScheduleFilters::search($query, $search))
            ->searchPlaceholder('Search subject, class, or room')
            ->recordActions([$this->viewMeetingAction()])
            ->columns([
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Subject')
                    ->description(function (SectionMeeting $record): ?string {
                        $code = $record->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                        $componentType = $record->schedulingDemand?->courseComponent?->component_type;
                        $component = $componentType !== null
                            ? (CourseComponent::typeOptions()[$componentType] ?? str((string) $componentType)->headline()->toString())
                            : null;

                        return collect([$code, $component])->filter()->implode(' · ') ?: null;
                    })
                    ->wrap()
                    ->searchable(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Class')
                    ->searchable(),
                TextColumn::make('meeting_time')
                    ->label('Time')
                    ->state(fn (SectionMeeting $record): string => self::timeRange($record))
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->searchable()
                    ->placeholder('TBA'),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SectionMeeting::modalityOptions()[$state] ?? str((string) $state)->headline()->toString()),
            ])
            ->filters([
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
            ->filtersFormWidth(Width::Medium)
            ->filtersFormColumns(['default' => 1, 'sm' => 2])
            ->groups([
                Group::make('day_of_week')
                    ->label('Day')
                    ->getTitleFromRecordUsing(fn (SectionMeeting $record): string => SectionMeeting::dayOptions()[(int) $record->day_of_week] ?? 'Unscheduled'),
            ])
            ->defaultGroup('day_of_week')
            ->groupingSettingsHidden()
            ->emptyStateHeading('No classes match your filters')
            ->emptyStateDescription('Clear your search or filters to see your full schedule.')
            ->emptyStateActions([])
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyState(view('filament.partials.collection-empty-state'));
    }

    public function viewMeetingAction(): Action
    {
        return Action::make('viewMeeting')
            ->label('Details')->color('gray')->icon(Heroicon::OutlinedEye)
            ->authorize(fn (SectionMeeting $record): bool => ($version = $this->currentVersion()) && $this->meetingsQuery()->where('published_timetable_version_id', $version->id)->whereKey($record)->exists())
            ->modalHeading('Class meeting')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
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
        $faculty = auth()->user();
        $termResolver = app(FacultyActiveTermResolver::class);
        $term = $this->termId !== null
            ? Term::query()->with('academicYear')->find($this->termId)
            : $termResolver->resolve($faculty instanceof User ? $faculty : null);

        $assignedMeetings = $this->assignedMeetings();
        $meetings = $this->scheduleCriteria() === []
            ? $assignedMeetings
            : $this->getFilteredTableQuery()->get();
        $version = $this->currentVersion();
        $package = $this->termId === null ? null : TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('windows')
            ->first();
        $examinationPeriod = $package?->windows->first(
            fn (TermCalendarWindow $window): bool => $window->window_type === TermCalendarWindow::TypeExaminationPeriod,
        );

        $availabilityStatus = ($term instanceof Term && $faculty instanceof User)
            ? app(FacultyAvailabilityStatusProjection::class)->forFaculty($term, $faculty)
            : null;
        $affectedMeetings = collect($availabilityStatus['affected_meetings'] ?? [])
            ->map(fn (array $item): array => $this->describeAffectedMeeting($item))
            ->values()
            ->all();
        $hasLateAvailabilityImpact = collect($affectedMeetings)
            ->contains(fn (array $item): bool => $item['source'] === 'late_availability_impact');
        $externallyArrangedClasses = $this->externallyArrangedClasses();
        $scheduledClassCount = $assignedMeetings->pluck('schedulingDemand.sectionDeliveryGroup.section.id')->filter()->unique()->count();
        $totalClassCount = $scheduledClassCount + count($externallyArrangedClasses);

        return [
            'term' => $term,
            'scopeTitle' => $term instanceof Term ? $termResolver->scopeTitle($term) : null,
            'scopeMeta' => $term instanceof Term ? $termResolver->scopeMeta($term, $package instanceof TermCalendarPackage) : null,
            'academicCalendarUrl' => FacultyAcademicCalendar::getUrl(),
            'version' => $version,
            'presentation' => $version ? TimetablePresentation::forPublished($version, $meetings) : null,
            'assignmentCount' => $assignedMeetings->count(),
            'classCount' => $scheduledClassCount,
            'totalClassCount' => $totalClassCount,
            'teachingDayCount' => $assignedMeetings->pluck('day_of_week')->filter()->unique()->count(),
            'meetingsByDay' => $meetings->groupBy('day_of_week'),
            'externallyArrangedClasses' => $externallyArrangedClasses,
            'availabilityStatus' => $availabilityStatus,
            'affectedMeetings' => $affectedMeetings,
            'hasLateAvailabilityImpact' => $hasLateAvailabilityImpact,
            'examinationPeriod' => $examinationPeriod,
        ];
    }

    /**
     * @return Collection<int, SectionMeeting>
     */
    private function assignedMeetings(): Collection
    {
        return $this->cachedAssignedMeetings ??= $this->meetingsQuery()->get();
    }

    /**
     * @return list<array{course_code:string,course_title:string,section_code:string,role:string,units:string,enrolled_count:int,note:string}>
     */
    private function externallyArrangedClasses(): array
    {
        if ($this->cachedExternallyArrangedClasses !== null) {
            return $this->cachedExternallyArrangedClasses;
        }

        $facultyId = auth()->id();

        if ($this->termId === null || $facultyId === null) {
            return $this->cachedExternallyArrangedClasses = [];
        }

        $assignments = ClassOfferingTeachingAssignment::query()
            ->with([
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
            ])
            ->where('faculty_user_id', $facultyId)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->whereHas('termOffering', fn (Builder $query): Builder => $query
                ->where('term_id', $this->termId)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->whereHas('curriculumEntry.courseSpecification', fn (Builder $specQuery): Builder => $specQuery
                    ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged)))
            ->where(function (Builder $query): void {
                $query->whereNull('section_id')
                    ->orWhereHas('section', fn (Builder $sectionQuery): Builder => $sectionQuery->where('state', '!=', Section::StateCancelled));
            })
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return $this->cachedExternallyArrangedClasses = [];
        }

        $sectionIds = $assignments->pluck('section_id')->filter()->unique()->values()->all();
        $offeringIds = $assignments->pluck('term_offering_id')->filter()->unique()->values()->all();

        $enrolledBySection = $sectionIds === []
            ? collect()
            : CourseEnrollment::query()
                ->whereIn('section_id', $sectionIds)
                ->where('status', CourseEnrollment::StatusActive)
                ->where('is_current', true)
                ->selectRaw('section_id, COUNT(*) as total')
                ->groupBy('section_id')
                ->pluck('total', 'section_id');

        $enrolledByOffering = $offeringIds === []
            ? collect()
            : CourseEnrollment::query()
                ->whereIn('term_offering_id', $offeringIds)
                ->where('status', CourseEnrollment::StatusActive)
                ->where('is_current', true)
                ->selectRaw('term_offering_id, COUNT(*) as total')
                ->groupBy('term_offering_id')
                ->pluck('total', 'term_offering_id');

        return $this->cachedExternallyArrangedClasses = $assignments
            ->map(function (ClassOfferingTeachingAssignment $assignment) use ($enrolledBySection, $enrolledByOffering): array {
                $spec = $assignment->termOffering?->curriculumEntry?->courseSpecification;
                $course = $spec?->course;
                $enrolledCount = $assignment->section_id !== null
                    ? (int) ($enrolledBySection->get($assignment->section_id) ?? 0)
                    : (int) ($enrolledByOffering->get($assignment->term_offering_id) ?? 0);

                return [
                    'course_code' => (string) ($course?->code ?? 'Subject'),
                    'course_title' => (string) ($spec?->title ?? $course?->code ?? 'Externally arranged subject'),
                    'section_code' => (string) ($assignment->section?->code ?: 'Term offering'),
                    'role' => $assignment->role === ClassOfferingTeachingAssignment::RoleDesignated ? 'Designated instructor' : 'Co-faculty',
                    'units' => $spec?->credit_units !== null
                        ? rtrim(rtrim(number_format((float) $spec->credit_units, 2, '.', ''), '0'), '.').' units'
                        : '—',
                    'enrolled_count' => $enrolledCount,
                    'note' => 'Externally arranged · No fixed weekly room or time slot',
                ];
            })
            ->unique(fn (array $row): string => $row['course_code'].'|'.$row['section_code'].'|'.$row['role'])
            ->sortBy(['course_code', 'section_code'])
            ->values()
            ->all();
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
        if ($this->resolvedCurrentVersion) {
            return $this->cachedCurrentVersion;
        }

        $this->resolvedCurrentVersion = true;

        if ($this->termId === null) {
            return $this->cachedCurrentVersion = null;
        }

        return $this->cachedCurrentVersion = PublishedTimetableVersion::query()
            ->with(['scheduleRun', 'term.academicYear'])
            ->where('term_id', $this->termId)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->whereHas('meetings', fn (Builder $query): Builder => $query->where('faculty_user_id', auth()->id()))
            ->latest('version')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{source:string,badge_label:string,badge_color:string,course_section:string,schedule_line:string,detail_line:string}
     */
    private function describeAffectedMeeting(array $item): array
    {
        $source = (string) ($item['source'] ?? 'schedule_revision');
        $courseSection = collect([
            $item['course_code'] ?? $item['course_title'] ?? null,
            $item['section_code'] ?? null,
        ])->filter()->implode(' · ') ?: 'Assigned class';

        $dayLabel = (string) ($item['day_label'] ?? 'Unknown day');
        $timeLabel = (string) ($item['time_label'] ?? '–');

        if ($source === 'late_availability_impact') {
            $detail = collect([
                'Outside your recorded availability',
                filled($item['reason'] ?? null) ? (string) $item['reason'] : null,
            ])->filter()->implode(' · ');

            return [
                'source' => $source,
                'badge_label' => 'Under review',
                'badge_color' => 'warning',
                'course_section' => $courseSection,
                'schedule_line' => $dayLabel.' · '.$timeLabel,
                'detail_line' => $detail,
            ];
        }

        $scheduleLine = $dayLabel.' · '.$timeLabel;
        $oldSnapshot = is_array($item['old_snapshot'] ?? null) ? $item['old_snapshot'] : [];
        $oldDay = isset($oldSnapshot['day_of_week']) ? (int) $oldSnapshot['day_of_week'] : null;
        $oldStart = is_string($oldSnapshot['starts_at'] ?? null) ? $oldSnapshot['starts_at'] : null;
        $oldEnd = is_string($oldSnapshot['ends_at'] ?? null) ? $oldSnapshot['ends_at'] : null;

        if ($oldDay !== null && $oldStart !== null && $oldEnd !== null
            && ($oldDay !== (int) ($item['day_of_week'] ?? 0)
                || substr($oldStart, 0, 5) !== substr((string) ($item['starts_at'] ?? ''), 0, 5)
                || substr($oldEnd, 0, 5) !== substr((string) ($item['ends_at'] ?? ''), 0, 5))) {
            $oldDayLabel = SectionMeeting::dayOptions()[$oldDay] ?? 'Unknown day';
            $oldTimeLabel = self::formatDisplayClock($oldStart).'–'.self::formatDisplayClock($oldEnd);
            $scheduleLine = 'Was '.$oldDayLabel.' · '.$oldTimeLabel.' → Now '.$dayLabel.' · '.$timeLabel;
        }

        $effective = $item['effective_date'] ?? null;
        $effectiveText = method_exists($effective, 'format')
            ? 'Effective '.$effective->format('M j, Y')
            : null;
        $detailLine = collect([
            $effectiveText,
            filled($item['reason'] ?? null) ? (string) $item['reason'] : null,
        ])->filter()->implode(' · ');

        return [
            'source' => $source,
            'badge_label' => (string) ($item['change_type_label'] ?? 'Schedule update'),
            'badge_color' => 'info',
            'course_section' => $courseSection,
            'schedule_line' => $scheduleLine,
            'detail_line' => $detailLine !== '' ? $detailLine : 'Updated class schedule.',
        ];
    }

    private static function formatDisplayClock(string $time): string
    {
        return CarbonImmutable::createFromFormat(
            '!H:i',
            substr($time, 0, 5),
            (string) config('app.display_timezone', 'Asia/Manila'),
        )->format('g:i A');
    }

    private static function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode('–');
    }
}
