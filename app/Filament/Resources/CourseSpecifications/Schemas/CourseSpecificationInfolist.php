<?php

namespace App\Filament\Resources\CourseSpecifications\Schemas;

use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseSpecificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Course Specification')
                ->schema([
                    TextEntry::make('course.code')->label('Subject Code'),
                    TextEntry::make('revision_code')->label('Revision'),
                    TextEntry::make('title')->label('Subject Title'),
                    TextEntry::make('credit_units')->label('Units'),
                    TextEntry::make('state')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => CourseSpecification::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
                    TextEntry::make('component_summary')
                        ->label('Components')
                        ->state(fn (CourseSpecification $record): string => $record->components()
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn (CourseComponent $component): string => collect([
                                CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type,
                                $component->weekly_contact_hours.' hour(s)',
                                $component->room_type_default,
                            ])->filter()->implode(' | '))
                            ->implode("\n"))
                        ->columnSpanFull()
                        ->placeholder('-'),
                    TextEntry::make('requirement_summary')
                        ->label('Requirements')
                        ->state(fn (CourseSpecification $record): string => $record->requirements()
                            ->with('relatedCourse')
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn (CourseRequirement $requirement): string => collect([
                                CourseRequirement::typeOptions()[$requirement->rule_type] ?? $requirement->rule_type,
                                $requirement->group_key,
                                $requirement->relatedCourse?->code,
                            ])->filter()->implode(' | '))
                            ->implode("\n"))
                        ->columnSpanFull()
                        ->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
