<?php

namespace App\Models;

use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory;

    public const StatusPendingDocumentFee = 'pending_document_fee';

    public const StatusProcessing = 'processing';

    public const StatusReadyForPickup = 'ready_for_pickup';

    public const StatusPendingShippingPayment = 'pending_shipping_payment';

    public const StatusCompleted = 'completed';

    public const StatusCompletedWithDebt = 'completed_with_debt';

    public const StatusCancelled = 'cancelled';

    public const DeliveryModePickup = 'pickup';

    public const DeliveryModeCourier = 'courier';

    public const TypeCertificateOfRegistration = 'certificate_of_registration';

    public const TypeCertificateOfEnrollment = 'certificate_of_enrollment';

    public const TypeGoodMoralCharacter = 'good_moral_character';

    public const TypeTranscriptOfRecords = 'transcript_of_records';

    public const TypeForm137 = 'form_137';

    public const TypeForm138 = 'form_138';

    public const TypeDiploma = 'diploma';

    public const TypeOther = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'document_type',
        'status',
        'is_free_request',
        'delivery_consent',
        'delivery_mode',
        'courier_name',
        'tracking_number',
        'tracking_number_normalized',
        'shipping_fee',
        'courier_receipt_path',
        'shipped_at',
        'shipping_grace_ends_at',
        'shipping_fee_assessment_transaction_id',
        'shipping_fee_payment_transaction_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_free_request' => 'boolean',
            'delivery_consent' => 'boolean',
            'shipping_fee' => 'decimal:2',
            'shipped_at' => 'datetime',
            'shipping_grace_ends_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function shippingFeeAssessmentLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'shipping_fee_assessment_transaction_id');
    }

    public function shippingFeePaymentLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'shipping_fee_payment_transaction_id');
    }

    /**
     * @return array<string, string>
     */
    public static function documentTypeOptions(): array
    {
        return [
            self::TypeCertificateOfRegistration => 'Certificate of Registration',
            self::TypeCertificateOfEnrollment => 'Certificate of Enrollment',
            self::TypeGoodMoralCharacter => 'Certificate of Good Moral Character',
            self::TypeTranscriptOfRecords => 'Transcript of Records',
            self::TypeForm137 => 'Form 137',
            self::TypeForm138 => 'Form 138',
            self::TypeDiploma => 'Diploma',
            self::TypeOther => 'Other',
        ];
    }

    public static function documentTypeLabel(?string $documentType): string
    {
        return self::documentTypeOptions()[$documentType] ?? str((string) $documentType)->replace('_', ' ')->headline()->toString();
    }
}
