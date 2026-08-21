<?php

namespace App\Models;

use Database\Factories\ApprovedCoverageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovedCoverage extends Model
{
    /** @use HasFactory<ApprovedCoverageFactory> */
    use HasFactory;

    protected $fillable = [
        'term_account_id', 'assessment_obligation_id', 'amount', 'authority_reference',
        'approved_by', 'approved_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
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
}
