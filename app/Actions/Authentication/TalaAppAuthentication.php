<?php

namespace App\Actions\Authentication;

use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Component as LivewireComponent;

class TalaAppAuthentication extends AppAuthentication
{
    public function generateQrCodeDataUri(#[\SensitiveParameter] string $secret): string
    {
        return $this->normalizeQrCodeDataUri(parent::generateQrCodeDataUri($secret));
    }

    protected function normalizeQrCodeDataUri(#[\SensitiveParameter] string $dataUri): string
    {
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
        $isValid = parent::verifyRecoveryCode(trim($recoveryCode), $user);

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
     * @return array<Component>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $isRecoverable = $this->isRecoverable();

        return [
            OneTimeCodeInput::make('code')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.label'))
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(function (Set $set, LivewireComponent $livewire): void {
                        $set('useRecoveryCode', true);
                        $set('code', null);
                        $livewire->resetValidation();
                    })
                    ->visible(fn (): bool => $isRecoverable && (! (bool) $get('useRecoveryCode'))))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.code.validation_attribute'))
                ->visible(fn (Get $get): bool => (! $isRecoverable) || (! (bool) $get('useRecoveryCode')))
                ->required(fn (Get $get): bool => (! $isRecoverable) || (! (bool) $get('useRecoveryCode')))
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                        if (blank($value)) {
                            return;
                        }

                        if (is_string($value) && $this->verifyCode($value, $this->getSecret($user), shouldPreventCodeReuse: true)) {
                            return;
                        }

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
                    };
                }),
            TextInput::make('recoveryCode')
                ->label('Recovery code')
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.validation_attribute'))
                ->password()
                ->revealable(Filament::arePasswordsRevealable())
                ->autocomplete('one-time-code')
                ->autofocus()
                ->belowContent(fn (Get $get): Action => Action::make('useAuthenticatorCode')
                    ->label('Use an authenticator app code')
                    ->link()
                    ->action(function (Set $set, LivewireComponent $livewire): void {
                        $set('useRecoveryCode', false);
                        $set('recoveryCode', null);
                        $livewire->resetValidation();
                    })
                    ->visible(fn (): bool => $isRecoverable && (bool) $get('useRecoveryCode')))
                ->required(fn (Get $get): bool => $isRecoverable && (bool) $get('useRecoveryCode'))
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                        $normalized = is_string($value) ? trim($value) : '';

                        if ($normalized === '') {
                            return;
                        }

