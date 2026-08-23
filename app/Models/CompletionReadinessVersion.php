<?php

namespace App\Models;

use Database\Factories\CompletionReadinessVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletionReadinessVersion extends Model
{
    /** @use HasFactory<CompletionReadinessVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'graduation_application_id', 'version', 'supersedes_readiness_id',
        'state', 'source_fingerprint', 'source_snapshot', 'blockers', 'generated_by', 'generated_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'source_snapshot' => 'array', 'blockers' => 'array', 'generated_at' => 'datetime'];
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
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_readiness_id');
    }
}
