<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class FaqEntry extends Model
{
    public const CategoryGeneral = 'general';

    public const CategoryAdmissionEnrollment = 'admission_enrollment';

    public const CategoryPaymentsFees = 'payments_fees';

    public const CategoryDocumentsRequests = 'documents_requests';

    public const CategoryGradesAcademics = 'grades_academics';

    public const CategoryAccountLogin = 'account_login';

    public const CategoryTechnicalSupport = 'technical_support';

    protected $fillable = [
        'question',
        'answer',
        'category',
        'sort_order',
        'is_published',
    ];

    protected static function booted(): void
    {
        static::creating(function (FaqEntry $faqEntry): void {
            if (Auth::id() !== null) {
                $faqEntry->created_by ??= Auth::id();
                $faqEntry->updated_by = Auth::id();
            }
        });

        static::updating(function (FaqEntry $faqEntry): void {
            if (Auth::id() !== null) {
                $faqEntry->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CategoryGeneral => 'General',
            self::CategoryAdmissionEnrollment => 'Admission / Enrollment',
            self::CategoryPaymentsFees => 'Payments / Fees',
            self::CategoryDocumentsRequests => 'Documents / Requests',
            self::CategoryGradesAcademics => 'Grades / Academics',
            self::CategoryAccountLogin => 'Account / Login',
            self::CategoryTechnicalSupport => 'Technical Support',
        ];
    }

    public static function categoryLabel(?string $category): string
    {
        return self::categoryOptions()[$category] ?? str((string) $category)->replace('_', ' ')->headline()->toString();
    }
}
