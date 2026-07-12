<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccommodation extends Model
{
    public const BasisDswDLguCertification = 'DSWD_LGU_CERTIFICATION';

    public const BasisInstitutionalAccommodation = 'INSTITUTIONAL_ACCOMMODATION';

    public const StatusPending = 'PENDING';

    public const StatusActive = 'ACTIVE';

    public const StatusFulfilled = 'FULFILLED';

    public const StatusDefaulted = 'DEFAULTED';

    public const StatusExpired = 'EXPIRED';

    public const StatusCancelled = 'CANCELLED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'balance_snapshot',
        'covered_amount',
        'basis',
        'certification_reference',
        'private_evidence_reference',
        'promissory_required',
        'promissory_maker',
        'allows_finance_gate',
        'allows_next_term_enrollment',
        'allows_reactivation',
        'allows_record_release',
        'waives_downpayment',
        'authority',
        'recorded_by',
        'status',
        'effective_from',
        'expires_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_snapshot' => 'decimal:2',
            'covered_amount' => 'decimal:2',
            'promissory_required' => 'boolean',
            'allows_finance_gate' => 'boolean',
            'allows_next_term_enrollment' => 'boolean',
            'allows_reactivation' => 'boolean',
            'allows_record_release' => 'boolean',
            'waives_downpayment' => 'boolean',
            'effective_from' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return HasMany<PaymentScheduleRow, $this> */
    public function paymentScheduleRows(): HasMany
    {
        return $this->hasMany(PaymentScheduleRow::class);
    }

    /**
     * @return array<string, string>
     */
    public static function basisOptions(): array
    {
        return [
            self::BasisDswDLguCertification => 'DSWD/LGU Certification',
            self::BasisInstitutionalAccommodation => 'Institutional Accommodation',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusPending => 'Pending',
            self::StatusActive => 'Active',
            self::StatusFulfilled => 'Fulfilled',
            self::StatusDefaulted => 'Defaulted',
            self::StatusExpired => 'Expired',
            self::StatusCancelled => 'Cancelled',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function creationStatusOptions(): array
    {
        return array_intersect_key(self::statusOptions(), array_flip([
            self::StatusPending,
            self::StatusActive,
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function transitionStatusOptions(): array
    {
        $targets = match ($this->status) {
            self::StatusPending => [self::StatusActive, self::StatusCancelled],
            self::StatusActive => [
                self::StatusFulfilled,
                self::StatusDefaulted,
                self::StatusExpired,
                self::StatusCancelled,
            ],
            default => [],
        };

        return array_intersect_key(self::statusOptions(), array_flip($targets));
    }

    public static function studentOptionLabel(StudentProfile $studentProfile): string
    {
        $name = $studentProfile->user instanceof User && filled($studentProfile->user->name)
            ? $studentProfile->user->name
            : collect([$studentProfile->first_name, $studentProfile->middle_name, $studentProfile->last_name])
                ->filter()
                ->implode(' ');

        return collect([$studentProfile->student_number, $name])
            ->filter()
            ->implode(' — ');
    }
}
