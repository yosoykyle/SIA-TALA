<?php

namespace App\Actions\Scheduling;

use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Models\CandidateScheduleRow;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class TimetableConflictDiagnosticService
{
    /** @var array<int, string> */
    private const DAY_SHORT = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /** @var array<int, string> */
    private const DAY_FULL = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * Diagnose scheduling conflicts and infeasibility causes for a timetable run in plain language.
     *
     * @return array{
     *     has_conflicts: bool,
     *     summary: string,
     *     items: list<array{
     *         category: string,
     *         severity: 'danger'|'warning',
     *         title: string,
     *         involved: list<string>,
     *         why: string,
     *         how_to_fix: list<string>,
     *         action_label: ?string,
     *         action_url: ?string,
     *     }>,
     * }
     */
    public function diagnose(ScheduleGenerationRun $run, ?Term $term = null): array
    {
        $resolvedTerm = $term ?? ($run->relationLoaded('term') ? $run->term : Term::query()->find($run->term_id));
        $termId = $resolvedTerm?->id ?? ($run->term_id !== null ? (int) $run->term_id : null);
        $snapshot = is_array($run->input_snapshot) ? $run->input_snapshot : [];
        $diagnostics = is_array($run->diagnostics) ? $run->diagnostics : [];

        if ($this->isCancelledOrTechnicalFailureOnly($run, $diagnostics)) {
            return [
                'has_conflicts' => false,
                'summary' => '',
                'items' => [],
            ];
        }

        $demands = $this->normalizedDemands($snapshot);

        $candidateRows = $run->relationLoaded('candidateRows')
            ? $run->candidateRows
            : $run->candidateRows()->with([
                'faculty:id,name',
                'room:id,code,name,room_type,capacity',
                'schedulingDemand.courseComponent.courseSpecification.course:id,code',
                'schedulingDemand.sectionDeliveryGroup.section.cohorts:id,reference',
                'schedulingDemand.termOffering:id,term_id,modality',
            ])->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')->get();

        $context = $this->buildEntityContext($snapshot, $demands, $candidateRows, $resolvedTerm);

        $items = [];
        $seenKeys = [];

        $addItem = function (string $dedupeKey, array $item) use (&$items, &$seenKeys): void {
            if (isset($seenKeys[$dedupeKey])) {
                return;
            }
            $seenKeys[$dedupeKey] = true;
            $item['involved'] = $this->limitInvolved($item['involved'] ?? []);
            $items[] = $item;
        };

        $isFailedOrInfeasible = $this->isFailedOrInfeasibleRun($run, $diagnostics);

        // (a) Unassignable demands from solver reasons, validation findings, rejected rows, and snapshot pre-checks
        $this->diagnoseUnassignableDemands($snapshot, $diagnostics, $demands, $context, $termId, $addItem);

        // (b) Fixed-slot / pinned collisions (teacher, room, section, calendar breaks, teacher unavailability)
        $this->diagnoseFixedSlotCollisions($snapshot, $demands, $context, $termId, $addItem);

        // (c) Faculty unit-load bottlenecks (exclusive demands & subject pools)
        $this->diagnoseFacultyUnitLoadBottlenecks($snapshot, $demands, $context, $termId, $addItem);

        // (d) Faculty time-capacity bottlenecks
        $this->diagnoseFacultyTimeCapacityBottlenecks($snapshot, $demands, $context, $termId, $addItem);

        // (e) Room-type / seat-capacity shortages
        $this->diagnoseRoomShortages($snapshot, $demands, $context, $termId, $addItem);

        // (f) Student-section overload
        $this->diagnoseStudentSectionOverload($snapshot, $demands, $context, $termId, $addItem);

        // (g) Calendar / break-time drift on Stale draft runs
        if ($run->candidate_state === 'Stale' && $resolvedTerm instanceof Term) {
            $this->diagnoseStaleCalendarDrift($run, $diagnostics, $candidateRows, $resolvedTerm, $context, $termId, $addItem);
        }

        // (h) Editable draft row-level conflicts (non-publishable rows & pairwise overlaps)
        if ($candidateRows->isNotEmpty()) {
            $this->diagnoseCandidateRowConflicts($run, $candidateRows, $demands, $context, $addItem);
        }

        // (i) Graceful fallback for complex multi-way CP-SAT infeasibility
        if ($items === [] && $isFailedOrInfeasible && $demands !== []) {
            $this->diagnoseMultiWayTightestConstraints($snapshot, $demands, $context, $termId, $addItem);
        }

        // Stale draft fallback when no specific break/row overlap triggered
        if ($items === [] && $run->candidate_state === 'Stale') {
            $staleReason = trim((string) ($diagnostics['stale_reason'] ?? $run->candidate_review_reason ?? ''));
            $why = $staleReason !== ''
                ? $staleReason
                : 'Calendar hours, classes, faculty availability, or rooms changed after this draft was generated.';
            $addItem('stale-source-drift', [
                'category' => 'Calendar break overlap',
                'severity' => 'warning',
                'title' => 'This draft is outdated because term planning inputs changed',
                'involved' => ['Draft Attempt #'.$run->id, 'Source records updated'],
                'why' => $why,
                'how_to_fix' => [
                    'Click New draft above to generate an updated timetable using the latest term inputs.',
                    'Review any changes in 1. Calendar, 2. Sections & classes, or 3. Faculty & rooms if needed.',
                ],
                'action_label' => 'Review 1. Calendar',
                'action_url' => $this->calendarUrl($termId),
            ]);
        }

        $count = count($items);
        $hasConflicts = $count > 0;

        $summary = '';
        if ($hasConflicts) {
            $noun = $count === 1 ? 'conflict' : 'conflicts';
            if ($isFailedOrInfeasible && $candidateRows->isEmpty()) {
                $summary = sprintf('%d scheduling %s prevented a valid timetable.', $count, $noun);
            } else {
                $issueNoun = $count === 1 ? 'issue needs' : 'issues need';
                $summary = sprintf('%d scheduling %s attention before publishing.', $count, $issueNoun);
            }
        }

        return [
            'has_conflicts' => $hasConflicts,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Resolve human-readable labels for snapshot and run entities (reused by Solver Report).
     *
     * @return array{
     *     demands: array<int, string>,
     *     faculty: array<int, string>,
     *     rooms: array<int, string>,
     *     cohorts: array<int, string>,
     * }
     */
    public function entityLabelsForRun(ScheduleGenerationRun $run): array
    {
        $snapshot = is_array($run->input_snapshot) ? $run->input_snapshot : [];
        $demands = $this->normalizedDemands($snapshot);
        $context = $this->buildEntityContext($snapshot, $demands, collect(), $run->term);

        return [
            'demands' => $context['demand_labels'],
            'faculty' => $context['faculty_names'],
            'rooms' => $context['room_labels'],
            'cohorts' => $context['cohort_labels'],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $diagnostics
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseUnassignableDemands(
        array $snapshot,
        array $diagnostics,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        $rooms = $this->normalizedRooms($snapshot);
        $teachingIntervals = $this->teachingIntervalsByDay($snapshot);
        $maxContinuousSlotMinutes = $this->maxContinuousOpenMinutes($teachingIntervals);

        // 1. Direct snapshot pre-checks on every demand so we catch root causes with exact entities
        foreach ($demands as $demandId => $demand) {
            $demandLabel = $this->demandLabel($demandId, $context);
            $courseCode = (string) ($demand['course_code'] ?? $context['demand_courses'][$demandId] ?? 'this subject');
            $eligibleFacultyIds = $this->intList($demand['eligible_faculty_user_ids'] ?? []);
            $fixedFacultyId = $this->nullableInt($demand['fixed_faculty_user_id'] ?? null);

            if ($eligibleFacultyIds === [] && $fixedFacultyId === null) {
                $addItem('unassignable-faculty:'.$demandId, [
                    'category' => 'Unassignable class',
                    'severity' => 'danger',
                    'title' => sprintf('%s has no available qualified teacher', $demandLabel),
                    'involved' => [$demandLabel, $courseCode],
                    'why' => sprintf('No active teacher qualified for %s has declared availability and available term load.', $courseCode),
                    'how_to_fix' => [
                        sprintf('Open 3. Faculty & rooms and qualify an active teacher for %s.', $courseCode),
                        'Confirm the teacher has submitted term availability and is not marked "Cannot teach".',
                    ],
                    'action_label' => 'Open 3. Faculty & rooms',
                    'action_url' => $this->resourcesUrl($termId, 'faculty'),
                ]);
            }

            $roomRequired = (bool) ($demand['room_required'] ?? (($demand['modality'] ?? '') === TermOffering::ModalityFaceToFace));
            if ($roomRequired) {
                $matchingRooms = $this->matchingRoomsForDemand($demand, $rooms);
                if ($matchingRooms === []) {
                    $reqType = (string) ($demand['room_type_requirement'] ?? '');
                    $roomTypeLabel = $this->roomTypeLabel($reqType);
                    $seatsNeeded = max(0, (int) ($demand['expected_count'] ?? 0));
                    $features = $this->stringList($demand['required_room_feature_keys'] ?? []);
                    $featureNote = $features !== [] ? ' with '.implode(', ', $features) : '';

                    $involved = [$demandLabel, sprintf('%s (%d seats needed)', $roomTypeLabel, $seatsNeeded)];
                    if ($features !== []) {
                        $involved[] = 'Equipment: '.implode(', ', $features);
                    }

                    $addItem('unassignable-room:'.$demandId, [
                        'category' => 'Room shortage',
                        'severity' => 'danger',
                        'title' => sprintf('%s has no matching active %s room', $demandLabel, strtolower($roomTypeLabel)),
                        'involved' => $involved,
                        'why' => sprintf(
                            'No active %s room%s can hold %d students for this on-campus class.',
                            strtolower($roomTypeLabel),
                            $featureNote,
                            $seatsNeeded,
                        ),
                        'how_to_fix' => [
                            sprintf('Add or update an active %s room with at least %d seats in 3. Faculty & rooms.', strtolower($roomTypeLabel), max(1, $seatsNeeded)),
                            'Or split the section / switch this class to Online delivery in 2. Sections & classes.',
                        ],
                        'action_label' => 'Open 3. Faculty & rooms (Rooms)',
                        'action_url' => $this->resourcesUrl($termId, 'rooms'),
                    ]);
                }
            }

            $durationMinutes = max(0, (int) ($demand['required_duration_minutes'] ?? 0));
            if ($maxContinuousSlotMinutes > 0 && $durationMinutes > $maxContinuousSlotMinutes) {
                $addItem('unassignable-slot-duration:'.$demandId, [
                    'category' => 'Calendar break overlap',
                    'severity' => 'danger',
                    'title' => sprintf('%s (%s) does not fit between daily breaks', $demandLabel, $this->formatDuration($durationMinutes)),
                    'involved' => [$demandLabel, sprintf('%s uninterrupted block needed', $this->formatDuration($durationMinutes))],
                    'why' => sprintf(
                        'This meeting requires %s without interruption, but the longest open teaching block between breaks is %s.',
                        $this->formatDuration($durationMinutes),
                        $this->formatDuration($maxContinuousSlotMinutes),
                    ),
                    'how_to_fix' => [
                        'Extend daily teaching hours or shorten/move the daily break in 1. Calendar.',
                        'Or adjust the course meeting pattern to shorter sessions if policy permits.',
                    ],
                    'action_label' => 'Review 1. Calendar',
                    'action_url' => $this->calendarUrl($termId),
                ]);
            }
        }

        // 2. Inspect solver infeasible_reasons, validation_findings, rejected_rows, and current_revalidation findings
        $rawFindings = [];

        foreach ((array) data_get($diagnostics, 'solver_result.findings', []) as $finding) {
            if (is_array($finding) && ($finding['severity'] ?? 'blocking') === 'blocking') {
                $rawFindings[] = $finding;
            }
        }

        foreach ((array) data_get($diagnostics, 'current_revalidation.findings', []) as $finding) {
            if (is_array($finding) && ($finding['severity'] ?? 'blocking') === 'blocking') {
                $rawFindings[] = $finding;
            }
        }

        foreach ((array) data_get($diagnostics, 'validation_findings', []) as $finding) {
            if (is_array($finding) && ($finding['severity'] ?? 'blocking') === 'blocking') {
                $rawFindings[] = $finding;
            }
        }

        foreach ((array) data_get($diagnostics, 'solver_result.infeasible_reasons', []) as $reason) {
            if (is_array($reason)) {
                $rawFindings[] = [
                    'code' => (string) ($reason['type'] ?? $reason['code'] ?? 'solver_reason'),
                    'message' => (string) ($reason['message'] ?? ''),
                    'scheduling_demand_id' => $reason['scheduling_demand_id'] ?? null,
                    'source_type' => $reason['source_type'] ?? null,
                    'source_id' => $reason['source_id'] ?? null,
                ];
            } elseif (is_string($reason) && trim($reason) !== '') {
                $rawFindings[] = [
                    'code' => 'solver_reason',
                    'message' => trim($reason),
                ];
            }
        }

        foreach ((array) data_get($diagnostics, 'solver_result.summary.rejected_rows', []) as $rejected) {
            if (is_array($rejected)) {
                $rawFindings[] = [
                    'code' => (string) ($rejected['reason'] ?? $rejected['code'] ?? 'rejected_row'),
                    'message' => (string) ($rejected['message'] ?? ''),
                    'scheduling_demand_id' => $rejected['scheduling_demand_id'] ?? null,
                ];
            }
        }

        $genericEnvelopeCodes = [
            'solver_infeasible',
            'solver_unknown',
            'solver_model_invalid',
            'solver_unassigned',
            'hard_violation_reported_for_solution',
            'assigned_count_mismatch',
            'unassigned_count_mismatch',
            'hard_violation_count_mismatch',
        ];

        foreach ($rawFindings as $finding) {
            $code = strtolower(trim((string) ($finding['code'] ?? $finding['type'] ?? '')));
            $message = trim((string) ($finding['message'] ?? ''));

            if (in_array($code, $genericEnvelopeCodes, true)) {
                continue;
            }

            $demandId = $this->nullableInt($finding['scheduling_demand_id'] ?? null);
            if ($demandId === null && ($finding['source_type'] ?? null) === 'scheduling_demand') {
                $demandId = $this->nullableInt($finding['source_id'] ?? null);
            }

            $demandLabel = $demandId !== null ? $this->demandLabel($demandId, $context) : null;
            $translated = $this->translateFindingCode($code, $message, $demandId, $demandLabel, $finding, $context, $termId);

            if ($translated !== null) {
                $dedupeKey = 'finding:'.$translated['category'].':'.($demandId ?? 'none').':'.$code;
                $addItem($dedupeKey, $translated);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function translateFindingCode(
        string $code,
        string $message,
        ?int $demandId,
        ?string $demandLabel,
        array $finding,
        array $context,
        ?int $termId,
    ): ?array {
        $lowerMsg = strtolower($message);
        $subjectLabel = $demandLabel ?? 'A required class';

        // No eligible faculty
        if (in_array($code, ['no_eligible_faculty', 'missing_faculty', 'ineligible_faculty', 'faculty_not_qualified', 'missing_faculty_qualification'], true)
            || (str_contains($lowerMsg, 'faculty') && str_contains($lowerMsg, 'eligible'))) {
            if ($demandId !== null) {
                return [
                    'category' => 'Unassignable class',
                    'severity' => 'danger',
                    'title' => sprintf('%s has no eligible teacher', $subjectLabel),
                    'involved' => array_values(array_filter([$demandLabel])),
                    'why' => 'No qualified teacher with submitted availability can be assigned to this class.',
                    'how_to_fix' => [
                        'Open 3. Faculty & rooms and verify an active teacher is qualified for this subject.',
                        'Confirm the teacher has declared available hours for this term.',
                    ],
                    'action_label' => 'Open 3. Faculty & rooms',
                    'action_url' => $this->resourcesUrl($termId, 'faculty'),
                ];
            }

            return [
                'category' => 'Unassignable class',
                'severity' => 'danger',
                'title' => 'At least one class has no eligible teacher',
                'involved' => ['Faculty qualifications & availability'],
                'why' => 'One or more classes have no active qualified teacher with submitted availability for this term.',
                'how_to_fix' => [
                    'Open 3. Faculty & rooms to assign qualified teachers to every offered subject.',
                    'Check that qualified teachers are not marked "Cannot teach".',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ];
        }

        // No eligible room / room mismatch
        if (in_array($code, ['no_eligible_room', 'missing_room', 'room_unsuitable', 'room_capacity_exceeded', 'room_type_mismatch', 'room_feature_missing', 'room_required'], true)
            || (str_contains($lowerMsg, 'room') && (str_contains($lowerMsg, 'no available') || str_contains($lowerMsg, 'no active') || str_contains($lowerMsg, 'capacity') || str_contains($lowerMsg, 'unsuitable')))) {
            $roomId = ($finding['source_type'] ?? null) === 'room' ? $this->nullableInt($finding['source_id'] ?? null) : null;
            $involved = array_values(array_filter([
                $demandLabel,
                $roomId !== null ? $this->roomLabel($roomId, $context) : null,
            ]));

            return [
                'category' => 'Room shortage',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('%s has no suitable room available', $demandLabel)
                    : 'No suitable active room matched an on-campus class',
                'involved' => $involved !== [] ? $involved : ['Active campus rooms'],
                'why' => $message !== '' && ! str_contains($lowerMsg, 'scheduling_demand')
                    ? $message
                    : 'No active room meets the required room type, seat capacity, or equipment for this class.',
                'how_to_fix' => [
                    'Open 3. Faculty & rooms (Rooms tab) and add or resize a matching active room.',
                    'Or adjust the section size or delivery mode in 2. Sections & classes.',
                ],
                'action_label' => 'Open 3. Faculty & rooms (Rooms)',
                'action_url' => $this->resourcesUrl($termId, 'rooms'),
            ];
        }

        // Calendar block / time slot conflict
        if (in_array($code, ['no_valid_time_slot', 'missing_time_slot', 'fixed_time_conflicts_with_calendar_block', 'calendar_block_overlap', 'outside_teaching_grid'], true)
            || str_contains($lowerMsg, 'calendar block')
            || str_contains($lowerMsg, 'time slot')) {
            return [
                'category' => 'Calendar break overlap',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('%s has no valid time slot inside teaching hours', $demandLabel)
                    : 'A class cannot fit inside the current teaching hours and breaks',
                'involved' => array_values(array_filter([$demandLabel ?? 'Term teaching grid & breaks'])),
                'why' => 'The class duration or locked schedule overlaps a daily break, blocked period, or falls outside active teaching hours.',
                'how_to_fix' => [
                    'Review daily teaching hours and break times in 1. Calendar.',
                    'If this class has a locked time slot, update or remove the lock in 2. Sections & classes.',
                ],
                'action_label' => 'Review 1. Calendar',
                'action_url' => $this->calendarUrl($termId),
            ];
        }

        // Faculty load exceeded
        if ($code === 'faculty_load_exceeded' || str_contains($lowerMsg, 'teaching load exceeds')) {
            return [
                'category' => 'Faculty load',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('Teacher load cap exceeded for %s', $demandLabel)
                    : 'A teacher exceeds the allowed term unit load',
                'involved' => array_values(array_filter([$demandLabel ?? 'Faculty unit caps'])),
                'why' => 'Assigned classes exceed the teacher\'s maximum allowed term units.',
                'how_to_fix' => [
                    'Open 3. Faculty & rooms and approve a term load override for the teacher.',
                    'Or qualify an additional teacher so classes can be distributed.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ];
        }

        // Faculty availability / unavailability
        if (in_array($code, ['faculty_unavailable', 'outside_faculty_availability'], true) || str_contains($lowerMsg, 'unavailab')) {
            return [
                'category' => 'Teacher availability',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('Assigned teacher is unavailable for %s', $demandLabel)
                    : 'Teacher availability blocks a required class',
                'involved' => array_values(array_filter([$demandLabel ?? 'Faculty availability'])),
                'why' => 'The teacher\'s declared availability does not allow this class to be placed in its required time slot.',
                'how_to_fix' => [
                    'Open 3. Faculty & rooms and review the teacher\'s declared availability windows.',
                    'Or assign another qualified teacher who is available during teaching hours.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ];
        }

        // Overlaps from revalidation
        if (in_array($code, ['faculty_overlap', 'room_overlap', 'delivery_group_overlap', 'cohort_overlap'], true)) {
            $kindLabel = match ($code) {
                'faculty_overlap' => 'Same teacher double-booked',
                'room_overlap' => 'Same room double-booked',
                default => 'Same student section double-booked',
            };

            return [
                'category' => 'Draft overlap',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('%s on %s', $kindLabel, $demandLabel)
                    : $kindLabel,
                'involved' => array_values(array_filter([$demandLabel])),
                'why' => 'Two scheduled meetings overlap at the same day and time.',
                'how_to_fix' => [
                    'Click the conflicting class tile on the grid below and select a conflict-free slot in the edit drawer.',
                    'Or generate a fresh draft timetable.',
                ],
                'action_label' => null,
                'action_url' => null,
            ];
        }

        // Modality transition buffer
        if ($code === 'cohort_modality_transition_buffer') {
            return [
                'category' => 'Draft overlap',
                'severity' => 'warning',
                'title' => $demandLabel !== null
                    ? sprintf('%s needs a 30-minute gap between Online and On-campus classes', $demandLabel)
                    : 'Student section lacks a 30-minute Online to On-campus transition gap',
                'involved' => array_values(array_filter([$demandLabel])),
                'why' => 'Students need at least 30 minutes between an Online class and an On-campus class on the same day.',
                'how_to_fix' => [
                    'Move one of the back-to-back classes at least 30 minutes apart on the timetable grid.',
                    'Or switch both classes to the same delivery mode in 2. Sections & classes.',
                ],
                'action_label' => 'Edit in 2. Sections & classes',
                'action_url' => $this->classesUrl($termId, 'classes'),
            ];
        }

        // Same faculty mismatch
        if ($code === 'same_faculty_mismatch') {
            return [
                'category' => 'Unassignable class',
                'severity' => 'danger',
                'title' => $demandLabel !== null
                    ? sprintf('Linked components of %s have different teachers', $demandLabel)
                    : 'Linked lecture and lab components use different teachers',
                'involved' => array_values(array_filter([$demandLabel])),
                'why' => 'This class requires the same teacher for its linked components (for example, Lecture and Lab).',
                'how_to_fix' => [
                    'Assign the same qualified teacher to both components on the draft timetable, or disable the same-teacher requirement in 2. Sections & classes.',
                ],
                'action_label' => 'Edit in 2. Sections & classes',
                'action_url' => $this->classesUrl($termId, 'classes'),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseFixedSlotCollisions(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        $fixedSlots = [];

        foreach ($demands as $demandId => $demand) {
            $duration = max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            $cohorts = $this->intList($demand['cohort_or_student_group_ids'] ?? []);
            $deliveryGroupId = $this->nullableInt($demand['section_delivery_group_id'] ?? null);
            $fixedMeetings = is_array($demand['fixed_meetings'] ?? null) ? $demand['fixed_meetings'] : [];

            if ($fixedMeetings !== []) {
                foreach ($fixedMeetings as $pin) {
                    if (! is_array($pin)) {
                        continue;
                    }
                    $day = $this->nullableInt($pin['fixed_day_of_week'] ?? null);
                    $start = $this->clockToMinutes($pin['fixed_start_time'] ?? null);
                    if ($day === null || $start === null) {
                        continue;
                    }
                    $end = $this->clockToMinutes($pin['fixed_end_time'] ?? null) ?? ($start + $duration);
                    $fixedSlots[] = [
                        'demand_id' => $demandId,
                        'sequence' => (int) ($pin['meeting_sequence'] ?? 1),
                        'day' => $day,
                        'start' => $start,
                        'end' => max($start + 15, $end),
                        'faculty_id' => $this->nullableInt($pin['fixed_faculty_user_id'] ?? $demand['fixed_faculty_user_id'] ?? null),
                        'room_id' => $this->nullableInt($pin['fixed_room_id'] ?? $demand['fixed_room_id'] ?? null),
                        'cohorts' => $cohorts,
                        'delivery_group_id' => $deliveryGroupId,
                    ];
                }
            } else {
                $day = $this->nullableInt($demand['fixed_day_of_week'] ?? null);
                $start = $this->clockToMinutes($demand['fixed_start_time'] ?? null);
                if ($day !== null && $start !== null) {
                    $fixedSlots[] = [
                        'demand_id' => $demandId,
                        'sequence' => (int) ($demand['fixed_meeting_sequence'] ?? 1),
                        'day' => $day,
                        'start' => $start,
                        'end' => $start + $duration,
                        'faculty_id' => $this->nullableInt($demand['fixed_faculty_user_id'] ?? null),
                        'room_id' => $this->nullableInt($demand['fixed_room_id'] ?? null),
                        'cohorts' => $cohorts,
                        'delivery_group_id' => $deliveryGroupId,
                    ];
                }
            }
        }

        if ($fixedSlots === []) {
            return;
        }

        // 1. Pairwise fixed collisions
        $slotCount = count($fixedSlots);
        for ($i = 0; $i < $slotCount; $i++) {
            for ($j = $i + 1; $j < $slotCount; $j++) {
                $a = $fixedSlots[$i];
                $b = $fixedSlots[$j];

                if ($a['day'] !== $b['day'] || ! ($a['start'] < $b['end'] && $a['end'] > $b['start'])) {
                    continue;
                }

                $labelA = $this->demandLabel($a['demand_id'], $context);
                $labelB = $this->demandLabel($b['demand_id'], $context);
                $timeText = sprintf('%s %s', $this->dayShort($a['day']), $this->formatTimeRange(min($a['start'], $b['start']), max($a['end'], $b['end'])));
                $pairKey = min($a['demand_id'], $b['demand_id']).':'.max($a['demand_id'], $b['demand_id']).':'.$a['day'].':'.min($a['start'], $b['start']);

                if ($a['faculty_id'] !== null && $a['faculty_id'] === $b['faculty_id']) {
                    $teacherName = $this->facultyLabel($a['faculty_id'], $context);
                    $addItem('fixed-teacher-clash:'.$pairKey, [
                        'category' => 'Fixed schedule clash',
                        'severity' => 'danger',
                        'title' => sprintf('%s is locked into two overlapping classes on %s', $teacherName, $this->dayFull($a['day'])),
                        'involved' => [$teacherName, $labelA, $labelB, $timeText],
                        'why' => sprintf('Both %s and %s have locked slots assigned to %s at %s.', $labelA, $labelB, $teacherName, $timeText),
                        'how_to_fix' => [
                            'Open 2. Sections & classes and unlock or move the fixed day/time on one of the two classes.',
                            'Or assign a different teacher to one of the locked classes.',
                        ],
                        'action_label' => 'Edit in 2. Sections & classes',
                        'action_url' => $this->classesUrl($termId, 'classes'),
                    ]);
                }

                if ($a['room_id'] !== null && $a['room_id'] === $b['room_id']) {
                    $roomLabel = $this->roomLabel($a['room_id'], $context);
                    $addItem('fixed-room-clash:'.$pairKey, [
                        'category' => 'Fixed schedule clash',
                        'severity' => 'danger',
                        'title' => sprintf('Room %s is locked into two overlapping classes on %s', $roomLabel, $this->dayFull($a['day'])),
                        'involved' => [$roomLabel, $labelA, $labelB, $timeText],
                        'why' => sprintf('Both %s and %s are locked to %s at %s.', $labelA, $labelB, $roomLabel, $timeText),
                        'how_to_fix' => [
                            'Open 2. Sections & classes and remove or change the locked room or time on one of the classes.',
                            'Let the solver choose another compatible active room automatically.',
                        ],
                        'action_label' => 'Edit in 2. Sections & classes',
                        'action_url' => $this->classesUrl($termId, 'classes'),
                    ]);
                }

                $sharedCohorts = array_values(array_intersect($a['cohorts'], $b['cohorts']));
                $sameGroup = $a['delivery_group_id'] !== null && $a['delivery_group_id'] === $b['delivery_group_id'];
                if ($sharedCohorts !== [] || $sameGroup) {
                    $sectionNames = collect($sharedCohorts)
                        ->map(fn (int $cid): string => $this->cohortLabel($cid, $context))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    $sectionDisplay = $sectionNames !== [] ? implode(', ', $sectionNames) : 'the same student section';
                    $addItem('fixed-section-clash:'.$pairKey, [
                        'category' => 'Fixed schedule clash',
                        'severity' => 'danger',
                        'title' => sprintf('%s has two locked classes at the same time on %s', $sectionDisplay, $this->dayFull($a['day'])),
                        'involved' => [...($sectionNames !== [] ? $sectionNames : ['Shared student section']), $labelA, $labelB, $timeText],
                        'why' => sprintf('Students in %s cannot attend both %s and %s at %s.', $sectionDisplay, $labelA, $labelB, $timeText),
                        'how_to_fix' => [
                            'Open 2. Sections & classes and unlock or reschedule one of the locked classes to a different day or time.',
                        ],
                        'action_label' => 'Edit in 2. Sections & classes',
                        'action_url' => $this->classesUrl($termId, 'classes'),
                    ]);
                }
            }
        }

        // 2. Fixed slot vs calendar blocks / daily breaks & teacher availability
        $calendarBlocks = is_array($snapshot['calendar_blocks'] ?? null) ? $snapshot['calendar_blocks'] : [];
        $availabilityByFaculty = $this->indexFacultyAvailability($snapshot);

        foreach ($fixedSlots as $slot) {
            $label = $this->demandLabel($slot['demand_id'], $context);
            $slotTime = sprintf('%s %s', $this->dayShort($slot['day']), $this->formatTimeRange($slot['start'], $slot['end']));

            foreach ($calendarBlocks as $block) {
                if (! is_array($block) || (int) ($block['day_of_week'] ?? 0) !== $slot['day']) {
                    continue;
                }
                $bStart = $this->clockToMinutes($block['starts_at'] ?? null);
                $bEnd = $this->clockToMinutes($block['ends_at'] ?? null);
                if ($bStart === null || $bEnd === null || ! ($slot['start'] < $bEnd && $slot['end'] > $bStart)) {
                    continue;
                }

                $scope = (string) ($block['scope_type'] ?? 'Institution');
                $eventType = (string) ($block['event_type'] ?? '');
                $blockRange = $this->formatTimeRange($bStart, $bEnd);

                if ($scope === 'Institution' && empty($block['faculty_user_id']) && empty($block['room_id'])) {
                    $breakName = $eventType === 'TeachingGridBreak' ? 'daily break' : 'school-wide blocked period';
                    $addItem('fixed-break:'.$slot['demand_id'].':'.$slot['day'].':'.$slot['start'], [
                        'category' => 'Calendar break overlap',
                        'severity' => 'danger',
                        'title' => sprintf('Locked slot for %s overlaps the %s', $label, $breakName),
                        'involved' => [$label, $slotTime, sprintf('Break: %s %s', $this->dayShort($slot['day']), $blockRange)],
                        'why' => sprintf('%s is locked at %s, which overlaps the %s (%s).', $label, $slotTime, $breakName, $blockRange),
                        'how_to_fix' => [
                            'Open 2. Sections & classes to unlock or move this class outside the break window.',
                            'Or adjust the daily break time in 1. Calendar.',
                        ],
                        'action_label' => 'Edit in 2. Sections & classes',
                        'action_url' => $this->classesUrl($termId, 'classes'),
                    ]);
                } elseif ($scope === 'Faculty' && $slot['faculty_id'] !== null && (int) ($block['faculty_user_id'] ?? 0) === $slot['faculty_id']) {
                    $teacherName = $this->facultyLabel($slot['faculty_id'], $context);
                    $addItem('fixed-faculty-unavail:'.$slot['demand_id'].':'.$slot['faculty_id'].':'.$slot['day'].':'.$slot['start'], [
                        'category' => 'Teacher availability',
                        'severity' => 'danger',
                        'title' => sprintf('%s is unavailable during the locked slot for %s', $teacherName, $label),
                        'involved' => [$teacherName, $label, $slotTime],
                        'why' => sprintf('%s is locked to %s at %s, but their term availability blocks %s.', $label, $teacherName, $slotTime, $blockRange),
                        'how_to_fix' => [
                            sprintf('Open 3. Faculty & rooms and update %s\'s declared availability to cover %s.', $teacherName, $slotTime),
                            'Or unlock the fixed time/teacher on this class in 2. Sections & classes.',
                        ],
                        'action_label' => 'Open 3. Faculty & rooms',
                        'action_url' => $this->resourcesUrl($termId, 'faculty'),
                    ]);
                } elseif ($scope === 'Room' && $slot['room_id'] !== null && (int) ($block['room_id'] ?? 0) === $slot['room_id']) {
                    $roomLabel = $this->roomLabel($slot['room_id'], $context);
                    $addItem('fixed-room-block:'.$slot['demand_id'].':'.$slot['room_id'].':'.$slot['day'].':'.$slot['start'], [
                        'category' => 'Fixed schedule clash',
                        'severity' => 'danger',
                        'title' => sprintf('Room %s is blocked during the locked slot for %s', $roomLabel, $label),
                        'involved' => [$roomLabel, $label, $slotTime],
                        'why' => sprintf('%s is locked to %s at %s, when the room has an active calendar block (%s).', $label, $roomLabel, $slotTime, $blockRange),
                        'how_to_fix' => [
                            'Unlock the fixed room or move the slot in 2. Sections & classes.',
                            'Or remove the room block in 1. Calendar.',
                        ],
                        'action_label' => 'Edit in 2. Sections & classes',
                        'action_url' => $this->classesUrl($termId, 'classes'),
                    ]);
                }
            }

            // Also check explicit faculty availability mode if not already emitted via calendar_blocks
            if ($slot['faculty_id'] !== null && isset($availabilityByFaculty[$slot['faculty_id']])) {
                $avail = $availabilityByFaculty[$slot['faculty_id']];
                $teacherName = $this->facultyLabel($slot['faculty_id'], $context);
                if (! $this->isFacultyAvailableForSlot($avail, $slot['day'], $slot['start'], $slot['end'])) {
                    $addItem('fixed-faculty-unavail:'.$slot['demand_id'].':'.$slot['faculty_id'].':'.$slot['day'].':'.$slot['start'], [
                        'category' => 'Teacher availability',
                        'severity' => 'danger',
                        'title' => sprintf('%s is unavailable during the locked slot for %s', $teacherName, $label),
                        'involved' => [$teacherName, $label, $slotTime],
                        'why' => ($avail['mode'] ?? '') === FacultyAvailabilityDeclaration::ModeCannotTeach
                            ? sprintf('%s is marked "Cannot teach" this term, but is locked to %s.', $teacherName, $label)
                            : sprintf('%s is locked to %s at %s, which falls outside their declared teaching windows.', $teacherName, $label, $slotTime),
                        'how_to_fix' => [
                            sprintf('Open 3. Faculty & rooms and update %s\'s availability for this term.', $teacherName),
                            'Or unlock the fixed teacher/time in 2. Sections & classes.',
                        ],
                        'action_label' => 'Open 3. Faculty & rooms',
                        'action_url' => $this->resourcesUrl($termId, 'faculty'),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseFacultyUnitLoadBottlenecks(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        if ($demands === []) {
            return;
        }

        $facultyCaps = $this->facultyUnitCaps($snapshot);
        $classGroups = $this->deduplicatedClassLoadGroups($demands, $context);

        // (i) Exclusive / fixed demands per single teacher
        $exclusiveByTeacher = [];
        foreach ($classGroups as $group) {
            $soleTeacherId = null;
            if ($group['fixed_faculty_id'] !== null) {
                $soleTeacherId = $group['fixed_faculty_id'];
            } elseif (count($group['eligible_faculty_ids']) === 1) {
                $soleTeacherId = $group['eligible_faculty_ids'][0];
            }

            if ($soleTeacherId === null || $group['units'] <= 0.0) {
                continue;
            }

            $exclusiveByTeacher[$soleTeacherId]['units'] = ($exclusiveByTeacher[$soleTeacherId]['units'] ?? 0.0) + $group['units'];
            $exclusiveByTeacher[$soleTeacherId]['classes'][] = $group['label'];
        }

        foreach ($exclusiveByTeacher as $facultyId => $usage) {
            $cap = $facultyCaps[$facultyId] ?? null;
            if ($cap === null || $usage['units'] <= $cap + 0.0001) {
                continue;
            }

            $teacherName = $this->facultyLabel($facultyId, $context);
            $reqStr = $this->formatUnits($usage['units']);
            $capStr = $this->formatUnits($cap);
            $addItem('faculty-load-exclusive:'.$facultyId, [
                'category' => 'Faculty load',
                'severity' => 'danger',
                'title' => sprintf('%s needs %s but is capped at %s', $teacherName, $reqStr, $capStr),
                'involved' => [$teacherName, sprintf('%s required vs %s cap', $reqStr, $capStr), ...$usage['classes']],
                'why' => sprintf(
                    '%s is the only eligible (or locked) teacher for %d %s totaling %s, which exceeds their %s term cap.',
                    $teacherName,
                    count($usage['classes']),
                    Str::plural('class', count($usage['classes'])),
                    $reqStr,
                    $capStr,
                ),
                'how_to_fix' => [
                    sprintf('Open 3. Faculty & rooms and approve a term load override of at least %s for %s.', $reqStr, $teacherName),
                    'Or qualify another active teacher for one or more of these subjects so the load can be shared.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ]);
        }

        // (ii) Subject-pool demands vs combined max_allowed_units of qualified teachers
        $byCourse = [];
        foreach ($classGroups as $group) {
            $courseKey = $group['course_code'] !== '' ? $group['course_code'] : ('course:'.($group['course_id'] ?? 0));
            $byCourse[$courseKey]['course_code'] = $group['course_code'] !== '' ? $group['course_code'] : 'Subject';
            $byCourse[$courseKey]['units'] = ($byCourse[$courseKey]['units'] ?? 0.0) + $group['units'];
            $byCourse[$courseKey]['classes'][] = $group['label'];
            foreach ($group['eligible_faculty_ids'] as $fid) {
                $byCourse[$courseKey]['teachers'][$fid] = $fid;
            }
        }

        foreach ($byCourse as $courseKey => $pool) {
            $teacherIds = array_values($pool['teachers'] ?? []);
            if (count($teacherIds) < 2 || $pool['units'] <= 0.0) {
                continue;
            }

            $allCapped = true;
            $combinedCap = 0.0;
            foreach ($teacherIds as $fid) {
                if (! isset($facultyCaps[$fid]) || $facultyCaps[$fid] === null) {
                    $allCapped = false;
                    break;
                }
                $combinedCap += $facultyCaps[$fid];
            }

            if (! $allCapped || $pool['units'] <= $combinedCap + 0.0001) {
                continue;
            }

            $teacherNames = array_map(fn (int $fid): string => $this->facultyLabel($fid, $context), $teacherIds);
            $reqStr = $this->formatUnits($pool['units']);
            $capStr = $this->formatUnits($combinedCap);

            $addItem('faculty-load-pool:'.$courseKey, [
                'category' => 'Faculty load',
                'severity' => 'danger',
                'title' => sprintf('%s classes need %s, exceeding qualified teachers\' combined %s cap', $pool['course_code'], $reqStr, $capStr),
                'involved' => [$pool['course_code'], sprintf('%s needed vs %s combined cap', $reqStr, $capStr), ...$teacherNames],
                'why' => sprintf(
                    'All %d %s of %s require %s in total, but the %d qualified teachers (%s) only have %s combined capacity.',
                    count($pool['classes']),
                    Str::plural('class', count($pool['classes'])),
                    $pool['course_code'],
                    $reqStr,
                    count($teacherIds),
                    implode(', ', array_slice($teacherNames, 0, 3)),
                    $capStr,
                ),
                'how_to_fix' => [
                    sprintf('Qualify another teacher for %s in 3. Faculty & rooms.', $pool['course_code']),
                    'Or approve term load overrides for the qualified teachers.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ]);
        }

        // (iii) Whole-term total demanded units vs total combined cap of all eligible teachers
        $totalUnitsNeeded = array_sum(array_column($classGroups, 'units'));
        $allFacultyIds = array_keys($facultyCaps);
        $hasUncappedTeacher = collect($facultyCaps)->contains(fn (?float $cap): bool => $cap === null);
        if (! $hasUncappedTeacher && $allFacultyIds !== [] && $totalUnitsNeeded > 0.0) {
            $totalFacultyCap = array_sum(array_map(fn (?float $c): float => (float) ($c ?? 0.0), $facultyCaps));
            if ($totalUnitsNeeded > $totalFacultyCap + 0.0001) {
                $addItem('faculty-load-total-term', [
                    'category' => 'Faculty load',
                    'severity' => 'danger',
                    'title' => sprintf('Whole-term teaching load (%s) exceeds total available faculty capacity (%s)', $this->formatUnits($totalUnitsNeeded), $this->formatUnits($totalFacultyCap)),
                    'involved' => [
                        sprintf('%s needed across %d classes', $this->formatUnits($totalUnitsNeeded), count($classGroups)),
                        sprintf('%s available across %d teachers', $this->formatUnits($totalFacultyCap), count($allFacultyIds)),
                    ],
                    'why' => 'Even combining every eligible teacher\'s maximum term load is not enough to cover all planned classes.',
                    'how_to_fix' => [
                        'Approve term load overrides or increase the term default unit limit in 3. Faculty & rooms.',
                        'Or qualify additional faculty members / merge small sections in 2. Sections & classes.',
                    ],
                    'action_label' => 'Open 3. Faculty & rooms',
                    'action_url' => $this->resourcesUrl($termId, 'faculty'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseFacultyTimeCapacityBottlenecks(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        if ($demands === []) {
            return;
        }

        $teachingIntervals = $this->teachingIntervalsByDay($snapshot);
        if ($teachingIntervals === []) {
            return;
        }

        $availabilityByFaculty = $this->indexFacultyAvailability($snapshot);
        $facultyBlocks = $this->facultyBlocksByFaculty($snapshot);
        $maxContinuousSlotMinutes = $this->maxContinuousOpenMinutes($teachingIntervals);
        $dailyBreaks = $this->dailyBreakRanges($snapshot);
        $dailyBreakSummary = $this->formatDailyBreakSummary($dailyBreaks);
        $dailyBreakTimesText = $this->formatDailyBreakTimesText($dailyBreaks);

        /** @var array<int, array<int, list<array{start:int,end:int}>>> $effectiveIntervalsCache */
        $effectiveIntervalsCache = [];
        $resolveEffectiveIntervals = function (int $facultyId) use (
            &$effectiveIntervalsCache,
            $teachingIntervals,
            $availabilityByFaculty,
            $facultyBlocks,
        ): array {
            if (! array_key_exists($facultyId, $effectiveIntervalsCache)) {
                $effectiveIntervalsCache[$facultyId] = $this->facultyEffectiveDayIntervals(
                    $facultyId,
                    $teachingIntervals,
                    $availabilityByFaculty[$facultyId] ?? null,
                    $facultyBlocks[$facultyId] ?? [],
                );
            }

            return $effectiveIntervalsCache[$facultyId];
        };

        $exclusiveMinutesByTeacher = [];
        foreach ($demands as $demandId => $demand) {
            $eligibleIds = $this->intList($demand['eligible_faculty_user_ids'] ?? []);
            $fixedFacultyId = $this->nullableInt($demand['fixed_faculty_user_id'] ?? null);
            $soleId = $fixedFacultyId ?? (count($eligibleIds) === 1 ? $eligibleIds[0] : null);
            if ($soleId === null) {
                continue;
            }
            $weeklyMinutes = max(1, (int) ($demand['meeting_count'] ?? 1)) * max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            $exclusiveMinutesByTeacher[$soleId]['minutes'] = ($exclusiveMinutesByTeacher[$soleId]['minutes'] ?? 0) + $weeklyMinutes;
            $exclusiveMinutesByTeacher[$soleId]['classes'][] = $this->demandLabel($demandId, $context);
        }

        foreach ($exclusiveMinutesByTeacher as $facultyId => $usage) {
            $openMinutes = $this->totalMinutesInIntervals($resolveEffectiveIntervals($facultyId));

            if ($usage['minutes'] <= $openMinutes) {
                continue;
            }

            $teacherName = $this->facultyLabel($facultyId, $context);
            $reqHours = $this->formatHours($usage['minutes']);
            $availHours = $this->formatHours($openMinutes);

            $addItem('faculty-time-cap:'.$facultyId, [
                'category' => 'Teacher availability',
                'severity' => 'danger',
                'title' => $openMinutes === 0
                    ? sprintf('%s has 0 open teaching hours declared, but classes require %s', $teacherName, $reqHours)
                    : sprintf('%s only has %s open, but assigned classes need %s', $teacherName, $availHours, $reqHours),
                'involved' => [$teacherName, sprintf('%s needed vs %s open', $reqHours, $availHours), ...array_unique($usage['classes'])],
                'why' => sprintf(
                    '%s\'s declared teaching availability (%s/week) cannot fit all %s/week of classes that have no other qualified teacher.',
                    $teacherName,
                    $availHours,
                    $reqHours,
                ),
                'how_to_fix' => [
                    sprintf('Open 3. Faculty & rooms and expand %s\'s available days or hours for this term.', $teacherName),
                    'Or qualify another active teacher for one of these subjects so meetings can be shared.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ]);
        }

        // Contiguous-block split and distinct usable day checks per demand
        foreach ($demands as $demandId => $demand) {
            $eligibleFacultyIds = $this->intList($demand['eligible_faculty_user_ids'] ?? []);
            $fixedFacultyId = $this->nullableInt($demand['fixed_faculty_user_id'] ?? null);
            $candidateFacultyIds = $fixedFacultyId !== null ? [$fixedFacultyId] : $eligibleFacultyIds;

            if ($candidateFacultyIds === []) {
                continue;
            }

            $durationMinutes = max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            $meetingCount = max(1, (int) ($demand['meeting_count'] ?? 1));

            if ($maxContinuousSlotMinutes < $durationMinutes) {
                continue;
            }

            $candidateStats = [];
            $hasTeacherWithContiguousBlock = false;
            $hasTeacherWithEnoughDays = false;

            foreach ($candidateFacultyIds as $facultyId) {
                $dayIntervals = $resolveEffectiveIntervals($facultyId);
                $maxBlockMinutes = $this->maxContinuousOpenMinutes($dayIntervals);
                $usableDaysCount = $this->usableDaysCount($dayIntervals, $durationMinutes);

                if ($maxBlockMinutes >= $durationMinutes) {
                    $hasTeacherWithContiguousBlock = true;
                }
                if ($usableDaysCount >= $meetingCount) {
                    $hasTeacherWithEnoughDays = true;
                }

                $candidateStats[] = [
                    'faculty_id' => $facultyId,
                    'max_block_minutes' => $maxBlockMinutes,
                    'usable_days_count' => $usableDaysCount,
                ];
            }

            $demandLabel = $this->demandLabel($demandId, $context);
            $durationFormatted = $this->formatDuration($durationMinutes);
            $hasDailyBreaks = $dailyBreakSummary !== null;

            if (! $hasTeacherWithContiguousBlock) {
                usort(
                    $candidateStats,
                    fn (array $left, array $right): int => $right['max_block_minutes'] <=> $left['max_block_minutes'],
                );

                $teacherSummaries = array_map(
                    fn (array $stats): string => sprintf(
                        '%s (longest open block: %s)',
                        $this->facultyLabel($stats['faculty_id'], $context),
                        $this->formatDuration($stats['max_block_minutes']),
                    ),
                    array_slice($candidateStats, 0, 3),
                );

                $involved = [
                    $demandLabel,
                    sprintf('%s uninterrupted block needed', $durationFormatted),
                    ...$teacherSummaries,
                ];
                if ($dailyBreakSummary !== null) {
                    $involved[] = $dailyBreakSummary;
                }

                $why = $hasDailyBreaks
                    ? sprintf(
                        'Even though a qualified teacher may have enough total weekly hours, daily breaks (%s) or split availability windows leave no single uninterrupted block of at least %s for %s.',
                        $dailyBreakTimesText,
                        $durationFormatted,
                        $demandLabel,
                    )
                    : sprintf(
                        'Even though a qualified teacher may have enough total weekly hours, split availability windows or blocked times leave no single uninterrupted block of at least %s for %s.',
                        $durationFormatted,
                        $demandLabel,
                    );

                $howToFix = [
                    sprintf(
                        'Open 3. Faculty & rooms and expand a qualified teacher\'s availability window so an uninterrupted %s block fits before or after any break.',
                        $durationFormatted,
                    ),
                ];
                if ($hasDailyBreaks) {
                    $howToFix[] = 'Or adjust or shorten the daily break in 1. Calendar so longer uninterrupted teaching blocks stay open.';
                }
                $howToFix[] = 'Or qualify another active teacher for this subject who has a long enough uninterrupted block.';

                $addItem('faculty-contiguous-block:'.$demandId, [
                    'category' => $hasDailyBreaks ? 'Calendar break overlap' : 'Teacher availability',
                    'severity' => 'danger',
                    'title' => $hasDailyBreaks
                        ? sprintf('%s (%s) cannot fit inside any qualified teacher\'s uninterrupted hours after daily breaks', $demandLabel, $durationFormatted)
                        : sprintf('%s (%s) cannot fit inside any qualified teacher\'s uninterrupted hours', $demandLabel, $durationFormatted),
                    'involved' => $involved,
                    'why' => $why,
                    'how_to_fix' => $howToFix,
                    'action_label' => 'Open 3. Faculty & rooms',
                    'action_url' => $this->resourcesUrl($termId, 'faculty'),
                ]);
            } elseif ($meetingCount > 1 && ! $hasTeacherWithEnoughDays) {
                usort(
                    $candidateStats,
                    fn (array $left, array $right): int => $right['usable_days_count'] <=> $left['usable_days_count'],
                );

                $teacherSummaries = array_map(
                    fn (array $stats): string => sprintf(
                        '%s (%d %s for %s meetings)',
                        $this->facultyLabel($stats['faculty_id'], $context),
                        $stats['usable_days_count'],
                        $stats['usable_days_count'] === 1 ? 'usable day' : 'usable days',
                        $durationFormatted,
                    ),
                    array_slice($candidateStats, 0, 3),
                );

                $involved = [
                    $demandLabel,
                    sprintf('%d distinct days needed', $meetingCount),
                    ...$teacherSummaries,
                ];
                if ($dailyBreakSummary !== null) {
                    $involved[] = $dailyBreakSummary;
                }

                $why = $hasDailyBreaks
                    ? sprintf(
                        '%s must meet on %d separate days for %s each, but after daily breaks (%s) and declared availability windows, no qualified teacher has %d distinct days with an uninterrupted %s block.',
                        $demandLabel,
                        $meetingCount,
                        $durationFormatted,
                        $dailyBreakTimesText,
                        $meetingCount,
                        $durationFormatted,
                    )
                    : sprintf(
                        '%s must meet on %d separate days for %s each, but declared availability windows leave no qualified teacher with %d distinct days containing an uninterrupted %s block.',
                        $demandLabel,
                        $meetingCount,
                        $durationFormatted,
                        $meetingCount,
                        $durationFormatted,
                    );

                $howToFix = [
                    sprintf(
                        'Open 3. Faculty & rooms and expand a qualified teacher\'s availability to at least %d separate days with an uninterrupted %s block.',
                        $meetingCount,
                        $durationFormatted,
                    ),
                ];
                if ($hasDailyBreaks) {
                    $howToFix[] = 'Or adjust daily break times or active teaching days in 1. Calendar if breaks split another day too narrowly.';
                }
                $howToFix[] = 'Or qualify another active teacher for this subject whose availability covers enough separate days.';

                $addItem('faculty-usable-days:'.$demandId, [
                    'category' => 'Teacher availability',
                    'severity' => 'danger',
                    'title' => sprintf('%s needs %d separate days, but no qualified teacher has enough usable days', $demandLabel, $meetingCount),
                    'involved' => $involved,
                    'why' => $why,
                    'how_to_fix' => $howToFix,
                    'action_label' => 'Open 3. Faculty & rooms',
                    'action_url' => $this->resourcesUrl($termId, 'faculty'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseRoomShortages(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        if ($demands === []) {
            return;
        }

        $teachingIntervals = $this->teachingIntervalsByDay($snapshot);
        $weeklyOpenMinutesPerRoom = $this->totalMinutesInIntervals($teachingIntervals);
        if ($weeklyOpenMinutesPerRoom <= 0) {
            return;
        }

        $rooms = $this->normalizedRooms($snapshot);
        $roomBlocks = $this->roomBlockMinutesByRoom($snapshot, $teachingIntervals);

        $demandsByType = [];
        foreach ($demands as $demandId => $demand) {
            $roomRequired = (bool) ($demand['room_required'] ?? (($demand['modality'] ?? '') === TermOffering::ModalityFaceToFace));
            if (! $roomRequired) {
                continue;
            }
            $typeKey = strtoupper(trim((string) ($demand['room_type_requirement'] ?? 'LECTURE')));
            if ($typeKey === '') {
                $typeKey = 'LECTURE';
            }
            $weeklyMinutes = max(1, (int) ($demand['meeting_count'] ?? 1)) * max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            $seats = max(0, (int) ($demand['expected_count'] ?? 0));

            $demandsByType[$typeKey]['minutes'] = ($demandsByType[$typeKey]['minutes'] ?? 0) + $weeklyMinutes;
            $demandsByType[$typeKey]['count'] = ($demandsByType[$typeKey]['count'] ?? 0) + 1;
            $demandsByType[$typeKey]['max_seats'] = max($demandsByType[$typeKey]['max_seats'] ?? 0, $seats);
            $demandsByType[$typeKey]['demands'][] = ['id' => $demandId, 'minutes' => $weeklyMinutes, 'seats' => $seats];
        }

        foreach ($demandsByType as $typeKey => $group) {
            $matchingRooms = array_values(array_filter(
                $rooms,
                fn (array $room): bool => $this->roomMatchesType($room, $typeKey),
            ));

            $availableMinutes = 0;
            foreach ($matchingRooms as $room) {
                $blocked = $roomBlocks[(int) $room['room_id']] ?? 0;
                $availableMinutes += max(0, $weeklyOpenMinutesPerRoom - $blocked);
            }

            $typeLabel = $this->roomTypeLabel($typeKey);

            if ($group['minutes'] > $availableMinutes) {
                $reqHours = $this->formatHours($group['minutes']);
                $availHours = $this->formatHours($availableMinutes);
                $roomCount = count($matchingRooms);

                $addItem('room-shortage:'.$typeKey, [
                    'category' => 'Room shortage',
                    'severity' => 'danger',
                    'title' => sprintf('Not enough %s room hours for %d on-campus %s', strtolower($typeLabel), $group['count'], Str::plural('class', $group['count'])),
                    'involved' => [
                        sprintf('%s rooms (%d active)', $typeLabel, $roomCount),
                        sprintf('%s needed vs %s available', $reqHours, $availHours),
                        sprintf('Up to %d seats needed', $group['max_seats']),
                    ],
                    'why' => sprintf(
                        'On-campus %s classes need %s/week, but the %d active %s %s only provide %s/week inside teaching hours.',
                        strtolower($typeLabel),
                        $reqHours,
                        $roomCount,
                        strtolower($typeLabel),
                        Str::plural('room', $roomCount),
                        $availHours,
                    ),
                    'how_to_fix' => [
                        sprintf('Add another active %s room in 3. Faculty & rooms (Rooms tab).', strtolower($typeLabel)),
                        'Or move some classes to Online delivery in 2. Sections & classes, or extend teaching hours in 1. Calendar.',
                    ],
                    'action_label' => 'Open 3. Faculty & rooms (Rooms)',
                    'action_url' => $this->resourcesUrl($termId, 'rooms'),
                ]);

                continue;
            }

            // Also check large-section seat-tier shortage within this room type
            $distinctSeatTiers = array_values(array_unique(array_filter(
                array_column($group['demands'], 'seats'),
                fn (int $s): bool => $s > 0,
            )));
            rsort($distinctSeatTiers);

            foreach ($distinctSeatTiers as $seatThreshold) {
                $tierDemands = array_values(array_filter($group['demands'], fn (array $d): bool => $d['seats'] >= $seatThreshold));
                $tierMinutes = array_sum(array_column($tierDemands, 'minutes'));
                $tierRooms = array_values(array_filter($matchingRooms, fn (array $r): bool => (int) ($r['capacity'] ?? 0) >= $seatThreshold));
                $tierAvailMinutes = 0;
                foreach ($tierRooms as $room) {
                    $tierAvailMinutes += max(0, $weeklyOpenMinutesPerRoom - ($roomBlocks[(int) $room['room_id']] ?? 0));
                }

                if ($tierMinutes > $tierAvailMinutes && $tierRooms !== []) {
                    $addItem('room-seat-tier:'.$typeKey.':'.$seatThreshold, [
                        'category' => 'Room shortage',
                        'severity' => 'danger',
                        'title' => sprintf('Not enough large %s rooms (%d+ seats) for %d %s', strtolower($typeLabel), $seatThreshold, count($tierDemands), Str::plural('class', count($tierDemands))),
                        'involved' => [
                            sprintf('%s rooms with %d+ seats (%d active)', $typeLabel, $seatThreshold, count($tierRooms)),
                            sprintf('%s needed vs %s available', $this->formatHours($tierMinutes), $this->formatHours($tierAvailMinutes)),
                        ],
                        'why' => sprintf(
                            'Classes with %d+ students need %s/week, exceeding the %s/week capacity of matching large rooms.',
                            $seatThreshold,
                            $this->formatHours($tierMinutes),
                            $this->formatHours($tierAvailMinutes),
                        ),
                        'how_to_fix' => [
                            sprintf('Add another %s room with %d+ seats in 3. Faculty & rooms (Rooms tab).', strtolower($typeLabel), $seatThreshold),
                            'Or split large sections into smaller classes in 2. Sections & classes.',
                        ],
                        'action_label' => 'Open 3. Faculty & rooms (Rooms)',
                        'action_url' => $this->resourcesUrl($termId, 'rooms'),
                    ]);
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseStudentSectionOverload(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        if ($demands === []) {
            return;
        }

        $teachingIntervals = $this->teachingIntervalsByDay($snapshot);
        $weeklyOpenMinutes = $this->totalMinutesInIntervals($teachingIntervals);
        if ($weeklyOpenMinutes <= 0) {
            return;
        }

        $minutesByCohort = [];
        foreach ($demands as $demandId => $demand) {
            $weeklyMinutes = max(1, (int) ($demand['meeting_count'] ?? 1)) * max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            foreach ($this->intList($demand['cohort_or_student_group_ids'] ?? []) as $cohortId) {
                $minutesByCohort[$cohortId]['minutes'] = ($minutesByCohort[$cohortId]['minutes'] ?? 0) + $weeklyMinutes;
                $minutesByCohort[$cohortId]['classes'][] = $this->demandLabel($demandId, $context);
            }
        }

        foreach ($minutesByCohort as $cohortId => $usage) {
            if ($usage['minutes'] <= $weeklyOpenMinutes) {
                continue;
            }

            $cohortLabel = $this->cohortLabel($cohortId, $context);
            $reqHours = $this->formatHours($usage['minutes']);
            $availHours = $this->formatHours($weeklyOpenMinutes);

            $addItem('section-overload:'.$cohortId, [
                'category' => 'Student section overload',
                'severity' => 'danger',
                'title' => sprintf('%s has %s of classes, exceeding the %s teaching week', $cohortLabel, $reqHours, $availHours),
                'involved' => [$cohortLabel, sprintf('%s required vs %s open teaching hours', $reqHours, $availHours)],
                'why' => sprintf(
                    'All classes assigned to %s require %s/week, which is more than the %s/week of open teaching time in the term calendar.',
                    $cohortLabel,
                    $reqHours,
                    $availHours,
                ),
                'how_to_fix' => [
                    'Extend daily teaching hours or add another teaching day in 1. Calendar.',
                    sprintf('Or review the classes assigned to %s in 2. Sections & classes to remove duplicate or excess offerings.', $cohortLabel),
                ],
                'action_label' => 'Review 1. Calendar',
                'action_url' => $this->calendarUrl($termId),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseStaleCalendarDrift(
        ScheduleGenerationRun $run,
        array $diagnostics,
        Collection $candidateRows,
        Term $term,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        if ($candidateRows->isEmpty()) {
            return;
        }

        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->first();

        if (! $activePackage instanceof TermCalendarPackage || $activePackage->teachingGridRows->isEmpty()) {
            return;
        }

        $gridByDay = [];
        $breaksByDay = [];
        foreach ($activePackage->teachingGridRows as $gridRow) {
            $day = (int) $gridRow->day_of_week;
            $gStart = $this->clockToMinutes($gridRow->starts_at);
            $gEnd = $this->clockToMinutes($gridRow->ends_at);
            if ($gStart !== null && $gEnd !== null && $gEnd > $gStart) {
                $gridByDay[$day][] = ['start' => $gStart, 'end' => $gEnd];
            }
            foreach (is_array($gridRow->breaks) ? $gridRow->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }
                $bStart = $this->clockToMinutes($break['starts_at'] ?? null);
                $bEnd = $this->clockToMinutes($break['ends_at'] ?? null);
                if ($bStart !== null && $bEnd !== null && $bEnd > $bStart) {
                    $breaksByDay[$day][] = ['start' => $bStart, 'end' => $bEnd];
                }
            }
        }

        $breakCollisions = [];
        $outOfHoursCollisions = [];

        foreach ($candidateRows as $row) {
            $day = (int) $row->day_of_week;
            $start = $this->clockToMinutes($row->starts_at);
            $end = $this->clockToMinutes($row->ends_at);
            if ($start === null || $end === null) {
                continue;
            }
            $rowLabel = $this->rowLabel($row, $context);
            $slotLabel = sprintf('%s %s', $this->dayShort($day), $this->formatTimeRange($start, $end));

            foreach ($breaksByDay[$day] ?? [] as $break) {
                if ($start < $break['end'] && $end > $break['start']) {
                    $breakCollisions[] = sprintf(
                        '%s · %s (overlaps break %s)',
                        $rowLabel,
                        $slotLabel,
                        $this->formatTimeRange($break['start'], $break['end']),
                    );
                    break;
                }
            }

            $fitsActiveDay = false;
            foreach ($gridByDay[$day] ?? [] as $interval) {
                if ($start >= $interval['start'] && $end <= $interval['end']) {
                    $fitsActiveDay = true;
                    break;
                }
            }
            if (! $fitsActiveDay) {
                $outOfHoursCollisions[] = sprintf('%s · %s', $rowLabel, $slotLabel);
            }
        }

        if ($breakCollisions !== []) {
            $count = count($breakCollisions);
            $addItem('stale-break-drift', [
                'category' => 'Calendar break overlap',
                'severity' => 'danger',
                'title' => sprintf('%d scheduled %s now %s with the updated daily break', $count, Str::plural('meeting', $count), $count === 1 ? 'overlaps' : 'overlap'),
                'involved' => $breakCollisions,
                'why' => 'The active Term Calendar was updated with new or moved daily break times after this draft was generated, so this draft is stale and cannot be published.',
                'how_to_fix' => [
                    'Click New draft above to generate a fresh timetable that automatically avoids the new daily break.',
                    'Or adjust the daily break window in 1. Calendar if you want to keep these meeting times.',
                ],
                'action_label' => 'Review 1. Calendar',
                'action_url' => $this->calendarUrl($termId),
            ]);
        }

        if ($outOfHoursCollisions !== []) {
            $count = count($outOfHoursCollisions);
            $addItem('stale-hours-drift', [
                'category' => 'Calendar break overlap',
                'severity' => 'warning',
                'title' => sprintf('%d scheduled %s now %s outside the updated teaching hours', $count, Str::plural('meeting', $count), $count === 1 ? 'falls' : 'fall'),
                'involved' => $outOfHoursCollisions,
                'why' => 'The active Term Calendar now uses shorter teaching hours or different teaching days than when this draft was generated.',
                'how_to_fix' => [
                    'Click New draft above to regenerate the timetable inside the updated teaching window.',
                    'Or restore the previous teaching hours in 1. Calendar.',
                ],
                'action_label' => 'Review 1. Calendar',
                'action_url' => $this->calendarUrl($termId),
            ]);
        }
    }

    /**
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseCandidateRowConflicts(
        ScheduleGenerationRun $run,
        Collection $candidateRows,
        array $demands,
        array $context,
        callable $addItem,
    ): void {
        $rows = $candidateRows->values()->all();
        $rowCount = count($rows);

        // 1. Pairwise teacher, room, and section overlaps
        for ($i = 0; $i < $rowCount; $i++) {
            for ($j = $i + 1; $j < $rowCount; $j++) {
                /** @var CandidateScheduleRow $a */
                $a = $rows[$i];
                /** @var CandidateScheduleRow $b */
                $b = $rows[$j];

                if ((int) $a->day_of_week !== (int) $b->day_of_week) {
                    continue;
                }
                $aStart = $this->clockToMinutes($a->starts_at);
                $aEnd = $this->clockToMinutes($a->ends_at);
                $bStart = $this->clockToMinutes($b->starts_at);
                $bEnd = $this->clockToMinutes($b->ends_at);
                if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
                    continue;
                }
                if (! ($aStart < $bEnd && $aEnd > $bStart)) {
                    continue;
                }

                $labelA = $this->rowLabel($a, $context);
                $labelB = $this->rowLabel($b, $context);
                $timeText = sprintf('%s %s', $this->dayShort((int) $a->day_of_week), $this->formatTimeRange(min($aStart, $bStart), max($aEnd, $bEnd)));
                $pairKey = min((int) $a->id, (int) $b->id).':'.max((int) $a->id, (int) $b->id);

                if ($a->faculty_user_id !== null && (int) $a->faculty_user_id === (int) $b->faculty_user_id) {
                    $teacherName = $a->faculty?->name ?? $this->facultyLabel((int) $a->faculty_user_id, $context);
                    $addItem('draft-overlap-teacher:'.$pairKey, [
                        'category' => 'Draft overlap',
                        'severity' => 'danger',
                        'title' => sprintf('%s is double-booked on %s', $teacherName, $this->dayFull((int) $a->day_of_week)),
                        'involved' => [$teacherName, $labelA, $labelB, $timeText],
                        'why' => sprintf('%s is assigned to both %s and %s at %s.', $teacherName, $labelA, $labelB, $timeText),
                        'how_to_fix' => [
                            'Click either class tile on the timetable grid below and choose one of the suggested conflict-free slots.',
                            'Or pick a different qualified teacher for one of the classes in the edit drawer.',
                        ],
                        'action_label' => null,
                        'action_url' => null,
                    ]);
                }

                if ($a->room_id !== null && (int) $a->room_id === (int) $b->room_id) {
                    $roomLabel = $a->room?->code ?? $this->roomLabel((int) $a->room_id, $context);
                    $addItem('draft-overlap-room:'.$pairKey, [
                        'category' => 'Draft overlap',
                        'severity' => 'danger',
                        'title' => sprintf('Room %s is double-booked on %s', $roomLabel, $this->dayFull((int) $a->day_of_week)),
                        'involved' => [$roomLabel, $labelA, $labelB, $timeText],
                        'why' => sprintf('Both %s and %s are placed in %s at %s.', $labelA, $labelB, $roomLabel, $timeText),
                        'how_to_fix' => [
                            'Click either class tile below and switch to another compatible room or a conflict-free time slot.',
                        ],
                        'action_label' => null,
                        'action_url' => null,
                    ]);
                }

                $cohortsA = $this->rowCohorts($a, $demands);
                $cohortsB = $this->rowCohorts($b, $demands);
                $sharedCohorts = array_values(array_intersect($cohortsA, $cohortsB));
                if ($sharedCohorts !== []) {
                    $sectionNames = array_map(fn (int $cid): string => $this->cohortLabel($cid, $context), $sharedCohorts);
                    $sectionDisplay = implode(', ', array_unique($sectionNames));
                    $addItem('draft-overlap-section:'.$pairKey, [
                        'category' => 'Draft overlap',
                        'severity' => 'danger',
                        'title' => sprintf('%s has two overlapping classes on %s', $sectionDisplay, $this->dayFull((int) $a->day_of_week)),
                        'involved' => [...array_unique($sectionNames), $labelA, $labelB, $timeText],
                        'why' => sprintf('Students in %s are scheduled for both %s and %s at %s.', $sectionDisplay, $labelA, $labelB, $timeText),
                        'how_to_fix' => [
                            'Click either class tile below and pick a conflict-free time slot in the edit drawer.',
                        ],
                        'action_label' => null,
                        'action_url' => null,
                    ]);
                }
            }
        }

        // 2. Non-publishable rows (missing room on Face-to-Face, conflict status, or recorded violations)
        foreach ($rows as $row) {
            /** @var CandidateScheduleRow $row */
            if ($row->isPublishableFor($run)) {
                continue;
            }

            $rowLabel = $this->rowLabel($row, $context);
            $start = $this->clockToMinutes($row->starts_at);
            $end = $this->clockToMinutes($row->ends_at);
            $timeText = ($start !== null && $end !== null)
                ? sprintf('%s %s', $this->dayShort((int) $row->day_of_week), $this->formatTimeRange($start, $end))
                : $this->dayShort((int) $row->day_of_week);
            $teacherName = $row->faculty?->name ?? ($row->faculty_user_id !== null ? $this->facultyLabel((int) $row->faculty_user_id, $context) : null);

            $violationReason = $this->extractRowViolationReason($row);
            if ($violationReason === '' && $row->room_id === null && $row->schedulingDemand?->modality === TermOffering::ModalityFaceToFace) {
                $violationReason = 'This on-campus meeting has no room assigned yet.';
            }
            if ($violationReason === '') {
                $violationReason = 'This meeting currently violates a hard scheduling rule and blocks publication.';
            }

            $addItem('draft-non-publishable:'.$row->id, [
                'category' => 'Draft overlap',
                'severity' => 'danger',
                'title' => sprintf('%s has a blocking conflict at %s', $rowLabel, $timeText),
                'involved' => array_values(array_filter([$rowLabel, $teacherName, $timeText])),
                'why' => $violationReason,
                'how_to_fix' => [
                    'Click this class tile on the timetable grid below to move it to a conflict-free slot, or assign a valid room and teacher.',
                    'Or click Regenerate draft to let the solver place all meetings cleanly.',
                ],
                'action_label' => null,
                'action_url' => null,
            ]);
        }
    }

    /**
     * Graceful fallback for complex multi-way CP-SAT infeasibility: highlight the tightest 2-3 constraints.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void  $addItem
     */
    private function diagnoseMultiWayTightestConstraints(
        array $snapshot,
        array $demands,
        array $context,
        ?int $termId,
        callable $addItem,
    ): void {
        $teachingIntervals = $this->teachingIntervalsByDay($snapshot);
        $weeklyOpenMinutes = $this->totalMinutesInIntervals($teachingIntervals);
        $availabilityByFaculty = $this->indexFacultyAvailability($snapshot);
        $facultyBlocks = $this->facultyBlocksByFaculty($snapshot);
        $facultyCaps = $this->facultyUnitCaps($snapshot);
        $rooms = $this->normalizedRooms($snapshot);

        // 1. Tightest teacher by availability or load pressure
        $teacherPressure = [];
        $classGroups = $this->deduplicatedClassLoadGroups($demands, $context);
        foreach ($demands as $demandId => $demand) {
            $eligibleIds = $this->intList($demand['eligible_faculty_user_ids'] ?? []);
            if ($eligibleIds === []) {
                continue;
            }
            $weeklyMinutes = max(1, (int) ($demand['meeting_count'] ?? 1)) * max(30, (int) ($demand['required_duration_minutes'] ?? 60));
            $share = $weeklyMinutes / count($eligibleIds);
            foreach ($eligibleIds as $fid) {
                $teacherPressure[$fid]['minutes'] = ($teacherPressure[$fid]['minutes'] ?? 0.0) + $share;
                $teacherPressure[$fid]['classes'][] = $this->demandLabel($demandId, $context);
            }
        }
        foreach ($classGroups as $group) {
            $eligibleIds = $group['eligible_faculty_ids'];
            if ($eligibleIds === []) {
                continue;
            }
            $unitShare = $group['units'] / count($eligibleIds);
            foreach ($eligibleIds as $fid) {
                $teacherPressure[$fid]['units'] = ($teacherPressure[$fid]['units'] ?? 0.0) + $unitShare;
            }
        }

        $tightestTeacher = null;
        $tightestScore = 0.0;
        foreach ($teacherPressure as $fid => $stats) {
            $openMin = $this->facultyOpenWeeklyMinutes($fid, $teachingIntervals, $availabilityByFaculty[$fid] ?? null, $facultyBlocks[$fid] ?? []);
            $timeRatio = $openMin > 0 ? ($stats['minutes'] / $openMin) : 2.0;
            $cap = $facultyCaps[$fid] ?? null;
            $loadRatio = ($cap !== null && $cap > 0) ? (($stats['units'] ?? 0.0) / $cap) : 0.0;
            $score = max($timeRatio, $loadRatio);
            if ($score > $tightestScore) {
                $tightestScore = $score;
                $tightestTeacher = [
                    'id' => $fid,
                    'open_minutes' => $openMin,
                    'minutes' => (int) round($stats['minutes']),
                    'units' => round((float) ($stats['units'] ?? 0.0), 1),
                    'cap' => $cap,
                    'classes' => array_values(array_unique($stats['classes'] ?? [])),
                ];
            }
        }

        if ($tightestTeacher !== null) {
            $teacherName = $this->facultyLabel($tightestTeacher['id'], $context);
            $involved = [$teacherName, sprintf('%s open/week', $this->formatHours($tightestTeacher['open_minutes']))];
            if ($tightestTeacher['cap'] !== null) {
                $involved[] = sprintf('%s cap', $this->formatUnits($tightestTeacher['cap']));
            }
            foreach (array_slice($tightestTeacher['classes'], 0, 2) as $cls) {
                $involved[] = $cls;
            }

            $addItem('multiway-tightest-teacher', [
                'category' => 'Teacher availability',
                'severity' => 'warning',
                'title' => sprintf('Tightest teacher bottleneck: %s', $teacherName),
                'involved' => $involved,
                'why' => sprintf(
                    'Multiple constraints overlap at once, and %s is one of the tightest bottlenecks (%s open per week across %d eligible %s).',
                    $teacherName,
                    $this->formatHours($tightestTeacher['open_minutes']),
                    count($tightestTeacher['classes']),
                    Str::plural('class', count($tightestTeacher['classes'])),
                ),
                'how_to_fix' => [
                    sprintf('Widen %s\'s available days/hours or approve a load override in 3. Faculty & rooms.', $teacherName),
                    'Or qualify a backup teacher for one of their subjects so the solver has room to rearrange meetings.',
                ],
                'action_label' => 'Open 3. Faculty & rooms',
                'action_url' => $this->resourcesUrl($termId, 'faculty'),
            ]);
        }

        // 2. Tightest room type utilization
        if ($weeklyOpenMinutes > 0 && $rooms !== []) {
            $roomUtilization = [];
            foreach ($demands as $demand) {
                $roomRequired = (bool) ($demand['room_required'] ?? (($demand['modality'] ?? '') === TermOffering::ModalityFaceToFace));
                if (! $roomRequired) {
                    continue;
                }
                $typeKey = strtoupper(trim((string) ($demand['room_type_requirement'] ?? 'LECTURE'))) ?: 'LECTURE';
                $weeklyMin = max(1, (int) ($demand['meeting_count'] ?? 1)) * max(30, (int) ($demand['required_duration_minutes'] ?? 60));
                $roomUtilization[$typeKey] = ($roomUtilization[$typeKey] ?? 0) + $weeklyMin;
            }

            $tightestRoomType = null;
            $tightestRoomRatio = 0.0;
            foreach ($roomUtilization as $typeKey => $demandedMin) {
                $matchCount = count(array_filter($rooms, fn (array $r): bool => $this->roomMatchesType($r, $typeKey)));
                $availMin = $matchCount * $weeklyOpenMinutes;
                $ratio = $availMin > 0 ? ($demandedMin / $availMin) : 2.0;
                if ($ratio > $tightestRoomRatio) {
                    $tightestRoomRatio = $ratio;
                    $tightestRoomType = [
                        'type' => $typeKey,
                        'count' => $matchCount,
                        'demanded' => $demandedMin,
                        'available' => $availMin,
                        'pct' => (int) round($ratio * 100),
                    ];
                }
            }

            if ($tightestRoomType !== null && $tightestRoomRatio >= 0.5) {
                $typeLabel = $this->roomTypeLabel($tightestRoomType['type']);
                $addItem('multiway-tightest-rooms', [
                    'category' => 'Room shortage',
                    'severity' => 'warning',
                    'title' => sprintf('High room pressure on %s rooms (%d%% of weekly hours)', strtolower($typeLabel), $tightestRoomType['pct']),
                    'involved' => [
                        sprintf('%s rooms (%d active)', $typeLabel, $tightestRoomType['count']),
                        sprintf('%s needed of %s open', $this->formatHours($tightestRoomType['demanded']), $this->formatHours($tightestRoomType['available'])),
                    ],
                    'why' => sprintf(
                        'Combined with teacher hours and student-section schedules, %s rooms are heavily packed (%d%% full), leaving little room to resolve overlaps.',
                        strtolower($typeLabel),
                        $tightestRoomType['pct'],
                    ),
                    'how_to_fix' => [
                        sprintf('Add another active %s room in 3. Faculty & rooms (Rooms tab).', strtolower($typeLabel)),
                        'Or switch one or more classes to Online delivery in 2. Sections & classes.',
                    ],
                    'action_label' => 'Open 3. Faculty & rooms (Rooms)',
                    'action_url' => $this->resourcesUrl($termId, 'rooms'),
                ]);
            }
        }

        // 3. Most-constrained classes (fewest eligible teachers / locked slots / longest sessions)
        $rankedDemands = [];
        foreach ($demands as $demandId => $demand) {
            $eligibleCount = count($this->intList($demand['eligible_faculty_user_ids'] ?? []));
            $roomRequired = (bool) ($demand['room_required'] ?? (($demand['modality'] ?? '') === TermOffering::ModalityFaceToFace));
            $roomOptions = $roomRequired ? count($this->matchingRoomsForDemand($demand, $rooms)) : 10;
            $hasFixed = ($demand['fixed_day_of_week'] ?? null) !== null
                || ($demand['fixed_start_time'] ?? null) !== null
                || ($demand['fixed_faculty_user_id'] ?? null) !== null
                || ($demand['fixed_room_id'] ?? null) !== null
                || ! empty($demand['fixed_meetings']);
            $flexScore = max(1, $eligibleCount) * max(1, $roomOptions) * ($hasFixed ? 0.5 : 1.0);
            $rankedDemands[] = [
                'id' => $demandId,
                'label' => $this->demandLabel($demandId, $context),
                'flex' => $flexScore,
                'eligible_count' => $eligibleCount,
                'room_options' => $roomOptions,
                'has_fixed' => $hasFixed,
            ];
        }

        usort($rankedDemands, fn (array $a, array $b): int => $a['flex'] <=> $b['flex']);
        $topTight = array_slice($rankedDemands, 0, 3);

        if ($topTight !== []) {
            $tightLabels = array_column($topTight, 'label');
            $hasAnyFixed = collect($topTight)->contains(fn (array $d): bool => $d['has_fixed']);

            $addItem('multiway-most-constrained-classes', [
                'category' => 'Unassignable class',
                'severity' => 'warning',
                'title' => 'Most-constrained classes leaving the solver with fewest options',
                'involved' => $tightLabels,
                'why' => 'No single rule failed alone, but the combination of teacher windows, room types, locked slots, and student-section schedules left no valid placement for every class at once.',
                'how_to_fix' => array_values(array_filter([
                    $hasAnyFixed ? 'Unlock any fixed day, time, teacher, or room locks on these classes in 2. Sections & classes.' : null,
                    'Qualify an additional teacher or widen teacher availability in 3. Faculty & rooms.',
                    'Extend teaching hours or add another teaching day in 1. Calendar to give the solver more slots.',
                ])),
                'action_label' => 'Edit in 2. Sections & classes',
                'action_url' => $this->classesUrl($termId, 'classes'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $demands
     * @param  Collection<int, CandidateScheduleRow>  $candidateRows
     * @return array{
     *     faculty_names: array<int, string>,
     *     room_labels: array<int, string>,
     *     cohort_labels: array<int, string>,
     *     demand_labels: array<int, string>,
     *     demand_courses: array<int, string>,
     * }
     */
    private function buildEntityContext(
        array $snapshot,
        array $demands,
        Collection $candidateRows,
        ?Term $term,
    ): array {
        $facultyIds = [];
        $roomIds = [];
        $sectionIds = [];
        $cohortIds = [];
        $demandIds = array_keys($demands);

        foreach ($demands as $demand) {
            foreach ($this->intList($demand['eligible_faculty_user_ids'] ?? []) as $fid) {
                $facultyIds[$fid] = $fid;
            }
            if (($fid = $this->nullableInt($demand['fixed_faculty_user_id'] ?? null)) !== null) {
                $facultyIds[$fid] = $fid;
            }
            if (($rid = $this->nullableInt($demand['fixed_room_id'] ?? null)) !== null) {
                $roomIds[$rid] = $rid;
            }
            if (($sid = $this->nullableInt($demand['section_id'] ?? null)) !== null && $sid > 0) {
                $sectionIds[$sid] = $sid;
            }
            foreach ($this->intList($demand['cohort_or_student_group_ids'] ?? []) as $cid) {
                $cohortIds[$cid] = $cid;
            }
            foreach (is_array($demand['fixed_meetings'] ?? null) ? $demand['fixed_meetings'] : [] as $pin) {
                if (! is_array($pin)) {
                    continue;
                }
                if (($fid = $this->nullableInt($pin['fixed_faculty_user_id'] ?? null)) !== null) {
                    $facultyIds[$fid] = $fid;
                }
                if (($rid = $this->nullableInt($pin['fixed_room_id'] ?? null)) !== null) {
                    $roomIds[$rid] = $rid;
                }
            }
        }

        foreach (is_array($snapshot['faculty'] ?? null) ? $snapshot['faculty'] : [] as $fRow) {
            if (is_array($fRow) && ($fid = $this->nullableInt($fRow['faculty_id'] ?? null)) !== null) {
                $facultyIds[$fid] = $fid;
            }
        }

        foreach (is_array($snapshot['faculty_availability'] ?? null) ? $snapshot['faculty_availability'] : [] as $aRow) {
            if (is_array($aRow) && ($fid = $this->nullableInt($aRow['faculty_user_id'] ?? null)) !== null) {
                $facultyIds[$fid] = $fid;
            }
        }

        foreach ($candidateRows as $row) {
            if ($row->faculty_user_id !== null) {
                $facultyIds[(int) $row->faculty_user_id] = (int) $row->faculty_user_id;
            }
            if ($row->room_id !== null) {
                $roomIds[(int) $row->room_id] = (int) $row->room_id;
            }
            if ($row->scheduling_demand_id !== null) {
                $demandIds[] = (int) $row->scheduling_demand_id;
            }
        }

        $demandIds = array_values(array_unique(array_filter($demandIds, fn (int $id): bool => $id > 0)));

        $facultyNames = $facultyIds !== []
            ? User::query()->whereIn('id', array_values($facultyIds))->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all()
            : [];

        $roomLabels = [];
        foreach (is_array($snapshot['rooms'] ?? null) ? $snapshot['rooms'] : [] as $roomRow) {
            if (! is_array($roomRow) || ! isset($roomRow['room_id'])) {
                continue;
            }
            $rid = (int) $roomRow['room_id'];
            $code = trim((string) ($roomRow['code'] ?? ''));
            $name = trim((string) ($roomRow['name'] ?? ''));
            $roomLabels[$rid] = $code !== ''
                ? ($name !== '' && strcasecmp($code, $name) !== 0 ? "{$code} · {$name}" : $code)
                : ($name !== '' ? $name : 'Assigned room');
        }

        $missingRoomIds = array_values(array_diff(array_values($roomIds), array_keys($roomLabels)));
        if ($missingRoomIds !== []) {
            foreach (Room::query()->whereIn('id', $missingRoomIds)->get(['id', 'code', 'name']) as $room) {
                $code = trim((string) $room->code);
                $name = trim((string) $room->name);
                $roomLabels[(int) $room->id] = $code !== ''
                    ? ($name !== '' && strcasecmp($code, $name) !== 0 ? "{$code} · {$name}" : $code)
                    : ($name !== '' ? $name : 'Assigned room');
            }
        }

        $cohortLabels = $cohortIds !== []
            ? TermCohort::query()->whereIn('id', array_values($cohortIds))->pluck('reference', 'id')->map(fn (mixed $r): string => trim((string) $r))->filter()->all()
            : [];

        $sectionsById = $sectionIds !== []
            ? Section::query()->whereIn('id', array_values($sectionIds))->with('cohorts:id,reference')->get(['id', 'class_reference', 'code'])->keyBy('id')
            : collect();

        foreach ($sectionsById as $section) {
            foreach ($section->cohorts as $cohort) {
                if (trim((string) $cohort->reference) !== '') {
                    $cohortLabels[(int) $cohort->id] ??= trim((string) $cohort->reference);
                }
            }
        }

        // Load DB demand metadata as fallback for section/course/component names
        $dbDemands = $demandIds !== []
            ? SchedulingDemand::query()
                ->whereIn('id', $demandIds)
                ->with([
                    'courseComponent.courseSpecification.course:id,code',
                    'sectionDeliveryGroup.section:id,class_reference,code',
                    'sectionDeliveryGroup.section.cohorts:id,reference',
                    'termOffering.curriculumEntry.courseSpecification.course:id,code',
                ])
                ->get()
                ->keyBy('id')
            : collect();

        $demandLabels = [];
        $demandCourses = [];

        foreach ($demandIds as $demandId) {
            $snapDemand = $demands[$demandId] ?? null;
            /** @var SchedulingDemand|null $dbDemand */
            $dbDemand = $dbDemands->get($demandId);

            $courseCode = trim((string) (
                ($snapDemand['course_code'] ?? null)
                ?: $dbDemand?->courseComponent?->courseSpecification?->course?->code
                ?: $dbDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code
                ?: data_get($dbDemand?->source_snapshot, 'course_code', '')
            ));

            $sectionLabel = '';
            $snapCohortIds = is_array($snapDemand) ? $this->intList($snapDemand['cohort_or_student_group_ids'] ?? []) : [];
            $resolvedCohorts = array_values(array_filter(array_map(
                fn (int $cid): string => $cohortLabels[$cid] ?? '',
                $snapCohortIds,
            )));
            if ($resolvedCohorts !== []) {
                $sectionLabel = implode(', ', array_unique($resolvedCohorts));
            } else {
                $sid = is_array($snapDemand) ? $this->nullableInt($snapDemand['section_id'] ?? null) : null;
                $sec = $sid !== null ? $sectionsById->get($sid) : $dbDemand?->sectionDeliveryGroup?->section;
                if ($sec instanceof Section) {
                    $secCohorts = $sec->relationLoaded('cohorts')
                        ? $sec->cohorts->pluck('reference')->filter()->unique()->values()->all()
                        : [];
                    if ($secCohorts !== []) {
                        $sectionLabel = implode(', ', $secCohorts);
                    } else {
                        $rawSec = trim((string) ($sec->class_reference ?: $sec->code ?: ''));
                        if ($courseCode !== '' && str_starts_with(strtoupper($rawSec), strtoupper($courseCode))) {
                            $rawSec = trim((string) preg_replace('/^'.preg_quote($courseCode, '/').'\s*[·\-:]\s*/iu', '', $rawSec));
                        }
                        $sectionLabel = $rawSec;
                    }
                }
            }

            $componentType = strtoupper(trim((string) (
                ($snapDemand['component_type'] ?? null)
                ?: $dbDemand?->courseComponent?->component_type
                ?: data_get($dbDemand?->source_snapshot, 'component_type', '')
            )));
            $componentLabel = match ($componentType) {
                'LECTURE' => 'Lecture',
                'LABORATORY' => 'Lab',
                default => '',
            };

            $parts = [];
            if ($courseCode !== '') {
                $parts[] = $courseCode;
                $demandCourses[$demandId] = $courseCode;
            }
            if ($sectionLabel !== '' && strcasecmp($sectionLabel, $courseCode) !== 0) {
                $parts[] = $sectionLabel;
            }
            $base = $parts !== [] ? implode(' · ', $parts) : 'Scheduled class';
            $demandLabels[$demandId] = $componentLabel !== '' ? sprintf('%s (%s)', $base, $componentLabel) : $base;
        }

        return [
            'faculty_names' => $facultyNames,
            'room_labels' => $roomLabels,
            'cohort_labels' => $cohortLabels,
            'demand_labels' => $demandLabels,
            'demand_courses' => $demandCourses,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function normalizedDemands(array $snapshot): array
    {
        $demands = [];
        foreach (is_array($snapshot['scheduling_demands'] ?? null) ? $snapshot['scheduling_demands'] : [] as $demand) {
            if (! is_array($demand)) {
                continue;
            }
            $id = $this->nullableInt($demand['scheduling_demand_id'] ?? null);
            if ($id === null || $id <= 0) {
                continue;
            }
            $demands[$id] = $demand;
        }

        return $demands;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function normalizedRooms(array $snapshot): array
    {
        return array_values(array_filter(
            is_array($snapshot['rooms'] ?? null) ? $snapshot['rooms'] : [],
            'is_array',
        ));
    }

    /**
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     */
    private function matchingRoomsForDemand(array $demand, array $rooms): array
    {
        $reqType = strtoupper(trim((string) ($demand['room_type_requirement'] ?? '')));
        $seatsNeeded = max(0, (int) ($demand['expected_count'] ?? 0));
        $requiredFeatures = $this->stringList($demand['required_room_feature_keys'] ?? []);

        return array_values(array_filter($rooms, function (array $room) use ($reqType, $seatsNeeded, $requiredFeatures): bool {
            if ($reqType !== '' && ! $this->roomMatchesType($room, $reqType)) {
                return false;
            }
            if ((int) ($room['capacity'] ?? 0) < $seatsNeeded) {
                return false;
            }
            if ($requiredFeatures !== []) {
                $roomFeatures = array_map('strtoupper', $this->stringList($room['feature_keys'] ?? []));
                foreach ($requiredFeatures as $feature) {
                    if (! in_array(strtoupper($feature), $roomFeatures, true)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function roomMatchesType(array $room, string $requiredType): bool
    {
        $roomType = strtoupper(trim((string) ($room['room_type'] ?? '')));
        $req = strtoupper(trim($requiredType));

        return $req === '' || $roomType === $req;
    }

    /**
     * Build open teaching intervals per day (teaching_grid_rows minus Institution breaks).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, list<array{start:int,end:int}>>
     */
    private function teachingIntervalsByDay(array $snapshot): array
    {
        $termSnap = is_array($snapshot['term'] ?? null) ? $snapshot['term'] : [];
        $gridRows = is_array($termSnap['teaching_grid_rows'] ?? null) ? $termSnap['teaching_grid_rows'] : [];
        $intervals = [];

        foreach ($gridRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $day = (int) ($row['day_of_week'] ?? 0);
            $start = $this->clockToMinutes($row['starts_at'] ?? null);
            $end = $this->clockToMinutes($row['ends_at'] ?? null);
            if ($day >= 1 && $day <= 7 && $start !== null && $end !== null && $end > $start) {
                $intervals[$day][] = ['start' => $start, 'end' => $end];
                foreach (is_array($row['breaks'] ?? null) ? $row['breaks'] : [] as $break) {
                    if (! is_array($break)) {
                        continue;
                    }
                    $bStart = $this->clockToMinutes($break['starts_at'] ?? null);
                    $bEnd = $this->clockToMinutes($break['ends_at'] ?? null);
                    if ($bStart !== null && $bEnd !== null && $bEnd > $bStart) {
                        $intervals[$day] = $this->subtractInterval($intervals[$day], $bStart, $bEnd);
                    }
                }
            }
        }

        if ($intervals === []) {
            $days = $this->intList($termSnap['scheduling_days'] ?? []);
            $start = $this->clockToMinutes($termSnap['scheduling_day_starts_at'] ?? null);
            $end = $this->clockToMinutes($termSnap['scheduling_day_ends_at'] ?? null);
            if ($start !== null && $end !== null && $end > $start) {
                foreach ($days as $day) {
                    $intervals[$day][] = ['start' => $start, 'end' => $end];
                }
            }
        }

        // Subtract Institution-wide blocks (including TeachingGridBreak)
        foreach (is_array($snapshot['calendar_blocks'] ?? null) ? $snapshot['calendar_blocks'] : [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['scope_type'] ?? null) !== 'Institution' || ! empty($block['faculty_user_id']) || ! empty($block['room_id'])) {
                continue;
            }
            $day = (int) ($block['day_of_week'] ?? 0);
            $bStart = $this->clockToMinutes($block['starts_at'] ?? null);
            $bEnd = $this->clockToMinutes($block['ends_at'] ?? null);
            if ($bStart === null || $bEnd === null || $bEnd <= $bStart || ! isset($intervals[$day])) {
                continue;
            }
            $intervals[$day] = $this->subtractInterval($intervals[$day], $bStart, $bEnd);
        }

        return $intervals;
    }

    /**
     * @param  list<array{start:int,end:int}>  $intervals
     * @return list<array{start:int,end:int}>
     */
    private function subtractInterval(array $intervals, int $cutStart, int $cutEnd): array
    {
        $result = [];
        foreach ($intervals as $interval) {
            $s = $interval['start'];
            $e = $interval['end'];
            if ($cutEnd <= $s || $cutStart >= $e) {
                $result[] = $interval;

                continue;
            }
            if ($cutStart > $s) {
                $result[] = ['start' => $s, 'end' => $cutStart];
            }
            if ($cutEnd < $e) {
                $result[] = ['start' => $cutEnd, 'end' => $e];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, list<array{start:int,end:int}>>  $intervalsByDay
     */
    private function maxContinuousOpenMinutes(array $intervalsByDay): int
    {
        $max = 0;
        foreach ($intervalsByDay as $intervals) {
            foreach ($intervals as $interval) {
                $max = max($max, max(0, $interval['end'] - $interval['start']));
            }
        }

        return $max;
    }

    /**
     * @param  array<int, list<array{start:int,end:int}>>  $intervalsByDay
     */
    private function totalMinutesInIntervals(array $intervalsByDay): int
    {
        $total = 0;
        foreach ($intervalsByDay as $intervals) {
            foreach ($intervals as $interval) {
                $total += max(0, $interval['end'] - $interval['start']);
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, ?float>
     */
    private function facultyUnitCaps(array $snapshot): array
    {
        $defaultCap = is_numeric(data_get($snapshot, 'term.default_max_units'))
            ? (float) data_get($snapshot, 'term.default_max_units')
            : null;
        $cannotTeachIds = [];

        foreach (is_array($snapshot['faculty_availability'] ?? null) ? $snapshot['faculty_availability'] : [] as $availability) {
            if (! is_array($availability) || ! isset($availability['faculty_user_id'])) {
                continue;
            }

            $mode = (string) ($availability['availability_mode'] ?? '');
            $declaration = (string) ($availability['declaration'] ?? '');

            if ($mode === FacultyAvailabilityDeclaration::ModeCannotTeach
                || $declaration === FacultyAvailabilityDeclaration::DeclarationUnavailable) {
                $cannotTeachIds[(int) $availability['faculty_user_id']] = true;
            }
        }

        $caps = [];

        foreach (is_array($snapshot['faculty'] ?? null) ? $snapshot['faculty'] : [] as $row) {
            if (! is_array($row) || ! isset($row['faculty_id'])) {
                continue;
            }
            $fid = (int) $row['faculty_id'];
            if (isset($cannotTeachIds[$fid])) {
                continue;
            }
            $cap = is_numeric($row['max_allowed_units'] ?? null)
                ? (float) $row['max_allowed_units']
                : $defaultCap;
            $caps[$fid] = $cap;
        }

        return $caps;
    }

    /**
     * Deduplicate teaching load units per class (`term_offering_id:section_delivery_group_id`).
     *
     * @param  array<int, array<string, mixed>>  $demands
     * @param  array<string, mixed>  $context
     * @return list<array{key:string,course_id:?int,course_code:string,label:string,units:float,eligible_faculty_ids:list<int>,fixed_faculty_id:?int}>
     */
    private function deduplicatedClassLoadGroups(array $demands, array $context): array
    {
        $groups = [];
        foreach ($demands as $demandId => $demand) {
            $offeringId = (int) ($demand['term_offering_id'] ?? 0);
            $groupId = (int) ($demand['section_delivery_group_id'] ?? 0);
            $key = ($offeringId > 0 && $groupId > 0) ? ($offeringId.':'.$groupId) : ('demand:'.$demandId);
            $units = is_numeric($demand['load_units'] ?? null) ? (float) $demand['load_units'] : 0.0;
            $eligible = $this->intList($demand['eligible_faculty_user_ids'] ?? []);
            $fixedFacultyId = $this->nullableInt($demand['fixed_faculty_user_id'] ?? null);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'course_id' => $this->nullableInt($demand['course_id'] ?? null),
                    'course_code' => trim((string) ($demand['course_code'] ?? $context['demand_courses'][$demandId] ?? '')),
                    'label' => $this->demandLabel($demandId, $context),
                    'units' => $units,
                    'eligible_faculty_ids' => $eligible,
                    'fixed_faculty_id' => $fixedFacultyId,
                ];
            } else {
                $groups[$key]['units'] = max($groups[$key]['units'], $units);
                if ($groups[$key]['fixed_faculty_id'] === null && $fixedFacultyId !== null) {
                    $groups[$key]['fixed_faculty_id'] = $fixedFacultyId;
                }
                if ($eligible !== []) {
                    $intersection = array_values(array_intersect($groups[$key]['eligible_faculty_ids'], $eligible));
                    if ($intersection !== []) {
                        $groups[$key]['eligible_faculty_ids'] = $intersection;
                    }
                }
            }
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{mode:string,windows:list<array{day:int,start:int,end:int}>,unavailability:list<array{day:int,start:int,end:int}>}>
     */
    private function indexFacultyAvailability(array $snapshot): array
    {
        $indexed = [];
        foreach (is_array($snapshot['faculty_availability'] ?? null) ? $snapshot['faculty_availability'] : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['faculty_user_id'])) {
                continue;
            }
            $fid = (int) $entry['faculty_user_id'];
            $mode = (string) ($entry['availability_mode'] ?? 'any');
            $windows = [];
            foreach (is_array($entry['teaching_windows'] ?? null) ? $entry['teaching_windows'] : [] as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $day = (int) ($w['day_of_week'] ?? 0);
                $s = $this->clockToMinutes($w['start_time'] ?? $w['starts_at'] ?? null);
                $e = $this->clockToMinutes($w['end_time'] ?? $w['ends_at'] ?? null);
                if ($day >= 1 && $day <= 7 && $s !== null && $e !== null && $e > $s) {
                    $windows[] = ['day' => $day, 'start' => $s, 'end' => $e];
                }
            }
            $unavailability = [];
            foreach (is_array($entry['hard_unavailability'] ?? null) ? $entry['hard_unavailability'] : [] as $u) {
                if (! is_array($u)) {
                    continue;
                }
                $day = (int) ($u['day_of_week'] ?? 0);
                $s = $this->clockToMinutes($u['starts_at'] ?? $u['start_time'] ?? null);
                $e = $this->clockToMinutes($u['ends_at'] ?? $u['end_time'] ?? null);
                if ($day >= 1 && $day <= 7 && $s !== null && $e !== null && $e > $s) {
                    $unavailability[] = ['day' => $day, 'start' => $s, 'end' => $e];
                }
            }

            $indexed[$fid] = [
                'mode' => $mode,
                'windows' => $windows,
                'unavailability' => $unavailability,
            ];
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, list<array{day:int,start:int,end:int}>>
     */
    private function facultyBlocksByFaculty(array $snapshot): array
    {
        $blocks = [];
        foreach (is_array($snapshot['calendar_blocks'] ?? null) ? $snapshot['calendar_blocks'] : [] as $block) {
            if (! is_array($block) || ($block['scope_type'] ?? null) !== 'Faculty' || empty($block['faculty_user_id'])) {
                continue;
            }
            $fid = (int) $block['faculty_user_id'];
            $day = (int) ($block['day_of_week'] ?? 0);
            $s = $this->clockToMinutes($block['starts_at'] ?? null);
            $e = $this->clockToMinutes($block['ends_at'] ?? null);
            if ($day >= 1 && $day <= 7 && $s !== null && $e !== null && $e > $s) {
                $blocks[$fid][] = ['day' => $day, 'start' => $s, 'end' => $e];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, list<array{start:int,end:int}>>  $teachingIntervals
     * @return array<int, int>
     */
    private function roomBlockMinutesByRoom(array $snapshot, array $teachingIntervals): array
    {
        $blocked = [];
        foreach (is_array($snapshot['calendar_blocks'] ?? null) ? $snapshot['calendar_blocks'] : [] as $block) {
            if (! is_array($block) || ($block['scope_type'] ?? null) !== 'Room' || empty($block['room_id'])) {
                continue;
            }
            $rid = (int) $block['room_id'];
            $day = (int) ($block['day_of_week'] ?? 0);
            $s = $this->clockToMinutes($block['starts_at'] ?? null);
            $e = $this->clockToMinutes($block['ends_at'] ?? null);
            if ($s === null || $e === null || $e <= $s) {
                continue;
            }
            foreach ($teachingIntervals[$day] ?? [] as $interval) {
                $overlap = max(0, min($e, $interval['end']) - max($s, $interval['start']));
                $blocked[$rid] = ($blocked[$rid] ?? 0) + $overlap;
            }
        }

        return $blocked;
    }

    /**
     * Collect unique daily break time ranges from calendar_blocks and teaching_grid_rows.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{start:int,end:int}>
     */
    private function dailyBreakRanges(array $snapshot): array
    {
        $ranges = [];

        foreach (is_array($snapshot['calendar_blocks'] ?? null) ? $snapshot['calendar_blocks'] : [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['scope_type'] ?? null) !== 'Institution'
                || ! empty($block['faculty_user_id'])
                || ! empty($block['room_id'])
                || (string) ($block['event_type'] ?? '') !== 'TeachingGridBreak') {
                continue;
            }
            $bStart = $this->clockToMinutes($block['starts_at'] ?? null);
            $bEnd = $this->clockToMinutes($block['ends_at'] ?? null);
            if ($bStart !== null && $bEnd !== null && $bEnd > $bStart) {
                $ranges[$bStart.':'.$bEnd] = ['start' => $bStart, 'end' => $bEnd];
            }
        }

        $termSnap = is_array($snapshot['term'] ?? null) ? $snapshot['term'] : [];
        foreach (is_array($termSnap['teaching_grid_rows'] ?? null) ? $termSnap['teaching_grid_rows'] : [] as $row) {
            if (! is_array($row) || ! is_array($row['breaks'] ?? null)) {
                continue;
            }
            foreach ($row['breaks'] as $break) {
                if (! is_array($break)) {
                    continue;
                }
                $bStart = $this->clockToMinutes($break['starts_at'] ?? null);
                $bEnd = $this->clockToMinutes($break['ends_at'] ?? null);
                if ($bStart !== null && $bEnd !== null && $bEnd > $bStart) {
                    $ranges[$bStart.':'.$bEnd] = ['start' => $bStart, 'end' => $bEnd];
                }
            }
        }

        $list = array_values($ranges);
        usort($list, fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]);

        return $list;
    }

    /**
     * @param  list<array{start:int,end:int}>  $dailyBreaks
     */
    private function formatDailyBreakSummary(array $dailyBreaks): ?string
    {
        if ($dailyBreaks === []) {
            return null;
        }

        $formatted = array_map(
            fn (array $range): string => $this->formatTimeRange($range['start'], $range['end']),
            array_slice($dailyBreaks, 0, 2),
        );

        return count($dailyBreaks) === 1
            ? 'Daily break: '.$formatted[0]
            : 'Daily breaks: '.implode(', ', $formatted);
    }

    /**
     * @param  list<array{start:int,end:int}>  $dailyBreaks
     */
    private function formatDailyBreakTimesText(array $dailyBreaks): string
    {
        if ($dailyBreaks === []) {
            return '';
        }

        return implode(', ', array_map(
            fn (array $range): string => $this->formatTimeRange($range['start'], $range['end']),
            array_slice($dailyBreaks, 0, 2),
        ));
    }

    /**
     * Count distinct days that contain at least one uninterrupted open interval of at least $durationMinutes.
     *
     * @param  array<int, list<array{start:int,end:int}>>  $intervalsByDay
     */
    private function usableDaysCount(array $intervalsByDay, int $durationMinutes): int
    {
        $usableDays = 0;

        foreach ($intervalsByDay as $intervals) {
            foreach ($intervals as $interval) {
                if (($interval['end'] - $interval['start']) >= $durationMinutes) {
                    $usableDays++;
                    break;
                }
            }
        }

        return $usableDays;
    }

    /**
     * Resolve uninterrupted open teaching intervals per day (1..7) for a faculty member
     * after intersecting calendar teaching hours (already minus breaks) with declared
     * availability windows and subtracting unavailability and faculty blocks.
     *
     * @param  array<int, list<array{start:int,end:int}>>  $teachingIntervals
     * @param  array{mode:string,windows:list<array{day:int,start:int,end:int}>,unavailability:list<array{day:int,start:int,end:int}>}|null  $availability
     * @param  list<array{day:int,start:int,end:int}>  $facultyBlocks
     * @return array<int, list<array{start:int,end:int}>>
     */
    private function facultyEffectiveDayIntervals(
        int $facultyId,
        array $teachingIntervals,
        ?array $availability,
        array $facultyBlocks,
    ): array {
        if ($availability !== null && $availability['mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return [];
        }

        $openByDay = [];

        if ($availability !== null && $availability['mode'] === FacultyAvailabilityDeclaration::ModeWindows) {
            $windowsByDay = [];
            foreach ($availability['windows'] as $window) {
                $day = (int) $window['day'];
                if ($day >= 1 && $day <= 7 && $window['end'] > $window['start']) {
                    $windowsByDay[$day][] = ['start' => $window['start'], 'end' => $window['end']];
                }
            }

            foreach ($windowsByDay as $day => $dayWindows) {
                $mergedWindows = FacultyAvailabilityDeclaration::mergeIntervals($dayWindows);
                $dayGrid = FacultyAvailabilityDeclaration::mergeIntervals($teachingIntervals[$day] ?? []);
                foreach ($mergedWindows as $window) {
                    foreach ($dayGrid as $grid) {
                        $start = max($window['start'], $grid['start']);
                        $end = min($window['end'], $grid['end']);
                        if ($end > $start) {
                            $openByDay[$day][] = ['start' => $start, 'end' => $end];
                        }
                    }
                }
                if (isset($openByDay[$day])) {
                    $openByDay[$day] = FacultyAvailabilityDeclaration::mergeIntervals($openByDay[$day]);
                }
            }
        } else {
            foreach ($teachingIntervals as $day => $dayGrid) {
                if ($day >= 1 && $day <= 7) {
                    $merged = FacultyAvailabilityDeclaration::mergeIntervals($dayGrid);
                    if ($merged !== []) {
                        $openByDay[$day] = $merged;
                    }
                }
            }
        }

        if ($availability !== null) {
            foreach ($availability['unavailability'] as $unavail) {
                $day = (int) $unavail['day'];
                if (isset($openByDay[$day])) {
                    $openByDay[$day] = $this->subtractInterval($openByDay[$day], $unavail['start'], $unavail['end']);
                }
            }
        }

        foreach ($facultyBlocks as $block) {
            $day = (int) $block['day'];
            if (isset($openByDay[$day])) {
                $openByDay[$day] = $this->subtractInterval($openByDay[$day], $block['start'], $block['end']);
            }
        }

        $result = [];
        for ($day = 1; $day <= 7; $day++) {
            if (! isset($openByDay[$day])) {
                continue;
            }
            $clean = FacultyAvailabilityDeclaration::mergeIntervals($openByDay[$day]);
            if ($clean !== []) {
                $result[$day] = $clean;
            }
        }

        return $result;
    }

    /**
     * @param  array{mode:string,windows:list<array{day:int,start:int,end:int}>,unavailability:list<array{day:int,start:int,end:int}>}|null  $avail
     * @param  array<int, list<array{start:int,end:int}>>  $teachingIntervals
     * @param  list<array{day:int,start:int,end:int}>  $facultyBlocks
     */
    private function facultyOpenWeeklyMinutes(
        int $facultyId,
        array $teachingIntervals,
        ?array $avail,
        array $facultyBlocks,
    ): int {
        return $this->totalMinutesInIntervals(
            $this->facultyEffectiveDayIntervals($facultyId, $teachingIntervals, $avail, $facultyBlocks),
        );
    }

    /**
     * @param  array{mode:string,windows:list<array{day:int,start:int,end:int}>,unavailability:list<array{day:int,start:int,end:int}>}  $avail
     */
    private function isFacultyAvailableForSlot(array $avail, int $day, int $start, int $end): bool
    {
        if ($avail['mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return false;
        }

        if ($avail['mode'] === FacultyAvailabilityDeclaration::ModeWindows) {
            $fits = false;
            foreach ($avail['windows'] as $w) {
                if ($w['day'] === $day && $w['start'] <= $start && $w['end'] >= $end) {
                    $fits = true;
                    break;
                }
            }
            if (! $fits) {
                return false;
            }
        }

        foreach ($avail['unavailability'] as $u) {
            if ($u['day'] === $day && $start < $u['end'] && $end > $u['start']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function isCancelledOrTechnicalFailureOnly(ScheduleGenerationRun $run, array $diagnostics): bool
    {
        $dispatchStatus = strtolower(trim((string) data_get($diagnostics, 'solver_dispatch.status', '')));
        $latestOutcome = strtolower(trim((string) data_get($diagnostics, 'solver_dispatch.latest_outcome', '')));
        $failureCode = strtoupper(trim((string) data_get($diagnostics, 'solver_dispatch.failure.code', '')));
        $reviewReason = strtolower(trim((string) ($run->candidate_review_reason ?? '')));

        if (
            $dispatchStatus === 'cancelled'
            || $latestOutcome === 'cancelled'
            || $failureCode === 'CANCELLED_BY_REGISTRAR'
            || str_contains($reviewReason, 'cancelled while in progress')
        ) {
            return true;
        }

        if ($run->candidate_state === 'Stale') {
            return false;
        }

        $solverStatus = strtolower(trim((string) data_get($diagnostics, 'solver_result.solver_status', '')));
        $outcome = trim((string) data_get($diagnostics, 'solver_result.summary.outcome', ''));
        $hasFindings = ! empty($diagnostics['findings'])
            || ! empty(data_get($diagnostics, 'solver_result.findings'))
            || ! empty(data_get($diagnostics, 'validation.findings'));

        if ($hasFindings || in_array($solverStatus, ['infeasible', 'model_invalid'], true) || in_array($outcome, ['Infeasible', 'ModelInvalid'], true)) {
            return false;
        }

        if ($outcome === 'TechnicalFailure' || $solverStatus === 'technical_failure') {
            return true;
        }

        return is_array(data_get($diagnostics, 'solver_dispatch.failure'))
            && empty($diagnostics['solver_result']);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function isFailedOrInfeasibleRun(ScheduleGenerationRun $run, array $diagnostics): bool
    {
        if ($this->isCancelledOrTechnicalFailureOnly($run, $diagnostics)) {
            return false;
        }

        if (in_array($run->status, [ScheduleGenerationRun::StatusBlocked, ScheduleGenerationRun::StatusFailed], true)) {
            return true;
        }

        $solverStatus = strtolower((string) data_get($diagnostics, 'solver_result.solver_status', ''));
        $outcome = (string) data_get($diagnostics, 'solver_result.summary.outcome', '');

        return in_array($solverStatus, ['infeasible', 'model_invalid', 'unknown'], true)
            || in_array($outcome, ['Infeasible', 'ModelInvalid'], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $demands
     * @return list<int>
     */
    private function rowCohorts(CandidateScheduleRow $row, array $demands): array
    {
        $demandId = (int) $row->scheduling_demand_id;
        if (isset($demands[$demandId])) {
            $ids = $this->intList($demands[$demandId]['cohort_or_student_group_ids'] ?? []);
            if ($ids !== []) {
                return $ids;
            }
        }

        $section = $row->schedulingDemand?->sectionDeliveryGroup?->section;
        if ($section instanceof Section && $section->relationLoaded('cohorts')) {
            return $section->cohorts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function rowLabel(CandidateScheduleRow $row, array $context): string
    {
        $demandId = (int) $row->scheduling_demand_id;
        if ($demandId > 0 && isset($context['demand_labels'][$demandId])) {
            return $context['demand_labels'][$demandId];
        }

        $courseCode = trim((string) ($row->schedulingDemand?->courseComponent?->courseSpecification?->course?->code ?? ''));
        $section = $row->schedulingDemand?->sectionDeliveryGroup?->section;
        $sectionRef = $section instanceof Section ? trim((string) ($section->class_reference ?: $section->code ?: '')) : '';

        return trim(implode(' · ', array_filter([$courseCode, $sectionRef]))) ?: 'Scheduled class';
    }

    private function extractRowViolationReason(CandidateScheduleRow $row): string
    {
        $violations = $row->violations;
        if (! is_array($violations)) {
            return '';
        }

        $items = $violations['items'] ?? $violations;
        if (! is_array($items)) {
            return '';
        }

        foreach ($items as $item) {
            if (is_array($item) && ! empty($item['message'])) {
                return trim((string) $item['message']);
            }
            if (is_string($item) && trim($item) !== '') {
                return trim($item);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function demandLabel(int $demandId, array $context): string
    {
        return $context['demand_labels'][$demandId] ?? 'Scheduled class';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function facultyLabel(int $facultyId, array $context): string
    {
        return $context['faculty_names'][$facultyId] ?? 'Assigned teacher';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function roomLabel(int $roomId, array $context): string
    {
        return $context['room_labels'][$roomId] ?? 'Assigned room';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function cohortLabel(int $cohortId, array $context): string
    {
        return $context['cohort_labels'][$cohortId] ?? 'Student section';
    }

    private function roomTypeLabel(string $roomType): string
    {
        $trimmed = trim($roomType);
        if ($trimmed === '') {
            return 'Lecture';
        }

        $options = Room::typeOptions();
        if (isset($options[$trimmed])) {
            return (string) $options[$trimmed];
        }

        $upper = strtoupper($trimmed);
        if (isset($options[$upper])) {
            return (string) $options[$upper];
        }

        return match ($upper) {
            'LABORATORY', 'LAB' => 'Laboratory',
            'LECTURE', 'CLASSROOM' => 'Lecture',
            default => Str::headline(strtolower($trimmed)),
        };
    }

    private function dayShort(int $day): string
    {
        return self::DAY_SHORT[$day] ?? 'Day';
    }

    private function dayFull(int $day): string
    {
        return self::DAY_FULL[$day] ?? 'this day';
    }

    private function formatTimeRange(int $startMinutes, int $endMinutes): string
    {
        return $this->minutesToClock($startMinutes).'–'.$this->minutesToClock($endMinutes);
    }

    private function minutesToClock(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $min = $minutes % 60;
        $hour12 = $hour % 12 ?: 12;

        return sprintf('%d:%02d %s', $hour12, $min, $hour < 12 ? 'AM' : 'PM');
    }

    private function formatDuration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;
        if ($hours > 0 && $rem > 0) {
            return sprintf('%d %s %d min', $hours, Str::plural('hr', $hours), $rem);
        }
        if ($hours > 0) {
            return sprintf('%d %s', $hours, Str::plural('hr', $hours));
        }

        return sprintf('%d min', $minutes);
    }

    private function formatHours(int $minutes): string
    {
        $hours = round($minutes / 60, 1);
        $formatted = rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');

        return sprintf('%s %s', $formatted, abs($hours - 1.0) < 0.01 ? 'hr' : 'hrs');
    }

    private function formatUnits(float $units): string
    {
        $formatted = rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');

        return sprintf('%s %s', $formatted, abs($units - 1.0) < 0.01 ? 'unit' : 'units');
    }

    private function clockToMinutes(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return FacultyAvailabilityDeclaration::clockToMinutes($value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $value),
            fn (int $v): bool => $v > 0,
        )));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $v): string => is_string($v) ? trim($v) : '', $value),
            fn (string $v): bool => $v !== '',
        )));
    }

    /**
     * @param  list<string>  $involved
     * @return list<string>
     */
    private function limitInvolved(array $involved): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(fn (string $s): string => trim($s), $involved),
            fn (string $s): bool => $s !== '',
        )));

        if (count($clean) <= 6) {
            return $clean;
        }

        $extra = count($clean) - 5;

        return [...array_slice($clean, 0, 5), sprintf('+%d more', $extra)];
    }

    private function resourcesUrl(?int $termId, string $resourceView = 'faculty'): ?string
    {
        if ($termId === null) {
            return null;
        }

        try {
            return TermResources::getUrl([
                'termId' => $termId,
                'resourceView' => $resourceView,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function classesUrl(?int $termId, string $classesView = 'classes'): ?string
    {
        if ($termId === null) {
            return null;
        }

        try {
            return TermClasses::getUrl([
                'termId' => $termId,
                'classesView' => $classesView,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function calendarUrl(?int $termId): ?string
    {
        if ($termId === null) {
            return null;
        }

        try {
            return TermCalendar::getUrl([
                'termId' => $termId,
            ]);
        } catch (Throwable) {
            return null;
        }
    }
}
