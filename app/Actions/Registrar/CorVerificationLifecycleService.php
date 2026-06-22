<?php

namespace App\Actions\Registrar;

use App\Actions\Enrollment\StudentEnrollmentService;
use App\Models\CorVerification;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorVerificationLifecycleService
{
    public function __construct(
        private readonly StudentEnrollmentService $studentEnrollmentService,
    ) {}

    public function issueForEnrollment(
        Enrollment $enrollment,
        User $registrar,
        ?CarbonImmutable $issuedAt = null,
        ?CarbonImmutable $expiresAt = null,
    ): CorVerification {
        $this->authorizeRegistrar($registrar);
        $timestamp = $issuedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollment, $registrar, $timestamp, $expiresAt): CorVerification {
            $lockedEnrollment = Enrollment::query()
                ->with(['studentProfile.user', 'term'])
                ->lockForUpdate()
                ->findOrFail($enrollment->id);

            $readiness = $this->studentEnrollmentService->corReadiness($lockedEnrollment);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'enrollment' => 'COR generation requires a finance-cleared enrollment with an active student account, assigned section, and assigned delivery group.',
                    'blockers' => implode(', ', $readiness['blockers']),
                ]);
            }

            $existing = CorVerification::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('status', CorVerification::StatusValid)
                ->where(function ($query) use ($timestamp): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $timestamp);
                })
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CorVerification) {
                return $existing->fresh();
            }

            $corVerification = CorVerification::query()->create([
                'student_profile_id' => $lockedEnrollment->student_profile_id,
                'term_id' => $lockedEnrollment->term_id,
                'enrollment_id' => $lockedEnrollment->id,
                'token' => $this->uniqueToken(),
                'status' => CorVerification::StatusValid,
                'issued_at' => $timestamp,
                'expires_at' => $expiresAt,
            ]);

            $this->recordActivity(
                corVerification: $corVerification,
                registrar: $registrar,
                event: 'cor_issued',
                timestamp: $timestamp,
                properties: [
                    'enrollment_id' => $lockedEnrollment->id,
                    'status_after' => CorVerification::StatusValid,
                ],
            );

            return $corVerification->fresh();
        }, attempts: 3);
    }

    /**
     * @return array{status:string, label:string, message:string, student_id:?string, student_name:?string, term:?string, enrollment_status:?string, issued_at:?string, expires_at:?string, revoked_at:?string, revocation_reason:?string}
     */
    public function verificationResult(string $token, ?CarbonImmutable $checkedAt = null): array
    {
        $checkedAt ??= CarbonImmutable::now(config('app.timezone'));

        $corVerification = CorVerification::query()
            ->with(['studentProfile.user', 'term', 'enrollment'])
            ->where('token', $token)
            ->first();

        if (! $corVerification instanceof CorVerification) {
            return $this->publicPayload(
                status: CorVerification::StatusNotFound,
                message: 'No COR verification record was found for this token.',
            );
        }

        $status = $corVerification->publicVerificationStatus($checkedAt);

        return $this->publicPayload(
            status: $status,
            message: $this->messageForStatus($status),
            corVerification: $corVerification,
        );
    }

    public function supersede(CorVerification $corVerification, User $registrar): CorVerification
    {
        $this->authorizeRegistrar($registrar);

        return DB::transaction(function () use ($corVerification, $registrar): CorVerification {
            $locked = CorVerification::query()
                ->lockForUpdate()
                ->findOrFail($corVerification->getKey());

            if (! $locked->isValid()) {
                throw ValidationException::withMessages([
                    'status' => 'Only valid COR verification tokens can be superseded.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'status' => CorVerification::StatusSuperseded,
            ])->save();

            $this->recordActivity(
                corVerification: $locked,
                registrar: $registrar,
                event: 'cor_superseded',
                timestamp: $timestamp,
                properties: [
                    'status_after' => CorVerification::StatusSuperseded,
                ],
            );

            return $locked->fresh();
        });
    }

    public function revoke(CorVerification $corVerification, User $registrar, string $reason): CorVerification
    {
        $this->authorizeRegistrar($registrar);
        $reason = $this->requiredReason($reason);

        return DB::transaction(function () use ($corVerification, $registrar, $reason): CorVerification {
            $locked = CorVerification::query()
                ->lockForUpdate()
                ->findOrFail($corVerification->getKey());

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages([
                    'status' => 'This COR verification token is already revoked.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'status' => CorVerification::StatusRevoked,
                'revoked_at' => $timestamp,
                'revocation_reason' => $reason,
            ])->save();

            $this->recordActivity(
                corVerification: $locked,
                registrar: $registrar,
                event: 'cor_revoked',
                timestamp: $timestamp,
                properties: [
                    'status_after' => CorVerification::StatusRevoked,
                    'reason' => $reason,
                ],
            );

            return $locked->fresh();
        });
    }

    private function authorizeRegistrar(User $registrar): void
    {
        if ($registrar->can('manage-cor-verifications')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can manage COR verification tokens.');
    }

    private function uniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (CorVerification::query()->where('token', $token)->exists());

        return $token;
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            CorVerification::StatusValid => 'This COR verification token is valid.',
            CorVerification::StatusSuperseded => 'This COR verification token was superseded by a newer enrollment record.',
            CorVerification::StatusRevoked => 'This COR verification token was revoked by the Registrar.',
            CorVerification::StatusExpired => 'This COR verification token has expired.',
            default => 'No COR verification record was found for this token.',
        };
    }

    /**
     * @return array{status:string, label:string, message:string, student_id:?string, student_name:?string, term:?string, enrollment_status:?string, issued_at:?string, expires_at:?string, revoked_at:?string, revocation_reason:?string}
     */
    private function publicPayload(
        string $status,
        string $message,
        ?CorVerification $corVerification = null,
    ): array {
        return [
            'status' => $status,
            'label' => CorVerification::statusOptions()[$status] ?? 'Not Found',
            'message' => $message,
            'student_id' => $corVerification?->studentProfile?->student_id,
            'student_name' => $corVerification?->studentProfile?->user?->name,
            'term' => $corVerification?->term?->term_name,
            'enrollment_status' => $corVerification?->enrollment?->status,
            'issued_at' => $corVerification?->issued_at?->toDateTimeString(),
            'expires_at' => $corVerification?->expires_at?->toDateTimeString(),
            'revoked_at' => $corVerification?->revoked_at?->toDateTimeString(),
            'revocation_reason' => $status === CorVerification::StatusRevoked
                ? $corVerification?->revocation_reason
                : null,
        ];
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A revocation reason is required.',
            ]);
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(
        CorVerification $corVerification,
        User $registrar,
        string $event,
        CarbonImmutable $timestamp,
        array $properties,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'cor_controls',
            'description' => 'COR verification state changed.',
            'subject_type' => CorVerification::class,
            'subject_id' => $corVerification->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $registrar->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }
}
