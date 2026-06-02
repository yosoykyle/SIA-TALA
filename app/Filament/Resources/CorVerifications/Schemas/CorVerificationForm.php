<?php

namespace App\Filament\Resources\CorVerifications\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CorVerificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('student_profile_id')
                    ->required()
                    ->numeric(),
                TextInput::make('term_id')
                    ->required()
                    ->numeric(),
                TextInput::make('enrollment_id')
                    ->numeric(),
                TextInput::make('token')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('valid'),
                DateTimePicker::make('issued_at')
                    ->required(),
                DateTimePicker::make('expires_at'),
                DateTimePicker::make('revoked_at'),
                Textarea::make('revocation_reason')
                    ->columnSpanFull(),
            ]);
    }
}
