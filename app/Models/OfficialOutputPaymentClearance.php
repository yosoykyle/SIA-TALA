<?php

namespace App\Models;

use Database\Factories\OfficialOutputPaymentClearanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialOutputPaymentClearance extends Model
{
    /** @use HasFactory<OfficialOutputPaymentClearanceFactory> */
    use HasFactory;

    public const StateCleared = 'Cleared';

    public const StateNotCleared = 'NotCleared';

    public const StateWithdrawn = 'Withdrawn';

    public const StateNotRequired = 'NotRequired';

    public const StateActionNeeded = 'ActionNeeded';

    protected $fillable = [
        'term_account_id', 'transcript_request_id', 'output_request_reference', 'version', 'supersedes_clearance_id',
        'state', 'required_amount', 'authority_reference', 'safe_reason', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'required_amount' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<TermAccount, $this> */
    public function termAccount(): BelongsTo
    {
        return $this->belongsTo(TermAccount::class);
    }

    /** @return BelongsTo<OfficialOutputPaymentClearance, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_clearance_id');
    }

    /** @return BelongsTo<TranscriptRequest, $this> */
    public function transcriptRequest(): BelongsTo
    {
        return $this->belongsTo(TranscriptRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
