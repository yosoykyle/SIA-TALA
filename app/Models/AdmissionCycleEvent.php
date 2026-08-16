<?php

namespace App\Models;

use Database\Factories\AdmissionCycleEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/** @property Carbon $occurred_at */
class AdmissionCycleEvent extends Model
{
    /** @use HasFactory<AdmissionCycleEventFactory> */
    use HasFactory;

    public const TypePublished = 'Published';

    public const TypeDatesChanged = 'DatesChanged';

    public const TypeCancelled = 'Cancelled';

    /** @var list<string> */
    protected $fillable = [
        'admission_cycle_id',
        'event_type',
        'event_key',
        'previous_values',
        'new_values',
        'reason',
        'authority_reference',
        'actor_id',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'new_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionCycle, $this> */
    public function admissionCycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::creating(function (AdmissionCycleEvent $event): void {
            if (! in_array($event->event_type, self::eventTypes(), true)) {
                throw new InvalidArgumentException('Unsupported admission-cycle event type.');
            }
        });

        static::updating(fn (): never => throw new LogicException('Admission-cycle events are immutable history.'));
        static::deleting(fn (): never => throw new LogicException('Admission-cycle events cannot be deleted.'));
    }

    /** @return list<string> */
    private static function eventTypes(): array
    {
        return [self::TypePublished, self::TypeDatesChanged, self::TypeCancelled];
    }
}
