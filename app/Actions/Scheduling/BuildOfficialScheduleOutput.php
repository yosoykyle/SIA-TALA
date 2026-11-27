<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\DisplayDateTime;
use App\Support\FacultyScheduleFilters;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildOfficialScheduleOutput
{
    public function __construct(
        private readonly CurrentOfficialEnrollmentResolver $currentEnrollmentResolver,
    ) {}

    /**
     * @return array{title:string,owner:string,generated_at:string,version_label:string,version_state:string,is_faculty_output:bool,filter_description:string|null,back_url:string,back_label:string,rows:list<array<string, string>>,externally_arranged:list<array<string, string>>}
     */
    public function forFaculty(User $faculty, Request $request): array
    {
        abort_unless($faculty->hasRole(User::StaffRoleFaculty), 403);

        $request->validate([
            'scope' => ['sometimes', 'in:full,filtered'],
            'view' => ['sometimes', 'in:week,list'],
            'term_id' => ['sometimes', 'integer'],
            'return_filters' => ['sometimes', 'array'],
        ]);
        $filtered = $request->query('scope') === 'filtered';
        $criteria = $filtered ? FacultyScheduleFilters::normalize($request->query()) : [];
        $returnCriteria = $filtered ? $criteria : FacultyScheduleFilters::normalize($request->query('return_filters', []));

        $requestedVersionId = $request->integer('timetable_version');
        $requestedTermId = $request->integer('term_id');
        $availableVersions = PublishedTimetableVersion::query()
            ->whereHas('meetings', fn ($query) => $query->where('faculty_user_id', $faculty->id))
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('published_at')
            ->get();
        $version = $requestedVersionId > 0
            ? $availableVersions->firstWhere('id', $requestedVersionId)
            : ($availableVersions->count() === 1 ? $availableVersions->sole() : null);
        $resolvedTermId = $version instanceof PublishedTimetableVersion
            ? (int) $version->term_id
            : ($requestedTermId > 0 ? $requestedTermId : null);
        $resolvedTerm = $resolvedTermId !== null ? Term::query()->find($resolvedTermId) : null;
        $meetings = SectionMeeting::query()
            ->activeOfficial()
            ->with([
                'scheduleRun',
                'schedulingDemand.termOffering.term',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.courseComponent',
                'faculty',
                'room',
            ])
            ->where('faculty_user_id', $faculty->id)
            ->when(
                $version instanceof PublishedTimetableVersion,
                fn ($query) => $query->where('published_timetable_version_id', $version->id),
                fn ($query) => $requestedVersionId === 0 && $availableVersions->isEmpty()
                    ? $query->whereNull('published_timetable_version_id')
                        ->whereHas('scheduleRun', fn ($runQuery) => $runQuery->where('status', ScheduleGenerationRun::StatusPublished))
                    : $query->whereRaw('1 = 0'),
            )
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id');
        $ownedMeetings = (clone $meetings)->get();
        $meetings = FacultyScheduleFilters::apply($meetings, $criteria)->get();
        $criteriaDescription = FacultyScheduleFilters::describe($criteria, $ownedMeetings);
        $externallyArranged = $this->externallyArrangedForFaculty($faculty, $resolvedTermId, $filtered ? $criteria : []);
        $returnContext = ['termId' => $resolvedTermId, 'scheduleView' => $request->query('view', 'week')];
        foreach ($returnCriteria as $field => $value) {
            if ($field === 'search') {
                $returnContext['tableSearch'] = $value;
            } else {
                $returnContext['tableFilters'][$field]['value'] = $value;
            }
        }

        if ($meetings->isNotEmpty() || $externallyArranged !== []) {
            $versions = $meetings->pluck('scheduleRun.publication_version')->filter()->unique()->values();

            DB::table('output_access_logs')->insert([
                'output_type' => 'FACULTY_SCHEDULE',
                'source_record_type' => User::class,
                'source_record_id' => $faculty->id,
                'student_profile_id' => null,
                'actor_user_id' => $faculty->id,
                'actor_role' => $faculty->getRoleNames()->first(),
                'action' => 'PRINT',
                'copy_context' => 'FACULTY_COPY',
                'schedule_version' => $versions->count() === 1 ? $versions->first() : null,
                'filter_summary' => json_encode([
                    'timetable_version_id' => $version?->id,
                    'term_id' => $resolvedTermId,
                    'faculty_user_id' => $faculty->id,
                    'scope' => $filtered ? 'filtered' : 'full',
                    'criteria' => $criteria,
                ], JSON_THROW_ON_ERROR),
                'row_count' => $meetings->count() + count($externallyArranged),
                'purpose' => 'Faculty opened their current official assigned schedule for printing or saving as PDF.',
                'sensitivity' => 'faculty_record',
                'stored_file_reference' => null,
                'request_context' => json_encode($this->requestContext($request), JSON_THROW_ON_ERROR),
                'status' => 'logged',
                'occurred_at' => now(),
            ]);
        }

        $facultyUnits = $this->calculateDistinctOfferingUnits(
            $meetings->map(fn (SectionMeeting $meeting): ?object => $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification ? (object) [
                'key' => (int) ($meeting->schedulingDemand?->term_offering_id ?? 0).'|'.(int) ($meeting->schedulingDemand?->sectionDeliveryGroup?->section_id ?? 0),
                'units' => (float) $meeting->schedulingDemand->termOffering->curriculumEntry->courseSpecification->credit_units,
            ] : null)->filter(),
            collect($externallyArranged)->map(fn (array $item): object => (object) [
                'key' => 'ext|'.$item['course'].'|'.$item['section'],
                'units' => is_numeric(str_replace(' units', '', $item['units'])) ? (float) str_replace(' units', '', $item['units']) : 0.0,
            ]),
        );
        $facultyWeeklyHours = $this->calculateWeeklyContactHours(
            $meetings->map(fn (SectionMeeting $meeting): array => [
                'starts_at' => (string) $meeting->starts_at,
                'ends_at' => (string) $meeting->ends_at,
            ]),
        );

        return [
            'title' => $filtered ? 'Faculty Filtered Schedule' : 'Faculty Assigned Schedule',
            'is_faculty_output' => true,
            'back_url' => FacultySchedule::getUrl($returnContext),
            'back_label' => 'Back to My Schedule',
            'filter_description' => $filtered ? $criteriaDescription : null,
            'owner' => $faculty->name,
            'total_units' => $facultyUnits,
            'weekly_contact_hours' => $facultyWeeklyHours,
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'version_label' => match (true) {
                $version instanceof PublishedTimetableVersion => $version->term?->label.' · Timetable v'.$version->version,
                $externallyArranged !== [] && $resolvedTerm instanceof Term => $resolvedTerm->label.' · Externally arranged assignments',
                $availableVersions->count() > 1 => 'Select one exact Term and timetable version',
                $meetings->isNotEmpty() => 'Legacy published timetable baseline',
                default => 'No current published schedule is available for this account.',
            },
            'version_state' => match (true) {
                $version instanceof PublishedTimetableVersion => $version->state,
                $meetings->isNotEmpty() => 'Published',
                $externallyArranged !== [] => 'Active',
                default => 'Unavailable',
            },
            'rows' => $meetings
                ->map(fn (SectionMeeting $meeting): array => $this->meetingRow($meeting))
                ->values()
                ->all(),
            'externally_arranged' => $externallyArranged,
        ];
    }

    /**
     * @return array{title:string,owner:string,generated_at:string,version_label:string,version_state:string,back_url:string,rows:list<array<string, string>>,externally_arranged:list<array<string, string>>}
     */
    public function forStudent(User $student, Request $request): array
    {
        abort_unless($student->hasRole('student'), 403);

        $requestedTermId = $request->integer('term_id');
        $enrollment = $requestedTermId > 0
            ? $this->currentEnrollmentResolver->forStudentInTerm($student, $requestedTermId)
            : $this->currentEnrollmentResolver->forStudent($student);

        if ($requestedTermId > 0 && ! $enrollment instanceof Enrollment) {
            abort(404);
        }

        $version = $enrollment instanceof Enrollment
            ? PublishedTimetableVersion::query()
                ->where('term_id', $enrollment->term_id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->latest('version')
                ->first()
            : null;

        $activeRegistrations = CourseEnrollment::query()
            ->with([
                'termOffering.term',
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
                'publishedTimetableVersion',
            ])
            ->when($enrollment instanceof Enrollment,
                fn ($query) => $query->where('enrollment_id', $enrollment->id),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->get();

        $registrations = $activeRegistrations
            ->filter(fn (CourseEnrollment $registration): bool => $registration->section_id !== null)
            ->values();
        $meetings = PublishedTimetableMeeting::query()
            ->with(['faculty', 'room', 'classOffering'])
            ->when($version instanceof PublishedTimetableVersion && $registrations->isNotEmpty(),
                fn ($query) => $query
                    ->where('published_timetable_version_id', $version->id)
                    ->whereIn('section_id', $registrations->pluck('section_id')->unique()),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('meeting_sequence')
            ->get();

        $externallyArranged = $enrollment instanceof Enrollment
            ? $this->externallyArrangedForStudentEnrollment($enrollment, $activeRegistrations)
            : [];

        $student->loadMissing('studentProfile.program');
        $studentProfile = $enrollment?->studentProfile ?? $student->studentProfile;
        $studentTotalUnits = $this->calculateDistinctOfferingUnits(
            $activeRegistrations->map(fn (CourseEnrollment $registration): object => (object) [
                'key' => 'ce|'.$registration->id,
                'units' => (float) ($registration->units_snapshot
                    ?? $registration->termOffering?->curriculumEntry?->courseSpecification?->credit_units
                    ?? 0),
            ]),
        );
        $studentWeeklyHours = $this->calculateWeeklyContactHours(
            $meetings->map(fn (PublishedTimetableMeeting $meeting): array => [
                'starts_at' => (string) $meeting->starts_at,
                'ends_at' => (string) $meeting->ends_at,
            ]),
        );

        app(RecordStudentScheduleAccess::class)->execute(
            $student,
            $request,
            RecordStudentScheduleAccess::ActionPrint,
            $enrollment,
        );

        return [
            'title' => 'Student Class Schedule',
            'owner' => $student->name,
            'student_number' => $studentProfile?->student_number,
            'program_name' => $studentProfile?->program?->name ?? $studentProfile?->program?->code,
            'total_units' => $studentTotalUnits,
            'weekly_contact_hours' => $studentWeeklyHours,
            'generated_at' => DisplayDateTime::format(now(), 'F j, Y g:i A'),
            'back_url' => ScheduleView::getUrl(
                $requestedTermId > 0 && $enrollment instanceof Enrollment ? ['termId' => $enrollment->term_id] : [],
                panel: 'student',
            ),
            'version_label' => match (true) {
                $version instanceof PublishedTimetableVersion => $version->term?->label.' · Timetable v'.$version->version,
                $externallyArranged !== [] && $enrollment?->term instanceof Term => $enrollment->term->label.' · Externally arranged classes',
                default => (string) $enrollment?->term?->label ?: 'No current published schedule is available for this account.',
            },
            'version_state' => match (true) {
                $version instanceof PublishedTimetableVersion => $version->state,
                $meetings->isNotEmpty() => 'Published',
                $externallyArranged !== [] => 'Active',
                default => 'Unavailable',
            },
            'rows' => $this->mergedStudentRows($meetings, $registrations, $enrollment),
            'breaks' => $this->scheduledBreaksForTerm($enrollment instanceof Enrollment ? (int) $enrollment->term_id : null),
            'externally_arranged' => $externallyArranged,
        ];
    }

    /**
     * Merge multi-slot student meetings for the same course, section, and instructor into 1 row per subject.
     *
     * @param  Collection<int, PublishedTimetableMeeting>  $meetings
     * @param  Collection<int, CourseEnrollment>  $registrations
     * @return list<array<string, string>>
     */
    private function mergedStudentRows(Collection $meetings, Collection $registrations, ?Enrollment $enrollment): array
    {
        $entries = $meetings
            ->map(function (PublishedTimetableMeeting $meeting) use ($registrations, $enrollment): ?array {
                $registration = $registrations->first(fn (CourseEnrollment $candidate): bool => (int) $candidate->section_id === (int) $meeting->section_id);

                if (! $registration instanceof CourseEnrollment) {
                    return null;
                }

                $courseCode = (string) $registration->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                $description = (string) $registration->termOffering?->curriculumEntry?->courseSpecification?->title;
                $sectionCode = (string) $registration->section?->code;
                $facultyName = (string) $meeting->faculty?->name;

                return [
                    'group_key' => implode('|', [
                        $courseCode,
                        $sectionCode,
                        (string) ($meeting->faculty_user_id ?? $facultyName),
                    ]),
                    'term' => (string) ($enrollment !== null ? $enrollment->term?->label : $registration->termOffering?->term?->label),
                    'course' => $courseCode,
                    'description' => $description,
                    'section' => $sectionCode,
                    'day' => SectionMeeting::dayOptions()[$meeting->day_of_week] ?? 'Unscheduled',
                    'time' => $this->publishedTimeRange($meeting),
                    'slot' => $this->formatStudentMeetingSlot($meeting),
                    'room' => ($meeting->room !== null ? $meeting->room->code : null) ?? $meeting->location_label ?? 'TBA',
                    'modality' => SectionMeeting::modalityOptions()[$meeting->modality] ?? str($meeting->modality)->headline()->toString(),
                    'faculty' => $facultyName,
                ];
            })
            ->filter()
            ->values();

        return $entries
            ->groupBy('group_key')
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'term' => (string) $first['term'],
                    'course' => (string) $first['course'],
                    'description' => (string) $first['description'],
                    'section' => (string) $first['section'],
                    'component' => 'Published schedule',
                    'day' => $group->pluck('day')->filter()->unique()->values()->implode(', '),
                    'time' => $group->pluck('time')->filter()->unique()->values()->implode(', '),
                    'schedule' => $group->pluck('slot')->filter()->unique()->values()->implode(', '),
                    'room' => $group->pluck('room')->filter()->unique()->values()->implode(', '),
                    'modality' => $group->pluck('modality')->filter()->unique()->values()->implode(', '),
                    'faculty' => $group->pluck('faculty')->filter()->unique()->values()->implode(', '),
                ];
            })
            ->values()
            ->all();
    }

    private function formatStudentMeetingSlot(PublishedTimetableMeeting $meeting): string
    {
        $dayName = SectionMeeting::dayOptions()[$meeting->day_of_week] ?? 'Unscheduled';
        $shortDay = $dayName === 'Unscheduled' ? $dayName : substr($dayName, 0, 3);

        try {
            $start = CarbonImmutable::createFromFormat('H:i:s', (string) $meeting->starts_at);
            $end = CarbonImmutable::createFromFormat('H:i:s', (string) $meeting->ends_at);
            $timeRange = $start->format('A') === $end->format('A')
                ? $start->format('g:i').'–'.$end->format('g:i A')
                : $start->format('g:i A').'–'.$end->format('g:i A');
        } catch (\Throwable) {
            $timeRange = $this->publishedTimeRange($meeting);
        }

        return $shortDay.' '.$timeRange;
    }

    /**
     * Summarize scheduled daily breaks from the active TermCalendarPackage teaching grid.
     *
     * @return list<array{days:string,times:string,summary:string}>
     */
    private function scheduledBreaksForTerm(?int $termId): array
    {
        if ($termId === null) {
            return [];
        }

        $package = TermCalendarPackage::query()
            ->where('term_id', $termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->latest('version')
            ->first();

        if (! $package instanceof TermCalendarPackage) {
            return [];
        }

        $dayIndexMap = array_flip(SectionMeeting::dayOptions());
        $dayBreaks = collect(app(TeachingWeekSummary::class)->rows($package))
            ->map(function (array $row) use ($dayIndexMap): ?array {
                $times = collect($row['segments'] ?? [])
                    ->where('type', 'break')
                    ->pluck('time')
                    ->filter()
                    ->values()
                    ->all();

                if ($times === []) {
                    return null;
                }

                $dayName = (string) ($row['day'] ?? '');

                return [
                    'day_number' => (int) ($dayIndexMap[$dayName] ?? 0),
                    'short_day' => $dayName !== '' ? substr($dayName, 0, 3) : 'Day',
                    'times' => implode(', ', $times),
                ];
            })
            ->filter()
            ->values();

        if ($dayBreaks->isEmpty()) {
            return [];
        }

        return $dayBreaks
            ->groupBy('times')
            ->map(function (Collection $group, string $times): array {
                $sorted = $group->sortBy('day_number')->values();
                $dayNumbers = $sorted->pluck('day_number')->all();
                $shortDays = $sorted->pluck('short_day')->all();
                $count = count($shortDays);
                $isContiguous = $count > 2
                    && $dayNumbers[0] > 0
                    && $dayNumbers === range($dayNumbers[0], $dayNumbers[$count - 1]);
                $daysLabel = $isContiguous
                    ? $shortDays[0].'–'.$shortDays[$count - 1]
                    : implode(', ', $shortDays);

                return [
                    'days' => $daysLabel,
                    'times' => $times,
                    'summary' => $daysLabel.': '.$times,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CourseEnrollment>  $activeRegistrations
     * @return list<array<string, string>>
     */
    private function externallyArrangedForStudentEnrollment(Enrollment $enrollment, Collection $activeRegistrations): array
    {
        $externalRegistrations = $activeRegistrations
            ->filter(fn (CourseEnrollment $registration): bool => $registration->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged)
            ->values();

        if ($externalRegistrations->isEmpty()) {
            return [];
        }

        $offeringIds = $externalRegistrations->pluck('term_offering_id')->filter()->unique()->values()->all();
        $assignments = $offeringIds === []
            ? collect()
            : ClassOfferingTeachingAssignment::query()
                ->with('faculty')
                ->whereIn('term_offering_id', $offeringIds)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ClassOfferingTeachingAssignment::RoleDesignated])
                ->orderBy('id')
                ->get();

        return $externalRegistrations
            ->map(function (CourseEnrollment $registration) use ($enrollment, $assignments): array {
                $specification = $registration->termOffering?->curriculumEntry?->courseSpecification;
                $course = $specification?->course;
                $assignment = $assignments->first(function (ClassOfferingTeachingAssignment $candidate) use ($registration): bool {
                    if ((int) $candidate->term_offering_id !== (int) $registration->term_offering_id) {
                        return false;
                    }

                    return $registration->section_id === null
                        || $candidate->section_id === null
                        || (int) $candidate->section_id === (int) $registration->section_id;
                });

                return [
                    'term' => (string) ($enrollment->term?->label ?? $registration->termOffering?->term?->label ?? ''),
                    'course' => (string) ($course?->code ?? ''),
                    'description' => (string) ($specification?->title ?? ''),
                    'section' => (string) ($registration->section?->code ?: 'Not sectioned'),
                    'units' => $specification?->credit_units !== null ? number_format((float) $specification->credit_units, 2, '.', '').' units' : '—',
                    'faculty' => (string) ($assignment?->faculty?->name ?: 'Not assigned'),
                    'note' => 'Externally arranged · No fixed weekly room or time slot',
                ];
            })
            ->sortBy('course')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|string>  $criteria
     * @return list<array<string, string>>
     */
    private function externallyArrangedForFaculty(User $faculty, ?int $termId, array $criteria = []): array
    {
        if ($termId === null) {
            return [];
        }

        if (isset($criteria['day_of_week']) || isset($criteria['room_id']) || isset($criteria['modality'])) {
            return [];
        }

        $assignments = ClassOfferingTeachingAssignment::query()
            ->with([
                'termOffering.term',
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
            ])
            ->where('faculty_user_id', $faculty->id)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->whereHas('termOffering', fn ($query) => $query
                ->where('term_id', $termId)
                ->where('state', '!=', TermOffering::StateCancelled)
                ->whereHas('curriculumEntry.courseSpecification', fn ($specQuery) => $specQuery
                    ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged)))
            ->where(function ($query): void {
                $query->whereNull('section_id')
                    ->orWhereHas('section', fn ($sectionQuery) => $sectionQuery->where('state', '!=', Section::StateCancelled));
            })
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $search = isset($criteria['search']) ? mb_strtolower(trim((string) $criteria['search'])) : '';
        $courseFilter = isset($criteria['course_id']) ? (int) $criteria['course_id'] : null;
        $classFilter = isset($criteria['class_id']) ? (int) $criteria['class_id'] : null;

        return $assignments
            ->filter(function (ClassOfferingTeachingAssignment $assignment) use ($search, $courseFilter, $classFilter): bool {
                $spec = $assignment->termOffering?->curriculumEntry?->courseSpecification;
                $course = $spec?->course;

                if ($courseFilter !== null && (int) $course?->id !== $courseFilter) {
                    return false;
                }

                if ($classFilter !== null && (int) $assignment->section_id !== $classFilter) {
                    return false;
                }

                if ($search !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $course?->code,
                        $spec?->title,
                        $assignment->section?->code,
                    ])));

                    return str_contains($haystack, $search);
                }

                return true;
            })
            ->map(function (ClassOfferingTeachingAssignment $assignment): array {
                $spec = $assignment->termOffering?->curriculumEntry?->courseSpecification;
                $course = $spec?->course;

                return [
                    'term' => (string) ($assignment->termOffering?->term?->label ?? ''),
                    'course' => (string) ($course?->code ?? ''),
                    'description' => (string) ($spec?->title ?? ''),
                    'section' => (string) ($assignment->section?->code ?: 'Term offering'),
                    'role' => $assignment->role === ClassOfferingTeachingAssignment::RoleDesignated ? 'Designated instructor' : 'Co-faculty',
                    'units' => $spec?->credit_units !== null ? number_format((float) $spec->credit_units, 2, '.', '').' units' : '—',
                    'note' => 'Externally arranged · No fixed weekly room or time slot',
                ];
            })
            ->unique(fn (array $row): string => $row['course'].'|'.$row['section'].'|'.$row['role'])
            ->sortBy(['course', 'section'])
            ->values()
            ->all();
    }

    private function publishedTimeRange(PublishedTimetableMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    /**
     * @return array<string, string>
     */
    private function meetingRow(SectionMeeting $meeting): array
    {
        $room = $meeting->getRelation('room');
        $faculty = $meeting->getRelation('faculty');

        return [
            'term' => (string) $meeting->schedulingDemand?->termOffering?->term?->label,
            'course' => (string) $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
            'description' => (string) $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->title,
            'section' => (string) $meeting->schedulingDemand?->sectionDeliveryGroup?->section?->code,
            'component' => CourseComponent::typeOptions()[$meeting->schedulingDemand?->courseComponent?->component_type] ?? str((string) $meeting->schedulingDemand?->courseComponent?->component_type)->lower()->headline()->toString(),
            'day' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
            'time' => $this->timeRange($meeting),
            'room' => $room instanceof Room ? $room->code : 'TBA',
            'modality' => SectionMeeting::modalityOptions()[$meeting->modality] ?? str($meeting->modality)->headline()->toString(),
            'faculty' => $faculty instanceof User ? $faculty->name : '',
        ];
    }

    private function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    /**
     * @return array{ip:string|null,user_agent:string|null,route:string|null}
     */
    private function requestContext(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'route' => $request->route()?->getName(),
        ];
    }

    /**
     * @param  Collection<int, object>  ...$collections
     */
    private function calculateDistinctOfferingUnits(Collection ...$collections): string
    {
        $total = collect($collections)
            ->flatten(1)
            ->unique('key')
            ->sum(fn (object $item): float => (float) $item->units);

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * @param  Collection<int, array{starts_at:string,ends_at:string}>  $intervals
     */
    private function calculateWeeklyContactHours(Collection $intervals): string
    {
        $minutes = $intervals->sum(function (array $interval): int {
            try {
                $start = CarbonImmutable::createFromFormat('H:i:s', $interval['starts_at']);
                $end = CarbonImmutable::createFromFormat('H:i:s', $interval['ends_at']);

                return max(0, (int) $start->diffInMinutes($end));
            } catch (\Throwable) {
                return 0;
            }
        });

        $hours = round($minutes / 60, 2);

        return fmod($hours, 1.0) === 0.0
            ? number_format($hours, 0, '.', '')
            : rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
