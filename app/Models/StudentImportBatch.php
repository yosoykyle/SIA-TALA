<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $program_id
 * @property int $curriculum_version_id
 * @property int|null $entry_academic_year_id
 * @property int|null $completed_through_year_level
 * @property string|null $completed_through_term_type
 * @property int|null $imported_by
 * @property int $student_count
 * @property string|null $filename
 * @property Carbon|null $reverted_at
 * @property int|null $reverted_by
 */
class StudentImportBatch extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'program_id',
        'curriculum_version_id',
        'entry_academic_year_id',
        'completed_through_year_level',
        'completed_through_term_type',
        'imported_by',
        'student_count',
        'filename',
        'reverted_at',
        'reverted_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'completed_through_year_level' => 'integer',
            'student_count' => 'integer',
            'reverted_at' => 'datetime',
        ];
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

    /** @return BelongsTo<AcademicYear, $this> */
    public function entryAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'entry_academic_year_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reverter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    /** @return HasMany<StudentProfile, $this> */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'import_batch_id');
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }
}
