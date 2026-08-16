<?php

namespace App\Models;

use Database\Factories\AdmissionDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/** @property Carbon $decided_at */
class AdmissionDecision extends Model
{
    /** @use HasFactory<AdmissionDecisionFactory> */
    use HasFactory;

    public const DecisionAdmitted = 'Admitted';

    public const DecisionNotAdmitted = 'NotAdmitted';

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'decision',
        'reason',
        'authority_reference',
        'applicant_explanation',
        'decided_by',
        'decided_at',
        'supersedes_admission_decision_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<AdmissionDecision, $this> */
    public function supersededDecision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_admission_decision_id');
    }

    /** @return HasOne<AdmissionDecision, $this> */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_admission_decision_id');
    }

    public function supersedes(self $decision): bool
    {
        return (int) $this->supersedes_admission_decision_id === (int) $decision->id;
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Admission decisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Admission decisions cannot be deleted.'));
    }
}
