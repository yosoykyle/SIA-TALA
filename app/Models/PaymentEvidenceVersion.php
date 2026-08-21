<?php

namespace App\Models;

use Database\Factories\PaymentEvidenceVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvidenceVersion extends Model
{
    /** @use HasFactory<PaymentEvidenceVersionFactory> */
    use HasFactory;

    public const StateSubmitted = 'Submitted';

    public const StateVerified = 'Verified';

    public const StateRejected = 'Rejected';

    protected $fillable = [
        'term_account_id', 'supersedes_version_id', 'version', 'state', 'disk', 'path',
        'original_name', 'mime_type', 'size_bytes', 'checksum', 'claimed_amount',
        'payment_reference', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size_bytes' => 'integer', 'claimed_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<TermAccount, $this> */
    public function termAccount(): BelongsTo
    {
        return $this->belongsTo(TermAccount::class);
    }

    /** @return BelongsTo<PaymentEvidenceVersion, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }
}
