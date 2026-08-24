<?php

namespace App\Actions\Authentication;

use App\Models\OperationalEvent;
use App\Models\User;
use App\Notifications\AccountAccessChangedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class StaffMfaService
{
    public function __construct(private readonly UserSessionService $sessions) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reset(
        User $actor,
        User $target,
        #[\SensitiveParameter] string $actorPassword,
        string $reason,
        string $authority,
        ?string $evidenceReference = null,
    ): User {
        if (! $actor->hasRole(User::StaffRoleSystemSuperAdmin)) {
            throw new AuthorizationException('Only a System Administrator may reset Staff MFA.');
        }

        if (! Hash::check($actorPassword, (string) $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The administrator password is incorrect.',
            ]);
        }

        $reason = trim($reason);
        $authority = trim($authority);

        if (mb_strlen($reason) < 10 || $authority === '') {
            throw ValidationException::withMessages([
                'reason' => 'A specific recovery reason and authority are required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $reason, $authority, $evidenceReference): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $locked->isStaffCapable()) {
                throw ValidationException::withMessages([
                    'target' => 'MFA reset is limited to Staff-capable accounts.',
                ]);
            }

            $locked->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes_acknowledged_at' => null,
                'status' => User::StatusVerificationRequired,
            ])->save();

            $this->sessions->revokeAll($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('staff_mfa_reset')
                ->withProperties([
                    'reason' => $reason,
                    'authority' => $authority,
                    'evidence_reference' => $evidenceReference,
                ])
                ->log('Staff MFA reset after external identity verification');

            DB::afterCommit(function () use ($locked): void {
                try {
                    $locked->notify(new AccountAccessChangedNotification(
                        'Your Staff MFA was reset after an authorized identity-recovery process. Enroll a new authenticator before workspace access.',
                    ));
                } catch (Throwable $exception) {
                    OperationalEvent::query()->create([
                        'event_domain' => OperationalEvent::DomainNotifications,
                        'integration' => OperationalEvent::IntegrationMail,
                        'channel' => OperationalEvent::ChannelEmail,
                        'direction' => OperationalEvent::DirectionOutbound,
                        'event_type' => 'staff_mfa_reset_email',
                        'user_id' => $locked->id,
                        'status' => OperationalEvent::StatusFailed,
                        'occurred_at' => now(),
                        'failed_at' => now(),
                        'related_record_type' => User::class,
                        'related_record_id' => $locked->id,
                        'diagnostics' => ['exception' => $exception::class],
                    ]);
                }
            });

            return $locked->refresh();
        });
    }
}
