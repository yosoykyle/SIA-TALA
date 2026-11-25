<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\RevertCandidateRun;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Actions\Scheduling\TimetableFailureLanguage;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\AdmissionCycle;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\User;
use App\Support\PlanningTableRecords;
use App\Support\TimetablePresentation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

class TermTimetable extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    private const SearchableMeetingFields = ['courseTitle', 'courseCode', 'class', 'dayLabel', 'time', 'faculty', 'room', 'component', 'status'];

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

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
    public ?int $meetingId = null;

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

    public ?int $watchingPendingRunId = null;

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
        } elseif ($this->termId !== null && ! request()->query->has('timetableState')) {
            $hasPublishedVersion = PublishedTimetableVersion::query()
                ->where('term_id', $this->termId)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->exists();
            $hasActiveDraft = ScheduleGenerationRun::query()
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
                ->exists();

            if ($hasPublishedVersion && ! $hasActiveDraft) {
                $this->timetableState = 'official';
            }
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

    public function generateTimetableAction(): Action
    {
        return parent::generateTimetableAction()
            ->modalContent(fn (): HtmlString => $this->renderGenerationScopeContent())
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed()
                && ! ($this->hasPublishedTimetable() && $this->hasOfficialStudentEnrollments())
                && $this->retainedCandidateNextStep() === null
                && ($this->timetableState === 'official' || ! $this->canRecoverActiveRun()))
            ->hidden(fn (): bool => $this->isTermClosed());
    }

    private function renderGenerationScopeContent(): HtmlString
    {
        $coverage = $this->termId !== null
            ? app(TermCoverageProjection::class)->forTerm(Term::query()->findOrFail($this->termId))
            : null;

        if (! is_array($coverage)) {
            return new HtmlString('');
        }

        $summary = $coverage['summary'];
        $classLabel = $summary['class_count'] === 1 ? 'class' : 'classes';
        $sectionLabel = $summary['section_count'] === 1 ? 'student section' : 'student sections';
        $meetingLabel = $summary['meeting_count'] === 1 ? 'meeting' : 'meetings';
        $allRows = array_merge($coverage['rows'], $coverage['additional']);
        $programCount = collect($allRows)
            ->pluck('program_name')
            ->filter()
            ->unique()
            ->count();

        $rows = collect($allRows)
            ->map(function (array $row): string {
                $programCode = trim((string) ($row['program_code'] ?? ''));
                $programName = trim((string) ($row['program_name'] ?? ''));
                $programCell = $programCode !== '' && strcasecmp($programCode, $programName) !== 0
                    ? '<span class="font-semibold text-gray-900 dark:text-white">'.e($programCode).'</span>'
                        .'<span class="block truncate text-xs font-normal text-gray-500 dark:text-gray-400" title="'.e($programName).'">'.e($programName).'</span>'
                    : '<span class="block truncate font-medium text-gray-900 dark:text-white" title="'.e($programName).'">'.e($programName).'</span>';

                $rawYear = (string) ($row['year_level'] ?? '');
                $yearLabel = $rawYear !== '' && $rawYear !== 'Year not associated'
                    ? e($rawYear)
                    : '<span class="text-gray-500 dark:text-gray-400">Shared / unassigned</span>';
                $classCount = (int) ($row['class_count'] ?? 0);
                $meetingCount = (int) ($row['meeting_count'] ?? 0);

                $rawStatus = (string) ($row['status'] ?? '');
                $isPrepared = $rawStatus === 'Prepared';
                $statusLabel = match ($rawStatus) {
                    'Prepared' => 'Prepared',
                    'Needs association', 'Association unresolved' => 'Advisory',
                    default => $rawStatus !== '' ? $rawStatus : 'Advisory',
                };
                $pillClass = $isPrepared
                    ? 'tala-scope-pill is-prepared'
                    : 'tala-scope-pill is-advisory';

                $emptyNote = $classCount === 0
                    ? '<span class="ml-1.5 text-xs text-gray-500 dark:text-gray-400">No classes</span>'
                    : '';

                return '<tr class="border-t border-gray-200 dark:border-white/10">'
                    .'<th scope="row" class="max-w-[18rem] px-3 py-2 text-left">'.$programCell.'</th>'
                    .'<td class="whitespace-nowrap px-3 py-2 text-center tabular-nums text-gray-700 dark:text-gray-300">'.$yearLabel.'</td>'
                    .'<td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-medium text-gray-900 dark:text-white">'.$classCount.'</td>'
                    .'<td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-medium text-gray-900 dark:text-white">'.$meetingCount.'</td>'
                    .'<td class="whitespace-nowrap px-3 py-2 text-left"><span class="'.$pillClass.'">'.e($statusLabel).'</span>'.$emptyNote.'</td>'
                    .'</tr>';
            })
            ->implode('');

        $programSummary = $programCount > 0
            ? sprintf(' across %d %s', $programCount, Str::plural('program', $programCount))
            : '';

        return new HtmlString(<<<HTML
            <div class="tala-generation-content space-y-3 text-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="font-semibold text-gray-900 dark:text-white">Whole-term generation scope</p>
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300 tabular-nums">{$summary['class_count']} {$classLabel} · {$summary['section_count']} {$sectionLabel} · {$summary['meeting_count']} weekly {$meetingLabel}{$programSummary}</p>
                </div>
                <div class="tala-data-table-scroll tala-record-table tala-generation-scope-scroll rounded-lg border border-gray-200 dark:border-white/10" tabindex="0" role="region" aria-label="Whole-term generation scope. Scroll to view all programs and columns.">
                    <table class="tala-data-table tala-planning-table tala-generation-scope w-full border-collapse text-sm">
                        <caption class="sr-only">Whole-term generation scope</caption>
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-zinc-900 dark:text-gray-300">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left">Program</th>
                                <th scope="col" class="px-3 py-2 text-center">Year</th>
                                <th scope="col" class="px-3 py-2 text-right">Classes</th>
                                <th scope="col" class="px-3 py-2 text-right">Meetings</th>
                                <th scope="col" class="px-3 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            {$rows}
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Shared classes count once. Display filters do not narrow this scope. Rows without classes remain advisory.</p>
            </div>
            HTML);
    }

    public function acceptCandidateAction(): Action
    {
        return parent::acceptCandidateAction()
            ->hidden(fn (): bool => $this->isTermClosed());
    }

    public function rejectCandidateAction(): Action
    {
        return parent::rejectCandidateAction()
            ->hidden(fn (): bool => $this->isTermClosed());
    }

    public function retrySolverRunAction(): Action
    {
        return parent::retrySolverRunAction()
            ->hidden(fn (): bool => $this->isTermClosed());
    }

    public function publishOfficialTimetableAction(): Action
    {
        return parent::publishOfficialTimetableAction()
            ->label('Publish official')
            ->hidden(fn (): bool => $this->isTermClosed() || ($this->hasPublishedTimetable() && $this->hasOfficialStudentEnrollments()));
    }

    public function cancelActiveRunAction(): Action
    {
        return Action::make('cancelActiveRun')
            ->label('Cancel generation')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalIconColor('danger')
            ->modalHeading('Cancel timetable generation?')
            ->modalDescription('Stops waiting for this attempt and marks it cancelled so you can edit sources or start a fresh run. Any existing official timetable remains unaffected.')
            ->modalSubmitActionLabel('Cancel generation')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->visible(function (): bool {
                $actor = auth()->user();
                $run = $this->activeScheduleRun();

                return $actor instanceof User
                    && $actor->hasRole(User::StaffRoleRegistrar)
                    && ! $this->isTermClosed()
                    && $run instanceof ScheduleGenerationRun
                    && in_array($run->status, [
                        ScheduleGenerationRun::StatusQueued,
                        ScheduleGenerationRun::StatusDispatching,
                    ], true);
            })
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar) && ! $this->isTermClosed(), 403);
                $run = $this->activeScheduleRun();

                if (! $run instanceof ScheduleGenerationRun
                    || ! in_array($run->status, [
                        ScheduleGenerationRun::StatusQueued,
                        ScheduleGenerationRun::StatusDispatching,
                    ], true)) {
                    return;
                }

                $diagnostics = is_array($run->diagnostics) ? $run->diagnostics : [];
                $dispatch = is_array($diagnostics['solver_dispatch'] ?? null) ? $diagnostics['solver_dispatch'] : [];
                $diagnostics['solver_dispatch'] = [
                    ...$dispatch,
                    'status' => 'cancelled',
                    'latest_outcome' => 'cancelled',
                    'cancelled_at' => now()->toIso8601String(),
                    'cancelled_by' => $actor->id,
                    'failure' => [
                        'code' => 'CANCELLED_BY_REGISTRAR',
                        'message' => 'Timetable generation was cancelled by '.$actor->name.'.',
                        'retryable' => true,
                        'final' => true,
                        'failed_at' => now()->toIso8601String(),
                    ],
                ];

                $run->forceFill([
                    'status' => ScheduleGenerationRun::StatusFailed,
                    'candidate_state' => null,
                    'candidate_review_reason' => 'Cancelled while in progress by '.$actor->name.'.',
                    'diagnostics' => $diagnostics,
                ])->save();

                activity()
                    ->performedOn($run)
                    ->causedBy($actor)
                    ->event('schedule_generation_cancelled')
                    ->withProperties(['term_id' => $run->term_id])
                    ->log('Cancelled in-progress timetable generation');

                $this->selectedRunId = null;

                Notification::make()
                    ->title('Timetable generation cancelled')
                    ->body('You can adjust sources or start a new timetable run anytime.')
                    ->success()
                    ->send();
            });
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
        $this->dispatch('close-modal', id: 'timetable-review-details');
    }

    public function openGenerateFromDetails(): void
    {
        $this->dispatch('close-modal', id: 'timetable-review-details');

        $readiness = $this->generationReadiness();
        if (! ($readiness['ready'] ?? false)) {
            Notification::make()
                ->title('Cannot start a new draft yet')
                ->body((string) ($readiness['reason'] ?? 'Complete the remaining term prerequisites before generating a new draft.'))
                ->warning()
                ->send();

            return;
        }

        $this->mountAction('generateTimetable');
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
        $pendingDraftRevision = $official && $selectedVersion instanceof PublishedTimetableVersion
            ? TimetableRevision::query()
                ->where('source_version_id', $selectedVersion->id)
                ->where('state', TimetableRevision::StateDraft)
                ->latest('id')
                ->first()
            : null;

        $allRuns = ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->latest('id')
            ->get(['id', 'term_id', 'status', 'candidate_state', 'diagnostics', 'input_snapshot', 'created_at']);

        $nonPublishedRuns = $allRuns
            ->where('status', '!=', ScheduleGenerationRun::StatusPublished)
            ->filter(fn (ScheduleGenerationRun $run): bool => ($activeRun?->id === $run->id)
                || ! RevertCandidateRun::isIntermediateAdjustmentSnapshot($run))
            ->values();

        $activeDraftOptions = [];
        $pastAttemptOptions = [];

        foreach ($nonPublishedRuns as $run) {
            $editCount = RevertCandidateRun::manualEditCount($run);
            $statusLabel = $this->plainAttemptStatusLabel($run);
            $isActiveDraft = in_array($run->status, [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ], true) && ! in_array($run->candidate_state, ['Stale', 'Superseded', 'Rejected'], true);

            if ($isActiveDraft) {
                $label = in_array($run->status, [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                ], true)
                    ? sprintf('Generating · Attempt #%d', $run->id)
                    : ($editCount > 0
                        ? sprintf('Current draft · Attempt #%d (%d %s)', $run->id, $editCount, Str::plural('edit', $editCount))
                        : sprintf('Current draft · Attempt #%d', $run->id));

                $activeDraftOptions[] = [
                    'id' => (int) $run->id,
                    'label' => $label,
                ];
            } else {
                $pastAttemptOptions[] = [
                    'id' => (int) $run->id,
                    'label' => sprintf('Attempt #%d · %s', $run->id, $statusLabel),
                ];
            }
        }

        // Keep the switcher crisp: show at most the 3 most recent past attempts (plus the currently viewed one if older).
        $visiblePastAttempts = array_slice($pastAttemptOptions, 0, 3);
        if (! $official && $activeRun instanceof ScheduleGenerationRun) {
            $activeInVisible = collect($activeDraftOptions)->contains('id', (int) $activeRun->id)
                || collect($visiblePastAttempts)->contains('id', (int) $activeRun->id);
            if (! $activeInVisible) {
                $matched = collect($pastAttemptOptions)->firstWhere('id', (int) $activeRun->id);
                if ($matched !== null) {
                    $visiblePastAttempts[] = $matched;
                }
            }
        }

        $attemptSelectorOptions = [...$activeDraftOptions, ...$visiblePastAttempts];

        return [
            'term' => $term,
            'activeRun' => $activeRun,
            'allRuns' => $allRuns,
            'attemptSelectorOptions' => $attemptSelectorOptions,
            'activeDraftOptions' => $activeDraftOptions,
            'pastAttemptOptions' => $visiblePastAttempts,
            'canStartNewDraftAttempt' => $this->canStartNewDraftAttempt($term, $activeRun, $activeDraftOptions !== []),
            'hasOfficialEnrollmentsBlockingRegen' => $this->hasOfficialStudentEnrollments($term),
            'activeManualEditCount' => $activeRun instanceof ScheduleGenerationRun
                ? RevertCandidateRun::manualEditCount($activeRun)
                : 0,
            'currentVersion' => $currentVersion,
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'pendingDraftRevision' => $pendingDraftRevision,
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
        $activeRunForWatch = $data['activeRun'] ?? null;
        $isPendingNow = $activeRunForWatch instanceof ScheduleGenerationRun
            && in_array($activeRunForWatch->status, [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
            ], true);

        if ($isPendingNow) {
            $this->watchingPendingRunId = (int) $activeRunForWatch->id;
        } elseif ($this->watchingPendingRunId !== null) {
            $watchedStillPending = ScheduleGenerationRun::query()
                ->whereKey($this->watchingPendingRunId)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                ])
                ->exists();

            if (! $watchedStillPending) {
                $this->watchingPendingRunId = null;
                $this->dispatch('databaseNotificationsSent');
            }
        }

        $official = $data['showOfficial'];
        $presentation = $official ? $data['publishedPresentation'] : $data['candidatePresentation'];
        $allMeetings = $presentation['meetings'] ?? [];
        $term = $data['term'] ?? null;

        if ($allMeetings !== []) {
            $activeRunForSnapshot = $data['activeRun'] ?? null;
            $selectedVersionForSnapshot = $data['selectedVersion'] ?? null;
            $snapshot = $activeRunForSnapshot instanceof ScheduleGenerationRun && is_array($activeRunForSnapshot->input_snapshot)
                ? $activeRunForSnapshot->input_snapshot
                : ($selectedVersionForSnapshot instanceof PublishedTimetableVersion
                    && $selectedVersionForSnapshot->scheduleRun
                    && is_array($selectedVersionForSnapshot->scheduleRun->input_snapshot)
                    ? $selectedVersionForSnapshot->scheduleRun->input_snapshot
                    : []);

            $capturedCapacities = collect($snapshot['scheduling_demands'] ?? [])
                ->mapWithKeys(fn (array $demand): array => [
                    (int) ($demand['scheduling_demand_id'] ?? 0) => (int) ($demand['expected_count'] ?? $demand['required_capacity'] ?? $demand['section_capacity'] ?? 0),
                ]);

            $capturedUnits = collect($snapshot['scheduling_demands'] ?? [])
                ->mapWithKeys(fn (array $demand): array => [
                    (int) ($demand['scheduling_demand_id'] ?? 0) => (float) ($demand['load_units'] ?? $demand['credit_units'] ?? 0),
                ]);

            $capturedSectionIds = collect($snapshot['scheduling_demands'] ?? [])
                ->mapWithKeys(fn (array $demand): array => [
                    (int) ($demand['scheduling_demand_id'] ?? 0) => isset($demand['section_id']) ? (int) $demand['section_id'] : null,
                ]);

            $missingDemandIds = collect($allMeetings)
                ->map(fn (array $meeting): int => (int) ($meeting['schedulingDemandId'] ?? 0))
                ->filter(fn (int $id): bool => $id > 0 && (
                    (int) $capturedCapacities->get($id, 0) <= 0
                    || (float) $capturedUnits->get($id, 0.0) <= 0.0
                ))
                ->unique()
                ->values()
                ->all();

            $fallbackDemands = $missingDemandIds !== []
                ? SchedulingDemand::query()
                    ->whereIn('id', $missingDemandIds)
                    ->with([
                        'sectionDeliveryGroup:id,section_id,expected_count',
                        'sectionDeliveryGroup.section:id,capacity',
                        'courseComponent.courseSpecification:id,credit_units',
                        'termOffering.curriculumEntry.courseSpecification:id,credit_units',
                    ])
                    ->get()
                    ->keyBy('id')
                : collect();

            foreach ($allMeetings as &$meeting) {
                $demandId = (int) ($meeting['schedulingDemandId'] ?? 0);
                /** @var SchedulingDemand|null $fallbackDemand */
                $fallbackDemand = $demandId > 0 ? $fallbackDemands->get($demandId) : null;

                if (! isset($meeting['requiredCapacity']) || (int) $meeting['requiredCapacity'] <= 0) {
                    $fallbackCapacity = $fallbackDemand instanceof SchedulingDemand
                        ? (int) (
                            $fallbackDemand->sectionDeliveryGroup?->expected_count
                            ?? data_get($fallbackDemand->source_snapshot, 'expected_count')
                            ?? $fallbackDemand->sectionDeliveryGroup?->section?->capacity
                            ?? 0
                        )
                        : 0;
                    $meeting['requiredCapacity'] = (int) ($capturedCapacities->get($demandId, 0) ?: $fallbackCapacity);
                }

                if (! isset($meeting['sectionId']) || $meeting['sectionId'] === null) {
                    $meeting['sectionId'] = $capturedSectionIds->get($demandId)
                        ?? ($fallbackDemand?->sectionDeliveryGroup?->section_id !== null
                            ? (int) $fallbackDemand->sectionDeliveryGroup->section_id
                            : null);
                }

                $units = (float) $capturedUnits->get($demandId, 0.0);
                if ($units <= 0.0 && $fallbackDemand instanceof SchedulingDemand) {
                    $units = (float) (
                        $fallbackDemand->courseComponent?->courseSpecification?->credit_units
                        ?? $fallbackDemand->termOffering?->curriculumEntry?->courseSpecification?->credit_units
                        ?? data_get($fallbackDemand->source_snapshot, 'load_units')
                        ?? data_get($fallbackDemand->source_snapshot, 'credit_units')
                        ?? 0.0
                    );
                }
                $meeting['creditUnits'] = round(max(0.0, $units), 2);
            }
            unset($meeting);
        }

        if ($this->termId !== null && $allMeetings !== []) {
            $commitments = SchedulingCommitment::query()
                ->where('term_id', $this->termId)
                ->get(['section_id', 'day_of_week', 'starts_at', 'authority_reference', 'reason']);

            if ($commitments->isNotEmpty()) {
                foreach ($allMeetings as &$meeting) {
                    if ($meeting['isPinned'] ?? false) {
                        continue;
                    }

                    $sectionId = $meeting['sectionId'] ?? null;
                    $startsAt = $meeting['startsAt'] ?? null;
                    $day = $meeting['day'] ?? null;
                    $demandId = $meeting['demandId'] ?? null;
                    $meetingSequence = $meeting['meetingSequence'] ?? null;

                    $matched = $commitments->contains(function (SchedulingCommitment $commitment) use ($sectionId, $day, $startsAt, $demandId, $meetingSequence): bool {
                        $scope = GenerateSchedulingDemand::parseCommitmentScope($commitment);
                        if ($scope['demand_id'] !== null && $demandId !== null && $scope['demand_id'] !== (int) $demandId) {
                            return false;
                        }
                        if ($scope['meeting_sequence'] !== null && $meetingSequence !== null && $scope['meeting_sequence'] !== (int) $meetingSequence) {
                            return false;
                        }
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

        if ($official && ($data['selectedVersion'] ?? null) instanceof PublishedTimetableVersion && $allMeetings !== []) {
            $activeSectionMeetings = SectionMeeting::query()
                ->where('schedule_run_id', $data['selectedVersion']->schedule_run_id)
                ->where('state', SectionMeeting::StateActive)
                ->get(['id', 'scheduling_demand_id', 'meeting_sequence'])
                ->keyBy(fn (SectionMeeting $sectionMeeting): string => $sectionMeeting->scheduling_demand_id.':'.$sectionMeeting->meeting_sequence);

            foreach ($allMeetings as &$meeting) {
                $demandId = (int) ($meeting['schedulingDemandId'] ?? 0);
                $sequence = (int) ($meeting['meetingSequence'] ?? 1);
                $matchedSectionMeeting = $demandId > 0
                    ? $activeSectionMeetings->get($demandId.':'.$sequence)
                    : null;
                $meeting['sectionMeetingId'] = $matchedSectionMeeting instanceof SectionMeeting
                    ? (int) $matchedSectionMeeting->id
                    : null;
            }
            unset($meeting);
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

        $readiness = $this->generationReadiness();
        $stepperData = [];

        if ($term instanceof Term) {
            $activePackage = TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with(['teachingGridRows', 'datedExceptions'])
                ->first();
            $draftPackages = TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateDraft)
                ->get(['id', 'term_id', 'state']);
            $sourceClasses = Section::query()
                ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
                ->with(['termOffering:id,term_id,modality,curriculum_entry_id', 'calendarPackage.teachingGridRows', 'calendarPackage.datedExceptions'])
                ->get(['id', 'term_offering_id', 'term_calendar_package_id', 'delivery_mode', 'confirmed_at', 'state']);
            $contextClasses = $sourceClasses->filter(
                fn (Section $section): bool => $activePackage instanceof TermCalendarPackage
                    && $section->calendarPackage instanceof TermCalendarPackage
                    && $section->calendarPackage->isActivatedCoreSource()
                    && $section->calendarPackage->coreSignature() === $activePackage->coreSignature(),
            );
            $cohorts = TermCohort::query()->where('term_id', $term->id)->get(['id']);
            $demands = SchedulingDemand::query()
                ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                ->get(['id']);
            $activeRooms = Room::query()->where('is_active', true)->get(['id']);
            $offerings = TermOffering::query()
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->with('curriculumEntry.courseSpecification:id,course_id')
                ->get();
            $offeringCourseIds = $offerings
                ->map(fn (TermOffering $offering): ?int => $offering->curriculumEntry?->courseSpecification?->course_id)
                ->filter()
                ->unique();
            $qualifiedCourseIds = $offeringCourseIds->isNotEmpty()
                ? FacultyQualification::query()->active()->whereIn('course_id', $offeringCourseIds)->pluck('course_id')->unique()
                : collect();
            $missingQualifications = $offeringCourseIds->diff($qualifiedCourseIds)->count();
            $availabilityProjection = [
                'total_faculty' => User::query()
                    ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                    ->count(),
                'replied_count' => FacultyAvailabilityDeclaration::query()
                    ->where('term_id', $term->id)
                    ->distinct('faculty_user_id')
                    ->count('faculty_user_id'),
            ];

            $stepperData = [
                'readiness' => $readiness,
                'activePackage' => $activePackage,
                'draftPackages' => $draftPackages,
                'offerings' => $offerings,
                'sourceClasses' => $sourceClasses,
                'contextClasses' => $contextClasses,
                'cohorts' => $cohorts,
                'demands' => $demands,
                'rooms' => $activeRooms,
                'missingQualifications' => $missingQualifications,
                'availabilityProjection' => $availabilityProjection,
                'viewTab' => $this->viewTab,
            ];
        }

        return [...$data, ...$stepperData, 'showOfficial' => $official, 'timetablePresentation' => $presentation,
            'masterGroups' => $groups, 'programOptions' => $programs, 'yearOptions' => $years, 'viewOptions' => $options,
            'scopeMeetingCount' => count($allMeetings), 'generationReadiness' => $readiness,
            'interactiveContext' => $interactiveContext];
    }

    public function canEditActiveCandidate(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User
            && ! $this->isTermClosed()
            && $run instanceof ScheduleGenerationRun
            && $this->timetableState !== 'official'
            && $run->status === ScheduleGenerationRun::StatusUnderReview
            && ! in_array($run->candidate_state, ['Rejected', 'Stale', 'Superseded'], true)
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
    }

    public function canUndoLastChange(): bool
    {
        if (! $this->canEditActiveCandidate()) {
            return false;
        }

        $run = $this->activeScheduleRun();
        if (! $run instanceof ScheduleGenerationRun || ! RevertCandidateRun::isLocalAdjustment($run)) {
            return false;
        }

        $parentId = RevertCandidateRun::parentRunId($run);
        if ($parentId === null) {
            return false;
        }

        return ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->whereKey($parentId)
            ->exists();
    }

    public function canResetToSolverDraft(): bool
    {
        if (! $this->canUndoLastChange()) {
            return false;
        }

        $run = $this->activeScheduleRun();

        return $run instanceof ScheduleGenerationRun
            && RevertCandidateRun::rootSolverRun($run) instanceof ScheduleGenerationRun;
    }

    public function activeManualEditCount(): int
    {
        $run = $this->activeScheduleRun();

        return $run instanceof ScheduleGenerationRun
            ? RevertCandidateRun::manualEditCount($run)
            : 0;
    }

    public function undoLastChangeAction(): Action
    {
        return Action::make('undoLastChange')
            ->label('Undo last change')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->canUndoLastChange())
            ->action(function (): void {
                $this->performUndoLastChange();
            });
    }

    #[On('timetable-undo-last-change')]
    public function undoLastChangeFromNotification(?int $runId = null): void
    {
        if (! $this->canUndoLastChange()) {
            return;
        }

        $activeRun = $this->activeScheduleRun();
        if ($runId !== null && (! $activeRun instanceof ScheduleGenerationRun || (int) $activeRun->id !== $runId)) {
            return;
        }

        $this->performUndoLastChange();
    }

    public function resetToSolverDraftAction(): Action
    {
        return Action::make('resetToSolverDraft')
            ->label('Reset to solver draft')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('warning')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Reset to solver draft?')
            ->modalDescription(function (): string {
                $run = $this->activeScheduleRun();
                $editCount = $run instanceof ScheduleGenerationRun ? RevertCandidateRun::manualEditCount($run) : 0;
                $rootRun = $run instanceof ScheduleGenerationRun ? RevertCandidateRun::rootSolverRun($run) : null;
                $editsText = $editCount > 0
                    ? sprintf('%d manual %s', $editCount, Str::plural('edit', $editCount))
                    : 'your manual edits';
                $targetText = $rootRun instanceof ScheduleGenerationRun
                    ? sprintf(' (Attempt #%d)', $rootRun->id)
                    : '';

                return "This will undo {$editsText} and restore the original solver draft{$targetText}. Previous edits stay recorded in audit history.";
            })
            ->modalSubmitActionLabel('Reset to solver draft')
            ->visible(fn (): bool => $this->canResetToSolverDraft())
            ->action(function (): void {
                $run = $this->activeScheduleRun();
                if (! $run instanceof ScheduleGenerationRun) {
                    return;
                }

                $rootRun = RevertCandidateRun::rootSolverRun($run);
                if (! $rootRun instanceof ScheduleGenerationRun) {
                    return;
                }

                $this->performRevertToRun(
                    targetRun: $rootRun,
                    event: RevertCandidateRun::EventRevertedToSolver,
                    successTitle: 'Draft reset to solver schedule',
                    successBody: 'Restored the original solver draft with zero conflicts.',
                );
            });
    }

    public function regenerateTimetableAction(): Action
    {
        return Action::make('regenerateTimetable')
            ->label('Regenerate draft')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Regenerate draft timetable?')
            ->modalDescription(function (): string {
                $run = $this->activeScheduleRun();
                $attemptText = $run instanceof ScheduleGenerationRun ? "Attempt #{$run->id}" : 'this draft';
                $lockedCount = $this->termId !== null
                    ? SchedulingCommitment::query()->where('term_id', $this->termId)->count()
                    : 0;

                if ($lockedCount > 0) {
                    return sprintf(
                        'Discards %s and immediately generates a fresh whole-term draft. Your %d locked %s will be preserved.',
                        $attemptText,
                        $lockedCount,
                        Str::plural('slot', $lockedCount),
                    );
                }

                return "Discards {$attemptText} and immediately generates a fresh whole-term draft. Any existing official timetable remains unaffected.";
            })
            ->modalSubmitActionLabel('Regenerate draft')
            ->visible(function (): bool {
                $actor = auth()->user();

                return $actor instanceof User
                    && $actor->hasRole(User::StaffRoleRegistrar)
                    && $this->termId !== null
                    && ! $this->isTermClosed()
                    && ! ($this->hasPublishedTimetable() && $this->hasOfficialStudentEnrollments())
                    && $this->timetableState !== 'official'
                    && $this->canReviewActiveRun();
            })
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

                try {
                    $term = Term::query()->findOrFail($this->termId);

                    if ($this->isTermClosed($term)) {
                        throw ValidationException::withMessages([
                            'term' => 'This term is closed and cannot generate a new timetable.',
                        ]);
                    }

                    if ($this->hasPublishedTimetable($term) && $this->hasOfficialStudentEnrollments($term)) {
                        throw ValidationException::withMessages([
                            'term' => 'Full timetable regeneration is blocked because active official student enrollments exist. Use Revise schedule instead.',
                        ]);
                    }

                    $currentRun = $this->activeScheduleRun();
                    if ($currentRun instanceof ScheduleGenerationRun
                        && $currentRun->status === ScheduleGenerationRun::StatusUnderReview
                        && ! in_array($currentRun->candidate_state, ['Accepted', 'Rejected', 'Stale', 'Superseded'], true)) {
                        app(ReviewTimetableCandidate::class)->reject(
                            $currentRun,
                            $actor,
                            'Discarded by Registrar to regenerate a fresh draft timetable.',
                        );
                    }

                    $newRun = app(ScheduleGenerationService::class)->generate($term, $actor);

                    $this->selectedRunId = $newRun->id;
                    $this->timetableState = 'draft';
                    $this->viewFilter = null;
                    $this->programFilter = null;
                    $this->yearFilter = null;
                    $this->meetingDetailsViewData = [];
                    $this->resetTable();

                    $lockedCount = SchedulingCommitment::query()->where('term_id', $term->id)->count();
                    $body = $lockedCount > 0
                        ? sprintf('Attempt #%d is queued. %d locked %s preserved.', $newRun->id, $lockedCount, Str::plural('slot', $lockedCount))
                        : "Attempt #{$newRun->id} is queued. Status refreshes automatically.";

                    Notification::make()
                        ->title('Regenerating draft timetable')
                        ->body($body)
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    $rawMessage = is_string($message) ? $message : '';
                    $plain = TimetableFailureLanguage::forBlockedGeneration($rawMessage);
                    $blockingRun = $rawMessage === 'Review or reject the retained candidate, or change its source facts before generating again.'
                        && isset($term) && $term instanceof Term
                        ? $this->undecidedDraftRun($term)
                        : null;

                    $this->generationFailure = [
                        'title' => $plain['title'],
                        'explanation' => $plain['explanation'],
                        'nextStep' => $plain['nextStep'],
                        'technical' => is_string($message) ? $message : 'Review the Schedule Requirement findings and try again.',
                        'blockingRunId' => $blockingRun?->id,
                    ];
                    $this->dispatch('open-modal', id: 'timetable-generation-failure');
                } catch (Throwable $exception) {
                    report($exception);
                    $plain = TimetableFailureLanguage::fallback();

                    $this->generationFailure = [
                        'title' => $plain['title'],
                        'explanation' => $plain['explanation'],
                        'nextStep' => $plain['nextStep'],
                        'technical' => $exception->getMessage(),
                    ];
                    $this->dispatch('open-modal', id: 'timetable-generation-failure');
                }
            });
    }

    private function performUndoLastChange(): void
    {
        $run = $this->activeScheduleRun();
        if (! $run instanceof ScheduleGenerationRun) {
            return;
        }

        $parentId = RevertCandidateRun::parentRunId($run);
        if ($parentId === null) {
            return;
        }

        $targetRun = ScheduleGenerationRun::query()
            ->where('term_id', $run->term_id)
            ->whereKey($parentId)
            ->first();

        if (! $targetRun instanceof ScheduleGenerationRun) {
            return;
        }

        $this->performRevertToRun(
            targetRun: $targetRun,
            event: RevertCandidateRun::EventUndo,
            successTitle: 'Last change undone',
            successBody: 'Restored the previous draft timetable with zero conflicts.',
        );
    }

    private function performRevertToRun(
        ScheduleGenerationRun $targetRun,
        string $event,
        string $successTitle,
        string $successBody,
    ): void {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        if ($this->timetableState === 'official' || ! $this->canEditActiveCandidate()) {
            Notification::make()
                ->title('Cannot revert this timetable')
                ->body('Only an active editable draft candidate can be reverted.')
                ->danger()
                ->send();

            return;
        }

        $currentRun = $this->activeScheduleRun();
        abort_unless($currentRun instanceof ScheduleGenerationRun, 404);

        $pinCommitments = SchedulingCommitment::query()
            ->where('term_id', $currentRun->term_id)
            ->where(function ($query): void {
                $query->where('authority_reference', 'REGISTRAR-PIN')
                    ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
            })
            ->get()
            ->map(fn (SchedulingCommitment $c): array => $c->only([
                'term_id', 'section_id', 'faculty_user_id', 'room_id', 'day_of_week',
                'starts_at', 'ends_at', 'authority_reference', 'reason', 'recorded_by',
            ]))
            ->all();

        try {
            $reverted = DB::transaction(function () use ($currentRun, $targetRun, $actor, $event, $pinCommitments): ScheduleGenerationRun {
                if ($pinCommitments !== []) {
                    SchedulingCommitment::query()
                        ->where('term_id', $currentRun->term_id)
                        ->where(function ($query): void {
                            $query->where('authority_reference', 'REGISTRAR-PIN')
                                ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
                        })
                        ->delete();
                }

                $reverted = app(RevertCandidateRun::class)->execute(
                    currentRun: $currentRun,
                    targetRun: $targetRun,
                    actor: $actor,
                    event: $event,
                );

                $revertedRows = $reverted->candidateRows()->with('schedulingDemand.sectionDeliveryGroup')->get();

                foreach ($pinCommitments as $commitmentPayload) {
                    $matchedRow = $revertedRows->first(function ($row) use ($commitmentPayload): bool {
                        $ref = (string) ($commitmentPayload['authority_reference'] ?? '');
                        if (preg_match('/^REGISTRAR-PIN:D(\d+):M(\d+)$/', $ref, $matches) === 1) {
                            return (int) $row->scheduling_demand_id === (int) $matches[1]
                                && (int) $row->meeting_sequence === (int) $matches[2];
                        }

                        $rowSectionId = $row->schedulingDemand?->sectionDeliveryGroup?->section_id
                            ?? data_get($row->schedulingDemand?->source_snapshot, 'section_id');

                        return $commitmentPayload['section_id'] !== null
                            && (int) $rowSectionId === (int) $commitmentPayload['section_id'];
                    });

                    if ($matchedRow !== null) {
                        $commitmentPayload['day_of_week'] = (int) $matchedRow->day_of_week;
                        $commitmentPayload['starts_at'] = (string) $matchedRow->starts_at;
                        $commitmentPayload['ends_at'] = (string) $matchedRow->ends_at;
                        $commitmentPayload['faculty_user_id'] = (int) $matchedRow->faculty_user_id;
                        $commitmentPayload['room_id'] = $matchedRow->room_id !== null ? (int) $matchedRow->room_id : null;
                    }

                    SchedulingCommitment::query()->create($commitmentPayload);
                }

                return $reverted;
            });

            $this->selectedRunId = $reverted->id;
            $this->timetableState = 'draft';
            $this->meetingDetailsViewData = [];
            $this->resetTable();

            Notification::make()
                ->title($successTitle)
                ->body($successBody)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            Notification::make()
                ->title('Cannot revert draft timetable')
                ->body(is_string($message) ? $message : 'The previous draft could not be restored.')
                ->danger()
                ->send();

            throw $exception;
        }
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
                ->body('Published timetables are locked. Use Revise schedule for authorized mid-semester changes.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'timetable' => 'Official timetable is locked. Use Revise schedule for authorized mid-semester changes.',
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

        $demand = $row->schedulingDemand;
        $demandId = (int) ($demand?->id ?? $row->scheduling_demand_id);
        $componentId = (int) ($demand?->course_component_id ?? 0);
        $meetingSequence = max(1, (int) ($row->meeting_sequence ?: 1));
        $sectionDemandCount = $demand instanceof SchedulingDemand
            ? SchedulingDemand::query()
                ->where('section_delivery_group_id', $demand->section_delivery_group_id)
                ->count()
            : 1;
        $requiresScopedAuthority = $demand instanceof SchedulingDemand
            && ((int) $demand->meeting_count > 1 || $meetingSequence > 1 || $sectionDemandCount > 1);
        $authorityReference = $requiresScopedAuthority
            ? sprintf('REGISTRAR-PIN:D%d:M%d', $demandId, $meetingSequence)
            : 'REGISTRAR-PIN';
        $reason = $demandId > 0
            ? sprintf('Pinned by Registrar during timetable review. [D%d:C%d:M%d]', $demandId, $componentId, $meetingSequence)
            : 'Pinned by Registrar during timetable review.';

        $existingCommitmentQuery = SchedulingCommitment::query()
            ->where('term_id', $run->term_id)
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->where(function ($query) use ($row, $demandId, $meetingSequence): void {
                $query->where(function ($q) use ($row): void {
                    $q->where('day_of_week', (int) $row->day_of_week)
                        ->where('starts_at', (string) $row->starts_at);
                });
                if ($demandId > 0) {
                    $query->orWhere('authority_reference', sprintf('REGISTRAR-PIN:D%d:M%d', $demandId, $meetingSequence));
                }
            });

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
                ->title('Slot unlocked')
                ->body('This meeting slot is now unlocked and can move if you regenerate.')
                ->info()
                ->send();

            return;
        }

        $scores['pinned'] = true;
        $row->update([
            'scores' => $scores,
        ]);

        if ($demandId > 0) {
            SchedulingCommitment::query()
                ->where('term_id', (int) $run->term_id)
                ->where('authority_reference', sprintf('REGISTRAR-PIN:D%d:M%d', $demandId, $meetingSequence))
                ->delete();
        }

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
                'authority_reference' => $authorityReference,
                'reason' => $reason,
                'recorded_by' => (int) $actor->id,
            ],
        );

        Notification::make()
            ->title('Slot locked')
            ->body('This meeting slot is locked to this day, time, room, and teacher.')
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
                ->body('Published timetables are locked. Use Revise schedule for authorized mid-semester changes.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'timetable' => 'Official timetable is locked. Use Revise schedule for authorized mid-semester changes.',
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
            ->where(function ($query): void {
                $query->where('authority_reference', 'REGISTRAR-PIN')
                    ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
            })
            ->get()
            ->map(fn (SchedulingCommitment $c): array => $c->only([
                'term_id', 'section_id', 'faculty_user_id', 'room_id', 'day_of_week',
                'starts_at', 'ends_at', 'authority_reference', 'reason', 'recorded_by',
            ]))
            ->all();

        try {
            $successor = DB::transaction(function () use (
                $run,
                $row,
                $actor,
                $effectiveFacultyId,
                $effectiveRoomId,
                $dayOfWeek,
                $startsAtFormatted,
                $endsAtFormatted,
                $pinCommitments,
                $sectionId,
                $oldDay,
                $oldStart,
            ): ScheduleGenerationRun {
                if ($pinCommitments !== []) {
                    SchedulingCommitment::query()
                        ->where('term_id', $run->term_id)
                        ->where(function ($query): void {
                            $query->where('authority_reference', 'REGISTRAR-PIN')
                                ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
                        })
                        ->delete();
                }

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

                foreach ($pinCommitments as $commitmentPayload) {
                    if ((int) ($commitmentPayload['section_id'] ?? 0) === (int) $sectionId
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

                return $successor;
            });

            $this->selectedRunId = $successor->id;
            $this->timetableState = 'draft';
            $this->meetingDetailsViewData = [];

            Notification::make()
                ->title($successTitle)
                ->body($successBody)
                ->success()
                ->actions([
                    Action::make('undo')
                        ->label('Undo')
                        ->button()
                        ->color('gray')
                        ->dispatch('timetable-undo-last-change', ['runId' => $successor->id])
                        ->close(),
                ])
                ->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            Notification::make()
                ->title($failureTitle)
                ->body(is_string($message) ? $message : 'The selected assignment violates a hard scheduling rule.')
                ->danger()
                ->send();

            throw $exception;
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

        $canReviseOfficial = $official
            && ! $readOnly
            && ! $this->isTermClosed()
            && $selectedVersion instanceof PublishedTimetableVersion
            && $selectedVersion->state === PublishedTimetableVersion::StatePublished
            && $selectedVersion->scheduleRun?->isPublished()
            && Gate::allows('revise', SectionMeeting::class);

        $reviseOfficialUrl = null;
        if ($canReviseOfficial) {
            $reviseOfficialUrl = self::getUrl([
                'termId' => $selectedVersion->term_id,
                'timetableState' => 'official',
                'selectedVersionId' => $selectedVersion->id,
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
        $teacherLoadLimits = [];
        $defaultMaxUnits = ($term instanceof Term && $term->default_max_units !== null)
            ? round((float) $term->default_max_units, 2)
            : null;

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

            $declarations = FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->orderBy('faculty_user_id')
                ->orderByDesc('version')
                ->get()
                ->unique('faculty_user_id');

            $cannotTeachFacultyIds = $declarations
                ->filter(fn (FacultyAvailabilityDeclaration $declaration): bool => $declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach)
                ->pluck('faculty_user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $activeLoadOverrides = FacultyTermLoadOverride::query()
                ->where('term_id', $term->id)
                ->where('is_active', true)
                ->latest('id')
                ->get()
                ->unique('faculty_user_id')
                ->keyBy('faculty_user_id');

            $zeroUnitOverrideFacultyIds = $activeLoadOverrides
                ->filter(fn (FacultyTermLoadOverride $override): bool => $override->allowedLoadUnits() <= 0.0)
                ->keys()
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $excludedTeacherIds = array_fill_keys([...$cannotTeachFacultyIds, ...$zeroUnitOverrideFacultyIds], true);

            $courseIds = collect($allMeetings)->pluck('courseId')->filter()->unique()->values()->all();
            $qualifications = FacultyQualification::query()
                ->active()
                ->when($courseIds !== [], fn ($q) => $q->whereIn('course_id', $courseIds))
                ->with(['faculty:id,name', 'course:id,code'])
                ->get();

            $allRelevantFacultyIds = [];

            foreach ($qualifications as $qualification) {
                $code = (string) ($qualification->course?->code ?? '');
                $faculty = $qualification->faculty;
                if ($code !== '' && $faculty instanceof User && ! isset($excludedTeacherIds[(int) $faculty->id])) {
                    $qualifiedTeachersByCourse[$code][$faculty->id] = [
                        'id' => (int) $faculty->id,
                        'name' => (string) $faculty->name,
                    ];
                    $allRelevantFacultyIds[(int) $faculty->id] = true;
                }
            }

            foreach ($allMeetings as $meeting) {
                $code = (string) ($meeting['courseCode'] ?? '');
                $fId = $meeting['facultyId'] ?? null;
                $fName = (string) ($meeting['faculty'] ?? '');
                if ($fId !== null && (int) $fId > 0) {
                    $allRelevantFacultyIds[(int) $fId] = true;
                }
                if ($code !== '' && $fId !== null && $fName !== '' && $fName !== 'Faculty unassigned' && ! isset($excludedTeacherIds[(int) $fId])) {
                    $qualifiedTeachersByCourse[$code][(int) $fId] ??= [
                        'id' => (int) $fId,
                        'name' => $fName,
                    ];
                }
            }

            foreach ($activeLoadOverrides as $facultyId => $override) {
                $allRelevantFacultyIds[(int) $facultyId] = true;
            }

            foreach (array_keys($allRelevantFacultyIds) as $facultyId) {
                /** @var FacultyTermLoadOverride|null $override */
                $override = $activeLoadOverrides->get($facultyId);
                $teacherLoadLimits[$facultyId] = [
                    'limit' => $override instanceof FacultyTermLoadOverride
                        ? round($override->allowedLoadUnits(), 2)
                        : $defaultMaxUnits,
                    'hasOverride' => $override instanceof FacultyTermLoadOverride,
                ];
            }

            $qualifiedTeachersByCourse = collect($qualifiedTeachersByCourse)
                ->map(fn (array $teachers): array => collect($teachers)->sortBy('name')->values()->all())
                ->all();

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

        $isTermClosed = $this->isTermClosed($term instanceof Term ? $term : null);
        $isSupersededVersion = $official
            && $selectedVersion instanceof PublishedTimetableVersion
            && $selectedVersion->state !== PublishedTimetableVersion::StatePublished;
        $activeDraftOptions = is_array($data['activeDraftOptions'] ?? null) ? $data['activeDraftOptions'] : [];
        $activeDraftFirst = $activeDraftOptions[0] ?? null;

        return [
            'editable' => $editable,
            'isOfficial' => $official,
            'readOnly' => $readOnly,
            'isTermClosed' => $isTermClosed,
            'isSupersededVersion' => $isSupersededVersion,
            'hasOfficialEnrollmentsBlockingRegen' => (bool) ($data['hasOfficialEnrollmentsBlockingRegen'] ?? false),
            'canStartNewDraftAttempt' => (bool) ($data['canStartNewDraftAttempt'] ?? false),
            'activeDraftId' => is_array($activeDraftFirst) && isset($activeDraftFirst['id']) ? (int) $activeDraftFirst['id'] : null,
            'activeDraftLabel' => is_array($activeDraftFirst) && isset($activeDraftFirst['label']) ? (string) $activeDraftFirst['label'] : null,
            'statusPillLabel' => $official
                ? ($isTermClosed ? 'Official · Archived' : ($isSupersededVersion ? 'Historical · Read-only' : 'Official · Live'))
                : ($editable ? 'Draft · Editable' : null),
            'statusPillTone' => $official ? ($isTermClosed || $isSupersededVersion ? 'neutral' : 'official') : ($editable ? 'draft' : 'neutral'),
            'canReviseOfficial' => $canReviseOfficial,
            'reviseOfficialUrl' => $reviseOfficialUrl,
            'teachingGrid' => $teachingGrid,
            'dayOptions' => $dayOptions,
            'timeSlotOptions' => $timeSlotOptions,
            'rooms' => $rooms,
            'qualifiedTeachersByCourse' => $qualifiedTeachersByCourse,
            'teacherAvailability' => $teacherAvailability,
            'teacherLoadLimits' => $teacherLoadLimits,
            'defaultMaxUnits' => $defaultMaxUnits,
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

    public function plainAttemptStatusLabel(ScheduleGenerationRun $run): string
    {
        if ($run->status === ScheduleGenerationRun::StatusSuperseded) {
            return 'Replaced';
        }

        if ($run->status === ScheduleGenerationRun::StatusFailed) {
            return 'Cannot publish';
        }

        if ($run->status === ScheduleGenerationRun::StatusBlocked) {
            return in_array($run->candidate_state, ['Stale', 'Superseded', 'Rejected'], true)
                ? 'Outdated'
                : 'Cannot publish';
        }

        if (in_array($run->candidate_state, ['Stale', 'Superseded', 'Rejected'], true)) {
            return 'Outdated';
        }

        return ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status;
    }

    private function canStartNewDraftAttempt(Term $term, ?ScheduleGenerationRun $activeRun, bool $hasActiveDraft = false): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            return false;
        }

        if ($this->isTermClosed($term)) {
            return false;
        }

        if ($this->hasPublishedTimetable($term) && $this->hasOfficialStudentEnrollments($term)) {
            return false;
        }

        if ($hasActiveDraft) {
            return false;
        }

        if ($activeRun instanceof ScheduleGenerationRun && in_array($activeRun->status, [
            ScheduleGenerationRun::StatusQueued,
            ScheduleGenerationRun::StatusDispatching,
        ], true)) {
            return false;
        }

        return $this->retainedCandidateNextStep() === null;
    }

    /**
     * @return list<array{label: string, url: string, icon: string}>
     */
    public function postPublishHandoffLinks(): array
    {
        if ($this->termId === null || $this->isTermClosed()) {
            return [];
        }

        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('windows')
            ->first();

        $hasEnrollmentWindow = $activePackage?->windows->contains('window_type', TermCalendarWindow::TypeEnrollment) ?? false;
        $hasGradeEntryWindow = $activePackage?->windows->contains('window_type', TermCalendarWindow::TypeGradeEntry) ?? false;
        $missingCalendarWindows = ! $hasEnrollmentWindow || ! $hasGradeEntryWindow;

        $hasPublishedAdmissionCycle = AdmissionCycle::query()
            ->where('term_id', $this->termId)
            ->where('state', AdmissionCycle::StatePublished)
            ->exists();

        $hasEnrollments = Enrollment::query()
            ->where('term_id', $this->termId)
            ->exists();

        if (! $missingCalendarWindows && $hasPublishedAdmissionCycle && $hasEnrollments) {
            return [];
        }

        $links = [];

        if ($missingCalendarWindows && TermCalendar::canAccess()) {
            $links[] = [
                'label' => 'Enrollment & grading dates',
                'url' => TermCalendar::getUrl(['termId' => $this->termId]),
                'icon' => 'heroicon-o-calendar-days',
            ];
        }

        if (! $hasPublishedAdmissionCycle && AdmissionCycleResource::canAccess()) {
            $links[] = [
                'label' => 'Admission cycles',
                'url' => AdmissionCycleResource::getUrl('index'),
                'icon' => 'heroicon-o-user-plus',
            ];
        }

        if (EnrollmentResource::canAccess() && ($hasEnrollmentWindow || $links !== [])) {
            $links[] = [
                'label' => 'Students & Enrollment',
                'url' => EnrollmentResource::getUrl('index'),
                'icon' => 'heroicon-o-clipboard-document-list',
            ];
        }

        return $links;
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
                TextColumn::make('day')->label('Day & time')->formatStateUsing(fn (array $record): string => $record['dayLabel'])->description(fn (array $record): string => $record['time'])->icon(Heroicon::OutlinedClock)->weight(FontWeight::Medium)->width('11rem')->extraAttributes(['class' => 'tala-planning-meeting-when'])->sortable(),
                TextColumn::make('courseTitle')->label('Subject & student sections')->description(fn (array $record): string => collect($record['sections'] ?? [])->pluck('label')->filter()->unique()->implode(' · ') ?: $record['class'])->weight(FontWeight::SemiBold)->width('38%')->extraAttributes(['class' => 'tala-planning-meeting-course'])->sortable()->wrap(),
                TextColumn::make('faculty')->label('Teacher & room')->description(fn (array $record): string => $record['room'])->width('26%')->extraAttributes(['class' => 'tala-planning-meeting-teacher'])->sortable()->wrap(),
            ])->recordActions([Action::make('viewMeeting')->modal()->label('Meeting details')->icon(Heroicon::OutlinedEye)->button()->color('gray')->modalHeading('Class meeting')->modalWidth(Width::Large)->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Close')->extraModalFooterActions(fn (array $record): array => $this->meetingRevisionActions($record))->modalContent(fn (array $record): View => view('filament.components.faculty-meeting-details', ['meeting' => $record, 'showFaculty' => true]))])->defaultSort('day')->emptyState(view('filament.partials.collection-empty-state'))->emptyStateHeading('No meetings in this view')->emptyStateDescription('Choose a timetable version or clear the view filters.');
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
            return [];
        }

        if (! $this->canReviseOfficialSchedule()) {
            return [];
        }

        $version = $data['selectedVersion'] ?? null;
        if (! $version instanceof PublishedTimetableVersion
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

        $meetingId = (int) $meeting->id;

        return [
            Action::make('changeThisMeeting')
                ->label('Revise this class')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->action(fn () => $this->replaceMountedAction('revisePublishedSchedule', ['meeting' => $meetingId])),
            Action::make('cancelThisClass')
                ->label('Cancel this class')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->action(fn () => $this->replaceMountedAction('cancelPublishedClass', ['meeting' => $meetingId])),
        ];
    }

    public function publishPendingRevisionAction(): Action
    {
        return Action::make('publishPendingRevision')
            ->label('Publish pending revision')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Publish pending timetable revision')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalDescription(function (): string {
                $draft = $this->pendingDraftRevisionForOfficial();
                if (! $draft instanceof TimetableRevision) {
                    return 'No pending draft timetable revision exists for this published schedule.';
                }

                $caseIds = collect($draft->impact_snapshot['affected_registration_case_ids'] ?? [])->map(fn (mixed $id): int => (int) $id);
                $sourceReference = 'timetable-revision:'.$draft->id;
                $resolvedCount = $caseIds->isEmpty()
                    ? 0
                    : RegistrationCaseEvent::query()
                        ->whereIn('enrollment_id', $caseIds)
                        ->where('event_type', 'TimetableRevisionImpactReviewResolved')
                        ->where('authority_reference', $sourceReference)
                        ->distinct('enrollment_id')
                        ->count('enrollment_id');
                $remaining = max(0, $caseIds->count() - $resolvedCount);

                return sprintf(
                    'Revision #%d (%s): "%s". %d of %d affected enrollment cases resolved (%d remaining). Publishing creates the successor timetable version and reissues affected CORs.',
                    $draft->id,
                    $draft->change_type,
                    (string) $draft->reason,
                    $resolvedCount,
                    $caseIds->count(),
                    $remaining,
                );
            })
            ->modalSubmitActionLabel('Publish pending revision')
            ->visible(fn (): bool => $this->canReviseOfficialSchedule() && $this->pendingDraftRevisionForOfficial() instanceof TimetableRevision)
            ->action(function (): void {
                $actor = auth()->user();
                if (! $actor instanceof User || ! $this->canReviseOfficialSchedule()) {
                    abort(403);
                }

                $run = $this->officialPublishedRun();
                $draft = $this->pendingDraftRevisionForOfficial();

                if (! $run instanceof ScheduleGenerationRun || ! $draft instanceof TimetableRevision) {
                    Notification::make()
                        ->title('No pending revision found')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $events = app(PublishedScheduleRevisionService::class)->publishDraftRevision($run, $draft, $actor);

                    $latestVersion = PublishedTimetableVersion::query()
                        ->where('term_id', $run->term_id)
                        ->where('state', PublishedTimetableVersion::StatePublished)
                        ->latest('version')
                        ->first();
                    if ($latestVersion instanceof PublishedTimetableVersion) {
                        $this->selectedVersionId = $latestVersion->id;
                    }
                    $this->timetableState = 'official';
                    $this->meetingDetailsViewData = [];
                    $this->resetTable();

                    Notification::make()
                        ->title('Official timetable updated')
                        ->body($latestVersion instanceof PublishedTimetableVersion
                            ? "Official timetable updated (Rev {$latestVersion->version}). Affected teachers and students will be notified."
                            : 'Official timetable updated. Affected teachers and students will be notified.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    if ($this->isPendingImpactReviewBlock($exception)) {
                        $this->notifyPendingImpactReview();

                        return;
                    }

                    Notification::make()
                        ->title('Published schedule revision blocked')
                        ->body($this->revisionValidationMessage($exception))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                }
            });
    }

    public function revisePublishedScheduleAction(): Action
    {
        return $this->publishedRevisionAction(false);
    }

    public function cancelPublishedClassAction(): Action
    {
        return $this->publishedRevisionAction(true);
    }

    private function publishedRevisionAction(bool $cancelClass): Action
    {
        $action = Action::make($cancelClass ? 'cancelPublishedClass' : 'revisePublishedSchedule')
            ->label($cancelClass ? 'Cancel class' : 'Revise schedule')
            ->icon($cancelClass ? Heroicon::OutlinedXCircle : Heroicon::OutlinedPencilSquare)
            ->color($cancelClass ? 'danger' : 'primary')
            ->modalHeading($cancelClass ? 'Cancel a class' : 'Revise official schedule')
            ->modalDescription($cancelClass
                ? 'Cancels the whole class and all its official meetings. Affected student registrations must be resolved before publication; earlier versions stay in history.'
                : 'Update a live class without regenerating the whole term. TALA checks conflicts and notifies affected teachers and students.')
            ->modalSubmitActionLabel($cancelClass ? 'Cancel class' : 'Publish revision')
            ->modalSubmitAction(fn (Action $action): Action => $action->color($cancelClass ? 'danger' : 'primary'))
            ->modalWidth($cancelClass ? Width::TwoExtraLarge : Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->fillForm(fn (array $arguments): array => [
                ...$this->officialRevisionTarget($arguments, $cancelClass),
                'change_type' => $cancelClass ? ScheduleRevisionEvent::ChangeSectionCancellation : null,
                'replacement_room_id' => null,
                'replacement_faculty_user_id' => null,
                'day_of_week' => null,
                'starts_at' => null,
                'ends_at' => null,
                'reason' => null,
                'authority_reference' => null,
            ])
            ->visible(fn (): bool => $this->canReviseOfficialSchedule())
            ->action(function (array $data) use ($cancelClass): void {
                $actor = auth()->user();
                if (! $actor instanceof User || ! $this->canReviseOfficialSchedule()) {
                    abort(403);
                }

                $run = $this->officialPublishedRun();
                abort_unless($run instanceof ScheduleGenerationRun, 404);

                $changeType = $cancelClass ? ScheduleRevisionEvent::ChangeSectionCancellation : (string) ($data['change_type'] ?? '');
                $reason = (string) ($data['reason'] ?? '');

                if (! $cancelClass && $changeType === ScheduleRevisionEvent::ChangeSectionCancellation) {
                    throw ValidationException::withMessages(['change_type' => 'Use Cancel class to cancel the whole class and its meetings.']);
                }

                try {
                    $events = $changeType === ScheduleRevisionEvent::ChangeSectionCancellation
                        ? app(PublishedScheduleRevisionService::class)->cancelSection(
                            $run,
                            PublishedScheduleRevisionForm::section($run, $data),
                            $actor,
                            $reason,
                            (string) ($data['authority_reference'] ?? ''),
                        )
                        : app(PublishedScheduleRevisionService::class)->revise(
                            $run,
                            $actor,
                            $changeType,
                            PublishedScheduleRevisionForm::changes($run, $data),
                            $reason,
                            (string) ($data['authority_reference'] ?? ''),
                        );

                    $latestVersion = PublishedTimetableVersion::query()
                        ->where('term_id', $run->term_id)
                        ->where('state', PublishedTimetableVersion::StatePublished)
                        ->latest('version')
                        ->first();
                    if ($latestVersion instanceof PublishedTimetableVersion) {
                        $this->selectedVersionId = $latestVersion->id;
                    }
                    $this->timetableState = 'official';
                    $this->meetingId = null;
                    $this->meetingDetailsViewData = [];
                    $this->resetTable();

                    Notification::make()
                        ->title('Official timetable updated')
                        ->body($latestVersion instanceof PublishedTimetableVersion
                            ? "Official timetable updated (Rev {$latestVersion->version}). Affected teachers and students will be notified."
                            : 'Official timetable updated. Affected teachers and students will be notified.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    if ($this->isPendingImpactReviewBlock($exception)) {
                        $this->notifyPendingImpactReview();
                        $this->meetingDetailsViewData = [];

                        return;
                    }

                    Notification::make()
                        ->title('Published schedule revision blocked')
                        ->body($this->revisionValidationMessage($exception))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                }
            });

        if ($cancelClass) {
            return $action->schema(function (): array {
                $run = $this->officialPublishedRun();

                return $run instanceof ScheduleGenerationRun
                    ? PublishedScheduleRevisionForm::schema($run, true)
                    : [];
            });
        }

        return $action
            ->startOnStep(fn (array $arguments): int => filled($arguments['section_meeting_id'] ?? $arguments['meeting'] ?? $this->meetingId ?? null) ? 2 : 1)
            ->steps(function (): array {
                $run = $this->officialPublishedRun();

                return $run instanceof ScheduleGenerationRun
                    ? PublishedScheduleRevisionForm::wizardSteps($run)
                    : [];
            });
    }

    /**
     * Maps solver failure / outcome diagnostics into plain-language guidance without
     * swallowing infeasibility or solver-status messages as "Something went wrong".
     *
     * @param  array<string, mixed>  $solverFailure
     * @param  array<string, mixed>  $solverOutcome
     * @param  array<string, mixed>|null  $reviewEvidence
     * @return array{explanation: string, nextStep: string, technical: string}
     */
    public function plainSolverFailureSummary(array $solverFailure, array $solverOutcome, ?array $reviewEvidence = null): array
    {
        $rawMessage = trim((string) ($solverFailure['message'] ?? $solverOutcome['message'] ?? ''));
        $rawStatus = strtolower(trim((string) ($solverOutcome['solver_status'] ?? '')));
        $evidenceOutcome = (string) ($reviewEvidence['outcome'] ?? '');

        $mappedOutcome = match ($rawStatus) {
            'infeasible' => 'Infeasible',
            'unknown' => 'Unknown',
            'model_invalid' => 'ModelInvalid',
            'technical_failure' => 'TechnicalFailure',
            default => in_array($evidenceOutcome, ['Infeasible', 'Unknown', 'ModelInvalid', 'TechnicalFailure'], true)
                ? $evidenceOutcome
                : null,
        };

        if ($rawMessage !== '') {
            $transport = TimetableFailureLanguage::forSolverFailureMessage($rawMessage);
            $fallback = TimetableFailureLanguage::fallback();
            if ($transport['explanation'] !== $fallback['explanation']) {
                return [
                    'explanation' => $transport['explanation'],
                    'nextStep' => $transport['nextStep'],
                    'technical' => $rawMessage,
                ];
            }
        }

        if ($mappedOutcome !== null) {
            $outcomeText = TimetableFailureLanguage::forOutcome($mappedOutcome);
            $nextStep = match ($mappedOutcome) {
                'Infeasible' => 'Review conflicting class, room, or faculty availability requirements, then generate a new draft.',
                'Unknown' => 'Try generating again or loosen tight constraints before retrying.',
                'ModelInvalid' => 'Contact your system administrator to inspect the schedule data.',
                default => 'Try again. If this keeps happening, contact your system administrator.',
            };

            return [
                'explanation' => $outcomeText['explanation'],
                'nextStep' => $nextStep,
                'technical' => $rawMessage !== '' ? $rawMessage : ($outcomeText['label'].($rawStatus !== '' ? " ({$rawStatus})" : '')),
            ];
        }

        if ($rawMessage !== '' && (str_contains(strtolower($rawMessage), 'infeasible') || str_contains(strtolower($rawMessage), 'conflict'))) {
            $infeasible = TimetableFailureLanguage::forOutcome('Infeasible');

            return [
                'explanation' => $infeasible['explanation'],
                'nextStep' => 'Review conflicting class, room, or faculty availability requirements, then generate a new draft.',
                'technical' => $rawMessage,
            ];
        }

        $fallback = TimetableFailureLanguage::fallback();

        return [
            'explanation' => $fallback['explanation'],
            'nextStep' => $fallback['nextStep'],
            'technical' => $rawMessage,
        ];
    }

    private function officialPublishedRun(): ?ScheduleGenerationRun
    {
        if ($this->termId === null) {
            return null;
        }

        $versionQuery = PublishedTimetableVersion::query()
            ->where('term_id', $this->termId)
            ->where('state', PublishedTimetableVersion::StatePublished);

        $version = $this->selectedVersionId !== null
            ? (clone $versionQuery)->whereKey($this->selectedVersionId)->with('scheduleRun')->first()
            : $versionQuery->latest('version')->with('scheduleRun')->first();

        if (! $version instanceof PublishedTimetableVersion) {
            return null;
        }

        $run = $version->scheduleRun;

        return $run instanceof ScheduleGenerationRun && $run->isPublished() ? $run : null;
    }

    public function canReviseOfficialSchedule(): bool
    {
        $actor = auth()->user();
        $run = $this->officialPublishedRun();

        return $actor instanceof User
            && ! $actor->hasRole(User::StaffRoleAcademicHead)
            && ! $this->isTermClosed()
            && $run instanceof ScheduleGenerationRun
            && Gate::forUser($actor)->allows('revise', SectionMeeting::class);
    }

    private function pendingDraftRevisionForOfficial(): ?TimetableRevision
    {
        $run = $this->officialPublishedRun();
        if (! $run instanceof ScheduleGenerationRun) {
            return null;
        }

        $versionId = PublishedTimetableVersion::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->value('id');

        if ($versionId === null) {
            return null;
        }

        return TimetableRevision::query()
            ->where('source_version_id', $versionId)
            ->where('state', TimetableRevision::StateDraft)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{section_meeting_ids: list<int>, section_id: int|null}
     */
    private function officialRevisionTarget(array $arguments, bool $cancelClass): array
    {
        $rawMeeting = array_key_exists('section_meeting_id', $arguments)
            ? $arguments['section_meeting_id']
            : (array_key_exists('meeting', $arguments)
                ? $arguments['meeting']
                : $this->meetingId);

        if ($rawMeeting === null || $rawMeeting === '') {
            return ['section_meeting_ids' => [], 'section_id' => null];
        }

        abort_unless($this->canReviseOfficialSchedule(), 403);
        $meetingId = filter_var($rawMeeting, FILTER_VALIDATE_INT);
        abort_unless($meetingId !== false && $meetingId > 0, 404);

        $run = $this->officialPublishedRun();
        abort_unless($run instanceof ScheduleGenerationRun, 404);

        $meeting = SectionMeeting::query()
            ->where('schedule_run_id', $run->id)
            ->where('state', SectionMeeting::StateActive)
            ->with('schedulingDemand.sectionDeliveryGroup')
            ->findOrFail($meetingId);

        return [
            'section_meeting_ids' => $cancelClass ? [] : [(int) $meeting->id],
            'section_id' => $cancelClass ? (int) $meeting->schedulingDemand->sectionDeliveryGroup->section_id : null,
        ];
    }

    private function isPendingImpactReviewBlock(ValidationException $exception): bool
    {
        return str_contains(
            $this->revisionValidationMessage($exception),
            'Resolve every affected enrollment registration placement',
        );
    }

    private function notifyPendingImpactReview(): void
    {
        Notification::make()
            ->title('Pending revision saved: student impact review required')
            ->body('The revision is saved as a pending Draft and the current timetable stays in use. Resolve the affected student reviews in Enrollments, then click "Publish pending revision".')
            ->warning()
            ->persistent()
            ->send();
    }

    private function revisionValidationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'The complete replacement schedule failed current hard-constraint validation.';
    }
}
