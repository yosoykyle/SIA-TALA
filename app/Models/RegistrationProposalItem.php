<?php

namespace App\Models;

use Database\Factories\RegistrationProposalItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property array<int, array<string, mixed>> $meeting_snapshot
 */
class RegistrationProposalItem extends Model
{
    /** @use HasFactory<RegistrationProposalItemFactory> */
    use HasFactory;

    protected $fillable = [
        'registration_proposal_version_id', 'sequence', 'term_offering_id', 'section_id',
        'units_snapshot', 'course_code_snapshot', 'course_title_snapshot', 'meeting_snapshot',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'units_snapshot' => 'decimal:2', 'meeting_snapshot' => 'array'];
    }

    /** @return BelongsTo<RegistrationProposalVersion, $this> */
    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(RegistrationProposalVersion::class, 'registration_proposal_version_id');
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return HasOne<EnrollmentSeatReservation, $this> */
    public function reservation(): HasOne
    {
        return $this->hasOne(EnrollmentSeatReservation::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Registration Proposal items are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Registration Proposal items cannot be deleted.'));
    }
}
