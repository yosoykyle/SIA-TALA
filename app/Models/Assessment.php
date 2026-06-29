<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    public const StateDraft = 'DRAFT';

    public const StatePendingReview = 'PENDING_REVIEW';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    public const StateCancelled = 'CANCELLED';

    public const StateLocked = 'LOCKED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'version',
        'state',
        'currency',
        'subtotal',
        'discount_total',
        'total',
        'required_downpayment',
        'activated_by',
        'activated_at',
        'superseded_by_assessment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'required_downpayment' => 'decimal:2',
            'activated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return HasMany<AssessmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AssessmentLine::class);
    }

    /** @return HasMany<PaymentScheduleRow, $this> */
    public function paymentScheduleRows(): HasMany
    {
        return $this->hasMany(PaymentScheduleRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
