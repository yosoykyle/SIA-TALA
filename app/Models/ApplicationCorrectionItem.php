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

    /** @return array<string, string> */
    public static function fieldOptions(): array
    {
        return [
            'program_id' => 'Program choice', 'application_path' => 'Student type',
            'first_name' => 'First name', 'middle_name' => 'Middle name',
            'last_name' => 'Last name', 'extension_name' => 'Name suffix',
            'birth_date' => 'Date of birth', 'citizenship_country_code' => 'Citizenship',
            'phone' => 'Mobile number', 'current_city_municipality' => 'City or municipality',
            'current_province' => 'Province', 'guardian_full_name' => 'Parent, guardian or emergency contact name',
            'guardian_relationship' => 'Contact relationship', 'guardian_mobile' => 'Contact mobile number',
            'prior_school_name' => 'Previous school attended', 'gender' => 'Sex (optional)',
            'civil_status' => 'Civil status (optional)', 'lrn_availability' => 'LRN availability',
            'current_barangay' => 'Barangay', 'current_street_address' => 'Street address',
            'current_postal_code' => 'Postal code', 'prior_school_address' => 'Previous school address',
            'prior_school_country_code' => 'Previous school country', 'credential_basis' => 'Admission qualification',
            'prior_school_completion_year' => 'Graduation year', 'lrn' => 'Learner Reference Number (LRN)',
            'prior_college_identifier' => 'Previous college identifier',
        ];
    }

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
