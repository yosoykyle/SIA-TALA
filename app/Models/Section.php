<?php

namespace App\Models;

use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    public const StatePlanned = 'PLANNED';

    public const StateOpen = 'OPEN';

    public const StateClosed = 'CLOSED';

    public const StateCancelled = 'CANCELLED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'term_offering_id',
        'code',
        'capacity',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
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

    public function hasCapacityFor(int $expectedCount): bool
    {
        return $expectedCount >= 0 && $expectedCount <= $this->capacity;
    }
}
