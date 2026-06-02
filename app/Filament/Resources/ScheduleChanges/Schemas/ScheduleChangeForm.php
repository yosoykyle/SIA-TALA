<?php

namespace App\Filament\Resources\ScheduleChanges\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requested Schedule Change')
                    ->description('Registrar proposes the change; Academic Head approves it; Registrar applies it through table actions.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('section_meeting_id')
                            ->label('Official schedule')
                            ->relationship('sectionMeeting', 'id')
                            ->searchable()
                            ->preload(),
                        Hidden::make('status')
                            ->default('proposed')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Textarea::make('old_payload')
                            ->label('Current schedule data')
                            ->required()
                            ->rows(8)
                            ->rules(['json'])
                            ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $state)
                            ->dehydrateStateUsing(fn (?string $state): array => json_decode((string) $state, true) ?: [])
                            ->helperText('Enter valid JSON describing the current schedule values.'),
                        Textarea::make('new_payload')
                            ->label('Requested schedule data')
                            ->required()
                            ->rows(8)
                            ->rules(['json'])
                            ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $state)
                            ->dehydrateStateUsing(fn (?string $state): array => json_decode((string) $state, true) ?: [])
                            ->helperText('Enter valid JSON describing the requested replacement values.'),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Hidden::make('requested_by')
                            ->default(fn (): ?int => auth()->id())
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
