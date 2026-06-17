<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PromissoryNoteLifecycleService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, User $actor, ?CarbonImmutable $submittedAt = null): PromissoryNote
    {
        $studentProfile = StudentProfile::query()->with('user')->findOrFail((int) ($data['student_profile_id'] ?? 0));
        $this->authorizeSubmission($studentProfile, $actor);
        $validated = $this->validateSubmission($data, $studentProfile);
        $timestamp = $submittedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($validated, $actor, $timestamp): PromissoryNote {
            Enrollment::query()->lockForUpdate()->findOrFail($validated['enrollment_id']);

            $hasOpenRequest = PromissoryNote::query()
                ->where('enrollment_id', $validated['enrollment_id'])
                ->whereIn('status', [
                    PromissoryNote::StatusPending,
                    PromissoryNote::StatusApproved,
                    'active',
                ])
                ->exists();

            if ($hasOpenRequest) {
                throw new RuntimeException('Only one open promissory request is allowed per enrollment.');
            }

            $source = $actor->can('approve-promissory-notes')
                ? PromissoryNote::SourceStaffAssisted
                : PromissoryNote::SourceStudent;
            $note = PromissoryNote::query()->create([
                ...$validated,
                'status' => PromissoryNote::StatusPending,
                'requested_by' => $actor->id,
                'requested_at' => $timestamp,
                'request_source' => $source,
            ]);

            $this->recordActivity($note, 'promissory_note_submitted', $actor, [
                'status_after' => PromissoryNote::StatusPending,
                'request_source' => $source,
            ], $timestamp);
            $this->notifyStudent($note, new GeneralSystemNotification(
                type: 'promissory_note_submitted',
                subject: 'Promissory request submitted',
                body: 'Your promissory request is waiting for Accounting review.',
                metadata: $this->notificationMetadata($note),
            ));

            return $note->fresh();
        });
    }

    public function approve(
        PromissoryNote $note,
        User $reviewer,
        ?CarbonImmutable $reviewedAt = null,
    ): PromissoryNote {
        $this->authorizeReviewer($reviewer);
        $timestamp = $reviewedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($note, $reviewer, $timestamp): PromissoryNote {
            $locked = $this->lock($note);
            $this->assertStatus($locked, [PromissoryNote::StatusPending]);

            $locked->forceFill([
                'status' => PromissoryNote::StatusApproved,
                'approved_by' => $reviewer->id,
                'approved_at' => $timestamp,
                'expired_at' => null,
            ])->save();

            $this->recordActivity($locked, 'promissory_note_approved', $reviewer, [
                'status_after' => PromissoryNote::StatusApproved,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'promissory_note_approved',
                subject: 'Promissory request approved',
                body: 'Accounting approved your payment promise. This does not clear your balance or enrollment by itself.',
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function reject(
        PromissoryNote $note,
        User $reviewer,
        string $reason,
        ?CarbonImmutable $reviewedAt = null,
    ): PromissoryNote {
        $this->authorizeReviewer($reviewer);
        $reason = $this->requiredReason($reason, 'A rejection reason is required.');
        $timestamp = $reviewedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($note, $reviewer, $reason, $timestamp): PromissoryNote {
            $locked = $this->lock($note);
            $this->assertStatus($locked, [PromissoryNote::StatusPending]);
            $locked->forceFill([
                'status' => PromissoryNote::StatusRejected,
                'rejected_by' => $reviewer->id,
                'rejected_at' => $timestamp,
                'rejection_reason' => $reason,
            ])->save();

            $this->recordActivity($locked, 'promissory_note_rejected', $reviewer, [
                'status_after' => PromissoryNote::StatusRejected,
                'rejection_reason' => $reason,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'promissory_note_rejected',
                subject: 'Promissory request rejected',
                body: "Accounting rejected your promissory request. Reason: {$reason}",
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function cancel(
        PromissoryNote $note,
        User $actor,
        string $reason,
        ?CarbonImmutable $cancelledAt = null,
    ): PromissoryNote {
        $ownsRequest = (int) $note->studentProfile?->user_id === (int) $actor->id;

        if (! $ownsRequest && ! $actor->can('approve-promissory-notes')) {
            throw new AuthorizationException('Only the student owner or Accounting can cancel this request.');
        }

        $reason = $this->requiredReason($reason, 'A cancellation reason is required.');
        $timestamp = $cancelledAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($note, $actor, $reason, $timestamp): PromissoryNote {
            $locked = $this->lock($note);
            $this->assertStatus($locked, [PromissoryNote::StatusPending, PromissoryNote::StatusApproved, 'active']);
            $locked->forceFill([
                'status' => PromissoryNote::StatusCancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => $timestamp,
                'cancellation_reason' => $reason,
            ])->save();

            $this->recordActivity($locked, 'promissory_note_cancelled', $actor, [
                'status_after' => PromissoryNote::StatusCancelled,
                'cancellation_reason' => $reason,
            ], $timestamp);
            $this->notifyStudent($locked, new GeneralSystemNotification(
                type: 'promissory_note_cancelled',
                subject: 'Promissory request cancelled',
                body: "Your promissory request was cancelled. Reason: {$reason}",
                metadata: $this->notificationMetadata($locked),
            ));

            return $locked->fresh();
        });
    }

    public function settleEligibleForEnrollment(
        Enrollment $enrollment,
        ?User $actor,
        ?CarbonImmutable $settledAt = null,
    ): int {
        if (! Schema::hasTable((new PromissoryNote)->getTable())) {
            return 0;
        }

        $timestamp = $settledAt ?? CarbonImmutable::now(config('app.timezone'));
        $settled = 0;

        DB::transaction(function () use ($enrollment, $actor, $timestamp, &$settled): void {
            $studentProfile = StudentProfile::query()->lockForUpdate()->findOrFail($enrollment->student_profile_id);
            $notes = PromissoryNote::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('status', [PromissoryNote::StatusApproved, 'active'])
                ->lockForUpdate()
                ->get();

            foreach ($notes as $note) {
                $confirmedAfterApproval = Payment::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('status', 'confirmed')
                    ->when($note->approved_at !== null, fn ($query) => $query->where('confirmed_at', '>=', $note->approved_at))
                    ->where('confirmed_at', '<=', $timestamp)
                    ->sum('amount');
                $promiseFulfilled = $this->money->toCents((string) $confirmedAfterApproval) >= $this->money->toCents((string) $note->amount);
                $balanceCleared = $this->money->isZeroOrNegative((string) $studentProfile->current_balance);

                if (! $promiseFulfilled && ! $balanceCleared) {
                    continue;
                }

                $note->forceFill([
                    'status' => PromissoryNote::StatusSettled,
                    'settled_by' => $actor?->id,
                    'settled_at' => $timestamp,
                ])->save();
                $this->recordActivity($note, 'promissory_note_settled', $actor, [
                    'status_after' => PromissoryNote::StatusSettled,
                    'settlement_basis' => $balanceCleared ? 'balance_cleared' : 'promised_amount_paid',
                ], $timestamp);
                $this->notifyStudent($note, new GeneralSystemNotification(
                    type: 'promissory_note_settled',
                    subject: 'Promissory note settled',
                    body: 'Your recorded payment promise has been marked settled. Any remaining account balance still applies.',
                    metadata: $this->notificationMetadata($note),
                ));
                $settled++;
            }
        });

        return $settled;
    }

    /**
     * @return array{warnings_sent:int,expired:int}
     */
    public function processDeadlines(?CarbonImmutable $asOf = null): array
    {
        $timestamp = $asOf ?? CarbonImmutable::now(config('app.timezone'));
        $date = $timestamp->toDateString();
        $warningThrough = $timestamp->addDays(3)->toDateString();
        $warningsSent = 0;
        $expired = 0;

        $warningIds = PromissoryNote::query()
            ->whereIn('status', [PromissoryNote::StatusApproved, 'active'])
            ->whereNull('expiry_warning_sent_at')
            ->whereDate('due_date', '>=', $date)
            ->whereDate('due_date', '<=', $warningThrough)
            ->pluck('id');

        foreach ($warningIds as $noteId) {
            DB::transaction(function () use ($noteId, $timestamp, &$warningsSent): void {
                $note = PromissoryNote::query()->lockForUpdate()->findOrFail($noteId);

                if ($note->expiry_warning_sent_at !== null || ! in_array($note->status, [PromissoryNote::StatusApproved, 'active'], true)) {
                    return;
                }

                $note->forceFill(['expiry_warning_sent_at' => $timestamp])->save();
                $this->notifyStudent($note, new GeneralSystemNotification(
                    type: 'promissory_note_expiring',
                    subject: 'Promissory note expiring soon',
                    body: 'Your promissory note is due on '.$note->due_date?->toFormattedDateString().'.',
                    metadata: $this->notificationMetadata($note),
                ));
                $warningsSent++;
            });
        }

        $expiredIds = PromissoryNote::query()
            ->whereIn('status', [PromissoryNote::StatusApproved, 'active'])
            ->whereDate('due_date', '<', $date)
            ->pluck('id');

        foreach ($expiredIds as $noteId) {
            DB::transaction(function () use ($noteId, $timestamp, &$expired): void {
                $note = PromissoryNote::query()->lockForUpdate()->findOrFail($noteId);

                if (! in_array($note->status, [PromissoryNote::StatusApproved, 'active'], true)) {
                    return;
                }

                $note->forceFill([
                    'status' => PromissoryNote::StatusExpired,
                    'expired_at' => $timestamp,
                    'expiry_notified_at' => $timestamp,
                ])->save();
                $this->recordActivity($note, 'promissory_note_expired', null, [
                    'status_after' => PromissoryNote::StatusExpired,
                ], $timestamp);
                $this->notifyStudent($note, new GeneralSystemNotification(
                    type: 'promissory_note_expired',
                    subject: 'Promissory note expired',
                    body: 'Your promissory note has expired. Contact Accounting regarding the remaining balance.',
                    metadata: $this->notificationMetadata($note),
                ));
                $expired++;
            });
        }

        return ['warnings_sent' => $warningsSent, 'expired' => $expired];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateSubmission(array $data, StudentProfile $studentProfile): array
    {
        $data = PromissoryNote::validateAccountingScopeData($data);
        $errors = [];
        $termId = $data['term_id'];
        $enrollmentId = $data['enrollment_id'];
        $amount = $this->money->normalize((string) ($data['amount'] ?? '0'));
        $reason = trim((string) ($data['reason'] ?? ''));
        $dueDate = $this->parseDate($data['due_date'] ?? null);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        if ($termId === null) {
            $errors['term_id'] = 'A term is required for a promissory request.';
        }

        if ($enrollmentId === null) {
            $errors['enrollment_id'] = 'An enrollment is required for a promissory request.';
        }

        if (! $this->money->greaterThanZero($amount) || $this->money->toCents($amount) > $this->money->toCents((string) $studentProfile->current_balance)) {
            $errors['amount'] = 'The promised amount must be positive and cannot exceed the outstanding balance.';
        }

        if ($dueDate === null || $dueDate->lessThan($today)) {
            $errors['due_date'] = 'The promised payment date cannot be in the past.';
        }

        if ($reason === '') {
            $errors['reason'] = 'A reason is required for a promissory request.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'student_profile_id' => $studentProfile->id,
            'term_id' => $termId,
            'enrollment_id' => $enrollmentId,
            'ledger_entry_id' => $data['ledger_entry_id'],
            'amount' => $amount,
            'due_date' => $dueDate->toDateString(),
            'reason' => $reason,
        ];
    }

    private function authorizeSubmission(StudentProfile $studentProfile, User $actor): void
    {
        $ownsProfile = (int) $studentProfile->user_id === (int) $actor->id;
        $isEligibleOwner = $ownsProfile && $actor->hasAnyRole(['applicant', 'student']);

        if (! $isEligibleOwner && ! $actor->can('approve-promissory-notes')) {
            throw new AuthorizationException('Only the student owner or Accounting can submit this promissory request.');
        }
    }

    private function authorizeReviewer(User $reviewer): void
    {
        if (! $reviewer->can('approve-promissory-notes')) {
            throw new AuthorizationException('Only Accounting can review promissory requests.');
        }
    }

    private function lock(PromissoryNote $note): PromissoryNote
    {
        return PromissoryNote::query()->lockForUpdate()->findOrFail($note->id);
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function assertStatus(PromissoryNote $note, array $allowedStatuses): void
    {
        if (! in_array($note->status, $allowedStatuses, true)) {
            throw new RuntimeException("Invalid promissory transition from [{$note->status}].");
        }
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function requiredReason(string $reason, string $message): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException($message);
        }

        return $reason;
    }

    private function notifyStudent(PromissoryNote $note, GeneralSystemNotification $notification): void
    {
        $student = StudentProfile::query()->with('user')->find($note->student_profile_id)?->user;

        if ($student instanceof User) {
            $student->notify($notification);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationMetadata(PromissoryNote $note): array
    {
        return [
            'promissory_note_id' => $note->id,
            'term_id' => $note->term_id,
            'enrollment_id' => $note->enrollment_id,
            'status' => $note->status,
            'due_date' => $note->due_date?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        PromissoryNote $note,
        string $event,
        ?User $actor,
        array $properties,
        CarbonImmutable $timestamp,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'promissory_note',
            'description' => 'Promissory note lifecycle transition.',
            'subject_type' => PromissoryNote::class,
            'subject_id' => $note->id,
            'event' => $event,
            'causer_type' => $actor instanceof User ? User::class : null,
            'causer_id' => $actor?->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
