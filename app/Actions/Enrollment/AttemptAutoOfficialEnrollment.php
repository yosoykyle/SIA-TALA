<?php

namespace App\Actions\Enrollment;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttemptAutoOfficialEnrollment
{
    public const EventDeferred = 'AutoFinalizationDeferred';

    public const EventFailed = 'AutoFinalizationFailed';

    public function __construct(
        private readonly EnrollmentPaymentRequirementProjection $paymentRequirement,
        private readonly RegistrationReadinessQuery $readiness,
        private readonly RegistrationPlacementValidator $placementValidator,
        private readonly StudentUnitLoadService $unitLoad,
        private readonly FinalizeOfficialEnrollment $finalizer,
    ) {}

    public function attempt(int $enrollmentId, AutoFinalizationTrigger $trigger): AutoFinalizationResult
    {
        $enrollment = Enrollment::query()
            ->with(['termAccount', 'currentProposalVersion.confirmation', 'currentProposalVersion.items.reservation', 'credentialUser', 'term'])
            ->find($enrollmentId);

        if (! $enrollment instanceof Enrollment) {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusSkipped);
        }

        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
            && $enrollment->current_cor_version_id !== null) {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusAlreadyOfficial, $enrollment);
        }

        if ($enrollment->canonical_outcome !== Enrollment::OutcomeInProgress) {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusSkipped, $enrollment);
        }

        $finance = $this->paymentRequirement->forEnrollment($enrollment);
        if ($finance['state'] !== 'Cleared') {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusNotCleared, $enrollment);
        }

        try {
            $official = $this->finalizer->executeAutomatic($enrollment, $trigger);

            return new AutoFinalizationResult(
                status: AutoFinalizationResult::StatusFinalized,
                enrollment: $official,
            );
        } catch (ValidationException $exception) {
            return $this->handleDeferred($enrollmentId, $trigger, $exception);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedFailure($enrollmentId, $trigger, $exception);
        }
    }

    private function handleDeferred(
        int $enrollmentId,
        AutoFinalizationTrigger $trigger,
        ValidationException $exception,
    ): AutoFinalizationResult {
        $fresh = Enrollment::query()
            ->with(['currentProposalVersion.confirmation', 'currentProposalVersion.items.reservation', 'credentialUser', 'term'])
            ->find($enrollmentId);

        if (! $fresh instanceof Enrollment) {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusSkipped);
        }

        if ($fresh->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
            && $fresh->current_cor_version_id !== null) {
            return new AutoFinalizationResult(AutoFinalizationResult::StatusAlreadyOfficial, $fresh);
        }

        $readiness = $this->readiness->for($fresh);
        $proposal = $fresh->currentProposalVersion;

        if ($this->isUnconfirmedOnly($fresh, $proposal, $readiness)) {
            if ($trigger->triggerType !== AutoFinalizationTrigger::TypeZeroDueAssessmentCreated) {
                $this->recordCaseEvent(
                    enrollment: $fresh,
                    eventType: self::EventDeferred,
                    reason: 'Auto-finalization deferred: waiting for learner confirmation of proposed subjects.',
                    authorityReference: $trigger->triggerReference,
                );
            }

            return new AutoFinalizationResult(
                status: AutoFinalizationResult::StatusDeferredUnconfirmed,
                enrollment: $fresh,
                blockers: ['Confirmed Registration Proposal'],
            );
        }

        $blockers = $readiness['blockers'];
        if ($blockers === []) {
            $blockers = array_values(array_filter(array_map(
                static fn (mixed $messages): string => is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages,
                $exception->errors(),
            )));
        }
        if ($blockers === []) {
            $blockers = [$exception->getMessage()];
        }

        $this->recordCaseEvent(
            enrollment: $fresh,
            eventType: self::EventDeferred,
            reason: 'Auto-finalization deferred: '.implode('; ', $blockers).'.',
            authorityReference: $trigger->triggerReference,
        );

        $alerted = $this->notifyRegistrarsOfBlockedEnrollment($fresh, $blockers);

        return new AutoFinalizationResult(
            status: AutoFinalizationResult::StatusDeferredBlocked,
            enrollment: $fresh,
            blockers: $blockers,
            alertedRegistrars: $alerted,
        );
    }

    private function handleUnexpectedFailure(
        int $enrollmentId,
        AutoFinalizationTrigger $trigger,
        Throwable $exception,
    ): AutoFinalizationResult {
        Log::error('Automatic official enrollment failed unexpectedly after finance clearance.', [
            'enrollment_id' => $enrollmentId,
            'trigger_type' => $trigger->triggerType,
            'trigger_reference' => $trigger->triggerReference,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        $fresh = Enrollment::query()->with(['credentialUser', 'term'])->find($enrollmentId);

        if ($fresh instanceof Enrollment) {
            try {
                $this->recordCaseEvent(
                    enrollment: $fresh,
                    eventType: self::EventFailed,
                    reason: sprintf(
                        'Auto-finalization failed unexpectedly (%s). Payment remains committed; Registrar review required.',
                        class_basename($exception),
                    ),
                    authorityReference: $trigger->triggerReference,
                );
            } catch (Throwable $loggingException) {
                Log::error('Failed to record AutoFinalizationFailed case event.', [
                    'enrollment_id' => $enrollmentId,
                    'exception' => $loggingException::class,
                    'message' => $loggingException->getMessage(),
                ]);
            }

            $alerted = $this->notifyRegistrarsOfBlockedEnrollment(
                $fresh,
                ['Unexpected system error during auto-finalization'],
            );

            return new AutoFinalizationResult(
                status: AutoFinalizationResult::StatusFailed,
                enrollment: $fresh,
                errorMessage: $exception->getMessage(),
                alertedRegistrars: $alerted,
            );
        }

        return new AutoFinalizationResult(
            status: AutoFinalizationResult::StatusFailed,
            errorMessage: $exception->getMessage(),
        );
    }

    /** @param array<string, mixed> $readiness */
    private function isUnconfirmedOnly(
        Enrollment $enrollment,
        ?RegistrationProposalVersion $proposal,
        array $readiness,
    ): bool {
        if (! $proposal instanceof RegistrationProposalVersion
            || $proposal->state !== RegistrationProposalVersion::StateIssued
            || $proposal->confirmation !== null) {
            return false;
        }

        if (! ($readiness['identity'] ?? false)
            || ! ($readiness['eligibility'] ?? false)
            || ! ($readiness['finance'] ?? false)) {
            return false;
        }

        try {
            $this->placementValidator->assertCurrent($enrollment, $proposal, requireConfirmation: false);
            $this->unitLoad->assertProposalPermitted($enrollment, $proposal);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function recordCaseEvent(
        Enrollment $enrollment,
        string $eventType,
        string $reason,
        ?string $authorityReference,
    ): void {
        $latest = RegistrationCaseEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->orderByDesc('sequence')
            ->first();

        if ($latest instanceof RegistrationCaseEvent
            && $latest->event_type === $eventType
            && $latest->reason === $reason
            && $latest->authority_reference === $authorityReference) {
            return;
        }

        RegistrationCaseEvent::query()->create([
            'enrollment_id' => $enrollment->id,
            'sequence' => (($latest?->sequence ?? 0) + 1),
            'event_type' => $eventType,
            'from_outcome' => $enrollment->canonical_outcome,
            'to_outcome' => $enrollment->canonical_outcome,
            'reason' => $reason,
            'authority_reference' => $authorityReference,
            'actor_id' => null,
            'recorded_at' => now(),
        ]);
    }

    /** @param list<string> $blockers */
    private function notifyRegistrarsOfBlockedEnrollment(Enrollment $enrollment, array $blockers): bool
    {
        $registrars = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleRegistrar))
            ->get();

        if ($registrars->isEmpty()) {
            return false;
        }

        $learnerName = $enrollment->credentialUser?->getFilamentName() ?? 'Learner';
        $termLabel = $enrollment->term?->label ?? 'current term';
        $summary = implode(', ', $blockers);

        $enrollmentUrl = Route::has('filament.admin.resources.enrollments.view')
            ? route('filament.admin.resources.enrollments.view', ['record' => $enrollment->id])
            : null;

        $notification = Notification::make()
            ->title('Payment cleared — enrollment issues require resolution')
            ->body("{$learnerName} ({$termLabel}) cleared the enrollment fee, but automatic enrollment was blocked: {$summary}.")
            ->warning();

        if ($enrollmentUrl !== null) {
            $notification->actions([
                Action::make('reviewEnrollment')
                    ->label('Review enrollment')
                    ->url($enrollmentUrl),
            ]);
        }

        $notification->sendToDatabase($registrars);

        return true;
    }
}
