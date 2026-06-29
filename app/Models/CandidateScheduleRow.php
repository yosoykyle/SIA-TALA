<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CandidateScheduleRow extends Model
{
    public const StatusOk = 'ok';

    public const StatusWarning = 'warning';

    public const StatusConflict = 'conflict';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'schedule_run_id',
        'scheduling_demand_id',
        'meeting_sequence',
        'faculty_user_id',
        'room_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'time_block_key',
        'status',
        'scores',
        'warnings',
        'violations',
        'override_authority',
        'override_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_sequence' => 'integer',
            'day_of_week' => 'integer',
            'scores' => 'array',
            'warnings' => 'array',
            'violations' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public static function committableStatuses(): array
    {
        return [
            self::StatusOk,
            self::StatusWarning,
        ];
    }

    public function isCommittable(): bool
    {
        return in_array($this->status, self::committableStatuses(), true)
            && ! $this->hasBlockingViolations();
    }

    public function isPublishableFor(ScheduleGenerationRun $run): bool
    {
        if (! $this->isCommittable()) {
            return false;
        }

        $demand = $this->publicationDemand();
        $termOffering = $demand->getRelation('termOffering');

        if (! $termOffering instanceof TermOffering
            || (int) $termOffering->term_id !== (int) $run->term_id) {
            return false;
        }

        return $demand->modality !== TermOffering::ModalityFaceToFace
            || $this->room_id !== null;
    }

    public function publicationModality(): string
    {
        return (string) $this->publicationDemand()->modality;
    }

    private function publicationDemand(): SchedulingDemand
    {
        $this->loadMissing('schedulingDemand.termOffering');
        $demand = $this->getRelation('schedulingDemand');

        if (! $demand instanceof SchedulingDemand) {
            throw new LogicException('A candidate schedule row must reference a scheduling demand.');
        }

        return $demand;
    }

    public function hasWarnings(): bool
    {
        return $this->status === self::StatusWarning || $this->payloadIsNotEmpty($this->warnings);
    }

    public function hasBlockingViolations(): bool
    {
        return $this->payloadIsNotEmpty($this->violations);
    }

    private function payloadIsNotEmpty(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $items = $payload['items'] ?? $payload;

        return is_array($items) && $items !== [];
    }

    public function scheduleRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class, 'schedule_run_id');
    }

    public function generationRun(): BelongsTo
    {
        return $this->scheduleRun();
    }

    /** @return BelongsTo<SchedulingDemand, $this> */
    public function schedulingDemand(): BelongsTo
    {
        return $this->belongsTo(SchedulingDemand::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
