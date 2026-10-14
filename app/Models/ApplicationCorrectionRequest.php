<?php

namespace App\Models;

use Database\Factories\ApplicationCorrectionRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $due_at
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 */
class ApplicationCorrectionRequest extends Model
{
    /** @use HasFactory<ApplicationCorrectionRequestFactory> */
    use HasFactory;

    public const StateActive = 'Active';

    public const StateCompleted = 'Completed';

    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'sequence',
        'state',
        'applicant_instruction',
        'responsible_party',
        'due_at',
        'requested_by',
        'requested_at',
        'completed_at',
        'supersedes_correction_request_id',
        'source_submission_version_id',
        'target_requirement_set_id',
        'target_application_path',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => self::StateActive,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_at' => 'datetime',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'source_submission_version_id' => 'integer',
            'target_requirement_set_id' => 'integer',
        ];
    }

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<ApplicationCorrectionRequest, $this> */
    public function supersededRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_correction_request_id');
    }

    /** @return HasOne<ApplicationCorrectionRequest, $this> */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_correction_request_id');
    }

    /** @return HasMany<ApplicationCorrectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ApplicationCorrectionItem::class);
    }

    public function isOverdue(): bool
    {
        return $this->state === self::StateActive && $this->due_at->isPast();
    }

    public function changesStudentType(): bool
    {
        return $this->target_application_path !== null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty(['source_submission_version_id', 'target_requirement_set_id', 'target_application_path'])) {
                throw new \LogicException('The confirmed Student type correction source is immutable.');
            }
        });
    }
}
