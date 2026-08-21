<?php

namespace App\Models;

use Database\Factories\RegistrationProposalConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon|null $confirmed_at
 */
class RegistrationProposalConfirmation extends Model
{
    /** @use HasFactory<RegistrationProposalConfirmationFactory> */
    use HasFactory;

    public const MethodSelfService = 'SelfService';

    public const MethodRegistrarAssisted = 'RegistrarAssisted';

    protected $fillable = [
        'registration_proposal_version_id', 'method', 'learner_user_id', 'assisted_by',
        'assisted_evidence_reference', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<RegistrationProposalVersion, $this> */
    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(RegistrationProposalVersion::class, 'registration_proposal_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assisted_by');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Registration Proposal confirmations are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Registration Proposal confirmations cannot be deleted.'));
    }
}
