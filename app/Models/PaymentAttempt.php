<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'assessment_id',
        'student_profile_id',
        'channel',
        'provider',
        'internal_reference',
        'provider_checkout_id',
        'provider_intent_id',
        'amount',
        'currency',
        'status',
        'expires_at',
        'paid_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
