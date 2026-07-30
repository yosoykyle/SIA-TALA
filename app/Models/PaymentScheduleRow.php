<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentScheduleRow extends Model
{
    public const CategoryDownpayment = 'downpayment';

    public const StateDue = 'due';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assessment_id',
        'financial_accommodation_id',
        'sequence',
        'category',
        'due_date',
        'amount',
        'state',
        'linked_payment_allocation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<FinancialAccommodation, $this> */
    public function financialAccommodation(): BelongsTo
    {
        return $this->belongsTo(FinancialAccommodation::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
