<?php

namespace App\Models;

use Database\Factories\EnrollmentExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentException extends Model
{
    /** @use HasFactory<EnrollmentExceptionFactory> */
    use HasFactory;

    public const TypeGateOverride = 'GATE_OVERRIDE';

    public const TypePrerequisite = 'PREREQUISITE';

    public const TypeCorequisite = 'COREQUISITE';

    public const TypeUnitLoad = 'UNIT_LOAD';

    public const TypeConflict = 'CONFLICT';

    public const TypeBridging = 'BRIDGING';

    public const StateActive = 'ACTIVE';

    public const StateExpired = 'EXPIRED';

    public const StateRevoked = 'REVOKED';

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'student_profile_id', 'term_id', 'exception_type',
        'enrollment_gate_result_id', 'target_term_offering_id', 'original_failed_result',
        'original_rule', 'scope_key', 'expires_at', 'requested_values', 'approved_values',
        'reason', 'evidence_reference', 'requested_by', 'approved_by', 'approved_at', 'state',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'requested_values' => 'array',
            'approved_values' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function gateResult(): BelongsTo
    {
        return $this->belongsTo(EnrollmentGateResult::class, 'enrollment_gate_result_id');
    }

    public function targetTermOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class, 'target_term_offering_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
