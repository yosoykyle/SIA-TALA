<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Models\Section;
use App\Models\TermOffering;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;

class SectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Section Source Record')
                    ->description('Create Registrar-owned section records that scheduling demand and enrollment placement consume later.')
                    ->schema([
                        Select::make('term_offering_id')
                            ->label('Term Offering')
                            ->options(fn (): array => self::termOfferingOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('code')
                            ->label('Section Code')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('capacity')
                            ->label('Capacity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Registrar planning capacity. Enrollment/reservations are downstream records and are not counted here.'),
                        Select::make('state')
                            ->options(Section::stateOptions())
                            ->default(Section::StatePlanned)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function termOfferingOptions(): array
    {
        return TermOffering::query()
            ->with(['term', 'curriculumEntry.courseSpecification.course'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (TermOffering $offering): array => [
                $offering->id => collect([
                    $offering->term?->label,
                    $offering->curriculumEntry?->courseSpecification?->course?->code,
                    $offering->delivery_variant,
                    $offering->modality,
                    "Expected {$offering->expected_count}",
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
