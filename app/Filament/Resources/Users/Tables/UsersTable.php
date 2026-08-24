<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Authentication\StaffMfaService;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Actions\SystemAdministration\UserAccessService;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
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
                    ->badge(),
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
                Filter::make('verification')
                    ->schema([
                        Select::make('state')->options([
                            'verified' => 'Verified',
                            'pending' => 'Pending',
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(($data['state'] ?? null) === 'verified', fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'))
                        ->when(($data['state'] ?? null) === 'pending', fn (Builder $query): Builder => $query->whereNull('email_verified_at')))
                    ->indicateUsing(fn (array $data): ?string => match ($data['state'] ?? null) {
                        'verified' => 'Email: Verified',
                        'pending' => 'Email: Pending',
                        default => null,
                    }),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Created from'),
                        DatePicker::make('until')->label('Created until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
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
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('resendInvitation')
                        ->label('Resend invitation')
                        ->icon('heroicon-o-envelope')
                        ->visible(fn (User $record): bool => $record->status === User::StatusInvitationPending)
                        ->action(function (User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            $invitation = $record->staffInvitations()->latest()->firstOrFail();
                            app(StaffInvitationService::class)->resend($invitation, $actor);
                            Notification::make()->title('Invitation superseded and resent')->success()->send();
                        }),
                    Action::make('sendRecoveryLink')
                        ->label('Send password recovery')
                        ->icon('heroicon-o-key')
                        ->visible(fn (User $record): bool => $record->password !== null)
                        ->action(function (User $record): void {
                            Password::sendResetLink(['email' => $record->email]);
                            Notification::make()->title('Recovery request recorded')->success()->send();
                        }),
                    Action::make('changeStaffEmail')
                        ->label('Change Staff email')
                        ->icon('heroicon-o-at-symbol')
                        ->visible(fn (User $record): bool => $record->isStaffCapable())
                        ->schema([
                            TextInput::make('new_email')->label('New sign-in email')->email()->required()->maxLength(255),
                            TextInput::make('current_password')->label('Your current password')->password()->required(),
                            Textarea::make('reason')->required()->minLength(10),
                            TextInput::make('authority')->required(),
                        ])
                        ->action(function (array $data, User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(UserAccessService::class)->requestStaffEmailChange(
                                $actor,
                                $record,
                                $data['new_email'],
                                $data['current_password'],
                                $data['reason'],
                                $data['authority'],
                            );
                            Notification::make()
                                ->title('Successor email verification requested')
                                ->body('The current sign-in email remains active until the new address is verified.')
                                ->success()
                                ->send();
                        }),
                    Action::make('changeStaffAccess')
                        ->label('Change Staff access')
                        ->icon('heroicon-o-shield-check')
                        ->visible(fn (User $record): bool => $record->isStaffCapable())
                        ->fillForm(fn (User $record): array => [
                            'roles' => $record->roles()->whereIn('name', User::staffRoleNames())->pluck('name')->all(),
                        ])
                        ->schema([
                            Select::make('roles')->options(User::staffRoleOptions())->multiple()->required(),
                            Textarea::make('reason')->required()->minLength(10),
                            TextInput::make('authority')->required(),
                            TextInput::make('evidence_reference')->label('Evidence reference'),
                        ])
                        ->action(function (array $data, User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(UserAccessService::class)->changeStaffRoles(
                                $actor,
                                $record,
                                $data['roles'],
                                $data['reason'],
                                $data['authority'],
                                $data['evidence_reference'] ?? null,
                            );
                            Notification::make()->title('Staff access updated')->success()->send();
                        }),
                    Action::make('resetMfa')
                        ->label('Reset MFA')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->visible(fn (User $record): bool => $record->isStaffCapable() && filled($record->two_factor_secret))
                        ->schema([
                            TextInput::make('current_password')->label('Your current password')->password()->required(),
                            Textarea::make('reason')->required()->minLength(10),
                            TextInput::make('authority')->required(),
                            TextInput::make('evidence_reference')->label('Evidence reference'),
                        ])
                        ->action(function (array $data, User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(StaffMfaService::class)->reset(
                                $actor,
                                $record,
                                $data['current_password'],
                                $data['reason'],
                                $data['authority'],
                                $data['evidence_reference'] ?? null,
                            );
                            Notification::make()->title('MFA reset; re-enrollment required')->success()->send();
                        }),
                    Action::make('disable')
                        ->label('Disable account')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->required()
                                ->minLength(10)
                                ->columnSpanFull(),
                            TextInput::make('authority')->required(),
                            TextInput::make('evidence_reference')->label('Evidence reference'),
                        ])
                        ->requiresConfirmation()
                        ->visible(fn (User $record): bool => $record->status !== User::StatusDisabled)
                        ->action(function (array $data, User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(UserAccessService::class)->disable($actor, $record, $data['reason'], $data['authority'], $data['evidence_reference'] ?? null);
                            Notification::make()->title('Account disabled')->success()->send();
                        }),
                    Action::make('reactivate')
                        ->label('Reactivate account')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->schema([
                            Textarea::make('reason')->required()->minLength(10),
                            TextInput::make('authority')->required(),
                            TextInput::make('evidence_reference')->label('Evidence reference'),
                        ])
                        ->requiresConfirmation()
                        ->visible(fn (User $record): bool => $record->status === User::StatusDisabled)
                        ->action(function (array $data, User $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(UserAccessService::class)->reactivate($actor, $record, $data['reason'], $data['authority'], $data['evidence_reference'] ?? null);
                            Notification::make()->title('Account reactivated')->success()->send();
                        }),
                ])->label('Account actions'),
            ])
            ->toolbarActions([]);
    }
}
