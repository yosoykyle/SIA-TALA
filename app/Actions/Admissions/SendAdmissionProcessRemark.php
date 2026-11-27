<?php

namespace App\Actions\Admissions;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tells the Applicant what is blocking one step of their admission process, in the Registrar's own words.
 */
class SendAdmissionProcessRemark
{
    public const StepReview = 'review';

    public const StepEvidence = 'evidence';

    public const StepScreening = 'screening';

    public const StepClearance = 'clearance';

    public const StepReadiness = 'readiness';

    public const StepPayment = 'payment';

    public const StepEnrolled = 'enrolled';

    public function __construct(private readonly AdmissionNotificationLedger $notifications) {}

    /** @return array<string, string> */
    public static function stepLabels(): array
    {
        return [
            self::StepReview => 'Start review',
            self::StepEvidence => 'Preliminary evidence review',
            self::StepScreening => 'Application screening',
            self::StepClearance => 'Enrollment clearance',
            self::StepReadiness => 'Ready for enrollment',
            self::StepPayment => 'Payment verification',
            self::StepEnrolled => 'Officially enrolled',
        ];
    }

    public function execute(AdmissionApplication $application, User $actor, string $step, ?string $remark): AdmissionApplicationEvent
    {
        if (! $actor->can('review', $application)) {
            throw new AuthorizationException('Only an authorized Registrar may notify the Applicant about a process step.');
        }

        $validated = Validator::make(
            ['step' => $step, 'remark' => trim((string) $remark)],
            [
                'step' => ['required', Rule::in(array_keys(self::stepLabels()))],
                'remark' => ['required', 'string', 'max:1500'],
            ],
            ['remark.required' => 'Write a remark telling the Applicant what is blocking this step or what to do next.'],
        )->validate();

        return DB::transaction(function () use ($application, $actor, $validated): AdmissionApplicationEvent {
            $locked = AdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if (in_array($locked->application_state, [AdmissionApplication::StateDraft, AdmissionApplication::StateWithdrawn], true)) {
                throw ValidationException::withMessages(['remark' => 'This application is not active, so no process update can be sent.']);
            }

            $event = $locked->events()->create([
                'event_type' => AdmissionApplicationEvent::TypeProcessRemarkSent,
                'event_key' => 'process-remark:'.$locked->id.':'.Str::uuid(),
                'actor_id' => $actor->id,
                'source_type' => null,
                'source_id' => null,
                'payload' => ['step' => $validated['step'], 'remark' => $validated['remark']],
                'occurred_at' => now(),
            ]);

            $this->notifications->queuePending(
                $locked,
                $locked->user()->firstOrFail(),
                eventType: OperationalEvent::TypeAdmissionProcessRemarkSent,
                sourceKey: 'remark:'.$event->id,
                safePayload: [
                    'application_reference' => $locked->application_reference,
                    'step' => self::stepLabels()[$validated['step']],
                    'remark' => $validated['remark'],
                ],
            );

            return $event;
        }, attempts: 3);
    }
}
