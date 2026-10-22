<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\View as RecordView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)
            && ! auth()->user()->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting])) {
            return self::headTable($table);
        }
        $registrar = auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'credentialUser',
                'admissionApplication.program',
                'studentProfile.program',
                'term',
                'currentProposalVersion.curriculumVersion.program',
                'currentProposalVersion.confirmation',
                'currentProposalVersion.items.reservation',
                'termAccount.assessments',
            ]))
            ->defaultSort('updated_at', 'desc')
            ->columns($registrar ? [RecordView::make('filament.admin.enrollments.record-row')] : [
                TextColumn::make('learner')
                    ->label('Learner')
                    ->state(fn (Enrollment $record): string => $record->credentialUser?->getFilamentName() ?? 'Identity unavailable')
                    ->description(fn (Enrollment $record): ?string => $record->studentProfile?->student_number
                        ?? $record->admissionApplication?->application_reference)
                    ->wrap(),
                TextColumn::make('case_reference')
                    ->label('Registration reference')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('program_term')
                    ->label('Program & term')
                    ->state(fn (Enrollment $record): string => $record->currentProposalVersion?->curriculumVersion?->program?->name
                        ?? $record->admissionApplication?->program?->name
                        ?? $record->studentProfile?->program?->name
                        ?? 'Program not assigned')
                    ->description(fn (Enrollment $record): ?string => $record->term?->label)
                    ->wrap(),
                TextColumn::make('selection_basis')
                    ->label('Selection basis')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::headline($state) : 'Not selected'),
                TextColumn::make('stage')
                    ->label('Status')
                    ->state(fn (Enrollment $record): string => self::stage($record))
                    ->description(fn (Enrollment $record): string => self::nextAction($record))
                    ->badge()
                    ->color(fn (Enrollment $record): string => match (self::stage($record)) {
                        'Officially enrolled' => 'success',
                        'Cancelled', 'Not enrolled' => 'danger',
                        'Ready to finalize' => 'info',
                        'Placement required', 'Source review required' => 'warning',
                        default => 'gray',
                    })
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label('Last activity')
                    ->toggleable(isToggledHiddenByDefault: true)
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
                        Enrollment::OutcomeCancelledByLearner => 'Cancelled by Learner',
                        Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
                        Enrollment::OutcomeCancelled => 'Cancelled (legacy)',
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
            ->searchable([fn (Builder $query, string $search): Builder => self::searchLearner($query, $search)])
            ->searchPlaceholder('Name or number')
            ->recordActions($registrar ? [] : [ViewAction::make()])
            ->when($registrar, fn (Table $table): Table => $table->recordUrl(null)->recordAction(null))
            ->selectable(fn ($livewire): bool => $registrar && $livewire->activeTab === 'ready_to_finalize')
            ->emptyStateHeading(fn ($livewire): string => filled($livewire->getTableSearch()) ? 'No matching enrollments' : match ($livewire->activeTab) {
                'ready_to_prepare' => 'No subjects waiting to be prepared',
                'waiting_for_learner' => 'No students waiting to confirm subjects',
                'placement_shortages' => 'No current enrollment issues',
                'finance_pending' => 'No payments or fees waiting for review',
                'ready_to_finalize' => 'No enrollments ready to make official',
                default => 'No enrollment records found',
            })
            ->emptyStateDescription(fn ($livewire): string => filled($livewire->getTableSearch())
                ? 'Try another name or number, or clear the search and filters.'
                : ($livewire->activeTab === 'ready_to_prepare' ? 'New enrollments and saved drafts appear here.' : 'Return to Enrollment to choose another task or find a record.'))
            ->emptyStateActions([
                Action::make('chooseReadyApplicant')->label('Ready applicant')->icon('heroicon-o-user-plus')
                    ->visible(fn ($livewire): bool => $registrar && $livewire->activeTab === 'ready_to_prepare')
                    ->url(EnrollmentResource::getUrl('index', ['tab' => 'ready_applicants'])),
                Action::make('chooseContinuingStudent')->label('Continuing student')->color('gray')->icon('heroicon-o-plus-circle')
                    ->visible(fn ($livewire): bool => $registrar && $livewire->activeTab === 'ready_to_prepare')
                    ->action(fn ($livewire) => $livewire->mountAction('startContinuingEnrollment')),
            ])
            ->toolbarActions([
                BulkAction::make('finalizeSelected')->label('Finalize selected cases')->icon('heroicon-o-check-badge')
                    ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                    ->requiresConfirmation()->modalHeading('Make selected enrollments official')
                    ->modalContent(fn (Collection $records) => view('filament.pages.partials.enrollment-batch-confirmation', ['records' => $records]))
                    ->modalCancelActionLabel('Keep reviewing')
                    ->modalDescription('Successful cases receive official registration, a Student number when needed, and a certificate. Each case is rechecked independently; a failed case stays unchanged.')
                    ->modalSubmitActionLabel('Finalize selected cases')->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, $livewire): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->canAuthenticate() && $actor->hasRole(User::StaffRoleRegistrar), 403);
                        $results = [];
                        foreach ($records as $case) {
                            $result = 'Finalized';
                            $recovery = 'Official enrollment and COR are available. Email delivery is tracked separately.';
                            try {
                                Gate::forUser($actor)->authorize('view', $case);
                                app(FinalizeOfficialEnrollment::class)->execute($case, $actor);
                            } catch (Throwable $exception) {
                                $case->refresh();
                                if ($case->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled || $case->current_cor_version_id === null) {
                                    $result = 'Not finalized';
                                    $recovery = $exception instanceof ValidationException ? $exception->getMessage() : 'Open the case, review the current source or permission, then retry.';
                                } else {
                                    $recovery = 'Official result retained. Review the separate notification delivery status.';
                                }
                            }
                            $results[] = ['case' => $case->case_reference, 'learner' => $case->credentialUser?->getFilamentName() ?? 'Learner',
                                'result' => $result, 'recovery' => $recovery, 'url' => EnrollmentResource::getUrl('view', ['record' => $case])];
                        }
                        $livewire->finalizationResults = $results;
                        Notification::make()->title('Selected enrollment results are ready')->body('Review each case result below.')->success()->send();
                    }),
            ]);
    }

    private static function headTable(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
            'credentialUser', 'admissionApplication.program', 'studentProfile.program', 'term',
            'currentProposalVersion.curriculumVersion.program', 'currentProposalVersion.confirmation',
            'currentProposalVersion.items.reservation', 'termAccount.assessments',
        ]))
            ->defaultSort('updated_at', 'desc')
            ->columns([RecordView::make('filament.admin.enrollments.head-record-row')])
            ->recordUrl(null)->recordAction(null)->recordActions([])->toolbarActions([])
            ->filters([
                SelectFilter::make('term')->relationship('term', 'label')->searchable()->preload(),
                SelectFilter::make('program')->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                        fn (Builder $query, string $programId): Builder => self::filterHeadProgram($query, $programId))),
                SelectFilter::make('canonical_outcome')->label('Outcome')->options([
                    Enrollment::OutcomeInProgress => 'In progress', Enrollment::OutcomeOfficiallyEnrolled => 'Officially enrolled',
                    Enrollment::OutcomeCancelledByLearner => 'Cancelled by learner', Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
                    Enrollment::OutcomeCancelled => 'Cancelled (legacy)', Enrollment::OutcomeNotEnrolled => 'Not enrolled',
                ]),
            ])
            ->searchable([fn (Builder $query, string $search): Builder => self::searchLearner($query, $search)])
            ->searchPlaceholder('Name or number')
            ->emptyStateHeading('No matching enrollments')
            ->emptyStateDescription('Try another name or number, or change the filters.');
    }

    public static function headProgram(Enrollment $enrollment): ?Program
    {
        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return $enrollment->studentProfile?->program;
        }

        return $enrollment->currentProposalVersion?->curriculumVersion?->program
            ?? $enrollment->admissionApplication?->program
            ?? $enrollment->studentProfile?->program;
    }

    private static function filterHeadProgram(Builder $query, string $programId): Builder
    {
        $matchesProgram = fn (Builder $program): Builder => $program->whereKey($programId);

        return $query->where(fn (Builder $scope): Builder => $scope
            ->where(fn (Builder $official): Builder => $official
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                ->whereHas('studentProfile.program', $matchesProgram))
            ->orWhere(fn (Builder $unfinished): Builder => $unfinished
                ->where(fn (Builder $outcome): Builder => $outcome->where('canonical_outcome', '!=', Enrollment::OutcomeOfficiallyEnrolled)->orWhereNull('canonical_outcome'))
                ->where(fn (Builder $source): Builder => $source
                    ->whereHas('currentProposalVersion.curriculumVersion.program', $matchesProgram)
                    ->orWhere(fn (Builder $fallback): Builder => $fallback
                        ->whereDoesntHave('currentProposalVersion.curriculumVersion.program')
                        ->where(fn (Builder $identity): Builder => $identity
                            ->whereHas('admissionApplication.program', $matchesProgram)
                            ->orWhere(fn (Builder $profile): Builder => $profile
                                ->whereDoesntHave('admissionApplication.program')
                                ->whereHas('studentProfile.program', $matchesProgram)))))));
    }

    public static function headProgress(Enrollment $enrollment): ?string
    {
        if ($enrollment->canonical_outcome !== Enrollment::OutcomeInProgress) {
            return null;
        }

        return match (self::stage($enrollment)) {
            'Prepare subjects' => 'Subjects being prepared',
            'Waiting for learner' => 'Awaiting learner confirmation',
            'Ready to finalize' => 'Awaiting finalization',
            'Placement required' => 'Class placement needs review',
            'Payment or fee review' => 'Accounting review pending',
            default => 'Enrollment sources need review',
        };
    }

    public static function stage(Enrollment $enrollment): string
    {
        if ($enrollment->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return 'Officially enrolled';
        }
        if (in_array($enrollment->canonical_outcome, Enrollment::cancelledOutcomes(), true)) {
            return 'Cancelled';
        }
        if ($enrollment->canonical_outcome === Enrollment::OutcomeNotEnrolled) {
            return 'Not enrolled';
        }

        $proposal = $enrollment->currentProposalVersion;
        if (! $proposal instanceof RegistrationProposalVersion || $proposal->state === RegistrationProposalVersion::StateDraft) {
            return 'Prepare subjects';
        }

        $readiness = app(RegistrationReadinessQuery::class)->for($enrollment);
        if (! $readiness['identity'] || ! $readiness['eligibility']) {
            return 'Source review required';
        }

        if ($proposal->state === RegistrationProposalVersion::StateIssued) {
            try {
                app(RegistrationPlacementValidator::class)->assertCurrent($enrollment, $proposal, requireConfirmation: false);
            } catch (ValidationException) {
                return 'Placement required';
            }

            return app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment)['state'] === 'Unavailable'
                ? 'Source review required' : 'Waiting for learner';
        }

        if (! $readiness['placement']) {
            return 'Placement required';
        }

        return $readiness['ready'] ? 'Ready to finalize' : ($readiness['finance'] ? 'Source review required' : 'Payment or fee review');
    }

    private static function searchLearner(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query->where('case_reference', 'like', "%{$search}%")
                ->orWhereHas('credentialUser', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                ->orWhereHas('studentProfile', fn (Builder $query): Builder => $query->where('student_number', 'like', "%{$search}%"))
                ->orWhereHas('admissionApplication', fn (Builder $query): Builder => $query->where('application_reference', 'like', "%{$search}%"));
        });
    }

    private static function nextAction(Enrollment $enrollment): string
    {
        return match (self::stage($enrollment)) {
            'Prepare subjects' => 'Prepare subjects for learner confirmation.',
            'Waiting for learner' => 'Subjects issued for confirmation.',
            'Placement required' => 'Assign available classes from the published schedule.',
            'Payment or fee review' => 'Accounting reviews the required fees and payment.',
            'Source review required' => 'Review the changed enrollment requirements.',
            'Ready to finalize' => 'Review and make enrollment official.',
            'Officially enrolled' => 'View subjects and registration.',
            default => 'View enrollment history and available actions.',
        };
    }
}
