<?php

namespace App\Models;

use App\Models\Concerns\HasPublicContentVersions;
use Database\Factories\FaqEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int|null $root_id
 * @property int|null $previous_version_id
 * @property int $version
 * @property int $revision
 * @property bool $ever_published
 * @property int|null $published_by
 * @property Carbon|null $visible_from
 * @property Carbon|null $visible_until
 * @property Carbon|null $published_at
 */
class FaqEntry extends Model
{
    /** @use HasFactory<FaqEntryFactory> */
    use HasFactory, HasPublicContentVersions, LogsActivity;

    protected $attributes = ['version' => 1, 'revision' => 1, 'ever_published' => false, 'is_published' => false];

    public const CategoryGeneral = 'general';

    public const CategoryAdmissionEnrollment = 'admission_enrollment';

    public const CategoryPaymentsFees = 'payments_fees';

    public const CategoryGradesAcademics = 'grades_academics';

    public const CategoryAccountLogin = 'account_login';

    public const CategoryTechnicalSupport = 'technical_support';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question',
        'answer',
        'category',
        'sort_order',
        'is_published',
        'system_key',
        'visible_from',
        'visible_until',
    ];

    protected static function booted(): void
    {
        static::creating(function (FaqEntry $faqEntry): void {
            if (! array_key_exists('sort_order', $faqEntry->getAttributes())) {
                $faqEntry->sort_order = ((int) static::query()->max('sort_order')) + 1;
            }

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'ever_published' => 'boolean',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
            'published_at' => 'datetime',
            'version' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('faq')
            ->logOnly([
                'question',
                'answer',
                'category',
                'sort_order',
                'is_published',
                'system_key',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Published entries in their public display order.
     *
     * @param  Builder<FaqEntry>  $query
     * @return Builder<FaqEntry>
     */
    public function scopePublishedOrdered(Builder $query): Builder
    {
        return $query
            ->effective()
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function publicationColumn(): string
    {
        return 'is_published';
    }

    public static function contentFields(): array
    {
        return ['question', 'answer', 'category', 'sort_order', 'visible_from', 'visible_until'];
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
