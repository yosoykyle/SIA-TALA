<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $previous_deadline
 * @property Carbon $new_deadline
 * @property Carbon $authority_date
 * @property Carbon $recorded_at
 */
class IncDeadlineAmendment extends Model
{
    protected $fillable = [
        'grade_outcome_event_id', 'previous_deadline', 'new_deadline', 'authority_reference',
        'authority_date', 'reason', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_deadline' => 'date', 'new_deadline' => 'date',
            'authority_date' => 'date', 'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeOutcomeEvent, $this> */
    public function incompleteEvent(): BelongsTo
    {
        return $this->belongsTo(GradeOutcomeEvent::class, 'grade_outcome_event_id');
    }
}
