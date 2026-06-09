<?php

namespace App\Models;

use Database\Factories\FacultyAvailabilityPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacultyAvailabilityPeriod extends Model
{
    /** @use HasFactory<FacultyAvailabilityPeriodFactory> */
    use HasFactory;

    public const StatusOpen = 'open';

    public const StatusLocked = 'locked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_id',
        'opens_at',
        'closes_at',
        'status',
        'created_by',
        'locked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusOpen => 'Open',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FacultyAvailabilitySubmission::class, 'availability_period_id');
    }
}
