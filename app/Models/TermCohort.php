<?php

namespace App\Models;

use Database\Factories\TermCohortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** @property Pivot $pivot */
class TermCohort extends Model
{
    /** @use HasFactory<TermCohortFactory> */
    use HasFactory;

    protected $fillable = [
        'term_id', 'program_id', 'curriculum_version_id', 'reference', 'source',
        'forecast_count', 'confirmed_count', 'state', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'forecast_count' => 'integer',
            'confirmed_count' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return BelongsToMany<Section, $this> */
    public function classOfferings(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)->withPivot('expected_count')->withTimestamps();
    }
}
