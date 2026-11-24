<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Support\HasSearchableTermSelection;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

class AcademicApprovals extends Page
{
    use HasSearchableTermSelection;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Academic Oversight';

    protected static ?string $title = 'Academic Oversight';

    protected string $view = 'filament.pages.academic-approvals';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-academic-oversight-page'];

    public function getHeader(): ?View
    {
        return view('filament.pages.partials.academic-oversight-header');
    }

    #[Url(history: true)]
    public ?int $termId = null;

    public function mount(): void
    {
        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);
        }
        if ($this->termId === null) {
            $activeIds = TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)->distinct()->pluck('term_id');
            $this->termId = $activeIds->count() === 1 ? (int) $activeIds->sole() : null;
        }
    }

    public function selectTerm(int|string|null $termId): void
    {
        $selectedId = filled($termId) ? (int) $termId : null;
        abort_unless($selectedId === null || Term::query()->whereKey($selectedId)->exists(), 404);
        $this->termId = $selectedId;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $term = $this->termId !== null ? Term::query()->with('academicYear')->find($this->termId) : null;
        $calendar = $term?->calendarPackages()->with('windows')->where('state', TermCalendarPackage::StateActive)->latest('version')->first();
        $calendarReadiness = $calendar ? app(TermCalendarPackageReadinessService::class)->for($calendar) : null;
        $timetable = $term ? PublishedTimetableVersion::query()->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)->withCount('meetings')->latest('version')->first() : null;
        $hasDraftTimetable = $term !== null && $timetable === null
            && ScheduleGenerationRun::query()
                ->where('term_id', $term->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                    ScheduleGenerationRun::StatusUnderReview,
                ])
                ->exists();
        $readiness = app(AcademicReadinessService::class);
        $programs = Program::query()->with([
            'curriculumVersions.entries.courseSpecification.components',
            'curriculumVersions.entries.courseSpecification.course',
        ])->orderBy('code')->get();
        $authorityRows = $programs->map(function (Program $program) use ($readiness): array {
            $curriculum = $readiness->currentCurriculum($program);
            $successorDraft = $readiness->successorDraft($program);
            $programReadiness = $readiness->programReadiness($program);

            return [
                'program' => $program,
                'curriculum' => $curriculum,
                'successorDraft' => $successorDraft,
                'successorReadiness' => $programReadiness['successor_draft'],
                'successorUrl' => $successorDraft
                    ? CurriculumVersionResource::getUrl('review', ['record' => $successorDraft, 'oversightTermId' => $this->termId])
                    : null,
                'readiness' => $programReadiness,
                'url' => $curriculum
                    ? CurriculumVersionResource::getUrl('review', ['record' => $curriculum, 'oversightTermId' => $this->termId])
                    : ProgramResource::getUrl('view', ['record' => $program, 'oversightTermId' => $this->termId]),
            ];
        });
        $attention = [];
        $calendarUrl = TermCalendar::getUrl(['termId' => $this->termId]);
        $timetableUrl = TermTimetable::getUrl([
            'termId' => $this->termId,
            ...($timetable ? ['timetableState' => 'official', 'selectedVersionId' => $timetable->id] : []),
        ]);
        if ($term && ! $calendar) {
            $attention[] = ['title' => 'Active term calendar unavailable', 'detail' => 'No active term calendar is recorded for this term.', 'owner' => 'Registrar', 'url' => $calendarUrl, 'scope' => 'Selected term'];
        }
        foreach ($calendarReadiness['blockers'] ?? [] as $blocker) {
            $attention[] = ['title' => $blocker['source'], 'detail' => $blocker['reason'], 'owner' => $blocker['owner'], 'url' => $calendarUrl, 'scope' => 'Selected term'];
        }
        $examination = $this->examinationPeriod();
        if ($term && $examination['status'] === 'Unavailable') {
            $attention[] = ['title' => 'Exam period unavailable', 'detail' => $examination['message'], 'owner' => $examination['owner'], 'url' => $calendarUrl, 'scope' => 'Selected term'];
        }
        if ($term instanceof Term) {
            array_push($attention, ...$this->selectedTermOperationalFindings($term, $timetable));
        }
        foreach ($authorityRows as $row) {
            if ($row['readiness']['status'] !== 'Active') {
                $attention[] = [
                    'title' => $row['program']->code.' · '.$row['readiness']['status'],
                    'detail' => $row['readiness']['blocker'],
                    'owner' => 'Registrar',
                    'url' => $row['url'],
                    'scope' => 'Institution-wide',
                ];
            } elseif ($row['successorDraft'] !== null && $row['successorReadiness'] !== null && $row['successorUrl'] !== null) {
                $attention[] = [
                    'title' => $row['program']->code.' · Successor draft · '.$row['successorReadiness']['status'],
                    'detail' => 'Active curriculum remains in effect. Successor draft "'.$row['successorDraft']->name.'": '.$row['successorReadiness']['blocker'],
                    'owner' => 'Registrar',
                    'url' => $row['successorUrl'],
                    'scope' => 'Institution-wide',
                ];
            }
        }

        $termLinks = $term instanceof Term ? [
            [
                'label' => 'Calendar',
                'icon' => 'heroicon-o-calendar',
                'url' => $calendarUrl,
            ],
            [
                'label' => 'Classes & sections',
                'icon' => 'heroicon-o-rectangle-group',
                'url' => TermClasses::getUrl(['termId' => $term->id]),
            ],
            [
                'label' => 'Faculty & rooms',
                'icon' => 'heroicon-o-users',
                'url' => TermResources::getUrl(['termId' => $term->id]),
            ],
            [
                'label' => $timetable
                    ? 'Timetable ('.$timetable->meetings_count.' published)'
                    : ($hasDraftTimetable ? 'Timetable (Draft)' : 'Timetable (Unpublished)'),
                'icon' => 'heroicon-o-table-cells',
                'url' => $timetableUrl,
            ],
        ] : [];

        return compact('term', 'calendar', 'calendarReadiness', 'timetable', 'hasDraftTimetable', 'timetableUrl', 'termLinks', 'authorityRows', 'attention', 'examination') + [
            'terms' => Term::query()->with('academicYear')->latest('starts_on')->get(),
            'checkedAt' => now('Asia/Manila'),
            'enrollmentSummary' => $this->enrollmentSummary($term),
            'gradeSummary' => $this->gradeSummary($term),
            'curriculumSummary' => [
                'total' => $authorityRows->count(),
                'ready' => $authorityRows->where('readiness.status', 'Active')->count(),
                'successor_drafts' => $authorityRows->whereNotNull('successorDraft')->count(),
            ],
            'calendarTimeline' => $this->calendarTimeline($calendar, $examination, $calendarUrl),
        ];
    }

    /**
     * @return list<array{title: string, detail: string, owner: string, url: string, scope: string}>
     */
    private function selectedTermOperationalFindings(Term $term, ?PublishedTimetableVersion $timetable): array
    {
        $findings = [];

        $rosterCounts = GradeRoster::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->whereIn('state', [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted])
            ->select('state')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $returnedCount = (int) $rosterCounts->get(GradeRoster::StateReturned, 0);
        if ($returnedCount > 0) {
            $findings[] = [
                'title' => $returnedCount.' returned '.str('class roster')->plural($returnedCount),
                'detail' => 'Returned grade rosters require Faculty correction and resubmission before Registrar release.',
                'owner' => 'Faculty',
                'url' => GradeRosterResource::getUrl('index', [
                    'oversightTermId' => $term->id,
                    'filters' => [
                        'term' => ['value' => $term->id],
                        'state' => ['value' => GradeRoster::StateReturned],
                    ],
                ]),
                'scope' => 'Selected term',
            ];
        }

        $overdueCount = (int) $rosterCounts->get(GradeRoster::StateLateNotSubmitted, 0);
        if ($overdueCount > 0) {
            $findings[] = [
                'title' => $overdueCount.' overdue '.str('class roster')->plural($overdueCount),
                'detail' => 'Grade entry closed without submission. Faculty submission requires Registrar late-encoding authorization.',
                'owner' => 'Faculty · Registrar',
                'url' => GradeRosterResource::getUrl('index', [
                    'oversightTermId' => $term->id,
                    'filters' => [
                        'term' => ['value' => $term->id],
                        'state' => ['value' => GradeRoster::StateLateNotSubmitted],
                    ],
                ]),
                'scope' => 'Selected term',
            ];
        }

        $latestRun = ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->latest('id')
            ->first();

        if ($latestRun instanceof ScheduleGenerationRun
            && in_array($latestRun->status, [ScheduleGenerationRun::StatusFailed, ScheduleGenerationRun::StatusBlocked], true)) {
            $evidence = $latestRun->reviewEvidence();
            $statusLabel = $latestRun->status === ScheduleGenerationRun::StatusBlocked ? 'blocked' : 'failed';
            $findings[] = [
                'title' => 'Latest timetable generation run '.$statusLabel,
                'detail' => $evidence['meaning'],
                'owner' => $evidence['outcome'] === 'ModelInvalid' ? 'System Administrator' : 'Registrar',
                'url' => TermTimetable::getUrl(['termId' => $term->id]),
                'scope' => 'Selected term',
            ];
        }

        $availability = app(FacultyAvailabilityStatusProjection::class)->forTerm($term);
        if (($availability['late_impact_count'] ?? 0) > 0) {
            $officialRevisionUrl = TermTimetable::getUrl([
                'termId' => $term->id,
                'timetableState' => 'official',
                ...($timetable ? ['selectedVersionId' => $timetable->id] : []),
            ]);

            foreach ($availability['unresolved_late_impacts'] ?? [] as $impact) {
                $meetingCount = count($impact['affected_meetings'] ?? []);
                $findings[] = [
                    'title' => 'Faculty availability conflict with published schedule · '.$impact['faculty_name'],
                    'detail' => $meetingCount.' official '.str('meeting')->plural($meetingCount)
                        .' '.($meetingCount === 1 ? 'conflicts' : 'conflict')
                        .' with updated availability ('.$impact['mode_label'].'). Official timetable revision is pending.',
                    'owner' => 'Registrar',
                    'url' => $officialRevisionUrl,
                    'scope' => 'Selected term',
                ];
            }
        }

        return $findings;
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false;
    }

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        $term = $this->termId !== null ? Term::query()->find($this->termId) : null;

        return app(ExaminationPeriodProjection::class)->forTerm($term);
    }

    /** @return array<string, mixed> */
    private function enrollmentSummary(?Term $term): array
    {
        $summary = ['total' => null, 'official' => null, 'in_progress' => null, 'closed' => null, 'unknown' => null, 'url' => null];
        if ($term === null) {
            return $summary;
        }

        $counts = Enrollment::query()->where('term_id', $term->id)->select('canonical_outcome')
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('canonical_outcome')->pluck('aggregate', 'canonical_outcome');
        $summary['total'] = (int) $counts->sum();
        $summary['official'] = (int) $counts->get(Enrollment::OutcomeOfficiallyEnrolled, 0);
        $summary['in_progress'] = (int) $counts->get(Enrollment::OutcomeInProgress, 0);
        $summary['closed'] = (int) $counts->only([...Enrollment::cancelledOutcomes(), Enrollment::OutcomeNotEnrolled])->sum();
        $summary['unknown'] = $summary['total'] - $summary['official'] - $summary['in_progress'] - $summary['closed'];
        $summary['url'] = EnrollmentResource::getUrl('index', ['oversightTermId' => $term->id, 'filters' => ['term' => ['value' => $term->id]]]);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function gradeSummary(?Term $term): array
    {
        $summary = ['total' => null, 'released' => null, 'submitted' => null, 'in_progress' => null, 'unknown' => null, 'url' => null];
        if ($term === null) {
            return $summary;
        }

        $counts = GradeRoster::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->select('state')->selectRaw('COUNT(*) AS aggregate')->groupBy('state')->pluck('aggregate', 'state');
        $summary['total'] = (int) $counts->sum();
        $summary['released'] = (int) $counts->get(GradeRoster::StateReleased, 0);
        $summary['submitted'] = (int) $counts->get(GradeRoster::StateSubmitted, 0);
        $summary['in_progress'] = (int) $counts->only([GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted])->sum();
        $summary['unknown'] = $summary['total'] - $summary['released'] - $summary['submitted'] - $summary['in_progress'];
        $summary['url'] = GradeRosterResource::getUrl('index', ['oversightTermId' => $term->id, 'filters' => ['term' => ['value' => $term->id]]]);

        return $summary;
    }

    /** @param array<string, mixed> $examination
     * @return array<string, mixed>
     */
    private function calendarTimeline(?TermCalendarPackage $calendar, array $examination, string $url): array
    {
        $enrollment = $calendar?->windows->firstWhere('window_type', TermCalendarWindow::TypeEnrollment);
        $rows = [
            ['key' => 'enrollment', 'label' => 'Enrollment', 'opens_on' => $enrollment?->opens_on, 'closes_on' => $enrollment?->closes_on],
            ['key' => 'teaching', 'label' => 'Teaching', 'opens_on' => $calendar?->classes_start_on, 'closes_on' => $calendar?->classes_end_on],
            ['key' => 'exams', 'label' => 'Exams', 'opens_on' => $examination['opens_on'], 'closes_on' => $examination['closes_on']],
        ];
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        foreach ($rows as &$row) {
            $row['available'] = $row['opens_on'] !== null && $row['closes_on'] !== null && $row['opens_on']->lte($row['closes_on']);
            $row['opens_on'] = $row['available'] ? CarbonImmutable::parse($row['opens_on']->toDateString(), 'Asia/Manila') : null;
            $row['closes_on'] = $row['available'] ? CarbonImmutable::parse($row['closes_on']->toDateString(), 'Asia/Manila') : null;
            $row['left_percent'] = null;
            $row['width_percent'] = null;
        }
        unset($row);
        $valid = collect($rows)->where('available', true);
        $start = $valid->min('opens_on')?->startOfMonth();
        $end = $valid->max('closes_on')?->startOfMonth()->addMonth();
        $days = $start?->diffInDays($end);
        $months = [];
        if ($start !== null && $end !== null && $days > 0) {
            for ($month = $start; $month->lt($end); $month = $month->addMonth()) {
                $months[] = ['label' => $month->format('M Y'), 'left_percent' => round($start->diffInDays($month) / $days * 100, 4)];
            }
            foreach ($rows as &$row) {
                if ($row['available']) {
                    $row['left_percent'] = round($start->diffInDays($row['opens_on']) / $days * 100, 4);
                    $row['width_percent'] = round($row['opens_on']->diffInDays($row['closes_on']->addDay()) / $days * 100, 4);
                }
            }
            unset($row);
        }
        $enrollmentRow = $rows[0];
        $enrollmentBounds = $enrollmentRow['available'] ? app(CalendarPhaseGateService::class)->windowBounds($enrollment) : null;
        $at = CarbonImmutable::now('Asia/Manila');
        $enrollmentStatus = ! $enrollmentRow['available'] ? 'Enrollment period dates unavailable'
            : ($at->lt($enrollmentBounds['opens_at']) ? 'Enrollment period upcoming'
                : ($at->gt($enrollmentBounds['closes_at']) ? 'Enrollment period closed' : 'Enrollment period open'));

        return [
            'starts_on' => $start, 'ends_on' => $end, 'months' => $months, 'rows' => $rows, 'url' => $url,
            'today_percent' => $start !== null && $end !== null && $today->gte($start) && $today->lt($end)
                ? round($start->diffInDays($today) / $days * 100, 4) : null,
            'enrollment_status' => $enrollmentStatus,
        ];
    }

    /**
     * @return list<array{title: string, description: string, action: string, url: string, icon: string}>
     */
    public function approvalAreas(): array
    {
        $oversightParameters = $this->termId !== null ? ['oversightTermId' => $this->termId] : [];

        return collect([
            [
                'title' => 'Grades & Progress',
                'description' => 'Recorded class rosters and released results.',
                'action' => 'Class rosters',
                'url' => GradeRosterResource::getUrl('index', [
                    ...$oversightParameters,
                    ...($this->termId !== null ? ['filters' => ['term' => ['value' => $this->termId]]] : []),
                ]),
                'icon' => 'heroicon-o-document-check',
                'available' => GradeRosterResource::canAccess(),
            ],
            [
                'title' => 'Lifecycle & Completion',
                'description' => 'Recorded lifecycle changes and their institutional authority.',
                'action' => 'Lifecycle Exceptions',
                'url' => StudentLifecycleChangeResource::getUrl('index', $oversightParameters),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'available' => StudentLifecycleChangeResource::canAccess(),
            ],
            [
                'title' => 'Lifecycle & Completion',
                'description' => 'Read-only completion readiness and recorded graduation outcomes.',
                'action' => 'Completion & TOR',
                'url' => CompletionAndTor::getUrl($oversightParameters),
                'icon' => 'heroicon-o-academic-cap',
                'available' => CompletionAndTor::canAccess(),
            ],
        ])
            ->where('available', true)
            ->map(function (array $area): array {
                unset($area['available']);

                return $area;
            })
            ->values()
            ->all();
    }
}
