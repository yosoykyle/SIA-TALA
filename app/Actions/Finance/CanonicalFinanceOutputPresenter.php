<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;

class CanonicalFinanceOutputPresenter
{
    public function __construct(private readonly TermAccountProjection $projection) {}

    /** @return array<string, mixed> */
    public function statement(Assessment $assessment, User $actor): array
    {
        $assessment->loadMissing(['termAccount.credentialUser', 'enrollment.studentProfile.program', 'termAccount.term']);
        $isAccounting = $actor->hasRole(User::StaffRoleAccounting);
        abort_unless($assessment->termAccount !== null && ($isAccounting
            || ((int) $assessment->termAccount->credential_user_id === (int) $actor->id
                && $assessment->state === Assessment::StateActive)), 403);
        $position = $this->projection->forAccount($assessment->termAccount);

        return [
            'assessment' => $assessment,
            'account' => $assessment->termAccount,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'owner' => $assessment->enrollment?->studentProfile?->user?->getFilamentName() ?? $assessment->termAccount->credentialUser?->getFilamentName() ?? 'Learner account',
            'program' => $assessment->enrollment?->studentProfile?->program->code ?? 'Not assigned',
            'term' => $assessment->termAccount->term->label,
            'position' => $position,
            'authority_reference' => $assessment->authority_reference,
            'authority_date' => $assessment->authority_date?->toDateString(),
            'is_historical' => $assessment->state !== Assessment::StateActive,
            'disclaimer' => 'This authenticated statement reflects TALA account records and is not a tax receipt.',
        ];
    }

    /** @return array<string, mixed> */
    public function acknowledgement(Payment $payment, User $actor): array
    {
        $payment->loadMissing(['termAccount.credentialUser', 'termAccount.term', 'allocations.assessmentObligation']);
        abort_unless($payment->state === Payment::StatePosted && $payment->termAccount !== null
            && ($actor->hasRole(User::StaffRoleAccounting) || (int) $payment->termAccount->credential_user_id === (int) $actor->id), 403);

        return [
            'payment' => $payment,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'owner' => $payment->termAccount->credentialUser->getFilamentName(),
            'term' => $payment->termAccount->term->label,
            'allocations' => $payment->allocations->map(fn ($allocation): array => [
                'target' => $allocation->assessmentObligation->label,
                'amount' => (string) $allocation->amount,
            ])->all(),
            'disclaimer' => 'This acknowledges a verified posting in TALA. It is not an official receipt or tax document.',
        ];
    }
}
