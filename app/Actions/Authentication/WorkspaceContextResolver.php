<?php

namespace App\Actions\Authentication;

use App\Models\AdmissionApplication;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WorkspaceContextResolver
{
    public const SessionKey = 'tala.workspace_context';

    /**
     * @return array<string, array{label: string, destination: string, panel: string}>
     */
    public function availableContexts(User $user): array
    {
        if (! $user->canAuthenticate()) {
            return [];
        }

        $contexts = [];

        if ($user->hasAssignedRole('applicant') && $this->applicantContextIsCurrent($user)) {
            $contexts['applicant'] = $this->definition('Applicant', '/applicant', 'applicant');
        }

        if ($user->hasAssignedRole('student') && $user->hasAccessibleStudentProfile()) {
            $contexts['student'] = $this->definition('Student', '/student', 'student');
        }

        foreach (User::staffRoleOptions() as $role => $label) {
            if ($user->hasAssignedRole($role)) {
                $contexts[$role] = $this->definition($label, '/admin', 'admin');
            }
        }

        return $contexts;
    }

    public function selected(User $user): ?string
    {
        $selected = session(self::SessionKey);

        if (! is_string($selected) || ! array_key_exists($selected, $this->availableContexts($user))) {
            session()->forget(self::SessionKey);

            return null;
        }

        return $selected;
    }

    public function select(User $user, string $context): string
    {
        $destination = $this->destinationFor($user, $context);

        if ($destination === null) {
            throw ValidationException::withMessages([
                'context' => 'That workspace is no longer available for this account.',
            ]);
        }

        session()->put(self::SessionKey, $context);
        session()->regenerate();

        return $destination;
    }

    public function destinationFor(User $user, ?string $context): ?string
    {
        if ($context === null) {
            return null;
        }

        return $this->availableContexts($user)[$context]['destination'] ?? null;
    }

    public function isSelected(User $user, string $context): bool
    {
        return $this->selected($user) === $context;
    }

    private function applicantContextIsCurrent(User $user): bool
    {
        if (! $user->hasAccessibleStudentProfile()) {
            return true;
        }

        return $user->admissionApplications()
            ->canonical()
            ->whereIn('application_state', [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
            ])
            ->exists();
    }

    /** @return array{label: string, destination: string, panel: string} */
    private function definition(string $label, string $destination, string $panel): array
    {
        return compact('label', 'destination', 'panel');
    }
}
