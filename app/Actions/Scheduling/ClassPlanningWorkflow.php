<?php

namespace App\Actions\Scheduling;

use App\Filament\Pages\AcademicReadiness;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Support\Str;

class ClassPlanningWorkflow
{
    public function __construct(
        private readonly TermSchedulingReadinessService $readiness,
    ) {}

    /**
     * @return array{
     *     is_ready: bool,
     *     counts: array{offerings:int,sections:int,qualified_faculty:int,rooms:int,requirements:int,ready_requirements:int,schedule_runs:int,published_meetings:int},
     *     stages: list<array{key:string,title:string,description:string,status:string,color:string,summary:string,blocker:string,owner:string,action_label:string,action_url:string}>
     * }
     */
    public function present(Term $term): array
    {
        $readiness = $this->readiness->evaluateTerm($term);
        $counts = $this->counts($term);
        $latestRun = ScheduleGenerationRun::query()
            ->where('term_id', $term->getKey())
            ->withCount('candidateRows')
            ->latest('created_at')
            ->first();

        $prerequisitesReady = $readiness['missing_term_fields'] === [];
        $offeringsReady = $prerequisitesReady
            && $counts['offerings'] > 0
            && $counts['sections'] > 0
            && $readiness['section_issues'] === []
            && $readiness['delivery_group_issues'] === [];
        $resourcesReady = $offeringsReady
            && $readiness['faculty_input_issues'] === []
            && $readiness['room_input_issues'] === [];
        $requirementsReady = $resourcesReady
            && $counts['requirements'] > 0
            && $counts['requirements'] === $counts['ready_requirements'];
        $hasPublishedTimetable = $counts['published_meetings'] > 0;
        $teachingResourceAction = $this->teachingResourceAction(
            $readiness['faculty_input_issues'],
            $readiness['room_input_issues'],
        );

        return [
            'is_ready' => $requirementsReady,
            'counts' => $counts,
            'stages' => [
                $this->stage(
                    key: 'prerequisites',
                    title: 'Prerequisites',
                    description: 'Confirm the term, operating calendar, active curricula, and academic source records before building classes.',
                    ready: $prerequisitesReady,
                    summary: $prerequisitesReady
                        ? 'The term and recurring scheduling window are ready.'
                        : 'Academic or term setup still needs attention.',
                    blocker: $this->missingFieldsMessage($readiness['missing_term_fields']),
                    actionLabel: 'Complete academic and term setup',
                    actionUrl: AcademicReadiness::getUrl(),
                ),
                $this->stage(
                    key: 'offerings',
                    title: 'Offerings and Sections',
                    description: 'Create the subjects taught this term, their regular sections, capacities, and delivery groups.',
                    ready: $offeringsReady,
                    summary: "{$counts['offerings']} offerings and {$counts['sections']} sections recorded.",
                    blocker: $this->sourceIssueMessage(
                        $prerequisitesReady,
                        $counts['offerings'],
                        $readiness['section_issues'],
                        $readiness['delivery_group_issues'],
                    ),
                    actionLabel: 'Review offerings and sections',
                    actionUrl: TermOfferingResource::getUrl('index', [
                        'filters' => ['term' => ['value' => $term->getKey()]],
                    ]),
                ),
                $this->stage(
                    key: 'resources',
                    title: 'Teaching Resources',
                    description: 'Confirm qualified faculty, approved load limits, rooms, and recurring availability before generation.',
                    ready: $resourcesReady,
                    summary: "{$counts['qualified_faculty']} qualified faculty and {$counts['rooms']} active rooms are available.",
                    blocker: $this->resourceIssueMessage(
                        $offeringsReady,
                        $readiness['faculty_input_issues'],
                        $readiness['room_input_issues'],
                    ),
                    actionLabel: $teachingResourceAction['label'],
                    actionUrl: $teachingResourceAction['url'],
                ),
                $this->stage(
                    key: 'requirements',
                    title: 'Schedule Requirements',
                    description: 'Generate and resolve the required class components that the timetable generator must assign.',
                    ready: $requirementsReady,
                    summary: "{$counts['ready_requirements']} of {$counts['requirements']} schedule requirements are ready.",
                    blocker: $this->requirementsBlocker($counts, $resourcesReady),
                    actionLabel: 'Review schedule requirements',
                    actionUrl: SchedulingDemandResource::getUrl('index', [
                        'filters' => ['term_id' => ['value' => $term->getKey()]],
                    ]),
                ),
                $this->generatedStage($latestRun, $requirementsReady, (int) $term->getKey()),
                $this->stage(
                    key: 'published',
                    title: 'Published Timetable',
                    description: 'Only explicitly published, active meetings become the official timetable seen by students and faculty.',
                    ready: $hasPublishedTimetable,
                    summary: $hasPublishedTimetable
                        ? "{$counts['published_meetings']} official meetings are published."
                        : 'No official timetable is published for this term.',
                    blocker: $hasPublishedTimetable
                        ? 'None. Future changes must use the controlled revision workflow.'
                        : 'A validated candidate must be reviewed and explicitly published by the Registrar.',
                    actionLabel: 'View published timetable',
                    actionUrl: SectionMeetingResource::getUrl('index', [
                        'filters' => ['term_id' => ['value' => $term->getKey()]],
                    ]),
                ),
            ],
        ];
    }

