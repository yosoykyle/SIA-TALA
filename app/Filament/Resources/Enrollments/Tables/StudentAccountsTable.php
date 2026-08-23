<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Finance\TermAccountProjection;
use App\Models\Enrollment;
use App\Models\Program;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'credentialUser',
                'admissionApplication.program',
                'studentProfile.program',
                'term',
                'termAccount.assessments',
                'termAccount.paymentAttempts.obligations.assessmentObligation',
            ])->whereHas('termAccount'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('learner')
                    ->label('Learner')
                    ->state(fn (Enrollment $record): string => $record->credentialUser?->getFilamentName() ?? 'Identity unavailable')
                    ->description(fn (Enrollment $record): string => collect([
                        $record->studentProfile?->student_number,
                        $record->admissionApplication?->application_reference,
                        $record->credentialUser?->email,
                    ])->filter()->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->where('case_reference', 'like', "%{$search}%")
                            ->orWhereHas('credentialUser', fn (Builder $query): Builder => $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhereHas('studentProfile', fn (Builder $query): Builder => $query
                                ->where('student_number', 'like', "%{$search}%"));
                    }))
                    ->wrap(),
                TextColumn::make('account_reference')
                    ->label('Account')
                    ->state(fn (Enrollment $record): string => 'TERM-ACCOUNT-'.$record->termAccount?->id)
                    ->copyable(),
                TextColumn::make('program')
                    ->state(fn (Enrollment $record): string => self::programLabel($record)),
                TextColumn::make('term.label')->label('Term')->sortable(),
                TextColumn::make('account_state')
                    ->label('Account state')
                    ->state(fn (Enrollment $record): string => app(TermAccountProjection::class)->forAccount($record->termAccount)['state'])
                    ->description(fn (Enrollment $record): string => 'Current due: PHP '.app(TermAccountProjection::class)->forAccount($record->termAccount)['current_due'])
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Cleared' ? 'success' : 'warning'),
                TextColumn::make('payment_attempt_state')
                    ->label('Online payment')
                    ->state(function (Enrollment $record): string {
                        $attempt = $record->termAccount->paymentAttempts->sortByDesc('id')->first();

                        return $attempt === null ? 'No attempt' : $attempt->status;
                    })
                    ->description(function (Enrollment $record): ?string {
                        $attempt = $record->termAccount?->paymentAttempts->sortByDesc('id')->first();

                        if ($attempt === null) {
                            return null;
                        }

                        $targets = $attempt->obligations
                            ->map(fn ($target): string => $target->assessmentObligation?->label.' — PHP '.$target->amount)
                            ->filter()
                            ->implode('; ');

                        return $targets !== '' ? $targets : 'Historical attempt; no canonical target snapshot.';
                    })
                    ->badge()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('term')->relationship('term', 'label')->searchable()->preload(),
                SelectFilter::make('program')
                    ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $programId): Builder => $query->where(
                            fn (Builder $programQuery): Builder => $programQuery
                                ->whereHas('admissionApplication', fn (Builder $application): Builder => $application->where('program_id', $programId))
                                ->orWhereHas('studentProfile', fn (Builder $profile): Builder => $profile->where('program_id', $programId)),
                        ),
                    )),
            ])
            ->recordActions([ViewAction::make()])
            ->stackedOnMobile()
            ->toolbarActions([]);
    }

    private static function programLabel(Enrollment $enrollment): string
    {
        $program = $enrollment->studentProfile->program ?? $enrollment->admissionApplication->program ?? null;

        return $program->code ?? 'Program unavailable';
    }
}
