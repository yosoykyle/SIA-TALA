<?php

namespace App\Models;

use Database\Factories\GradeSubmissionPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeSubmissionPackage extends Model
{
    /** @use HasFactory<GradeSubmissionPackageFactory> */
    use HasFactory;

    public const StateSubmitted = 'submitted';

    public const StateReturned = 'returned';

    public const StateVerifiedFinalized = 'verified_finalized';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'state',
        'roster_snapshot_checksum',
        'grading_profile_snapshot',
        'submitted_by',
        'submitted_at',
        'registrar_reviewed_by',
        'registrar_reviewed_at',
        'return_reason',
        'finalized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grading_profile_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'registrar_reviewed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function registrarReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrar_reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GradeSubmissionPackageItem::class);
    }
}
