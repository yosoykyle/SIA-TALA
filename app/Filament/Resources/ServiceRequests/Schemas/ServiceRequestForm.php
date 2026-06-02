<?php

namespace App\Filament\Resources\ServiceRequests\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ServiceRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->relationship('studentProfile', 'id')
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'id'),
                TextInput::make('category')
                    ->required(),
                TextInput::make('sub_type'),
                TextInput::make('status')
                    ->required()
                    ->default('submitted'),
                Textarea::make('details')
                    ->columnSpanFull(),
                TextInput::make('attachment_paths'),
                TextInput::make('assigned_to')
                    ->numeric(),
                TextInput::make('resolved_by')
                    ->numeric(),
                DateTimePicker::make('resolved_at'),
            ]);
    }
}
