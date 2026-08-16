<?php

namespace App\Models;

use Database\Factories\ApplicationSubmissionVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/** @property Carbon $submitted_at */
class ApplicationSubmissionVersion extends Model
{
    /** @use HasFactory<ApplicationSubmissionVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'admission_requirement_set_id',
        'version',
        'snapshot',
        'privacy_notice_reference',
        'submitted_by',
        'submitted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<AdmissionRequirementSet, $this> */
    public function requirementSet(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementSet::class, 'admission_requirement_set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<DocumentEvidence, $this> */
    public function evidenceVersions(): HasMany
    {
        return $this->hasMany(DocumentEvidence::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Submitted application versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Submitted application versions cannot be deleted.'));
    }
}
