<?php

namespace App\Models;

use Database\Factories\SectionDeliveryGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SectionDeliveryGroup extends Model
{
    /** @use HasFactory<SectionDeliveryGroupFactory> */
    use HasFactory;

    public const StatusPlanned = 'planned';

    public const StatusActive = 'active';

    public const StatusClosed = 'closed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'section_id',
        'delivery_pattern_id',
        'name',
        'modality',
        'capacity',
        'assigned_count',
        'room_required',
        'room',
        'status',
        'created_by',
        'updated_by',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'assigned_count' => 'integer',
            'room_required' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::StatusPlanned => 'Planned',
            self::StatusActive => 'Active',
            self::StatusClosed => 'Closed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return SectionMeeting::modalityOptions();
    }

    public static function modalityRequiresRoom(?string $modality): bool
    {
        return Section::modalityRequiresRoom($modality);
    }

    public function availableSeats(): int
    {
        return max(0, (int) $this->capacity - (int) $this->assigned_count);
    }

    public function isAssignable(): bool
    {
        return $this->status === self::StatusActive && $this->availableSeats() > 0;
    }

    public function displayLabel(): string
    {
        $modality = self::modalityOptions()[$this->modality] ?? str((string) $this->modality)->replace('_', ' ')->headline()->toString();

        return "{$this->section?->name} / {$this->name} ({$modality})";
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function deliveryPattern(): BelongsTo
    {
        return $this->belongsTo(DeliveryPattern::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scheduleDraftRows(): HasMany
    {
        return $this->hasMany(ScheduleDraftRow::class);
    }

    public function sectionMeetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
