<?php

namespace App\Models;

use Database\Factories\DegreeConferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $conferred_on */
class DegreeConferral extends Model
{
    /** @use HasFactory<DegreeConferralFactory> */
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'graduation_application_id', 'completion_readiness_version_id',
        'curriculum_version_id', 'version', 'supersedes_conferral_id', 'active_scope_key',
        'program_name_snapshot', 'degree_name', 'conferred_on', 'authority_reference',
        'honor_text', 'honor_authority_reference', 'source_fingerprint',
        'final_evaluation_snapshot', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'conferred_on' => 'date', 'final_evaluation_snapshot' => 'array', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<GraduationApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(GraduationApplication::class, 'graduation_application_id');
    }

    /** @return BelongsTo<CompletionReadinessVersion, $this> */
    public function readinessVersion(): BelongsTo
    {
        return $this->belongsTo(CompletionReadinessVersion::class, 'completion_readiness_version_id');
    }
}
