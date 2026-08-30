<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartRegistrationCase
{
    public function __construct(
        private readonly ReadyApplicantProjectionQuery $readyApplicants,
        private readonly CalendarPhaseGateService $calendarPhaseGate,
        private readonly RegistrationSelectionBasisResolver $selectionBasisResolver,
    ) {}

    public function forReadyApplicant(
        AdmissionApplication $application,
        Term $term,
        User $actor,
        string $startMethod = 'SelfService',
        ?string $authorityReference = null,
    ): Enrollment {
        if (! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only an active authorized user may start a Registration Case.');
        }

        if ((int) $application->user_id !== (int) $actor->id
            && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only the Applicant or authorized Registrar may start this Registration Case.');
        }

        $projection = $this->readyApplicants->forApplication($application);

        if ($projection['ready'] !== true || (int) $projection['term_id'] !== (int) $term->id) {
            throw ValidationException::withMessages(['application' => 'The Applicant is not Ready for Enrollment in this exact Term.']);
        }

        return $this->createOrReturn(
            credentialUser: $application->user()->firstOrFail(),
            term: $term,
            actor: $actor,
            selectionBasis: $this->selectionBasisResolver->forApplicant($application),
            startMethod: $startMethod,
            application: $application,
            studentProfile: null,
            authorityReference: $authorityReference,
        );
    }

    public function forContinuingStudent(
        StudentProfile $studentProfile,
        Term $term,
        User $actor,
        string $startMethod = 'SelfService',
        ?string $authorityReference = null,
    ): Enrollment {
        if (! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only an active authorized user may start a Registration Case.');
        }

        if ((int) $studentProfile->user_id !== (int) $actor->id
            && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only the Student or authorized Registrar may start this Registration Case.');
        }

        if ($studentProfile->blocksEnrollmentByLifecycle()) {
            throw ValidationException::withMessages(['student_profile' => 'The Student lifecycle does not allow registration.']);
        }

        return $this->createOrReturn(
            credentialUser: $studentProfile->user()->firstOrFail(),
            term: $term,
            actor: $actor,
            selectionBasis: $this->selectionBasisResolver->forContinuingStudent($studentProfile),
            startMethod: $startMethod,
            application: null,
            studentProfile: $studentProfile,
            authorityReference: $authorityReference,
        );
    }

    private function createOrReturn(
        User $credentialUser,
        Term $term,
        User $actor,
        string $selectionBasis,
        string $startMethod,
        ?AdmissionApplication $application,
        ?StudentProfile $studentProfile,
        ?string $authorityReference,
    ): Enrollment {
        $actorIsRegistrar = $actor->hasRole(User::StaffRoleRegistrar);

        if (! in_array($startMethod, ['SelfService', 'RegistrarAssisted', 'LateAuthority'], true)) {
            throw ValidationException::withMessages(['start_method' => 'Select an approved start method.']);
        }

        if (in_array($startMethod, ['RegistrarAssisted', 'LateAuthority'], true) && blank($authorityReference)) {
            throw ValidationException::withMessages(['authority_reference' => 'Assisted or late registration requires recorded authority.']);
        }

        if ($startMethod === 'SelfService' && (int) $actor->id !== (int) $credentialUser->id) {
            throw new AuthorizationException('Self-service registration may be started only by the owning learner.');
        }

        if (in_array($startMethod, ['RegistrarAssisted', 'LateAuthority'], true) && ! $actorIsRegistrar) {
            throw new AuthorizationException('Assisted and late registration methods require authorized Registrar staff.');
        }

        if ($startMethod !== 'LateAuthority') {
            $this->calendarPhaseGate->assertEnrollmentWindowOpen($term->id);
        }

        return DB::transaction(function () use ($credentialUser, $term, $actor, $selectionBasis, $startMethod, $application, $studentProfile, $authorityReference): Enrollment {
            User::query()->whereKey($credentialUser->id)->lockForUpdate()->firstOrFail();
            Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

            $existing = Enrollment::query()
                ->where('credential_user_id', $credentialUser->id)
                ->where('term_id', $term->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Enrollment) {
                return $existing;
            }

            $enrollment = Enrollment::query()->create([
                'credential_user_id' => $credentialUser->id,
                'admission_application_id' => $application?->id,
                'student_profile_id' => $studentProfile?->id,
                'term_id' => $term->id,
                'case_reference' => 'REG-'.Str::upper((string) Str::ulid()),
                'selection_basis' => $selectionBasis,
                'canonical_outcome' => Enrollment::OutcomeInProgress,
                'status' => 'pending_review',
                'student_type' => null,
                'started_by' => $actor->id,
                'start_method' => $startMethod,
                'started_at' => now(),
                'status_reason' => 'Registration Case started from authoritative source facts.',
            ]);

            RegistrationCaseEvent::query()->create([
                'enrollment_id' => $enrollment->id,
                'sequence' => 1,
                'event_type' => 'Started',
                'from_outcome' => null,
                'to_outcome' => Enrollment::OutcomeInProgress,
                'reason' => $startMethod,
                'authority_reference' => $authorityReference,
                'actor_id' => $actor->id,
                'recorded_at' => now(),
            ]);

            return $enrollment->refresh();
        }, attempts: 3);
    }
}
