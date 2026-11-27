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
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\FacultyActiveTermResolver;
use App\Support\TimetablePresentation;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

class FacultyOverview extends Page
{
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

        $user = auth()->user();
        $this->termId = app(FacultyActiveTermResolver::class)->resolveId($user instanceof User ? $user : null);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $termResolver = app(FacultyActiveTermResolver::class);
        $term = $this->termId !== null
            ? Term::query()->with('academicYear')->find($this->termId)
            : $termResolver->resolve($user);

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
                'term' => null,
                'scopeTitle' => null,
                'scopeMeta' => null,
                'noTermAttention' => $this->noTermAttentionItems($allRosters, $openIncClasses, $gradeWindow),
                'actionItems' => [],
                'schedulePresentation' => null,
                'scheduleSummary' => null,
                'externallyArrangedClasses' => [],
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
        $hasDeclaration = (bool) ($availabilityStatus['has_declaration'] ?? false);
        $needsGridReview = (bool) ($availabilityStatus['needs_grid_review'] ?? false);
        $isAvailabilityOpen = $hasActiveCoreCalendar && ! $isTermClosed && ! $registrarNotTeaching;
        $canSubmitAvailability = $isAvailabilityOpen && ! $hasDeclaration;
        $effectiveMode = $availabilityStatus['effective_mode'] ?? null;
        /** @var FacultyAvailabilityDeclaration|null $latestDeclaration */
        $latestDeclaration = $availabilityStatus['latest_declaration'] ?? null;

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
                'schedulingDemand.termOffering.term',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.courseComponent',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'faculty',
                'room',
            ])
            ->where('faculty_user_id', $user->id)
            ->whereHas('schedulingDemand.termOffering', fn (Builder $query): Builder => $query->where('term_id', $term->id))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $versionId = $officialMeetings->pluck('published_timetable_version_id')->filter()->first();
        $publishedVersion = $versionId !== null
            ? PublishedTimetableVersion::query()
                ->with(['scheduleRun', 'term.academicYear'])
                ->find($versionId)
            : null;
        $schedulePresentation = ($publishedVersion instanceof PublishedTimetableVersion && $officialMeetings->isNotEmpty())
            ? TimetablePresentation::forPublished($publishedVersion, $officialMeetings)
            : null;

        $scheduleMeetingCount = $officialMeetings->count();
        $scheduleClassCount = $officialMeetings
            ->pluck('schedulingDemand.sectionDeliveryGroup.section.id')
            ->filter()
            ->unique()
            ->count();

        $externallyArrangedClasses = $this->externallyArrangedClasses($term, $user);
        $externallyArrangedCount = count($externallyArrangedClasses);

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
            isAvailabilityOpen: $isAvailabilityOpen,
            hasDeclaration: $hasDeclaration,
            needsGridReview: $needsGridReview,
            replyByFormatted: $replyByFormatted,
            affectedMeetings: $affectedMeetings,
        );

        $availabilityStatusLabel = match (true) {
            $registrarNotTeaching => 'Not teaching (Registrar)',
            ! $hasDeclaration => 'Not submitted yet',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeWindows => 'Available on selected times',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeAnyApproved => 'Available during all teaching hours',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeCannotTeach => 'Unavailable this term',
            default => $latestDeclaration?->modeLabel() ?? 'Not submitted yet',
        };

        $windowCount = count($availabilityStatus['teaching_windows'] ?? []);
        $availabilityNote = match (true) {
            $registrarNotTeaching => filled($registrarNotTeachingReason)
                ? 'Marked not teaching (0 units): '.$registrarNotTeachingReason
                : 'Marked not teaching (0 units) by the Registrar for this term.',
            $isTermClosed => 'This term is closed. Availability is read-only.',
            ! $hasActiveCoreCalendar && ! $hasDeclaration => 'Term calendar is not active yet.',
            ! $hasDeclaration => $canSubmitAvailability && $replyByFormatted !== null
                ? 'Reply by '.$replyByFormatted
                : 'Share when you can teach so the Registrar can plan your classes.',
            $needsGridReview => 'Teaching hours changed after submission. Contact the Registrar if adjustments are needed.',
            $effectiveMode === FacultyAvailabilityDeclaration::ModeWindows => $windowCount.' '.Str::plural('weekly window', $windowCount).' saved · Locked',
            default => 'Submitted and locked for this term.',
        };

        $otherTermActionableCount = $allRosters
            ->filter(fn (GradeRoster $roster): bool => (int) $roster->termOffering?->term_id !== (int) $term->id
                && $this->isRosterActionable($roster, $gradeWindow))
            ->count();

        $gradesDescriptionParts = [
            $assignedClassCount.' assigned '.Str::plural('class', $assignedClassCount),
            $actionableClassCount > 0 ? $actionableClassCount.' needing action' : null,
        ];
        $gradesNoteParts = [
            $termOpenIncCount > 0 ? $termOpenIncCount.' open INC '.Str::plural('completion', $termOpenIncCount) : null,
            $otherTermActionableCount > 0 ? $otherTermActionableCount.' needing action in earlier terms' : null,
        ];
        $gradesNote = collect($gradesNoteParts)->filter()->implode(' · ') ?: 'View class masterlists, export CSV, or enter grades.';

        $totalClassCount = $scheduleClassCount + $externallyArrangedCount;

        return [
            'term' => $term,
            'scopeTitle' => $termResolver->scopeTitle($term),
            'scopeMeta' => $termResolver->scopeMeta($term, $hasActiveCoreCalendar),
            'noTermAttention' => [],
            'actionItems' => $actionItems,
            'schedulePresentation' => $schedulePresentation,
            'scheduleSummary' => [
                'totalClassCount' => $totalClassCount,
                'classCount' => $scheduleClassCount,
                'meetingCount' => $scheduleMeetingCount,
                'externallyArrangedCount' => $externallyArrangedCount,
                'affectedCount' => $affectedMeetingsCount,
                'url' => FacultySchedule::getUrl(),
                'academicCalendarUrl' => FacultyAcademicCalendar::getUrl(),
            ],
            'externallyArrangedClasses' => $externallyArrangedClasses,
            'availabilityCard' => [
                'statusLabel' => $availabilityStatusLabel,
                'note' => $availabilityNote,
                'hasDeclaration' => $hasDeclaration,
                'url' => MyAvailability::getUrl(),
            ],
            'gradesCard' => [
                'actionableCount' => $actionableClassCount,
                'assignedCount' => $assignedClassCount,
                'otherTermActionableCount' => $otherTermActionableCount,
                'openIncCount' => $termOpenIncCount,
                'description' => collect($gradesDescriptionParts)->filter()->implode(' · '),
                'note' => $gradesNote,
                'url' => $actionableClassCount > 0
                    ? FacultyGradeRoster::getUrl(['queue' => 'needs-action'])
                    : FacultyGradeRoster::getUrl(),
            ],
        ];
    }

    /**
     * @return list<array{course_code: string, course_title: string, section_code: string, role: string}>
     */
    private function externallyArrangedClasses(Term $term, User $user): array
    {
        return ClassOfferingTeachingAssignment::query()
            ->with([
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
            ])
            ->where('faculty_user_id', $user->id)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->whereHas('termOffering', fn (Builder $query): Builder => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->whereHas('curriculumEntry.courseSpecification', fn (Builder $specQuery): Builder => $specQuery
                    ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged)))
            ->where(function (Builder $query): void {
                $query->whereNull('section_id')
                    ->orWhereHas('section', fn (Builder $sectionQuery): Builder => $sectionQuery->where('state', '!=', Section::StateCancelled));
            })
            ->orderBy('id')
            ->get()
            ->map(function (ClassOfferingTeachingAssignment $assignment): array {
                $spec = $assignment->termOffering?->curriculumEntry?->courseSpecification;
                $course = $spec?->course;

                return [
                    'course_code' => (string) ($course?->code ?? 'Subject'),
                    'course_title' => (string) ($spec?->title ?? $course?->code ?? 'Externally arranged subject'),
                    'section_code' => (string) ($assignment->section?->code ?: 'Term offering'),
                    'role' => $assignment->role === ClassOfferingTeachingAssignment::RoleDesignated ? 'Designated instructor' : 'Co-faculty',
                ];
            })
            ->unique(fn (array $row): string => $row['course_code'].'|'.$row['section_code'].'|'.$row['role'])
            ->sortBy(['course_code', 'section_code'])
            ->values()
            ->all();
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
     * @return list<array{label: string, count: int, href: string}>
     */
    private function noTermAttentionItems(Collection $allRosters, Collection $openIncClasses, GradeWindowService $gradeWindow): array
    {
        $items = [];

        $returnedCount = $allRosters->where('state', GradeRoster::StateReturned)->count();
        if ($returnedCount > 0) {
            $items[] = [
                'label' => 'Returned class grades',
                'count' => $returnedCount,
                'href' => FacultyGradeRoster::getUrl(['queue' => 'needs-action']),
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
            ];
        }

        $openIncTotal = (int) $openIncClasses->sum('count');
        if ($openIncTotal > 0) {
            $items[] = [
                'label' => 'Open INC completions',
                'count' => $openIncTotal,
                'href' => FacultyGradeRoster::getUrl(['queue' => 'inc']),
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
        bool $isAvailabilityOpen,
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

        if ($isAvailabilityOpen && ! $hasDeclaration) {
            $items[] = [
                'badge' => 'Availability needed',
                'severity' => 'warning',
                'title' => 'No availability submitted for '.$term->label,
                'summary' => collect([
                    'Submit your teaching availability so the Registrar can plan your class assignments.',
                    $replyByFormatted ? 'Reply by '.$replyByFormatted : null,
                ])->filter()->implode(' · '),
                'action_label' => 'Submit availability',
                'action_url' => MyAvailability::getUrl(),
                'action_color' => 'gray',
            ];
        } elseif ($isAvailabilityOpen && $hasDeclaration && $needsGridReview) {
            $items[] = [
                'badge' => 'Hours changed',
                'severity' => 'info',
                'title' => 'Teaching hours changed after submission',
                'summary' => 'One or more saved teaching windows fall outside the updated approved teaching grid. Contact the Registrar if your availability needs adjustment.',
                'action_label' => 'View availability',
                'action_url' => MyAvailability::getUrl(),
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
                    ? collect([$dayTime, 'Outside your recorded availability. Current meetings stay official until revised by the Registrar.'])->filter()->implode(' · ')
                    : collect([
                        $dayTime,
                        isset($meeting['effective_date']) && method_exists($meeting['effective_date'], 'format')
                            ? 'Effective '.$meeting['effective_date']->format('M j, Y')
                            : null,
                        filled($meeting['reason'] ?? null) ? (string) $meeting['reason'] : null,
                    ])->filter()->implode(' · '),
                'action_label' => 'View schedule',
                'action_url' => FacultySchedule::getUrl(),
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
