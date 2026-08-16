<?php

namespace App\Models;

use Database\Factories\ApplicationCorrectionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApplicationCorrectionItem extends Model
{
    /** @use HasFactory<ApplicationCorrectionItemFactory> */
    use HasFactory;

    public const ScopeField = 'Field';

    public const ScopeEvidence = 'Evidence';

    /** @var list<string> */
    protected $fillable = [
        'application_correction_request_id',
        'scope_type',
        'scope_key',
        'admission_requirement_id',
    ];

    /** @return BelongsTo<ApplicationCorrectionRequest, $this> */
    public function correctionRequest(): BelongsTo
    {
        return $this->belongsTo(ApplicationCorrectionRequest::class, 'application_correction_request_id');
    }

    /** @return BelongsTo<AdmissionRequirement, $this> */
    public function admissionRequirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirement::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Correction-request scope is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Correction-request scope cannot be deleted.'));
    }
}
