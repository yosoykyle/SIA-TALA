<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Schemas;

use App\Models\DeliveryPattern;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SectionDeliveryGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Section Delivery Group')
                    ->description('Delivery setup subset inside one academic section. Capacity and room rules are validated before save.')
                    ->schema([
                        Select::make('section_id')
                            ->label('Section')
                            ->options(fn (?SectionDeliveryGroup $record): array => self::sectionOptions($record?->section_id))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('delivery_pattern_id')
                            ->label('Delivery Pattern')
                            ->options(fn (?SectionDeliveryGroup $record): array => self::deliveryPatternOptions($record?->delivery_pattern_id))
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Group Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('modality')
                            ->options(SectionDeliveryGroup::modalityOptions())
                            ->live()
                            ->required(),
                        TextInput::make('capacity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(Section::MaxRescueSeats)
                            ->helperText('Cannot exceed the parent section capacity or drop below assigned count.'),
                        Select::make('status')
                            ->options(SectionDeliveryGroup::statusOptions())
                            ->required()
                            ->default(SectionDeliveryGroup::StatusActive),
                        Select::make('room')
                            ->label('Fixed Room')
                            ->options(fn (?SectionDeliveryGroup $record): array => Room::selectOptions($record?->room))
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => SectionDeliveryGroup::modalityRequiresRoom($get('modality')))
                            ->visible(fn (Get $get): bool => SectionDeliveryGroup::modalityRequiresRoom($get('modality')))
                            ->helperText('Required for on-site or blended groups. Online and modular groups keep room blank.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function sectionOptions(mixed $currentSectionId = null): array
    {
        return Section::query()
            ->with(['term', 'program'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Section $section): array => [
                $section->id => collect([
                    $section->term?->term_name,
                    $section->program?->code,
                    $section->name,
                    $section->year_level,
                    $section->curriculum_period,
                ])->filter()->implode(' | '),
            ])
            ->when(filled($currentSectionId), fn ($options) => $options)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function deliveryPatternOptions(mixed $currentPatternId = null): array
    {
        return DeliveryPattern::query()
            ->where(function ($query) use ($currentPatternId): void {
                $query->where('is_active', true);

                if (filled($currentPatternId)) {
                    $query->orWhereKey((int) $currentPatternId);
                }
            })
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->mapWithKeys(fn (DeliveryPattern $pattern): array => [
                $pattern->id => collect([
                    $pattern->code,
                    "v{$pattern->version}",
                    $pattern->name,
                    $pattern->modality === null ? 'Generic' : (DeliveryPattern::modalityOptions()[$pattern->modality] ?? $pattern->modality),
                    $pattern->is_active ? null : 'inactive',
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
