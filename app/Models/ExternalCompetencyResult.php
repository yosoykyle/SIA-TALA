<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCompetencyResult extends Model
{
    public const OutcomeNotYetCompetent = 'NOT_YET_COMPETENT';

    public const OutcomeCompetent = 'COMPETENT';

    protected $fillable = [
        'external_competency_requirement_id', 'student_profile_id', 'outcome',
        'evidence_reference', 'authority_reference', 'authority_date', 'recorded_by',
        'recorded_at', 'supersedes_result_id', 'is_current',
    ];

    protected function casts(): array
    {
        return ['authority_date' => 'date', 'recorded_at' => 'datetime', 'is_current' => 'boolean'];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ExternalCompetencyRequirement::class, 'external_competency_requirement_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
