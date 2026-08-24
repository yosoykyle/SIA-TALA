<?php

namespace App\Actions\SystemAdministration;

use App\Data\StaffInvitationResult;
use App\Models\OperationalEvent;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class StaffInvitationService
{
    /** @param array<string, mixed> $input */
    public function invite(User $actor, array $input): StaffInvitationResult
    {
        $this->authorize($actor);

        if (array_key_exists('email', $input) && is_string($input['email'])) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        $validated = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:40'],
            'staff_identifier' => ['nullable', 'string', 'max:100'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'in:'.implode(',', User::staffRoleNames())],
            'reason' => ['required', 'string', 'min:10'],
            'authority' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $validated['roles'] = array_values(array_unique($validated['roles']));

        $result = DB::transaction(function () use ($actor, $validated): StaffInvitationResult {
            $user = User::query()->where('email', $validated['email'])->lockForUpdate()->first();
            $isNew = $user === null;

            if ($isNew) {
                $user = User::query()->create([
                    ...User::staffNamePayload($validated['first_name'], $validated['middle_name'] ?? null, $validated['last_name'], $validated['suffix'] ?? null),
                    'email' => $validated['email'],
                    'password' => null,
                    'email_verified_at' => null,
                    'status' => User::StatusInvitationPending,
                ]);
            }

            $user->staffAccessProfile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'staff_identifier' => $validated['staff_identifier'] ?? null,
                    'first_name' => $validated['first_name'],
                    'middle_name' => $validated['middle_name'] ?? null,
                    'last_name' => $validated['last_name'],
                    'suffix' => $validated['suffix'] ?? null,
                ],
            );
            $result = $this->createInvitation($user, $actor, $validated);

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('staff_invited')
                ->withProperties([
                    'staff_roles' => $validated['roles'],
                    'reason' => $validated['reason'],
                    'authority' => $validated['authority'],
                    'existing_account_reused' => ! $isNew,
                ])
                ->log('Staff access invitation created');

            return $result;
        });

        $this->dispatch($result);

        return $result;
    }

    public function resend(StaffInvitation $invitation, User $actor): StaffInvitationResult
    {
        $this->authorize($actor);

        $result = DB::transaction(function () use ($invitation, $actor): StaffInvitationResult {
            $locked = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->accepted_at !== null || $locked->superseded_by_id !== null) {
                throw ValidationException::withMessages(['invitation' => 'That invitation can no longer be resent.']);
            }

            if ($locked->sent_at?->greaterThan(now()->subSeconds(60))) {
                throw ValidationException::withMessages([
                    'invitation' => 'Wait 60 seconds before resending this invitation.',
                ]);
            }

            $result = $this->createInvitation($locked->user, $actor, [
                'email' => $locked->email,
                'roles' => $locked->staff_roles,
                'reason' => $locked->reason,
                'authority' => $locked->authority,
                'evidence_reference' => $locked->evidence_reference,
            ]);
            $locked->update(['superseded_by_id' => $result->invitation->id]);

            return $result;
        });

        $this->dispatch($result);

        return $result;
    }

    public function activate(StaffInvitation $invitation, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, Carbon $acceptedAt): User
    {
        Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::min(15)->max(64)->uncompromised()],
        ])->validate();

        return DB::transaction(function () use ($invitation, $token, $password, $acceptedAt): User {
            $locked = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $this->isUsable($locked, $token, $acceptedAt)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This activation link is expired or no longer valid. Request a new invitation.',
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);

            if ($user->password === null) {
                $user->password = Hash::make($password);
            }

            $user->assignRole($locked->staff_roles);
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? $acceptedAt,
                'status' => User::StatusVerificationRequired,
            ])->save();
            $locked->update(['accepted_at' => $acceptedAt]);

            activity()
                ->performedOn($user)
                ->event('staff_invitation_activated')
                ->withProperties(['invitation_id' => $locked->id])
                ->log('Staff invitation activated');

            return $user->refresh();
        });
    }

    public function isUsable(StaffInvitation $invitation, #[\SensitiveParameter] string $token, Carbon $at): bool
    {
        return $invitation->accepted_at === null
            && $invitation->superseded_by_id === null
            && $invitation->expires_at->greaterThanOrEqualTo($at)
            && hash_equals($invitation->token_digest, hash('sha256', $token));
    }

    /** @param array<string, mixed> $input */
    private function createInvitation(User $user, User $actor, array $input): StaffInvitationResult
    {
        $token = Str::random(64);
        $invitation = StaffInvitation::query()->create([
            'user_id' => $user->id,
            'invited_by' => $actor->id,
            'email' => $input['email'],
            'staff_roles' => $input['roles'],
            'token_digest' => hash('sha256', $token),
            'reason' => trim((string) $input['reason']),
            'authority' => trim((string) $input['authority']),
            'evidence_reference' => $input['evidence_reference'] ?? null,
            'expires_at' => now()->addMinutes(60),
            'sent_at' => now(),
        ]);

        return new StaffInvitationResult($invitation, $token);
    }

    private function dispatch(StaffInvitationResult $result): void
    {
        try {
            $result->invitation->user->notify(new StaffInvitationNotification(
                $result->invitation,
                $result->plainTextToken,
            ));
        } catch (Throwable $exception) {
            OperationalEvent::query()->create([
                'event_domain' => OperationalEvent::DomainNotifications,
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => 'staff_invitation_email',
                'user_id' => $result->invitation->user_id,
                'status' => OperationalEvent::StatusFailed,
                'occurred_at' => now(),
                'failed_at' => now(),
                'related_record_type' => StaffInvitation::class,
                'related_record_id' => $result->invitation->id,
                'diagnostics' => ['exception' => $exception::class],
            ]);
        }
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleSystemSuperAdmin)) {
            throw new AuthorizationException('Only a System Administrator may invite Staff.');
        }
    }
}
