<?php

namespace App\Filament\Resources\ApplicantIntakes\Schemas;

use App\Actions\Applicants\ApplicantDuplicateCandidateFinder;
use App\Actions\Applicants\ApplicantIntakeWorkflowPresenter;
use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

class ApplicantIntakeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current Workflow')
                    ->description('Start here. This section explains the current stage, who must act, and what must happen next.')
                    ->schema([
                        TextEntry::make('workflow_stage')
                            ->label('Current Stage')
                            ->state(fn (ApplicantIntake $record): string => self::workflow($record)['stage'])
                            ->badge(),
                        TextEntry::make('responsible_party')
                            ->label('Responsible Party')
                            ->state(fn (ApplicantIntake $record): string => self::workflow($record)['responsible_party']),
                        TextEntry::make('next_action')
                            ->label('Next Action')
                            ->state(fn (ApplicantIntake $record): string => self::workflow($record)['next_action']),
                        TextEntry::make('requirement_readiness')
                            ->label('Requirement Readiness')
                            ->state(fn (ApplicantIntake $record): string => self::workflow($record)['requirements_summary']),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Identity Match Check')
                    ->description('Exact active-record matches are surfaced for Registrar investigation. TALA never merges records automatically.')
                    ->schema([
                        TextEntry::make('identity_match_summary')
                            ->label('Official Student Record Check')
                            ->state(fn (ApplicantIntake $record): string => self::candidateMatches($record)->isEmpty()
                                ? 'No exact active student record found'
                                : 'Possible existing student record')
                            ->belowContent(fn (ApplicantIntake $record): string => self::candidateMatches($record)->isEmpty()
                                ? 'Continue the normal review. A later duplicate concern must still be investigated if new evidence appears.'
                                : self::candidateMatches($record)
                                    ->map(fn (StudentProfile $profile): string => "{$profile->student_number} — {$profile->first_name} {$profile->last_name}")
                                    ->implode('; ')),
                    ])
                    ->headerActions([
                        Action::make('openDuplicateResolutionRegister')
                            ->label('Duplicate Resolution Register')
                            ->url(fn (): string => DuplicateProfileResolutionResource::getUrl('index'))
                            ->visible(fn (): bool => DuplicateProfileResolutionResource::canViewAny()),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Application Scope')
                    ->description('The admission period, program, and applicant classification used by this review.')
                    ->schema([
                        TextEntry::make('term.label')->label('Admission Term'),
                        TextEntry::make('program.name')->label('Preferred Program'),
                        TextEntry::make('admission_category')
                            ->label('Admission Category')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::admissionCategoryLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('credential_basis')
                            ->label('Credential Basis')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::credentialBasisLabels()[$state] ?? str((string) $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('prior_school')->label('Applicant Prior School')->placeholder('Not provided'),
                    ])
                    ->headerActions([
                        Action::make('manageRequirementPolicies')
                            ->label('Requirement Policies')
                            ->url(fn (): string => AdmissionRequirementPolicyResource::getUrl('index'))
                            ->visible(fn (): bool => AdmissionRequirementPolicyResource::canViewAny()),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Personal Information')
                    ->schema([
                        TextEntry::make('applicant_name')
                            ->label('Applicant Name')
                            ->state(fn (ApplicantIntake $record): string => self::fullName($record)),
                        TextEntry::make('email'),
                        TextEntry::make('phone')->label('Contact Number')->placeholder('Not provided'),
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
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('Address and Guardian')
                    ->schema([
                        TextEntry::make('address')
                            ->label('Applicant Address')
                            ->state(fn (ApplicantIntake $record): string => self::address($record))
                            ->placeholder('Not provided'),
                        TextEntry::make('guardian_name')->label('Parent / Guardian')->placeholder('Not provided'),
                        TextEntry::make('guardian_phone')->label('Guardian Contact')->placeholder('Not provided'),
                        TextEntry::make('guardian_address')->label('Guardian Address')->placeholder('Not provided'),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('Withdrawal Details')
                    ->description('Applicant-provided withdrawal information retained in the audit record.')
                    ->schema([
                        TextEntry::make('archived_at')->label('Withdrawn At')->dateTime(),
                        TextEntry::make('withdrawalActivity.causer.email')->label('Withdrawn By'),
                        TextEntry::make('withdrawal_reason')
                            ->label('Reason')
                            ->state(fn (ApplicantIntake $record): string => (string) (
                                $record->withdrawalActivity?->properties?->get('reason')
                                ?? 'No reason was recorded.'
                            )),
                    ])
                    ->columns(1)
                    ->visible(fn (ApplicantIntake $record): bool => $record->status === ApplicantIntake::StatusWithdrawn)
                    ->columnSpanFull(),
                Section::make('Application History')
                    ->description('Recorded lifecycle timestamps for this application. Requirement-level decisions appear in the checklist workspace below.')
                    ->schema([
                        TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('Not submitted'),
                        TextEntry::make('reviewed_at')->label('Last Registrar Review')->dateTime()->placeholder('Not reviewed'),
                        TextEntry::make('approved_at')->label('Approved')->dateTime()->placeholder('Not approved'),
                        TextEntry::make('handed_over_at')->label('Student Record Created')->dateTime()->placeholder('Not handed over'),
                        TextEntry::make('archived_at')->label('Withdrawn')->dateTime()->placeholder('Not withdrawn'),
                        TextEntry::make('updated_at')->label('Last Record Update')->dateTime(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('Technical References')
                    ->description('Internal references for support and audit. These are not workflow instructions.')
                    ->schema([
                        TextEntry::make('id')->label('Applicant Intake ID'),
                        TextEntry::make('identity_evidence_reference')
                            ->label('Legacy Identity Reference')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : 'Not recorded'),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, mixed> */
    private static function workflow(ApplicantIntake $record): array
    {
        return app(ApplicantIntakeWorkflowPresenter::class)->present($record);
    }

    /** @return Collection<int, StudentProfile> */
    private static function candidateMatches(ApplicantIntake $record): Collection
    {
        return app(ApplicantDuplicateCandidateFinder::class)->find($record);
    }

    private static function fullName(ApplicantIntake $record): string
    {
        return collect([
            $record->first_name,
            $record->middle_name,
            $record->last_name,
            $record->extension_name,
        ])->filter()->implode(' ');
    }

    private static function address(ApplicantIntake $record): string
    {
        return collect([
            $record->address_street,
            $record->address_barangay,
            $record->address_city,
            $record->address_district,
            $record->address_province,
        ])->filter()->implode(', ');
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
}
