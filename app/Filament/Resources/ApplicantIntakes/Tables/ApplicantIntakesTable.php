<?php

namespace App\Filament\Resources\ApplicantIntakes\Tables;

use App\Actions\Applicants\ApplicantIntakeWorkflowPresenter;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApplicantIntakesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applicant_name')
                    ->label('Applicant')
                    ->state(fn (ApplicantIntake $record): string => collect([
                        $record->first_name,
                        $record->middle_name,
                        $record->last_name,
                        $record->extension_name,
                    ])->filter()->implode(' '))
                    ->description(fn (ApplicantIntake $record): string => $record->email)
                    ->searchable(['first_name', 'middle_name', 'last_name', 'email']),
                TextColumn::make('admission_scope')
                    ->label('Program / Term')
                    ->state(fn (ApplicantIntake $record): string => (string) data_get($record, 'program.name'))
                    ->description(fn (ApplicantIntake $record): string => (string) data_get($record, 'term.label'))
                    ->wrap(),
                TextColumn::make('workflow_stage')
                    ->label('Current Stage')
                    ->state(fn (ApplicantIntake $record): string => self::workflow($record)['stage'])
                    ->description(fn (ApplicantIntake $record): string => 'Owner: '.self::workflow($record)['responsible_party'])
                    ->badge()
                    ->color(fn (ApplicantIntake $record): string => match (self::workflow($record)['stage']) {
                        'Student Record Created' => 'success',
                        'Ready for Handover' => 'success',
                        'Applicant Action Required' => 'danger',
                        'Registrar Evaluation' => 'info',
                        'Withdrawn' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('next_action')
                    ->label('Next Action')
                    ->state(fn (ApplicantIntake $record): string => self::workflow($record)['next_action'])
                    ->wrap(),
                TextColumn::make('requirements_summary')
                    ->label('Requirement Readiness')
                    ->state(fn (ApplicantIntake $record): string => self::workflow($record)['requirements_summary'])
                    ->color(fn (ApplicantIntake $record): string => self::workflow($record)['handover_blocker_count'] > 0
                        ? 'danger'
                        : 'success')
                    ->wrap(),
                TextColumn::make('last_activity_at')
                    ->label('Last Activity')
                    ->state(fn (ApplicantIntake $record): mixed => self::workflow($record)['last_activity_at'])
                    ->dateTime()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('updated_at', $direction)),
                TextColumn::make('admission_category')
                    ->label('Admission Category')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::admissionCategoryLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('credential_basis')
                    ->label('Credential Basis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::credentialBasisLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('program')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Workflow State')
                    ->options(self::statusLabels()),
                SelectFilter::make('admission_category')
                    ->label('Admission Category')
                    ->options(self::admissionCategoryLabels()),
                Filter::make('has_handover_blocker')
                    ->label('Has Handover Blocker')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'checklistItems',
                        fn (Builder $query): Builder => $query
                            ->where('blocking_level', ChecklistItem::BlockingHandover)
                            ->whereNotIn('status', [
                                ChecklistItem::StatusAccepted,
                                ChecklistItem::StatusWaived,
                                ChecklistItem::StatusUndertakingApproved,
                            ])
                            ->where('verification_status', '!=', ChecklistItem::VerificationVerified),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->label('Open Review'),
            ]);
    }

    /**
     * @return array{
     *     stage:string,
     *     responsible_party:string,
     *     next_action:string,
     *     handover_blocker_count:int,
     *     requirement_count:int,
     *     requirements_summary:string,
     *     ready_for_handover:bool,
     *     last_activity_at:mixed
     * }
     */
    private static function workflow(ApplicantIntake $record): array
    {
        return app(ApplicantIntakeWorkflowPresenter::class)->present($record);
    }

    /** @return array<string, string> */
    private static function admissionCategoryLabels(): array
    {
        return [
            ApplicantIntake::AdmissionCategoryFirstTimeCollege => 'First-Time College Applicant',
            ApplicantIntake::AdmissionCategoryTransfer => 'Transfer Applicant',
            ApplicantIntake::AdmissionCategoryReturning => 'Returning Student / Readmission',
        ];
    }

    /** @return array<string, string> */
    private static function credentialBasisLabels(): array
    {
        return [
            ApplicantIntake::CredentialBasisSeniorHighSchool => 'Senior High School Credential',
            ApplicantIntake::CredentialBasisTransferCredentials => 'Transfer Credentials',
            ApplicantIntake::CredentialBasisPriorStudentRecord => 'Prior Student Record',
        ];
    }

    /** @return array<string, string> */
    private static function statusLabels(): array
    {
        return [
            ApplicantIntake::StatusPending => 'Pending Review',
            ApplicantIntake::StatusActionRequired => 'Action Required',
            ApplicantIntake::StatusForEvaluation => 'For Evaluation',
            ApplicantIntake::StatusApproved => 'Approved for Handover',
            ApplicantIntake::StatusWithdrawn => 'Withdrawn',
        ];
    }
}
