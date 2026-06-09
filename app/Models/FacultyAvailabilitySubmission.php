<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilitySubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacultyAvailabilitySubmission extends Model
{
    /** @use HasFactory<FacultyAvailabilitySubmissionFactory> */
    use HasFactory;

    public const StatusSubmitted = 'submitted';

    public const StatusLocked = 'locked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'availability_period_id',
        'faculty_id',
        'status',
        'version',
        'submitted_at',
        'locked_at',
        'parent_submission_id',
        'change_reason',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusSubmitted => 'Submitted',
            self::StatusLocked => 'Locked',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status === self::StatusLocked;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function availabilityPeriod(): BelongsTo
    {
        return $this->belongsTo(FacultyAvailabilityPeriod::class, 'availability_period_id');
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function windows(): HasMany
    {
        return $this->hasMany(FacultyAvailabilityWindow::class, 'submission_id');
    }
}
