<?php

namespace App\Models;

use Database\Factories\FacultySubjectEligibilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultySubjectEligibility extends Model
{
    /** @use HasFactory<FacultySubjectEligibilityFactory> */
    use HasFactory;

    public const StatusActive = 'active';

    public const StatusInactive = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'faculty_id',
        'subject_id',
        'term_id',
        'status',
        'priority',
        'max_weekly_hours',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'max_weekly_hours' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusActive => 'Active',
            self::StatusInactive => 'Inactive',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statusValues(): array
    {
        return array_keys(self::statusOptions());
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            self::StatusActive => 'success',
            self::StatusInactive => 'gray',
        ];
    }

    public static function isActiveFor(int $facultyId, int $subjectId, ?int $termId): bool
    {
        return self::query()
            ->where('faculty_id', $facultyId)
            ->where('subject_id', $subjectId)
            ->where('status', self::StatusActive)
            ->where(function ($query) use ($termId): void {
                $query->whereNull('term_id');

                if ($termId !== null) {
                    $query->orWhere('term_id', $termId);
                }
            })
            ->exists();
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
