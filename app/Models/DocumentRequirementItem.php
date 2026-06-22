<?php

namespace App\Models;

use Database\Factories\DocumentRequirementItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequirementItem extends Model
{
    /** @use HasFactory<DocumentRequirementItemFactory> */
    use HasFactory;

    public const GateTypeAdmission = 'admission';

    public const GateTypeRetention = 'retention';

    public const StorageClassCredentialFile = 'credential_file';

    public const SensitivityStandard = 'standard';

    public const EvidenceMethodApplicantUpload = 'applicant_upload';

    public const EvidenceMethodRegistrarAssistedUpload = 'registrar_assisted_upload';

    public const EvidenceMethodPhysicalOriginal = 'physical_original';

    public const EvidenceMethodCertifiedCopy = 'certified_copy';

    public const EvidenceMethodSchoolTransmission = 'school_transmission';

    public const StorageClassStructuredRecord = 'structured_record';

    public const StorageClassPhysicalCustody = 'physical_custody';

    public const StorageClassGeneratedArtifact = 'generated_artifact';

    public const SensitivityRestricted = 'restricted';

    /**
     * @return array<string, string>
     */
    public static function gateTypeOptions(): array
    {
        return [
            self::GateTypeAdmission => 'Admission gate',
            self::GateTypeRetention => 'Retention follow-up',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function evidenceMethodOptions(): array
    {
        return [
            self::EvidenceMethodApplicantUpload => 'Applicant upload',
            self::EvidenceMethodRegistrarAssistedUpload => 'Registrar-assisted upload',
            self::EvidenceMethodPhysicalOriginal => 'Physical original',
            self::EvidenceMethodCertifiedCopy => 'Certified copy',
            self::EvidenceMethodSchoolTransmission => 'School-to-school transmission',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function storageClassOptions(): array
    {
        return [
            self::StorageClassCredentialFile => 'Credential file',
            self::StorageClassStructuredRecord => 'Structured record',
            self::StorageClassPhysicalCustody => 'Physical custody',
            self::StorageClassGeneratedArtifact => 'Generated artifact',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sensitivityClassOptions(): array
    {
        return [
            self::SensitivityStandard => 'Standard',
            self::SensitivityRestricted => 'Restricted',
        ];
    }

    /**
     * @return array<string, string>
     */
    /**
     * @var list<string>
     */
    protected $fillable = [
        'admission_requirement_policy_id',
        'key',
        'label',
        'gate_type',
        'sort_order',
        'permitted_evidence_methods',
        'storage_class',
        'sensitivity_class',
        'verified_field_mapping',
        'deadline_strategy',
        'retention_policy',
        'meta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'gate_type' => self::GateTypeAdmission,
        'sort_order' => 0,
        'storage_class' => self::StorageClassCredentialFile,
        'sensitivity_class' => self::SensitivityStandard,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'permitted_evidence_methods' => 'array',
            'verified_field_mapping' => 'array',
            'meta' => 'array',
        ];
    }

    public function admissionRequirementPolicy(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementPolicy::class);
    }

    public function displayLabel(): string
    {
        return "{$this->label} ({$this->key})";
    }
}
