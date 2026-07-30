<?php

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'assessment_line_id',
        'payment_schedule_row_id',
        'prior_balance_ledger_entry_id',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<AssessmentLine, $this> */
    public function assessmentLine(): BelongsTo
    {
        return $this->belongsTo(AssessmentLine::class);
    }

    /** @return BelongsTo<PaymentScheduleRow, $this> */
    public function paymentScheduleRow(): BelongsTo
    {
        return $this->belongsTo(PaymentScheduleRow::class);
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function priorBalanceLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'prior_balance_ledger_entry_id');
    }

    /** @return HasOne<LedgerEntry, $this> */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    public function targetLabel(): string
    {
        $this->loadMissing(['assessmentLine', 'paymentScheduleRow', 'priorBalanceLedgerEntry']);

        return match (true) {
            $this->assessmentLine instanceof AssessmentLine => (string) $this->assessmentLine->description_snapshot,
            $this->paymentScheduleRow instanceof PaymentScheduleRow => str((string) $this->paymentScheduleRow->category)
                ->replace('_', ' ')
                ->headline()
                ->toString(),
            $this->priorBalanceLedgerEntry instanceof LedgerEntry => (string) $this->priorBalanceLedgerEntry->description,
            default => 'Account balance',
        };
    }
}
