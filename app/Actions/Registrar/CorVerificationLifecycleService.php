<?php

namespace App\Actions\Registrar;

use App\Models\CorVerification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorVerificationLifecycleService
{
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
        if ($registrar->can('manage-lis')) {
            return;
        }

        throw new AuthorizationException('Only authorized Registrar staff can manage COR verification tokens.');
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
