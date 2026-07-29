<?php

namespace App\Models;

use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const DirectionCharge = 'charge';

    public const DirectionPenalty = 'penalty';

    public const DirectionPayment = 'payment';

    public const DirectionDiscount = 'discount';

    public const DirectionScholarship = 'scholarship';

    public const DirectionWaiver = 'waiver';

    public const DirectionRefund = 'refund';

    public const DirectionAdjustment = 'adjustment';

    public const DirectionReversal = 'reversal';

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'direction',
        'category',
        'amount',
        'source_type',
        'source_id',
        'payment_id',
        'payment_allocation_id',
        'reverses_entry_id',
        'adjusts_entry_id',
        'description',
        'posted_by',
        'posted_at',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function adjustedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_entry_id');
    }

    public function effectLabel(): string
    {
        return match ($this->direction) {
            self::DirectionCharge, self::DirectionPenalty, self::DirectionRefund => 'Increases balance',
            self::DirectionPayment, self::DirectionDiscount, self::DirectionScholarship, self::DirectionWaiver => 'Reduces balance',
            self::DirectionAdjustment => (float) $this->amount >= 0 ? 'Increases balance' : 'Reduces balance',
            self::DirectionReversal => 'Reverses a prior entry',
            default => str((string) $this->direction)->replace('_', ' ')->headline()->toString(),
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            AssessmentLine::class => 'Assessment charge',
            Payment::class => 'Verified payment',
            AccountingAdjustment::class => $this->direction === self::DirectionReversal
                ? 'Accounting reversal'
                : 'Accounting adjustment',
            Enrollment::class => 'Enrollment',
            default => filled($this->source_type)
                ? str(class_basename((string) $this->source_type))->headline()->toString()
                : 'System posting',
        };
    }

    public function displayLabel(): string
    {
        return collect([
            $this->sourceLabel(),
            $this->effectLabel(),
            $this->description,
            'PHP '.number_format((float) abs((float) $this->amount), 2),
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
