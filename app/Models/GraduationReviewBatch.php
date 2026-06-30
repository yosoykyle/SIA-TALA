<?php

namespace App\Models;

use Database\Factories\GraduationReviewBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraduationReviewBatch extends Model
{
    /** @use HasFactory<GraduationReviewBatchFactory> */
    use HasFactory;

    public const StateOpen = 'open';

    public const StateClosed = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'term_id',
        'name',
        'state',
        'created_by',
        'filter_summary',
        'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filter_summary' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<GraduationReviewMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GraduationReviewMember::class);
    }
}
