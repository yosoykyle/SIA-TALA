<?php

namespace App\Models;

use Database\Factories\GraduationReviewMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GraduationReviewMember extends Model
{
    /** @use HasFactory<GraduationReviewMemberFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'graduation_review_batch_id',
        'student_profile_id',
        'added_by',
        'added_at',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<GraduationReviewBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(GraduationReviewBatch::class, 'graduation_review_batch_id');
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** @return HasMany<GraduationSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(GraduationSnapshot::class);
    }

    /** @return HasOne<GraduationSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(GraduationSnapshot::class)->latestOfMany('version');
    }
}
