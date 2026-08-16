<?php

namespace App\Models;

use Database\Factories\OfficialCredentialResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon|null $exception_expires_at
 * @property Carbon $recorded_at
 */
class OfficialCredentialResult extends Model
{
    /** @use HasFactory<OfficialCredentialResultFactory> */
    use HasFactory;

    public const ResultNotYetDue = 'NotYetDue';

    public const ResultNotReceived = 'NotReceived';

    public const ResultReceivedUnderReview = 'ReceivedUnderReview';

    public const ResultVerified = 'Verified';

    public const ResultActionNeeded = 'ActionNeeded';

    public const ResultAuthorizedException = 'AuthorizedException';

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'admission_requirement_id',
        'result',
        'source_reference',
        'safe_explanation',
        'authority_reference',
        'exception_expires_at',
        'recorded_by',
        'recorded_at',
        'supersedes_official_credential_result_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exception_expires_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<AdmissionRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirement::class, 'admission_requirement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<OfficialCredentialResult, $this> */
    public function supersededResult(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_official_credential_result_id');
    }

    /** @return HasOne<OfficialCredentialResult, $this> */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_official_credential_result_id');
    }

    public function supersedes(self $result): bool
    {
        return (int) $this->supersedes_official_credential_result_id === (int) $result->id;
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Official credential results are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Official credential results cannot be deleted.'));
    }
}
