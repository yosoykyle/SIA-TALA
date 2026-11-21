<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordCounterCashPayment
{
    public function __construct(
        private readonly TermAccountProjection $projection,
        private readonly DecimalMoney $money,
        private readonly ReviewPaymentEvidence $reviewPaymentEvidence,
    ) {}

    /** @param list<int>|null $targetObligationIds */
    public function execute(TermAccount $account, User $actor, string $amount, string $orNumber, mixed $paidAt, ?array $targetObligationIds = null): Payment
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may record a counter cash payment.');
        }

        Validator::make(
            ['amount' => $amount, 'or_number' => $orNumber, 'paid_at' => $paidAt],
            [
                'amount' => ['required', 'numeric', 'gt:0'],
                'or_number' => ['required', 'string', 'max:255'],
                'paid_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            ],
        )->validate();

        $evidence = DB::transaction(function () use ($account, $actor, $amount, $orNumber, $paidAt, $targetObligationIds): PaymentEvidenceVersion {
            $locked = TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $position = $this->projection->forAccount($locked);
            $eligible = collect($position['obligations'])->filter(fn (array $row): bool => $row['is_due'] && $this->money->greaterThanZero($row['available_balance']));
            $ids = $targetObligationIds === null ? $eligible->pluck('id') : collect($targetObligationIds)->map(fn ($id): int => (int) $id)->unique();
            $targets = $eligible->whereIn('id', $ids)->values();
            if ($position['state'] === 'Unavailable' || $targets->isEmpty() || $targets->count() !== $ids->count()
                || $this->money->toCents($amount) > $targets->sum(fn (array $row): int => $this->money->toCents($row['available_balance']))) {
                throw ValidationException::withMessages(['amount' => 'Refresh the current assessment and choose its unpaid due obligations.']);
            }
            $previous = PaymentEvidenceVersion::query()->where('term_account_id', $locked->id)->latest('version')->lockForUpdate()->first();
            $version = ((int) $previous?->version) + 1;

            return PaymentEvidenceVersion::query()->create([
                'term_account_id' => $locked->id,
                'assessment_id' => $position['assessment_id'],
                'obligation_snapshot' => $targets->map(fn (array $row): array => ['id' => $row['id'], 'code' => $row['code'], 'label' => $row['label'], 'available_balance' => $row['available_balance']])->all(),
                'supersedes_version_id' => $previous?->id,
                'version' => $version,
                'state' => PaymentEvidenceVersion::StateSubmitted,
                'disk' => null,
                'path' => null,
                'original_name' => null,
                'mime_type' => null,
                'size_bytes' => null,
                'checksum' => hash('sha256', "counter-cash:{$locked->id}:{$version}:{$actor->id}:".now()->valueOf()),
                'claimed_amount' => $this->money->normalize($amount),
                'channel' => 'cash',
                'paid_at' => $paidAt,
                'payment_reference' => trim($orNumber),
                'possible_duplicate' => false,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'source' => PaymentEvidenceVersion::SourceAccountingCounter,
            ]);
        }, attempts: 3);

        return $this->reviewPaymentEvidence->verify($evidence, $actor, $amount, trim($orNumber));
    }
}
