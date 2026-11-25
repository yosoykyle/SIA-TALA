<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Authentication\StaffMfaService;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Actions\SystemAdministration\UserAccessService;
use App\Models\User;
use App\Support\DisplayDateTime;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class UsersTable
{
    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return [
            ...User::staffRoleOptions(),
            'student' => 'Student',
            'applicant' => 'Applicant',
        ];
    }

    public static function roleLabel(mixed $role): string
    {
        if (! is_string($role) || trim($role) === '') {
            return 'Unassigned';
        }

        return self::roleOptions()[$role] ?? str($role)->replace(['-', '_'], ' ')->headline()->toString();
    }

    public static function roleColor(mixed $role): string
    {
        return match ($role) {
            User::StaffRoleSystemSuperAdmin => 'danger',
            User::StaffRoleAccounting, User::StaffRoleAcademicHead => 'warning',
            User::StaffRoleRegistrar, User::StaffRoleFaculty => 'primary',
            default => 'gray',
        };
    }

    public static function roleIcon(mixed $role): ?string
    {
        return match ($role) {
            User::StaffRoleSystemSuperAdmin => 'heroicon-m-shield-check',
            User::StaffRoleAccounting => 'heroicon-m-banknotes',
            User::StaffRoleAcademicHead => 'heroicon-m-scale',
            User::StaffRoleRegistrar => 'heroicon-m-clipboard-document-check',
            User::StaffRoleFaculty => 'heroicon-m-academic-cap',
            'student' => 'heroicon-m-user',
            'applicant' => 'heroicon-m-identification',
            default => null,
        };
    }

    public static function displayName(User $record): string
    {
        return filled($record->name) ? (string) $record->name : 'Applicant account #'.$record->getKey();
    }

    public static function initials(User $record): string
    {
        if (filled($record->first_name) && filled($record->last_name)) {
            return Str::upper(mb_substr(trim((string) $record->first_name), 0, 1).mb_substr(trim((string) $record->last_name), 0, 1));
        }

        $name = trim((string) $record->name);

        if ($name !== '') {
            $words = preg_split('/\s+/', $name) ?: [];
            $first = mb_substr($words[0] ?? '', 0, 1);
            $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : mb_substr($words[0] ?? '', 1, 1);
            $initials = Str::upper($first.$last);

            if ($initials !== '') {
                return $initials;
            }
        }

        return 'AC';
    }

    /**
     * @return array{label: ?string, value: ?string, pill: string}
     */
    public static function institutionalIdentity(User $record): array
    {
        $staffId = filled($record->staffAccessProfile?->staff_identifier)
            ? trim((string) $record->staffAccessProfile->staff_identifier)
            : null;
        $studentNumber = filled($record->studentProfile?->student_number)
            ? trim((string) $record->studentProfile->student_number)
            : null;
        $applicationReference = filled($record->currentAdmissionApplication?->application_reference)
            ? trim((string) $record->currentAdmissionApplication->application_reference)
            : null;

        if ($record->isStaffCapable() && $staffId !== null) {
            return ['label' => 'Staff ID', 'value' => $staffId, 'pill' => 'Staff ID: '.$staffId];
        }

        if ($record->hasAssignedRole('student') && $studentNumber !== null) {
            return ['label' => 'Student No.', 'value' => $studentNumber, 'pill' => 'Student No.: '.$studentNumber];
        }

        if ($record->hasAssignedRole('applicant') && $applicationReference !== null) {
            return ['label' => 'App Ref', 'value' => $applicationReference, 'pill' => 'App Ref: '.$applicationReference];
        }

        if ($staffId !== null) {
            return ['label' => 'Staff ID', 'value' => $staffId, 'pill' => 'Staff ID: '.$staffId];
        }

        if ($studentNumber !== null) {
            return ['label' => 'Student No.', 'value' => $studentNumber, 'pill' => 'Student No.: '.$studentNumber];
        }

        if ($applicationReference !== null) {
            return ['label' => 'App Ref', 'value' => $applicationReference, 'pill' => 'App Ref: '.$applicationReference];
        }

        return ['label' => null, 'value' => null, 'pill' => 'Not assigned'];
    }

    public static function accountSubtitle(User $record): string
    {
        $identity = self::institutionalIdentity($record);

        return $identity['value'] !== null
            ? $record->email.' · '.$identity['pill']
            : (string) $record->email;
    }

    private static function accountSubtitleHtml(User $record): HtmlString
    {
        $identity = self::institutionalIdentity($record);
        $email = '<span style="display:block;overflow-wrap:anywhere;">'.e((string) $record->email).'</span>';

        if ($identity['value'] === null) {
            return new HtmlString($email);
        }

        return new HtmlString($email.'<span style="display:block;margin-top:0.125rem;">'.e($identity['pill']).'</span>');
    }

    public static function mfaStatusLabel(User $record): string
    {
        if (! $record->isStaffCapable()) {
            return 'Not required';
        }

        return filled($record->two_factor_confirmed_at) ? 'Enrolled' : 'Setup required';
    }

    public static function mfaStatusColor(User $record): string
    {
        if (! $record->isStaffCapable()) {
            return 'gray';
        }

        return filled($record->two_factor_confirmed_at) ? 'success' : 'warning';
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->state(fn (User $record): string => self::displayName($record))
                    ->description(fn (User $record): HtmlString => self::accountSubtitleHtml($record))
                    ->weight('semibold')
                    ->wrap()
                    ->extraCellAttributes(['style' => 'max-width: 13.5rem; white-space: normal; overflow-wrap: anywhere;'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $identity) use ($search): void {
                        $identity->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('username', 'like', '%'.$search.'%')
                            ->orWhereHas('staffAccessProfile', fn (Builder $profile): Builder => $profile->where('staff_identifier', 'like', '%'.$search.'%'))
                            ->orWhereHas('studentProfile', fn (Builder $student): Builder => $student->where('student_number', 'like', '%'.$search.'%'))
                            ->orWhereHas('admissionApplications', fn (Builder $applications): Builder => $applications->where('application_reference', 'like', '%'.$search.'%'));
                    }))
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->state(fn (User $record): array => $record->roles->pluck('name')->values()->all())
                    ->formatStateUsing(fn (mixed $state): string => self::roleLabel($state))
                    ->badge()
                    ->color(fn (mixed $state): string => self::roleColor($state))
                    ->icon(fn (mixed $state): ?string => self::roleIcon($state))
                    ->wrap()
                    ->placeholder('Unassigned'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::statusLabel($state))
                    ->color(fn (?string $state): string => User::statusColor($state))
                    ->wrap(),
                TextColumn::make('two_factor_confirmed_at')
                    ->label('MFA')
                    ->state(fn (User $record): string => self::mfaStatusLabel($record))
                    ->badge()
                    ->color(fn (User $record): string => self::mfaStatusColor($record))
                    ->wrap(),
                TextColumn::make('last_successful_sign_in_at')
                    ->label('Last sign-in')
                    ->state(fn (User $record): string => DisplayDateTime::format($record->last_successful_sign_in_at, 'M j, Y', 'Never'))
                    ->description(fn (User $record): ?string => $record->last_successful_sign_in_at !== null
                        ? DisplayDateTime::format($record->last_successful_sign_in_at, 'g:i A')
                        : null)
                    ->sortable()
                    ->visibleFrom('xl')
                    ->extraCellAttributes(['class' => 'tabular-nums', 'style' => 'white-space: normal;']),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role / workspace')
                    ->options(self::roleOptions())
                    ->multiple()
                    ->columnSpanFull()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = array_values(array_filter(
                            (array) ($data['values'] ?? (filled($data['value'] ?? null) ? [$data['value']] : [])),
                            static fn (mixed $role): bool => is_string($role) && trim($role) !== '',
                        ));

                        return $values !== []
                            ? $query->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', $values))
                            : $query;
                    }),
                SelectFilter::make('status')
                    ->label('Account status')
                    ->options([
                        User::StatusInvitationPending => 'Invitation pending',
                        User::StatusVerificationRequired => 'Verification required',
                        User::StatusActive => 'Active',
                        User::StatusDisabled => 'Disabled',
                    ]),
                SelectFilter::make('verification')
                    ->label('Verification status')
                    ->options([
                        'verified' => 'Verified',
                        'pending' => 'Pending verification',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'verified' => $query->whereNotNull('email_verified_at'),
                        'pending' => $query->whereNull('email_verified_at'),
                        default => $query,
                    }),
                Filter::make('created_at')
                    ->label('Created date')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            DatePicker::make('from')->label('Created from'),
                            DatePicker::make('until')->label('Created until'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = Indicator::make('Created from '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = Indicator::make('Created until '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
                Filter::make('last_successful_sign_in_at')
                    ->label('Last sign-in')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            DatePicker::make('from')->label('Signed in from'),
                            DatePicker::make('until')->label('Signed in until'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('last_successful_sign_in_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('last_successful_sign_in_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = Indicator::make('Signed in from '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = Indicator::make('Signed in until '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size(Size::Small))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->columnManager(false)
            ->searchPlaceholder('Search name, email, or ID')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['roles', 'staffAccessProfile', 'staffInvitations', 'studentProfile', 'currentAdmissionApplication'])
                ->orderByRaw("CASE status WHEN 'invitation_pending' THEN 0 WHEN 'verification_required' THEN 1 WHEN 'disabled' THEN 2 ELSE 3 END")
                ->orderBy('name'))
            ->recordActions([
                ViewAction::make()
                    ->button()
                    ->size(Size::Small)
                    ->color('gray'),
                ActionGroup::make([
                    self::resendInvitationAction(),
                    self::correctPendingEmailAction(),
                    self::resendVerificationAction(),
                    self::sendRecoveryLinkAction(),
                    self::editRoleAndDetailsAction(),
                    self::changeSignInEmailAction(),
                    self::reactivateAction(),
                    self::resetMfaAction(),
                    self::cancelInvitationAction(),
                    self::disableAction(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedChevronDown)
                    ->iconPosition(IconPosition::After)
                    ->button()
                    ->size(Size::Small)
                    ->color('gray')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn ($livewire): string => filled($livewire->getTableSearch()) || count($livewire->getTable()->getFilterIndicators()) > 0
                ? 'No matching accounts'
                : 'No accounts in this view')
            ->emptyStateDescription(fn ($livewire): string => filled($livewire->getTableSearch()) || count($livewire->getTable()->getFilterIndicators()) > 0
                ? 'Clear the search or adjust the filters to see other accounts.'
                : 'Invited staff and registered learner accounts matching this queue appear here.')
            ->toolbarActions([
                self::inviteStaffAction(),
            ]);
    }

    public static function inviteStaffAction(): Action
    {
        return Action::make('inviteStaff')
            ->label('Invite staff')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->size(Size::Small)
            ->modalHeading('Invite a staff member')
            ->modalDescription('TALA emails an activation link so this staff member sets their own password and MFA.')
            ->modalSubmitActionLabel('Send staff invitation')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('email')
                        ->label('Institutional email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->helperText('Existing verified accounts with this email are reused safely.'),
                    TextInput::make('staff_identifier')
                        ->label('Staff ID')
                        ->placeholder('Optional employee or faculty ID')
                        ->maxLength(100),
                    TextInput::make('first_name')
                        ->label('First name')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('last_name')
                        ->label('Last name')
                        ->required()
                        ->maxLength(100),
                ]),
                Select::make('roles')
                    ->label('Assigned staff roles')
                    ->options(User::staffRoleOptions())
                    ->multiple()
                    ->required()
                    ->helperText('Select one or more workspaces this staff member is approved to access.'),
                Textarea::make('reason')
                    ->label('Note / Reason')
                    ->placeholder('Optional note for audit records')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                app(StaffInvitationService::class)->invite($actor, $data);

                Notification::make()
                    ->title('Staff invitation sent')
                    ->body('The invitation is recorded and emailed. If the address had a typo, open Actions and choose Correct email & resend.')
                    ->success()
                    ->send();
            });
    }

    public static function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Resend invitation')
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Resend staff invitation')
            ->modalDescription('This will invalidate the prior activation link and send a new link to the recorded staff email. The account, assigned roles, and invitation history stay unchanged if delivery fails; resend again or contact the account owner after checking the recorded state.')
            ->modalSubmitActionLabel('Invalidate and resend')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->status === User::StatusInvitationPending)
            ->action(function (?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                $invitation = $target->staffInvitations()->latest()->firstOrFail();
                app(StaffInvitationService::class)->resend($invitation, $actor);
                Notification::make()->title('Invitation resent')->success()->send();
            });
    }

    public static function correctPendingEmailAction(): Action
    {
        return Action::make('correctPendingEmail')
            ->label('Correct email & resend')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Correct pending invitation email')
            ->modalDescription('Update the sign-in email for this unactivated staff invitation, invalidate the prior activation link, and send a fresh link to the corrected address.')
            ->modalSubmitActionLabel('Update email and resend')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User
                    && $target->status === User::StatusInvitationPending
                    && $target->password === null
                    && $target->last_successful_sign_in_at === null;
            })
            ->fillForm(fn (?User $record, $livewire): array => [
                'new_email' => self::resolveUserRecord($record, $livewire)?->email,
            ])
            ->schema([
                TextInput::make('new_email')
                    ->label('Corrected institutional email')
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);

                app(StaffInvitationService::class)->correctPendingEmailAndResend(
                    $target,
                    (string) $data['new_email'],
                    $actor,
                );

                Notification::make()
                    ->title('Invitation email updated and resent')
                    ->body('The prior activation link was invalidated and a new link was emailed to the corrected address.')
                    ->success()
                    ->send();
            });
    }

    public static function cancelInvitationAction(): Action
    {
        return Action::make('cancelInvitation')
            ->label('Cancel invitation')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Cancel pending staff invitation')
            ->modalDescription('Cancelling removes this never-activated pending invitation and its placeholder account so the email can be invited cleanly later. An audit entry is preserved. Cancel this dialog to leave the pending invitation active.')
            ->modalSubmitActionLabel('Cancel invitation')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User
                    && $target->status === User::StatusInvitationPending
                    && $target->password === null
                    && $target->last_successful_sign_in_at === null;
            })
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (optional)')
                    ->placeholder('e.g., Entered wrong email address or invitation no longer needed')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);

                app(StaffInvitationService::class)->cancelPendingInvitation(
                    $target,
                    $actor,
                    filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                );

                Notification::make()
                    ->title('Pending invitation cancelled')
                    ->body('The unactivated invitation and placeholder account were removed and logged.')
                    ->success()
                    ->send();
            });
    }

    public static function resendVerificationAction(): Action
    {
        return Action::make('resendVerification')
            ->label('Resend verification')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Resend email verification')
            ->modalDescription('Send a fresh verification email to this account’s recorded address. Assigned roles and account status stay unchanged until the account owner completes verification.')
            ->modalSubmitActionLabel('Send verification email')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User
                    && $target->email_verified_at === null
                    && ! in_array($target->status, [User::StatusInvitationPending, User::StatusDisabled], true);
            })
            ->action(function (?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);
                $target->sendEmailVerificationNotification();
                Notification::make()
                    ->title('Verification email sent')
                    ->body('A fresh verification link was emailed to the recorded address.')
                    ->success()
                    ->send();
            });
    }

    public static function sendRecoveryLinkAction(): Action
    {
        return Action::make('sendRecoveryLink')
            ->label('Send password reset link')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Send password reset link')
            ->modalDescription('Send a password reset email to the recorded sign-in address. This does not change the password, roles, or account state; the account owner completes recovery from the valid link or requests another link if it expires.')
            ->modalSubmitActionLabel('Send password reset link')
            ->visible(fn (?User $record, $livewire): bool => self::resolveUserRecord($record, $livewire)?->password !== null)
            ->action(function (?User $record, $livewire): void {
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($target instanceof User, 404);
                Password::sendResetLink(['email' => $target->email]);
                Notification::make()->title('Password reset link sent')->success()->send();
            });
    }

    public static function changeSignInEmailAction(): Action
    {
        return Action::make('changeSignInEmail')
            ->label('Change sign-in email')
            ->icon(Heroicon::OutlinedAtSymbol)
            ->color('gray')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User
                    && ! $target->isStaffCapable()
                    && $target->status !== User::StatusInvitationPending;
            })
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Change sign-in email')
            ->modalDescription('Send a verification link to confirm a new sign-in email for this account. The current verified email remains active and receives a security alert until the new address is verified. Cancel or let the link expire to keep the current address.')
            ->modalSubmitActionLabel('Send verification link')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('new_email')
                        ->label('New sign-in email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('current_password')
                        ->label('Your current password')
                        ->password()
                        ->required(),
                ]),
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('State why this sign-in email replacement is being requested (minimum 10 characters).')
                    ->required()
                    ->minLength(10)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);

                app(UserAccessService::class)->requestEmailChange(
                    $actor,
                    $target,
                    (string) $data['new_email'],
                    (string) $data['current_password'],
                    (string) $data['reason'],
                );

                Notification::make()
                    ->title('Email verification sent')
                    ->body('The current sign-in email remains active until the new address is verified.')
                    ->success()
                    ->send();
            });
    }

    public static function editRoleAndDetailsAction(): Action
    {
        return Action::make('editRoleAndDetails')
            ->label('Edit role & details')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (?User $record, $livewire): bool => (bool) self::resolveUserRecord($record, $livewire)?->isStaffCapable())
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Edit role & details')
            ->modalDescription('Update assigned Staff workspaces, Staff ID, or request a sign-in email change under recorded approval. If you enter a new email, the current verified email remains active until the new address is verified. Learner roles, linked records, and access history stay intact; cancel to preserve the current Staff access.')
            ->modalSubmitActionLabel('Save changes')
            ->fillForm(function (?User $record, $livewire): array {
                $target = self::resolveUserRecord($record, $livewire);

                return [
                    'roles' => $target?->roles()->whereIn('name', User::staffRoleNames())->pluck('name')->all() ?? [],
                    'staff_identifier' => $target?->staffAccessProfile?->staff_identifier,
                    'new_email' => null,
                    'current_password' => null,
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('roles')
                        ->label('Assigned staff roles')
                        ->options(User::staffRoleOptions())
                        ->multiple()
                        ->required()
                        ->disabled(function (?User $record, $livewire): bool {
                            $target = self::resolveUserRecord($record, $livewire);
                            $actor = auth()->user();

                            return $actor instanceof User && $target instanceof User && $actor->is($target);
                        })
                        ->dehydrated()
                        ->helperText(function (?User $record, $livewire): ?string {
                            $target = self::resolveUserRecord($record, $livewire);
                            $actor = auth()->user();

                            return $actor instanceof User && $target instanceof User && $actor->is($target)
                                ? 'You cannot change your own staff roles.'
                                : 'Replaces this account’s current Staff workspaces.';
                        }),
                    TextInput::make('staff_identifier')
                        ->label('Staff ID')
                        ->placeholder('Optional employee or faculty ID')
                        ->maxLength(100),
                ]),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('new_email')
                        ->label('New sign-in email (optional)')
                        ->email()
                        ->maxLength(255)
                        ->helperText('Leave blank to keep the current email. A verification link is sent before the address changes.'),
                    TextInput::make('current_password')
                        ->label('Your current password')
                        ->password()
                        ->required(fn (Get $get): bool => filled($get('new_email')))
                        ->helperText('Required only when requesting a new sign-in email.'),
                ]),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(10)
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')
                        ->label('Approval memo or reference')
                        ->placeholder('e.g., Memo 2026-014 or approving office')
                        ->required(),
                    TextInput::make('evidence_reference')
                        ->label('Evidence reference')
                        ->placeholder('Optional supporting document reference'),
                ]),
            ])
            ->action(function (array $data, ?User $record, $livewire): void {
                $actor = auth()->user();
                $target = self::resolveUserRecord($record, $livewire);
                abort_unless($actor instanceof User && $target instanceof User, 403);

                $service = app(UserAccessService::class);
                $currentRoles = $target->roles()
                    ->whereIn('name', User::staffRoleNames())
                    ->pluck('name')
                    ->sort()
                    ->values()
                    ->all();
                $requestedRoles = collect($data['roles'] ?? [])
                    ->filter(static fn (mixed $role): bool => is_string($role) && trim($role) !== '')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $rolesChanged = ! $actor->is($target) && $requestedRoles !== $currentRoles;

                $currentStaffId = filled($target->staffAccessProfile?->staff_identifier)
                    ? trim((string) $target->staffAccessProfile->staff_identifier)
                    : null;
                $requestedStaffId = filled($data['staff_identifier'] ?? null)
                    ? Str::squish((string) $data['staff_identifier'])
                    : null;
                $staffIdChanged = $requestedStaffId !== $currentStaffId;

                $requestedEmail = filled($data['new_email'] ?? null)
                    ? Str::lower(trim((string) $data['new_email']))
                    : null;
                $emailChanged = $requestedEmail !== null && $requestedEmail !== Str::lower((string) $target->email);

                if (! $rolesChanged && ! $staffIdChanged && ! $emailChanged) {
                    Notification::make()
                        ->title('No changes to save')
                        ->body('Roles, Staff ID, and sign-in email are unchanged.')
                        ->send();

                    return;
                }

                $reason = (string) $data['reason'];
                $authority = (string) $data['authority'];
                $evidenceReference = filled($data['evidence_reference'] ?? null)
                    ? (string) $data['evidence_reference']
                    : null;

                if ($rolesChanged) {
                    $service->changeStaffRoles(
                        $actor,
                        $target,
                        $requestedRoles,
                        $reason,
                        $authority,
                        $evidenceReference,
                    );
                }

                if ($staffIdChanged) {
                    $service->updateStaffIdentifier(
                        $actor,
                        $target,
                        $requestedStaffId,
                        $reason,
                        $authority,
                        $evidenceReference,
                    );
                }

                if ($emailChanged) {
                    $service->requestEmailChange(
                        $actor,
                        $target,
                        $requestedEmail,
                        (string) ($data['current_password'] ?? ''),
                        $reason,
                        $authority,
                    );
                }

                $summary = array_values(array_filter([
                    $rolesChanged ? 'Staff roles updated.' : null,
                    $staffIdChanged ? 'Staff ID updated.' : null,
                    $emailChanged ? 'Email verification requested; the current sign-in email stays active until verified.' : null,
                ]));

                Notification::make()
                    ->title('Staff details updated')
                    ->body(implode(' ', $summary))
                    ->success()
                    ->send();
            });
    }

    public static function resetMfaAction(): Action
    {
        return Action::make('resetMfa')
            ->label('Reset MFA')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('warning')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);

                return $target instanceof User && $target->isStaffCapable() && filled($target->two_factor_secret);
            })
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Reset staff MFA')
            ->modalDescription('Confirm the approved external identity check is complete before proceeding. Resetting ends active sessions, invalidates the current authenticator and recovery codes, and requires staff MFA re-enrollment at next sign-in. Cancel to preserve the current factor.')
            ->modalSubmitActionLabel('Reset MFA and end sessions')
            ->schema([
                TextInput::make('current_password')->label('Your current password')->password()->required(),
                Textarea::make('reason')->label('Reason')->required()->minLength(10),
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
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
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
            ->modalHeading('Disable account')
            ->modalDescription('Disabling immediately ends active sessions and blocks every authorized workspace while preserving roles, linked records, and access history. An authorized reactivation is the recovery path. Cancel to leave the account active.')
            ->modalSubmitActionLabel('Disable account and end sessions')
            ->visible(function (?User $record, $livewire): bool {
                $target = self::resolveUserRecord($record, $livewire);
                $actor = auth()->user();

                return $target instanceof User
                    && $target->status !== User::StatusDisabled
                    && ! ($actor instanceof User && $actor->is($target));
            })
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
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->minLength(10),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('authority')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required(),
                    TextInput::make('evidence_reference')->label('Evidence reference'),
                ]),
            ])
            ->requiresConfirmation()
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Reactivate account')
            ->modalDescription('Reactivation restores sign-in through the account\'s preserved roles and linked records without broadening access. Staff MFA and session rules still apply. You can disable again through the recorded process if approval is withdrawn.')
            ->modalSubmitActionLabel('Reactivate account')
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
