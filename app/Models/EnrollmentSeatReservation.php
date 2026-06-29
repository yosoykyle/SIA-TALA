<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentSeatReservation extends Model
{
    public const StatusActive = 'active';

    public const StatusPending = 'pending';

    public const StatusReleased = 'released';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'course_enrollment_id',
        'section_id',
        'status',
        'reserved_at',
        'released_at',
        'converted_at',
        'deadline',
        'registrar_user_id',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
            'converted_at' => 'datetime',
            'deadline' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function capacityHoldingStatuses(): array
    {
        return [
            self::StatusActive,
            self::StatusPending,
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrar_user_id');
    }
}
