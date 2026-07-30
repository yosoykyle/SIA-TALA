<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Tables;

use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'studentProfile.program',
                'term',
            ]))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student')
                    ->formatStateUsing(function (StudentLifecycleChange $record): string {
                        $profile = $record->studentProfile;

                        if (! $profile instanceof StudentProfile) {
                            return 'Student record unavailable';
                        }

                        return collect([
                            $profile->last_name,
                            $profile->first_name,
                        ])->filter()->implode(', ');
                    })
                    ->description(function (StudentLifecycleChange $record): string {
                        $profile = $record->studentProfile;

                        if (! $profile instanceof StudentProfile) {
                            return 'Student identity unavailable';
                        }

                        return collect([
                            $profile->student_number,
                            $profile->program?->name,
                        ])->filter()->implode(' · ');
                    })
                    ->searchable(
                        query: fn (Builder $query, string $search): Builder => $query->whereHas(
                            'studentProfile',
                            fn (Builder $profileQuery): Builder => $profileQuery->where(function (Builder $identityQuery) use ($search): void {
                                $identityQuery
                                    ->where('student_number', 'like', "%{$search}%")
                                    ->orWhere('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%");
                            }),
                        ),
                    )
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Recorded Change')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StudentLifecycleChange::typeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('term.label')->label('Term')->sortable(),
                TextColumn::make('effective_on')->date()->sortable(),
                TextColumn::make('state')
                    ->label('Recorded Result')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        StudentLifecycleChange::StateRecordedApproved => 'Approved — pending application',
                        StudentLifecycleChange::StateApplied => 'Applied to official record',
                        StudentLifecycleChange::StateCancelled => 'Cancelled before application',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('next_step')
                    ->label('Next Step')
                    ->state(fn (StudentLifecycleChange $record): string => match ($record->state) {
                        StudentLifecycleChange::StateRecordedApproved => 'Registrar must apply or cancel the approved action.',
                        StudentLifecycleChange::StateApplied => 'Recorded result is complete. No Registrar action is required.',
                        StudentLifecycleChange::StateCancelled => 'No action is pending because this decision was cancelled.',
                        default => 'Review this lifecycle record.',
                    })
                    ->description('Responsible office: Registrar Office')
                    ->wrap(),
                TextColumn::make('authority')
                    ->label('Decision Authority')
                    ->searchable()
                    ->wrap(),
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
                ActionGroup::make([
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
                    Action::make('cancel')
                        ->label('Cancel Program Shift')
                        ->authorize('cancel')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (StudentLifecycleChange $record): bool => $record->type === StudentLifecycleChange::TypeProgramShift && $record->state === StudentLifecycleChange::StateRecordedApproved)
                        ->action(function (StudentLifecycleChange $record): void {
                            app(StudentLifecycleService::class)->cancelProgramShift($record, auth()->user());
                            Notification::make()->title('Program Shift cancelled')->success()->send();
                        }),
                ])->tooltip('Lifecycle actions'),
            ])
            ->stackedOnMobile();
    }
}
