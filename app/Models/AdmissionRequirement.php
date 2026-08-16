<?php

namespace App\Models;

use Database\Factories\AdmissionRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdmissionRequirement extends Model
{
    /** @use HasFactory<AdmissionRequirementFactory> */
    use HasFactory;

    public const SubmissionInPerson = 'InPerson';

    public const SubmissionSchoolToSchool = 'SchoolToSchool';

    public const SubmissionNone = 'None';

    public const DuePreliminaryReview = 'PreliminaryReview';

    public const DueEnrollmentReadiness = 'EnrollmentReadiness';

    public const DuePostEnrollmentFollowUp = 'PostEnrollmentFollowUp';

    public const ClassificationCoreFirstYearCompletionCredential = 'CoreFirstYearCompletionCredential';

    public const ClassificationCoreTransferCredential = 'CoreTransferCredential';

    public const ClassificationCoreOtherOfficialCredential = 'CoreOtherOfficialCredential';

    public const ClassificationNonCore = 'NonCore';

    /** @var list<string> */
    protected $fillable = [
        'admission_requirement_set_id',
        'code',
        'label',
        'authority_reference',
        'purpose',
        'credential_classification',
        'requires_preliminary_evidence',
        'official_submission_method',
        'due_stage',
        'applicant_instructions',
        'registrar_instructions',
        'exception_permitted',
        'required_approving_authority',
        'display_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_preliminary_evidence' => 'boolean',
            'exception_permitted' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AdmissionRequirementSet, $this> */
    public function requirementSet(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirementSet::class, 'admission_requirement_set_id');
    }

    /** @return list<string> */
    public static function credentialClassifications(): array
    {
        return [
            self::ClassificationCoreFirstYearCompletionCredential,
            self::ClassificationCoreTransferCredential,
            self::ClassificationCoreOtherOfficialCredential,
            self::ClassificationNonCore,
        ];
    }

    public static function isCoreClassification(?string $classification): bool
    {
        return in_array($classification, [
            self::ClassificationCoreFirstYearCompletionCredential,
            self::ClassificationCoreTransferCredential,
            self::ClassificationCoreOtherOfficialCredential,
        ], true);
    }

    protected static function booted(): void
    {
        static::saving(function (AdmissionRequirement $requirement): void {
            $requirementSet = AdmissionRequirementSet::query()->find(
                $requirement->admission_requirement_set_id,
            );

            if ($requirementSet?->state === AdmissionRequirementSet::StatePublished) {
                throw new LogicException('Requirements in a published set are immutable.');
            }
        });

        static::deleting(function (AdmissionRequirement $requirement): void {
            if ($requirement->requirementSet()->where('state', AdmissionRequirementSet::StatePublished)->exists()) {
                throw new LogicException('Requirements in a published set cannot be deleted.');
            }
        });
    }
}