                        if ($this->verifyRecoveryCode($normalized, $user)) {
                            return;
                        }

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                    };
                })
                ->visible(fn (Get $get): bool => $isRecoverable && (bool) $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    /**
     * @return array<Component|Action|ActionGroup>
     */
    public function getManagementSchemaComponents(): array
    {
        $components = parent::getManagementSchemaComponents();
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->isEnabled($user)) {
            return $components;
        }

        foreach ($components as $component) {
            if ($component instanceof Actions) {
                $component->belowContent(
                    $user->isStaffCapable()
                        ? 'Store recovery codes safely. Lost access? Ask a System Administrator to reset two-factor authentication.'
                        : 'Store recovery codes safely. Select Turn off to remove two-factor authentication.',
                );
            }
        }

        return $components;
    }

    /** @return array<Action> */
    public function getActions(): array
    {
        $actions = parent::getActions();

        foreach ($actions as $action) {
            $action->button()->color('gray');

            if ($action->getName() === 'setUpAppAuthentication') {
                $this->configureSetupAction($action);
            }

            if ($action->getName() === 'regenerateAppAuthenticationRecoveryCodes') {
                $this->configureRecoveryCodeRegenerationAction($action);
            }

            if ($action->getName() === 'disableAppAuthentication') {
                $this->configureMfaModal($action, Width::Large);
                $this->attachForgotPasswordToActionSchema($action);
            }
        }
        $user = Filament::auth()->user();

        if (! ($user instanceof User) || ! $user->isStaffCapable()) {
            return $actions;
        }

        return array_values(array_filter(
            $actions,
            fn (Action $action): bool => $action->getName() !== 'disableAppAuthentication',
        ));
    }

    private function configureMfaModal(Action $action, Width $width, string ...$windowClasses): Action
    {
        $classes = implode(' ', ['tala-mfa-modal', ...$windowClasses]);

        return $action
            ->modalWidth($width)
            ->extraModalWindowAttributes(['class' => $classes], merge: true);
    }

    private function configureSetupAction(Action $action): void
    {
        $this->configureMfaModal($action, Width::ThreeExtraLarge, 'tala-mfa-setup-modal')
            ->color('primary')
            ->label('Set up authenticator app')
            ->modalHeading('Set up two-factor authentication')
            ->modalDescription('Use an authenticator app such as Google Authenticator or Microsoft Authenticator.')
            ->modifyWizardUsing(function (Wizard $wizard) use ($action): void {
                [$connect, $recovery] = $wizard->getDefaultChildComponents();

                [$setup, $code, $password] = $connect->getDefaultChildComponents();

                if ($password instanceof TextInput) {
                    $password->belowContent(DispatchPasswordRecovery::authenticatedAction(inMfaModal: true));
                }

                $connect
                    ->label('Connect and verify')
                    ->columns(['md' => 2])
                    ->schema([
                        Group::make([$setup])
                            ->extraAttributes(['class' => 'tala-mfa-setup-qr-card']),
                        Group::make([$code, $password])
                            ->extraAttributes(['class' => 'tala-mfa-setup-verify-col']),
                    ]);

                $recovery
                    ->label('Save recovery codes')
                    ->schema([
                        $this->buildRecoveryCodesGroup(
                            resolveCodes: fn (): array => array_values(
                                decrypt($action->getArguments()['encrypted'] ?? encrypt([]))['recoveryCodes'] ?? [],
                            ),
                            instruction: __('filament-panels::auth/multi-factor/app/actions/set-up.modal.content.recovery_codes.instruction'),
                        ),
                    ]);

                $wizard->nextAction(static fn (Action $action): Action => $action->label('Verify and continue'));
            });
    }

    private function configureRecoveryCodeRegenerationAction(Action $action): void
    {
        $this->configureMfaModal($action, Width::Large);

        $passwordInput = $this->findPasswordInputInActionSchema($action);

        if ($passwordInput instanceof TextInput) {
            // Keep Filament's original TextInput instance so beforeFormValidated's rate-limit/validation closure stays bound.
            $passwordInput
                ->label('Current password')
                ->autofocus()
                ->belowContent(DispatchPasswordRecovery::authenticatedAction(inMfaModal: true));

            $action->schema([$passwordInput]);
        }

        $action
            ->modalDescription(function (): string {
                $user = Filament::auth()->user();

                if ($user instanceof User && $user->isStaffCapable()) {
                    return 'New codes replace old ones immediately. Lost your app? Ask a System Administrator to reset two-factor authentication.';
                }

                return 'New codes replace old ones immediately. Lost your app? Use Turn off with a saved code instead.';
            })
            ->registerModalActions([
                $this->buildShowNewRecoveryCodesAction(),
            ]);
    }

    private function buildShowNewRecoveryCodesAction(): Action
    {
        return $this->configureMfaModal(Action::make('showNewRecoveryCodes'), Width::Large)
            ->modalHeading(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.heading'))
            ->modalDescription(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.description'))
            ->schema(fn (array $arguments): array => [
                $this->buildRecoveryCodesGroup(
                    resolveCodes: fn (): array => array_values($arguments['recoveryCodes'] ?? []),
                ),
            ])
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalCloseButton(false)
            ->modalSubmitAction(static fn (Action $action): Action => $action
                ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.actions.submit.label'))
                ->color('danger'))
            ->modalCancelAction(false)
            ->cancelParentActions();
    }

    /**
     * Build the shared recovery-code list and copy/download controls for setup and regeneration modals.
     *
     * @param  Closure(): list<string>  $resolveCodes
     */
    private function buildRecoveryCodesGroup(Closure $resolveCodes, ?string $instruction = null): Group
    {
        $components = [];

        if (filled($instruction)) {
            $components[] = Text::make($instruction)
                ->weight(FontWeight::Bold)
                ->color('neutral');
        }

        $components[] = UnorderedList::make(fn (): array => array_map(
            fn (string $recoveryCode): Component => Text::make($recoveryCode)
                ->fontFamily(FontFamily::Mono)
                ->size(TextSize::ExtraSmall)
                ->color('neutral'),
            $resolveCodes(),
        ))
            ->size(TextSize::ExtraSmall);

        $components[] = Text::make(function () use ($resolveCodes): Htmlable {
            $serializedCodes = implode(PHP_EOL, $resolveCodes());

            return new HtmlString(
                __('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.0')
                .' '
                .Action::make('copy')
                    ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.copy.label'))
                    ->link()
                    ->alpineClickHandler('
                        window.navigator.clipboard.writeText('.Js::from($serializedCodes).')
                        $tooltip('.Js::from(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.messages.copied')).', {
                            theme: $store.theme,
                        })
                    ')
                    ->toHtml()
                .' '
                .__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.1')
                .' '
                .Action::make('download')
                    ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.download.label'))
                    ->link()
                    ->url('data:application/octet-stream,'.urlencode($serializedCodes))
                    ->extraAttributes(['download' => 'recovery-codes.txt'])
                    ->toHtml()
                .' '
                .__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.2')
            );
        });

        return Group::make($components)
            ->dense()
            ->extraAttributes(['class' => 'tala-mfa-recovery-step']);
    }

    private function attachForgotPasswordToActionSchema(Action $action): void
    {
        $passwordInput = $this->findPasswordInputInActionSchema($action);

        if ($passwordInput instanceof TextInput) {
            $passwordInput->belowContent(DispatchPasswordRecovery::authenticatedAction(inMfaModal: true));
        }
    }

    private function findPasswordInputInActionSchema(Action $action): ?TextInput
    {
        $schema = $action->getSchema(Schema::make());

        if (! $schema instanceof Schema) {
            return null;
        }

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof TextInput && $component->getKey(isAbsolute: false) === 'password') {
                return $component;
            }
        }

        return null;
    }
}
