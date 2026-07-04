<?php

namespace App\Filament\Resources\ApplicantIntakes\Schemas;

use App\Models\ApplicantIntake;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApplicantIntakeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Applicant')
                    ->schema([
                        TextEntry::make('user.name')->label('Applicant Name'),
                        TextEntry::make('user.email')->label('Email'),
                        TextEntry::make('birth_date')->date(),
                        TextEntry::make('phone')->placeholder('Not provided'),
                        TextEntry::make('admission_category')
                            ->label('Admission Category')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::admissionCategoryLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('credential_basis')
                            ->label('Credential Basis')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::credentialBasisLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Application')
                    ->schema([
                        TextEntry::make('term.term_name')->label('Admission Term'),
                        TextEntry::make('program.name')->label('Preferred Program'),
                        TextEntry::make('prior_school')->placeholder('Not provided'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                ApplicantIntake::StatusApproved => 'success',
                                ApplicantIntake::StatusActionRequired => 'danger',
                                ApplicantIntake::StatusForEvaluation => 'info',
                                ApplicantIntake::StatusDraft => 'gray',
                                default => 'warning',
                            })
                            ->formatStateUsing(fn (?string $state): string => self::statusLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('submitted_at')->dateTime(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Identity Evidence')
                    ->schema([
                        TextEntry::make('identity_evidence_reference')
                            ->label('Private Identity Document')
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? basename($state)
                                : 'Not uploaded'),
                    ])
                    ->columnSpanFull(),
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
            ApplicantIntake::StatusDraft => 'Draft',
            ApplicantIntake::StatusPending => 'Pending Review',
            ApplicantIntake::StatusActionRequired => 'Action Required',
            ApplicantIntake::StatusForEvaluation => 'For Evaluation',
            ApplicantIntake::StatusApproved => 'Approved for Handover',
        ];
    }
}
