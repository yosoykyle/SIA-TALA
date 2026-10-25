<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use Livewire\Attributes\Computed;
use UnitEnum;

class TermOverview extends TermPlanningWorkbench
{
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
        $timetable = ['official' => null, 'attempt' => null];
        $counts = ['recorded' => 0, 'confirmed' => 0, 'current' => 0, 'student_sections' => 0];

        if (! $data['term']) {
            return $data + ['overviewWork' => $work, 'overviewWaiting' => $waiting, 'overviewTimetable' => $timetable, 'overviewCounts' => $counts];
        }

        $parameters = ['termId' => $data['term']->id];
        $readOnly = $data['readOnly'];
        $calendarUrl = TermCalendar::getUrl($parameters);
        $classesUrl = TermClasses::getUrl($parameters);
        $sectionsUrl = TermClasses::getUrl([...$parameters, 'classesView' => 'cohorts']);
        $resourcesUrl = TermResources::getUrl($parameters);
        $resultUrl = TermTimetable::getUrl($parameters);
        $counts = [
            'recorded' => $data['sourceClasses']->count(),
            'confirmed' => $data['sourceClasses']->whereNotNull('confirmed_at')->count(),
            'current' => $data['contextClasses']->count(),
            'student_sections' => $data['cohorts']->count(),
        ];

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
        if ($data['missingDeclarations'] > 0) {
            $requests = OperationalEvent::query()->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)
                ->where('related_record_type', TermCalendarPackage::class)
                ->whereIn('related_record_id', $data['activePackage']?->compatibleCoreSourceIds() ?? [])
                ->latest('id')->get()->unique('user_id')->keyBy('user_id');
            $missingResponses = $data['facultyRecords']->filter(fn (array $row): bool => $row['declaration'] === null);
            $awaiting = $missingResponses->filter(function (array $row) use ($requests): bool {
                $request = $requests->get($row['faculty']->id);

                return $request?->status === OperationalEvent::StatusProcessed && $request->sent_at !== null;
            })->count();
            $unrequested = $missingResponses->count() - $awaiting;
            if (! $data['facultyDeadline']) {
                $work[] = $this->overviewEntry('deadline', $readOnly ? 'Inspect reply deadline' : 'Set a reply deadline', 'Record a reply deadline before requesting Faculty availability.', $resourcesUrl, 'heroicon-o-clock');
            } elseif ($unrequested > 0) {
                $work[] = $this->overviewEntry('availability', $readOnly ? 'Inspect availability requests' : 'Review availability requests', $unrequested.' '.str('teacher')->plural($unrequested).' '.($unrequested === 1 ? 'has' : 'have').' no delivered request yet. Check request delivery.', $resourcesUrl, 'heroicon-o-envelope');
            }
            if ($awaiting > 0) {
                $waiting[] = $this->overviewEntry('availability', 'Waiting for Faculty availability', $awaiting.' '.str('teacher')->plural($awaiting).' '.($awaiting === 1 ? 'has' : 'have').' not replied.', $resourcesUrl, 'heroicon-o-clock');
            }
        }

        $readiness = $this->generationReadiness();
        if (! $readiness['ready'] && ($readiness['tab'] ?? null) === 'resources' && $data['missingQualifications'] === 0) {
            $work[] = $this->overviewEntry('resources', 'Review teaching resources', $readiness['reason'], $resourcesUrl, 'heroicon-o-building-office');
        }

        if ($version = $data['currentVersion']) {
            $timetable['official'] = ['state' => 'official', 'label' => 'Official timetable v'.$version->version, 'description' => 'The published timetable remains official while separate work is prepared.', 'url' => TermTimetable::getUrl([...$parameters, 'selectedVersionId' => $version->id])];
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

        return $data + ['overviewWork' => $work, 'overviewWaiting' => $waiting, 'overviewTimetable' => $timetable, 'overviewCounts' => $counts];
    }

    /** @return array{key:string,label:string,description:string,url:string,icon:string} */
    private function overviewEntry(string $key, string $label, string $description, string $url, string $icon): array
    {
        return compact('key', 'label', 'description', 'url', 'icon');
    }

    #[Computed]
    public function coverage(): ?array
    {

        $term = $this->termId ? Term::find($this->termId) : null;

        return $term ? app(TermCoverageProjection::class)->forTerm($term) : null;

    }
}
