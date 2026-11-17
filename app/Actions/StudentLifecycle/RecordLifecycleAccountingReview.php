<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordLifecycleAccountingReview
{
    public function __construct(private readonly LifecycleAccountingReview $reviews) {}

    /**
     * @param  array{event_id:int,source_fingerprint:string,current_result_id:?int,account_id:int}  $reviewed
     * @param  array{outcome:string,reason:string,financial_source?:?string}  $data
     * @param  array<string, string>  $financialFingerprints
     */
    public function execute(Enrollment $enrollment, User $actor, array $reviewed, array $data, array $financialFingerprints = []): OperationalEvent
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only active Accounting staff may record a student-change review.');
        }
        $data = Validator::make($data, [
            'outcome' => ['required', Rule::in(array_keys(LifecycleAccountingReview::outcomes($reviewed['current_result_id'] !== null)))],
            'reason' => ['required', 'string', 'max:1000'],
            'financial_source' => ['nullable', 'string', 'required_if:outcome,'.LifecycleAccountingReview::FinancialActionRecorded],
        ])->validate();
        $data['reason'] = trim($data['reason']);

        return DB::transaction(function () use ($enrollment, $actor, $reviewed, $data, $financialFingerprints): OperationalEvent {
            $case = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $event = $this->reviews->roots()->lockForUpdate()->findOrFail($reviewed['event_id']);
            $source = StudentLifecycleChange::query()->lockForUpdate()->find($event->related_record_id);
            $account = TermAccount::query()->lockForUpdate()->find($reviewed['account_id']);
            if ($source === null || ! $this->reviews->sourceMatches($source, $case)
                || (int) data_get($event->payload, 'enrollment_id') !== (int) $case->id
                || (int) data_get($event->payload, 'student_profile_id') !== (int) $case->student_profile_id
                || ! $this->reviews->accountMatches($account, $case)
                || ! hash_equals($reviewed['source_fingerprint'], $this->reviews->fingerprint($source))) {
                throw ValidationException::withMessages(['review' => 'The applied change or account source is unavailable or changed. Nothing was recorded. Reopen the current review or contact Registrar.']);
            }
            $financialSource = null;
            if ($data['outcome'] === LifecycleAccountingReview::FinancialActionRecorded) {
                $key = $data['financial_source'];
                $financialSource = $this->reviews->financialSources($case)[$key] ?? null;
                if ($financialSource !== null) {
                    $financialSource = $financialSource->newQuery()->lockForUpdate()->find($financialSource->getKey());
                }
                if ($financialSource === null || ! isset($financialFingerprints[$key])
                    || ! hash_equals($financialFingerprints[$key], $this->reviews->fingerprint($financialSource))) {
                    throw ValidationException::withMessages(['financial_source' => 'Choose an available, unchanged authoritative record from this exact account. Reopen the form to refresh its sources.']);
                }
            }
            $payload = [
                'outcome' => $data['outcome'], 'reason' => $data['reason'],
                'financial_record_type' => $financialSource?->getMorphClass(), 'financial_record_id' => $financialSource?->getKey(),
                'financial_fingerprint' => $financialSource !== null ? $this->reviews->fingerprint($financialSource) : null,
                'source_fingerprint' => $reviewed['source_fingerprint'], 'previous_result_id' => $reviewed['current_result_id'],
                'lifecycle_change_id' => $source->id, 'enrollment_id' => $case->id, 'student_profile_id' => $case->student_profile_id,
                'term_id' => $case->term_id, 'term_account_id' => $account->id,
            ];
            $latest = $event->lifecycleReviewResults()->latest('id')->first();
            if ($latest?->id !== $reviewed['current_result_id']) {
                if ($latest !== null && (int) $latest->user_id === (int) $actor->id && $latest->payload == $payload) {
                    return $latest;
                }
                throw ValidationException::withMessages(['review' => 'The review changed while this form was open. Nothing was recorded. Close and reopen the current review.']);
            }

            return OperationalEvent::query()->create([
                'event_domain' => OperationalEvent::DomainOperations, 'integration' => OperationalEvent::IntegrationAcademicRecords,
                'channel' => OperationalEvent::ChannelEvidenceIngest, 'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeLifecycleAccountingReviewResult, 'event_version' => '1',
                'external_id' => 'lifecycle-review-result:'.$event->id.':'.($latest?->id ?? 'initial'),
                'user_id' => $actor->id, 'status' => OperationalEvent::StatusProcessed, 'occurred_at' => now(),
                'related_record_type' => OperationalEvent::class, 'related_record_id' => $event->id, 'payload' => $payload,
            ]);
        }, attempts: 3);
    }
}
