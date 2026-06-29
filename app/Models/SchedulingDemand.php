<?php

namespace App\Models;

use Database\Factories\SchedulingDemandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingDemand extends Model
{
    /** @use HasFactory<SchedulingDemandFactory> */
    use HasFactory;

    public const ValidationReadyForReview = 'READY_FOR_REVIEW';

    public const ValidationActionRequired = 'ACTION_REQUIRED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_offering_id',
        'course_component_id',
        'section_delivery_group_id',
        'demand_key',
        'required_duration_minutes',
        'meeting_count',
        'modality',
        'fixed_faculty_user_id',
        'fixed_room_id',
        'fixed_day_of_week',
        'fixed_start_time',
        'source_snapshot',
        'readiness_findings',
        'validation_state',
        'generated_by',
        'readiness_checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_duration_minutes' => 'integer',
            'meeting_count' => 'integer',
            'fixed_day_of_week' => 'integer',
            'source_snapshot' => 'array',
            'readiness_findings' => 'array',
            'readiness_checked_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validationStateOptions(): array
    {
        return [
            self::ValidationReadyForReview => 'Ready for review',
            self::ValidationActionRequired => 'Action required',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validationStateColors(): array
    {
        return [
            self::ValidationReadyForReview => 'success',
            self::ValidationActionRequired => 'warning',
        ];
    }

    public function hasReadinessFindings(): bool
    {
        return $this->readinessFindings() !== [];
    }

    /**
     * @return list<array{key:string,severity:string,source_type:string,source_id:int|null,message:string}>
     */
    public function readinessFindings(): array
    {
        $findings = $this->getAttribute('readiness_findings');

        return is_array($findings) ? array_values($findings) : [];
    }

    /**
     * @return list<string>
     */
    public function readinessFindingKeys(): array
    {
        return collect($this->readinessFindings())
            ->pluck('key')
            ->filter()
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return BelongsTo<CourseComponent, $this> */
    public function courseComponent(): BelongsTo
    {
        return $this->belongsTo(CourseComponent::class);
    }

    /** @return BelongsTo<SectionDeliveryGroup, $this> */
    public function sectionDeliveryGroup(): BelongsTo
    {
        return $this->belongsTo(SectionDeliveryGroup::class);
    }

    /** @return BelongsTo<User, $this> */
    public function fixedFaculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fixed_faculty_user_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function fixedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'fixed_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return HasMany<SectionMeeting, $this> */
    public function sectionMeetings(): HasMany
    {
        return $this->hasMany(SectionMeeting::class);
    }
}
