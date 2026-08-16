<?php

namespace App\Models;

use Database\Factories\AdmissionApplicationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
class AdmissionApplicationEvent extends Model
{
    /** @use HasFactory<AdmissionApplicationEventFactory> */
    use HasFactory;

    public const TypeSubmitted = 'Submitted';

    public const TypeCorrectionRequested = 'CorrectionRequested';

    public const TypeResubmitted = 'Resubmitted';

    public const TypeWithdrawn = 'Withdrawn';

    public const TypeReopened = 'Reopened';

    public const TypeDecisionRecorded = 'DecisionRecorded';

    public const TypeCredentialResultRecorded = 'CredentialResultRecorded';

    public const TypeReadinessBecameTrue = 'ReadinessBecameTrue';

    public const TypeReadinessBecameFalse = 'ReadinessBecameFalse';

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'event_type',
        'event_key',
        'actor_id',
        'source_type',
        'source_id',
        'payload',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::creating(function (AdmissionApplicationEvent $event): void {
            if (! in_array($event->event_type, self::eventTypes(), true)) {
                throw new InvalidArgumentException('Unsupported admission-application event type.');
            }
        });

        static::updating(fn (): never => throw new LogicException('Admission-application events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Admission-application events cannot be deleted.'));
    }

    /** @return list<string> */
    private static function eventTypes(): array
    {
        return [
            self::TypeSubmitted,
            self::TypeCorrectionRequested,
            self::TypeResubmitted,
            self::TypeWithdrawn,
            self::TypeReopened,
            self::TypeDecisionRecorded,
            self::TypeCredentialResultRecorded,
            self::TypeReadinessBecameTrue,
            self::TypeReadinessBecameFalse,
        ];
    }
}
