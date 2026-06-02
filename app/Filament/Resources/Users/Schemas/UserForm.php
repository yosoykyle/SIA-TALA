<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Staff Account')
                    ->description('System Super Admin creates staff accounts only. Applicant and student accounts are created by the enrollment intake flow.')
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First name')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Required legal/display first name for the staff account.'),
                        TextInput::make('middle_name')
                            ->label('Middle name')
                            ->maxLength(100)
                            ->helperText('Optional. Leave blank if not applicable.'),
                        TextInput::make('last_name')
                            ->label('Last name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('suffix')
                            ->label('Suffix')
                            ->maxLength(40)
                            ->placeholder('Jr., Sr., III')
                            ->helperText('Optional extension name.'),
                        TextInput::make('username')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used for staff login and internal identification.'),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Required when creating a staff account. Leave blank on edit to keep the current password.'),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->required()
                            ->default('active')
                            ->helperText('Archived status is controlled by the Archive/Restore account actions.'),
                        Select::make('roles')
                            ->label('Staff role')
                            ->relationship(
                                'roles',
                                'name',
                                fn (Builder $query): Builder => $query->whereIn('name', [
                                    'registrar',
                                    'accounting',
                                    'faculty',
                                    'academic-head',
                                    'system-super-admin',
                                ])
                            )
                            ->multiple()
                            ->maxItems(1)
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText('One role per account. Student/applicant roles are not manually assigned here.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
