<?php

namespace App\Models;

use App\Enums\RetentionCategory;
use Database\Factories\DisposalReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TAL-92E: audited manual disposal-review decision.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7.4/§13.7.5
 * and §13.8 "Retention/disposal review" row. Direction A (confirmed
 * 2026-07-08): this is a ledger record only — it never physically deletes
 * or purges the referenced `StudentProfile` or any other record.
 */
class DisposalReview extends Model
{
    /** @use HasFactory<DisposalReviewFactory> */
    use HasFactory;

    public const DecisionClearedForDisposal = 'CLEARED_FOR_DISPOSAL';

    public const DecisionRetained = 'RETAINED';

    public const DecisionBlockedByHold = 'BLOCKED_BY_HOLD';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'retention_category',
        'hold_check_result',
        'legal_audit_attestation',
        'decision',
        'reason',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_category' => RetentionCategory::class,
            'hold_check_result' => 'boolean',
            'legal_audit_attestation' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function decisionOptions(): array
    {
        return [
            self::DecisionClearedForDisposal => 'Cleared for Disposal',
            self::DecisionRetained => 'Retained',
            self::DecisionBlockedByHold => 'Blocked by Hold',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
