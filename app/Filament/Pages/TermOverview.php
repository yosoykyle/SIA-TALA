<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermOffering;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class TermOverview extends TermPlanningWorkbench
{
    use WithPagination;

    #[Url]
    public string $coverageSearch = '';

    #[Url]
    public string $coverageYear = '';

    #[Url]
    public string $termSearch = '';

    #[Url]
    public string $termStateFilter = 'all';

    #[Url]
    public string $termAcademicYear = '';

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Term overview';

    protected static ?string $title = 'Term planning';

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = 0;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'plan';

    protected string $view = 'filament.pages.term-overview';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $data = parent::getViewData();
        $work = [];
        $waiting = [];
        $actionItems = [];
        $reassurance = null;
        $timetable = ['official' => null, 'attempt' => null];
        $counts = ['recorded' => 0, 'confirmed' => 0, 'current' => 0, 'student_sections' => 0];

        if (! $data['term']) {
            $allTerms = $data['terms'];
            $termStateCounts = [
                'all' => $allTerms->count(),
                Term::StateActive => $allTerms->where('state', Term::StateActive)->count(),
                Term::StateDraft => $allTerms->where('state', Term::StateDraft)->count(),
                Term::StateClosed => $allTerms->where('state', Term::StateClosed)->count(),
            ];
            $termAcademicYearOptions = $allTerms
                ->pluck('academicYear')
                ->filter()
                ->unique('id')
                ->sortByDesc('label')
                ->mapWithKeys(fn ($year): array => [(string) $year->id => (string) $year->label])
                ->all();
            $termClassCounts = Section::query()
                ->join('term_offerings', 'term_offerings.id', '=', 'sections.term_offering_id')
                ->where('term_offerings.state', '!=', TermOffering::StateCancelled)
                ->whereNotIn('sections.state', [Section::StateCancelled, Section::StateClosed])
                ->groupBy('term_offerings.term_id')
                ->selectRaw('term_offerings.term_id, count(sections.id) as aggregate')
                ->pluck('aggregate', 'term_offerings.term_id');

            $activeStateFilter = in_array($this->termStateFilter, ['all', Term::StateActive, Term::StateDraft, Term::StateClosed], true)
                ? $this->termStateFilter
                : 'all';
            $needle = mb_strtolower(trim($this->termSearch));
            $filteredTerms = $allTerms->filter(function (Term $availableTerm) use ($activeStateFilter, $needle): bool {
                if ($activeStateFilter !== 'all' && $availableTerm->state !== $activeStateFilter) {
                    return false;
                }

                if ($this->termAcademicYear !== '' && (string) ($availableTerm->academic_year_id ?? '') !== $this->termAcademicYear) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', array_filter([
                    $availableTerm->typeLabel(),
                    $availableTerm->label,
                    $availableTerm->academicYear?->label,
                    Term::stateOptions()[$availableTerm->state] ?? $availableTerm->state,
                ])));

                return str_contains($haystack, $needle);
            })->values();

            $perPage = 15;
            $page = min(max(1, $this->getPage('termsPage')), max(1, (int) ceil($filteredTerms->count() / $perPage)));
            $chooserPage = new LengthAwarePaginator(
                $filteredTerms->forPage($page, $perPage),
                $filteredTerms->count(),
                $perPage,
                $page,
                [
                    'path' => request()->url(),
                    'pageName' => 'termsPage',
                ],
            );

            return $data + [
                'overviewWork' => $work,
                'overviewWaiting' => $waiting,
                'overviewTimetable' => $timetable,
                'overviewCounts' => $counts,
                'overviewActionItems' => $actionItems,
                'overviewReassurance' => $reassurance,
                'chooserPage' => $chooserPage,
                'termStateCounts' => $termStateCounts,
                'termAcademicYearOptions' => $termAcademicYearOptions,
                'termClassCounts' => $termClassCounts,
                'activeTermStateFilter' => $activeStateFilter,
            ];
        }

        /** @var Term $term */
        $term = $data['term'];
        $parameters = ['termId' => $term->id];
        $readOnly = (bool) $data['readOnly'];
        $calendarUrl = TermCalendar::getUrl($parameters);
        $classesUrl = TermClasses::getUrl($parameters);
        $sectionsUrl = TermClasses::getUrl([...$parameters, 'classesView' => 'cohorts']);
        $resourcesUrl = TermResources::getUrl($parameters);
        $qualificationsUrl = FacultyQualificationResource::getUrl('index', $parameters);
        $resultUrl = TermTimetable::getUrl($parameters);
        $officialRevisionUrl = TermTimetable::getUrl([
            ...$parameters,
            'timetableState' => 'official',
            ...($data['currentVersion'] ? ['selectedVersionId' => $data['currentVersion']->id] : []),
        ]);

        $counts = [
            'recorded' => $data['sourceClasses']->count(),
            'confirmed' => $data['sourceClasses']->whereNotNull('confirmed_at')->count(),
            'current' => $data['contextClasses']->count(),
            'student_sections' => $data['cohorts']->count(),
        ];

        $availability = $data['availabilityProjection'] ?? [];
        $readinessProjection = $data['readiness'] ?? ['ready' => false, 'blockers' => [], 'advisories' => []];
        $tz = (string) config('app.display_timezone', 'Asia/Manila');

        foreach ($availability['unresolved_late_impacts'] ?? [] as $impact) {
            $meetingCount = count($impact['affected_meetings'] ?? []);
            $recordedAt = $impact['recorded_at']
                ? CarbonImmutable::parse($impact['recorded_at'])->timezone($tz)->format('M j, Y')
                : null;

            $actionItems[] = [
                'kind' => 'late_impact',
                'severity' => 'warning',
                'badge' => 'Affects the official timetable',
                'title' => $impact['faculty_name'].' changed availability after publication',
                'summary' => ($recordedAt ? "Changed {$recordedAt} · " : '')
                    .$meetingCount.' official '.str('meeting')->plural($meetingCount)
                    .' '.($meetingCount === 1 ? 'is' : 'are').' now outside '.$impact['mode_label'].'.',
                'reason' => $impact['reason'] ?? null,
                'meetings' => $impact['affected_meetings'] ?? [],
                'primary_action' => $readOnly
                    ? ['label' => 'Inspect official timetable', 'url' => $officialRevisionUrl, 'color' => 'gray']
                    : ['label' => 'Start timetable revision', 'url' => $officialRevisionUrl, 'color' => 'primary'],
                'secondary_action' => ['label' => 'View faculty', 'url' => $resourcesUrl],
            ];
        }

        foreach ($readinessProjection['blockers'] ?? [] as $blocker) {
            if (! in_array($blocker['code'], [
                'fixed_faculty_availability_missing',
                'fixed_faculty_cannot_teach',
                'fixed_faculty_no_usable_time',
                'faculty_demand_uncovered',
                'faculty_qualifications_missing',
            ], true)) {
                continue;
            }

            $isQualification = $blocker['code'] === 'faculty_qualifications_missing';

            $actionItems[] = [
                'kind' => 'teacher_blocker',
                'severity' => 'danger',
                'badge' => "Can't be scheduled",
                'title' => $blocker['reason'],
                'summary' => $readOnly ? 'Responsible: '.$blocker['owner'] : $blocker['next_action'],
                'reason' => null,
                'meetings' => [],
                'primary_action' => $isQualification
                    ? ['label' => $readOnly ? 'View qualifications' : 'Add qualification', 'url' => $qualificationsUrl, 'color' => 'gray']
                    : ['label' => 'Review faculty', 'url' => $resourcesUrl, 'color' => 'gray'],
                'secondary_action' => $isQualification
                    ? ['label' => 'Faculty & rooms', 'url' => $resourcesUrl]
                    : ['label' => 'Review classes', 'url' => $classesUrl],
            ];
        }

        if (($availability['needs_grid_review_count'] ?? 0) > 0) {
            $reviewNames = collect($availability['faculty_rows'] ?? [])
                ->where('needs_grid_review', true)
                ->pluck('name')
                ->values()
                ->all();

            $actionItems[] = [
                'kind' => 'grid_review',
                'severity' => 'warning',
                'badge' => 'Review needed',
                'title' => 'Teaching times outside updated hours for '.implode(', ', $reviewNames),
                'summary' => 'Chosen teaching windows fall outside the updated teaching grid or overlap a break. Outside hours remain excluded until updated on My Availability.',
                'reason' => null,
                'meetings' => [],
                'primary_action' => ['label' => 'Review faculty', 'url' => $resourcesUrl, 'color' => 'gray'],
                'secondary_action' => null,
            ];
        }

        $noResponseCount = (int) ($availability['no_response_count'] ?? 0);
        if ($actionItems === [] && ! empty($availability['has_active_core_calendar']) && $noResponseCount > 0) {
            $reassurance = [
                'heading' => 'No blockers in classes or faculty replies',
                'body' => $noResponseCount.' '.str('teacher')->plural($noResponseCount).' '
                    .($noResponseCount === 1 ? 'has' : 'have').' no response yet. '
                    .'Teachers with no response will not be scheduled for new assignments until they reply.',
                'action_label' => 'Faculty & rooms',
                'action_url' => $resourcesUrl,
            ];
        }

        if (! $data['activePackage'] || $data['draftPackages']->isNotEmpty()) {
            $work[] = $this->overviewEntry('calendar', $readOnly ? 'Inspect teaching calendar' : ($data['activePackage'] ? 'Review proposed calendar' : 'Set teaching calendar'), $data['activePackage'] ? 'An active calendar remains in use while the proposed calendar is reviewed.' : 'Record and activate this Term’s teaching dates and hours.', $calendarUrl, 'heroicon-o-calendar-days');
        }
        if ($counts['student_sections'] === 0) {
            $work[] = $this->overviewEntry('sections', $readOnly ? 'Inspect student sections' : 'Add student section', 'No student sections are recorded. The Registrar records the confirmed learner groups before linking classes.', $sectionsUrl, 'heroicon-o-user-group');
        }
        if ($counts['recorded'] === 0) {
            $work[] = $this->overviewEntry('classes', $readOnly ? 'Inspect class preparation' : 'Choose classes to offer', 'No active classes are recorded. Choose the courses that will run; unused years can stay empty.', ! $readOnly && $data['activePackage'] && $counts['student_sections'] > 0 ? TermClassProposal::getUrl($parameters) : $classesUrl, 'heroicon-o-book-open');
        } elseif ($counts['confirmed'] < $counts['recorded'] || $counts['current'] < $counts['recorded'] || collect(array_merge($this->coverage['rows'] ?? [], $this->coverage['additional'] ?? []))->contains(fn (array $row): bool => $row['class_count'] > 0 && $row['status'] !== 'Prepared')) {
            $work[] = $this->overviewEntry('classes', $readOnly ? 'Inspect class preparation' : 'Review and confirm classes', $counts['recorded'].' recorded · '.$counts['confirmed'].' confirmed. Review current calendar sources, student sections and meeting requirements.', ! $readOnly && $data['activePackage'] && $counts['student_sections'] > 0 && $counts['confirmed'] < $counts['recorded'] ? TermClassProposal::getUrl($parameters) : $classesUrl, 'heroicon-o-book-open');
        }
        if ($data['missingQualifications'] > 0) {
            $work[] = $this->overviewEntry('resources', $readOnly ? 'Inspect Faculty qualifications' : 'Review eligible teachers', $data['missingQualifications'].' course(s) have no active eligible Faculty record.', $resourcesUrl, 'heroicon-o-academic-cap');
        }
        if ($noResponseCount > 0) {
            $waiting[] = $this->overviewEntry('availability', 'Waiting for Faculty availability', $noResponseCount.' '.str('teacher')->plural($noResponseCount).' '.($noResponseCount === 1 ? 'has' : 'have').' no response yet.', $resourcesUrl, 'heroicon-o-clock');
        }

        $readiness = $this->generationReadiness();
        if (! $readiness['ready'] && ($readiness['tab'] ?? null) === 'resources' && $data['missingQualifications'] === 0) {
            $work[] = $this->overviewEntry('resources', 'Review teaching resources', $readiness['reason'], $resourcesUrl, 'heroicon-o-building-office');

            $hasFacultyBlocker = collect($actionItems)->contains(fn (array $item): bool => $item['kind'] === 'teacher_blocker');
            if (! $hasFacultyBlocker) {
                $roomsOnly = collect($readinessProjection['blockers'] ?? [])
                    ->pluck('code')
                    ->intersect(['rooms_unavailable', 'rooms_unsuitable'])
                    ->isNotEmpty();

                $actionItems[] = [
                    'kind' => 'resource_blocker',
                    'severity' => 'danger',
                    'badge' => "Can't be scheduled",
                    'title' => 'Review teaching resources',
                    'summary' => $readiness['reason'],
                    'reason' => null,
                    'meetings' => [],
                    'primary_action' => [
                        'label' => $roomsOnly ? 'Review rooms' : 'Review faculty & rooms',
                        'url' => $roomsOnly ? TermResources::getUrl([...$parameters, 'resourceView' => 'rooms']) : $resourcesUrl,
                        'color' => 'gray',
                    ],
                    'secondary_action' => null,
                ];
            }
        }

        if ($version = $data['currentVersion']) {
            $timetable['official'] = ['state' => 'official', 'label' => 'Official timetable'.($version->published_at ? ' · Published '.$version->published_at->timezone($tz)->format('M j, Y') : ''), 'description' => 'The published timetable remains official while separate work is prepared.', 'url' => TermTimetable::getUrl([...$parameters, 'selectedVersionId' => $version->id])];
        }
        $run = $data['activeRun'];
        if ($run && $run->status !== ScheduleGenerationRun::StatusPublished) {
            $state = in_array($run->status, [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching], true) ? 'pending' : ($run->status === ScheduleGenerationRun::StatusUnderReview ? strtolower($run->candidate_state ?: 'review') : $run->status);
            [$label, $description] = match ($state) {
                'pending' => ['Timetable generation in progress', 'This attempt is queued or running. Open progress for its current status.'],
                'accepted' => ['Accepted candidate', $this->canPublishActiveRun() ? 'Review publication of this accepted candidate. It is not official yet.' : 'This candidate is accepted. Inspect publication requirements; it is not official yet.'],
                'rejected' => ['Candidate rejected', 'The rejected candidate remains in history. Review its evidence before another attempt.'],
                'stale', 'superseded' => ['Candidate needs current sources', 'This result cannot be used as a current candidate. Inspect its recorded state and source changes.'],
                'failed', 'blocked' => ['Timetable attempt '.($state === 'failed' ? 'failed' : 'blocked'), $this->canRetryActiveRun() ? 'Inspect the problem and the permitted recovery action.' : 'Inspect the recorded problem and its responsible owner.'],
                default => ['Candidate under review', $this->canReviewActiveRun() ? 'Review the candidate before accepting or rejecting it.' : 'Inspect candidate evidence and its recorded review state.'],
            };
            $timetable['attempt'] = ['state' => $state, 'label' => $label, 'description' => $description, 'url' => TermTimetable::getUrl([...$parameters, 'selectedRunId' => $run->id])];
            $entry = $this->overviewEntry('result', $state === 'pending' ? 'View timetable progress' : 'Inspect timetable result', $description, $timetable['attempt']['url'], 'heroicon-o-table-cells');
            if ($state === 'pending') {
                $waiting[] = $entry;
            } else {
                $work[] = $entry;
            }
        } elseif (! $version && $readiness['ready'] && $this->retainedCandidateNextStep() === null) {
            $work[] = $this->overviewEntry('generate', $readOnly ? 'Inspect generation readiness' : 'Prepare timetable generation', 'Open Timetable to review whole-Term generation. Generation checks the captured sources.', $resultUrl, 'heroicon-o-table-cells');
        }

        return $data + [
            'overviewWork' => $work,
            'overviewWaiting' => $waiting,
            'overviewTimetable' => $timetable,
            'overviewCounts' => $counts,
            'overviewActionItems' => $actionItems,
            'overviewReassurance' => $reassurance,
        ];
    }

    /** @return array{key:string,label:string,description:string,url:string,icon:string} */
    private function overviewEntry(string $key, string $label, string $description, string $url, string $icon): array
    {
        return compact('key', 'label', 'description', 'url', 'icon');
    }

    public function setTermStateFilter(string $state): void
    {
        $this->termStateFilter = in_array($state, ['all', Term::StateActive, Term::StateDraft, Term::StateClosed], true)
            ? $state
            : 'all';
        $this->resetPage('termsPage');
    }

    public function updatedTermSearch(): void
    {
        $this->resetPage('termsPage');
    }

    public function updatedTermAcademicYear(): void
    {
        $this->resetPage('termsPage');
    }

    public function clearTermFilters(): void
    {
        $this->reset('termSearch', 'termAcademicYear');
        $this->termStateFilter = 'all';
        $this->resetPage('termsPage');
    }

    public function updatedCoverageSearch(): void
    {
        $this->resetPage('coveragePage');
    }

    public function updatedCoverageYear(): void
    {
        $this->resetPage('coveragePage');
    }

    public function clearCoverageFilters(): void
    {
        $this->reset('coverageSearch', 'coverageYear');
        $this->resetPage('coveragePage');
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function coverageEntries(): Collection
    {
        return collect([...($this->coverage['rows'] ?? []), ...($this->coverage['additional'] ?? [])])
            ->unique('id')
            ->sortBy(fn (array $row): string => sprintf('%d-%s-%s-%s', $row['class_count'] > 0 || $row['section_count'] > 0 ? 0 : 1, $row['program_name'], $row['curriculum_label'], $row['year_level'] ?? ''), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** @return Collection<string, string> */
    #[Computed]
    public function coverageYears(): Collection
    {
        return $this->coverageEntries->pluck('year_level')
            ->mapWithKeys(fn (?string $year): array => [$year ?? 'unassigned' => $year ?? 'Year not associated'])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function coveragePage(): LengthAwarePaginator
    {
        $needle = mb_strtolower(trim($this->coverageSearch));
        $rows = $this->coverageEntries->filter(fn (array $row): bool => ($needle === '' || str_contains(mb_strtolower($row['program_name'].' '.$row['curriculum_label'].' '.($row['curriculum_version_code'] ?? '')), $needle))
            && ($this->coverageYear === '' || ($row['year_level'] ?? 'unassigned') === $this->coverageYear)
        )->values();
        $page = min(max(1, $this->getPage('coveragePage')), max(1, (int) ceil($rows->count() / 10)));

        return new LengthAwarePaginator($rows->forPage($page, 10), $rows->count(), 10, $page, [
            'path' => request()->url(),
            'pageName' => 'coveragePage',
        ]);
    }

    #[Computed]
    public function coverage(): ?array
    {
        $term = $this->termId ? Term::find($this->termId) : null;

        return $term ? app(TermCoverageProjection::class)->forTerm($term) : null;
    }
}
