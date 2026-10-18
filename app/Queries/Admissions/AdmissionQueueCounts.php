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

    public const Closed = 'history';

    /** @var list<int>|null */
    private ?array $readyApplicationIds = null;

    public function __construct(private readonly ReadyApplicantProjectionQuery $readyApplicants) {}

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
        return [self::NeedsReview, self::RegistrarClearance];
    }

    public function scope(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            self::NeedsReview => $query->where('application_state', AdmissionApplication::StateSubmitted),
            self::WaitingForApplicant => $query->where('application_state', AdmissionApplication::StateActionNeeded),
            self::RegistrarClearance => $query->where('application_state', AdmissionApplication::StateAdmitted)
                ->whereNotIn('id', $this->readyApplicationIds()),
            self::ReadyForEnrollment => $query->whereIn('id', $this->readyApplicationIds()),
            self::Closed => $query->whereIn('application_state', [AdmissionApplication::StateNotAdmitted, AdmissionApplication::StateWithdrawn]),
            default => $query,
        };
    }

    public function count(string $queue): int
    {
        return $this->scope(AdmissionApplicationResource::getEloquentQuery(), $queue)->count();
    }

    /** @return list<int> */
    private function readyApplicationIds(): array
    {
        return $this->readyApplicationIds ??= $this->readyApplicants->readyApplicationIds()->all();
    }
}
