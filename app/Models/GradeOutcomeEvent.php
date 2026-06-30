<?php

namespace App\Models;

use Database\Factories\GradeOutcomeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeOutcomeEvent extends Model
{
    /** @use HasFactory<GradeOutcomeEventFactory> */
    use HasFactory;

    public $timestamps = false;

    public const TypeInitialRelease = 'INITIAL_RELEASE';

    public const TypePendingReplacement = 'PENDING_REPLACEMENT';

    public const TypeIncResolution = 'INC_RESOLUTION';

    public const TypePostedCorrection = 'POSTED_CORRECTION';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade_roster_row_id',
        'event_type',
        'previous_value',
        'new_value',
        'previous_category',
        'new_category',
        'deadline',
        'authority',
        'reason',
        'evidence_reference',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_value' => 'decimal:4',
            'new_value' => 'decimal:4',
            'deadline' => 'date',
        ];
    }

    /** @return BelongsTo<GradeRosterRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(GradeRosterRow::class, 'grade_roster_row_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
