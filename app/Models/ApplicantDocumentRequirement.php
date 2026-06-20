<?php

namespace App\Models;

use Database\Factories\ApplicantDocumentRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApplicantDocumentRequirement extends Model
{
    /** @use HasFactory<ApplicantDocumentRequirementFactory> */
    use HasFactory;

    public const EvidenceStatePending = 'pending';

    public const EvidenceStateSubmitted = 'submitted';

    public const EvidenceStateSatisfied = 'satisfied';

    public const EvidenceStateNeedsCorrection = 'needs_correction';

    public const EvidenceStateRejected = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'applicant_intake_id',
        'admission_offering_id',
        'admission_requirement_policy_id',
        'document_requirement_item_id',
        'item_key',
        'label',
        'gate_type',
        'permitted_evidence_methods',
        'storage_class',
        'sensitivity_class',
        'ocr_policy',
        'deadline_strategy',
        'evidence_state',
        'satisfied_by_document_upload_id',
        'satisfied_method',
        'satisfied_by',
        'satisfied_at',
        'due_at',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'evidence_state' => self::EvidenceStatePending,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permitted_evidence_methods' => 'array',
            'satisfied_at' => 'datetime',
            'due_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function applicantIntake(): BelongsTo
    {
        return $this->belongsTo(ApplicantIntake::class);
    }

    public function admissionOffering(): BelongsTo
    {
        return $this->belongsTo(AdmissionOffering::class);
    }

    public function admissionRequirementPolicy(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementPolicy::class);
    }

    public function documentRequirementItem(): BelongsTo
    {
        return $this->belongsTo(DocumentRequirementItem::class);
    }

    public function satisfiedByDocumentUpload(): BelongsTo
    {
        return $this->belongsTo(DocumentUpload::class, 'satisfied_by_document_upload_id');
    }

    public function satisfiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'satisfied_by');
    }

    public function retentionDocumentUndertaking(): HasOne
    {
        return $this->hasOne(RetentionDocumentUndertaking::class);
    }
}
