<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Regular (standard-curriculum) cases are prepared automatically when they open.
 * When that first attempt is blocked, this explains why and runs the same
 * automatic preparation again, either on the Registrar's request or after the
 * term's classes are published.
 */
class RetryStandardRegistrationPreparation
{
    public const PreparedReason = 'Standard subjects prepared automatically.';

    private const StartedReason = 'Registration Case started from authoritative source facts.';

    public function __construct(private readonly PrepareStandardRegistration $prepare) {}

    public function isWaiting(Enrollment $enrollment): bool
    {
        return $enrollment->canonical_outcome === Enrollment::OutcomeInProgress
            && $enrollment->selection_basis === Enrollment::SelectionStandardCurriculum
            && $enrollment->current_proposal_version_id === null;
    }

    /**
     * What currently stops automatic preparation. `resolved` is true when a fresh
     * check finds no blocker, so a retry is expected to succeed.
     *
     * @return array{reason: string, resolved: bool}|null
     */
    public function blocker(Enrollment $enrollment): ?array
    {
        if (! $this->isWaiting($enrollment)) {
            return null;
        }

        try {
            $this->prepare->sectionIds($enrollment);
        } catch (ValidationException $exception) {
            return ['reason' => (string) collect($exception->errors())->flatten()->first(), 'resolved' => false];
        } catch (Throwable) {
            return ['reason' => $this->recordedReason($enrollment) ?? 'The standard subjects for this term could not be worked out.', 'resolved' => false];
        }

        return ['reason' => $this->recordedReason($enrollment) ?? 'The last automatic attempt did not finish.', 'resolved' => true];
    }

    public function retry(Enrollment $enrollment, User $actor): Enrollment
    {
        $prepared = $this->prepare->execute($enrollment, $actor);

        if ($this->wasSentToStudent($prepared)) {
            $prepared->update(['status_reason' => self::PreparedReason]);
        }

        return $prepared->refresh();
    }

    public function wasSentToStudent(Enrollment $enrollment): bool
    {
        // #110: regular subjects are confirmed automatically when sent, so Confirmed also counts.
        return in_array($enrollment->currentProposalVersion?->state, [RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true);
    }

    /**
     * Retry every waiting regular case of a term, e.g. after its classes are published.
     *
     * @return array{prepared: int, waiting: int}
     */
    public function retryForTerm(int $termId, User $actor): array
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            return ['prepared' => 0, 'waiting' => 0];
        }

        $prepared = 0;
        $waiting = 0;

        Enrollment::query()
            ->where('term_id', $termId)
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->where('selection_basis', Enrollment::SelectionStandardCurriculum)
            ->whereNull('current_proposal_version_id')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($actor, &$prepared, &$waiting): void {
                try {
                    $result = $this->retry($enrollment, $actor);
                } catch (Throwable $exception) {
                    report($exception);
                    $waiting++;

                    return;
                }

                $this->wasSentToStudent($result) ? $prepared++ : $waiting++;
            });

        return ['prepared' => $prepared, 'waiting' => $waiting];
    }

    /** Retry the term's waiting regular cases once the surrounding publish transaction commits. */
    public function afterCommit(int $termId, User $actor): void
    {
        DB::afterCommit(fn (): array => $this->retryForTerm($termId, $actor));
    }

    private function recordedReason(Enrollment $enrollment): ?string
    {
        $reason = trim((string) $enrollment->status_reason);

        return $reason === '' || in_array($reason, [self::StartedReason, self::PreparedReason], true) ? null : $reason;
    }
}
