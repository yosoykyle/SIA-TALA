<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentUpload extends Model
{
    public const ReviewStatusUploaded = 'uploaded';

    public const ReviewStatusOcrExtracted = 'ocr_extracted';

    public const ReviewStatusStudentConfirmed = 'student_confirmed';

    public const ReviewStatusPendingRegistrarReview = 'pending_registrar_review';

    public const ReviewStatusRegistrarApproved = 'registrar_approved';

    public const ReviewStatusNeedsCorrection = 'needs_correction';

    public const ReviewStatusRejected = 'rejected';

    public const ReviewStatusNeedsManualReview = 'needs_manual_review';

    public const ReviewStatusManualEntry = 'manual_entry';

    /**
     * @var list<string>
     */
    public const RegistrarReviewableStatuses = [
        self::ReviewStatusUploaded,
        self::ReviewStatusOcrExtracted,
        self::ReviewStatusStudentConfirmed,
        self::ReviewStatusPendingRegistrarReview,
        self::ReviewStatusNeedsCorrection,
        self::ReviewStatusNeedsManualReview,
        self::ReviewStatusManualEntry,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'user_id',
        'term_id',
        'document_type',
        'file_disk',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'checksum',
        'upload_status',
        'ocr_review_status',
        'ocr_confidence',
        'ocr_text',
        'ocr_processed_at',
        'parser_version',
        'registrar_reviewed_by',
        'registrar_reviewed_at',
        'student_confirmed_payload',
        'student_confirmed_at',
        'registrar_approved_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_confidence' => 'decimal:2',
            'ocr_processed_at' => 'datetime',
            'registrar_reviewed_at' => 'datetime',
            'student_confirmed_payload' => 'array',
            'student_confirmed_at' => 'datetime',
            'registrar_approved_payload' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reviewStatusOptions(): array
    {
        return [
            self::ReviewStatusUploaded => 'Uploaded',
            self::ReviewStatusOcrExtracted => 'OCR Extracted',
            self::ReviewStatusStudentConfirmed => 'Student Confirmed',
            self::ReviewStatusPendingRegistrarReview => 'Pending Registrar Review',
            self::ReviewStatusRegistrarApproved => 'Registrar Approved',
            self::ReviewStatusNeedsCorrection => 'Needs Correction',
            self::ReviewStatusRejected => 'Rejected',
            self::ReviewStatusNeedsManualReview => 'Needs Manual Review',
            self::ReviewStatusManualEntry => 'Manual Entry',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reviewStatusColors(): array
    {
        return [
            'gray' => self::ReviewStatusUploaded,
            'info' => self::ReviewStatusOcrExtracted,
            'warning' => self::ReviewStatusPendingRegistrarReview,
            'success' => self::ReviewStatusRegistrarApproved,
            'danger' => self::ReviewStatusRejected,
        ];
    }

    public function isRegistrarApproved(): bool
    {
        return $this->ocr_review_status === self::ReviewStatusRegistrarApproved;
    }

    public function isRejected(): bool
    {
        return $this->ocr_review_status === self::ReviewStatusRejected;
    }

    public function isRegistrarReviewable(): bool
    {
        return in_array($this->ocr_review_status, self::RegistrarReviewableStatuses, true);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function registrarReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrar_reviewed_by');
    }
}
