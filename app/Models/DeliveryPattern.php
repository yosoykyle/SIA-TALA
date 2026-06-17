<?php

namespace App\Models;

use Database\Factories\DeliveryPatternFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryPattern extends Model
{
    /** @use HasFactory<DeliveryPatternFactory> */
    use HasFactory;

    public const SubjectRoutingSameSubjectSet = 'same_subject_set';

    public const SubjectRoutingMinorOnlineMajorOnSite = 'minor_online_major_on_site';

    public const SubjectRoutingCustom = 'custom';

    public const EnforcementStrict = 'strict';

    public const EnforcementAdvisory = 'advisory';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'version',
        'name',
        'description',
        'modality',
        'allowed_days',
        'subject_routing',
        'enforcement_level',
        'default_room_required',
        'is_active',
        'is_frozen',
        'used_at',
        'cloned_from_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'allowed_days' => 'array',
            'default_room_required' => 'boolean',
            'is_active' => 'boolean',
            'is_frozen' => 'boolean',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function modalityOptions(): array
    {
        return SectionMeeting::modalityOptions();
    }

    /**
     * @return array<int, string>
     */
    public static function dayOptions(): array
    {
        return SectionMeeting::dayOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function subjectRoutingOptions(): array
    {
        return [
            self::SubjectRoutingSameSubjectSet => 'Same subject set',
            self::SubjectRoutingMinorOnlineMajorOnSite => 'Minor online / major on-site',
            self::SubjectRoutingCustom => 'Custom staff-defined rule',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function enforcementLevelOptions(): array
    {
        return [
            self::EnforcementStrict => 'Strict',
            self::EnforcementAdvisory => 'Advisory',
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null || $this->sectionDeliveryGroups()->exists();
    }

    public function isRuleLocked(): bool
    {
        return $this->is_frozen || $this->isUsed();
    }

    public function displayLabel(): string
    {
        return "{$this->name} v{$this->version}";
    }

    public function sectionDeliveryGroups(): HasMany
    {
        return $this->hasMany(SectionDeliveryGroup::class);
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
