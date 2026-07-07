<?php

namespace App\Filament\Resources\GradeRosters\Tables;

use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\ReturnGradeRoster;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

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
                Action::make('authorizeLateGradeEncoding')
                    ->label('Authorize Late Encoding')
                    ->color('info')
                    ->schema([
                        Select::make('period')
                            ->label('Grading period')
                            ->options([
                                LateGradeAuthorization::PeriodPrelim => 'Prelim',
                                LateGradeAuthorization::PeriodMidterm => 'Midterm',
                                LateGradeAuthorization::PeriodFinal => 'Final',
                            ])
                            ->required(),
                        DateTimePicker::make('opens_at')
                            ->label('Opens at')
                            ->seconds(false)
                            ->required(),
                        DateTimePicker::make('closes_at')
                            ->label('Closes at')
                            ->seconds(false)
                            ->required(),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->visible(fn (GradeRoster $record): bool => auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead])
                        && in_array($record->state, [GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true))
                    ->action(function (GradeRoster $record, array $data): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(AuthorizeLateGradeEncoding::class)->execute(
                            $record,
                            (string) $data['period'],
                            Carbon::parse($data['opens_at']),
                            Carbon::parse($data['closes_at']),
                            (string) $data['reason'],
                            $actor,
                        );

                        Notification::make()->title('Late grade authorization opened')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
