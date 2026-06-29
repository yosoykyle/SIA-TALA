<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentGateResult extends Model
{
    public const GatePlacement = 'placement';

    public const GateCapacity = 'capacity';

    public const GateConflict = 'conflict';

    public const ResultPassed = 'passed';

    public const ResultFailed = 'failed';

    public const ResultPendingReview = 'pending_review';

    public const ResponsibleOfficeRegistrar = 'registrar';

    public const RuleVersionTal67Mvp = 'tal-67-mvp';

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
