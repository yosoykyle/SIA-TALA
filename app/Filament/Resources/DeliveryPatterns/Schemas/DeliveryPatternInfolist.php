<?php

namespace App\Filament\Resources\DeliveryPatterns\Schemas;

use App\Models\DeliveryPattern;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeliveryPatternInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delivery Pattern')
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('version')
                            ->badge(),
                        TextEntry::make('name'),
                        TextEntry::make('modality')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? 'Generic' : (DeliveryPattern::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                        TextEntry::make('subject_routing')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (DeliveryPattern::subjectRoutingOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                        TextEntry::make('enforcement_level')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (DeliveryPattern::enforcementLevelOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                        TextEntry::make('allowed_days')
                            ->formatStateUsing(fn (?array $state): string => collect($state ?? [])
                                ->map(fn (int $day): string => DeliveryPattern::dayOptions()[$day] ?? (string) $day)
                                ->implode(', '))
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        IconEntry::make('default_room_required')
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->boolean(),
                        IconEntry::make('is_frozen')
                            ->boolean(),
                        TextEntry::make('used_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
