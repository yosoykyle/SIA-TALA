<?php

namespace App\Actions\Enrollment;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Regular (standard-curriculum) cases are prepared automatically when they open.
 * When that first attempt is blocked, this explains why and runs the same
 * automatic preparation again, either on the Registrar's request, after the
 * term's classes are published, or after the term's Fee Plan is published (#117).
 */
class RetryStandardRegistrationPreparation
{
    public const PreparedReason = 'Standard subjects prepared automatically.';

    public const MissingFeePlanReason = 'Accounting has not published the Fee Plan for this program and term yet.';

    /** How an automatic attempt records that the assessment could not be made yet. */
    public const AssessmentUnavailablePrefix = 'Unavailable:';

    private const StartedReason = 'Registration Case started from authoritative source facts.';

    public function __construct(private readonly PrepareStandardRegistration $prepare) {}

    /**
     * A regular case is waiting when it has no subjects yet, or when TALA's automatic attempt
     * reserved its initial subjects but stopped because no assessment could be made (#117).
     * The automatic attempt runs as whoever started the case, so a Draft someone else
     * prepared by hand (for example the Registrar on a student-started case) is left to them.
     */
    public function isWaiting(Enrollment $enrollment): bool
    {
        if ($enrollment->canonical_outcome !== Enrollment::OutcomeInProgress
            || $enrollment->selection_basis !== Enrollment::SelectionStandardCurriculum) {
            return false;
        }

        if ($enrollment->current_proposal_version_id === null) {
            return true;
        }

        $proposal = $enrollment->currentProposalVersion;

        return $proposal instanceof RegistrationProposalVersion
            && $proposal->purpose === RegistrationProposalVersion::PurposeInitial
            && $proposal->state === RegistrationProposalVersion::StateDraft
            && str_starts_with((string) $enrollment->status_reason, self::AssessmentUnavailablePrefix)
            && (int) $proposal->prepared_by === (int) $enrollment->started_by
            && ! $enrollment->termAccount()->whereHas('assessments', fn (Builder $assessment): Builder => $this->assessesProposal($assessment, (int) $proposal->id))->exists();
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

        if ($this->waitingForFeePlan($enrollment)) {
            return ['reason' => self::MissingFeePlanReason, 'resolved' => false];
        }

        if ($enrollment->current_proposal_version_id !== null) {
            return ['reason' => $this->recordedReason($enrollment) ?? 'The reserved subjects were not sent to the student.', 'resolved' => true];
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

    /** True when a waiting regular case has reserved subjects but its program has no published Fee Plan for the term. */
    public function waitingForFeePlan(Enrollment $enrollment): bool
    {
        if (! $this->isWaiting($enrollment) || $enrollment->current_proposal_version_id === null) {
            return false;
        }

        $programId = $this->programId($enrollment);

        return $programId === null || ! FeePlan::query()
            ->where('program_id', $programId)
            ->where('term_id', $enrollment->term_id)
            ->where('state', FeePlan::StatePublished)
            ->exists();
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

        return $this->retryEach($this->waitingQuery($termId), fn (): User => $actor);
    }

    /**
     * Retry the waiting regular cases of the Fee Plan's program and term (#117). Each case runs as
     * its own student, who may already prepare, reserve and assess a standard case automatically.
     *
     * @return array{prepared: int, waiting: int}
     */
    public function retryForFeePlan(FeePlan $feePlan): array
    {
        if ($feePlan->state !== FeePlan::StatePublished) {
            return ['prepared' => 0, 'waiting' => 0];
        }

        $programId = (int) $feePlan->program_id;
        $query = $this->waitingQuery((int) $feePlan->term_id)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('admissionApplication', fn (Builder $application): Builder => $application->where('program_id', $programId))
                ->orWhere(fn (Builder $student): Builder => $student
                    ->whereNull('admission_application_id')
                    ->whereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('program_id', $programId))));

        return $this->retryEach($query, fn (Enrollment $enrollment): ?User => $enrollment->credentialUser);
    }

    /** Retry the term's waiting regular cases once the surrounding publish transaction commits. */
    public function afterCommit(int $termId, User $actor): void
    {
        DB::afterCommit(fn (): array => $this->retryForTerm($termId, $actor));
    }

    /** Retry the Fee Plan's waiting regular cases once the surrounding publish transaction commits (#117). */
    public function afterFeePlanCommit(FeePlan $feePlan): void
    {
        DB::afterCommit(fn (): array => $this->retryForFeePlan($feePlan->fresh()));
    }

    /** @return Builder<Enrollment> */
    private function waitingQuery(int $termId): Builder
    {
        return Enrollment::query()
            ->where('term_id', $termId)
            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->where('selection_basis', Enrollment::SelectionStandardCurriculum)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('current_proposal_version_id')
                ->orWhere(fn (Builder $draft): Builder => $draft
                    ->where('status_reason', 'like', self::AssessmentUnavailablePrefix.'%')
                    ->whereHas('currentProposalVersion', fn (Builder $proposal): Builder => $proposal
                        ->whereColumn('registration_proposal_versions.prepared_by', 'enrollments.started_by')
                        ->where('purpose', RegistrationProposalVersion::PurposeInitial)
                        ->where('state', RegistrationProposalVersion::StateDraft))
                    ->whereDoesntHave('termAccount.assessments', fn (Builder $assessment): Builder => $this->assessesProposal($assessment))))
            ->orderBy('id');
    }

    /**
     * An assessed Draft stopped for another reason; the Registrar sends it from the Draft view (#103).
     *
     * @param  Builder<Assessment>  $assessment
     * @return Builder<Assessment>
     */
    private function assessesProposal(Builder $assessment, ?int $proposalId = null): Builder
    {
        $assessment->where('state', Assessment::StateActive);

        return $proposalId === null
            ? $assessment->whereColumn('source_proposal_version_id', 'enrollments.current_proposal_version_id')
            : $assessment->where('source_proposal_version_id', $proposalId);
    }

    /**
     * @param  Builder<Enrollment>  $query
     * @param  callable(Enrollment): ?User  $actorFor
     * @return array{prepared: int, waiting: int}
     */
    private function retryEach(Builder $query, callable $actorFor): array
    {
        $prepared = 0;
        $waiting = 0;

        $query->each(function (Enrollment $enrollment) use ($actorFor, &$prepared, &$waiting): void {
            $actor = $actorFor($enrollment);
            if (! $actor instanceof User || ! $actor->canAuthenticate()) {
                $waiting++;

                return;
            }

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

    private function programId(Enrollment $enrollment): ?int
    {
        // Same order as the assessment: the admission application's program, then the student record's.
        $programId = $enrollment->admissionApplication()->value('program_id')
            ?? $enrollment->studentProfile()->value('program_id');

        return $programId === null ? null : (int) $programId;
    }

    private function recordedReason(Enrollment $enrollment): ?string
    {
        $reason = trim((string) $enrollment->status_reason);

        return $reason === '' || in_array($reason, [self::StartedReason, self::PreparedReason], true) ? null : $reason;
    }
}
