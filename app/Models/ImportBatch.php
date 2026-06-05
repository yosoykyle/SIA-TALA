<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportBatch extends Model
{
    public const TypeStudentData = 'student_data';

    public const TypeLegacyGrades = 'legacy_grades';

    public const TypeLegacyFinancial = 'legacy_financial';

    public const TypeEnrollmentRecords = 'enrollment_records';

    public const TypeCurriculum = 'curriculum';

    public const StatusPendingReview = 'pending_review';

    public const StatusCommitted = 'committed';

    public const StatusCancelled = 'cancelled';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'import_type',
        'file_name',
        'file_path',
        'total_rows',
        'valid_rows',
        'error_rows',
        'skipped_rows',
        'status',
        'imported_by',
        'committed_by',
        'committed_at',
        'error_log',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'error_log' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function importTypeOptions(): array
    {
        return [
            self::TypeStudentData => 'Student Data',
            self::TypeLegacyGrades => 'Legacy Grades',
            self::TypeLegacyFinancial => 'Legacy Financial',
            self::TypeEnrollmentRecords => 'Enrollment Records',
            self::TypeCurriculum => 'Curriculum',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusPendingReview => 'Pending Review',
            self::StatusCommitted => 'Committed',
            self::StatusCancelled => 'Cancelled',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'warning' => self::StatusPendingReview,
            'success' => self::StatusCommitted,
            'gray' => self::StatusCancelled,
        ];
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::StatusPendingReview;
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
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
