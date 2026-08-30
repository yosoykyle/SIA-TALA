<?php

namespace App\Actions\Authentication;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;

class TalaAppAuthentication extends AppAuthentication
{
    public function generateQrCodeDataUri(#[\SensitiveParameter] string $secret): string
    {
        $dataUri = parent::generateQrCodeDataUri($secret);
        $svgPrefix = 'data:image/svg+xml;base64,';

        if (! str_starts_with($dataUri, $svgPrefix)) {
            return $dataUri;
        }

        $decoded = base64_decode(substr($dataUri, strlen($svgPrefix)), strict: true);

        return is_string($decoded) && str_starts_with($decoded, $svgPrefix)
            ? $decoded
            : $dataUri;
    }

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
