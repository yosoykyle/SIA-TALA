<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncCompletionSubmission extends Model
{
    public const StateSubmitted = 'SUBMITTED';

    public const StateReturned = 'RETURNED';

    public const StateReleased = 'RELEASED';

    protected $fillable = [
        'grade_outcome_event_id', 'proposed_result', 'completion_note', 'state',
        'submitted_by', 'submitted_at', 'released_event_id', 'reviewed_by',
        'reviewed_at', 'return_reason',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<GradeOutcomeEvent, $this> */
    public function incompleteEvent(): BelongsTo
    {
        return $this->belongsTo(GradeOutcomeEvent::class, 'grade_outcome_event_id');
    }
}
