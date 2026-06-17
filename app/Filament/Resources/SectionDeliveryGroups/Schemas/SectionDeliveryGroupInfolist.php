<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Schemas;

use App\Models\SectionDeliveryGroup;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SectionDeliveryGroupInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Section Delivery Group')
                    ->schema([
                        TextEntry::make('section.term.term_name')
                            ->label('Term'),
                        TextEntry::make('section.name')
                            ->label('Section'),
                        TextEntry::make('deliveryPattern.name')
                            ->label('Delivery Pattern'),
                        TextEntry::make('name')
                            ->label('Group Name'),
                        TextEntry::make('modality')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::statusOptions()[$state] ?? str($state)->headline()->toString())),
                        TextEntry::make('capacity')
                            ->numeric(),
                        TextEntry::make('assigned_count')
                            ->label('Assigned')
                            ->numeric(),
                        TextEntry::make('available_seats')
                            ->label('Available')
                            ->state(fn (SectionDeliveryGroup $record): int => $record->availableSeats())
                            ->badge(),
                        IconEntry::make('room_required')
                            ->boolean(),
                        TextEntry::make('room')
                            ->placeholder('-'),
                        TextEntry::make('closed_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
