<?php

namespace App\Models;

use Database\Factories\CurriculumReadinessScopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumReadinessScope extends Model
{
    /** @use HasFactory<CurriculumReadinessScopeFactory> */
    use HasFactory;

    public const StatusNeedsReview = 'needs_review';

    public const StatusReadyForScheduling = 'ready_for_scheduling';

    public const StatusBlocked = 'blocked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'curriculum_id',
        'year_level',
        'curriculum_period',
        'status',
        'last_transition_by',
        'last_transition_at',
        'last_blockers',
        'last_blocker_hash',
        'last_transition_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_transition_at' => 'datetime',
            'last_blockers' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusNeedsReview => 'Needs Review',
            self::StatusReadyForScheduling => 'Ready for Scheduling',
            self::StatusBlocked => 'Blocked',
        ];
    }

    public function isReadyForScheduling(): bool
    {
        return $this->status === self::StatusReadyForScheduling;
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function lastTransitionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_transition_by');
    }
}
