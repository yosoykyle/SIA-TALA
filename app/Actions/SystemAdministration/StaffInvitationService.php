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
use Illuminate\Validation\Rule;
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
            'email' => ['required', $this->emailRule(), 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:40'],
            'staff_identifier' => ['nullable', 'string', 'max:100'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'in:'.implode(',', User::staffRoleNames())],
            'reason' => ['nullable', 'string', 'max:500'],
            'authority' => ['nullable', 'string', 'max:255'],
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
                    'staff_identifier' => filled($validated['staff_identifier'] ?? null)
                        ? Str::squish((string) $validated['staff_identifier'])
                        : null,
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
                    'reason' => filled($validated['reason'] ?? null) ? trim((string) $validated['reason']) : null,
                    'authority' => filled($validated['authority'] ?? null) ? trim((string) $validated['authority']) : null,
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

    public function cancelPendingInvitation(User $target, User $actor, ?string $reason = null): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($target, $actor, $reason): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->status !== User::StatusInvitationPending
                || $locked->password !== null
                || $locked->last_successful_sign_in_at !== null
                || $locked->staffInvitations()->whereNotNull('accepted_at')->exists()) {
                throw ValidationException::withMessages([
                    'invitation' => 'Only a never-activated pending staff invitation can be cancelled.',
                ]);
            }

            $cancelledUserId = $locked->id;
            $cancelledName = (string) $locked->name;
            $cancelledDomain = Str::after((string) $locked->email, '@');

            activity('user_access')
                ->causedBy($actor)
                ->event('staff_invitation_cancelled')
                ->withProperties([
                    'cancelled_user_id' => $cancelledUserId,
                    'cancelled_name' => $cancelledName,
                    'cancelled_email_domain' => $cancelledDomain,
                    'reason' => filled($reason) ? trim((string) $reason) : null,
                ])
                ->log('Pending staff invitation cancelled');

            $locked->staffInvitations()->update(['superseded_by_id' => null]);
            $locked->staffInvitations()->delete();
            $locked->staffAccessProfile()->delete();
            $locked->syncRoles([]);
            $locked->delete();
        });
    }

    public function correctPendingEmailAndResend(User $target, string $newEmail, User $actor): StaffInvitation
    {
        $this->authorize($actor);

        $normalized = Str::lower(trim($newEmail));

        Validator::make(['email' => $normalized], [
            'email' => [
                'required',
                $this->emailRule(),
                'max:255',
                Rule::unique('users', 'email')->ignore($target->id),
            ],
        ])->validate();

        $result = DB::transaction(function () use ($target, $normalized, $actor): StaffInvitationResult {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->status !== User::StatusInvitationPending
                || $locked->password !== null
                || $locked->last_successful_sign_in_at !== null) {
                throw ValidationException::withMessages([
                    'email' => 'Only a pending, unactivated staff invitation email can be corrected here.',
                ]);
            }

            $latestInvitation = $locked->staffInvitations()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $latestInvitation instanceof StaffInvitation || $latestInvitation->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'email' => 'No active pending staff invitation was found for this account.',
                ]);
            }

            $previousDomain = Str::after((string) $locked->email, '@');

            $locked->forceFill([
                'email' => $normalized,
                'email_verified_at' => null,
            ])->save();

            $result = $this->createInvitation($locked, $actor, [
                'email' => $normalized,
                'roles' => $latestInvitation->staff_roles,
                'reason' => $latestInvitation->reason,
                'authority' => $latestInvitation->authority,
                'evidence_reference' => $latestInvitation->evidence_reference,
            ]);

            $locked->staffInvitations()
                ->whereKeyNot($result->invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('superseded_by_id')
                ->update(['superseded_by_id' => $result->invitation->id]);

            activity('user_access')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('staff_invitation_email_corrected')
                ->withProperties([
                    'previous_email_domain' => $previousDomain,
                    'new_email_domain' => Str::after($normalized, '@'),
                    'invitation_id' => $result->invitation->id,
                ])
                ->log('Pending staff invitation email corrected and resent');

            return $result;
        });

        $this->dispatch($result);

        return $result->invitation;
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

    private function emailRule(): string
    {
        return app()->runningUnitTests() ? 'email:rfc' : 'email:rfc,dns';
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
            'reason' => filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null,
            'authority' => filled($input['authority'] ?? null) ? trim((string) $input['authority']) : null,
            'evidence_reference' => filled($input['evidence_reference'] ?? null) ? trim((string) $input['evidence_reference']) : null,
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
