<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\PlanningTableRecords;
use App\Support\TimetablePresentation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

class TermTimetable extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    private const SearchableMeetingFields = ['courseTitle', 'courseCode', 'class', 'dayLabel', 'time', 'faculty', 'room', 'component', 'status'];

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Timetable';

    protected static ?string $title = 'Timetable';

    protected static ?string $slug = 'timetable';

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'generate';

    protected string $view = 'filament.pages.term-timetable';

    /** @var array<string, array<string, mixed>> */
    private array $meetingDetailsViewData = [];

    #[Url]
    public string $timetableState = 'draft';

    #[Url]
    public string $timetableView = 'whole';

    #[Url]
    public string $timetableFormat = 'week';

    #[Url]
    public ?string $viewFilter = null;

    #[Url]
    public ?string $programFilter = null;

    #[Url]
    public ?string $yearFilter = null;

    /** @return array<string, array<string, mixed>> */
    protected function queryString(): array
    {
        return ['tableSearch' => ['except' => '']];
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(in_array($this->timetableState, ['draft', 'official'], true), 404);
        abort_unless(in_array($this->timetableView, ['whole', 'section', 'faculty', 'room', 'list'], true), 404);
        abort_unless(in_array($this->timetableFormat, ['week', 'list'], true), 404);
        if ($this->timetableView === 'list') {
            $this->timetableView = 'whole';
            $this->timetableFormat = 'list';
        }
        if ($this->selectedVersionId !== null) {
            $this->timetableState = 'official';
        } elseif ($this->selectedRunId !== null) {
            $this->timetableState = 'draft';
        }
        $this->autoSyncTermSchedulingDemands();
    }

    private function autoSyncTermSchedulingDemands(): void
    {
        $actor = auth()->user();
        if ($this->termId === null || ! $actor instanceof User || ! $actor->can('create', SchedulingDemand::class)) {
            return;
        }

        $term = $this->selectedTerm();
        if (! $term instanceof Term || $term->state !== Term::StateActive) {
            return;
        }

        $hasConfirmedClassesWithOfferings = Section::query()
            ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))
            ->whereNotNull('confirmed_at')
            ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id)->where('state', TermOffering::StatePendingScheduling))
            ->whereHas('deliveryGroups')
            ->exists();

        if (! $hasConfirmedClassesWithOfferings) {
            return;
        }

        $demands = SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id));
        if (! (clone $demands)->exists()
            || (clone $demands)->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)->exists()) {
            app(GenerateSchedulingDemand::class)->forTerm($actor, $term);
        }
    }

    public function clearViewFilters(): void
    {
        $this->programFilter = null;
        $this->yearFilter = null;
        $this->viewFilter = null;
        $this->resetTableSearch();
        $this->resetTable();
    }

    public function expandTimetableAction(): Action
    {
        return Action::make('expandTimetable')
            ->label('Expand timetable')
            ->color('gray')
            ->modalHeading('Whole-Term timetable')
            ->modalWidth(Width::Screen)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): View {
                $data = $this->getViewData();

                return view('filament.pages.partials.term-expanded-timetable', [
                    'presentation' => $data['timetablePresentation'],
                    'groups' => $data['masterGroups'],
                ]);
            });
    }

    public function chooseTimetable(string $selection): void
    {
        [$state, $id] = array_pad(explode(':', $selection, 2), 2, null);
        if ($state === 'official') {
            $this->selectPublishedVersion((int) $id);
            $this->timetableState = 'official';
        } elseif ($state === 'draft') {
            $this->selectRun((int) $id);
            $this->timetableState = 'draft';
        }
        $this->viewFilter = null;
        $this->programFilter = null;
        $this->yearFilter = null;
        $this->resetTableSearch();
        $this->resetTable();
    }

    public function showTimetableView(string $view): void
    {
        abort_unless(in_array($view, ['whole', 'section', 'faculty', 'room', 'list'], true), 404);
        if ($view === 'list') {
            $this->showTimetableFormat('list');

            return;
        }
        $this->timetableView = $view;
        $this->viewFilter = null;
        $this->resetTable();
    }

    public function showTimetableFormat(string $format): void
    {
        abort_unless(in_array($format, ['week', 'list'], true), 404);
        $this->timetableFormat = $format;
        $this->resetTable();
    }

    public function updatedProgramFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedViewFilter(): void
    {
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    private function timetableSourceData(): array
    {
        $term = Term::query()->with('academicYear')->find($this->termId);

        if (! $term) {
            return [...parent::getViewData(), 'showOfficial' => true];
        }

        $versions = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)->latest('version')->get();
        $currentVersion = $versions->firstWhere('state', PublishedTimetableVersion::StatePublished);
        $selectedVersion = $versions->firstWhere('id', $this->selectedVersionId);

        if ($this->selectedVersionId !== null && ! $selectedVersion) {
            $this->selectedVersionId = null;
        }

        $selectedVersion ??= $currentVersion ?? $versions->first();
        $activeRun = $this->activeScheduleRun();
        $official = $this->timetableState === 'official' || ! $activeRun
            || ($activeRun->status === ScheduleGenerationRun::StatusPublished && $selectedVersion);
        $relations = [
            'faculty', 'room',
            'schedulingDemand.courseComponent.courseSpecification.course',
            'schedulingDemand.sectionDeliveryGroup.section.cohorts.program',
            'schedulingDemand.sectionDeliveryGroup.section.cohorts.curriculumVersion.entries.courseSpecification',
            'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',
            'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
        ];
        $presentation = null;

        if ($official && $selectedVersion) {
            $selectedVersion->loadMissing('scheduleRun');
            $meetings = $selectedVersion->meetings()->with([
                ...$relations,
                'classOffering.courseRevision.course',
                'classOffering.cohorts.program',
                'classOffering.cohorts.curriculumVersion.entries.courseSpecification',
            ])->orderBy('day_of_week')->orderBy('starts_at')->get();
            $presentation = TimetablePresentation::forPublished($selectedVersion, $meetings);
        } elseif (! $official && $activeRun) {
            $meetings = $activeRun->candidateRows()->with($relations)
                ->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')->get();
            $presentation = TimetablePresentation::forCandidate($activeRun, $meetings);
        }

        $diagnostics = is_array($activeRun?->diagnostics) ? $activeRun->diagnostics : [];

        return [
            'term' => $term,
            'activeRun' => $activeRun,
            'allRuns' => ScheduleGenerationRun::query()->where('term_id', $term->id)
                ->latest('id')->get(['id', 'term_id', 'status', 'created_at']),
            'currentVersion' => $currentVersion,
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'showOfficial' => $official,
            'candidatePresentation' => $official ? null : $presentation,
            'publishedPresentation' => $official ? $presentation : null,
            'readOnly' => (bool) auth()->user()?->hasRole(User::StaffRoleAcademicHead),
            'solverFailure' => $official ? [] : ($activeRun?->finalSolverFailure() ?? []),
            'solverOutcome' => $official ? [] : (array) ($diagnostics['solver_outcome'] ?? $diagnostics['solver_result'] ?? []),
        ];
    }

    protected function getViewData(): array
    {
        if (count($this->mountedActions ?? []) !== 1 || ($this->mountedActions[0]['name'] ?? null) !== 'viewMeeting') {
            return $this->buildTimetableViewData();
        }

        $scope = serialize([
            $this->termId, $this->selectedRunId, $this->selectedVersionId,
            $this->timetableState, $this->timetableView, $this->viewFilter,
            $this->programFilter, $this->yearFilter, $this->getTableSearch(),
        ]);

        return $this->meetingDetailsViewData[$scope] ??= $this->buildTimetableViewData();
    }

    /** @return array<string, mixed> */
    private function buildTimetableViewData(): array
    {
        $data = $this->timetableSourceData();
        $official = $data['showOfficial'];
        $presentation = $official ? $data['publishedPresentation'] : $data['candidatePresentation'];
        $allMeetings = $presentation['meetings'] ?? [];

        if ($this->termId !== null && $allMeetings !== []) {
            $commitments = SchedulingCommitment::query()
                ->where('term_id', $this->termId)
                ->get(['section_id', 'day_of_week', 'starts_at']);

            if ($commitments->isNotEmpty()) {
                foreach ($allMeetings as &$meeting) {
                    if ($meeting['isPinned'] ?? false) {
                        continue;
                    }

                    $sectionId = $meeting['sectionId'] ?? null;
                    $startsAt = $meeting['startsAt'] ?? null;
                    $day = $meeting['day'] ?? null;

                    $matched = $commitments->contains(function (SchedulingCommitment $commitment) use ($sectionId, $day, $startsAt): bool {
                        if ($commitment->section_id !== null && (int) $commitment->section_id !== (int) $sectionId) {
                            return false;
                        }
                        if ($commitment->day_of_week !== null && (int) $commitment->day_of_week !== (int) $day) {
                            return false;
                        }

                        return $commitment->starts_at === null || substr((string) $commitment->starts_at, 0, 5) === (string) $startsAt;
                    });

                    if ($matched) {
                        $meeting['isPinned'] = true;
                    }
                }
                unset($meeting);
            }
        }

        $courseColors = collect($allMeetings)->pluck('courseCode')->unique()->sort()->values()
            ->mapWithKeys(fn (string $code, int $index): array => [$code => ($index % 4) + 1])->all();
        $groups = [];
        foreach ($allMeetings as $meeting) {
            foreach ($meeting['sections'] ?? [] as $section) {
                $key = $section['id'];
                $groups[$key] ??= [...$section, 'meetings' => []];
                $groups[$key]['meetings'][] = $meeting;
                $groups[$key]['years'] = array_merge($groups[$key]['years'] ?? [], $section['years'] ?? []);
            }
        }
        $allSectionGroups = collect($groups)
            ->sortBy(fn (array $group): string => $group['program'].' '.$group['label'])
            ->map(fn (array $group): array => [
                'id' => (string) $group['id'],
                'label' => (string) $group['label'],
                'program' => (string) $group['program'],
                'years' => array_values(array_unique($group['years'] ?? [])),
            ])
            ->values()
            ->all();
        $years = collect($groups)->flatMap(fn (array $group): array => $group['years'])->unique()->sort()->values()->all();
        $programs = collect($groups)->pluck('program')->unique()->sort()->values()->all();
        $groups = collect($groups)->sortBy(fn (array $group): string => $group['program'].' '.$group['label'])
            ->filter(fn (array $group): bool => (! $this->programFilter || $group['program'] === $this->programFilter) && (! $this->yearFilter || in_array($this->yearFilter, $group['years'], true)))->values()->all();
        $options = match ($this->timetableView) {
            'section' => collect($groups)->mapWithKeys(fn (array $group): array => [$group['id'] => $group['label']])->all(),
            'faculty', 'room' => collect($allMeetings)->pluck($this->timetableView)->unique()->sort()->mapWithKeys(fn (string $value): array => [$value => $value])->all(),
            default => [],
        };
        $filtered = collect($allMeetings)->filter(function (array $meeting): bool {
            if (($search = $this->getTableSearch()) !== null && ! collect(self::SearchableMeetingFields)->contains(fn (string $field): bool => str_contains(mb_strtolower((string) ($meeting[$field] ?? '')), mb_strtolower($search)))) {
                return false;
            }
            if (($this->programFilter || $this->yearFilter) && ! collect($meeting['sections'])->contains(fn (array $section): bool => (! $this->programFilter || $section['program'] === $this->programFilter)
                && (! $this->yearFilter || in_array($this->yearFilter, $section['years'] ?? [], true)))) {
                return false;
            }
            if (! $this->viewFilter) {
                return true;
            }

            return $this->timetableView === 'section'
                ? collect($meeting['sections'])->contains('id', $this->viewFilter)
                : ($meeting[$this->timetableView] ?? null) === $this->viewFilter;
        })->values()->all();
        $visibleIds = array_column($filtered, 'id');
        $groups = collect($groups)->map(function (array $group) use ($visibleIds): array {
            $group['meetings'] = array_values(array_filter($group['meetings'], fn (array $meeting): bool => in_array($meeting['id'], $visibleIds, true)));

            return $group;
        })->filter(fn (array $group): bool => $group['meetings'] !== [])->values()->all();
        if ($presentation) {
            $presentation['meetings'] = $filtered;
            $presentation['allMeetings'] = $allMeetings;
            $presentation['courseColors'] = $courseColors;
        }

        $interactiveContext = $this->buildInteractiveContext(
            $data,
            $presentation,
            $allMeetings,
            $allSectionGroups,
            $programs,
            $years,
        );

        return [...$data, 'showOfficial' => $official, 'timetablePresentation' => $presentation,
            'masterGroups' => $groups, 'programOptions' => $programs, 'yearOptions' => $years, 'viewOptions' => $options,
            'scopeMeetingCount' => count($allMeetings), 'generationReadiness' => $this->generationReadiness(),
            'interactiveContext' => $interactiveContext];
    }

    public function canEditActiveCandidate(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && $this->timetableState !== 'official'
            && $run->status === ScheduleGenerationRun::StatusUnderReview
            && ! in_array($run->candidate_state, ['Rejected', 'Stale', 'Superseded'], true)
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
    }

    public function moveCandidateMeeting(int $rowId, int $dayOfWeek, string $startsAt): void
    {
        $this->executeCandidateUpdate(
            rowId: $rowId,
            dayOfWeek: $dayOfWeek,
            startsAt: $startsAt,
            roomId: null,
            facultyUserId: null,
            preserveRoomAndFaculty: true,
            successTitle: 'Meeting slot updated',
            successBody: 'The draft timetable meeting was moved and validated with zero conflicts.',
            failureTitle: 'Cannot move meeting to this slot',
        );
    }

    public function updateCandidateMeeting(
        int $rowId,
        int $dayOfWeek,
        string $startsAt,
        ?int $roomId = null,
        ?int $facultyUserId = null,
    ): void {
        $this->executeCandidateUpdate(
            rowId: $rowId,
            dayOfWeek: $dayOfWeek,
            startsAt: $startsAt,
            roomId: $roomId,
            facultyUserId: $facultyUserId,
            preserveRoomAndFaculty: false,
            successTitle: 'Meeting schedule updated',
            successBody: 'The draft meeting assignment was updated and validated.',
            failureTitle: 'Cannot update meeting schedule',
        );
    }

    public function togglePinMeeting(int $rowId): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        if ($this->timetableState === 'official' || ! $this->canEditActiveCandidate()) {
            Notification::make()
                ->title('Official timetable is locked')
                ->body('Published timetables are locked. Use Revise official schedule for authorized mid-semester changes.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'timetable' => 'Official timetable is locked. Use Revise official schedule for authorized mid-semester changes.',
            ]);
        }

        $run = $this->activeScheduleRun();
        abort_unless($run instanceof ScheduleGenerationRun, 404);

        $row = $run->candidateRows()
            ->with(['schedulingDemand.sectionDeliveryGroup.section'])
            ->findOrFail($rowId);

        $sectionId = $row->schedulingDemand?->sectionDeliveryGroup?->section_id
            ?? data_get($row->schedulingDemand?->source_snapshot, 'section_id');
        $sectionId = is_numeric($sectionId) && (int) $sectionId > 0 ? (int) $sectionId : null;

        $existingCommitmentQuery = SchedulingCommitment::query()
            ->where('term_id', $run->term_id)
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->where('day_of_week', (int) $row->day_of_week)
            ->where('starts_at', (string) $row->starts_at);

        $isCurrentlyPinned = (bool) data_get($row->scores, 'pinned', false)
            || $row->change_type === 'Pinned'
            || (clone $existingCommitmentQuery)->exists();

        $scores = is_array($row->scores) ? $row->scores : [];

        if ($isCurrentlyPinned) {
            unset($scores['pinned']);
            $row->update([
                'scores' => $scores,
                'change_type' => $row->change_type === 'Pinned' ? 'Unchanged' : $row->change_type,
            ]);
            (clone $existingCommitmentQuery)->delete();

            Notification::make()
                ->title('Meeting unpinned')
                ->body('This meeting slot is now unlocked for future adjustments.')
                ->info()
                ->send();

            return;
        }

        $scores['pinned'] = true;
        $row->update([
            'scores' => $scores,
        ]);

        SchedulingCommitment::query()->updateOrCreate(
            [
                'term_id' => (int) $run->term_id,
                'section_id' => $sectionId,
                'day_of_week' => (int) $row->day_of_week,
                'starts_at' => (string) $row->starts_at,
            ],
            [
                'faculty_user_id' => (int) $row->faculty_user_id,
                'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
                'ends_at' => (string) $row->ends_at,
                'authority_reference' => 'REGISTRAR-PIN',
                'reason' => 'Pinned by Registrar during timetable review.',
                'recorded_by' => (int) $actor->id,
            ],
        );

        Notification::make()
            ->title('Meeting pinned')
            ->body('This meeting slot is now pinned in the timetable.')
            ->success()
            ->send();
    }

    private function executeCandidateUpdate(
        int $rowId,
        int $dayOfWeek,
        string $startsAt,
        ?int $roomId,
        ?int $facultyUserId,
        bool $preserveRoomAndFaculty,
        string $successTitle,
        string $successBody,
        string $failureTitle,
    ): void {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        if ($this->timetableState === 'official' || ! $this->canEditActiveCandidate()) {
            Notification::make()
                ->title('Official timetable is locked')
                ->body('Published timetables are locked. Use Revise official schedule for authorized mid-semester changes.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'timetable' => 'Official timetable is locked. Use Revise official schedule for authorized mid-semester changes.',
            ]);
        }

        $run = $this->activeScheduleRun();
        abort_unless($run instanceof ScheduleGenerationRun, 404);

        $row = $run->candidateRows()
            ->with(['schedulingDemand.courseComponent', 'schedulingDemand.sectionDeliveryGroup.section'])
            ->findOrFail($rowId);

        $normalizedStart = substr(trim($startsAt), 0, 5);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $normalizedStart, $parts) !== 1) {
            Notification::make()
                ->title($failureTitle)
                ->body('Select a valid start time.')
                ->danger()
                ->send();

            throw ValidationException::withMessages(['starts_at' => 'Select a valid start time.']);
        }

        $startMinutes = ((int) $parts[1] * 60) + (int) $parts[2];
        $rowStartMinutes = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
        $rowEndMinutes = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);
        $durationMinutes = ($rowStartMinutes !== null && $rowEndMinutes !== null && $rowEndMinutes > $rowStartMinutes)
            ? ($rowEndMinutes - $rowStartMinutes)
            : max(30, (int) ($row->schedulingDemand?->required_duration_minutes ?? 60));

        $endMinutes = $startMinutes + $durationMinutes;
        $startsAtFormatted = sprintf('%02d:%02d:00', intdiv($startMinutes, 60), $startMinutes % 60);
        $endsAtFormatted = sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60);

        $effectiveFacultyId = $preserveRoomAndFaculty || $facultyUserId === null || $facultyUserId <= 0
            ? (int) $row->faculty_user_id
            : $facultyUserId;
        $effectiveRoomId = $preserveRoomAndFaculty
            ? ($row->room_id !== null ? (int) $row->room_id : null)
            : ($roomId !== null && $roomId > 0 ? $roomId : ($row->room_id !== null ? (int) $row->room_id : null));

        $sectionId = $row->schedulingDemand?->sectionDeliveryGroup?->section_id
            ?? data_get($row->schedulingDemand?->source_snapshot, 'section_id');
        $sectionId = is_numeric($sectionId) && (int) $sectionId > 0 ? (int) $sectionId : null;
        $oldDay = (int) $row->day_of_week;
        $oldStart = (string) $row->starts_at;

        $pinCommitments = SchedulingCommitment::query()
            ->where('term_id', $run->term_id)
            ->where('authority_reference', 'REGISTRAR-PIN')
            ->get()
            ->map(fn (SchedulingCommitment $c): array => $c->only([
                'term_id', 'section_id', 'faculty_user_id', 'room_id', 'day_of_week',
                'starts_at', 'ends_at', 'authority_reference', 'reason', 'recorded_by',
            ]))
            ->all();

        if ($pinCommitments !== []) {
            SchedulingCommitment::query()
                ->where('term_id', $run->term_id)
                ->where('authority_reference', 'REGISTRAR-PIN')
                ->delete();
        }

        $succeeded = false;
        try {
            $successor = app(AdjustCandidateMeeting::class)->execute(
                requestedRow: $row,
                actor: $actor,
                assignment: [
                    'faculty_user_id' => $effectiveFacultyId,
                    'room_id' => $effectiveRoomId,
                    'day_of_week' => $dayOfWeek,
                    'starts_at' => $startsAtFormatted,
                    'ends_at' => $endsAtFormatted,
                ],
                reason: 'Registrar interactive draft adjustment',
                authority: 'Registrar draft adjustment',
            );
            $succeeded = true;

            $this->selectedRunId = $successor->id;
            $this->timetableState = 'draft';

            Notification::make()
                ->title($successTitle)
                ->body($successBody)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            Notification::make()
                ->title($failureTitle)
                ->body(is_string($message) ? $message : 'The selected assignment violates a hard scheduling rule.')
                ->danger()
                ->send();

            throw $exception;
        } finally {
            foreach ($pinCommitments as $commitmentPayload) {
                if ($succeeded
                    && (int) ($commitmentPayload['section_id'] ?? 0) === (int) $sectionId
                    && (int) ($commitmentPayload['day_of_week'] ?? 0) === $oldDay
                    && (string) ($commitmentPayload['starts_at'] ?? '') === $oldStart) {
                    $commitmentPayload['day_of_week'] = $dayOfWeek;
                    $commitmentPayload['starts_at'] = $startsAtFormatted;
                    $commitmentPayload['ends_at'] = $endsAtFormatted;
                    $commitmentPayload['faculty_user_id'] = $effectiveFacultyId;
                    $commitmentPayload['room_id'] = $effectiveRoomId;
                }
                SchedulingCommitment::query()->create($commitmentPayload);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $presentation
     * @param  list<array<string, mixed>>  $allMeetings
     * @param  list<array<string, mixed>>  $allSectionGroups
     * @param  list<string>  $programs
     * @param  list<string>  $years
     * @return array<string, mixed>
     */
    private function buildInteractiveContext(
        array $data,
        ?array $presentation,
        array $allMeetings,
        array $allSectionGroups,
        array $programs,
        array $years,
    ): array {
        $official = (bool) ($data['showOfficial'] ?? false);
        $readOnly = (bool) ($data['readOnly'] ?? false);
        $activeRun = $data['activeRun'] ?? null;
        $selectedVersion = $data['selectedVersion'] ?? null;
        $term = $data['term'] ?? null;

        $editable = ! $official
            && ! $readOnly
            && $activeRun instanceof ScheduleGenerationRun
            && $activeRun->status === ScheduleGenerationRun::StatusUnderReview
            && ! in_array($activeRun->candidate_state, ['Rejected', 'Stale', 'Superseded'], true);

        $reviseOfficialUrl = null;
        if ($official
            && ! $readOnly
            && $selectedVersion instanceof PublishedTimetableVersion
            && $selectedVersion->state === PublishedTimetableVersion::StatePublished
            && $selectedVersion->scheduleRun?->isPublished()
            && Gate::allows('revise', SectionMeeting::class)) {
            $reviseOfficialUrl = ScheduleGenerationRunResource::getUrl('view', [
                'record' => $selectedVersion->schedule_run_id,
                'termId' => $selectedVersion->term_id,
                'timetableContext' => [
                    'timetableView' => $this->timetableView,
                    'timetableFormat' => $this->timetableFormat,
                    'viewFilter' => $this->viewFilter,
                    'programFilter' => $this->programFilter,
                    'yearFilter' => $this->yearFilter,
                    'tableSearch' => $this->getTableSearch(),
                    'selectedVersionId' => $selectedVersion->id,
                ],
                'action' => 'revisePublishedSchedule',
            ]);
        }

        $snapshot = $activeRun instanceof ScheduleGenerationRun && is_array($activeRun->input_snapshot)
            ? $activeRun->input_snapshot
            : ($selectedVersion?->scheduleRun && is_array($selectedVersion->scheduleRun->input_snapshot)
                ? $selectedVersion->scheduleRun->input_snapshot
                : []);
        $termSnapshot = is_array($snapshot['term'] ?? null) ? $snapshot['term'] : [];
        $gridRows = is_array($termSnapshot['teaching_grid_rows'] ?? null) ? $termSnapshot['teaching_grid_rows'] : [];

        if ($gridRows === [] && $term instanceof Term) {
            $activePackage = TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with('teachingGridRows')
                ->first();
            if ($activePackage) {
                $gridRows = $activePackage->teachingGridRows
                    ->map(fn ($row): array => [
                        'day_of_week' => (int) $row->day_of_week,
                        'starts_at' => (string) $row->starts_at,
                        'ends_at' => (string) $row->ends_at,
                    ])
                    ->all();
            }
        }

        $teachingGrid = [];
        foreach ($gridRows as $row) {
            $day = (int) ($row['day_of_week'] ?? 0);
            $start = FacultyAvailabilityDeclaration::clockToMinutes((string) ($row['starts_at'] ?? ''));
            $end = FacultyAvailabilityDeclaration::clockToMinutes((string) ($row['ends_at'] ?? ''));
            if ($day >= 1 && $day <= 7 && $start !== null && $end !== null && $end > $start) {
                $teachingGrid[$day][] = [
                    'start' => $start,
                    'end' => $end,
                    'startsAt' => substr((string) $row['starts_at'], 0, 5),
                    'endsAt' => substr((string) $row['ends_at'], 0, 5),
                ];
            }
        }

        if ($teachingGrid === [] && $presentation) {
            $fallbackStart = (int) ($presentation['axisStart'] ?? 420);
            $fallbackEnd = (int) ($presentation['axisEnd'] ?? 1140);
            foreach ($presentation['days'] ?? [] as $dayItem) {
                $teachingGrid[(int) $dayItem['number']] = [[
                    'start' => $fallbackStart,
                    'end' => $fallbackEnd,
                    'startsAt' => sprintf('%02d:%02d', intdiv($fallbackStart, 60), $fallbackStart % 60),
                    'endsAt' => sprintf('%02d:%02d', intdiv($fallbackEnd, 60), $fallbackEnd % 60),
                ]];
            }
        }

        $dayOptions = collect($presentation['days'] ?? [])
            ->map(fn (array $day): array => ['value' => (int) $day['number'], 'label' => (string) $day['label']])
            ->values()
            ->all();

        $timeSlotOptions = collect($presentation['gridSlots'] ?? [])
            ->map(fn (array $slot): array => [
                'value' => (string) $slot['startsAt'],
                'label' => (string) $slot['label'],
                'minutes' => (int) $slot['start'],
            ])
            ->values()
            ->all();

        $rooms = [];
        $qualifiedTeachersByCourse = [];
        $teacherAvailability = [];

        if ($term instanceof Term) {
            $rooms = Room::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'room_type', 'capacity'])
                ->map(fn (Room $room): array => [
                    'id' => (int) $room->id,
                    'code' => (string) $room->code,
                    'name' => (string) $room->name,
                    'roomType' => (string) $room->room_type,
                    'roomTypeLabel' => Room::typeOptions()[$room->room_type] ?? (string) $room->room_type,
                    'capacity' => (int) $room->capacity,
                ])
                ->values()
                ->all();

            $courseIds = collect($allMeetings)->pluck('courseId')->filter()->unique()->values()->all();
            $qualifications = FacultyQualification::query()
                ->active()
                ->when($courseIds !== [], fn ($q) => $q->whereIn('course_id', $courseIds))
                ->with(['faculty:id,name', 'course:id,code'])
                ->get();

            foreach ($qualifications as $qualification) {
                $code = (string) ($qualification->course?->code ?? '');
                $faculty = $qualification->faculty;
                if ($code !== '' && $faculty instanceof User) {
                    $qualifiedTeachersByCourse[$code][$faculty->id] = [
                        'id' => (int) $faculty->id,
                        'name' => (string) $faculty->name,
                    ];
                }
            }

            foreach ($allMeetings as $meeting) {
                $code = (string) ($meeting['courseCode'] ?? '');
                $fId = $meeting['facultyId'] ?? null;
                $fName = (string) ($meeting['faculty'] ?? '');
                if ($code !== '' && $fId !== null && $fName !== '' && $fName !== 'Faculty unassigned') {
                    $qualifiedTeachersByCourse[$code][(int) $fId] ??= [
                        'id' => (int) $fId,
                        'name' => $fName,
                    ];
                }
            }

            $qualifiedTeachersByCourse = collect($qualifiedTeachersByCourse)
                ->map(fn (array $teachers): array => collect($teachers)->sortBy('name')->values()->all())
                ->all();

            $declarations = FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->orderBy('faculty_user_id')
                ->orderByDesc('version')
                ->get()
                ->unique('faculty_user_id');

            foreach ($declarations as $declaration) {
                $mode = $declaration->effectiveMode();
                $windows = collect($declaration->normalizedTeachingWindows())
                    ->map(fn (array $w): array => [
                        'day' => (int) $w['day_of_week'],
                        'start' => (int) (FacultyAvailabilityDeclaration::clockToMinutes($w['start_time']) ?? 0),
                        'end' => (int) (FacultyAvailabilityDeclaration::clockToMinutes($w['end_time']) ?? 0),
                    ])
                    ->values()
                    ->all();
                $unavailability = collect(is_array($declaration->hard_unavailability) ? $declaration->hard_unavailability : [])
                    ->filter(fn (mixed $u): bool => is_array($u))
                    ->map(fn (array $u): array => [
                        'day' => (int) ($u['day_of_week'] ?? 0),
                        'start' => (int) (FacultyAvailabilityDeclaration::clockToMinutes((string) ($u['starts_at'] ?? $u['start_time'] ?? '')) ?? 0),
                        'end' => (int) (FacultyAvailabilityDeclaration::clockToMinutes((string) ($u['ends_at'] ?? $u['end_time'] ?? '')) ?? 0),
                    ])
                    ->filter(fn (array $u): bool => $u['day'] >= 1 && $u['end'] > $u['start'])
                    ->values()
                    ->all();

                $teacherAvailability[(int) $declaration->faculty_user_id] = [
                    'mode' => $mode,
                    'windows' => $windows,
                    'unavailability' => $unavailability,
                ];
            }
        }

        return [
            'editable' => $editable,
            'isOfficial' => $official,
            'readOnly' => $readOnly,
            'statusPillLabel' => $official
                ? 'Official timetable · Locked'
                : ($editable ? 'Draft candidate · Editable' : null),
            'statusPillTone' => $official ? 'official' : ($editable ? 'draft' : 'neutral'),
            'reviseOfficialUrl' => $reviseOfficialUrl,
            'teachingGrid' => $teachingGrid,
            'dayOptions' => $dayOptions,
            'timeSlotOptions' => $timeSlotOptions,
            'rooms' => $rooms,
            'qualifiedTeachersByCourse' => $qualifiedTeachersByCourse,
            'teacherAvailability' => $teacherAvailability,
            'sectionOptions' => $allSectionGroups,
            'facultyOptions' => collect($allMeetings)->pluck('faculty')->filter(fn (string $v): bool => $v !== '' && $v !== 'Faculty unassigned')->unique()->sort()->values()->all(),
            'roomOptions' => collect($allMeetings)->pluck('room')->filter(fn (string $v): bool => $v !== '' && $v !== 'Room unassigned')->unique()->sort()->values()->all(),
            'programOptions' => $programs,
            'yearOptions' => $years,
        ];
    }

    public function timetableReviewUrl(int $runId): string
    {
        return ScheduleGenerationRunResource::getUrl('view', [
            'record' => $runId, 'termId' => $this->termId,
            'timetableContext' => $this->reviewReturnContext(),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function reviewReturnContext(): array
    {
        $data = $this->getViewData();

        return [
            'timetableView' => $this->timetableView, 'timetableFormat' => $this->timetableFormat,
            'viewFilter' => $this->viewFilter, 'programFilter' => $this->programFilter,
            'yearFilter' => $this->yearFilter, 'tableSearch' => $this->getTableSearch(),
            'selectedVersionId' => $data['showOfficial'] ? $data['selectedVersion']?->id : null,
        ];
    }

    public function table(Table $table): Table
    {
        return $table->stackedOnMobile()->records(fn (?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => PlanningTableRecords::paginate(collect($this->getViewData()['timetablePresentation']['meetings'] ?? [])->keyBy('id')->all(), self::SearchableMeetingFields, null, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('day')->label('Day & time')->formatStateUsing(fn (array $record): string => $record['dayLabel'])->description(fn (array $record): string => $record['time'])->icon('heroicon-o-clock')->weight(FontWeight::Medium)->width('11rem')->extraAttributes(['class' => 'tala-planning-meeting-when'])->sortable(),
                TextColumn::make('courseTitle')->label('Course & student sections')->description(fn (array $record): string => collect($record['sections'] ?? [])->pluck('label')->filter()->unique()->implode(' · ') ?: $record['class'])->weight(FontWeight::SemiBold)->width('38%')->extraAttributes(['class' => 'tala-planning-meeting-course'])->sortable()->wrap(),
                TextColumn::make('faculty')->label('Teacher & room')->description(fn (array $record): string => $record['room'])->width('26%')->extraAttributes(['class' => 'tala-planning-meeting-teacher'])->sortable()->wrap(),
            ])->recordActions([Action::make('viewMeeting')->modal()->label('Meeting details')->icon('heroicon-o-eye')->button()->color('gray')->modalHeading('Class meeting')->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Close')->extraModalFooterActions(fn (array $record): array => $this->meetingRevisionActions($record))->modalContent(fn (array $record): View => view('filament.components.faculty-meeting-details', ['meeting' => $record, 'showFaculty' => true]))])->defaultSort('day')->emptyState(view('filament.partials.collection-empty-state'))->emptyStateHeading('No meetings in this view')->emptyStateDescription('Choose a timetable version or clear the view filters.');
    }

    /** @return array<string, mixed>|null */
    protected function resolveTableRecord(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $meeting = collect($this->getViewData()['timetablePresentation']['meetings'] ?? [])->first(
            fn (array $meeting): bool => (string) $meeting['id'] === $key,
        );

        return $meeting === null ? null : [...$meeting, '__key' => $key];
    }

    /** @param array<string, mixed> $record
     * @return list<Action>
     */
    private function meetingRevisionActions(array $record): array
    {
        $data = $this->getViewData();
        if (! $data['showOfficial']) {
            return $this->canReviewActiveRun() ? [
                Action::make('reviewCandidateAssignments')->label('Review or change assignments')->icon('heroicon-o-pencil-square')
                    ->url($this->timetableReviewUrl($data['activeRun']->id).'#timetable-run-meetings'),
            ] : [];
        }

        if (! Gate::allows('revise', SectionMeeting::class)) {
            return [];
        }

        $version = $data['selectedVersion'] ?? null;
        if (! $data['showOfficial'] || ! $version instanceof PublishedTimetableVersion
            || $version->state !== PublishedTimetableVersion::StatePublished
            || ! $version->scheduleRun?->isPublished()) {
            return [];
        }

        $published = $version->meetings()->find($record['id']);
        if ($published === null) {
            return [];
        }
        $meeting = SectionMeeting::query()->where('schedule_run_id', $version->schedule_run_id)
            ->where('scheduling_demand_id', $published->scheduling_demand_id)
            ->where('meeting_sequence', $published->meeting_sequence)
            ->where('state', SectionMeeting::StateActive)->first();
        if ($meeting === null) {
            return [];
        }

        $target = ['record' => $version->schedule_run_id, 'termId' => $version->term_id, 'timetableContext' => $this->reviewReturnContext(),
            'actionArguments' => ['meeting' => $meeting->id]];

        return [
            Action::make('changeThisMeeting')->label('Change this meeting')->icon('heroicon-o-pencil-square')
                ->url(ScheduleGenerationRunResource::getUrl('view', [...$target, 'action' => 'revisePublishedSchedule'])),
            Action::make('cancelThisClass')->label('Cancel this class')->icon('heroicon-o-x-circle')->color('danger')
                ->url(ScheduleGenerationRunResource::getUrl('view', [...$target, 'action' => 'cancelPublishedClass'])),
        ];
    }
}
