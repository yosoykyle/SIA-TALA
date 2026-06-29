<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public static function manualConfirmationChannelOptions(): array
    {
        return [
            'cash' => 'Cash',
            'gcash_manual' => 'GCash Manual',
            'bank_transfer' => 'Bank Transfer',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_attempt_id',
        'student_profile_id',
        'term_id',
        'method',
        'channel',
        'amount',
        'currency',
        'evidence_status',
        'paid_at',
        'verified_at',
        'verified_by',
        'or_number',
        'or_mapped_by',
        'or_mapped_at',
        'provider_reference',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'or_mapped_at' => 'datetime',
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

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->oldestOfMany();
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function orMapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'or_mapped_by');
    }
}
