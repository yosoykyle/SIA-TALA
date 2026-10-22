<?php

namespace App\Actions\Finance;

use App\Models\PaymentEvidenceVersion;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

class SubmitPaymentEvidence
{
    public function __construct(private readonly TermAccountProjection $projection, private readonly DecimalMoney $money) {}

    /** @param list<int>|null $targetObligationIds */
    public function execute(TermAccount $account, User $actor, UploadedFile $file, string $claimedAmount, string $channel, mixed $paidAt, ?string $paymentReference = null, ?array $targetObligationIds = null): PaymentEvidenceVersion
    {
        $account->loadMissing('enrollment');
        if (! $actor->canAuthenticate() || (int) $account->credential_user_id !== (int) $actor->id) {
            throw new AuthorizationException('Only the owning learner may submit payment evidence.');
        }

        Validator::make(
            ['file' => $file, 'claimed_amount' => $claimedAmount, 'channel' => $channel, 'paid_at' => $paidAt],
            [
                'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb')],
                'claimed_amount' => ['required', 'numeric', 'gt:0'],
                'channel' => ['required', 'in:cash,gcash_manual,bank_transfer'],
                'paid_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            ],
        )->validate();

        $path = $file->store("registration-payment-evidence/{$account->id}", 'local');

        try {
            return DB::transaction(function () use ($account, $actor, $file, $path, $claimedAmount, $channel, $paidAt, $paymentReference, $targetObligationIds): PaymentEvidenceVersion {
                $locked = TermAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $position = $this->projection->forAccount($locked);
                $eligible = collect($position['obligations'])->filter(fn (array $row): bool => $row['is_due'] && $this->money->greaterThanZero($row['available_balance']));
                $ids = $targetObligationIds === null ? $eligible->pluck('id') : collect($targetObligationIds)->map(fn ($id): int => (int) $id)->unique();
                $targets = $eligible->whereIn('id', $ids)->values();
                if ($position['state'] === 'Unavailable' || $targets->isEmpty() || $targets->count() !== $ids->count()
                    || $this->money->toCents($claimedAmount) > $targets->sum(fn (array $row): int => $this->money->toCents($row['available_balance']))) {
                    throw ValidationException::withMessages(['claimed_amount' => 'Refresh the current assessment and choose its unpaid due obligations. Excess or unavailable amounts require Accounting review.']);
                }
                $previous = PaymentEvidenceVersion::query()->where('term_account_id', $locked->id)->latest('version')->lockForUpdate()->first();
                $version = ((int) $previous?->version) + 1;
                $checksum = hash_file('sha256', $file->getRealPath());
                $possibleDuplicate = PaymentEvidenceVersion::query()
                    ->where('term_account_id', $locked->id)
                    ->where(function ($query) use ($checksum, $channel, $paymentReference): void {
                        $query->where('checksum', $checksum);
                        if (filled($paymentReference)) {
                            $query->orWhere(fn ($referenceQuery) => $referenceQuery
                                ->where('channel', $channel)
                                ->where('payment_reference', $paymentReference));
                        }
                    })->exists();

                return PaymentEvidenceVersion::query()->create([
                    'term_account_id' => $locked->id,
                    'assessment_id' => $position['assessment_id'],
                    'obligation_snapshot' => $targets->map(fn (array $row): array => ['id' => $row['id'], 'code' => $row['code'], 'label' => $row['label'], 'available_balance' => $row['available_balance']])->all(),
                    'supersedes_version_id' => $previous?->id,
                    'version' => $version,
                    'state' => PaymentEvidenceVersion::StateSubmitted,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'checksum' => $checksum,
                    'claimed_amount' => $this->money->normalize($claimedAmount),
                    'channel' => $channel,
                    'paid_at' => $paidAt,
                    'payment_reference' => $paymentReference,
                    'possible_duplicate' => $possibleDuplicate,
                    'submitted_by' => $actor->id,
                    'submitted_at' => now(),
                ]);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }
}
