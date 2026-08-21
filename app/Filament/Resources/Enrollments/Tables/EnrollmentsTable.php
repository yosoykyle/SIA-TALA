<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\TermAccount;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class EnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'credentialUser',
                'admissionApplication.program',
                'studentProfile.program',
                'term',
                'currentProposalVersion.confirmation',
                'currentProposalVersion.items.reservation',
                'termAccount.assessments',
            ]))
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
                                ->where('student_number', 'like', "%{$search}%"))
                            ->orWhereHas('admissionApplication', fn (Builder $query): Builder => $query
                                ->where('application_reference', 'like', "%{$search}%"));
                    }))
                    ->wrap(),
                TextColumn::make('case_reference')
                    ->label('Registration Case')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->sortable(),
                TextColumn::make('selection_basis')
                    ->label('Selection basis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::headline($state) : 'Not selected'),
                TextColumn::make('stage')
                    ->state(fn (Enrollment $record): string => self::stage($record))
                    ->description(fn (Enrollment $record): string => self::nextAction($record))
                    ->badge()
                    ->color(fn (Enrollment $record): string => match (self::stage($record)) {
                        'Officially enrolled' => 'success',
                        'Cancelled', 'Not enrolled' => 'danger',
                        'Ready to finalize' => 'info',
                        default => 'warning',
                    })
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label('Last activity')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('program')
                    ->options(fn (): array => Program::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $programId): Builder => $query->where(
                            fn (Builder $programQuery): Builder => $programQuery
                                ->whereHas('admissionApplication', fn (Builder $application): Builder => $application
                                    ->where('program_id', $programId))
                                ->orWhereHas('studentProfile', fn (Builder $profile): Builder => $profile
                                    ->where('program_id', $programId)),
                        ),
                    )),
                SelectFilter::make('selection_basis')
                    ->options([
                        Enrollment::SelectionStandardCurriculum => 'Standard Curriculum',
                        Enrollment::SelectionIndividuallyAdvised => 'Individually Advised',
                    ]),
                SelectFilter::make('canonical_outcome')
                    ->label('Outcome')
                    ->options([
                        Enrollment::OutcomeInProgress => 'In Progress',
                        Enrollment::OutcomeOfficiallyEnrolled => 'Officially Enrolled',
                        Enrollment::OutcomeCancelled => 'Cancelled',
                        Enrollment::OutcomeNotEnrolled => 'Not Enrolled',
                    ]),
                SelectFilter::make('context')
                    ->options([
                        'applicant' => 'Ready Applicant',
                        'continuing' => 'Continuing Student',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $context): Builder => $context === 'applicant'
                            ? $query->whereNotNull('admission_application_id')
                            : $query->whereNull('admission_application_id')->whereNotNull('student_profile_id'),
                    )),
                Filter::make('capacity_shortage')
                    ->label('Capacity shortage')
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        app(RegistrationShortageProjection::class)->caseIds(),
                    )),
            ])
            ->recordActions([ViewAction::make()])
            ->stackedOnMobile()
            ->toolbarActions([]);
    }

    private static function stage(Enrollment $enrollment): string
    {
        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return 'Officially enrolled';
        }

        if ($enrollment->canonical_outcome === Enrollment::OutcomeCancelled) {
            return 'Cancelled';
        }

        if ($enrollment->canonical_outcome === Enrollment::OutcomeNotEnrolled) {
            return 'Not enrolled';
        }

        $proposal = $enrollment->currentProposalVersion;
        if (! $proposal instanceof RegistrationProposalVersion) {
            return 'Prepare proposal';
        }

        if ($proposal->state === RegistrationProposalVersion::StateDraft) {
            return 'Draft proposal';
        }

        if ($proposal->state === RegistrationProposalVersion::StateIssued) {
            return 'Awaiting learner confirmation';
        }

        $placed = $proposal->items->isNotEmpty()
            && $proposal->items->every(fn ($item): bool => $item->reservation?->status === 'active');
        if (! $placed) {
            return 'Placement required';
        }

        return $enrollment->termAccount?->state === TermAccount::StateCleared
            ? 'Ready to finalize'
            : 'Accounting clearance';
    }

    private static function nextAction(Enrollment $enrollment): string
    {
        return match (self::stage($enrollment)) {
            'Prepare proposal', 'Draft proposal' => 'Registrar prepares and issues the current proposal.',
            'Awaiting learner confirmation' => 'Learner confirms the issued proposal.',
            'Placement required' => 'Registrar protects seats in the published timetable.',
            'Accounting clearance' => 'Accounting resolves the current assessment requirement.',
            'Ready to finalize' => 'Registrar revalidates all five checkpoints and finalizes.',
            'Officially enrolled' => 'Current official registrations and immutable COR are available.',
            default => 'Review preserved history and authorized recovery options.',
        };
    }
}
