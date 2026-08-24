<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account identity')
                ->schema([
                    TextEntry::make('name')->placeholder('Applicant account'),
                    TextEntry::make('email')->label('Verified sign-in email'),
                    TextEntry::make('staffAccessProfile.staff_identifier')->label('Staff identifier')->placeholder('Not assigned'),
                    TextEntry::make('status')->badge(),
                ])->columns(2),
            Section::make('Authorized contexts')
                ->schema([
                    TextEntry::make('roles.name')->label('Roles')->badge()->separator(', '),
                    TextEntry::make('email_verified_at')->label('Email verified')->dateTime()->placeholder('Pending'),
                    TextEntry::make('two_factor_confirmed_at')->label('MFA enrolled')->dateTime()->placeholder('Not enrolled'),
                    TextEntry::make('last_successful_sign_in_at')->label('Last successful sign-in')->dateTime()->placeholder('No successful sign-in recorded'),
                ])->columns(2),
            Section::make('Access history')
                ->schema([
                    TextEntry::make('disabled_at')->label('Disabled at')->dateTime()->placeholder('Active'),
                    TextEntry::make('disabled_reason')->label('Disablement reason')->placeholder('Not applicable'),
                    TextEntry::make('disabled_authority')->label('Authority')->placeholder('Not applicable'),
                    TextEntry::make('created_at')->label('Account created')->dateTime(),
                ])->columns(2),
        ]);
    }
}
