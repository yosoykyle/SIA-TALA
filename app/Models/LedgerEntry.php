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

    public function displayLabel(): string
    {
        return collect([
            "#{$this->id}",
            $this->direction,
            $this->category,
            $this->description,
            'Amount: '.number_format((float) $this->amount, 2),
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
