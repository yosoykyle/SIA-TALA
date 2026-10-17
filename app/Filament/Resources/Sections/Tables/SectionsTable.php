<?php

namespace App\Filament\Resources\Sections\Tables;

use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\Section;
use App\Models\TermOffering;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Component;

class SectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course']))
            ->columns([
                TextColumn::make('code')
                    ->label('Class')
                    ->description(fn (Section $record): string => collect([
                        $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                        $record->termOffering?->curriculumEntry?->courseSpecification?->title,
                    ])->filter()->implode(' · '))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('termOffering.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('capacity')
                    ->label('Seat capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Planning state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::stateOptions()[$state] ?? str($state)->headline()->toString())),
            ])
            ->filters([
                SelectFilter::make('term_offering_id')
                    ->label('Term Offering')
                    ->options(fn (): array => self::termOfferingOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('state')
                    ->options(Section::stateOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()->color('gray')->url(fn (Section $record, Component $livewire): string => SectionResource::getUrl('view', [
                    'record' => $record,
                    ...($livewire instanceof ListSections && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
                EditAction::make()->color('gray')->url(fn (Section $record, Component $livewire): string => SectionResource::getUrl('edit', [
                    'record' => $record,
                    ...($livewire instanceof ListSections && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No student sections are recorded')
            ->emptyStateDescription('Build a course offering first, then add its classes and delivery groups.');
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
