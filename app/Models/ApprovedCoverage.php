<?php

namespace App\Models;

use Database\Factories\ApprovedCoverageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $authority_date
 * @property Carbon|null $effective_date
 * @property Carbon|null $approved_at
 * @property Carbon|null $reversed_at
 */
class ApprovedCoverage extends Model
{
    /** @use HasFactory<ApprovedCoverageFactory> */
    use HasFactory;

    public const StateApplied = 'Applied';

    public const StateSuperseded = 'Superseded';

    public const StateReversed = 'Reversed';

    public const CategoryScholarship = 'Scholarship';

    public const CategorySponsorship = 'Sponsorship';

    public const CategoryGovernmentSubsidy = 'GovernmentSubsidy';

    public const CategoryOtherAuthorizedFunding = 'OtherAuthorizedFunding';

    public const Categories = [
        self::CategoryScholarship,
        self::CategorySponsorship,
        self::CategoryGovernmentSubsidy,
        self::CategoryOtherAuthorizedFunding,
    ];

    protected $fillable = [
        'term_account_id', 'assessment_obligation_id', 'supersedes_coverage_id', 'state',
        'category', 'safe_source_description', 'amount', 'authority_reference', 'authority_date',
        'effective_date', 'approved_by', 'approved_at', 'reversed_by', 'reversed_at',
        'reversal_reason', 'reversal_authority_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'authority_date' => 'date', 'effective_date' => 'date',
            'approved_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TermAccount, $this> */
    public function termAccount(): BelongsTo
    {
        return $this->belongsTo(TermAccount::class);
    }

    /** @return BelongsTo<AssessmentObligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(AssessmentObligation::class, 'assessment_obligation_id');
    }

    /** @return BelongsTo<ApprovedCoverage, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_coverage_id');
    }
}
