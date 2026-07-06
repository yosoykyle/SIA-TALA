<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentGateResult extends Model
{
    public const GateIdentity = 'identity';

    public const GateAdmissionOrStudentStatus = 'admission_or_student_status';

    public const GateDocument = 'document';

    public const GateFinance = 'finance';

    public const GateAcademicProgression = 'academic_progression';

    public const GateCapacity = 'capacity';

    public const GatePlacement = 'placement';

    public const GateConflict = 'conflict';

    public const GateFinalApproval = 'final_approval';

    public const ResultNotChecked = 'not_checked';

    public const ResultPassed = 'passed';

    public const ResultFailed = 'failed';

    public const ResultPendingReview = 'pending_review';

    public const ResultWaived = 'waived';

    public const ResultOverridden = 'overridden';

    public const ResultNotApplicable = 'not_applicable';

    public const ResponsibleOfficeRegistrar = 'registrar';

    public const ResponsibleOfficeAccounting = 'accounting';

    public const ResponsibleOfficeAcademicHead = 'academic_head';

    public const RuleVersionTal67Mvp = 'tal-67-mvp';

    public const RuleVersionTal87C = 'tal-87c-gate-evaluator';

    public const RuleVersionTal87D = 'tal-87d-official-enrollment';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'gate_type',
        'sequence',
        'result',
        'responsible_office',
        'blocker_code',
        'blocker_message',
        'source_type',
        'source_id',
        'checked_at',
        'rule_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
