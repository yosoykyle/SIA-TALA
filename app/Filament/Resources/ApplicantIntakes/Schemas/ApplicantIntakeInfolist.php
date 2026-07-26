<?php

namespace App\Filament\Resources\ApplicantIntakes\Schemas;

use App\Models\ApplicantIntake;
use Carbon\CarbonImmutable;
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
                        TextEntry::make('applicant_name')
                            ->label('Applicant Name')
                            ->state(fn (ApplicantIntake $record): string => collect([
                                $record->first_name,
                                $record->middle_name,
                                $record->last_name,
                                $record->extension_name,
                            ])->filter()->implode(' ')),
                        TextEntry::make('email'),
                        TextEntry::make('birth_date')->label('Date of Birth')->date(),
                        TextEntry::make('age')
                            ->state(fn (ApplicantIntake $record): string => filled($record->birth_date)
                                ? (string) CarbonImmutable::parse((string) $record->birth_date)->age
                                : 'Not available'),
                        TextEntry::make('birth_place')->label('Place of Birth')->placeholder('Not provided'),
                        TextEntry::make('gender')
                            ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString())
                            ->placeholder('Not provided'),
                        TextEntry::make('civil_status')
                            ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->title()->toString())
                            ->placeholder('Not provided'),
                        TextEntry::make('phone')->label('Contact Number')->placeholder('Not provided'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Application')
                    ->schema([
                        TextEntry::make('term.label')->label('Admission Term'),
                        TextEntry::make('program.name')->label('Preferred Program'),
                        TextEntry::make('modality_preference')
                            ->label('Modality Preference')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                ApplicantIntake::ModalityPreferenceFaceToFace => 'Face-to-Face',
                                ApplicantIntake::ModalityPreferenceOnline => 'Online',
                                default => 'Not provided',
                            }),
                        TextEntry::make('admission_category')
                            ->label('Admission Category')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::admissionCategoryLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('credential_basis')
                            ->label('Credential Basis')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::credentialBasisLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('prior_school')->label('Prior School')->placeholder('Not provided'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                ApplicantIntake::StatusApproved => 'success',
                                ApplicantIntake::StatusActionRequired => 'danger',
                                ApplicantIntake::StatusForEvaluation => 'info',
                                ApplicantIntake::StatusDraft, ApplicantIntake::StatusWithdrawn => 'gray',
                                default => 'warning',
                            })
                            ->formatStateUsing(fn (?string $state): string => self::statusLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('submitted_at')->dateTime()->placeholder('Draft not submitted'),
                        TextEntry::make('reviewed_at')->label('Last Registrar Review')->dateTime()->placeholder('Not reviewed'),
                        TextEntry::make('approved_at')->label('Approved At')->dateTime()->placeholder('Not approved'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Address and Guardian')
                    ->schema([
                        TextEntry::make('address')
                            ->state(fn (ApplicantIntake $record): string => collect([
                                $record->address_street,
                                $record->address_barangay,
                                $record->address_city,
                                $record->address_district,
                                $record->address_province,
                            ])->filter()->implode(', '))
                            ->placeholder('Not provided'),
                        TextEntry::make('guardian_name')->label('Parent / Guardian')->placeholder('Not provided'),
                        TextEntry::make('guardian_phone')->label('Guardian Contact')->placeholder('Not provided'),
                        TextEntry::make('guardian_address')->label('Guardian Address')->placeholder('Not provided'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Withdrawal Details')
                    ->description('Applicant-provided withdrawal information retained in the audit record.')
                    ->schema([
                        TextEntry::make('archived_at')
                            ->label('Withdrawn At')
                            ->dateTime(),
                        TextEntry::make('withdrawalActivity.causer.email')
                            ->label('Withdrawn By'),
                        TextEntry::make('withdrawal_reason')
                            ->label('Reason')
                            ->state(fn (ApplicantIntake $record): string => (string) (
                                $record->withdrawalActivity?->properties?->get('reason')
                                ?? 'No reason was recorded.'
                            ))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (ApplicantIntake $record): bool => $record->status === ApplicantIntake::StatusWithdrawn)
                    ->columnSpanFull(),
                Section::make('Digital Evidence')
                    ->description('Open and decide each digital requirement individually in the checklist below.')
                    ->schema([
                        TextEntry::make('identity_evidence_reference')
                            ->label('Identity Document Reference')
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
            ApplicantIntake::StatusWithdrawn => 'Withdrawn',
        ];
    }
}
