<?php

namespace App\Models;

use Database\Factories\ExternalCompetencyRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCompetencyRequirement extends Model
{
    /** @use HasFactory<ExternalCompetencyRequirementFactory> */
    use HasFactory;

    protected $fillable = [
        'curriculum_version_id', 'curriculum_entry_id', 'requirement_code',
        'qualification_label', 'qualification_level', 'treatment',
        'authority_reference', 'authority_date', 'state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['authority_date' => 'date', 'recorded_at' => 'datetime'];
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
