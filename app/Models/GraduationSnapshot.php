<?php

namespace App\Models;

use Database\Factories\GraduationSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $evaluation_snapshot
 */
class GraduationSnapshot extends Model
{
    /** @use HasFactory<GraduationSnapshotFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'graduation_review_member_id',
        'version',
        'result_status',
        'evaluation_snapshot',
        'generated_by',
        'generated_at',
        'made_visible_by',
        'made_visible_at',
        'visibility_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evaluation_snapshot' => 'array',
            'generated_at' => 'datetime',
            'made_visible_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GraduationReviewMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(GraduationReviewMember::class, 'graduation_review_member_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function madeVisibleBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_visible_by');
    }
}
