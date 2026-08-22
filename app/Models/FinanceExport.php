<?php

namespace App\Models;

use Database\Factories\FinanceExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $generated_at */
class FinanceExport extends Model
{
    /** @use HasFactory<FinanceExportFactory> */
    use HasFactory;

    public const TypeAccountStatus = 'AccountStatus';

    public const TypeVerifiedPayments = 'VerifiedPayments';

    public const OutcomePreparing = 'Preparing';

    public const OutcomeGenerated = 'Generated';

    public const OutcomeNoRows = 'NoRows';

    public const OutcomeFailed = 'Failed';

    protected $fillable = [
        'reference', 'type', 'term_account_id', 'initiated_by', 'purpose', 'normalized_scope',
        'row_count', 'outcome', 'disk', 'path', 'checksum', 'generated_at',
    ];

    protected function casts(): array
    {
        return ['normalized_scope' => 'array', 'row_count' => 'integer', 'generated_at' => 'datetime'];
    }

    /** @return BelongsTo<TermAccount, $this> */
    public function termAccount(): BelongsTo
    {
        return $this->belongsTo(TermAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function downloadFilename(): string
    {
        $prefix = $this->type === self::TypeAccountStatus
            ? 'tala-account-status'
            : 'tala-verified-payments';
        $generatedAt = $this->generated_at?->setTimezone(config('app.display_timezone')) ?? now(config('app.display_timezone'));

        return $prefix.'-'.$generatedAt->format('Ymd-His').'-PHT.csv';
    }
}
