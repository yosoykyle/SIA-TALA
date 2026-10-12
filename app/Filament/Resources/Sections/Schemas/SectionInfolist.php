<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Support\DisplayDateTime;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Schemas\Schema;

class SectionInfolist
{
    public static function configure(Schema $schema, bool $showCourseTitle = true): Schema
    {
        return $schema
            ->components([
                InfolistSection::make('Class identity')
                    ->schema([
                        TextEntry::make('course_title')->label('Course title')
                            ->state(fn (Section $record): ?string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title)
                            ->placeholder('Course title unavailable')->visible($showCourseTitle),
                        TextEntry::make('term_label')->label('Term')
                            ->state(fn (Section $record): ?string => $record->calendarPackage?->term?->label ?? $record->termOffering?->term?->label)
                            ->placeholder('Term unavailable'),
                        TextEntry::make('course_code')
                            ->label('Course code')
                            ->state(fn (Section $record): ?string => $record->courseRevision?->course?->code ?? $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code)
                            ->placeholder('Course code unavailable'),
                        TextEntry::make('code')
                            ->label('Class code'),
                        TextEntry::make('capacity')
                            ->numeric(),
                        TextEntry::make('state')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::stateOptions()[$state] ?? str($state)->headline()->toString())),
                        TextEntry::make('cohorts.reference')->label('Linked student sections')->listWithLineBreaks()->placeholder('No sections linked'),
                        TextEntry::make('confirmed_at')->label('Class confirmed (Asia/Manila)')->state(fn (Section $record): string => DisplayDateTime::format($record->confirmed_at, fallback: 'Not confirmed')),
                        TextEntry::make('scheduling_requirements')->label('Delivery groups and meeting requirements')
                            ->state(fn (Section $record): array => $record->deliveryGroups->map(fn (SectionDeliveryGroup $group): string => $group->name.' · '.$group->expected_count.' learners · '.(SectionDeliveryGroup::modalityOptions()[$group->modality] ?? $group->modality).' · '.$group->schedulingDemands->map(fn ($demand): string => $demand->meeting_count.' × '.$demand->required_duration_minutes.' minutes')->implode('; '))->all())
                            ->listWithLineBreaks()->placeholder('No meeting requirements recorded')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                InfolistSection::make('Record history')->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->label('Created (Asia/Manila)')->state(fn (Section $record): string => DisplayDateTime::format($record->created_at)),
                        TextEntry::make('updated_at')->label('Updated (Asia/Manila)')->state(fn (Section $record): string => DisplayDateTime::format($record->updated_at)),
                    ])->columns(2)->columnSpanFull(),
            ]);
    }
}
