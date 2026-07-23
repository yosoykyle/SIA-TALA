<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\Term;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurriculumVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Curriculum Version')
                ->description('Encode or import a Draft curriculum. External approval and activation are separate controlled actions.')
                ->schema([
                    Select::make('program_id')
                        ->label('Program')
                        ->options(fn (): array => self::programOptions())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('version_code')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('name')
                        ->label('Version Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('effective_entry_term_id')
                        ->label('Effective Entry Term')
                        ->options(fn (): array => self::termOptions())
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Curriculum Entries')
                ->description('Map approved course specification revisions into year level, term label, sequence, and requirement group.')
                ->schema([
                    Repeater::make('entries')
                        ->relationship()
                        ->schema([
                            Select::make('course_specification_id')
                                ->label('Course Specification')
                                ->options(fn (): array => self::courseSpecificationOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('year_level')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('term_label')
                                ->label('Term Label')
                                ->required()
                                ->maxLength(255),
                            Select::make('term_type')
                                ->options(Term::typeOptions())
                                ->required(),
                            TextInput::make('sequence')
                                ->integer()
                                ->minValue(1)
                                ->required(),
                            Select::make('requirement_group')
                                ->options(CurriculumEntry::requirementGroupOptions())
                                ->default(CurriculumEntry::RequirementGroupRequired)
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add curriculum entry'),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function programOptions(): array
    {
        return Program::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Program $program): array => [$program->id => "{$program->code} - {$program->name}"])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function termOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([
                    $term->academicYear?->label,
                    $term->label,
                ])->filter()->implode(' | '),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function courseSpecificationOptions(): array
    {
        return CourseSpecification::query()
            ->with('course')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (CourseSpecification $specification): array => [
                $specification->id => collect([
                    $specification->course?->code,
                    $specification->title,
                    $specification->revision_code,
                ])->filter()->implode(' - '),
            ])
            ->all();
    }
}
