<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilityChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyAvailabilityChangeRequest extends Model
{
    /** @use HasFactory<FacultyAvailabilityChangeRequestFactory> */
    use HasFactory;

    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'faculty_id',
        'submission_id',
        'status',
        'reason',
        'source_windows',
        'requested_windows',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'creates_submission_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_windows' => 'array',
            'requested_windows' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusPending => 'Pending',
            self::StatusApproved => 'Approved',
            self::StatusRejected => 'Rejected',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'warning' => self::StatusPending,
            'success' => self::StatusApproved,
            'danger' => self::StatusRejected,
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::StatusPending;
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FacultyAvailabilitySubmission::class, 'submission_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdSubmission(): BelongsTo
    {
        return $this->belongsTo(FacultyAvailabilitySubmission::class, 'creates_submission_id');
    }
}
