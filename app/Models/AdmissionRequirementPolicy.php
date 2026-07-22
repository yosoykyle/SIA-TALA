<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionRequirementPolicy extends Model
{
    /** @use HasFactory<AdmissionRequirementPolicyFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StateDraft => 'Draft',
            self::StateActive => 'Active',
            self::StateSuperseded => 'Superseded',
        ];
    }

    /**
     * Admission categories, keyed by the exact ApplicantIntake constants the
     * resolver matches on, so this configuration surface cannot drift.
     *
     * @return array<string, string>
     */
    public static function admissionCategoryOptions(): array
    {
        return [
            ApplicantIntake::AdmissionCategoryFirstTimeCollege => 'First-Time College',
            ApplicantIntake::AdmissionCategoryTransfer => 'Transfer',
            ApplicantIntake::AdmissionCategoryReturning => 'Returning',
        ];
    }

    /**
     * Credential bases, keyed by the exact ApplicantIntake constants the
     * resolver matches on.
     *
     * @return array<string, string>
     */
    public static function credentialBasisOptions(): array
    {
        return [
            ApplicantIntake::CredentialBasisSeniorHighSchool => 'Senior High School',
            ApplicantIntake::CredentialBasisTransferCredentials => 'Transfer Credentials',
            ApplicantIntake::CredentialBasisPriorStudentRecord => 'Prior Student Record',
        ];
    }

    /**
     * Curated document requirement types. IDENTITY_DOCUMENT is required by the
     * intake service to attach digital identity evidence.
     *
     * @return array<string, string>
     */
    public static function requirementTypeOptions(): array
    {
        return [
            'IDENTITY_DOCUMENT' => 'Identity Document',
            'BIRTH_CERTIFICATE' => 'PSA Birth Certificate',
            'FORM_137' => 'Form 137',
            'TRANSCRIPT_OF_RECORDS' => 'Transcript of Records',
            'GOOD_MORAL' => 'Good Moral Certificate',
            'PRIOR_STUDENT_RECORD' => 'Prior Student Record',
        ];
    }

    /**
     * Evidence methods, keyed by the ChecklistItem constants copied onto each
     * generated checklist item at submission time.
     *
     * @return array<string, string>
     */
    public static function evidenceMethodOptions(): array
    {
        return [
            ChecklistItem::EvidenceMethodPhysicalCopy => 'Physical Copy',
            ChecklistItem::EvidenceMethodDigitalUpload => 'Digital Upload',
            ChecklistItem::EvidenceMethodMetadataOnly => 'Metadata Only',
        ];
    }

    /**
     * Blocking levels, keyed by the ChecklistItem constants that gate the
     * downstream handover and enrollment workflows.
     *
     * @return array<string, string>
     */
    public static function blockingLevelOptions(): array
    {
        return [
            ChecklistItem::BlockingHandover => 'Blocks Handover',
            ChecklistItem::BlockingEnrollment => 'Blocks Enrollment',
            ChecklistItem::BlockingCorPrint => 'Blocks COR Print',
            ChecklistItem::BlockingRecordRelease => 'Blocks Record Release',
            ChecklistItem::BlockingRetentionOnly => 'Retention Only',
            ChecklistItem::BlockingAdvisoryOnly => 'Advisory Only',
        ];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admission_category',
        'credential_basis',
        'requirement_type',
        'evidence_method',
        'blocking_level',
        'effective_from',
        'effective_until',
        'state',
        'authority',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => self::StateDraft,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'source_policy_id');
    }

    public function displayLabel(): string
    {
        return collect([
            $this->admission_category,
            $this->credential_basis,
            $this->requirement_type,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
