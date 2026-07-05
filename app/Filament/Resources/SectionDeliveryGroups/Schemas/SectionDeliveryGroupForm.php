<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Schemas;

use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;

class SectionDeliveryGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Section Delivery Group')
                    ->description('Delivery setup subset inside one academic section. Expected counts are validated against the parent section capacity before save.')
                    ->schema([
                        Select::make('section_id')
                            ->label('Section')
                            ->options(fn (): array => self::sectionOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Group Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('expected_count')
                            ->label('Expected Count')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->helperText('Cannot exceed the parent section capacity.'),
                        Select::make('modality')
                            ->options(SectionDeliveryGroup::modalityOptions())
                            ->required(),
                        Select::make('state')
                            ->options(SectionDeliveryGroup::stateOptions())
                            ->default(SectionDeliveryGroup::StatePlanned)
                            ->required()
                            ->helperText('Use Ready only after this source record can safely feed scheduling demand.'),
                        KeyValue::make('delivery_override')
                            ->label('Delivery Override')
                            ->keyLabel('Override key')
                            ->valueLabel('Value')
                            ->helperText('Optional source-record override for later scheduling demand generation.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function sectionOptions(): array
    {
        return Section::query()
            ->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Section $section): array => [
                $section->id => collect([
                    $section->termOffering?->term?->label,
                    $section->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                    $section->code,
                    "Capacity {$section->capacity}",
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