    /**
     * @return array{offerings:int,sections:int,qualified_faculty:int,rooms:int,requirements:int,ready_requirements:int,schedule_runs:int,published_meetings:int}
     */
    private function counts(Term $term): array
    {
        $termId = $term->getKey();
        $requirements = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $termId));

        return [
            'offerings' => TermOffering::query()->where('term_id', $termId)->count(),
            'sections' => Section::query()
                ->whereHas('termOffering', fn ($query) => $query->where('term_id', $termId))
                ->count(),
            'qualified_faculty' => FacultyQualification::query()
                ->active()
                ->distinct()
                ->count('faculty_user_id'),
            'rooms' => Room::query()->where('is_active', true)->count(),
            'requirements' => (clone $requirements)->count(),
            'ready_requirements' => (clone $requirements)
                ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                ->count(),
            'schedule_runs' => ScheduleGenerationRun::query()->where('term_id', $termId)->count(),
            'published_meetings' => SectionMeeting::query()
                ->activeOfficial()
                ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $termId))
                ->count(),
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    private function missingFieldsMessage(array $fields): string
    {
        if ($fields === []) {
            return 'None.';
        }

        return 'Complete: '.collect($fields)
            ->map(fn (string $field): string => Str::headline($field))
            ->implode(', ').'.';
    }

    /**
     * @param  list<array<string, mixed>>  $sectionIssues
     * @param  list<array<string, mixed>>  $deliveryGroupIssues
     */
    private function sourceIssueMessage(
        bool $prerequisitesReady,
        int $offeringCount,
        array $sectionIssues,
        array $deliveryGroupIssues,
    ): string {
        if (! $prerequisitesReady) {
            return 'Complete the academic and term prerequisites first.';
        }

        if ($offeringCount === 0) {
            return 'No term offerings are recorded.';
        }

        $issueCount = count($sectionIssues) + count($deliveryGroupIssues);

        return $issueCount === 0
            ? 'None.'
            : "{$issueCount} section or delivery-group records need correction.";
    }

    /**
     * @param  list<array<string, mixed>>  $facultyIssues
     * @param  list<array<string, mixed>>  $roomIssues
     */
    private function resourceIssueMessage(
        bool $offeringsReady,
        array $facultyIssues,
        array $roomIssues,
    ): string {
        if (! $offeringsReady) {
            return 'Complete offerings and sections first.';
        }

        if ($facultyIssues === [] && $roomIssues === []) {
            return 'None.';
        }

        return sprintf(
            '%d faculty-input and %d room-input issues need correction.',
            count($facultyIssues),
            count($roomIssues),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $facultyIssues
     * @param  list<array<string, mixed>>  $roomIssues
     * @return array{label:string,url:string}
     */
    private function teachingResourceAction(array $facultyIssues, array $roomIssues): array
    {
        $missingFacultyInputs = collect($facultyIssues)
            ->flatMap(fn (array $issue): array => is_array($issue['missing_inputs'] ?? null)
                ? $issue['missing_inputs']
                : [])
            ->all();

        if (in_array('active_faculty_qualification', $missingFacultyInputs, true)) {
            return [
                'label' => 'Review faculty qualifications',
                'url' => FacultyQualificationResource::getUrl('index'),
            ];
        }

        if (in_array('missing_default_faculty_load', $missingFacultyInputs, true)) {
            return [
                'label' => 'Review faculty term loads',
                'url' => FacultyTermLoadOverrideResource::getUrl('index'),
            ];
        }

        if ($roomIssues !== []) {
            return [
                'label' => 'Review rooms',
                'url' => RoomResource::getUrl('index'),
            ];
        }

        return [
            'label' => 'Review teaching resources',
            'url' => FacultyQualificationResource::getUrl('index'),
        ];
    }

    /**
     * @param  array{requirements:int,ready_requirements:int}  $counts
     */
    private function requirementsBlocker(array $counts, bool $resourcesReady): string
    {
        if (! $resourcesReady) {
            return 'Complete offerings, sections, and teaching-resource inputs first.';
        }

        if ($counts['requirements'] === 0) {
            return 'Generate the term schedule requirements before requesting a timetable.';
        }

        $needsReview = $counts['requirements'] - $counts['ready_requirements'];

        return $needsReview === 0
            ? 'None.'
            : "{$needsReview} schedule requirements still need source-data correction.";
    }

    /**
     * @return array{key:string,title:string,description:string,status:string,color:string,summary:string,blocker:string,owner:string,action_label:string,action_url:string}
     */
    private function generatedStage(
        ?ScheduleGenerationRun $run,
        bool $requirementsReady,
        int $termId,
    ): array {
        if (! $run instanceof ScheduleGenerationRun) {
            return $this->stage(
                key: 'generated',
                title: 'Generated Timetables',
                description: 'Request generation only after readiness passes, then review assignment coverage, hard-rule validation, warnings, and solution quality.',
                ready: $requirementsReady,
                summary: 'No timetable-generation request has been recorded.',
                blocker: $requirementsReady
                    ? 'None. The Registrar may request timetable generation.'
                    : 'All schedule requirements must be ready before generation.',
                actionLabel: 'Generate timetable',
                actionUrl: ScheduleGenerationRunResource::getUrl('index', [
                    'filters' => ['term_id' => ['value' => $termId]],
                ]),
            );
        }

        $status = ScheduleGenerationRun::statusOptions()[$run->status] ?? Str::headline($run->status);
        $candidateCount = (int) ($run->candidate_rows_count ?? 0);
        $ready = in_array($run->status, [
            ScheduleGenerationRun::StatusUnderReview,
            ScheduleGenerationRun::StatusPublished,
        ], true);

        return [
            ...$this->stage(
                key: 'generated',
                title: 'Generated Timetables',
                description: 'Request generation only after readiness passes, then review assignment coverage, hard-rule validation, warnings, and solution quality.',
                ready: $ready,
                summary: "Latest request: {$status}; {$candidateCount} candidate assignments.",
                blocker: match ($run->status) {
                    ScheduleGenerationRun::StatusBlocked => 'The latest candidate is blocked. Review its validation findings.',
                    ScheduleGenerationRun::StatusFailed => 'The latest generation request failed. Review the recorded failure before retrying.',
                    ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching => 'Generation is still in progress.',
                    default => 'None.',
                },
                actionLabel: 'Review generated timetable',
                actionUrl: ScheduleGenerationRunResource::getUrl('view', ['record' => $run]),
            ),
            'status' => $status,
            'color' => ScheduleGenerationRun::statusColors()[$run->status] ?? 'gray',
        ];
    }

    /**
     * @return array{key:string,title:string,description:string,status:string,color:string,summary:string,blocker:string,owner:string,action_label:string,action_url:string}
     */
    private function stage(
        string $key,
        string $title,
        string $description,
        bool $ready,
        string $summary,
        string $blocker,
        string $actionLabel,
        string $actionUrl,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'status' => $ready ? 'Ready' : 'Blocked',
            'color' => $ready ? 'success' : 'warning',
            'summary' => $summary,
            'blocker' => $blocker,
            'owner' => 'Registrar',
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
        ];
    }
}
