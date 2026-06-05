<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'ledger_entry_id',
        'channel',
        'status',
        'provider',
        'provider_event_id',
        'provider_checkout_session_id',
        'provider_payment_id',
        'provider_payment_intent_id',
        'amount',
        'meta',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function displayLabel(): string
    {
        return collect([
            "#{$this->id}",
            $this->provider,
            $this->channel,
            $this->status,
            'Amount: '.number_format((float) $this->amount, 2),
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
