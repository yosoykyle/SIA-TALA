<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Tables;

use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Models\StudentLifecycleChange;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentLifecycleChangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('studentProfile.student_number')->label('Student')->searchable()->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('term.label')->label('Term')->sortable(),
                TextColumn::make('effective_on')->date()->sortable(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('authority')->searchable(),
            ])
            ->defaultSort('effective_on', 'desc')
            ->filters([
                SelectFilter::make('type')->options(StudentLifecycleChange::typeOptions()),
                SelectFilter::make('term')->relationship('term', 'label')->searchable()->preload(),
                SelectFilter::make('state')->options([
                    StudentLifecycleChange::StateRecordedApproved => 'Recorded Approved',
                    StudentLifecycleChange::StateApplied => 'Applied',
                    StudentLifecycleChange::StateCancelled => 'Cancelled',
                ]),
                Filter::make('effective_date')->schema([
                    DatePicker::make('from'), DatePicker::make('until'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('effective_on', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('effective_on', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('apply')
                    ->label('Apply Program Shift')
                    ->authorize('apply')
                    ->requiresConfirmation()
                    ->visible(fn (StudentLifecycleChange $record): bool => $record->type === StudentLifecycleChange::TypeProgramShift && $record->state === StudentLifecycleChange::StateRecordedApproved)
                    ->action(function (StudentLifecycleChange $record): void {
                        app(StudentLifecycleService::class)->applyProgramShift($record, auth()->user());
                        Notification::make()->title('Program Shift applied')->success()->send();
                    }),
            ]);
    }
}
