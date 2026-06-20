<?php

namespace App\Models;

use Database\Factories\RetentionDocumentUndertakingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionDocumentUndertaking extends Model
{
    /** @use HasFactory<RetentionDocumentUndertakingFactory> */
    use HasFactory;

    public const StatusActive = 'active';

    public const StatusResolved = 'resolved';

    public const StatusOverdue = 'overdue';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'applicant_intake_id',
        'applicant_document_requirement_id',
        'student_profile_id',
        'enrollment_id',
        'status',
        'issued_by',
        'issued_at',
        'due_at',
        'extension_count',
        'resolved_by',
        'resolved_at',
        'resolved_by_document_upload_id',
        'overdue_marked_at',
        'hold_applied_at',
        'hold_reason',
        'meta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::StatusActive,
        'extension_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'extension_count' => 'integer',
            'resolved_at' => 'datetime',
            'overdue_marked_at' => 'datetime',
            'hold_applied_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function applicantIntake(): BelongsTo
    {
        return $this->belongsTo(ApplicantIntake::class);
    }

    public function applicantDocumentRequirement(): BelongsTo
    {
        return $this->belongsTo(ApplicantDocumentRequirement::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolvedByDocumentUpload(): BelongsTo
    {
        return $this->belongsTo(DocumentUpload::class, 'resolved_by_document_upload_id');
    }
}
