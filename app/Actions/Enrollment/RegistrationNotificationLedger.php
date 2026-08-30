<?php

namespace App\Actions\Enrollment;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Mail\OfficialEnrollmentMail;
use App\Mail\RegistrationJourneyMail;
use App\Models\Assessment;
use App\Models\CourseDropRecord;
use App\Models\Enrollment;
use App\Models\EnrollmentAdjustment;
use App\Models\EnrollmentSeatReservation;
use App\Models\OperationalEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationNotificationLedger
{
    public function __construct(private readonly EnrollmentPaymentRequirementProjection $paymentRequirement) {}

    public function recordOfficialEnrollment(Enrollment $enrollment): OperationalEvent
    {
        $enrollment->loadMissing(['credentialUser', 'term', 'currentCorVersion', 'studentProfile']);

        return $this->record(
            $enrollment->credentialUser,
            OperationalEvent::TypeOfficialEnrollmentEmail,
            'cor:'.$enrollment->current_cor_version_id,
            $enrollment,
            [
                'term_label' => $enrollment->term?->label,
                'cor_version_id' => $enrollment->current_cor_version_id,
                'student_access_active' => $enrollment->studentProfile !== null,
            ],
        );
    }

    public function recordProposalReady(RegistrationProposalVersion $proposal): OperationalEvent
    {
        $proposal->loadMissing(['enrollment.credentialUser', 'enrollment.term']);
        $deadline = TermCalendarWindow::query()
            ->where('window_type', TermCalendarWindow::TypeEnrollment)
            ->whereHas('package', fn ($query) => $query
                ->where('term_id', $proposal->enrollment->term_id)
                ->where('state', TermCalendarPackage::StateActive))
            ->latest('closes_on')
            ->value('closes_on');

        return $this->record(
            $proposal->enrollment->credentialUser,
            OperationalEvent::TypeRegistrationProposalEmail,
            'proposal:'.$proposal->id.':'.$proposal->content_hash,
            $proposal,
            [
                'proposal_version' => $proposal->version,
                'material_revision' => $proposal->supersedes_version_id !== null,
                'term_label' => $proposal->enrollment->term?->label,
                'deadline' => $deadline,
            ],
        );
    }

    public function recordPaymentActionRequired(Assessment $assessment): OperationalEvent
    {
        $assessment->loadMissing(['enrollment.credentialUser', 'obligations']);
        $requirement = $this->paymentRequirement->forEnrollment($assessment->enrollment);
        $deadline = $assessment->obligations
            ->where('required_for_enrollment', true)
            ->sortBy('due_at')
            ->first()?->due_at?->toDateString();

        return $this->record(
            $assessment->enrollment->credentialUser,
            OperationalEvent::TypeRegistrationPaymentActionEmail,
            'assessment:'.$assessment->id.':'.$assessment->content_hash,
            $assessment,
            [
                'assessment_version' => $assessment->version,
                'assessment_id' => $assessment->id,
                'amount_due_now' => $requirement['balance'],
                'deadline' => $deadline,
            ],
        );
    }

    public function recordReservationRelease(EnrollmentSeatReservation $reservation): OperationalEvent
    {
        $reservation->loadMissing('enrollment.credentialUser');

        return $this->record(
            $reservation->enrollment->credentialUser,
            OperationalEvent::TypeRegistrationCaseExpiryEmail,
            'reservation:'.$reservation->id.':released',
            $reservation,
            ['reservation_id' => $reservation->id, 'released_at' => $reservation->released_at?->toIso8601String()],
        );
    }

    public function recordCaseExpiry(Enrollment $enrollment): OperationalEvent
    {
        $enrollment->loadMissing('credentialUser');

        return $this->record(
            $enrollment->credentialUser,
            OperationalEvent::TypeRegistrationCaseExpiryEmail,
            "case:{$enrollment->id}:".Enrollment::OutcomeNotEnrolled,
            $enrollment,
            [
                'enrollment_id' => $enrollment->id,
                'case_reference' => $enrollment->case_reference,
                'outcome' => $enrollment->canonical_outcome,
            ],
        );
    }

    public function recordAdjustment(EnrollmentAdjustment $adjustment): OperationalEvent
    {
        $adjustment->loadMissing('enrollment.credentialUser');
        $corVersionId = $adjustment->enrollment->current_cor_version_id;

        return $this->record(
            $adjustment->enrollment->credentialUser,
            OperationalEvent::TypeRegistrationAdjustmentEmail,
            "adjustment:{$adjustment->id}:cor:{$corVersionId}",
            $adjustment,
            ['adjustment_id' => $adjustment->id, 'cor_version_id' => $corVersionId, 'financial_effect' => $adjustment->financial_effect],
        );
    }

    public function recordCourseDrop(CourseDropRecord $drop): OperationalEvent
    {
        $drop->loadMissing('enrollment.credentialUser');
        $corVersionId = $drop->enrollment->current_cor_version_id;

        return $this->record(
            $drop->enrollment->credentialUser,
            OperationalEvent::TypeCourseDropEmail,
            "course-drop:{$drop->id}:cor:{$corVersionId}",
            $drop,
            ['course_drop_id' => $drop->id, 'course_enrollment_id' => $drop->course_enrollment_id, 'cor_version_id' => $corVersionId],
        );
    }

    public function recordContinuingWindow(
        StudentProfile $profile,
        TermCalendarPackage $package,
        TermCalendarWindow $window,
    ): OperationalEvent {
        $profile->loadMissing('user');
        $package->loadMissing('term');

        return $this->record(
            $profile->user,
            OperationalEvent::TypeEnrollmentWindowEmail,
            "calendar:{$package->id}:window:{$window->id}",
            $profile,
            [
                'term_id' => $package->term_id,
                'term_label' => $package->term?->label,
                'calendar_package_id' => $package->id,
                'window_id' => $window->id,
                'opens_on' => $window->opens_on->toDateString(),
                'closes_on' => $window->closes_on->toDateString(),
            ],
        );
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        $outcome = DB::transaction(function () use ($event, $actor): array {
            $locked = OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->event_type, OperationalEvent::registrationNotificationTypes(), true)
                || $locked->status !== OperationalEvent::StatusFailed) {
                throw ValidationException::withMessages(['notification' => 'Only a failed registration notification may be resent.']);
            }
            if ((int) $locked->user_id !== (int) $actor->id && ! $actor->hasRole(User::StaffRoleRegistrar)) {
                abort(403);
            }
            $recipient = User::query()->findOrFail($locked->user_id);
            $locked->update([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'diagnostics' => null,
            ]);

            return [$locked->fresh(), $recipient];
        }, attempts: 3);
        $this->queue($outcome[0], $outcome[1]);

        return $outcome[0];
    }

    /** @param array<string, mixed> $payload */
    private function record(
        User $recipient,
        string $eventType,
        string $sourceKey,
        Model $related,
        array $payload,
    ): OperationalEvent {
        $event = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "registration:{$eventType}:{$sourceKey}:user:{$recipient->id}",
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => $eventType,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => ['user_id' => $recipient->id, 'email' => $recipient->email],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'related_record_type' => $related::class,
                'related_record_id' => $related->getKey(),
                'payload' => $payload,
            ],
        );

        if ($event->wasRecentlyCreated) {
            $this->queue($event, $recipient);
        }

        return $event;
    }

    private function queue(OperationalEvent $event, User $recipient): void
    {
        try {
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Recipient email is invalid.');
            }

            if ($event->event_type === OperationalEvent::TypeOfficialEnrollmentEmail) {
                $payload = is_array($event->payload) ? $event->payload : [];
                Mail::to($recipient)->queue(new OfficialEnrollmentMail(
                    operationalEventId: $event->id,
                    recipientName: $recipient->getFilamentName(),
                    termLabel: (string) ($payload['term_label'] ?? 'Current Term'),
                    corUrl: route('cor.print', ['enrollment' => $event->related_record_id]),
                ));

                return;
            }

            $content = $this->mailContent($event);
            Mail::to($recipient)->queue(new RegistrationJourneyMail(
                operationalEventId: $event->id,
                operationalEventType: $event->event_type,
                recipientName: $recipient->getFilamentName(),
                subjectLine: $content['subject'],
                heading: $content['heading'],
                messageLine: $content['message'],
                actionLabel: $content['action_label'],
                actionUrl: $recipient->hasRole('student')
                    ? route('filament.student.pages.enrollment')
                    : route('filament.applicant.pages.dashboard'),
            ));
        } catch (Throwable $exception) {
            $event->update([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'failed_at' => now(),
                'diagnostics' => [
                    'reason' => 'Mail could not be queued.',
                    'exception_type' => class_basename($exception),
                ],
            ]);
        }
    }

    /** @return array{subject:string,heading:string,message:string,action_label:string} */
    private function mailContent(OperationalEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return match ($event->event_type) {
            OperationalEvent::TypeEnrollmentWindowEmail => [
                'subject' => 'Enrollment is available for your Term',
                'heading' => 'Your enrollment window is open',
                'message' => sprintf(
                    'Enrollment for %s is open through %s. Open Enrollment to review the authoritative checkpoints and next action.',
                    $payload['term_label'] ?? 'your exact Term',
                    $payload['closes_on'] ?? 'the published deadline',
                ),
                'action_label' => 'Open Enrollment',
            ],
            OperationalEvent::TypeRegistrationProposalEmail => [
                'subject' => 'Your registration proposal is ready',
                'heading' => 'Review your proposed subjects',
                'message' => sprintf(
                    'The Registrar prepared proposal version %s for %s. Review its subjects, schedule, consequences, and confirmation by %s.',
                    $payload['proposal_version'] ?? 'current',
                    $payload['term_label'] ?? 'your exact Term',
                    $payload['deadline'] ?? 'the published deadline',
                ),
                'action_label' => 'Review Proposal',
            ],
            OperationalEvent::TypeRegistrationPaymentActionEmail => [
                'subject' => 'Registration finance action is required',
                'heading' => 'Review your registration finance requirement',
                'message' => sprintf(
                    'Accounting recorded PHP %s due for enrollment by %s. Open Enrollment or Finance to see the authoritative requirement and safe next action.',
                    $payload['amount_due_now'] ?? 'an amount',
                    $payload['deadline'] ?? 'the published deadline',
                ),
                'action_label' => 'Review Requirement',
            ],
            OperationalEvent::TypeRegistrationCaseExpiryEmail => [
                'subject' => 'Your registration reservation was released',
                'heading' => 'A reservation deadline passed',
                'message' => 'The reservation was released without deleting your case or finance history. Open Enrollment for the current owner and recovery path.',
                'action_label' => 'Review Registration',
            ],
            OperationalEvent::TypeRegistrationAdjustmentEmail => [
                'subject' => 'Your official enrollment was adjusted',
                'heading' => 'An authorized enrollment adjustment was recorded',
                'message' => 'Open Enrollment to review the successor course and COR version. Any Accounting review remains separately identified.',
                'action_label' => 'Review Adjustment',
            ],
            OperationalEvent::TypeCourseDropEmail => [
                'subject' => 'Your official Course Drop was recorded',
                'heading' => 'An authorized Course Drop was recorded',
                'message' => 'Open Enrollment to review the updated official courses and COR version. Accounting effects are not inferred by this message.',
                'action_label' => 'Review Course Drop',
            ],
            default => throw new \RuntimeException('Unsupported registration notification type.'),
        };
    }
}
