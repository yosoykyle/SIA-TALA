<?php

namespace App\Actions\Cor;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
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

    public function __construct(
        private readonly CurrentOfficialEnrollmentResolver $currentEnrollmentResolver,
        private readonly HoldEvaluationService $holdEvaluation,
    ) {}

    /** @return array<string, mixed> */
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

        return $enrollment instanceof Enrollment
            ? $this->forEnrollment($enrollment, $actor, self::CopyStudent, true)
            : $this->unavailable('No current official enrollment is available for COR viewing.');
    }

    /** @return array<string, mixed> */
    public function forEnrollment(
        Enrollment $enrollment,
        User $actor,
        string $copyContext = self::CopyStudent,
        bool $studentCurrentOnly = false,
        ?CorVersion $requestedVersion = null,
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

        if ($enrollment->studentProfile->blocksCurrentCorByLifecycle()) {
            return $this->unavailable(sprintf(
                'Your current COR is unavailable while your student lifecycle status is %s. Please contact the Registrar Office for the next step.',
                StudentProfile::lifecycleStatusLabel($enrollment->studentProfile->lifecycle_status),
            ), $enrollment);
        }

        $activeHolds = $this->blockingCorHolds($enrollment);

        if ($activeHolds->isNotEmpty() && $this->actorOwnsEnrollment($actor, $enrollment)) {
            $message = $activeHolds
                ->map(fn (Hold $hold): ?string => $hold->studentFacingMessage())
                ->filter()
                ->first() ?: 'A COR download hold is active. Please contact the Registrar or Accounting Office.';

            return $this->unavailable($message, $enrollment);
        }

        $corVersion = $requestedVersion ?? $enrollment->currentCorVersion;

        if ($corVersion instanceof CorVersion && (int) $corVersion->enrollment_id !== (int) $enrollment->id) {
            abort(403);
        }

        return $corVersion instanceof CorVersion
            ? $this->fromImmutableVersion($enrollment, $corVersion, $copyContext)
            : $this->unavailable(
                'The immutable COR source is unavailable. Registrar must repair the official record; live data will not be substituted.',
                $enrollment,
            );
    }

    /** @param array<string, mixed> $output */
    public function recordAccess(array $output, User $actor, string $action, ?Request $request = null): void
    {
        if (($output['available'] ?? false) !== true || ! ($output['enrollment'] ?? null) instanceof Enrollment) {
            return;
        }

        /** @var Enrollment $enrollment */
        $enrollment = $output['enrollment'];
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
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function currentOfficialEnrollment(StudentProfile $studentProfile): ?Enrollment
    {
        $enrollment = $this->currentEnrollmentResolver->forProfile($studentProfile);

        if ($enrollment instanceof Enrollment) {
            $this->loadEnrollment($enrollment);
        }

        return $enrollment;
    }

    private function loadEnrollment(Enrollment $enrollment): void
    {
        $enrollment->loadMissing([
            'currentCorVersion',
            'credentialUser',
            'studentProfile.user',
            'studentProfile.program',
            'term',
            'holds',
        ]);
    }

    /** @return array<string, mixed> */
    private function fromImmutableVersion(Enrollment $enrollment, CorVersion $corVersion, string $copyContext): array
    {
        $snapshot = $corVersion->snapshot;
        $subjects = collect($snapshot['courses'] ?? [])->flatMap(function (array $course) use ($snapshot): array {
            return collect($course['meetings'] ?? [])->map(function (array $meeting) use ($course, $snapshot): array {
                $day = match ((int) ($meeting['day_of_week'] ?? 0)) {
                    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday', default => 'Not recorded',
                };

                return [
                    'course_enrollment_id' => $course['course_enrollment_id'],
                    'subject_code' => $course['course_code'],
                    'subject_description' => $course['course_title'],
                    'units' => $course['units'],
                    'lecture_hours' => '0.00',
                    'laboratory_hours' => '0.00',
                    'section' => (string) ($course['section_code'] ?? $course['section_id']),
                    'day' => $day,
                    'time' => substr((string) ($meeting['starts_at'] ?? ''), 0, 5).' - '.substr((string) ($meeting['ends_at'] ?? ''), 0, 5),
                    'room' => $meeting['room_label'] ?? 'Online / TBA',
                    'instructor' => $meeting['faculty_name'] ?? 'Not recorded',
                    'modality' => $meeting['modality'] ?? 'Not recorded',
                    'schedule_version' => $snapshot['published_timetable_version_id'],
                ];
            })->all();
        })->values()->all();
        $totalUnits = collect($snapshot['courses'] ?? [])->sum(fn (array $course): float => (float) ($course['units'] ?? 0));
        $state = [
            'availability_status' => 'Available',
            'notice' => 'This immutable COR version is available for print or browser save-as-PDF.',
            'student_number' => $snapshot['student_number'],
            'student_name' => $snapshot['student_name'],
            'program' => (string) ($snapshot['program_code'] ?? $snapshot['program_name'] ?? $snapshot['program_id']),
            'curriculum_level' => 'Recorded curriculum version #'.$snapshot['curriculum_version_id'],
            'term' => $snapshot['term_label'],
            'registration_date' => $corVersion->issued_at?->toFormattedDateString(),
            'payment_status' => 'Cleared at finalization',
            'course_delivery_mix' => 'Published timetable version #'.$snapshot['published_timetable_version_id'],
            'total_units' => number_format($totalUnits, 2, '.', ''),
            'balance' => 'PHP 0.00',
            'subjects' => $subjects,
            'installment_applicable' => false,
            'installment_rows' => [],
        ];

        return [
            'available' => true,
            'reason' => null,
            'enrollment' => $enrollment,
            'student_profile' => $enrollment->studentProfile,
            'student' => $enrollment->credentialUser,
            'term' => $enrollment->term,
            'copy_context' => $copyContext,
            'generated_at' => $corVersion->issued_at,
            'schedule_version' => $snapshot['published_timetable_version_id'],
            'subjects' => $subjects,
            'fees' => $snapshot['fees'] ?? [],
            'installment' => ['applicable' => false, 'rows' => []],
            'summary' => [
                'enrollment_id' => $enrollment->id,
                'cor_version_id' => $corVersion->id,
                'cor_version' => $corVersion->version,
                'student_number' => $snapshot['student_number'],
                'student_name' => $snapshot['student_name'],
                'prior_identifier' => $enrollment->studentProfile?->prior_identifier,
                'program' => (string) ($snapshot['program_code'] ?? $snapshot['program_name'] ?? $snapshot['program_id']),
                'curriculum_level' => 'Recorded curriculum version #'.$snapshot['curriculum_version_id'],
                'term' => $snapshot['term_label'],
                'registration_date' => $corVersion->issued_at?->toFormattedDateString(),
                'payment_status' => 'Cleared at finalization',
                'course_delivery_mix' => 'Published timetable version #'.$snapshot['published_timetable_version_id'],
                'total_units' => number_format($totalUnits, 2, '.', ''),
                'balance' => '0.00',
                'status' => 'Available',
                'notice' => $state['notice'],
            ],
            'state' => $state,
        ];
    }

    private function isOfficial(Enrollment $enrollment): bool
    {
        return $enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
            && $enrollment->officially_enrolled_at !== null;
    }

    private function actorCanAccess(User $actor, Enrollment $enrollment): bool
    {
        return $this->actorOwnsEnrollment($actor, $enrollment)
            || $actor->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]);
    }

    private function actorOwnsEnrollment(User $actor, Enrollment $enrollment): bool
    {
        return (int) $enrollment->credential_user_id === (int) $actor->id;
    }

    /** @return Collection<int, Hold> */
    private function blockingCorHolds(Enrollment $enrollment): Collection
    {
        return $this->holdEvaluation->activeBlockingHolds(
            $enrollment->studentProfile,
            [Hold::BlockingCorPrint],
            $enrollment,
        );
    }

    /** @return array<string, mixed> */
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
                'curriculum_level' => 'Not available',
                'term' => 'Not available',
                'registration_date' => 'Not available',
                'payment_status' => 'Not available',
                'course_delivery_mix' => 'Not available',
                'total_units' => '0.00',
                'balance' => 'PHP 0.00',
                'subjects' => [],
                'installment_applicable' => false,
                'installment_rows' => [],
            ],
        ];
    }
}
