<?php

namespace App\Models;

use Database\Factories\AdmissionCapacityReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionCapacityReservation extends Model
{
    /** @use HasFactory<AdmissionCapacityReservationFactory> */
    use HasFactory;

    public const StatusSecured = 'secured';

    public const StatusReleased = 'released';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admission_capacity_plan_id',
        'enrollment_id',
        'student_profile_id',
        'payment_id',
        'ledger_entry_id',
        'status',
        'secured_at',
        'scope_snapshot',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::StatusSecured,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secured_at' => 'datetime',
            'scope_snapshot' => 'array',
            'meta' => 'array',
        ];
    }

    public function admissionCapacityPlan(): BelongsTo
    {
        return $this->belongsTo(AdmissionCapacityPlan::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
