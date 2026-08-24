<?php

namespace App\Actions\Authentication;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

class TalaAppAuthentication extends AppAuthentication
{
    /** @param array<string>|null $codes */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[\SensitiveParameter] ?array $codes): void
    {
        $previousCodes = $user->getAppAuthenticationRecoveryCodes();
        parent::saveRecoveryCodes($user, $codes);

        if (! $user instanceof User) {
            return;
        }

        $event = match (true) {
            $codes === null => 'mfa_disabled',
            $previousCodes === null => 'mfa_enabled',
            default => 'mfa_recovery_codes_regenerated',
        };

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event($event)
            ->log(str($event)->replace('_', ' ')->headline()->toString());
    }

    public function verifyRecoveryCode(#[\SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $isValid = parent::verifyRecoveryCode($recoveryCode, $user);

        if ($isValid && $user instanceof User) {
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('mfa_recovery_code_used')
                ->log('MFA recovery code used');
        }

        return $isValid;
    }

    /**
     * @param  Authenticatable&HasAppAuthentication&HasAppAuthenticationRecovery  $user
     * @return array<Component | Action | ActionGroup>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $components = parent::getChallengeFormComponents($user);
        $components[1]->live();

        return $components;
    }

    /** @return array<Action> */
    public function getActions(): array
    {
        $actions = parent::getActions();
        $user = Filament::auth()->user();

        if (! ($user instanceof User) || ! $user->isStaffCapable()) {
            return $actions;
        }

        return array_values(array_filter(
            $actions,
            fn (Action $action): bool => $action->getName() !== 'disableAppAuthentication',
        ));
    }
}
