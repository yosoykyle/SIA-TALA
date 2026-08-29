<?php

namespace App\Models;

use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    public const StatePlanned = 'PLANNED';

    public const StateOpen = 'OPEN';

    public const StateClosed = 'CLOSED';

    public const StateCancelled = 'CANCELLED';

    public const SourceRegular = 'Regular';

    public const SourceShared = 'Shared';

    public const SourceAdditional = 'Additional';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_offering_id',
        'term_calendar_package_id',
        'course_specification_id',
        'code',
        'class_reference',
        'source',
        'delivery_mode',
        'authority_reference',
        'capacity',
        'state',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        return [
            self::StatePlanned => 'Planned',
            self::StateOpen => 'Open',
            self::StateClosed => 'Closed',
            self::StateCancelled => 'Cancelled',
        ];
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return [
            self::SourceRegular => 'Regular',
            self::SourceShared => 'Shared',
            self::SourceAdditional => 'Additional',
        ];
    }

    /** @return array<string, string> */
    public static function confirmableSourceOptions(): array
    {
        return [
            self::SourceRegular => 'Regular',
            self::SourceAdditional => 'Additional',
        ];
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return BelongsTo<TermCalendarPackage, $this> */
    public function calendarPackage(): BelongsTo
    {
        return $this->belongsTo(TermCalendarPackage::class, 'term_calendar_package_id');
    }

    /** @return BelongsTo<CourseSpecification, $this> */
    public function courseRevision(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class, 'course_specification_id');
    }

    /** @return BelongsToMany<TermCohort, $this> */
    public function cohorts(): BelongsToMany
    {
        return $this->belongsToMany(TermCohort::class)->withPivot('expected_count')->withTimestamps();
    }

    /** @return HasMany<SectionDeliveryGroup, $this> */
    public function deliveryGroups(): HasMany
    {
        return $this->hasMany(SectionDeliveryGroup::class);
    }

    /** @return HasMany<GradeRoster, $this> */
    public function gradeRosters(): HasMany
    {
        return $this->hasMany(GradeRoster::class);
    }

    /** @return HasMany<EnrollmentSeatReservation, $this> */
    public function seatReservations(): HasMany
    {
        return $this->hasMany(EnrollmentSeatReservation::class);
    }

    public function hasCapacityFor(int $expectedCount): bool
    {
        return $expectedCount >= 0 && $expectedCount <= $this->capacity;
    }
}
