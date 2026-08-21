<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordAuthorizedIndividualAssessment
{
    public function __construct(
        private readonly CreateAssessmentFromPublishedFeePlan $creator,
        private readonly RegistrationPlacementValidator $placementValidator,
    ) {}

    /**
     * @param  list<array{code:string,label:string,amount:string,required_for_enrollment?:bool}>  $charges
     */
    public function execute(Enrollment $enrollment, User $actor, string $authorityReference, array $charges): Assessment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record an authorized individual assessment.');
        }
        if (blank($authorityReference) || $charges === []) {
            throw ValidationException::withMessages(['assessment' => 'Exact authority and fixed charges are required.']);
        }

        $normalized = collect($charges)->map(function (array $charge): array {
            if (blank($charge['code']) || blank($charge['label']) || (float) $charge['amount'] < 0) {
                throw ValidationException::withMessages(['charges' => 'Each authorized line requires a code, label, and non-negative fixed amount.']);
            }

            return ['code' => $charge['code'], 'label' => $charge['label'], 'amount' => number_format((float) $charge['amount'], 2, '.', ''), 'required_for_enrollment' => $charge['required_for_enrollment'] ?? true];
        })->values()->all();

        return DB::transaction(function () use ($enrollment, $actor, $authorityReference, $normalized): Assessment {
            $locked = Enrollment::query()
                ->with(['term', 'currentProposalVersion.items.reservation'])
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled
                && $locked->selection_basis !== Enrollment::SelectionIndividuallyAdvised
                && $locked->term?->type !== Term::TypeSummer) {
                throw ValidationException::withMessages([
                    'assessment' => 'An individual assessment requires an exact Individually Advised or Summer/Special Term exception.',
                ]);
            }

            if ($locked->currentProposalVersion === null) {
                throw ValidationException::withMessages(['assessment' => 'A current confirmed and placed proposal is required.']);
            }

            if ($locked->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
                $this->placementValidator->assertCurrent($locked, $locked->currentProposalVersion, lockForUpdate: true);
            }

            return $this->creator->create(
                $locked,
                $actor,
                'AuthorizedIndividualAssessment',
                $authorityReference,
                null,
                $normalized,
                $normalized,
            );
        }, attempts: 3);
    }
}
