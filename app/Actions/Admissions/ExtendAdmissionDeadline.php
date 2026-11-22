<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Queries\Admissions\AdmissionDeadlineStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Registrar moves one Applicant's paper or payment deadline. On an expired application this re-admits it:
 * the Applicant returns to the pending step. A closed admission period cannot be reopened this way.
 */
class ExtendAdmissionDeadline
{
    public function __construct(
        private readonly AdmissionDeadlineStatus $deadlines,
        private readonly AdmissionNotificationLedger $notifications,
    ) {}

    public function execute(AdmissionApplication $application, User $actor, string $kind, CarbonInterface $newDeadline, string $reason): AdmissionApplicationEvent
    {
        if (! $actor->can('review', $application)) {
            throw new AuthorizationException('Only an authorized Registrar may extend an admission deadline.');
        }

        $validated = Validator::make(
            ['kind' => $kind, 'reason' => trim($reason)],
            [
                'kind' => ['required', Rule::in([AdmissionDeadlineStatus::KindRequirements, AdmissionDeadlineStatus::KindPayment])],
                'reason' => ['required', 'string', 'max:1000'],
            ],
        )->validate();
        $newDeadline = CarbonImmutable::instance($newDeadline);

        return DB::transaction(function () use ($application, $actor, $validated, $newDeadline): AdmissionApplicationEvent {
            $locked = AdmissionApplication::query()->lockForUpdate()->with('admissionCycle')->findOrFail($application->id);

            if ($locked->application_state !== AdmissionApplication::StateAdmitted) {
                throw ValidationException::withMessages(['deadline' => 'Only an admitted application has paper and payment deadlines.']);
            }

            if ($this->deadlines->for($locked)['status'] === AdmissionDeadlineStatus::Closed) {
                throw ValidationException::withMessages(['deadline' => 'The admission period has ended. A closed application cannot be re-admitted.']);
            }

            if (! $newDeadline->isFuture()) {
                throw ValidationException::withMessages(['deadline' => 'Choose a new deadline in the future.']);
            }

            $periodEndsAt = $locked->admissionCycle?->period_ends_at;
            if ($periodEndsAt !== null && $newDeadline->greaterThan($periodEndsAt)) {
                throw ValidationException::withMessages(['deadline' => 'The new deadline must fall on or before the end of the admission period.']);
            }

            $field = $validated['kind'] === AdmissionDeadlineStatus::KindRequirements ? 'requirements_due_at' : 'payment_due_at';
            $previous = $validated['kind'] === AdmissionDeadlineStatus::KindRequirements
                ? $this->deadlines->requirementsDeadline($locked)
                : $this->deadlines->paymentDeadline($locked);
            $wasExpired = $this->deadlines->for($locked)['status'] === AdmissionDeadlineStatus::Expired;
            $locked->forceFill([$field => $newDeadline])->save();

            $event = $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeDeadlineExtended,
                'event_key' => 'deadline-extended:'.$locked->id.':'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => null,
                'source_id' => null,
                'payload' => [
                    'kind' => $validated['kind'],
                    'previous_deadline' => $previous?->toIso8601String(),
                    'new_deadline' => $newDeadline->toIso8601String(),
                    'readmitted' => $wasExpired,
                    'reason' => $validated['reason'],
                ],
                'occurred_at' => now(),
            ]);

            $label = $validated['kind'] === AdmissionDeadlineStatus::KindRequirements ? 'physical requirements' : 'enrollment payment';
            $this->notifications->queuePending(
                $locked,
                $locked->user()->firstOrFail(),
                eventType: OperationalEvent::TypeAdmissionProcessRemarkSent,
                sourceKey: 'deadline:'.$event->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'step' => $wasExpired ? 'Application re-admitted' : 'Deadline extended',
                    'remark' => 'Your '.$label.' deadline is now '.$newDeadline->timezone((string) config('app.display_timezone'))->format('M j, Y g:i A').' (Asia/Manila). '.$validated['reason'],
                ],
            );

            return $event;
        }, attempts: 3);
    }
}
