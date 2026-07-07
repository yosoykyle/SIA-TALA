<?php

namespace App\Filament\Resources\GradeRosters\Tables;

use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GradeRostersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('termOffering.term.label')->label('Term')->searchable(),
                TextColumn::make('section.code')->label('Section')->searchable(),
                TextColumn::make('faculty.name')->label('Faculty')->searchable(),
                TextColumn::make('state')->badge(),
                TextColumn::make('rows_count')->counts('rows')->label('Rows'),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('released_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    GradeRoster::StateDraft => 'Draft',
                    GradeRoster::StateSubmitted => 'Submitted',
                    GradeRoster::StateReturned => 'Returned',
                    GradeRoster::StateReleased => 'Posted & Released',
                    GradeRoster::StateLateNotSubmitted => 'Late / Not Submitted',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (GradeRoster $record): string => GradeRosterResource::getUrl('view', ['record' => $record])),
                Action::make('return')
                    ->label('Return')
                    ->color('warning')
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(2000),
                    ])
                    ->visible(fn (GradeRoster $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $record->state === GradeRoster::StateSubmitted)
                    ->action(function (GradeRoster $record, array $data): void {
                        app(ReturnGradeRoster::class)->execute($record, auth()->user(), (string) $data['reason']);
                        Notification::make()->title('Grade roster returned')->warning()->send();
                    }),
                Action::make('postAndRelease')
                    ->label('Post & Release')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (GradeRoster $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $record->state === GradeRoster::StateSubmitted)
                    ->action(function (GradeRoster $record): void {
                        app(PostAndReleaseGradeRoster::class)->execute($record, auth()->user());
                        Notification::make()->title('Grade roster posted and released')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
