<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\SystemAdministration\StaffInvitationService;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteStaff')
                ->label('Invite Staff')
                ->icon(Heroicon::OutlinedEnvelope)
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255),
                    TextInput::make('first_name')->label('First name')->required()->maxLength(100),
                    TextInput::make('middle_name')->label('Middle name')->maxLength(100),
                    TextInput::make('last_name')->label('Last name')->required()->maxLength(100),
                    TextInput::make('suffix')->maxLength(40),
                    TextInput::make('staff_identifier')->label('Staff identifier')->maxLength(100),
                    Select::make('roles')
                        ->label('Fixed Staff roles')
                        ->options(User::staffRoleOptions())
                        ->multiple()
                        ->required(),
                    Textarea::make('reason')->required()->minLength(10)->columnSpanFull(),
                    TextInput::make('authority')->required()->maxLength(255),
                    TextInput::make('evidence_reference')->label('Evidence reference')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(StaffInvitationService::class)->invite($actor, $data);

                    Notification::make()
                        ->title('Staff invitation created')
                        ->body('Access is recorded. If delivery fails, use Resend invitation from the account row.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
