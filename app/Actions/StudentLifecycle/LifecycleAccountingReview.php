<?php

namespace App\Actions\StudentLifecycle;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\StudentLifecycleChange;
use App\Models\TermAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LifecycleAccountingReview
{
    public const Pending = 'Pending';

    public const NoAccountChange = 'NoAccountChange';

    public const FinancialActionRecorded = 'FinancialActionRecorded';

    /** @return array<string, string> */
    public static function outcomes(bool $correcting = false): array
    {
        return [self::NoAccountChange => 'No account change required', self::FinancialActionRecorded => 'Authorized financial action recorded',
            ...($correcting ? [self::Pending => 'Return to pending'] : [])];
    }

    /** @return Builder<OperationalEvent> */
    public function roots(): Builder
    {
        return OperationalEvent::query()->where('event_domain', OperationalEvent::DomainOperations)
            ->where('integration', OperationalEvent::IntegrationAcademicRecords)
            ->where('event_type', OperationalEvent::TypeLifecycleAccountingReview)
            ->where('related_record_type', StudentLifecycleChange::class)
            ->whereRaw("external_id = CONCAT('lifecycle-accounting-review:', related_record_id)");
    }

    /** @return Builder<OperationalEvent> */
    public function forEnrollment(Enrollment $case, bool $pending = true): Builder
    {
        $latestResults = $this->results()->selectRaw('MAX(id)')->groupBy('related_record_id');
        $reviewedEvents = $this->results()->whereIn('id', $latestResults)
            ->whereIn('payload->outcome', [self::NoAccountChange, self::FinancialActionRecorded])->select('related_record_id');

        return $this->roots()->where('payload->enrollment_id', $case->id)
            ->where('payload->student_profile_id', $case->student_profile_id)
            ->where(function (Builder $query) use ($case): void {
                $query->whereHas('lifecycleReviewSource', fn (Builder $source): Builder => $source
                    ->where('state', StudentLifecycleChange::StateApplied)->where('enrollment_id', $case->id)
                    ->where('student_profile_id', $case->student_profile_id)->where('term_id', $case->term_id))
                    ->orWhereDoesntHave('lifecycleReviewSource');
            })->where(function (Builder $query) use ($pending, $reviewedEvents): void {
                if ($pending) {
                    $query->whereNotIn('id', $reviewedEvents)
                        ->orWhereDoesntHave('lifecycleReviewSource');
                } else {
                    $query->whereHas('lifecycleReviewSource')->whereIn('id', $reviewedEvents);
                }
            })->with(['lifecycleReviewSource', 'latestLifecycleReviewResult.user'])->orderBy('id');
    }

    /** @return Builder<OperationalEvent> */
    public function results(): Builder
    {
        return OperationalEvent::query()->where('event_domain', OperationalEvent::DomainOperations)
            ->where('integration', OperationalEvent::IntegrationAcademicRecords)
            ->where('event_type', OperationalEvent::TypeLifecycleAccountingReviewResult)
            ->where('related_record_type', OperationalEvent::class);
    }

    /** @return Builder<OperationalEvent> */
    public function history(Enrollment $case): Builder
    {
        return $this->results()->where('payload->enrollment_id', $case->id)
            ->where('payload->student_profile_id', $case->student_profile_id)->where('payload->term_id', $case->term_id)
            ->with(['user', 'lifecycleReview.lifecycleReviewSource', 'lifecycleReview.latestLifecycleReviewResult'])->latest('id');
    }

    /** @return list<int> Count and destination both represent enrollments, including missing-account recovery. */
    public function pendingEnrollmentIds(): array
    {
        $events = $this->roots()->with(['lifecycleReviewSource', 'latestLifecycleReviewResult'])->get();
        $cases = Enrollment::query()->whereIn('id', $events->pluck('payload.enrollment_id'))
            ->get(['id', 'student_profile_id', 'term_id'])->keyBy('id');

        return $events->filter(function (OperationalEvent $event) use ($cases): bool {
            $case = $cases->get(data_get($event->payload, 'enrollment_id'));
            if ($case === null || (int) $case->student_profile_id !== (int) data_get($event->payload, 'student_profile_id')) {
                return false;
            }
            $source = $event->lifecycleReviewSource;

            return $source === null || ($this->sourceMatches($source, $case)
                && ($event->latestLifecycleReviewResult === null || data_get($event->latestLifecycleReviewResult->payload, 'outcome') === self::Pending));
        })->pluck('payload.enrollment_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    public function sourceMatches(StudentLifecycleChange $source, Enrollment $case): bool
    {
        return $source->state === StudentLifecycleChange::StateApplied
            && (int) $source->enrollment_id === (int) $case->id
            && (int) $source->student_profile_id === (int) $case->student_profile_id
            && (int) $source->term_id === (int) $case->term_id;
    }

    public function accountMatches(?TermAccount $account, Enrollment $case): bool
    {
        return $account !== null && (int) $account->enrollment_id === (int) $case->id
            && (int) $account->credential_user_id === (int) $case->credential_user_id && (int) $account->term_id === (int) $case->term_id;
    }

    public function fingerprint(Model $model): string
    {
        return hash('sha256', json_encode($model->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    /** @return array{event_id:int,source_fingerprint:string,current_result_id:?int,account_id:int} */
    public function snapshot(Enrollment $case, int $eventId): array
    {
        $event = $this->forEnrollment($case)->whereKey($eventId)->first() ?? $this->forEnrollment($case, false)->whereKey($eventId)->first();
        abort_unless($event !== null, 404);
        $account = $case->termAccount()->first();
        if ($event->lifecycleReviewSource === null || ! $this->accountMatches($account, $case)) {
            throw ValidationException::withMessages(['review' => 'Restore the applied change and its exact term account with Registrar and Accounting before recording a result. The review stays pending.']);
        }

        return ['event_id' => (int) $event->id, 'source_fingerprint' => $this->fingerprint($event->lifecycleReviewSource),
            'current_result_id' => $event->latestLifecycleReviewResult?->id, 'account_id' => (int) $account->id];
    }

    /** @return array<string, Model> Existing authoritative sources; Accounting explains their specific relationship to this change. */
    public function financialSources(Enrollment $case): array
    {
        $account = $case->termAccount()->first();
        if (! $this->accountMatches($account, $case)) {
            return [];
        }
        $sources = [];
        foreach (Payment::query()->where('term_account_id', $account->id)->where('student_profile_id', $case->student_profile_id)
            ->where('term_id', $case->term_id)->where('evidence_status', 'verified')->whereNotNull('verified_at')
            ->whereIn('state', [Payment::StatePosted, Payment::StateReversal])->get() as $payment) {
            $sources['payment:'.$payment->id] = $payment;
        }
        foreach (ApprovedCoverage::query()->where('term_account_id', $account->id)->whereNotNull('approved_at')
            ->whereNotNull('approved_by')->whereNotNull('authority_reference')->whereIn('state', [ApprovedCoverage::StateApplied, ApprovedCoverage::StateReversed])->get() as $coverage) {
            $sources['coverage:'.$coverage->id] = $coverage;
        }
        foreach (Assessment::query()->where('term_account_id', $account->id)->where('enrollment_id', $case->id)
            ->where('state', Assessment::StateActive)->whereNotNull('authority_reference')->get() as $assessment) {
            $sources['assessment:'.$assessment->id] = $assessment;
        }

        return $sources;
    }

    public function financialSourceLabel(string $key, Model $source): string
    {
        $label = match (true) {
            $source instanceof Payment => $source->state === Payment::StateReversal ? 'Payment reversal' : 'Verified payment',
            $source instanceof ApprovedCoverage => $source->state === ApprovedCoverage::StateReversed ? 'Coverage reversal' : 'Approved coverage',
            default => 'Authorized assessment',
        };

        $detail = match (true) {
            $source instanceof Payment => collect([
                $source->channelLabel(),
                ($source->external_refund_at ?? $source->paid_at ?? $source->verified_at)?->format('M j, Y'),
                $source->or_number ?: ($source->external_check_reference ?: $source->provider_reference),
            ])->filter()->implode(' · '),
            $source instanceof ApprovedCoverage => collect([
                ($source->reversed_at ?? $source->effective_date ?? $source->approved_at)?->format('M j, Y'),
                $source->authority_reference,
            ])->filter()->implode(' · '),
            $source instanceof Assessment => collect([
                "Version {$source->version}",
                $source->activated_at?->format('M j, Y'),
                $source->authority_reference,
            ])->filter()->implode(' · '),
            default => null,
        };

        $base = $label.' · PHP '.number_format((float) ($source->amount ?? $source->total), 2);

        return ($detail !== null && $detail !== '') ? "{$base} · {$detail}" : "{$base} · #{$source->getKey()}";
    }

    /** @return array{pending:int,reviewed:int} Safe summary excludes private reasons and financial evidence. */
    public function safeStatus(Enrollment $case): array
    {
        return ['pending' => $this->forEnrollment($case)->count(), 'reviewed' => $this->forEnrollment($case, false)->count()];
    }

    public function statusForChange(StudentLifecycleChange $change): ?string
    {
        $case = $change->enrollment;
        if ($case === null || ! $this->sourceMatches($change, $case)) {
            return null;
        }
        $event = $this->roots()->where('related_record_id', $change->id)
            ->where('payload->enrollment_id', $case->id)->where('payload->student_profile_id', $case->student_profile_id)
            ->with('latestLifecycleReviewResult')->first();
        if ($event === null) {
            return null;
        }

        return $event->latestLifecycleReviewResult === null || data_get($event->latestLifecycleReviewResult->payload, 'outcome') === self::Pending
            ? 'Accounting review pending' : 'Accounting review recorded';
    }
}
