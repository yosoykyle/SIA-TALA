<?php

namespace App\Actions\StudentHub;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FaqEntry;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Support\DecimalMoney;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class StudentDashboardService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly HoldEvaluationService $holds,
    ) {}

    /**
     * @return array{
     *     profile:array<string,mixed>,
     *     enrollment:array{current:array<string,mixed>|null,history:list<array<string,mixed>>},
     *     schedule:array{current:list<array<string,mixed>>},
     *     financials:array<string,mixed>,
     *     grades:array{terms:list<array<string,mixed>>},
     *     requests:array{grade_corrections:list<array<string,mixed>>},
     *     holds:list<array<string,mixed>>,
     *     notifications:list<array<string,mixed>>,
     *     help:array<string,mixed>,
     *     summary:array<string,mixed>
     * }
     */
    public function forStudent(StudentProfile $studentProfile): array
    {
        $studentProfile->load(['user', 'program']);

        $enrollments = $this->enrollmentsFor($studentProfile);
        $currentEnrollment = $enrollments->first();
        $holds = $this->holds($studentProfile, $currentEnrollment);

        return [
            'profile' => $this->profile($studentProfile),
            'enrollment' => [
                'current' => $currentEnrollment instanceof Enrollment ? $this->enrollmentItem($currentEnrollment) : null,
                'history' => $enrollments->map(fn (Enrollment $enrollment): array => $this->enrollmentItem($enrollment))->values()->all(),
            ],
            'schedule' => [
                'current' => $currentEnrollment instanceof Enrollment ? $this->scheduleFor($currentEnrollment) : [],
            ],
            'financials' => $this->financials($studentProfile),
            'grades' => [
                'terms' => $this->gradesByTerm($studentProfile),
            ],
            'requests' => $this->requests($studentProfile),
            'holds' => $holds,
            'notifications' => $this->notifications($studentProfile),
            'help' => $this->help(),
            'summary' => [
                'status' => $currentEnrollment instanceof Enrollment ? 'dashboard_ready' : 'no_current_enrollment',
                'has_current_enrollment' => $currentEnrollment instanceof Enrollment,
                'has_holds' => $holds !== [],
                'hold_count' => count($holds),
            ],
        ];
    }

    /**
     * @return EloquentCollection<int, Enrollment>
     */
    private function enrollmentsFor(StudentProfile $studentProfile): EloquentCollection
    {
        return Enrollment::query()
            ->with(['term', 'section.program', 'sectionDeliveryGroup'])
            ->where('student_profile_id', $studentProfile->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(StudentProfile $studentProfile): array
    {
        return [
            'student_profile_id' => (int) $studentProfile->id,
            'user_id' => (int) $studentProfile->user_id,
            'name' => $studentProfile->user?->name,
            'student_id' => $this->stringAttribute($studentProfile, 'student_number'),
            'program_id' => (int) $studentProfile->program_id,
            'program_code' => $studentProfile->program?->code,
            'program_name' => $studentProfile->program?->name,
            'year_level' => null,
            'modality' => null,
            'operational_status' => $this->stringAttribute($studentProfile, 'lifecycle_status'),
            'user_status' => $studentProfile->user?->status,
            'hard_copy_received' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enrollmentItem(Enrollment $enrollment): array
    {
        return [
            'enrollment_id' => (int) $enrollment->id,
            'term_id' => (int) $enrollment->term_id,
            'term_name' => $enrollment->term?->label,
            'section_id' => null,
            'section_name' => null,
            'section_delivery_group_id' => null,
            'section_delivery_group_name' => null,
            'status' => $enrollment->status,
            'student_type' => $enrollment->student_type,
            'year_level' => null,
            'modality' => null,
            'lis_status' => null,
            'is_late_enrollment' => false,
            'enrolled_at' => $this->dateTimeString($enrollment->officially_enrolled_at),
            'pre_enrolled_at' => $this->dateTimeString($enrollment->registered_at),
            'officially_enrolled_at' => $this->dateTimeString($enrollment->officially_enrolled_at),
            'completed_at' => null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scheduleFor(Enrollment $enrollment): array
    {
        return StudentScheduleBinding::query()
            ->with([
                'courseEnrollment.termOffering.curriculumEntry.courseSpecification.course',
                'sectionMeeting.scheduleRun',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
                'sectionMeeting.faculty',
                'sectionMeeting.room',
            ])
            ->where('is_active', true)
            ->whereHas('courseEnrollment', function ($query) use ($enrollment): void {
                $query
                    ->where('enrollment_id', $enrollment->id)
                    ->where('status', CourseEnrollment::StatusActive);
            })
            ->whereHas('sectionMeeting', function ($query): void {
                $query
                    ->where('state', SectionMeeting::StateActive)
                    ->whereHas('scheduleRun', function ($query): void {
                        $query->where('status', ScheduleGenerationRun::StatusPublished);
                    });
            })
            ->get()
            ->filter(fn (StudentScheduleBinding $binding): bool => $binding->sectionMeeting instanceof SectionMeeting)
            ->sortBy(fn (StudentScheduleBinding $binding): string => sprintf(
                '%02d-%s-%010d',
                (int) $binding->sectionMeeting->day_of_week,
                (string) $binding->sectionMeeting->starts_at,
                (int) $binding->sectionMeeting->id,
            ))
            ->map(function (StudentScheduleBinding $binding): array {
                $meeting = $binding->sectionMeeting;
                $courseEnrollment = $binding->courseEnrollment;
                $termOffering = $courseEnrollment?->termOffering;
                $courseSpecification = $termOffering?->curriculumEntry?->courseSpecification;
                $course = $courseSpecification?->course;
                $deliveryGroup = $meeting->schedulingDemand?->sectionDeliveryGroup;
                $section = $deliveryGroup?->section;

                return [
                    'section_meeting_id' => (int) $meeting->id,
                    'term_id' => $termOffering?->term_id !== null ? (int) $termOffering->term_id : null,
                    'section_id' => $section?->id !== null ? (int) $section->id : null,
                    'section_delivery_group_id' => $deliveryGroup?->id !== null ? (int) $deliveryGroup->id : null,
                    'section_delivery_group_name' => $deliveryGroup?->name,
                    'subject_id' => $course?->id !== null ? (int) $course->id : null,
                    'subject_code' => $course?->code,
                    'subject_description' => $courseSpecification?->title ?: $courseSpecification?->description,
                    'faculty_id' => (int) $meeting->faculty_user_id,
                    'faculty_name' => $meeting->faculty?->name,
                    'day_of_week' => (int) $meeting->day_of_week,
                    'day_label' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
                    'starts_at' => $this->timeValue($meeting->starts_at),
                    'ends_at' => $this->timeValue($meeting->ends_at),
                    'time_label' => $this->timeRange($meeting->starts_at, $meeting->ends_at),
                    'room' => $meeting->room?->code,
                    'modality' => $meeting->modality,
                    'modality_label' => SectionMeeting::modalityOptions()[$meeting->modality] ?? $meeting->modality,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function financials(StudentProfile $studentProfile): array
    {
        $currentBalance = $this->sumAmounts(
            LedgerEntry::query()
                ->where('student_profile_id', $studentProfile->id)
                ->pluck('amount')
        );
        $ledgerEntries = LedgerEntry::query()
            ->with('term')
            ->where('student_profile_id', $studentProfile->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();
        $payments = Payment::query()
            ->with('term')
            ->where('student_profile_id', $studentProfile->id)
            ->where('evidence_status', 'verified')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
        $latestPayments = [];

        foreach ($payments->take(5) as $payment) {
            $term = $payment->term;

            $latestPayments[] = [
                'payment_id' => (int) $payment->id,
                'term_id' => (int) $payment->term_id,
                'term_name' => $term instanceof Term ? $this->stringAttribute($term, 'label') : null,
                'payment_reference' => $this->stringAttribute($payment, 'provider_reference') ?? $this->stringAttribute($payment, 'or_number'),
                'channel' => $payment->channel,
                'amount' => $this->money->normalize((string) $payment->amount),
                'status' => $this->stringAttribute($payment, 'evidence_status'),
                'confirmed_at' => $this->dateTimeString($payment->verified_at ?? $payment->paid_at),
            ];
        }

        return [
            'current_balance' => $currentBalance,
            'has_balance' => $this->money->greaterThanZero($currentBalance),
            'term_summaries' => $this->financialTermSummaries($ledgerEntries, $payments),
            'latest_payments' => $latestPayments,
        ];
    }

    /**
     * @param  EloquentCollection<int, LedgerEntry>  $ledgerEntries
     * @param  EloquentCollection<int, Payment>  $payments
     * @return list<array<string,mixed>>
     */
    private function financialTermSummaries(EloquentCollection $ledgerEntries, EloquentCollection $payments): array
    {
        $termIds = $ledgerEntries
            ->pluck('term_id')
            ->merge($payments->pluck('term_id'))
            ->filter()
            ->map(fn (int|string $termId): int => (int) $termId)
            ->unique()
            ->values()
            ->all();

        if ($termIds === []) {
            return [];
        }

        $termLabels = Term::query()
            ->whereKey($termIds)
            ->pluck('label', 'id');
        $summaries = [];

        foreach ($termIds as $termId) {
            $termLedgerEntries = $ledgerEntries->where('term_id', $termId)->values();
            $termPayments = $payments->where('term_id', $termId)->values();
            $latestLedgerEntry = $termLedgerEntries->first();

            $summaries[] = [
                'term_id' => $termId,
                'term_name' => $this->collectionString($termLabels, $termId),
                'total_assessment' => $this->sumAmounts($termLedgerEntries->where('direction', LedgerEntry::DirectionCharge)->pluck('amount')),
                'total_paid' => $termPayments->isNotEmpty()
                    ? $this->sumAmounts($termPayments->pluck('amount'))
                    : $this->sumAbsoluteAmounts($termLedgerEntries->where('direction', LedgerEntry::DirectionPayment)->pluck('amount')),
                'remaining_balance' => $this->sumAmounts($termLedgerEntries->pluck('amount')),
                'latest_entry_at' => $latestLedgerEntry instanceof LedgerEntry
                    ? $this->dateTimeString($latestLedgerEntry->posted_at)
                    : null,
            ];
        }

        return $summaries;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function gradesByTerm(StudentProfile $studentProfile): array
    {
        $grades = GradeRosterRow::query()
            ->with([
                'roster.termOffering.term',
                'roster.termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->whereNotNull('released_at')
            ->whereHas('courseEnrollment.enrollment', fn ($query) => $query->where('student_profile_id', $studentProfile->id))
            ->orderByDesc('released_at')
            ->orderBy('id')
            ->get();

        return $grades
            ->groupBy(fn (GradeRosterRow $row): int => (int) $row->roster->termOffering->term_id)
            ->map(function (Collection $termGrades, int|string $termId): array {
                $term = $termGrades->first()?->roster?->termOffering?->term;

                return [
                    'term_id' => (int) $termId,
                    'term_name' => $term?->label,
                    'grades' => $termGrades
                        ->map(fn (GradeRosterRow $grade): array => [
                            'grade_roster_row_id' => (int) $grade->id,
                            'course_enrollment_id' => (int) $grade->course_enrollment_id,
                            'subject_code' => $grade->roster->termOffering->curriculumEntry?->courseSpecification?->course?->code,
                            'subject_description' => $grade->roster->termOffering->curriculumEntry?->courseSpecification?->title,
                            'grade' => $grade->current_outcome_code,
                            'remarks' => $grade->current_outcome_category,
                            'is_inc' => $grade->current_outcome_code === 'INC',
                            'is_finalized' => true,
                            'finalized_at' => $grade->released_at?->toDateTimeString(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{grade_corrections:list<array<string,mixed>>}
     */
    private function requests(StudentProfile $studentProfile): array
    {
        return [
            'grade_corrections' => $this->gradeCorrections($studentProfile),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function gradeCorrections(StudentProfile $studentProfile): array
    {
        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function holds(StudentProfile $studentProfile, ?Enrollment $currentEnrollment): array
    {
        return $this->holds
            ->activeBlockingHolds($studentProfile, [
                Hold::BlockingEnrollment,
                Hold::BlockingCorPrint,
                Hold::BlockingClearance,
                Hold::BlockingRecordRelease,
                Hold::BlockingGraduationEligibility,
                Hold::BlockingReactivation,
                Hold::BlockingAdvisoryOnly,
            ], $currentEnrollment)
            ->map(fn (Hold $hold): array => [
                'hold_id' => (int) $hold->id,
                'code' => $hold->hold_type,
                'blocking_level' => $hold->blocking_level,
                'status' => $hold->status,
                'severity' => $hold->blocking_level === Hold::BlockingAdvisoryOnly ? 'info' : 'warning',
                'message' => $hold->student_message ?? $hold->reason,
                'expires_at' => $hold->expires_at?->toDateTimeString(),
                'resolution_requirement' => $hold->resolution_requirement,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function notifications(StudentProfile $studentProfile): array
    {
        if ($studentProfile->user === null) {
            return [];
        }

        $notifications = [];

        foreach ($studentProfile->user
            ->notifications()
            ->latest()
            ->limit(5)
            ->get() as $notification) {
            $type = $this->stringAttribute($notification, 'type') ?? 'notification';
            $data = $notification->getAttribute('data');

            $notifications[] = [
                'id' => (string) $notification->id,
                'type' => $type,
                'title' => data_get($data, 'title', $type),
                'body' => data_get($data, 'body'),
                'read_at' => $this->dateTimeString($notification->getAttribute('read_at')),
                'created_at' => $this->dateTimeString($notification->getAttribute('created_at')),
            ];
        }

        return $notifications;
    }

    /**
     * @return array<string,mixed>
     */
    private function help(): array
    {
        $faqEntries = [];

        foreach (FaqEntry::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(5)
            ->get() as $entry) {
            $category = $this->stringAttribute($entry, 'category');

            $faqEntries[] = [
                'faq_entry_id' => (int) $entry->id,
                'question' => $this->stringAttribute($entry, 'question'),
                'answer' => $this->stringAttribute($entry, 'answer'),
                'category' => $category,
                'category_label' => FaqEntry::categoryLabel($category),
            ];
        }

        return [
            'help_path' => null,
            'public_faq_path' => null,
            'faq_entries' => $faqEntries,
        ];
    }

    private function stringAttribute(Model $model, string $key): ?string
    {
        $value = $model->getAttribute($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function collectionString(Collection $collection, int $key): ?string
    {
        $value = $collection->get($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param  Collection<int, mixed>  $amounts
     */
    private function sumAmounts(Collection $amounts): string
    {
        return $amounts->reduce(
            fn (string $carry, mixed $amount): string => $this->money->add($carry, (string) $amount),
            '0.00',
        );
    }

    /**
     * @param  Collection<int, mixed>  $amounts
     */
    private function sumAbsoluteAmounts(Collection $amounts): string
    {
        return $amounts->reduce(function (string $carry, mixed $amount): string {
            $normalized = $this->money->normalize((string) $amount);
            $absolute = str_starts_with($normalized, '-') ? substr($normalized, 1) : $normalized;

            return $this->money->add($carry, $absolute);
        }, '0.00');
    }

    private function timeRange(mixed $startsAt, mixed $endsAt): ?string
    {
        $start = $this->timeValue($startsAt);
        $end = $this->timeValue($endsAt);

        if ($start === null && $end === null) {
            return null;
        }

        return trim(implode('-', array_filter([$start, $end])));
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }
}
