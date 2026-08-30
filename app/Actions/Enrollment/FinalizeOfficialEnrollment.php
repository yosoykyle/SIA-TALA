<?php

namespace App\Actions\Enrollment;

use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\RegistrationIdentityConfirmationVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class FinalizeOfficialEnrollment
{
    public function __construct(
        private readonly RegistrationReadinessQuery $readiness,
        private readonly RegistrationPlacementValidator $placementValidator,
        private readonly AllocateStudentNumber $studentNumbers,
        private readonly RegistrationNotificationLedger $notifications,
        private readonly ConfirmRegistrationIdentity $identityConfirmations,
    ) {}

    public function execute(
        Enrollment $enrollment,
        User $actor,
        ?string $remark = null,
        ?CarbonImmutable $recordedAt = null,
    ): Enrollment {
        Gate::forUser($actor)->authorize('officiallyEnroll', $enrollment);
        $recordedAt ??= CarbonImmutable::now(config('app.timezone'));

        $official = DB::transaction(function () use ($enrollment, $actor, $remark, $recordedAt): Enrollment {
            $locked = Enrollment::query()
                ->with([
                    'credentialUser.roles', 'admissionApplication.program', 'studentProfile.program',
                    'currentProposalVersion.items.reservation', 'currentProposalVersion.items.section', 'currentProposalVersion.timetableVersion',
                    'termAccount.assessments.obligations', 'term',
                ])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $locked->current_cor_version_id !== null) {
                return $locked;
            }

            if ($locked->canonical_outcome !== Enrollment::OutcomeInProgress) {
                throw ValidationException::withMessages(['case' => 'Only an active Registration Case may be finalized.']);
            }

            $proposal = RegistrationProposalVersion::query()
                ->with(['items.reservation', 'items.section', 'confirmation', 'timetableVersion'])
                ->whereKey($locked->current_proposal_version_id)
                ->lockForUpdate()
                ->first();
            $assessment = Assessment::query()
                ->with('obligations')
                ->where('enrollment_id', $locked->id)
                ->where('state', Assessment::StateActive)
                ->latest('version')
                ->lockForUpdate()
                ->first();

            if (! $proposal instanceof RegistrationProposalVersion || ! $assessment instanceof Assessment) {
                throw ValidationException::withMessages(['readiness' => 'Current proposal and assessment authority are required.']);
            }

            EnrollmentSeatReservation::query()
                ->where('enrollment_id', $locked->id)
                ->lockForUpdate()
                ->get();

            $this->placementValidator->assertCurrent($locked, $proposal, lockForUpdate: true);

            $readiness = $this->readiness->for($locked);
            if ($readiness['ready'] !== true) {
                throw ValidationException::withMessages(['readiness' => 'Official enrollment is blocked by: '.implode(', ', $readiness['blockers']).'.']);
            }

            $identityConfirmation = null;
            if (! ($locked->studentProfile instanceof StudentProfile)) {
                if ($locked->admissionApplication === null) {
                    throw ValidationException::withMessages(['identity' => 'A first Student activation requires the admitted Application source.']);
                }

                $identityConfirmation = $this->identityConfirmations->latestMatching($locked, $locked->admissionApplication);
                if (! $identityConfirmation instanceof RegistrationIdentityConfirmationVersion) {
                    throw ValidationException::withMessages([
                        'identity' => 'The learner must confirm the current identity and contact source before Official Enrollment.',
                    ]);
                }
            }

            $studentProfile = $this->activateStudent($locked, $proposal, $recordedAt, $identityConfirmation);
            $studentProfile->loadMissing('program');
            $officialCourses = [];

            foreach ($proposal->items as $item) {
                $reservation = $item->reservation;
                if (! $reservation instanceof EnrollmentSeatReservation
                    || $reservation->status !== EnrollmentSeatReservation::StatusActive) {
                    throw ValidationException::withMessages(['placement' => 'Every proposal item needs one active reservation.']);
                }

                $course = CourseEnrollment::query()->firstOrCreate(
                    [
                        'enrollment_id' => $locked->id,
                        'term_offering_id' => $item->term_offering_id,
                    ],
                    [
                        'section_id' => $item->section_id,
                        'registration_proposal_item_id' => $item->id,
                        'published_timetable_version_id' => $proposal->published_timetable_version_id,
                        'change_source' => 'InitialFinalization',
                        'effective_from' => $recordedAt,
                        'is_current' => true,
                        'status' => CourseEnrollment::StatusActive,
                        'units_snapshot' => $item->units_snapshot,
                        'added_at' => $recordedAt,
                        'status_reason' => 'Created atomically from the confirmed Registration Proposal.',
                    ],
                );
                $reservation->update([
                    'course_enrollment_id' => $course->id,
                    'status' => EnrollmentSeatReservation::StatusConverted,
                    'converted_at' => $recordedAt,
                    'lock_version' => $reservation->lock_version + 1,
                ]);
                $officialCourses[] = [
                    'course_enrollment_id' => $course->id,
                    'proposal_item_id' => $item->id,
                    'section_id' => $item->section_id,
                    'section_code' => $item->section?->code,
                    'course_code' => $item->course_code_snapshot,
                    'course_title' => $item->course_title_snapshot,
                    'units' => $item->units_snapshot,
                    'scheduling_treatment' => $item->scheduling_treatment_snapshot,
                    'contact_hours' => $item->contact_hours_snapshot,
                    'meetings' => $item->meeting_snapshot,
                ];
            }

            $corSnapshot = [
                'case_reference' => $locked->case_reference,
                'student_number' => $studentProfile->student_number,
                'student_name' => collect([$studentProfile->first_name, $studentProfile->middle_name, $studentProfile->last_name])->filter()->implode(' '),
                'program_id' => $studentProfile->program_id,
                'program_code' => $studentProfile->program?->code,
                'program_name' => $studentProfile->program?->name,
                'curriculum_version_id' => $studentProfile->curriculum_version_id,
                'represented_curriculum_levels' => array_filter([
                    data_get($proposal->source_snapshot, 'unit_load.year_level'),
                ]),
                'term_id' => $locked->term_id,
                'term_label' => $locked->term?->label,
                'proposal_version_id' => $proposal->id,
                'selection_basis' => $locked->selection_basis,
                'identity_confirmation_version_id' => $identityConfirmation?->id,
                'identity_source_hash' => $identityConfirmation?->source_hash,
                'published_timetable_version_id' => $proposal->published_timetable_version_id,
                'assessment_id' => $assessment->id,
                'assessment_total' => $assessment->total,
                'term_account_id' => $assessment->term_account_id,
                'finance_satisfaction' => [
                    'state' => 'Satisfied at finalization',
                    'source' => 'EnrollmentPaymentRequirementProjection',
                    'assessment_id' => $assessment->id,
                    'assessment_version' => $assessment->version,
                    'recorded_at' => $recordedAt->toIso8601String(),
                ],
                'fees' => $assessment->lines()->orderBy('id')->get()->map(fn ($line): array => [
                    'label' => $line->description_snapshot,
                    'amount' => $line->amount,
                ])->all(),
                'courses' => $officialCourses,
                'issued_by_user_id' => $actor->id,
                'issued_by_name' => $actor->getFilamentName(),
                'issued_at' => $recordedAt->toIso8601String(),
            ];
            $cor = CorVersion::query()->firstOrCreate(
                ['enrollment_id' => $locked->id, 'version' => 1],
                [
                    'supersedes_version_id' => null,
                    'registration_proposal_version_id' => $proposal->id,
                    'assessment_id' => $assessment->id,
                    'published_timetable_version_id' => $proposal->published_timetable_version_id,
                    'snapshot' => $corSnapshot,
                    'content_hash' => hash('sha256', json_encode($corSnapshot, JSON_THROW_ON_ERROR)),
                    'issued_by' => $actor->id,
                    'issued_at' => $recordedAt,
                ],
            );

            $locked->update([
                'student_profile_id' => $studentProfile->id,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'status' => 'officially_enrolled',
                'current_cor_version_id' => $cor->id,
                'registered_at' => $locked->registered_at ?? $recordedAt,
                'officially_enrolled_at' => $recordedAt,
                'finalized_by' => $actor->id,
                'status_reason' => $remark ?: 'Registrar finalized all five authoritative checkpoints atomically.',
                'lock_version' => $locked->lock_version + 1,
            ]);

            return $locked->refresh()->load(['studentProfile.user', 'courseEnrollments.section', 'currentCorVersion']);
        }, attempts: 3);

        $this->notifications->recordOfficialEnrollment($official);

        return $official;
    }

    private function activateStudent(
        Enrollment $enrollment,
        RegistrationProposalVersion $proposal,
        CarbonImmutable $recordedAt,
        ?RegistrationIdentityConfirmationVersion $identityConfirmation,
    ): StudentProfile {
        $existing = StudentProfile::query()->where('user_id', $enrollment->credential_user_id)->lockForUpdate()->first();
        if ($existing instanceof StudentProfile) {
            return $existing;
        }

        $application = $enrollment->admissionApplication;
        if ($application === null) {
            throw ValidationException::withMessages(['identity' => 'A first Student activation requires the admitted Application source.']);
        }

        if (! $identityConfirmation instanceof RegistrationIdentityConfirmationVersion) {
            throw ValidationException::withMessages(['identity' => 'The current identity confirmation snapshot is required.']);
        }
        $identity = $identityConfirmation->identity_snapshot;

        $studentNumber = $this->studentNumbers->execute((int) $recordedAt->format('Y'));
        $profile = StudentProfile::query()->create([
            'user_id' => $enrollment->credential_user_id,
            'applicant_intake_id' => $application->id,
            'student_number' => $studentNumber,
            'entry_term_id' => $enrollment->term_id,
            'first_name' => $identity['first_name'],
            'middle_name' => $identity['middle_name'],
            'last_name' => $identity['last_name'],
            'birth_date' => $identity['birth_date'],
            'prior_identifier' => $identity['prior_identifier'],
            'program_id' => $application->program_id,
            'curriculum_version_id' => $proposal->curriculum_version_id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingNotYetEvaluated,
            'email' => $identity['email'],
            'phone' => $identity['phone'],
            'address' => $identity['address'],
        ]);

        Role::findOrCreate('student', 'web');
        $enrollment->credentialUser->forceFill([
            'status' => User::StatusActive,
            'username' => $studentNumber,
        ])->save();
        $enrollment->credentialUser->assignRole('student');

        return $profile;
    }
}
