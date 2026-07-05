<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Schemas;

use App\Models\SectionDeliveryGroup;
use Filament\Infolists\Components\KeyValueEntry;
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
                        TextEntry::make('section.termOffering.term.label')
                            ->label('Term'),
                        TextEntry::make('section.termOffering.curriculumEntry.courseSpecification.course.code')
                            ->label('Course')
                            ->placeholder('-'),
                        TextEntry::make('section.code')
                            ->label('Section Code'),
                        TextEntry::make('name')
                            ->label('Group Name'),
                        TextEntry::make('expected_count')
                            ->label('Expected Count')
                            ->numeric(),
                        TextEntry::make('modality')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                        TextEntry::make('state')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::stateOptions()[$state] ?? str($state)->headline()->toString())),
                        KeyValueEntry::make('delivery_override')
                            ->label('Delivery Override')
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
