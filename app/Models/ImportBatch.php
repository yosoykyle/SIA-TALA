<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    public const TypeCourseSpecification = 'COURSE_SPECIFICATION';

    public const TypeCurriculum = 'CURRICULUM';

    public const StatePendingReview = 'PENDING_REVIEW';

    public const StatePosted = 'POSTED';

    public const StateCancelled = 'CANCELLED';

    public const StatusPendingReview = self::StatePendingReview;

    public const StatusCommitted = self::StatePosted;

    public const StatusCancelled = self::StateCancelled;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'template_version',
        'source_disk',
        'source_path',
        'source_checksum',
        'uploaded_by',
        'row_count',
        'error_count',
        'warning_count',
        'state',
        'validation_details',
        'acknowledged_at',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validation_details' => 'array',
            'acknowledged_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function importTypeOptions(): array
    {
        return [
            self::TypeCourseSpecification => 'Course Specification',
            self::TypeCurriculum => 'Curriculum',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatePendingReview => 'Pending Review',
            self::StatePosted => 'Posted',
            self::StateCancelled => 'Cancelled',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'warning' => self::StatePendingReview,
            'success' => self::StatePosted,
            'gray' => self::StateCancelled,
        ];
    }

    public function isPendingReview(): bool
    {
        return $this->state === self::StatePendingReview;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function importer(): BelongsTo
    {
        return $this->uploader();
    }

    protected static function booted(): void
    {
        static::creating(function (ImportBatch $importBatch): void {
            if (! $importBatch->getKey()) {
                $importBatch->setAttribute($importBatch->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
