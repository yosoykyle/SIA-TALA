<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Authentication\StaffMfaService;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Actions\SystemAdministration\UserAccessService;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->label('Email verified')
                    ->formatStateUsing(fn ($state): string => $state ? 'Verified' : 'Pending')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::statusLabel($state))
                    ->color(fn (?string $state): string => User::statusColor($state))
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Authorized workspaces')
                    ->badge()
                    ->separator(', ')
                    ->toggleable(),
                TextColumn::make('staffAccessProfile.staff_identifier')
                    ->label('Staff identifier')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('two_factor_confirmed_at')
                    ->label('MFA')
                    ->formatStateUsing(fn ($state): string => $state ? 'Enrolled' : 'Required')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
                TextColumn::make('last_successful_sign_in_at')
                    ->label('Last sign-in')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        User::StatusInvitationPending => 'Invitation pending',
                        User::StatusVerificationRequired => 'Verification required',
                        User::StatusActive => 'Active',
                        User::StatusDisabled => 'Disabled',
                    ]),
                SelectFilter::make('role')
                    ->relationship('roles', 'name', fn ($query) => $query->whereIn('name', User::staffRoleNames()))
                    ->options(User::staffRoleOptions()),
                Filter::make('last_successful_sign_in_at')
                    ->schema([
                        DatePicker::make('from')->label('Signed in from'),
                        DatePicker::make('until')->label('Signed in until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('last_successful_sign_in_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('last_successful_sign_in_at', '<=', $date))),
            ])
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['roles', 'staffAccessProfile', 'staffInvitations'])
                ->orderByRaw("CASE status WHEN 'invitation_pending' THEN 0 WHEN 'verification_required' THEN 1 WHEN 'disabled' THEN 2 ELSE 3 END")
                ->orderBy('name'))
            ->recordActions([
                ViewAction::make()->button()->color('gray'),
                self::resendInvitationAction()->button()->color('primary'),
                self::reactivateAction()->button(),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn ($livewire): string => filled($livewire->getTableSearch()) || count($livewire->getTable()->getFilterIndicators()) > 0 ? 'No matching accounts' : 'No accounts recorded')
            ->emptyStateDescription(fn ($livewire): string => filled($livewire->getTableSearch()) || count($livewire->getTable()->getFilterIndicators()) > 0
                ? 'Clear the search or adjust the status, role, and verification filters.'
                : 'Invited Staff and registered learner accounts appear here.')
            ->toolbarActions([]);
    }

    public static function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Resend invitation')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('System Administration will invalidate the prior activation link and send a new link to the recorded Staff email. The account, assigned role, and invitation history remain unchanged if sending fails; resend again or contact the account owner after checking the recorded state.')
            ->modalSubmitActionLabel('Invalidate and resend invitation')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->status === User::StatusInvitationPending)
            ->action(function (?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                $invitation = $target->staffInvitations()->latest()->firstOrFail();
                app(StaffInvitationService::class)->resend($invitation, $actor);
                Notification::make()->title('Invitation superseded and resent')->success()->send();
            });
    }

    public static function sendRecoveryLinkAction(): Action
    {
        return Action::make('sendRecoveryLink')
            ->label('Send password recovery')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('System Administration will request a password-recovery message for the recorded sign-in email. This does not change the password, roles, or account state; the account owner completes recovery from the valid link or requests another link if it expires.')
            ->modalSubmitActionLabel('Send recovery link')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->password !== null)
            ->action(function (?User $record, $livewire): void {
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($target instanceof User, 404);
                Password::sendResetLink(['email' => $target->email]);
                Notification::make()->title('Recovery request recorded')->success()->send();
            });
    }

    public static function changeStaffEmailAction(): Action
    {
        return Action::make('changeStaffEmail')
            ->label('Change Staff email')
            ->icon('heroicon-o-at-symbol')
            ->color('gray')
            ->visible(fn (?User $record, $livewire): bool => (bool) self::resolveUserRecord($record, $livewire)?->isStaffCapable())
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('System Administration will request verification of the new Staff sign-in email. The current verified email remains active until the new address is verified. Cancel or let the request expire to preserve the current address.')
            ->modalSubmitActionLabel('Send verification request')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('new_email')->label('New sign-in email')->email()->required()->maxLength(255),
                    TextInput::make('current_password')->label('Your current password')->password()->required(),
                ]),
                Textarea::make('reason')->required()->minLength(10),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                app(UserAccessService::class)->requestStaffEmailChange(
                    $actor,
                    $target,
                    $data['new_email'],
                    $data['current_password'],
                    $data['reason'],
                );
                Notification::make()
                    ->title('New email verification requested')
                    ->body('The current sign-in email remains active until the new address is verified.')
                    ->success()
                    ->send();
            });
    }

    public static function changeStaffAccessAction(): Action
    {
        return Action::make('changeStaffAccess')
            ->label('Change Staff access')
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->visible(fn (?User $record, $livewire): bool => (bool) self::resolveUserRecord($record, $livewire)?->isStaffCapable())
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('The selected roles will replace this account\'s current Staff workspaces using the supplied authority. Learner roles, linked records, and access history remain. System Administration owns this change; cancel to preserve the current Staff access.')
            ->modalSubmitActionLabel('Save Staff access change')
            ->fillForm(fn (?User $record, $livewire): array => [
                'roles' => self::resolveUserRecord($record, $livewire)?->roles()->whereIn('name', User::staffRoleNames())->pluck('name')->all() ?? [],
            ])
            ->schema([
                Select::make('roles')->options(User::staffRoleOptions())->multiple()->required(),
                Textarea::make('reason')->required()->minLength(10),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required(),
                    TextInput::make('evidence_reference')->label('Evidence reference'),
                ]),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                app(UserAccessService::class)->changeStaffRoles(
                    $actor,
                    $target,
                    $data['roles'],
                    $data['reason'],
                    $data['authority'],
                    $data['evidence_reference'] ?? null,
                );
                Notification::make()->title('Staff access updated')->success()->send();
            });
    }

    public static function resetMfaAction(): Action
    {
        return Action::make('resetMfa')
            ->label('Reset MFA')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('gray')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User && $target->isStaffCapable() && filled($target->two_factor_secret);
            })
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('System Administration must already have completed the approved external identity check. Resetting ends active sessions, invalidates the current authenticator and recovery codes, and requires Staff MFA re-enrollment at the next sign-in. Cancel to preserve the current factor.')
            ->modalSubmitActionLabel('Reset MFA and end sessions')
            ->schema([
                TextInput::make('current_password')->label('Your current password')->password()->required(),
                Textarea::make('reason')->required()->minLength(10),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required(),
                    TextInput::make('evidence_reference')->label('Evidence reference'),
                ]),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                app(StaffMfaService::class)->reset(
                    $actor,
                    $target,
                    $data['current_password'],
                    $data['reason'],
                    $data['authority'],
                    $data['evidence_reference'] ?? null,
                );
                Notification::make()->title('MFA reset; re-enrollment required')->success()->send();
            });
    }

    public static function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Disable account')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                Textarea::make('reason')
                    ->required()
                    ->minLength(10)
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required(),
                    TextInput::make('evidence_reference')->label('Evidence reference'),
                ]),
            ])
            ->requiresConfirmation()
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Disabling immediately ends active sessions and blocks every authorized workspace while preserving roles, linked records, and access history. System Administration owns the action; an authorized reactivation is the recovery path. Cancel to leave the account active.')
            ->modalSubmitActionLabel('Disable account and end sessions')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->status !== User::StatusDisabled)
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                app(UserAccessService::class)->disable($actor, $target, $data['reason'], $data['authority'], $data['evidence_reference'] ?? null);
                Notification::make()->title('Account disabled')->success()->send();
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate account')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                Textarea::make('reason')->required()->minLength(10),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required(),
                    TextInput::make('evidence_reference')->label('Evidence reference'),
                ]),
            ])
            ->requiresConfirmation()
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Reactivation restores sign-in through the account\'s preserved roles and linked records; it does not recreate or broaden access. Staff MFA and session rules still apply. System Administration owns the action; disable again through the recorded process if the authority is withdrawn.')
            ->modalSubmitActionLabel('Reactivate preserved access')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->status === User::StatusDisabled)
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                app(UserAccessService::class)->reactivate($actor, $target, $data['reason'], $data['authority'], $data['evidence_reference'] ?? null);
                Notification::make()->title('Account reactivated')->success()->send();
            });
    }

    private static function resolveUserRecord(?User $record, mixed $livewire): ?User
    {
        if ($record instanceof User) {
            return $record;
        }

        if (is_object($livewire) && method_exists($livewire, 'getRecord')) {
            $resolved = $livewire->getRecord();

            return $resolved instanceof User ? $resolved : null;
        }

        return null;
    }
}
