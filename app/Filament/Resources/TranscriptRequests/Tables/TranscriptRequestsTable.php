<?php

namespace App\Filament\Resources\TranscriptRequests\Tables;

use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Models\TranscriptRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TranscriptRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('studentProfile.last_name')->label('Student')
                    ->formatStateUsing(fn ($record): string => $record->studentProfile->last_name.', '.$record->studentProfile->first_name)
                    ->description(fn ($record): string => $record->studentProfile->student_number)
                    ->searchable(['last_name', 'first_name', 'student_number']),
                TextColumn::make('external_request_reference')->label('Request')->searchable()
                    ->description(fn ($record): string => 'Requested '.$record->requested_on->format('M j, Y')),
                TextColumn::make('due_on')->label('Due date')->date('M j, Y')->sortable()
                    ->description(fn ($record): string => $record->state !== TranscriptRequest::StateIssued && $record->due_on->endOfDay()->isPast() ? 'Due date passed · follow up' : '30 days from the actual request'),
                TextColumn::make('lifecycle_state')->label('Status')
                    ->state(fn ($record): string => app(TranscriptLifecycleProjection::class)->statusForRequest($record))->badge()
                    ->description(fn ($record): string => $record->officialOutputRestrictions->whereNull('resolved_at')->isNotEmpty() ? 'Accounting restriction · open request' : 'No active Accounting TOR restriction'),
            ])
            ->recordActions([ViewAction::make()->label('Open request')])
            ->emptyStateHeading(fn ($livewire): string => $livewire->hasTableSearch() ? 'No matching TOR requests' : 'No TOR requests recorded')
            ->emptyStateDescription(fn ($livewire): string => $livewire->hasTableSearch() ? 'Try another student name, number or request reference.' : 'The Registrar records an actual request from Completion & TOR. Graduation is not a prerequisite.')
            ->stackedOnMobile()
            ->defaultSort('requested_on', 'asc');
    }
}
