<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Validation\ValidationException;

class RegistrationEntryContext
{
    public function __construct(
        private readonly CalendarPhaseGateService $calendar,
        private readonly ReadyApplicantProjectionQuery $readyApplicants,
        private readonly RegistrationSelectionBasisResolver $selectionBasis,
    ) {}

    /** @return array{term:?Term,enrollment:?Enrollment,application:?AdmissionApplication,student_profile:?StudentProfile,can_start:bool,reason:?string} */
    public function forUser(User $user): array
    {
        $profile = StudentProfile::query()->where('user_id', $user->id)->first();
        $cases = Enrollment::query()->with(['term', 'currentProposalVersion.confirmation', 'termAccount', 'admissionApplication', 'studentProfile'])
            ->where('credential_user_id', $user->id)->latest('id')->get();
        $application = null;
        $term = null;
        $reason = null;
        if ($profile instanceof StudentProfile) {
            $terms = Term::query()->whereHas('calendarPackages', fn ($query) => $query->where('state', TermCalendarPackage::StateActive)
                ->whereHas('windows', fn ($window) => $window->where('window_type', TermCalendarWindow::TypeEnrollment)))
                ->orderBy('starts_on')->get()->filter(function (Term $candidate) use ($profile): bool {
                    try {
                        $this->calendar->assertEnrollmentWindowOpen($candidate->id);
                        $basis = $this->selectionBasis->forContinuingStudent($profile, $candidate);
                        app(RegistrationWindowApplicability::class)->assertPermitted($candidate, $basis, false);

                        return true;
                    } catch (CalendarGateViolation|ValidationException) {
                        return false;
                    }
                });
            if ($terms->count() === 1) {
                $term = $terms->first();
                try {
                    $this->selectionBasis->forContinuingStudent($profile, $term);
                } catch (ValidationException $exception) {
                    $reason = $exception->getMessage();
                }
            } else {
                $reason = $terms->isEmpty()
                    ? 'Enrollment is not open for you right now. Ask the Registrar about the next enrollment date.'
                    : 'The Registrar needs to confirm which term you can enroll in before you can start.';
            }
        } else {
            $applications = AdmissionApplication::query()->where('user_id', $user->id)->where('application_state', AdmissionApplication::StateAdmitted)->latest('id')->get();
            $ready = $applications->filter(fn (AdmissionApplication $candidate): bool => $this->readyApplicants->forApplication($candidate)['ready']);
            if ($ready->count() === 1) {
                $application = $ready->first();
                $term = $application->term()->first();
                try {
                    $this->calendar->assertEnrollmentWindowOpen($term->id);
                    app(RegistrationWindowApplicability::class)->assertPermitted($term, $this->selectionBasis->forApplicant($application), true);
                } catch (CalendarGateViolation|ValidationException $exception) {
                    $reason = $exception->getMessage();
                }
            } else {
                $reason = $ready->isEmpty()
                    ? 'The Registrar needs to finish checking your admission requirements before you can enroll.'
                    : 'The Registrar needs to confirm which application you will use for enrollment.';
            }
        }
        $activeCases = $cases->filter(fn (Enrollment $candidate): bool => $candidate->term?->state === Term::StateActive);
        $unfinished = $activeCases->where('canonical_outcome', Enrollment::OutcomeInProgress);
        $case = $term instanceof Term ? $cases->firstWhere('term_id', $term->id)
            : ($unfinished->count() === 1 ? $unfinished->first() : ($activeCases->count() === 1 ? $activeCases->first() : ($activeCases->isEmpty() ? $cases->first() : null)));
        if (! $term instanceof Term && $activeCases->count() > 1 && $unfinished->count() !== 1) {
            $reason = 'The Registrar needs to confirm your current enrollment term. Your earlier enrollment records remain available.';
        }
        if ($case instanceof Enrollment && ! $term instanceof Term) {
            $term = $case->term;
            $application ??= $case->admissionApplication;
        }

        return [
            'term' => $term, 'enrollment' => $case, 'application' => $application, 'student_profile' => $profile,
            'can_start' => $user->canAuthenticate() && $term instanceof Term && ! $case instanceof Enrollment && $reason === null,
            'reason' => $reason,
        ];
    }
}
