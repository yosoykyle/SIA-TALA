<?php

namespace App\Models;

use Database\Factories\SectionDeliveryGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionDeliveryGroup extends Model
{
    /** @use HasFactory<SectionDeliveryGroupFactory> */
    use HasFactory;

    public const StatePlanned = 'PLANNED';

    public const StateReady = 'READY';

    public const StateClosed = 'CLOSED';

    public const StateCancelled = 'CANCELLED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'section_id',
        'name',
        'expected_count',
        'modality',
        'delivery_override',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_count' => 'integer',
            'delivery_override' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            self::StatePlanned => 'Planned',
            self::StateReady => 'Ready',
            self::StateClosed => 'Closed',
            self::StateCancelled => 'Cancelled',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return TermOffering::modalityOptions();
    }

    public function displayLabel(): string
    {
        $modality = self::modalityOptions()[$this->modality] ?? str((string) $this->modality)->replace('_', ' ')->headline()->toString();
        $section = $this->section;
        $sectionCode = $section instanceof Section ? $section->code : 'Unassigned section';

        return "{$sectionCode} / {$this->name} ({$modality})";
    }

    public function exceedsSectionCapacity(): bool
    {
        $section = $this->section;

        if (! $section instanceof Section) {
            return true;
        }

        return ! $section->hasCapacityFor((int) $this->expected_count);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
