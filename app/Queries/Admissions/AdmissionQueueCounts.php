<?php

namespace App\Queries\Admissions;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Registrar's Admissions queues, shared by the queue tabs and the sidebar sub-items (#61 F101).
 */
class AdmissionQueueCounts
{
    public const NeedsReview = 'needs_review';

    public const WaitingForApplicant = 'waiting_for_applicant';

    public const RegistrarClearance = 'official_credentials';

    public const ReadyForEnrollment = 'ready_for_enrollment';

    public const Expired = 'expired';

    public const Closed = 'history';

    /** @var list<int>|null */
    private ?array $readyApplicationIds = null;

    /** @var array<string, list<int>> */
    private array $deadlineIds = [];

    public function __construct(
        private readonly ReadyApplicantProjectionQuery $readyApplicants,
        private readonly AdmissionDeadlineStatus $deadlines,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::NeedsReview => 'Needs review',
            self::WaitingForApplicant => 'Waiting for applicant',
            self::RegistrarClearance => 'Registrar clearance',
            self::ReadyForEnrollment => 'Ready for enrollment',
            self::Expired => 'Expired applications',
            self::Closed => 'Closed applications',
        ];
    }

    /**
     * Queues where the Registrar is the one who must act next.
     *
     * @return list<string>
     */
    public static function registrarActionQueues(): array
    {
        return [self::NeedsReview, self::RegistrarClearance, self::Expired];
    }

    public function scope(Builder $query, string $queue): Builder
    {
        $closed = $this->idsWith(AdmissionDeadlineStatus::Closed);
        $expired = $this->idsWith(AdmissionDeadlineStatus::Expired);

        return match ($queue) {
            self::NeedsReview => $query->where('application_state', AdmissionApplication::StateSubmitted)->whereNotIn('id', $closed),
            self::WaitingForApplicant => $query->where('application_state', AdmissionApplication::StateActionNeeded)->whereNotIn('id', $closed),
            self::RegistrarClearance => $query->where('application_state', AdmissionApplication::StateAdmitted)
                ->whereNotIn('id', [...$this->readyApplicationIds(), ...$closed, ...$expired]),
            self::ReadyForEnrollment => $query->whereIn('id', $this->readyApplicationIds())->whereNotIn('id', [...$closed, ...$expired]),
            self::Expired => $query->whereIn('id', $expired),
            self::Closed => $query->where(fn (Builder $closedQuery): Builder => $closedQuery
                ->whereIn('application_state', [AdmissionApplication::StateNotAdmitted, AdmissionApplication::StateWithdrawn])
                ->orWhereIn('id', $closed)),
            default => $query,
        };
    }

    public function count(string $queue): int
    {
        return $this->scope(AdmissionApplicationResource::getEloquentQuery(), $queue)->count();
    }

    /** @return list<int> */
    private function idsWith(string $status): array
    {
        return $this->deadlineIds[$status] ??= $this->deadlines->idsWith($status);
    }

    /** @return list<int> */
    private function readyApplicationIds(): array
    {
        return $this->readyApplicationIds ??= $this->readyApplicants->readyApplicationIds()->all();
    }
}
