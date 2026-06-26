<?php

namespace App\Models;

use Database\Factories\ApplicantIntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ApplicantIntake extends Model
{
    /** @use HasFactory<ApplicantIntakeFactory> */
    use HasFactory;

    public const StatusPending = 'pending';

    public const StatusActionRequired = 'action_required';

    public const StatusForEvaluation = 'for_evaluation';

    public const StatusApproved = 'approved';

    public const DuplicateStatusClear = 'clear';

    public const DuplicateStatusBlocked = 'blocked';

    public const ApplicantTypeNew = 'new';

    public const ApplicantTypeTransferee = 'transferee';

    public const ApplicantTypeReturnee = 'returnee';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'term_id',
        'program_id',
        'lrn',
        'birthdate',
        'place_of_birth',
        'gender',
        'civil_status',
        'mothers_maiden_name',
        'contact_number',
        'street',
        'barangay',
        'city',
        'province',
        'region',
        'zip_code',
        'father_name',
        'father_occupation',
        'mother_occupation',
        'guardian_name',
        'guardian_contact_number',
        'guardian_address',
        'year_level',
        'applicant_type',
        'preferred_modality',
        'last_school_name',
        'last_school_address',
        'last_school_year',
        'orientation_modality_acknowledged_at',
        'orientation_policy_accepted_at',
        'status',
        'duplicate_check_status',
        'duplicate_check_payload',
        'required_documents',
        'identity_document_url',
        'registrar_reviewed_by',
        'registrar_reviewed_at',
        'submitted_at',
        'approved_at',
        'action_required_at',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::StatusPending,
        'duplicate_check_status' => self::DuplicateStatusClear,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'orientation_modality_acknowledged_at' => 'datetime',
            'orientation_policy_accepted_at' => 'datetime',
            'duplicate_check_payload' => 'array',
            'required_documents' => 'array',
            'registrar_reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'action_required_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function registrarReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrar_reviewed_by');
    }

    public function documentUploads(): HasMany
    {
        return $this->hasMany(DocumentUpload::class);
    }

    public function checklistItems(): MorphMany
    {
        return $this->morphMany(ChecklistItem::class, 'owner');
    }

    /**
     * @return list<string>
     */
    public function requiredDocumentTypes(): array
    {
        if ($this->exists && $this->checklistItems()->exists()) {
            return $this->checklistItems()
                ->orderBy('id')
                ->pluck('requirement_type')
                ->all();
        }

        $documents = $this->required_documents ?? [];

        return array_values(array_filter($documents, 'is_string'));
    }

    /**
     * @return list<string>
     */
    public function admissionGateDocumentTypes(): array
    {
        if ($this->exists && $this->checklistItems()->exists()) {
            return $this->checklistItems()
                ->where('blocking_level', 'blocks_handover') // Assuming blocks_handover corresponds to admission gate
                ->orderBy('id')
                ->pluck('requirement_type')
                ->all();
        }

        return $this->requiredDocumentTypes();
    }

    /**
     * @return list<string>
     */
    public function missingApprovedDocumentTypes(): array
    {
        $approvedDocuments = $this->documentUploads()
            ->where('review_status', DocumentUpload::ReviewStatusRegistrarApproved)
            ->pluck('document_type')
            ->all();

        return array_values(array_diff($this->requiredDocumentTypes(), $approvedDocuments));
    }

    public function hasAllApprovedRequiredDocuments(): bool
    {
        return $this->missingApprovedDocumentTypes() === [];
    }

    /**
     * @return list<string>
     */
    public function missingSubmittedAdmissionGateDocumentTypes(): array
    {
        $submittedDocuments = $this->documentUploads()
            ->select('document_type')
            ->distinct()
            ->pluck('document_type')
            ->all();

        return array_values(array_diff($this->admissionGateDocumentTypes(), $submittedDocuments));
    }

    /**
     * @return list<string>
     */
    public function missingApprovedAdmissionGateDocumentTypes(): array
    {
        $approvedDocuments = $this->documentUploads()
            ->where('review_status', DocumentUpload::ReviewStatusRegistrarApproved)
            ->pluck('document_type')
            ->all();

        return array_values(array_diff($this->admissionGateDocumentTypes(), $approvedDocuments));
    }
}
