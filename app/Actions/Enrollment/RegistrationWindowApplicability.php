<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationWindowApplicability
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return ['AllEligible' => 'All eligible learners', 'ReadyApplicants' => 'Ready applicants', 'StandardContinuing' => 'Continuing students: standard subjects', 'AdvisedContinuing' => 'Continuing students: advised subjects'];
    }

    public function permits(Term $term, string $selectionBasis, bool $isApplicant): bool
    {
        return match ($term->registration_applicability) {
            'AllEligible' => true,
            'ReadyApplicants' => $isApplicant,
            'StandardContinuing' => ! $isApplicant && $selectionBasis === Enrollment::SelectionStandardCurriculum,
            'AdvisedContinuing' => ! $isApplicant && $selectionBasis === Enrollment::SelectionIndividuallyAdvised,
            default => false,
        };
    }

    public function assertPermitted(Term $term, string $selectionBasis, bool $isApplicant): void
    {
        if (! $this->permits($term, $selectionBasis, $isApplicant)) {
            throw ValidationException::withMessages(['term' => 'The published enrollment window does not currently apply to this learner. Registrar can confirm the permitted enrollment date.']);
        }
    }

    public function record(Term $term, User $actor, string $applicability, ?string $authorityReference = null): Term
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar may record enrollment window applicability.');
        }
        if (! array_key_exists($applicability, self::options())) {
            throw ValidationException::withMessages(['applicability' => 'Choose which learners may enroll during this period.']);
        }

        return DB::transaction(function () use ($term, $actor, $applicability, $authorityReference): Term {
            $locked = Term::query()->lockForUpdate()->findOrFail($term->id);
            if ($locked->registration_applicability === $applicability && blank($authorityReference)) {
                return $locked;
            }
            $before = $locked->only(['registration_applicability', 'registration_applicability_authority']);
            $reference = trim((string) $authorityReference);
            $locked->update(['registration_applicability' => $applicability, 'registration_applicability_authority' => $reference !== '' ? $reference : null]);
            activity()->performedOn($locked)->causedBy($actor)->withProperties(['before' => $before, 'after' => $locked->only(array_keys($before))])->event('registration_applicability_recorded')->log('Enrollment window applicability recorded');

            return $locked;
        }, attempts: 3);
    }
}
