<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttemptObligation extends Model
{
    protected $fillable = [
        'payment_attempt_id',
        'assessment_obligation_id',
        'sequence',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PaymentAttempt, $this> */
    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    /** @return BelongsTo<AssessmentObligation, $this> */
    public function assessmentObligation(): BelongsTo
    {
        return $this->belongsTo(AssessmentObligation::class);
    }
}
