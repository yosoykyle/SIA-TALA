<?php

namespace App\Filament\Pages;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\LateGradeAuthorization;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\HasSearchableTermSelection;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

class FacultyOverview extends Page
{
    use HasSearchableTermSelection;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?string $slug = 'faculty-overview';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.faculty-overview';

    #[Url]
    public ?int $termId = null;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasRole(User::StaffRoleFaculty)) {
            return false;
        }

        $contexts = app(WorkspaceContextResolver::class);
        $selected = $contexts->selected($user);

        if ($selected === null) {
            $available = $contexts->availableContexts($user);
            $selected = count($available) === 1 ? array_key_first($available) : null;
        }

        return $selected === User::StaffRoleFaculty;
    }

    public function mount(): void
    {
        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);

            return;
        }

        $activeCalendarTermIds = TermCalendarPackage::query()
            ->where('state', TermCalendarPackage::StateActive)
            ->whereHas('term', fn ($query) => $query->where('state', '!=', Term::StateClosed))
            ->distinct()
            ->pluck('term_id');

        if ($activeCalendarTermIds->count() === 1) {
            $this->termId = (int) $activeCalendarTermIds->sole();

            return;
        }

        $facultyId = auth()->id();

        if ($facultyId !== null) {
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
        }

        $activeStateTermIds = Term::query()
            ->where('state', Term::StateActive)
            ->pluck('id');

        if ($activeStateTermIds->count() === 1) {
            $this->termId = (int) $activeStateTermIds->sole();
        }
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $term = $this->termId !== null ? $terms->firstWhere('id', $this->termId) : null;

        $allRosters = GradeRoster::query()
            ->whereIn('section_id', ClassOfferingTeachingAssignment::query()
                ->select('section_id')
                ->where('faculty_user_id', $user->id)
                ->where('state', ClassOfferingTeachingAssignment::StateActive))
            ->with([
                'termOffering.term.academicYear',
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
                'teachingAssignment',
            ])
            ->orderByDesc('id')
            ->get();

        $releasedRosters = $allRosters->where('state', GradeRoster::StateReleased)->values();
        if ($releasedRosters->isNotEmpty()) {
            $releasedRosters->loadMissing('rows.latestReleasedOutcome.completionSubmissions');
        }

        $gradeWindow = app(GradeWindowService::class);
        $incDeadlines = app(IncDeadlineService::class);
        $tz = (string) config('app.display_timezone', 'Asia/Manila');

        $openIncClasses = $this->openIncClasses($releasedRosters, $user, $incDeadlines);

        if (! $term instanceof Term) {
            return [
                'terms' => $terms,
                'term' => null,
                'termGateAttention' => $this->termGateAttentionItems($allRosters, $openIncClasses, $gradeWindow),
                'actionItems' => [],
                'priorityCard' => null,
                'scheduleCard' => null,
                'availabilityCard' => null,
                'gradesCard' => null,
            ];
        }

        $termRosters = $allRosters
            ->filter(fn (GradeRoster $roster): bool => (int) $roster->termOffering?->term_id === (int) $term->id)
            ->values();

        $assignedClassCount = $termRosters->count();
        $actionableClassCount = $termRosters
            ->filter(fn (GradeRoster $roster): bool => $this->isRosterActionable($roster, $gradeWindow))
            ->count();

        $availabilityStatus = app(FacultyAvailabilityStatusProjection::class)->forFaculty($term, $user);
        $hasActiveCoreCalendar = (bool) ($availabilityStatus['has_active_core_calendar'] ?? false);
        $isTermClosed = $term->state === Term::StateClosed;
        $registrarNotTeaching = (bool) ($availabilityStatus['registrar_not_teaching'] ?? false);
        $registrarNotTeachingReason = $availabilityStatus['registrar_not_teaching_reason'] ?? null;
        $canSubmitAvailability = $hasActiveCoreCalendar && ! $isTermClosed && ! $registrarNotTeaching;
        $hasDeclaration = (bool) ($availabilityStatus['has_declaration'] ?? false);
        $needsGridReview = (bool) ($availabilityStatus['needs_grid_review'] ?? false);
        $effectiveMode = $availabilityStatus['effective_mode'] ?? null;
        /** @var FacultyAvailabilityDeclaration|null $latestDeclaration */
        $latestDeclaration = $availabilityStatus['latest_declaration'] ?? null;
        /** @var TermCalendarPackage|null $activePackage */
        $activePackage = $availabilityStatus['active_calendar_package'] ?? null;
        $activePackage?->loadMissing('windows');

        $deadlineSource = FacultyAvailabilityDeadline::query()
            ->where('term_id', $term->id)
            ->latest('id')
            ->first();
        $replyByFormatted = $deadlineSource?->due_at
            ? $deadlineSource->due_at->copy()->timezone($tz)->format('M j, Y')
            : null;

        $officialMeetings = SectionMeeting::query()
            ->activeOfficial()
            ->with([
                'schedulingDemand.termOffering',
                'schedulingDemand.sectionDeliveryGroup.section',
            ])
            ->where('faculty_user_id', $user->id)
            ->whereHas('schedulingDemand.termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->get();

        $scheduleMeetingCount = $officialMeetings->count();
        $scheduleClassCount = $officialMeetings
            ->pluck('schedulingDemand.sectionDeliveryGroup.section.id')
            ->filter()
            ->unique()
            ->count();

        $externallyArrangedCount = ClassOfferingTeachingAssignment::query()
            ->where('faculty_user_id', $user->id)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->whereHas('termOffering', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->whereHas('curriculumEntry.courseSpecification', fn ($specQuery) => $specQuery
                    ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged)))
            ->where(function ($query): void {
                $query->whereNull('section_id')
                    ->orWhereHas('section', fn ($sectionQuery) => $sectionQuery->where('state', '!=', Section::StateCancelled));
            })
            ->count();

        /** @var TermCalendarWindow|null $examinationPeriod */
        $examinationPeriod = $activePackage?->windows->first(
            fn (TermCalendarWindow $window): bool => $window->window_type === TermCalendarWindow::TypeExaminationPeriod,
        );

        $affectedMeetings = $availabilityStatus['affected_meetings'] ?? [];
        $affectedMeetingsCount = count($affectedMeetings);

        $termOpenIncClasses = $openIncClasses
            ->filter(fn (array $entry): bool => $entry['term_id'] === (int) $term->id)
            ->values();
        $termOpenIncCount = (int) $termOpenIncClasses->sum('count');

        $actionItems = $this->buildActionItems(
            term: $term,
            allRosters: $allRosters,
            openIncClasses: $openIncClasses,
            gradeWindow: $gradeWindow,
            hasActiveCoreCalendar: $canSubmitAvailability,
            hasDeclaration: $hasDeclaration,
            needsGridReview: $needsGridReview,
            replyByFormatted: $replyByFormatted,
            affectedMeetings: $affectedMeetings,
        );

        $hasReturnedOrOverdue = $allRosters->contains(
            fn (GradeRoster $roster): bool => $roster->state === GradeRoster::StateReturned
                || ($roster->state === GradeRoster::StateLateNotSubmitted && $gradeWindow->isOpen($roster, LateGradeAuthorization::PeriodFinal)),
        );

        $priorityCard = match (true) {
            $hasReturnedOrOverdue => 'grades',
            $canSubmitAvailability && (! $hasDeclaration || $needsGridReview) => 'availability',
            $affectedMeetingsCount > 0 => 'schedule',
            $actionableClassCount > 0 || $openIncClasses->isNotEmpty() => 'grades',
            default => null,
        };

        $availabilityStatusLabel = match (true) {
            $registrarNotTeaching => 'Not teaching (Registrar)',
            ! $hasDeclaration => 'Not submitted yet',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeWindows => 'Available on selected times',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeAnyApproved => 'Available during all teaching hours',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeCannotTeach => 'Unavailable this term',
            default => $latestDeclaration?->modeLabel() ?? 'Not submitted yet',
        };

        $availabilityDescription = match (true) {
            $registrarNotTeaching => filled($registrarNotTeachingReason)
                ? 'Marked not teaching (0 units): '.$registrarNotTeachingReason
                : 'Marked not teaching (0 units) by the Registrar for this term.',
            $isTermClosed => 'This term is closed. Availability is read-only.',
            ! $hasActiveCoreCalendar && ! $hasDeclaration => 'Term calendar is not active yet.',
            ! $hasDeclaration => 'Share when you can teach so the Registrar can schedule your classes.',
            $needsGridReview => 'Teaching hours changed. Review and update your selected times.',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeWindows => count($availabilityStatus['teaching_windows'] ?? []).' '.Str::plural('weekly window', count($availabilityStatus['teaching_windows'] ?? [])).' saved.',
            default => 'Saved for this term.',
        };

        $otherTermActionableCount = $allRosters
            ->filter(fn (GradeRoster $roster): bool => (int) $roster->termOffering?->term_id !== (int) $term->id
                && $this->isRosterActionable($roster, $gradeWindow))
            ->count();

        $scopeMetaParts = [
            $term->starts_on->format('M j').' – '.$term->ends_on->format('M j, Y'),
            $hasActiveCoreCalendar ? 'Term calendar active' : 'Term calendar not active',
        ];

        $totalClassCount = $scheduleClassCount + $externallyArrangedCount;

        return [
            'terms' => $terms,
            'term' => $term,
            'scopeMeta' => implode(' · ', $scopeMetaParts),
            'termGateAttention' => [],
            'actionItems' => $actionItems,
            'priorityCard' => $priorityCard,
            'scheduleCard' => [
                'totalClassCount' => $totalClassCount,
                'classCount' => $scheduleClassCount,
                'meetingCount' => $scheduleMeetingCount,
                'externallyArrangedCount' => $externallyArrangedCount,
                'affectedCount' => $affectedMeetingsCount,
                'examPeriodLabel' => $examinationPeriod
                    ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y')
                    : null,
                'url' => FacultySchedule::getUrl(['termId' => $term->id]),
            ],
            'availabilityCard' => [
                'statusLabel' => $availabilityStatusLabel,
                'description' => $availabilityDescription,
                'replyBy' => $canSubmitAvailability ? $replyByFormatted : null,
                'hasDeclaration' => $hasDeclaration,
                'needsReview' => $canSubmitAvailability && $needsGridReview,
                'url' => MyAvailability::getUrl(['termId' => $term->id]),
                'ctaLabel' => ($isTermClosed || $registrarNotTeaching)
                    ? 'View availability'
                    : ($hasDeclaration ? 'Update availability' : 'Submit availability'),
            ],
            'gradesCard' => [
                'actionableCount' => $actionableClassCount,
                'assignedCount' => $assignedClassCount,
                'otherTermActionableCount' => $otherTermActionableCount,
                'openIncCount' => $termOpenIncCount,
                'url' => $actionableClassCount > 0
                    ? FacultyGradeRoster::getUrl(['queue' => 'needs-action', 'termId' => $term->id])
                    : FacultyGradeRoster::getUrl(['termId' => $term->id]),
            ],
        ];
    }

    private function isRosterActionable(GradeRoster $roster, GradeWindowService $gradeWindow): bool
    {
        if (! in_array($roster->state, [
            GradeRoster::StateReturned,
            GradeRoster::StateDraft,
            GradeRoster::StateLateNotSubmitted,
        ], true)) {
            return false;
        }

        return $roster->state === GradeRoster::StateReturned
            || $gradeWindow->isOpen($roster, LateGradeAuthorization::PeriodFinal);
    }

    /**
     * @param  Collection<int, GradeRoster>  $releasedRosters
     * @return Collection<int, array{roster: GradeRoster, term_id: int, course_section: string, term_label: string, count: int}>
     */
    private function openIncClasses(Collection $releasedRosters, User $user, IncDeadlineService $incDeadlines): Collection
    {
        return $releasedRosters
            ->filter(fn (GradeRoster $roster): bool => $roster->teachingAssignment?->state === ClassOfferingTeachingAssignment::StateActive
                && $roster->teachingAssignment?->role === ClassOfferingTeachingAssignment::RoleDesignated
                && (int) $roster->teachingAssignment?->faculty_user_id === (int) $user->id)
            ->map(function (GradeRoster $roster) use ($incDeadlines): ?array {
                $openCount = $roster->rows
                    ->filter(fn (GradeRosterRow $row): bool => $row->current_outcome_code === 'INC'
                        && $row->latestReleasedOutcome?->result_code === 'INC'
                        && ! $row->latestReleasedOutcome->completionSubmissions->contains('state', IncCompletionSubmission::StateSubmitted)
                        && $incDeadlines->state($row->latestReleasedOutcome) === IncDeadlineService::StateCompletionOpen)
                    ->count();

                if ($openCount === 0) {
                    return null;
                }

                return [
                    'roster' => $roster,
                    'term_id' => (int) ($roster->termOffering?->term_id ?? 0),
                    'course_section' => $this->rosterCourseSectionLabel($roster),
                    'term_label' => $this->rosterTermLabel($roster),
                    'count' => $openCount,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, GradeRoster>  $allRosters
     * @param  Collection<int, array{roster: GradeRoster, term_id: int, course_section: string, term_label: string, count: int}>  $openIncClasses
     * @return list<array{label: string, count?: int, href: string, icon: string}>
     */
    private function termGateAttentionItems(Collection $allRosters, Collection $openIncClasses, GradeWindowService $gradeWindow): array
    {
        $items = [];

        $returnedCount = $allRosters->where('state', GradeRoster::StateReturned)->count();
        if ($returnedCount > 0) {
            $items[] = [
                'label' => 'Returned class grades',
                'count' => $returnedCount,
                'href' => FacultyGradeRoster::getUrl(['queue' => 'needs-action']),
                'icon' => 'heroicon-o-exclamation-circle',
            ];
        }

        $overdueOpenCount = $allRosters
            ->filter(fn (GradeRoster $roster): bool => $roster->state === GradeRoster::StateLateNotSubmitted
                && $gradeWindow->isOpen($roster, LateGradeAuthorization::PeriodFinal))
            ->count();
        if ($overdueOpenCount > 0) {
            $items[] = [
                'label' => 'Overdue class grades ready for entry',
                'count' => $overdueOpenCount,
                'href' => FacultyGradeRoster::getUrl(['queue' => 'needs-action']),
                'icon' => 'heroicon-o-academic-cap',
            ];
        }

        $openIncTotal = (int) $openIncClasses->sum('count');
        if ($openIncTotal > 0) {
            $items[] = [
                'label' => 'Open INC completions',
                'count' => $openIncTotal,
                'href' => FacultyGradeRoster::getUrl(['queue' => 'inc']),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, GradeRoster>  $allRosters
     * @param  Collection<int, array{roster: GradeRoster, term_id: int, course_section: string, term_label: string, count: int}>  $openIncClasses
     * @param  list<array<string, mixed>>  $affectedMeetings
     * @return list<array{badge: string, severity: string, title: string, summary: string, action_label: string, action_url: string, action_color: string}>
     */
    private function buildActionItems(
        Term $term,
        Collection $allRosters,
        Collection $openIncClasses,
        GradeWindowService $gradeWindow,
        bool $hasActiveCoreCalendar,
        bool $hasDeclaration,
        bool $needsGridReview,
        ?string $replyByFormatted,
        array $affectedMeetings,
    ): array {
        $items = [];

        $orderedRosters = $allRosters
            ->sortBy(fn (GradeRoster $roster): array => [
                (int) $roster->termOffering?->term_id === (int) $term->id ? 0 : 1,
                $roster->state === GradeRoster::StateReturned ? 0 : 1,
                $roster->id,
            ])
            ->values();

        foreach ($orderedRosters as $roster) {
            $otherTermLabel = (int) $roster->termOffering?->term_id !== (int) $term->id
                ? $this->rosterTermLabel($roster)
                : null;

            if ($roster->state === GradeRoster::StateReturned) {
                $items[] = [
                    'badge' => 'Changes requested',
                    'severity' => 'warning',
                    'title' => $this->rosterCourseSectionLabel($roster),
                    'summary' => collect([
                        filled($roster->return_reason) ? 'Return note: '.$roster->return_reason : 'Returned by the Registrar for grade corrections.',
                        $otherTermLabel,
                    ])->filter()->implode(' · '),
                    'action_label' => 'Fix returned grades',
                    'action_url' => FacultyGradeRoster::getUrl(['roster' => $roster->id]),
                    'action_color' => 'primary',
                ];

                continue;
            }

            if ($roster->state === GradeRoster::StateLateNotSubmitted
                && $gradeWindow->isOpen($roster, LateGradeAuthorization::PeriodFinal)) {
                $items[] = [
                    'badge' => 'Submission overdue',
                    'severity' => 'warning',
                    'title' => $this->rosterCourseSectionLabel($roster),
                    'summary' => collect([
                        'Grade entry is overdue and open for submission.',
                        $otherTermLabel,
                    ])->filter()->implode(' · '),
                    'action_label' => 'Enter grades',
                    'action_url' => FacultyGradeRoster::getUrl(['roster' => $roster->id]),
                    'action_color' => 'primary',
                ];
            }
        }

        if ($hasActiveCoreCalendar && ! $hasDeclaration) {
            $items[] = [
                'badge' => 'Availability needed',
                'severity' => 'warning',
                'title' => 'No availability submitted for '.$term->label,
                'summary' => collect([
                    'Submit your teaching availability so the Registrar can plan your class assignments.',
                    $replyByFormatted ? 'Reply by '.$replyByFormatted : null,
                ])->filter()->implode(' · '),
                'action_label' => 'Submit availability',
                'action_url' => MyAvailability::getUrl(['termId' => $term->id]),
                'action_color' => 'gray',
            ];
        } elseif ($hasActiveCoreCalendar && $needsGridReview) {
            $items[] = [
                'badge' => 'Review needed',
                'severity' => 'warning',
                'title' => 'Teaching times outside updated hours',
                'summary' => collect([
                    'One or more saved teaching windows fall outside the updated approved teaching grid.',
                    $replyByFormatted ? 'Reply by '.$replyByFormatted : null,
                ])->filter()->implode(' · '),
                'action_label' => 'Update times',
                'action_url' => MyAvailability::getUrl(['termId' => $term->id]),
                'action_color' => 'gray',
            ];
        }

        foreach ($affectedMeetings as $meeting) {
            $courseSection = collect([
                $meeting['course_code'] ?? null,
                $meeting['course_title'] ?? null,
                $meeting['section_code'] ?? null,
            ])->filter()->implode(' · ') ?: 'Official class meeting';

            $dayTime = trim(($meeting['day_label'] ?? '').' · '.($meeting['time_label'] ?? ''), ' ·');
            $isLateImpact = ($meeting['source'] ?? null) === 'late_availability_impact';

            $items[] = [
                'badge' => $isLateImpact
                    ? 'Availability conflict'
                    : (string) ($meeting['change_type_label'] ?? 'Schedule revision'),
                'severity' => $isLateImpact ? 'warning' : 'info',
                'title' => $courseSection,
                'summary' => $isLateImpact
                    ? collect([$dayTime, 'Outside your updated availability. Current meetings stay official until revised.'])->filter()->implode(' · ')
                    : collect([
                        $dayTime,
                        isset($meeting['effective_date']) && method_exists($meeting['effective_date'], 'format')
                            ? 'Effective '.$meeting['effective_date']->format('M j, Y')
                            : null,
                        filled($meeting['reason'] ?? null) ? (string) $meeting['reason'] : null,
                    ])->filter()->implode(' · '),
                'action_label' => 'View schedule',
                'action_url' => FacultySchedule::getUrl(['termId' => $term->id]),
                'action_color' => 'gray',
            ];
        }

        if ($openIncClasses->isNotEmpty()) {
            $orderedInc = $openIncClasses
                ->sortBy(fn (array $entry): int => $entry['term_id'] === (int) $term->id ? 0 : 1)
                ->values();

            foreach ($orderedInc as $entry) {
                $otherTermLabel = $entry['term_id'] !== (int) $term->id ? $entry['term_label'] : null;

                $items[] = [
                    'badge' => 'INC completion open',
                    'severity' => 'info',
                    'title' => $entry['course_section'],
                    'summary' => collect([
                        $entry['count'].' '.Str::plural('student', $entry['count']).' ready for INC completion submission.',
                        $otherTermLabel,
                    ])->filter()->implode(' · '),
                    'action_label' => 'Submit INC completion',
                    'action_url' => $orderedInc->count() === 1
                        ? FacultyGradeRoster::getUrl(['roster' => $entry['roster']->id])
                        : FacultyGradeRoster::getUrl(['queue' => 'inc']),
                    'action_color' => 'gray',
                ];
            }
        }

        return $items;
    }

    private function rosterCourseSectionLabel(GradeRoster $roster): string
    {
        $specification = $roster->termOffering?->curriculumEntry?->courseSpecification;

        return collect([
            $specification?->course?->code,
            $specification?->title,
            $roster->section?->code,
        ])->filter()->implode(' · ') ?: 'Assigned class';
    }

    private function rosterTermLabel(GradeRoster $roster): string
    {
        $rosterTerm = $roster->termOffering?->term;

        return collect([
            $rosterTerm?->label,
            $rosterTerm?->academicYear?->label,
        ])->filter()->implode(' · ');
    }
}
