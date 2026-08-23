<?php

namespace App\Models;

use Database\Factories\GraduationApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraduationApplication extends Model
{
    /** @use HasFactory<GraduationApplicationFactory> */
    use HasFactory;

    public const StateActive = 'Active';

    public const StateWithdrawn = 'Withdrawn';

    public const StateCorrected = 'Corrected';

    protected $fillable = [
        'student_profile_id', 'curriculum_version_id', 'term_id', 'version',
        'supersedes_application_id', 'state', 'active_scope_key', 'source_fingerprint',
        'applied_by', 'applied_at', 'withdrawn_by', 'withdrawn_at', 'withdrawal_reason',
        'correction_authority_reference', 'correction_reason',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'applied_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<CurriculumVersion, $this> */
    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<GraduationApplication, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_application_id');
    }
}
