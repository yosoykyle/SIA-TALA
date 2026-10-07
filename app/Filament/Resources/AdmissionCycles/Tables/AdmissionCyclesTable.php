<?php

namespace App\Filament\Resources\AdmissionCycles\Tables;

use App\Models\AdmissionCycle;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionCyclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'tala-admissions-table'])
            ->searchPlaceholder('Search cycle name or code')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('label')->label('Admission cycle')->searchable(['label', 'code'])->wrap()->weight('semibold')
                            ->description(fn (AdmissionCycle $record): string => $record->code),
                        TextColumn::make('term.label')->label('Target term')->prefix('Target term: ')->wrap()->placeholder('Target term not selected'),
                    ])->space(1),
                    TextColumn::make('availability')->label('Public applications')
                        ->prefix('Applications: ')
                        ->state(fn (AdmissionCycle $record): string => match (true) {
                            $record->state === AdmissionCycle::StateDraft => 'Draft',
                            $record->state === AdmissionCycle::StateCancelled => 'Cancelled',
                            $record->state !== AdmissionCycle::StatePublished || ! $record->opens_at || ! $record->closes_at => 'Window unavailable',
                            $record->opens_at->isFuture() => 'Scheduled',
                            $record->closes_at <= now() => 'Closed',
                            default => 'Open',
                        })
                        ->description(fn (AdmissionCycle $record): string => match (true) {
                            $record->state !== AdmissionCycle::StatePublished => 'First submissions unavailable',
                            ! $record->opens_at || ! $record->closes_at => 'Review the cycle dates',
                            $record->opens_at->isFuture() => 'Opens '.$record->opens_at->timezone('Asia/Manila')->format('M j, Y · g:i A').' (Asia/Manila)',
                            default => ($record->closes_at <= now() ? 'Closed ' : 'Closes ').$record->closes_at->timezone('Asia/Manila')->format('M j, Y · g:i A').' (Asia/Manila)',
                        })
                        ->wrap(),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('state')->label('Lifecycle')->prefix('Lifecycle: ')->badge()->color(fn (string $state): string => match ($state) {
                            AdmissionCycle::StatePublished => 'info',
                            default => 'gray',
                        })->sortable(),
                        TextColumn::make('opens_at')->label('Applications open')->prefix('Opens: ')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->wrap()->sortable(),
                        TextColumn::make('closes_at')->label('Public closing')->prefix('Public closing: ')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->wrap()->sortable(),
                        TextColumn::make('correction_closes_at')->label('Correction requests end')->prefix('New correction requests end: ')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->wrap()->placeholder('Not set'),
                        TextColumn::make('programs_count')->counts('programs')->label('Programs')->prefix('Programs: '),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter cycles')->tooltip('Narrow this cycle list by state or target term'))
            ->filters([
                SelectFilter::make('state')->options([
                    AdmissionCycle::StateDraft => 'Draft',
                    AdmissionCycle::StatePublished => 'Published',
                    AdmissionCycle::StateCancelled => 'Cancelled',
                ]),
                SelectFilter::make('term_id')
                    ->label('Target term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('Open')->button()->color('gray'),
                EditAction::make()->iconButton()->tooltip('Edit draft cycle')->color('gray')
                    ->visible(fn (AdmissionCycle $record): bool => $record->state === AdmissionCycle::StateDraft),
            ]);
    }
}
