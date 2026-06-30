<?php

namespace App\Actions\Cor;

use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\PaymentScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildCorOutput
{
    public const OutputType = 'COR';

    public const ActionView = 'VIEW';

    public const ActionPrint = 'PRINT';

    public const CopyStudent = 'STUDENT_COPY';

    public const CopyRegistrar = 'REGISTRAR_COPY';

    public const CopyAccounting = 'ACCOUNTING_COPY';

    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(User $actor): array
    {
        $studentProfile = StudentProfile::query()
            ->with(['user', 'program'])
            ->where('user_id', $actor->id)
            ->first();

        if (! $studentProfile instanceof StudentProfile) {
            return $this->unavailable('No student profile is linked to your account yet.');
        }

        $enrollment = $this->currentOfficialEnrollment($studentProfile);

        if (! $enrollment instanceof Enrollment) {
            return $this->unavailable('No current official enrollment is available for COR viewing.');
        }

        return $this->forEnrollment($enrollment, $actor, self::CopyStudent, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function forEnrollment(
        Enrollment $enrollment,
        User $actor,
        string $copyContext = self::CopyStudent,
        bool $studentCurrentOnly = false,
    ): array {
        $this->loadEnrollment($enrollment);

        if (! $this->actorCanAccess($actor, $enrollment)) {
            abort(403);
        }

        if ($studentCurrentOnly && $this->actorOwnsEnrollment($actor, $enrollment)) {
            $current = $this->currentOfficialEnrollment($enrollment->studentProfile);

            if (! $current instanceof Enrollment || ! $current->is($enrollment)) {
                return $this->unavailable('Students may view and print only the current active COR.');
            }
        }

        if (! $this->isOfficial($enrollment)) {
            return $this->unavailable('This enrollment is not officially enrolled yet.');
        }

        $activeHolds = $this->blockingCorHolds($enrollment);

        if ($activeHolds->isNotEmpty() && $this->actorOwnsEnrollment($actor, $enrollment)) {
            $message = $activeHolds
                ->map(fn (Hold $hold): ?string => $hold->studentFacingMessage())
                ->filter()
                ->first() ?: 'A COR download hold is active. Please contact the Registrar or Accounting Office.';

            return $this->unavailable($message, $enrollment);
        }

        $subjects = $this->subjectRows($enrollment);
        $finance = $this->financeSummary($enrollment);
        $scheduleVersion = collect($subjects)->pluck('schedule_version')->filter()->max();
        $totalUnits = collect($subjects)
            ->unique('course_enrollment_id')
            ->reduce(fn (string $carry, array $row): string => $this->money->add($carry, (string) $row['units']), '0.00');
        $studentName = $this->studentName($enrollment->studentProfile);
        $program = $enrollment->studentProfile->program->code ?: $enrollment->studentProfile->program->name;
        $yearLevel = $this->yearLevel($enrollment);
        $term = $enrollment->term->label;
        $registrationDate = $enrollment->officially_enrolled_at?->toFormattedDateString()
            ?? $enrollment->registered_at?->toFormattedDateString()
            ?? 'Not recorded';
        $deliveryModality = $this->deliveryModality($enrollment);

        return [
            'available' => true,
            'reason' => null,
            'enrollment' => $enrollment,
            'student_profile' => $enrollment->studentProfile,
            'student' => $enrollment->studentProfile->user,
            'term' => $enrollment->term,
            'copy_context' => $copyContext,
            'generated_at' => Carbon::now(config('app.timezone')),
            'schedule_version' => $scheduleVersion !== null ? (int) $scheduleVersion : null,
            'subjects' => $subjects,
            'fees' => $finance['fees'],
            'summary' => [
                'enrollment_id' => (int) $enrollment->id,
                'student_number' => $enrollment->studentProfile->student_number,
                'student_name' => $studentName,
                'prior_identifier' => $enrollment->studentProfile->prior_identifier,
                'program' => $program,
                'year_level' => $yearLevel,
                'term' => $term,
                'registration_date' => $registrationDate,
                'payment_status' => $finance['payment_status'],
                'delivery_modality' => $deliveryModality,
                'total_units' => $totalUnits,
                'balance' => $finance['balance'],
                'status' => 'Available',
                'notice' => 'Your current official COR is available for print or browser save-as-PDF.',
            ],
            'state' => [
                'availability_status' => 'Available',
                'notice' => 'Your current official COR is available for print or browser save-as-PDF.',
                'student_number' => $enrollment->studentProfile->student_number,
                'student_name' => $studentName,
                'program' => $program,
                'year_level' => $yearLevel,
                'term' => $term,
                'registration_date' => $registrationDate,
                'payment_status' => $finance['payment_status'],
                'delivery_modality' => $deliveryModality,
                'total_units' => $totalUnits,
                'balance' => 'PHP '.$finance['balance'],
                'subjects' => $subjects,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function recordAccess(array $output, User $actor, string $action, ?Request $request = null): void
    {
        if (($output['available'] ?? false) !== true || ! ($output['enrollment'] ?? null) instanceof Enrollment) {
            return;
        }

        /** @var Enrollment $enrollment */
        $enrollment = $output['enrollment'];
        $now = Carbon::now();

        DB::table('output_access_logs')->insert([
            'output_type' => self::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $enrollment->id,
            'student_profile_id' => $enrollment->student_profile_id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->roles()->value('name'),
            'action' => $action,
            'copy_context' => $output['copy_context'] ?? self::CopyStudent,
            'schedule_version' => $output['schedule_version'] ?? null,
            'request_context' => json_encode([
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'route' => $request?->route()?->getName(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'logged',
            'occurred_at' => $now,
        ]);
    }

    private function currentOfficialEnrollment(StudentProfile $studentProfile): ?Enrollment
    {
        $enrollment = Enrollment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', 'officially_enrolled')
            ->whereNotNull('officially_enrolled_at')
            ->orderByDesc('officially_enrolled_at')
            ->orderByDesc('id')
            ->first();

        if (! $enrollment instanceof Enrollment) {
            return null;
        }

        $this->loadEnrollment($enrollment);

        return $enrollment;
    }

    private function loadEnrollment(Enrollment $enrollment): void
    {
        $enrollment->loadMissing([
            'studentProfile.user',
            'studentProfile.program',
            'term',
            'courseEnrollments.termOffering.curriculumEntry.courseSpecification.course',
            'courseEnrollments.termOffering.curriculumEntry.courseSpecification.components',
            'courseEnrollments.scheduleBindings.sectionMeeting.faculty',
            'courseEnrollments.scheduleBindings.sectionMeeting.room',
            'courseEnrollments.scheduleBindings.sectionMeeting.scheduleRun',
            'courseEnrollments.scheduleBindings.sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
            'holds',
        ]);
    }

    private function isOfficial(Enrollment $enrollment): bool
    {
        return $enrollment->status === 'officially_enrolled' && $enrollment->officially_enrolled_at !== null;
    }

    private function actorCanAccess(User $actor, Enrollment $enrollment): bool
    {
        return $this->actorOwnsEnrollment($actor, $enrollment)
            || $actor->hasAnyRole([
                User::StaffRoleRegistrar,
                User::StaffRoleAccounting,
            ]);
    }

    private function actorOwnsEnrollment(User $actor, Enrollment $enrollment): bool
    {
        return (int) $enrollment->studentProfile->user_id === (int) $actor->id;
    }

    /**
     * @return Collection<int, Hold>
     */
    private function blockingCorHolds(Enrollment $enrollment): Collection
    {
        return Hold::query()
            ->where('student_profile_id', $enrollment->student_profile_id)
            ->where('status', Hold::StatusActive)
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('term_id')->orWhere('term_id', $enrollment->term_id);
            })
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('enrollment_id')->orWhere('enrollment_id', $enrollment->id);
            })
            ->where(function ($query): void {
                $query->where('hold_type', Hold::TypeCorDownload)
                    ->orWhere('blocking_level', Hold::BlockingCorPrint);
            })
            ->orderBy('effective_at')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subjectRows(Enrollment $enrollment): array
    {
        return $enrollment->courseEnrollments
            ->filter(fn (CourseEnrollment $courseEnrollment): bool => $courseEnrollment->status === CourseEnrollment::StatusActive)
            ->flatMap(function (CourseEnrollment $courseEnrollment): array {
                $termOffering = $courseEnrollment->termOffering;
                $courseSpecification = $termOffering->curriculumEntry->courseSpecification;
                $course = $courseSpecification->course;
                $activeBindings = $courseEnrollment->scheduleBindings
                    ->filter(fn (StudentScheduleBinding $binding): bool => $binding->is_active)
                    ->filter(fn (StudentScheduleBinding $binding): bool => $this->isActiveOfficialMeeting($binding->sectionMeeting))
                    ->values();
                $base = [
                    'course_enrollment_id' => (int) $courseEnrollment->id,
                    'subject_code' => $course->code,
                    'subject_description' => $courseSpecification->title ?: $courseSpecification->description,
                    'units' => $this->money->normalize((string) ($courseEnrollment->units_snapshot ?? $courseSpecification->credit_units)),
                    'lecture_hours' => $this->componentHours($courseSpecification->components, 'LECTURE'),
                    'laboratory_hours' => $this->componentHours($courseSpecification->components, 'LABORATORY'),
                    'section' => 'Unassigned',
                    'day' => 'Unscheduled',
                    'time' => 'Unscheduled',
                    'room' => 'TBA',
                    'instructor' => 'TBA',
                    'schedule_version' => null,
                ];

                if ($activeBindings->isEmpty()) {
                    return [$base];
                }

                return $activeBindings
                    ->map(function (StudentScheduleBinding $binding) use ($base): array {
                        $meeting = $binding->sectionMeeting;
                        $scheduleRun = $meeting->scheduleRun;
                        $section = $meeting->schedulingDemand->sectionDeliveryGroup->section;

                        return [
                            ...$base,
                            'section' => $section->code,
                            'day' => SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled',
                            'time' => $this->timeRange($meeting->starts_at, $meeting->ends_at),
                            'room' => $meeting->room_id !== null ? $meeting->room->code : 'TBA',
                            'instructor' => $meeting->faculty->name,
                            'schedule_version' => $scheduleRun instanceof ScheduleGenerationRun
                                ? $scheduleRun->publication_version
                                : null,
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    private function isActiveOfficialMeeting(SectionMeeting $meeting): bool
    {
        return $meeting->state === SectionMeeting::StateActive
            && $meeting->scheduleRun instanceof ScheduleGenerationRun
            && $meeting->scheduleRun->status === ScheduleGenerationRun::StatusPublished;
    }

    /**
     * @param  Collection<int, mixed>  $components
     */
    private function componentHours(Collection $components, string $type): string
    {
        return $components
            ->where('component_type', $type)
            ->reduce(fn (string $carry, mixed $component): string => $this->money->add($carry, (string) $component->weekly_contact_hours), '0.00');
    }

    /**
     * @return array{payment_status:string,balance:string,fees:list<array{label:string,amount:string}>}
     */
    private function financeSummary(Enrollment $enrollment): array
    {
        $assessment = Assessment::query()
            ->with(['lines', 'paymentScheduleRows'])
            ->where('enrollment_id', $enrollment->id)
            ->where('state', Assessment::StateActive)
            ->latest('version')
            ->latest('id')
            ->first();
        $ledgerEntries = LedgerEntry::query()
            ->where('enrollment_id', $enrollment->id)
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();
        $balance = $ledgerEntries->reduce(
            fn (string $carry, LedgerEntry $entry): string => $this->money->add($carry, $this->balanceAmount($entry)),
            '0.00',
        );
        $postedPayments = $ledgerEntries
            ->where('direction', LedgerEntry::DirectionPayment)
            ->reduce(fn (string $carry, LedgerEntry $entry): string => $this->money->add($carry, (string) abs((float) $entry->amount)), '0.00');
        $fees = [];

        if ($assessment instanceof Assessment) {
            foreach ($assessment->lines as $line) {
                $fees[] = [
                    'label' => $line->description_snapshot,
                    'amount' => $this->money->normalize((string) $line->amount),
                ];
            }

            $fees[] = ['label' => 'Total Fees', 'amount' => $this->money->normalize((string) $assessment->total)];
            $fees[] = ['label' => 'Down Payment', 'amount' => $this->money->normalize((string) $assessment->required_downpayment)];
        }

        $fees[] = ['label' => 'Posted Payments', 'amount' => $postedPayments];
        $fees[] = ['label' => 'Balance', 'amount' => $balance];

        return [
            'payment_status' => $this->paymentStatus($assessment, $balance, $postedPayments),
            'balance' => $balance,
            'fees' => $fees,
        ];
    }

    private function balanceAmount(LedgerEntry $entry): string
    {
        $amount = $this->money->normalize((string) $entry->amount);

        return match ($entry->direction) {
            LedgerEntry::DirectionPayment,
            LedgerEntry::DirectionDiscount,
            LedgerEntry::DirectionScholarship,
            LedgerEntry::DirectionWaiver => str_starts_with($amount, '-') ? $amount : '-'.$amount,
            default => $amount,
        };
    }

    private function paymentStatus(?Assessment $assessment, string $balance, string $postedPayments): string
    {
        if (! $assessment instanceof Assessment) {
            return 'No Active Assessment';
        }

        if (! $this->money->greaterThanZero($balance)) {
            return 'Full Paid';
        }

        if (! $this->money->greaterThanZero($postedPayments)) {
            return 'Unpaid';
        }

        if ($assessment->paymentScheduleRows->where('state', PaymentScheduleRow::StateDue)->count() > 1) {
            return 'Installment';
        }

        return 'Partially Paid';
    }

    private function deliveryModality(Enrollment $enrollment): string
    {
        $modality = $enrollment->courseEnrollments
            ->map(fn (CourseEnrollment $courseEnrollment): string => $courseEnrollment->termOffering->modality)
            ->filter()
            ->first();

        return TermOffering::modalityOptions()[$modality] ?? 'Not recorded';
    }

    private function yearLevel(Enrollment $enrollment): string
    {
        $yearLevel = $enrollment->courseEnrollments
            ->map(fn (CourseEnrollment $courseEnrollment): string => $courseEnrollment->termOffering->curriculumEntry->year_level)
            ->filter()
            ->first();

        return $yearLevel ?? 'Not recorded';
    }

    private function studentName(StudentProfile $studentProfile): string
    {
        if ($studentProfile->user instanceof User && filled($studentProfile->user->name)) {
            return $studentProfile->user->name;
        }

        return collect([$studentProfile->first_name, $studentProfile->middle_name, $studentProfile->last_name])
            ->filter()
            ->implode(' ');
    }

    private function timeRange(mixed $startsAt, mixed $endsAt): string
    {
        $start = $this->timeValue($startsAt);
        $end = $this->timeValue($endsAt);

        return $start !== null && $end !== null ? "{$start}-{$end}" : 'Unscheduled';
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, ?Enrollment $enrollment = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'enrollment' => $enrollment,
            'state' => [
                'availability_status' => 'Unavailable',
                'notice' => $reason,
                'student_number' => 'Not available',
                'student_name' => 'Not available',
                'program' => 'Not available',
                'year_level' => 'Not available',
                'term' => 'Not available',
                'registration_date' => 'Not available',
                'payment_status' => 'Not available',
                'delivery_modality' => 'Not available',
                'total_units' => '0.00',
                'balance' => 'PHP 0.00',
                'subjects' => [],
            ],
        ];
    }
}
