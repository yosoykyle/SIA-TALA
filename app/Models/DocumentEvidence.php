<?php

namespace App\Models;

use Database\Factories\DocumentEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $uploaded_at
 * @property Carbon|null $reviewed_at
 */
class DocumentEvidence extends Model
{
    /** @use HasFactory<DocumentEvidenceFactory> */
    use HasFactory;

    public const StatusSubmitted = 'SUBMITTED';

    public const StatusAccepted = 'ACCEPTED';

    public const StatusRejected = 'REJECTED';

    /** @var list<string> */
    protected $fillable = [
        'checklist_item_id',
        'admission_application_id',
        'admission_requirement_id',
        'application_submission_version_id',
        'disk',
        'path',
        'checksum',
        'mime_type',
        'size_bytes',
        'evidence_method',
        'status',
        'uploaded_by',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
        'replaces_document_evidence_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChecklistItem, $this> */
    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function admissionApplication(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class);
    }

    /** @return BelongsTo<AdmissionRequirement, $this> */
    public function admissionRequirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirement::class);
    }

    /** @return BelongsTo<ApplicationSubmissionVersion, $this> */
    public function applicationSubmissionVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationSubmissionVersion::class);
    }

    /** @return HasMany<PreliminaryEvidenceReview, $this> */
    public function preliminaryReviews(): HasMany
    {
        return $this->hasMany(PreliminaryEvidenceReview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<DocumentEvidence, $this> */
    public function replacedEvidence(): BelongsTo
    {
        return $this->belongsTo(DocumentEvidence::class, 'replaces_document_evidence_id');
    }
}
