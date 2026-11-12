<?php

namespace App\Models;

use Database\Factories\ExternalCompetencyRequirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExternalCompetencyRequirement extends Model
{
    /** @use HasFactory<ExternalCompetencyRequirementFactory> */
    use HasFactory;

    public const TreatmentCompletionRequired = 'CompletionRequired';

    public const TreatmentTrackedOnly = 'TrackedOnly';

    protected $fillable = [
        'curriculum_version_id', 'curriculum_entry_id', 'requirement_code',
        'qualification_label', 'qualification_level', 'treatment',
        'authority_reference', 'authority_date', 'state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['authority_date' => 'date', 'recorded_at' => 'datetime'];
    }

    public function scopeApplicableTo(Builder $query, StudentProfile $student): Builder
    {
        return $query->where('state', 'ACTIVE')
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->whereHas('curriculumVersion', fn (Builder $versions): Builder => $versions->where('state', CurriculumVersion::StateActive));
    }

    public function scopeWithCurrentResultFor(Builder $query, StudentProfile $student): Builder
    {
        return $query->with(['currentResult' => fn (HasOne $result): HasOne => $result
            ->where('student_profile_id', $student->id)
            ->select(['id', 'external_competency_requirement_id', 'outcome', 'assessment_date'])]);
    }

    /** @return HasOne<ExternalCompetencyResult, $this> */
    public function currentResult(): HasOne
    {
        return $this->hasOne(ExternalCompetencyResult::class)->where('is_current', true);
    }

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return BelongsTo<CurriculumEntry, $this> */
    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }
}
