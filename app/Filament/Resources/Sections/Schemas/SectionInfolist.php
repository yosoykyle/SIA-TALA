<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Support\DisplayDateTime;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Schemas\Schema;
use Livewire\Component as LivewireComponent;

class SectionInfolist
{
    public static function configure(Schema $schema, bool $showCourseTitle = true): Schema
    {
        return $schema
            ->components([
                InfolistSection::make('Class summary')
                    ->compact()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->visible(fn (LivewireComponent $livewire): bool => $showCourseTitle)
                            ->schema([
                                TextEntry::make('course_title')
                                    ->label('Course title')
                                    ->state(fn (Section $record): ?string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title)
                                    ->placeholder('Course title unavailable')
                                    ->visible(fn (LivewireComponent $livewire): bool => $showCourseTitle && ! ($livewire instanceof ViewSection && $livewire->termId !== null)),
                                TextEntry::make('course_code')
                                    ->label('Course code')
                                    ->visible($showCourseTitle)
                                    ->state(fn (Section $record): ?string => $record->courseRevision?->course?->code ?? $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code)
                                    ->placeholder('Course code unavailable'),
                                TextEntry::make('term_label')
                                    ->label('Term')
                                    ->state(fn (Section $record): ?string => $record->calendarPackage?->term?->label ?? $record->termOffering?->term?->label)
                                    ->placeholder('Term unavailable')
                                    ->visible(fn (LivewireComponent $livewire): bool => $showCourseTitle && ! ($livewire instanceof ViewSection && $livewire->termId !== null)),
                            ])
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextEntry::make('code')
                                    ->label('Class reference')
                                    ->weight('semibold'),
                                TextEntry::make('state')
                                    ->label('Class status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        Section::StateOpen => 'success',
                                        Section::StatePlanned => 'info',
                                        Section::StateCancelled => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::stateOptions()[$state] ?? str($state)->headline()->toString())),
                                TextEntry::make('capacity')
                                    ->label('Approved capacity')
                                    ->numeric()
                                    ->suffix(' seats')
                                    ->extraAttributes(['class' => 'tabular-nums']),
                            ])
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextEntry::make('curriculum_origin')
                                    ->label('Curriculum source')
                                    ->state(fn (Section $record): string => collect([$record->termOffering?->curriculumEntry?->curriculumVersion?->program?->name, $record->termOffering?->curriculumEntry?->year_level])->filter()->implode(' · ') ?: 'Not recorded')
                                    ->columnSpan(['default' => 1, 'md' => 2]),
                                TextEntry::make('confirmed_at')
                                    ->label('Class confirmed')
                                    ->state(fn (Section $record): string => DisplayDateTime::format($record->confirmed_at, fallback: 'Not confirmed'))
                                    ->extraAttributes(['class' => 'tabular-nums']),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        InfolistSection::make('Student sections served')
                            ->compact()
                            ->schema([
                                TextEntry::make('cohorts.reference')
                                    ->hiddenLabel()
                                    ->listWithLineBreaks()
                                    ->placeholder('No student sections linked')
                                    ->helperText(fn (Section $record): string => $record->cohorts->sum(fn ($cohort): int => (int) $cohort->pivot->expected_count).' learners linked to this class'),
                            ]),
                        InfolistSection::make('Meeting requirements')
                            ->compact()
                            ->schema([
                                TextEntry::make('scheduling_requirements')
                                    ->hiddenLabel()
                                    ->state(fn (Section $record): array => $record->deliveryGroups->map(fn (SectionDeliveryGroup $group): string => $group->name.' · '.$group->expected_count.' learners · '.(SectionDeliveryGroup::modalityOptions()[$group->modality] ?? $group->modality).' · '.$group->schedulingDemands->map(fn ($demand): string => $demand->meeting_count.' × '.$demand->required_duration_minutes.' minutes')->implode('; '))->all())
                                    ->listWithLineBreaks()
                                    ->placeholder('No meeting requirements recorded'),
                            ]),
                    ])
                    ->columnSpanFull(),
                TextEntry::make('record_metadata')
                    ->hiddenLabel()
                    ->state(fn (Section $record): string => 'Created '.DisplayDateTime::format($record->created_at).' · Last updated '.DisplayDateTime::format($record->updated_at).' (Asia/Manila)')
                    ->color('gray')
                    ->extraAttributes(['class' => 'text-xs text-zinc-500 dark:text-zinc-400 tabular-nums'])
                    ->columnSpanFull(),
            ]);
    }
}
