<?php

namespace App\Actions\StudentHub;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\Enrollment;
use App\Models\FaqEntry;
use App\Models\Grade;
use App\Models\GradeCorrection;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
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
            'user_id' => $studentProfile->user_id !== null ? (int) $studentProfile->user_id : null,
            'name' => $studentProfile->user?->name,
            'student_id' => $studentProfile->student_id,
            'program_id' => $studentProfile->program_id !== null ? (int) $studentProfile->program_id : null,
            'program_code' => $studentProfile->program?->code,
            'program_name' => $studentProfile->program?->name,
            'year_level' => $studentProfile->year_level,
            'modality' => $studentProfile->modality,
            'operational_status' => $studentProfile->operational_status,
            'user_status' => $studentProfile->user?->status,
            'hard_copy_received' => (bool) $studentProfile->hard_copy_received,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enrollmentItem(Enrollment $enrollment): array
    {
        return [
            'enrollment_id' => (int) $enrollment->id,
            'term_id' => $enrollment->term_id !== null ? (int) $enrollment->term_id : null,
            'term_name' => $enrollment->term?->term_name,
            'section_id' => $enrollment->section_id !== null ? (int) $enrollment->section_id : null,
            'section_name' => $enrollment->section?->name,
            'section_delivery_group_id' => $enrollment->section_delivery_group_id !== null ? (int) $enrollment->section_delivery_group_id : null,
            'section_delivery_group_name' => $enrollment->sectionDeliveryGroup?->name,
            'status' => $enrollment->status,
            'student_type' => $enrollment->student_type,
            'year_level' => $enrollment->year_level,
            'modality' => $enrollment->modality,
            'lis_status' => $enrollment->lis_status,
            'is_late_enrollment' => (bool) $enrollment->is_late_enrollment,
            'enrolled_at' => $enrollment->enrolled_at?->toDateTimeString(),
            'pre_enrolled_at' => $enrollment->pre_enrolled_at?->toDateTimeString(),
            'officially_enrolled_at' => $enrollment->officially_enrolled_at?->toDateTimeString(),
            'completed_at' => $enrollment->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scheduleFor(Enrollment $enrollment): array
    {
        if ($enrollment->section_id === null || $enrollment->term_id === null) {
            return [];
        }

        return SectionMeeting::query()
            ->with(['subject', 'faculty', 'sectionDeliveryGroup'])
            ->activeOfficial()
            ->where('term_id', $enrollment->term_id)
            ->where('section_id', $enrollment->section_id)
            ->when($enrollment->section_delivery_group_id !== null, function ($query) use ($enrollment): void {
                $query->where(function ($query) use ($enrollment): void {
                    $query->whereNull('section_delivery_group_id')
                        ->orWhere('section_delivery_group_id', $enrollment->section_delivery_group_id);
                });
            })
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SectionMeeting $meeting): array => [
                'section_meeting_id' => (int) $meeting->id,
                'term_id' => (int) $meeting->term_id,
                'section_id' => (int) $meeting->section_id,
                'section_delivery_group_id' => $meeting->section_delivery_group_id !== null ? (int) $meeting->section_delivery_group_id : null,
                'section_delivery_group_name' => $meeting->sectionDeliveryGroup?->name,
                'subject_id' => (int) $meeting->subject_id,
                'subject_code' => $meeting->subject?->code,
                'subject_description' => $meeting->subject?->description,
                'faculty_id' => $meeting->faculty_id !== null ? (int) $meeting->faculty_id : null,
                'faculty_name' => $meeting->faculty?->name,
                'day_of_week' => (int) $meeting->day_of_week,
                'day_label' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
                'starts_at' => $this->timeValue($meeting->starts_at),
                'ends_at' => $this->timeValue($meeting->ends_at),
                'time_label' => $this->timeRange($meeting->starts_at, $meeting->ends_at),
                'room' => $meeting->room,
                'modality' => $meeting->modality,
                'modality_label' => SectionMeeting::modalityOptions()[$meeting->modality] ?? $meeting->modality,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function financials(StudentProfile $studentProfile): array
    {
        $currentBalance = $this->money->normalize((string) $studentProfile->current_balance);
        $ledgerEntries = LedgerEntry::query()
            ->with('term')
            ->where('student_profile_id', $studentProfile->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();
        $payments = Payment::query()
            ->with('term')
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get();

        return [
            'current_balance' => $currentBalance,
            'has_balance' => $this->money->greaterThanZero($currentBalance),
            'term_summaries' => $this->financialTermSummaries($ledgerEntries, $payments),
            'latest_payments' => $payments
                ->take(5)
                ->map(fn (Payment $payment): array => [
                    'payment_id' => (int) $payment->id,
                    'term_id' => $payment->term_id !== null ? (int) $payment->term_id : null,
                    'term_name' => $payment->term?->term_name,
                    'payment_reference' => $payment->payment_reference,
                    'channel' => $payment->channel,
                    'amount' => $this->money->normalize((string) $payment->amount),
                    'status' => $payment->status,
                    'confirmed_at' => $payment->confirmed_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  EloquentCollection<int, LedgerEntry>  $ledgerEntries
     * @param  EloquentCollection<int, Payment>  $payments
     * @return list<array<string,mixed>>
     */
    private function financialTermSummaries(EloquentCollection $ledgerEntries, EloquentCollection $payments): array
    {
        $termIds = $ledgerEntries->pluck('term_id')
            ->merge($payments->pluck('term_id'))
            ->filter()
            ->unique()
            ->values();

        if ($termIds->isEmpty()) {
            return [];
        }

        $terms = Term::query()
            ->whereKey($termIds->all())
            ->get()
            ->keyBy('id');

        return $termIds
            ->map(function (int|string $termId) use ($ledgerEntries, $payments, $terms): array {
                $termLedgerEntries = $ledgerEntries->where('term_id', $termId)->values();
                $termPayments = $payments->where('term_id', $termId)->values();
                $latestLedgerEntry = $termLedgerEntries->first();

                return [
                    'term_id' => (int) $termId,
                    'term_name' => $terms->get($termId)?->term_name,
                    'total_assessment' => $this->sumAmounts($termLedgerEntries->where('entry_type', 'assessment')->pluck('amount')),
                    'total_paid' => $termPayments->isNotEmpty()
                        ? $this->sumAmounts($termPayments->pluck('amount'))
                        : $this->sumAbsoluteAmounts($termLedgerEntries->where('entry_type', 'payment')->pluck('amount')),
                    'remaining_balance' => $latestLedgerEntry instanceof LedgerEntry && $latestLedgerEntry->running_balance !== null
                        ? $this->money->normalize((string) $latestLedgerEntry->running_balance)
                        : $this->sumAmounts($termLedgerEntries->pluck('amount')),
                    'latest_entry_at' => $latestLedgerEntry?->posted_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function gradesByTerm(StudentProfile $studentProfile): array
    {
        $enrollmentIds = $studentProfile->enrollments()->pluck('id');

        if ($enrollmentIds->isEmpty()) {
            return [];
        }

        $grades = Grade::query()
            ->with(['subject', 'term'])
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('is_finalized', true)
            ->orderByDesc('term_id')
            ->orderBy('id')
            ->get();

        return $grades
            ->groupBy('term_id')
            ->map(function (Collection $termGrades, int|string $termId): array {
                $term = $termGrades->first()?->term;

                return [
                    'term_id' => (int) $termId,
                    'term_name' => $term?->term_name,
                    'grades' => $termGrades
                        ->map(fn (Grade $grade): array => [
                            'grade_id' => (int) $grade->id,
                            'subject_id' => (int) $grade->subject_id,
                            'subject_code' => $grade->subject?->code,
                            'subject_description' => $grade->subject?->description,
                            'grade' => $grade->grade !== null ? (string) $grade->grade : null,
                            'remarks' => $grade->remarks,
                            'is_inc' => (bool) $grade->is_inc,
                            'is_finalized' => (bool) $grade->is_finalized,
                            'finalized_at' => $grade->finalized_at?->toDateTimeString(),
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
        if ($studentProfile->user_id === null) {
            return [];
        }

        return GradeCorrection::query()
            ->with(['subject', 'term'])
            ->where('user_id', $studentProfile->user_id)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (GradeCorrection $correction): array => [
                'grade_correction_id' => (int) $correction->id,
                'grade_id' => $correction->grade_id !== null ? (int) $correction->grade_id : null,
                'term_id' => $correction->term_id !== null ? (int) $correction->term_id : null,
                'term_name' => $correction->term?->term_name,
                'subject_id' => $correction->subject_id !== null ? (int) $correction->subject_id : null,
                'subject_code' => $correction->subject?->code,
                'assessment_component' => $correction->assessment_component,
                'status' => $correction->status instanceof \BackedEnum ? $correction->status->value : (string) $correction->status,
                'requested_action' => $correction->requested_action,
                'resolved_at' => $correction->resolved_at?->toDateTimeString(),
                'created_at' => $correction->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
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

        return $studentProfile->user
            ->notifications()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => (string) $notification->id,
                'type' => $notification->type,
                'title' => data_get($notification->data, 'title', $notification->type),
                'body' => data_get($notification->data, 'body'),
                'read_at' => $notification->read_at?->toDateTimeString(),
                'created_at' => $notification->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function help(): array
    {
        return [
            'help_path' => route('faq', [], false),
            'public_faq_path' => route('faq', [], false),
            'faq_entries' => FaqEntry::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(5)
                ->get()
                ->map(fn (FaqEntry $entry): array => [
                    'faq_entry_id' => (int) $entry->id,
                    'question' => $entry->question,
                    'answer' => $entry->answer,
                    'category' => $entry->category,
                    'category_label' => FaqEntry::categoryLabel($entry->category),
                ])
                ->values()
                ->all(),
        ];
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
