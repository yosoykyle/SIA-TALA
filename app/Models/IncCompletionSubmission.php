<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $controlling_deadline */
class IncCompletionSubmission extends Model
{
    public const StateSubmitted = 'SUBMITTED';

    public const StateReturned = 'RETURNED';

    public const StateReleased = 'RELEASED';

    protected $fillable = [
        'grade_outcome_event_id', 'proposed_result', 'completion_note', 'state',
        'controlling_result_event_id', 'controlling_deadline_amendment_id', 'controlling_deadline',
        'submitted_by', 'submitted_at', 'released_event_id', 'reviewed_by',
        'reviewed_at', 'return_reason',
    ];

    protected function casts(): array
    {
        return [
            'controlling_deadline' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeOutcomeEvent, $this> */
    public function incompleteEvent(): BelongsTo
    {
        return $this->belongsTo(GradeOutcomeEvent::class, 'grade_outcome_event_id');
    }

    /** @return BelongsTo<GradeOutcomeEvent, $this> */
    public function controllingResultEvent(): BelongsTo
    {
        return $this->belongsTo(GradeOutcomeEvent::class, 'controlling_result_event_id');
    }

    /** @return BelongsTo<IncDeadlineAmendment, $this> */
    public function controllingDeadlineAmendment(): BelongsTo
    {
        return $this->belongsTo(IncDeadlineAmendment::class, 'controlling_deadline_amendment_id');
    }
}
