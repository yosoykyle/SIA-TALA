<?php

namespace App\Filament\Resources\Sections\Tables;

use App\Models\Section;
use App\Models\TermOffering;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course']))
            ->columns([
                TextColumn::make('termOffering.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course')
                    ->searchable(),
                TextColumn::make('termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Course Title')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Section Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('state')
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
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
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
