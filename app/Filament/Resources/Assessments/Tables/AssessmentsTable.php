<?php

namespace App\Filament\Resources\Assessments\Tables;

use App\Actions\Finance\StudentAccountPresenter;
use App\Models\Assessment;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class AssessmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'enrollment.studentProfile.program',
                'enrollment.studentProfile.user',
                'enrollment.term.academicYear',
            ]))
            ->columns([
                TextColumn::make('enrollment.studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student_name')
                    ->label('Student')
                    ->state(fn (Assessment $record): string => self::account($record)['student_name'])
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('enrollment.studentProfile', fn ($profile) => $profile
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")))
                    ->wrap(),
                TextColumn::make('program')
                    ->label('Program')
                    ->state(fn (Assessment $record): string => self::account($record)['program']),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->state(fn (Assessment $record): string => self::account($record)['year_level']),
                TextColumn::make('section')
                    ->label('Section')
                    ->state(fn (Assessment $record): string => self::account($record)['section']),
                TextColumn::make('enrollment.term.label')
                    ->label('Term')
                    ->description(fn (Assessment $record): ?string => $record->enrollment?->term?->academicYear?->label)
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Account State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString()),
                TextColumn::make('total')
                    ->label('Assessment')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('posted_payments')
                    ->label('Posted Payments')
                    ->state(fn (Assessment $record): string => self::account($record)['posted_payments']),
                TextColumn::make('remaining_balance')
                    ->label('Balance')
                    ->state(fn (Assessment $record): string => self::account($record)['remaining_balance']),
                TextColumn::make('current_due')
                    ->label('Due Now')
                    ->state(fn (Assessment $record): string => self::account($record)['current_due']),
                TextColumn::make('payment_status')
                    ->label('Payment Status')
                    ->state(fn (Assessment $record): string => self::account($record)['payment_status'])
                    ->badge()
                    ->wrap(),
                TextColumn::make('finance_gate')
                    ->label('Finance Gate')
                    ->state(fn (Assessment $record): string => self::account($record)['finance_gate_status'])
                    ->description(fn (Assessment $record): string => self::account($record)['finance_gate_source'])
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Cleared' ? 'success' : 'warning'),
                TextColumn::make('next_action')
                    ->label('Next Action')
                    ->state(fn (Assessment $record): string => self::account($record)['next_action'])
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Account State')
                    ->options([
                        Assessment::StateDraft => 'Draft',
                        Assessment::StatePendingReview => 'Pending Review',
                        Assessment::StateActive => 'Active',
                        Assessment::StateSuperseded => 'Superseded',
                        Assessment::StateCancelled => 'Cancelled',
                        Assessment::StateLocked => 'Locked',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Open Account'),
            ])
            ->defaultSort('activated_at', 'desc')
            ->emptyStateHeading('No student accounts yet')
            ->emptyStateDescription('Student accounts appear after an enrollment assessment is generated.')
            ->toolbarActions([]);
    }

    /** @return array<string, mixed> */
    private static function account(Assessment $assessment): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new RuntimeException('An authenticated user is required to view a student account.');
        }

        return app(StudentAccountPresenter::class)->present($assessment, $actor);
    }
}
