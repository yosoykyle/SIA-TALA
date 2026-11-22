<?php

namespace App\Queries\Admissions;

use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\RegistrarEnrollmentClearance;
use Carbon\CarbonInterface;

/**
 * Derives the QA workflow's Expired and Closed situations from deadlines, without a stored state.
 *
 * Closed: the cycle's admission period ended before the application was finished. Final.
 * Expired: an admitted applicant missed the physical-papers deadline before clearance, or a cleared applicant
 * missed the payment deadline before Accounting verified a payment. Recoverable: the Registrar extends the deadline.
 */
class AdmissionDeadlineStatus
{
    public const Expired = 'expired';

    public const Closed = 'closed';

    public const KindRequirements = 'requirements';

    public const KindPayment = 'payment';

    public function __construct(private readonly ReadyApplicantProjectionQuery $readiness) {}

    /**
     * @return array{status: string|null, kind: string|null, deadline: CarbonInterface|null, reason: string|null}
     */
    public function for(AdmissionApplication $application): array
    {
        $none = ['status' => null, 'kind' => null, 'deadline' => null, 'reason' => null];
        $cycle = $application->admissionCycle;

        if (! in_array($application->application_state, [
            AdmissionApplication::StateDraft,
            AdmissionApplication::StateSubmitted,
            AdmissionApplication::StateActionNeeded,
            AdmissionApplication::StateAdmitted,
        ], true)) {
            return $none;
        }

        $enrollment = Enrollment::query()
            ->where('admission_application_id', $application->id)
            ->whereNull('cancelled_at')
            ->latest('id')
            ->first();

        if ($enrollment?->officially_enrolled_at !== null) {
            return $none;
        }

        if ($cycle?->period_ends_at !== null && $cycle->period_ends_at->isPast()) {
            return [
                'status' => self::Closed,
                'kind' => null,
                'deadline' => $cycle->period_ends_at,
                'reason' => 'The admission period ended on '.$this->format($cycle->period_ends_at).' before this application was finished.',
            ];
        }

        if ($application->application_state !== AdmissionApplication::StateAdmitted) {
            return $none;
        }

        $projection = $this->readiness->forApplication($application);

        if ($projection['clearance_result'] !== RegistrarEnrollmentClearance::ResultCleared) {
            $deadline = $this->requirementsDeadline($application);

            return $deadline !== null && $deadline->isPast()
                ? [
                    'status' => self::Expired,
                    'kind' => self::KindRequirements,
                    'deadline' => $deadline,
                    'reason' => 'The physical requirements were not completed by '.$this->format($deadline).'.',
                ]
                : $none;
        }

        $deadline = $this->paymentDeadline($application);
        $paymentVerified = $enrollment?->termAccount !== null && $enrollment->termAccount->payments()
            ->where('state', Payment::StatePosted)
            ->whereNotNull('verified_at')
            ->exists();

        return $deadline !== null && $deadline->isPast() && ! $paymentVerified
            ? [
                'status' => self::Expired,
                'kind' => self::KindPayment,
                'deadline' => $deadline,
                'reason' => 'The enrollment payment was not verified by '.$this->format($deadline).'.',
            ]
            : $none;
    }

    public function requirementsDeadline(AdmissionApplication $application): ?CarbonInterface
    {
        return $application->requirements_due_at ?? $application->admissionCycle?->requirements_due_at;
    }

    public function paymentDeadline(AdmissionApplication $application): ?CarbonInterface
    {
        return $application->payment_due_at ?? $application->admissionCycle?->payment_due_at;
    }

    /** @return list<int> */
    public function idsWith(string $status): array
    {
        return AdmissionApplication::query()
            ->whereIn('application_state', [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateAdmitted,
            ])
            ->where(fn ($query) => $query
                ->whereHas('admissionCycle', fn ($cycle) => $cycle->whereNotNull('period_ends_at')
                    ->orWhereNotNull('requirements_due_at')
                    ->orWhereNotNull('payment_due_at'))
                ->orWhereNotNull('requirements_due_at')
                ->orWhereNotNull('payment_due_at'))
            ->with('admissionCycle')
            ->get()
            ->filter(fn (AdmissionApplication $application): bool => $this->for($application)['status'] === $status)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function format(CarbonInterface $moment): string
    {
        return $moment->copy()->timezone((string) config('app.display_timezone'))->format('M j, Y g:i A');
    }
}
