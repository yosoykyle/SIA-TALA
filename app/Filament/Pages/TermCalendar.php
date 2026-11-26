<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TermCalendar extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Term calendar';

    protected static ?string $slug = 'calendar';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'overview';

    protected string $view = 'filament.pages.term-calendar';

    public function teachingWeekRows(TermCalendarPackage $package): array
    {
        return app(TeachingWeekSummary::class)->rows($package);
    }

    public function datedExceptionTypeLabel(?string $type): ?string
    {
        if (! filled($type)) {
            return null;
        }

        return $this->datedExceptionTypeOptions($type)[$type] ?? $type;
    }

    /**
     * @return list<array{short_day: string, day: string, hours: string, breaks: ?string}>
     */
    public function teachingDayChips(TermCalendarPackage $package): array
    {
        $package->loadMissing('teachingGridRows');

        return $package->teachingGridRows
            ->sortBy('day_of_week')
            ->map(function ($row): array {
                $day = SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day';
                $hours = CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A');
                $breaks = collect($row->breaks ?? [])
                    ->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'])->format('g:i A'))
                    ->implode(', ');

                return [
                    'short_day' => substr($day, 0, 3),
                    'day' => $day,
                    'hours' => $hours,
                    'breaks' => $breaks !== '' ? $breaks : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, code: string, date_range: string, time_note: ?string, status: string, status_color: string, url: string}>
     */
    public function linkedAdmissionCycles(Term $term): array
    {
        $stateOrder = [
            AdmissionCycle::StatePublished => 0,
            AdmissionCycle::StateDraft => 1,
            AdmissionCycle::StateCancelled => 2,
        ];

        return AdmissionCycle::query()
            ->where('term_id', $term->id)
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (AdmissionCycle $cycle): int => $stateOrder[$cycle->state] ?? 9)
            ->map(function (AdmissionCycle $cycle): array {
                $status = match (true) {
                    $cycle->state === AdmissionCycle::StateDraft => 'Draft',
                    $cycle->state === AdmissionCycle::StateCancelled => 'Cancelled',
                    $cycle->state !== AdmissionCycle::StatePublished || ! $cycle->opens_at || ! $cycle->closes_at => 'Window unavailable',
                    $cycle->opens_at->isFuture() => 'Scheduled',
                    $cycle->closes_at <= now() => 'Closed',
                    default => 'Open',
                };

                $statusColor = match ($status) {
                    'Open' => 'success',
                    'Scheduled' => 'info',
                    'Cancelled' => 'danger',
                    'Window unavailable' => 'warning',
                    default => 'gray',
                };

                $dateRange = 'Dates not set';
                $timeNote = null;

                if ($cycle->opens_at && $cycle->closes_at) {
                    $opens = $cycle->opens_at->timezone('Asia/Manila');
                    $closes = $cycle->closes_at->timezone('Asia/Manila');
                    $dateRange = $opens->year === $closes->year
                        ? $opens->format('M j').' – '.$closes->format('M j, Y')
                        : $opens->format('M j, Y').' – '.$closes->format('M j, Y');
                    $timeParts = [
                        'Opens '.$opens->format('g:i A'),
                        'Closes '.$closes->format('g:i A'),
                    ];
                    if ($cycle->correction_closes_at) {
                        $correctionCloses = $cycle->correction_closes_at->timezone('Asia/Manila');
                        $timeParts[] = 'Corrections until '.$correctionCloses->format('M j, Y g:i A');
                    }
                    $timeParts[] = 'Asia/Manila';
                    $timeNote = implode(' · ', $timeParts);
                }

                return [
                    'id' => (int) $cycle->id,
                    'label' => filled($cycle->label) ? (string) $cycle->label : (string) $cycle->code,
                    'code' => (string) $cycle->code,
                    'date_range' => $dateRange,
                    'time_note' => $timeNote,
                    'status' => $status,
                    'status_color' => $statusColor,
                    'url' => AdmissionCycleResource::getUrl('view', ['record' => $cycle]),
                ];
            })
            ->values()
            ->all();
    }

    public function admissionsIndexUrl(): string
    {
        return AdmissionCycleResource::getUrl('index');
    }

    /**
     * @return array{
     *     classes_run: string,
     *     classes_run_note: ?string,
     *     teaching_hours: string,
     *     teaching_hours_note: string,
     *     grid_slots: string,
     *     grid_slots_note: string,
     *     has_breaks: bool
     * }
     */
    public function teachingScheduleSummary(?TermCalendarPackage $package, ?Term $term = null): array
    {
        if (! $package instanceof TermCalendarPackage) {
            $start = $term?->starts_on;
            $end = $term?->ends_on;
            $classesRun = ($start && $end)
                ? ($start->year === $end->year
                    ? $start->format('M j').' – '.$end->format('M j, Y')
                    : $start->format('M j, Y').' – '.$end->format('M j, Y'))
                : 'Not set';

            return [
                'classes_run' => $classesRun,
                'classes_run_note' => 'Pre-filled from term dates (not yet activated)',
                'teaching_hours' => 'Mon–Sat · 7:00 AM–7:00 PM',
                'teaching_hours_note' => 'Default 6-day teaching schedule',
                'grid_slots' => '30-minute intervals',
                'grid_slots_note' => 'Asia/Manila',
                'has_breaks' => false,
            ];
        }

        $package->loadMissing('teachingGridRows');
        $start = $package->classes_start_on;
        $end = $package->classes_end_on;
        $classesRun = ($start && $end)
            ? ($start->year === $end->year
                ? $start->format('M j').' – '.$end->format('M j, Y')
                : $start->format('M j, Y').' – '.$end->format('M j, Y'))
            : 'Dates missing';

        $classesRunNote = null;
        if ($package->administrative_starts_on && $package->administrative_ends_on
            && ($package->administrative_starts_on->toDateString() !== $start?->toDateString()
                || $package->administrative_ends_on->toDateString() !== $end?->toDateString())) {
            $adminStart = $package->administrative_starts_on;
            $adminEnd = $package->administrative_ends_on;
            $adminRange = $adminStart->year === $adminEnd->year
                ? $adminStart->format('M j').' – '.$adminEnd->format('M j, Y')
                : $adminStart->format('M j, Y').' – '.$adminEnd->format('M j, Y');
            $classesRunNote = 'Term dates: '.$adminRange;
        }

        $rows = $package->teachingGridRows->sortBy('day_of_week')->values();
        $dayCount = $rows->count();
        $dayOptions = SectionMeeting::dayOptions();
        $hasBreaks = false;

        if ($dayCount === 0) {
            $teachingHours = 'Not configured';
            $teachingHoursNote = 'No approved teaching days';
        } else {
            $shortDays = $rows->map(fn ($row): string => substr($dayOptions[(int) $row->day_of_week] ?? 'Day', 0, 3))->values()->all();
            $daySpan = $rows->pluck('day_of_week')->map(fn (mixed $d): int => (int) $d)->values()->all() === range((int) $rows->first()->day_of_week, (int) $rows->last()->day_of_week) && $dayCount > 2
                ? $shortDays[0].'–'.$shortDays[$dayCount - 1]
                : implode(', ', $shortDays);

            $uniqueRanges = $rows->map(
                fn ($row): string => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A')
            )->unique()->values();

            $teachingHours = $uniqueRanges->count() === 1
                ? $daySpan.' · '.$uniqueRanges->first()
                : $daySpan.' (varies by day)';

            $dayBreakLists = $rows->map(function ($row): array {
                return collect(is_array($row->breaks) ? $row->breaks : [])
                    ->filter(fn (mixed $break): bool => is_array($break) && filled($break['starts_at'] ?? null) && filled($break['ends_at'] ?? null))
                    ->sortBy(fn (array $break): int => $this->clockMinutes($break['starts_at']) ?? 0)
                    ->map(function (array $break): ?string {
                        $startLabel = $this->formatClockLabel($break['starts_at']);
                        $endLabel = $this->formatClockLabel($break['ends_at']);

                        return ($startLabel !== null && $endLabel !== null) ? $startLabel.'–'.$endLabel : null;
                    })
                    ->filter()
                    ->values()
                    ->all();
            });

            $dayBreakSignatures = $dayBreakLists->map(fn (array $breaks): string => implode(', ', $breaks));
            $nonEmptyBreakSignatures = $dayBreakSignatures->filter(fn (string $signature): bool => $signature !== '')->values();
            $hasBreaks = $nonEmptyBreakSignatures->isNotEmpty();

            $dayLabel = $dayCount.' '.str('teaching day')->plural($dayCount).'/week';

            if (! $hasBreaks) {
                $teachingHoursNote = $dayLabel.' · No daily breaks set';
            } elseif ($dayBreakSignatures->unique()->count() === 1) {
                $firstDayBreakCount = count($dayBreakLists->first() ?? []);
                $breakPrefix = $firstDayBreakCount === 1 ? 'Daily break ' : 'Daily breaks ';
                $teachingHoursNote = $dayLabel.' · '.$breakPrefix.$dayBreakSignatures->first();
            } elseif ($nonEmptyBreakSignatures->unique()->count() === 1) {
                $daysWithBreaks = $nonEmptyBreakSignatures->count();
                $teachingHoursNote = $dayLabel.' · Break '.$nonEmptyBreakSignatures->first().' ('.$daysWithBreaks.' of '.$dayCount.' '.str('day')->plural($dayCount).')';
            } else {
                $daysWithBreaks = $nonEmptyBreakSignatures->count();
                $teachingHoursNote = $dayLabel.' · Daily breaks vary by day ('.$daysWithBreaks.' '.str('day')->plural($daysWithBreaks).')';
            }
        }

        return [
            'classes_run' => $classesRun,
            'classes_run_note' => $classesRunNote,
            'teaching_hours' => $teachingHours,
            'teaching_hours_note' => $teachingHoursNote,
            'grid_slots' => '30-minute intervals',
            'grid_slots_note' => 'Asia/Manila',
            'has_breaks' => $hasBreaks,
        ];
    }

    /**
     * @return array{confirmed_classes: int, draft_runs: int, draft_meetings: int}|null
     */
    private function calendarChangeImpactSummary(): ?array
    {
        if ($this->termId === null || ! $this->activeCalendarPackage() instanceof TermCalendarPackage) {
            return null;
        }

        $confirmedClasses = Section::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $this->termId))
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
            ->whereNotNull('confirmed_at')
            ->count();

        $draftRunIds = ScheduleGenerationRun::query()
            ->where('term_id', $this->termId)
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->where(function ($query): void {
                $query->whereNull('candidate_state')
                    ->orWhereNotIn('candidate_state', ['Stale', 'Superseded', 'Rejected']);
            })
            ->pluck('id');

        $draftRuns = $draftRunIds->count();
        $draftMeetings = $draftRuns > 0
            ? CandidateScheduleRow::query()->whereIn('schedule_run_id', $draftRunIds)->count()
            : 0;

        if ($confirmedClasses === 0 && $draftRuns === 0) {
            return null;
        }

        return [
            'confirmed_classes' => $confirmedClasses,
            'draft_runs' => $draftRuns,
            'draft_meetings' => $draftMeetings,
        ];
    }

    public function isCalendarScheduleLocked(?Term $term = null): bool
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);
        if (! $resolved instanceof Term) {
            return true;
        }

        return $this->isTermClosed($resolved)
            || ($this->hasPublishedTimetable($resolved) && $this->hasOfficialStudentEnrollments($resolved));
    }

    public function saveAndActivateCalendarAction(): Action
    {
        return Action::make('saveAndActivateCalendar')
            ->label(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Edit calendar'
                : 'Activate calendar')
            ->icon(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'heroicon-o-pencil-square'
                : 'heroicon-o-check-circle')
            ->color(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage() ? 'gray' : 'primary')
            ->size('sm')
            ->modalHeading(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Edit term calendar'
                : 'Set up term calendar')
            ->modalDescription(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Update teaching dates, weekly teaching hours, or school events. Saving updates the active term calendar immediately.'
                : 'Pre-filled with term dates and default Mon–Sat hours. Review and activate to start planning.')
            ->modalSubmitActionLabel(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Save changes'
                : 'Activate calendar')
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isCalendarScheduleLocked())
            ->fillForm(function (): array {
                $draft = $this->latestDraftCalendarPackage();
                if ($draft instanceof TermCalendarPackage) {
                    return $this->extractPackageFormData($draft) + [
                        'draft_package_id' => $draft->id,
                        'draft_token' => $draft->concurrencyToken(),
                    ];
                }

                $active = $this->activeCalendarPackage();
                if ($active instanceof TermCalendarPackage) {
                    return $this->extractPackageFormData($active) + [
                        'draft_package_id' => null,
                        'draft_token' => null,
                    ];
                }

                return $this->defaultCalendarFormData($this->selectedTerm()) + [
                    'draft_package_id' => null,
                    'draft_token' => null,
                ];
            })
            ->schema([
                Hidden::make('draft_package_id'),
                Hidden::make('draft_token'),
                Placeholder::make('calendar_change_impact')
                    ->hiddenLabel()
                    ->visible(fn (): bool => $this->calendarChangeImpactSummary() !== null)
                    ->content(function (): HtmlString {
                        $impact = $this->calendarChangeImpactSummary();
                        if ($impact === null) {
                            return new HtmlString('');
                        }

                        $parts = [];
                        if ($impact['confirmed_classes'] > 0) {
                            $parts[] = $impact['confirmed_classes'].' confirmed '.str('class')->plural($impact['confirmed_classes']);
                        }
                        if ($impact['draft_runs'] > 0) {
                            $draftPart = $impact['draft_runs'].' draft timetable '.str('attempt')->plural($impact['draft_runs']);
                            if ($impact['draft_meetings'] > 0) {
                                $draftPart .= ' ('.$impact['draft_meetings'].' draft '.str('meeting')->plural($impact['draft_meetings']).')';
                            }
                            $parts[] = $draftPart;
                        }
                        $scopeText = implode(' and ', $parts);
                        $draftPublishNotice = $impact['draft_runs'] > 0
                            ? ' Existing draft timetables cannot be published until regenerated with the updated calendar.'
                            : '';

                        return new HtmlString(
                            '<div class="tala-warning-banner" role="status">'
                            .'<p><strong>Impact warning:</strong> Changing dates, teaching hours, or daily breaks will mark existing draft timetable attempt(s) stale so you can regenerate a conflict-free timetable around the new schedule.'
                            .$draftPublishNotice
                            .' ('.e($scopeText).' currently recorded for this term.)</p>'
                            .'</div>'
                        );
                    })
                    ->columnSpanFull(),
                SchemaSection::make('Teaching dates')
                    ->description('Pre-filled from term dates. Adjust if class dates differ from the administrative term window.')
                    ->schema([
                        DatePicker::make('administrative_starts_on')
                            ->label('Term start')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('start', 'Term')])
                            ->required(),
                        DatePicker::make('administrative_ends_on')
                            ->label('Term end')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('end', 'Term')])
                            ->required()
                            ->afterOrEqual('administrative_starts_on'),
                        DatePicker::make('classes_start_on')
                            ->label('Classes start')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('start', 'Classes')])
                            ->required(),
                        DatePicker::make('classes_end_on')
                            ->label('Classes end')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('end', 'Classes')])
                            ->required()
                            ->afterOrEqual('classes_start_on'),
                        TextInput::make('special_term_schedule_basis')
                            ->label('Special Term class-day and class-hour basis')
                            ->maxLength(255)
                            ->visible(fn (): bool => $this->selectedTerm()?->type === Term::TypeSpecialTerm)
                            ->required(fn (): bool => $this->selectedTerm()?->type === Term::TypeSpecialTerm)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                SchemaSection::make('Teaching hours')
                    ->description('Standard Mon–Sat 7:00 AM–7:00 PM schedule is pre-filled. Scheduling uses 30-minute intervals in Asia/Manila.')
                    ->schema([
                        SchemaActions::make([
                            Action::make('applyLunchBreakToAllDays')
                                ->label('Apply lunch break (12:00 PM – 1:00 PM) to all days')
                                ->icon(Heroicon::OutlinedClock)
                                ->color('gray')
                                ->size('sm')
                                ->action(function (Get $get, Set $set): void {
                                    $rows = $get('teaching_grid_rows');
                                    if (! is_array($rows) || $rows === []) {
                                        return;
                                    }

                                    $lunchStart = 12 * 60;
                                    $lunchEnd = 13 * 60;

                                    foreach ($rows as $rowKey => $row) {
                                        if (! is_array($row)) {
                                            continue;
                                        }

                                        $dayStart = $this->clockMinutes($row['starts_at'] ?? null);
                                        $dayEnd = $this->clockMinutes($row['ends_at'] ?? null);

                                        if ($dayStart === null || $dayEnd === null || $dayStart > $lunchStart || $dayEnd < $lunchEnd) {
                                            continue;
                                        }

                                        $breaks = is_array($row['breaks'] ?? null) ? $row['breaks'] : [];
                                        $alreadyCovered = false;

                                        foreach ($breaks as $break) {
                                            if (! is_array($break)) {
                                                continue;
                                            }

                                            $breakStart = $this->clockMinutes($break['starts_at'] ?? null);
                                            $breakEnd = $this->clockMinutes($break['ends_at'] ?? null);

                                            if ($breakStart !== null && $breakEnd !== null && $breakStart < $lunchEnd && $breakEnd > $lunchStart) {
                                                $alreadyCovered = true;
                                                break;
                                            }
                                        }

                                        if (! $alreadyCovered) {
                                            $breaks[(string) Str::uuid()] = [
                                                'starts_at' => '12:00',
                                                'ends_at' => '13:00',
                                            ];
                                            $rows[$rowKey]['breaks'] = $breaks;
                                        }
                                    }

                                    $set('teaching_grid_rows', $rows);
                                }),
                            Action::make('copyMondayBreaksToAllDays')
                                ->label('Copy Monday breaks to all days')
                                ->icon(Heroicon::OutlinedDocumentDuplicate)
                                ->color('gray')
                                ->size('sm')
                                ->action(function (Get $get, Set $set): void {
                                    $rows = $get('teaching_grid_rows');
                                    if (! is_array($rows) || count($rows) < 2) {
                                        return;
                                    }

                                    $sourceKey = null;
                                    foreach ($rows as $rowKey => $row) {
                                        if (is_array($row) && (int) ($row['day_of_week'] ?? 0) === 1) {
                                            $sourceKey = $rowKey;
                                            break;
                                        }
                                    }

                                    if ($sourceKey === null) {
                                        $sourceKey = array_key_first($rows);
                                    }

                                    $sourceRow = $rows[$sourceKey] ?? null;
                                    if (! is_array($sourceRow)) {
                                        return;
                                    }

                                    $sourceBreaks = collect(is_array($sourceRow['breaks'] ?? null) ? $sourceRow['breaks'] : [])
                                        ->filter(fn (mixed $break): bool => is_array($break) && filled($break['starts_at'] ?? null) && filled($break['ends_at'] ?? null))
                                        ->map(fn (array $break): array => [
                                            'starts_at' => substr((string) $break['starts_at'], 0, 5),
                                            'ends_at' => substr((string) $break['ends_at'], 0, 5),
                                        ])
                                        ->values()
                                        ->all();

                                    foreach ($rows as $rowKey => $row) {
                                        if ($rowKey === $sourceKey || ! is_array($row)) {
                                            continue;
                                        }

                                        $dayStart = $this->clockMinutes($row['starts_at'] ?? null);
                                        $dayEnd = $this->clockMinutes($row['ends_at'] ?? null);
                                        $copied = [];

                                        foreach ($sourceBreaks as $break) {
                                            $breakStart = $this->clockMinutes($break['starts_at']);
                                            $breakEnd = $this->clockMinutes($break['ends_at']);

                                            if ($dayStart !== null && $dayEnd !== null && $breakStart !== null && $breakEnd !== null
                                                && ($breakStart < $dayStart || $breakEnd > $dayEnd)) {
                                                continue;
                                            }

                                            $copied[(string) Str::uuid()] = $break;
                                        }

                                        $rows[$rowKey]['breaks'] = $copied;
                                    }

                                    $set('teaching_grid_rows', $rows);
                                }),
                            Action::make('clearAllBreaks')
                                ->label('Clear all breaks')
                                ->icon(Heroicon::OutlinedXMark)
                                ->color('gray')
                                ->size('sm')
                                ->action(function (Get $get, Set $set): void {
                                    $rows = $get('teaching_grid_rows');
                                    if (! is_array($rows) || $rows === []) {
                                        return;
                                    }

                                    foreach ($rows as $rowKey => $row) {
                                        if (! is_array($row)) {
                                            continue;
                                        }

                                        $rows[$rowKey]['breaks'] = [];
                                    }

                                    $set('teaching_grid_rows', $rows);
                                }),
                        ])
                            ->key('quick_daily_breaks')
                            ->label('Quick daily break (apply to all days)')
                            ->belowContent('Classes will not be scheduled during break times. Apply a common break to all days or customize per day below.')
                            ->columnSpanFull(),
                        Repeater::make('teaching_grid_rows')
                            ->label('Teaching days & hours')
                            ->itemLabel(function (array $state): ?string {
                                $day = SectionMeeting::dayOptions()[(int) ($state['day_of_week'] ?? 0)] ?? null;
                                if ($day === null) {
                                    return null;
                                }

                                $breaks = collect(is_array($state['breaks'] ?? null) ? $state['breaks'] : [])
                                    ->filter(fn (mixed $break): bool => is_array($break) && filled($break['starts_at'] ?? null) && filled($break['ends_at'] ?? null))
                                    ->map(function (array $break): ?string {
                                        $startLabel = $this->formatClockLabel($break['starts_at']);
                                        $endLabel = $this->formatClockLabel($break['ends_at']);

                                        return ($startLabel !== null && $endLabel !== null) ? $startLabel.'–'.$endLabel : null;
                                    })
                                    ->filter()
                                    ->values();

                                return $breaks->isEmpty()
                                    ? $day
                                    : $day.' · Break: '.$breaks->implode(', ');
                            })
                            ->schema([
                                Select::make('day_of_week')->label('Day')->options(SectionMeeting::dayOptions())->required()->distinct(),
                                TimePicker::make('starts_at')->label('Start time')->timezone('UTC')->seconds(false)->required(),
                                TimePicker::make('ends_at')->label('End time')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                Repeater::make('breaks')
                                    ->label('Daily breaks (no classes scheduled)')
                                    ->helperText('Keep each break within this day\'s teaching hours in 30-minute increments.')
                                    ->defaultItems(0)
                                    ->addActionLabel('Add break')
                                    ->schema([
                                        TimePicker::make('starts_at')->label('Start time')->timezone('UTC')->seconds(false)->required(),
                                        TimePicker::make('ends_at')->label('End time')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ])
                            ->minItems(1)
                            ->required()
                            ->reorderable(false)
                            ->columns(3),
                    ]),
                SchemaSection::make('School events & no-class days (optional)')
                    ->description('Holidays, school events, class suspensions, or make-up class days.')
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => blank($get('dated_exceptions')))
                    ->schema([
                        Repeater::make('dated_exceptions')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add school event or no-class day')
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? (string) $state['label']
                                : (filled($state['exception_type'] ?? null) ? ($this->datedExceptionTypeOptions((string) $state['exception_type'])[(string) $state['exception_type']] ?? (string) $state['exception_type']) : null))
                            ->schema([
                                DatePicker::make('starts_on')
                                    ->label('Start date')
                                    ->required(),
                                DatePicker::make('ends_on')
                                    ->label('End date')
                                    ->required()
                                    ->afterOrEqual('starts_on'),
                                Select::make('exception_type')
                                    ->label('Event type')
                                    ->options(fn (?string $state): array => $this->datedExceptionTypeOptions($state))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $blocksTeaching = $this->defaultBlocksTeachingForExceptionType($state);

                                        if ($blocksTeaching !== null) {
                                            $set('blocks_teaching', $blocksTeaching);
                                        }
                                    }),
                                TextInput::make('label')->label('Event name')->required()->maxLength(255),
                                Toggle::make('blocks_teaching')->label('Suspend classes on these dates')->default(true),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                if ($this->isCalendarScheduleLocked($term)) {
                    throw ValidationException::withMessages([
                        'classes_start_on' => $this->isTermClosed($term)
                            ? 'This term is closed and read-only.'
                            : 'Teaching hours and daily breaks are locked because students are enrolled in this term\'s official timetable. Daily breaks can no longer be changed for this term; use Revise official schedule in 4. Timetable only to move individual classes.',
                    ]);
                }

                $rawState = $schema->getRawState();
                $rawRows = is_array($rawState['teaching_grid_rows'] ?? null)
                    ? $rawState['teaching_grid_rows']
                    : (is_array($data['teaching_grid_rows'] ?? null) ? $data['teaching_grid_rows'] : []);
                $this->validateTeachingGridBreaks($rawRows, $schema->getStatePath());

                $boundedAcademicYear = $this->boundedAcademicYearForTerm($term);
                if ($boundedAcademicYear instanceof AcademicYear) {
                    $rangeLabel = $boundedAcademicYear->starts_on->format('M j, Y').' – '.$boundedAcademicYear->ends_on->format('M j, Y');
                    $boundErrors = [];

                    if (filled($data['administrative_starts_on'] ?? null)) {
                        $adminStart = CarbonImmutable::parse((string) $data['administrative_starts_on'])->startOfDay();
                        if (! $adminStart->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['administrative_starts_on'] = "Term start date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if (filled($data['administrative_ends_on'] ?? null)) {
                        $adminEnd = CarbonImmutable::parse((string) $data['administrative_ends_on'])->startOfDay();
                        if (! $adminEnd->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['administrative_ends_on'] = "Term end date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if (filled($data['classes_start_on'] ?? null)) {
                        $classesStart = CarbonImmutable::parse((string) $data['classes_start_on'])->startOfDay();
                        if (! $classesStart->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['classes_start_on'] = "Classes start date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if (filled($data['classes_end_on'] ?? null)) {
                        $classesEnd = CarbonImmutable::parse((string) $data['classes_end_on'])->startOfDay();
                        if (! $classesEnd->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['classes_end_on'] = "Classes end date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if ($boundErrors !== []) {
                        throw ValidationException::withMessages($boundErrors);
                    }
                }

                $staleDraftRunsCount = 0;

                DB::transaction(function () use ($data, $actor, $term, &$staleDraftRunsCount): void {
                    $staleDraftRunsCount = 0;
                    $lockedTerm = Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

                    $activeSourcePackage = TermCalendarPackage::query()
                        ->where('term_id', $lockedTerm->id)
                        ->where('state', TermCalendarPackage::StateActive)
                        ->with('windows')
                        ->latest('version')
                        ->first();

                    $package = null;
                    $reusedDraft = false;
                    $draftId = filter_var($data['draft_package_id'] ?? null, FILTER_VALIDATE_INT);

                    if ($draftId !== false && $draftId > 0) {
                        $package = TermCalendarPackage::query()
                            ->where('term_id', $lockedTerm->id)
                            ->where('state', TermCalendarPackage::StateDraft)
                            ->whereKey($draftId)
                            ->lockForUpdate()
                            ->first();

                        if ($package instanceof TermCalendarPackage) {
                            $submittedToken = (string) ($data['draft_token'] ?? '');
                            if (blank($submittedToken) || ! hash_equals($package->concurrencyToken(), $submittedToken)) {
                                throw ValidationException::withMessages([
                                    'classes_start_on' => 'This calendar draft was updated by another user. Reopen and try again.',
                                ]);
                            }

                            $package->update([
                                ...collect($data)->except(['draft_package_id', 'draft_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                                'recorded_by' => $actor->id,
                            ]);
                            // Key term dates are edited exclusively via Card 2 (Set dates); preserve any saved windows.
                            $package->teachingGridRows()->delete();
                            $package->datedExceptions()->delete();
                            $reusedDraft = true;
                        }
                    }

                    if (! $package instanceof TermCalendarPackage) {
                        $version = ((int) TermCalendarPackage::query()->where('term_id', $lockedTerm->id)->max('version')) + 1;
                        $package = TermCalendarPackage::query()->create([
                            ...collect($data)->except(['draft_package_id', 'draft_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                            'term_id' => $lockedTerm->id,
                            'version' => $version,
                            'state' => TermCalendarPackage::StateDraft,
                            'recorded_by' => $actor->id,
                        ]);
                    }

                    if (! $reusedDraft && $activeSourcePackage instanceof TermCalendarPackage) {
                        foreach ($activeSourcePackage->windows as $window) {
                            $package->windows()->create([
                                'window_type' => $window->window_type,
                                'opens_on' => $window->opens_on?->toDateString(),
                                'closes_on' => $window->closes_on?->toDateString(),
                                'cutoff_at' => $window->cutoff_at,
                            ]);
                        }
                    }

                    foreach ($data['teaching_grid_rows'] ?? [] as $row) {
                        $package->teachingGridRows()->create($row);
                    }

                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([
                            ...$exception,
                            'authority_reference' => filled($exception['authority_reference'] ?? null)
                                ? trim((string) $exception['authority_reference'])
                                : null,
                            'blocks_teaching' => (bool) ($exception['blocks_teaching'] ?? true),
                        ]);
                    }

                    $freshPackage = $package->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
                    $activator = app(ActivateTermCalendarPackage::class);
                    $activator->execute($freshPackage, $actor, $freshPackage->concurrencyToken());
                    $staleDraftRunsCount = $activator->lastStaleDraftRunCount();
                }, attempts: 3);

                if ($this->hasPublishedTimetable($term)) {
                    Notification::make()
                        ->title('Calendar updated · Generate new draft to apply')
                        ->body("Teaching hours or breaks were updated for {$term->label}. Generate and publish a new draft in 4. Timetable for these changes to take effect on the live schedule.")
                        ->warning()
                        ->persistent()
                        ->actions([
                            Action::make('openTimetable')
                                ->label('Open 4. Timetable')
                                ->url(TermTimetable::getUrl(['termId' => $term->id]))
                                ->button(),
                        ])
                        ->send();
                } elseif ($staleDraftRunsCount > 0) {
                    Notification::make()
                        ->title('Calendar updated · Draft timetable marked stale')
                        ->body("Teaching hours or breaks changed for {$term->label}. Existing draft timetable(s) were marked stale so no classes fall during your updated breaks. Regenerate in 4. Timetable.")
                        ->warning()
                        ->persistent()
                        ->actions([
                            Action::make('openTimetable')
                                ->label('Open 4. Timetable')
                                ->url(TermTimetable::getUrl(['termId' => $term->id]))
                                ->button(),
                        ])
                        ->send();
                } else {
                    Notification::make()
                        ->title('Term calendar saved and activated')
                        ->body("The term calendar is now active for {$term->label}.")
                        ->success()
                        ->send();
                }
            });
    }

    public function discardDraftCalendarAction(): Action
    {
        return Action::make('discardDraftCalendar')
            ->label('Discard draft')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Discard draft calendar?')
            ->modalDescription('Removes this unactivated draft calendar so the active calendar remains the only current teaching schedule. This cannot be undone.')
            ->modalSubmitActionLabel('Discard draft')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isCalendarScheduleLocked()
                && $this->latestDraftCalendarPackage() instanceof TermCalendarPackage)
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                if ($this->isCalendarScheduleLocked($term)) {
                    throw ValidationException::withMessages([
                        'draft' => 'Draft calendars cannot be discarded once students are enrolled in the official timetable or the term is closed.',
                    ]);
                }

                $removed = 0;

                DB::transaction(function () use ($term, &$removed): void {
                    $drafts = TermCalendarPackage::query()
                        ->where('term_id', $term->id)
                        ->where('state', TermCalendarPackage::StateDraft)
                        ->lockForUpdate()
                        ->get();

                    foreach ($drafts as $draft) {
                        $hasSections = Section::query()
                            ->where('term_calendar_package_id', $draft->id)
                            ->exists();

                        if ($hasSections) {
                            $draft->update([
                                'state' => TermCalendarPackage::StateClosed,
                                'closed_at' => now(),
                            ]);
                        } else {
                            $draft->windows()->delete();
                            $draft->teachingGridRows()->delete();
                            $draft->datedExceptions()->delete();
                            $draft->delete();
                        }

                        $removed++;
                    }
                }, attempts: 3);

                if ($removed > 0) {
                    Notification::make()
                        ->title('Draft calendar discarded')
                        ->body('The unactivated draft calendar was removed.')
                        ->success()
                        ->send();
                }
            });
    }

    /** @return array<string, mixed> */
    public function defaultCalendarFormData(Term $term): array
    {
        return [
            'authority_reference' => null,
            'authority_date' => null,
            'administrative_starts_on' => $term->starts_on?->toDateString(),
            'administrative_ends_on' => $term->ends_on?->toDateString(),
            'classes_start_on' => $term->starts_on?->toDateString(),
            'classes_end_on' => $term->ends_on?->toDateString(),
            'special_term_schedule_basis' => null,
            'windows' => [],
            'teaching_grid_rows' => collect([1, 2, 3, 4, 5, 6])->map(fn (int $day): array => [
                'day_of_week' => $day,
                'starts_at' => '07:00',
                'ends_at' => '19:00',
                'breaks' => [],
            ])->all(),
            'dated_exceptions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function extractPackageFormData(TermCalendarPackage $package): array
    {
        $package->loadMissing(['windows', 'teachingGridRows', 'datedExceptions']);

        return [
            ...$package->only(['authority_reference', 'special_term_schedule_basis']),
            'administrative_starts_on' => $package->administrative_starts_on?->toDateString(),
            'administrative_ends_on' => $package->administrative_ends_on?->toDateString(),
            'classes_start_on' => $package->classes_start_on?->toDateString(),
            'classes_end_on' => $package->classes_end_on?->toDateString(),
            'authority_date' => $package->authority_date?->toDateString(),
            'windows' => $package->windows->map(fn (TermCalendarWindow $window): array => [
                'window_type' => $window->window_type,
                'opens_on' => $window->opens_on?->toDateString(),
                'closes_on' => $window->closes_on?->toDateString(),
                'cutoff_at' => $window->cutoff_at,
            ])->all(),
            'teaching_grid_rows' => $package->teachingGridRows->sortBy('day_of_week')->map(fn ($row): array => [
                'day_of_week' => $row->day_of_week,
                'starts_at' => substr((string) $row->starts_at, 0, 5),
                'ends_at' => substr((string) $row->ends_at, 0, 5),
                'breaks' => collect($row->breaks ?? [])->map(fn ($break): array => [
                    'starts_at' => isset($break['starts_at']) ? substr((string) $break['starts_at'], 0, 5) : null,
                    'ends_at' => isset($break['ends_at']) ? substr((string) $break['ends_at'], 0, 5) : null,
                ])->all(),
            ])->values()->all(),
            'dated_exceptions' => $package->datedExceptions->map(fn ($exception): array => [
                ...$exception->only(['exception_type', 'label', 'authority_reference', 'blocks_teaching']),
                'starts_on' => $exception->starts_on?->toDateString(),
                'ends_on' => $exception->ends_on?->toDateString(),
            ])->all(),
        ];
    }

    private function latestDraftCalendarPackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        $activeVersion = TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->max('version');

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateDraft)
            ->when($activeVersion !== null, fn ($query) => $query->where('version', '>', (int) $activeVersion))
            ->latest('version')
            ->first();
    }

    private function boundedAcademicYearForSelectedTerm(): ?AcademicYear
    {
        if ($this->termId === null) {
            return null;
        }

        return $this->boundedAcademicYearForTerm($this->selectedTerm());
    }

    private function boundedAcademicYearForTerm(Term $term): ?AcademicYear
    {
        $term->loadMissing('academicYear');
        $academicYear = $term->academicYear;

        if (! $academicYear instanceof AcademicYear
            || $academicYear->starts_on === null
            || $academicYear->ends_on === null
            || $term->starts_on === null
            || $term->ends_on === null) {
            return null;
        }

        if (! $term->starts_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)
            || ! $term->ends_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)) {
            return null;
        }

        return $academicYear;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     */
    private function validateTeachingGridBreaks(array $rows, ?string $statePath): void
    {
        $fieldErrors = [];
        $summaryErrors = [];
        $dayOptions = SectionMeeting::dayOptions();

        foreach ($rows as $rowKey => $row) {
            if (! is_array($row)) {
                continue;
            }

            $dayStart = $this->clockMinutes($row['starts_at'] ?? null);
            $dayEnd = $this->clockMinutes($row['ends_at'] ?? null);
            $dayName = $dayOptions[(int) ($row['day_of_week'] ?? 0)] ?? 'this teaching day';
            $dayRange = ($this->formatClockLabel($row['starts_at'] ?? null) ?? 'start')
                .'–'
                .($this->formatClockLabel($row['ends_at'] ?? null) ?? 'end');
            $breaks = is_array($row['breaks'] ?? null) ? $row['breaks'] : [];
            $seenIntervals = [];

            foreach ($breaks as $breakKey => $break) {
                $prefix = filled($statePath)
                    ? "{$statePath}.teaching_grid_rows.{$rowKey}.breaks.{$breakKey}"
                    : "teaching_grid_rows.{$rowKey}.breaks.{$breakKey}";

                if (! is_array($break)) {
                    $message = "A break on {$dayName} is incomplete.";
                    $fieldErrors["{$prefix}.starts_at"] = $message;
                    $summaryErrors[] = $message;

                    continue;
                }

                $breakStart = $this->clockMinutes($break['starts_at'] ?? null);
                $breakEnd = $this->clockMinutes($break['ends_at'] ?? null);

                if ($breakStart === null || $breakEnd === null || $breakEnd <= $breakStart) {
                    $message = "Break end time on {$dayName} must be after its start time.";
                    $fieldErrors["{$prefix}.ends_at"] = $message;
                    $summaryErrors[] = $message;

                    continue;
                }

                if (($breakStart % 30) !== 0) {
                    $message = "Break start time on {$dayName} must be on a 30-minute interval.";
                    $fieldErrors["{$prefix}.starts_at"] = $message;
                    $summaryErrors[] = $message;
                }

                if (($breakEnd % 30) !== 0) {
                    $message = "Break end time on {$dayName} must be on a 30-minute interval.";
                    $fieldErrors["{$prefix}.ends_at"] = $message;
                    $summaryErrors[] = $message;
                }

                if ($dayStart !== null && $breakStart < $dayStart) {
                    $message = "Break start time must fall within {$dayName} teaching hours ({$dayRange}).";
                    $fieldErrors["{$prefix}.starts_at"] = $message;
                    $summaryErrors[] = $message;
                }

                if ($dayEnd !== null && $breakEnd > $dayEnd) {
                    $message = "Break end time must fall within {$dayName} teaching hours ({$dayRange}).";
                    $fieldErrors["{$prefix}.ends_at"] = $message;
                    $summaryErrors[] = $message;
                }

                foreach ($seenIntervals as $existing) {
                    if ($breakStart < $existing['end'] && $breakEnd > $existing['start']) {
                        $message = "Daily breaks on {$dayName} cannot overlap each other.";
                        $fieldErrors["{$prefix}.starts_at"] = $message;
                        $summaryErrors[] = $message;
                        break;
                    }
                }

                $seenIntervals[] = ['start' => $breakStart, 'end' => $breakEnd];
            }
        }

        if ($fieldErrors !== []) {
            $uniqueSummary = array_values(array_unique($summaryErrors));
            $fieldErrors['package_id'] = $uniqueSummary;
            $fieldErrors['readiness'] = $uniqueSummary;

            throw ValidationException::withMessages($fieldErrors);
        }
    }

    private function clockMinutes(mixed $value): ?int
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 || $seconds !== 0) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function formatClockLabel(mixed $value): ?string
    {
        $minutes = $this->clockMinutes($value);
        if ($minutes === null) {
            return null;
        }

        return CarbonImmutable::createFromTime(intdiv($minutes, 60), $minutes % 60)->format('g:i A');
    }

    private function calendarAcademicYearBoundRule(string $boundary, string $subject = 'Classes'): Closure
    {
        return function () use ($boundary, $subject): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($boundary, $subject): void {
                if (blank($value)) {
                    return;
                }

                $academicYear = $this->boundedAcademicYearForSelectedTerm();

                if (! $academicYear instanceof AcademicYear) {
                    return;
                }

                $date = CarbonImmutable::parse((string) $value)->startOfDay();
                $startsOn = CarbonImmutable::parse($academicYear->starts_on)->startOfDay();
                $endsOn = CarbonImmutable::parse($academicYear->ends_on)->startOfDay();

                if ($date->betweenIncluded($startsOn, $endsOn)) {
                    return;
                }

                $label = $boundary === 'start' ? 'start' : 'end';
                $rangeLabel = $academicYear->starts_on->format('M j, Y').' – '.$academicYear->ends_on->format('M j, Y');
                $fail("{$subject} {$label} date must fall within {$academicYear->displayLabel()} ({$rangeLabel}).");
            };
        };
    }

    public function table(Table $table): Table
    {
        return $table->records(function (): array {
            $package = TermCalendarPackage::query()
                ->where('term_id', $this->termId)
                ->whereIn('state', [TermCalendarPackage::StateDraft, TermCalendarPackage::StateActive])
                ->latest('version')
                ->with('teachingGridRows')
                ->first();

            return $package?->teachingGridRows->sortBy('day_of_week')->mapWithKeys(fn ($row): array => [$row->id => [
                'day' => SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day',
                'hours' => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A'),
                'breaks' => collect($row->breaks ?? [])->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'])->format('g:i A'))->implode(', ') ?: 'None recorded',
            ]])->all() ?? [];
        })->columns([TextColumn::make('day'), TextColumn::make('hours')->label('Teaching hours'), TextColumn::make('breaks')->wrap()])
            ->paginated(false)
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading('No approved teaching days recorded')
            ->emptyStateDescription(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar sets the term calendar before classes can be planned.' : 'Set the term calendar before adding classes.');
    }
}
