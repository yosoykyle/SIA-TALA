<?php

namespace App\Filament\Resources\ApplicantIntakes\Tables;

use App\Models\ApplicantIntake;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                TextColumn::make('program.name')
                    ->label('Program')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Admission Term')
                    ->sortable(),
                TextColumn::make('admission_category')
                    ->label('Admission Category')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::admissionCategoryLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                TextColumn::make('credential_basis')
                    ->label('Credential Basis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::credentialBasisLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ApplicantIntake::StatusApproved => 'success',
                        ApplicantIntake::StatusActionRequired => 'danger',
                        ApplicantIntake::StatusForEvaluation => 'info',
                        ApplicantIntake::StatusDraft => 'gray',
                        ApplicantIntake::StatusWithdrawn => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => self::statusLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->label('Withdrawn At')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusLabels()),
                SelectFilter::make('admission_category')
                    ->label('Admission Category')
                    ->options(self::admissionCategoryLabels()),
                SelectFilter::make('credential_basis')
                    ->label('Credential Basis')
                    ->options(self::credentialBasisLabels()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
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
