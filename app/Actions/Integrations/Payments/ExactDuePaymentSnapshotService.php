<?php

namespace App\Actions\Integrations\Payments;

use App\Actions\Finance\TermAccountProjection;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptObligation;
use App\Models\TermAccount;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use JsonException;

final class ExactDuePaymentSnapshotService
{
    public function __construct(
        private readonly TermAccountProjection $projection,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @return array{term_account_id:int,assessment_id:int,assessment_version:int,amount:string,checksum:string,created_at:CarbonImmutable,obligations:list<array{id:int,sequence:int,code:string,label:string,amount:string}>}
     *
     * @throws PaymentAttemptSnapshotException
     */
    public function forAccount(TermAccount $account, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $assessment = Assessment::query()
            ->where('term_account_id', $account->id)
            ->where('state', Assessment::StateActive)
            ->latest('version')
            ->first();

        if (! $assessment instanceof Assessment
            || ! filled($assessment->content_hash)
            || $assessment->currency !== 'PHP') {
            throw new PaymentAttemptSnapshotException('current_assessment_unavailable');
        }

        $projection = $this->projection->forAccount($account, $asOf);

        if (($projection['assessment_id'] ?? null) !== $assessment->id) {
            throw new PaymentAttemptSnapshotException('assessment_projection_mismatch');
        }

        $obligations = collect($projection['obligations'])
            ->filter(fn (array $row): bool => ($row['is_due'] ?? false) === true
                && $this->money->greaterThanZero((string) ($row['balance'] ?? '0.00')))
            ->values()
            ->map(fn (array $row, int $index): array => [
                'id' => (int) $row['id'],
                'sequence' => $index + 1,
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
                'amount' => $this->money->normalize((string) $row['balance']),
            ])
            ->all();
        $amount = $this->money->fromCents((int) collect($obligations)
            ->sum(fn (array $row): int => $this->money->toCents($row['amount'])));

        if ($obligations === []
            || ! $this->money->greaterThanZero($amount)
            || $amount !== $this->money->normalize($projection['current_due'])) {
            throw new PaymentAttemptSnapshotException('positive_current_due_unavailable');
        }

        return [
            'term_account_id' => (int) $account->id,
            'assessment_id' => (int) $assessment->id,
            'assessment_version' => (int) $assessment->version,
            'amount' => $amount,
            'checksum' => $this->checksum(
                accountId: (int) $account->id,
                assessment: $assessment,
                amount: $amount,
                obligations: $obligations,
            ),
            'created_at' => $asOf,
            'obligations' => $obligations,
        ];
    }

    /**
     * @throws PaymentAttemptSnapshotException
     */
    public function assertCurrent(PaymentAttempt $attempt): void
    {
        if ($attempt->term_account_id === null
            || $attempt->assessment_version === null
            || ! is_string($attempt->snapshot_checksum)
            || preg_match('/\A[a-f0-9]{64}\z/', $attempt->snapshot_checksum) !== 1) {
            throw new PaymentAttemptSnapshotException('snapshot_authority_missing');
        }

        $account = TermAccount::query()->lockForUpdate()->find($attempt->term_account_id);

        if (! $account instanceof TermAccount || $account->state !== TermAccount::StateOpen) {
            throw new PaymentAttemptSnapshotException('term_account_unavailable');
        }

        $current = $this->forAccount($account);
        $attempt->loadMissing('obligations');
        $stored = $attempt->obligations
            ->map(fn (PaymentAttemptObligation $target): array => [
                'id' => (int) $target->assessment_obligation_id,
                'sequence' => (int) $target->sequence,
                'amount' => $this->money->normalize((string) $target->amount),
            ])
            ->values()
            ->all();
        $expected = collect($current['obligations'])
            ->map(fn (array $target): array => [
                'id' => $target['id'],
                'sequence' => $target['sequence'],
                'amount' => $target['amount'],
            ])
            ->all();

        if ($current['assessment_id'] !== (int) $attempt->assessment_id
            || $current['assessment_version'] !== (int) $attempt->assessment_version
            || $current['amount'] !== $this->money->normalize((string) $attempt->amount)
            || ! hash_equals($current['checksum'], $attempt->snapshot_checksum)
            || $stored !== $expected) {
            throw new PaymentAttemptSnapshotException('snapshot_stale');
        }
    }

    /** @return list<array{target_type:string,target_id:int,description:string,amount:string}> */
    public function allocationTargets(PaymentAttempt $attempt): array
    {
        $attempt->loadMissing('obligations.assessmentObligation');

        return $attempt->obligations
            ->map(fn (PaymentAttemptObligation $target): array => [
                'target_type' => AssessmentObligation::class,
                'target_id' => (int) $target->assessment_obligation_id,
                'description' => 'PayMongo payment for '.(string) $target->assessmentObligation?->label,
                'amount' => $this->money->normalize((string) $target->amount),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,sequence:int,code:string,label:string,amount:string}>  $obligations
     *
     * @throws JsonException
     */
    private function checksum(int $accountId, Assessment $assessment, string $amount, array $obligations): string
    {
        return hash('sha256', json_encode([
            'term_account_id' => $accountId,
            'assessment_id' => (int) $assessment->id,
            'assessment_version' => (int) $assessment->version,
            'assessment_content_hash' => (string) $assessment->content_hash,
            'currency' => 'PHP',
            'amount' => $amount,
            'obligations' => collect($obligations)
                ->map(fn (array $row): array => [
                    'id' => $row['id'],
                    'sequence' => $row['sequence'],
                    'amount' => $row['amount'],
                ])
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
