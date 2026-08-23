<?php

namespace App\Models;

use Database\Factories\TranscriptRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranscriptRequest extends Model
{
    /** @use HasFactory<TranscriptRequestFactory> */
    use HasFactory;

    public const StateOpen = 'Open';

    public const StateIssued = 'Issued';

    public const TemplateServitechV1 = 'TALA Standard TOR — Servitech v1';

    public const SealImage = 'Image';

    public const SealPlacementInstruction = 'PlacementInstruction';

    protected $fillable = [
        'student_profile_id', 'degree_conferral_id', 'version', 'supersedes_request_id',
        'external_request_reference', 'requested_on', 'due_on', 'template_version',
        'signatory_name', 'signatory_title', 'seal_input_type', 'seal_path', 'seal_checksum',
        'seal_placement_instruction', 'source_fingerprint', 'state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'requested_on' => 'date', 'due_on' => 'date', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<DegreeConferral, $this> */
    public function conferral(): BelongsTo
    {
        return $this->belongsTo(DegreeConferral::class, 'degree_conferral_id');
    }

    /** @return HasMany<OfficialOutputPaymentClearance, $this> */
    public function clearances(): HasMany
    {
        return $this->hasMany(OfficialOutputPaymentClearance::class);
    }

    /** @return HasMany<TranscriptSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(TranscriptSnapshot::class);
    }

    /** @return HasMany<TranscriptIssuanceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TranscriptIssuanceEvent::class);
    }

    public function currentClearance(): ?OfficialOutputPaymentClearance
    {
        $clearance = $this->clearances()->latest('version')->first();

        return $clearance instanceof OfficialOutputPaymentClearance ? $clearance : null;
    }

    public function clearanceState(): string
    {
        $state = $this->currentClearance()?->state;

        return in_array($state, [OfficialOutputPaymentClearance::StateCleared, OfficialOutputPaymentClearance::StateNotRequired], true)
            ? $state
            : OfficialOutputPaymentClearance::StateActionNeeded;
    }
}
