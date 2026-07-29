<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurriculumVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Curriculum Version')
                ->schema([
                    TextEntry::make('program.code')->label('Program'),
                    TextEntry::make('version_code')->label('Version Code'),
                    TextEntry::make('name')->label('Version Name'),
                    TextEntry::make('state')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => CurriculumVersion::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
                    TextEntry::make('approval_reference')->placeholder('-'),
                    TextEntry::make('approved_at')->dateTime()->placeholder('-'),
                    RepeatableEntry::make('curriculum_entries')
                        ->label('Curriculum Entries')
                        ->state(fn (CurriculumVersion $record): array => self::entryRows($record))
                        ->table([
                            TableColumn::make('Year Level'),
                            TableColumn::make('Term'),
                            TableColumn::make('Sequence'),
                            TableColumn::make('Course Code'),
                            TableColumn::make('Course Title'),
                            TableColumn::make('Units'),
                            TableColumn::make('Requirement'),
                        ])
                        ->schema([
                            TextEntry::make('year_level'),
                            TextEntry::make('term_label'),
                            TextEntry::make('sequence'),
                            TextEntry::make('course_code'),
                            TextEntry::make('course_title'),
                            TextEntry::make('credit_units'),
                            TextEntry::make('requirement_group'),
                        ])
                        ->columnSpanFull()
                        ->contained(false),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int, array{
     *     year_level: string,
     *     term_label: string,
     *     sequence: int,
     *     course_code: string,
     *     course_title: string,
     *     credit_units: float,
     *     requirement_group: string
     * }>
     */
    private static function entryRows(CurriculumVersion $record): array
    {
        return $record->entries()
            ->with('courseSpecification.course')
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->get()
            ->map(function (CurriculumEntry $entry): array {
                $specification = $entry->courseSpecification;

                return [
                    'year_level' => $entry->year_level,
                    'term_label' => $entry->term_label,
                    'sequence' => $entry->sequence,
                    'course_code' => $specification->course->code,
                    'course_title' => $specification->title,
                    'credit_units' => $specification->credit_units,
                    'requirement_group' => CurriculumEntry::requirementGroupOptions()[$entry->requirement_group]
                        ?? str($entry->requirement_group)->headline()->toString(),
                ];
            })
            ->values()
            ->all();
    }
}
