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
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class FacultySchedule extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Assigned Schedule';

    protected static ?string $title = 'My Assigned Schedule';

    protected string $view = 'filament.pages.faculty-schedule';

    #[Url]
    public ?int $termId = null;

    public function mount(): void
    {
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

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        $version = $this->currentVersion();

        if (! $version instanceof PublishedTimetableVersion) {
            return [];
        }

        return [
            Action::make('printSchedule')
                ->label('Print / Save as PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(route('faculty.schedule.print', ['timetable_version' => $version->id]))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->meetingsQuery())
            ->columns([
                TextColumn::make('schedulingDemand.termOffering.term.label')
                    ->label('Term'),
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course')
                    ->searchable(),
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Description')
                    ->wrap(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
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
                SelectFilter::make('modality')
                    ->options(SectionMeeting::modalityOptions()),
            ])
            ->groups([
                Group::make('day_of_week')
                    ->label('Day')
                    ->getTitleFromRecordUsing(fn (SectionMeeting $record): string => SectionMeeting::dayOptions()[(int) $record->day_of_week] ?? 'Unscheduled'),
            ])
            ->defaultGroup('day_of_week')
            ->groupingSettingsHidden()
            ->emptyStateHeading('No assigned schedule')
            ->emptyStateDescription('Your published class assignments appear here.')
            ->emptyStateIcon(Heroicon::OutlinedCalendar);
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->resetTable();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $meetings = $this->meetingsQuery()->get();
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
            'version' => $this->currentVersion(),
            'meetingsByDay' => $meetings->groupBy('day_of_week'),
            'revisionEvents' => $this->revisionEvents(),
            'examinationPeriod' => $examinationPeriod,
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
