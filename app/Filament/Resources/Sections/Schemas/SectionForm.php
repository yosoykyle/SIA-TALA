<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
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
                FormSection::make('Class')
                    ->description('One course taught to one or more linked student sections.')
                    ->schema([
                        Select::make('term_offering_id')
                            ->label('Offered course')
                            ->options(fn (CreateSection|EditSection|ListSections $livewire): array => self::termOfferingOptions($livewire->termId))
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('code')
                            ->label('Class code')
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
    private static function termOfferingOptions(?int $termId): array
    {
        return TermOffering::query()
            ->when($termId !== null, fn ($query) => $query->where('term_id', $termId))
            ->with(['term', 'curriculumEntry.courseSpecification.course'])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (TermOffering $offering): array => [
                $offering->id => collect([
                    $offering->curriculumEntry?->courseSpecification?->title ?: 'Course title unavailable',
                    $offering->curriculumEntry?->courseSpecification?->course?->code,
                    $offering->term?->label,
                    str((string) $offering->delivery_variant)->headline()->toString(),
                    TermOffering::modalityOptions()[$offering->modality] ?? str((string) $offering->modality)->headline()->toString(),
                    "{$offering->expected_count} expected learners",
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
