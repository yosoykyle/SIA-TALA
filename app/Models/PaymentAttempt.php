<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    public const StatusPending = 'Pending';

    public const StatusCancelled = 'Cancelled';

    public const StatusExpired = 'Expired';

    public const StatusFailed = 'Failed';

    public const StatusConfirmed = 'Confirmed';

    public const StatusReviewRequired = 'ReviewRequired';

    public const ActiveStatuses = [self::StatusPending, self::StatusReviewRequired];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assessment_id',
        'term_account_id',
        'student_profile_id',
        'assessment_version',
        'snapshot_created_at',
        'snapshot_checksum',
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
            'assessment_version' => 'integer',
            'snapshot_created_at' => 'datetime',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return Attribute<string, string> */
    protected function status(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => match ($value) {
            'pending', self::StatusPending => self::StatusPending,
            'under_review', self::StatusReviewRequired => self::StatusReviewRequired,
            'paid', 'confirmed', self::StatusConfirmed => self::StatusConfirmed,
            'cancelled', 'canceled', self::StatusCancelled => self::StatusCancelled,
            'expired', self::StatusExpired => self::StatusExpired,
            'failed', self::StatusFailed => self::StatusFailed,
            default => $value,
        });
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return BelongsTo<TermAccount, $this> */
    public function termAccount(): BelongsTo
    {
        return $this->belongsTo(TermAccount::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<PaymentAttemptObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(PaymentAttemptObligation::class)->orderBy('sequence')->orderBy('id');
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
